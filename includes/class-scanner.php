<?php
/**
 * Resumable local discovery and verification worker.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Runs resumable discovery and URL verification work.
 */
final class Scanner {
	const LOCK_OPTION          = 'sblc_scan_lock';
	const WORKER_LOCK_OPTION   = 'sblc_scan_worker_lock';
	const WORKER_STALE_SECONDS = 60;
	const SCAN_STALE_SECONDS   = 90;

	/**
	 * Start a new scan.
	 *
	 * @return array|\WP_Error
	 */
	public static function start() {
		$active = Database::get_active_scan();
		if ( $active ) {
			self::reconcile_scan( $active );
			if ( Database::get_active_scan() ) {
				return new \WP_Error( 'sblc_scan_active', __( 'A scan is already in progress.', 'linksolva-broken-link-checker' ), array( 'status' => 409 ) );
			}
		}
		if ( ! self::acquire_lock() ) {
			return new \WP_Error( 'sblc_scan_locked', __( 'Another scan request is being started. Please try again.', 'linksolva-broken-link-checker' ), array( 'status' => 409 ) );
		}
		Database::reset_for_scan();
		$scan_id = Database::insert_scan();
		if ( ! $scan_id ) {
			self::release_lock();
			return new \WP_Error( 'sblc_scan_create_failed', __( 'The scan could not be created.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
		}
		self::set_lock_scan( $scan_id );
		return self::step( $scan_id );
	}

	/**
	 * Process one bounded worker request.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array|\WP_Error
	 */
	public static function step( $scan_id ) {
		$worker_lock = self::acquire_worker_lock();
		if ( ! $worker_lock ) {
			$scan = Database::get_scan( $scan_id );
			return $scan ? self::progress( $scan ) : new \WP_Error( 'sblc_scan_missing', __( 'The requested scan was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}

		try {
			return self::process_step( $scan_id );
		} finally {
			self::release_worker_lock( $worker_lock );
		}
	}

	/**
	 * Process one bounded worker request while holding the worker lock.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array|\WP_Error
	 */
	private static function process_step( $scan_id ) {
		$scan = Database::get_scan( $scan_id );
		if ( ! $scan ) {
			return new \WP_Error( 'sblc_scan_missing', __( 'The requested scan was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		if ( in_array( $scan->state, array( 'completed', 'cancelled', 'failed' ), true ) ) {
			return self::progress( $scan );
		}
		if ( 'cancelling' === $scan->state ) {
			Database::update_scan(
				$scan_id,
				array(
					'state'       => 'cancelled',
					'finished_at' => Database::now(),
				)
			);
			self::schedule_next();
			self::release_lock();
			return self::progress( Database::get_scan( $scan_id ) );
		}
		$scan = self::reconcile_scan( $scan, true );
		if ( ! $scan || in_array( $scan->state, array( 'completed', 'cancelled', 'failed' ), true ) ) {
			return self::progress( $scan );
		}

		$limits   = Settings::worker_limits();
		$deadline = microtime( true ) + $limits['execution_seconds'];
		try {
			if ( 'discovering' === $scan->state ) {
				/*
				 * Discovery and verification are deliberately serialized. Once a
				 * source batch has produced URLs, drain that queue before reading the
				 * next source batch so the UI can show decisions as they are made.
				 */
				$pending = Database::resources_for_scan( $scan->id, 1 );
				if ( empty( $pending ) && microtime( true ) < $deadline ) {
					self::discover( $scan, $deadline, $limits['batch_size'] );
				} elseif ( ! empty( $pending ) ) {
					self::check_resources( $scan, $deadline );
				}
			}
			$scan    = Database::get_scan( $scan_id );
			$pending = $scan ? Database::resources_for_scan( $scan->id, 1 ) : array();
			if ( $scan && ( 'checking' === $scan->state || ( 'discovering' === $scan->state && ! empty( $pending ) ) ) && microtime( true ) < $deadline ) {
				self::check_resources( $scan, $deadline );
			}
			$scan = Database::get_scan( $scan_id );
			$scan = self::reconcile_scan( $scan, true );
			if ( $scan && in_array( $scan->state, array( 'discovering', 'checking', 'cancelling' ), true ) ) {
				self::touch_lock( $scan_id );
			}
			return self::progress( $scan );
		} catch ( \Throwable $error ) {
			Database::update_scan(
				$scan_id,
				array(
					'state'         => 'failed',
					'error_message' => sanitize_text_field( $error->getMessage() ),
					'finished_at'   => Database::now(),
				)
			);
			self::release_lock();
			return new \WP_Error( 'sblc_scan_failed', __( 'The scan stopped unexpectedly. The current report may be incomplete; run another scan to rebuild it.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
		}
	}

	/**
	 * Ask the worker to stop after the current bounded operation.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array|\WP_Error
	 */
	public static function cancel( $scan_id ) {
		$scan = Database::get_scan( $scan_id );
		if ( ! $scan ) {
			return new \WP_Error( 'sblc_scan_missing', __( 'The requested scan was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		Database::update_scan( $scan_id, array( 'state' => 'cancelling' ) );
		return self::step( $scan_id );
	}

	/**
	 * Resume an active scan from cron.
	 *
	 * @return void
	 */
	public static function scheduled_step() {
		$scan = Database::get_active_scan();
		if ( $scan ) {
			self::step( $scan->id );
			return;
		}
		$schedule = sanitize_key( Settings::get( 'schedule', 'manual' ) );
		if ( 'manual' === $schedule ) {
			return;
		}
		$next = absint( get_option( 'sblc_next_scan_at', 0 ) );
		if ( ! $next ) {
			self::schedule_next();
			return;
		}
		if ( time() >= $next ) {
			$result = self::start();
			if ( is_wp_error( $result ) ) {
				update_option( 'sblc_next_scan_at', time() + HOUR_IN_SECONDS, false );
			}
		}
	}

	/**
	 * Get latest scan status.
	 *
	 * @return array
	 */
	public static function latest_progress() {
		$scan = Database::get_active_scan();
		if ( ! $scan ) {
			$scan = Database::get_latest_scan();
		}
		if ( $scan ) {
			$scan = self::reconcile_scan( $scan );
		}
		return self::progress( $scan );
	}

	/**
	 * Finalize completed work and recover scans abandoned by a terminated worker.
	 *
	 * @param object|null $scan             Scan row.
	 * @param bool        $worker_lock_held Whether this request already owns the worker lock.
	 * @return object|null
	 */
	private static function reconcile_scan( $scan, $worker_lock_held = false ) {
		if ( ! $scan || ! in_array( $scan->state, array( 'discovering', 'checking', 'cancelling' ), true ) ) {
			return $scan;
		}

		$stats    = Database::scan_stats( $scan->id );
		$drained  = 'checking' === $scan->state && $stats['checked_resources'] >= $stats['total_resources'];
		$stalled  = self::scan_is_stalled( $scan );
		if ( ! $drained && ! $stalled ) {
			return $scan;
		}

		$worker_lock = false;
		if ( ! $worker_lock_held ) {
			$worker_lock = self::acquire_worker_lock();
			if ( ! $worker_lock ) {
				return $scan;
			}
		}

		try {
			$scan = Database::get_scan( $scan->id );
			if ( ! $scan || ! in_array( $scan->state, array( 'discovering', 'checking', 'cancelling' ), true ) ) {
				return $scan;
			}
			$stats = Database::scan_stats( $scan->id );
			if ( 'checking' === $scan->state && $stats['checked_resources'] >= $stats['total_resources'] ) {
				return self::complete_scan( $scan->id, $stats );
			}
			if ( ! self::scan_is_stalled( $scan ) ) {
				return $scan;
			}
			if ( 'cancelling' === $scan->state ) {
				Database::update_scan( $scan->id, array( 'state' => 'cancelled', 'finished_at' => Database::now() ) );
				self::schedule_next();
				self::release_lock();
				return Database::get_scan( $scan->id );
			}
			if ( 'discovering' !== $scan->state ) {
				$skipped = Database::skip_pending_resources( $scan->id );
				if ( false === $skipped ) {
					return self::fail_stalled_scan( $scan->id, __( 'The scan could not save its remaining URL results. Review the report and run another scan.', 'linksolva-broken-link-checker' ) );
				}
				$stats = Database::scan_stats( $scan->id );
				if ( $stats['checked_resources'] >= $stats['total_resources'] ) {
					return self::complete_scan( $scan->id, $stats );
				}
				return self::fail_stalled_scan( $scan->id, __( 'The scan stopped because some URLs could not be recorded. Review the report and run another scan.', 'linksolva-broken-link-checker' ) );
			}

			return self::fail_stalled_scan( $scan->id, __( 'The scan stopped during source discovery because the worker stopped responding. Its report may be incomplete; start a new scan to continue.', 'linksolva-broken-link-checker' ) );
		} finally {
			if ( $worker_lock ) {
				self::release_worker_lock( $worker_lock );
			}
		}
	}

	/**
	 * Check whether an active scan has stopped recording work for too long.
	 *
	 * @param object $scan Scan row.
	 * @return bool
	 */
	private static function scan_is_stalled( $scan ) {
		$updated_at = isset( $scan->updated_at ) ? strtotime( $scan->updated_at . ' UTC' ) : false;
		return $updated_at && $updated_at <= time() - self::SCAN_STALE_SECONDS;
	}

	/**
	 * Mark a fully checked scan completed.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $stats   Scan statistics.
	 * @return object|null
	 */
	private static function complete_scan( $scan_id, $stats ) {
		Database::update_scan(
			$scan_id,
			array(
				'state'             => 'completed',
				'checked_resources' => absint( $stats['checked_resources'] ),
				'finished_at'       => Database::now(),
				'counts'            => wp_json_encode( Database::counts() ),
			)
		);
		self::notify_if_enabled( $scan_id );
		self::schedule_next();
		self::release_lock();
		return Database::get_scan( $scan_id );
	}

	/**
	 * Stop an unrecoverable scan with a visible explanation.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $message Failure explanation.
	 * @return object|null
	 */
	private static function fail_stalled_scan( $scan_id, $message ) {
		Database::update_scan(
			$scan_id,
			array(
				'state'         => 'failed',
				'error_message' => sanitize_text_field( $message ),
				'finished_at'   => Database::now(),
			)
		);
		self::schedule_next();
		self::release_lock();
		return Database::get_scan( $scan_id );
	}

	/**
	 * Discover a bounded source page.
	 *
	 * @param object $scan     Scan.
	 * @param float  $deadline Deadline.
	 * @param int    $batch_size Resources per worker request.
	 * @return void
	 */
	private static function discover( $scan, $deadline, $batch_size ) {
		$providers          = Sources::providers();
		$index              = array_search( $scan->provider, $providers, true );
		$index              = false === $index ? 0 : $index;
		$provider           = $providers[ $index ];
		$page               = max( 1, absint( $scan->page ) );
		$offset             = absint( $scan->resource_cursor );
		$batch              = Sources::batch( $provider, $page, absint( $batch_size ), $offset );
		$processed          = absint( $scan->processed_sources );
		$occurrences        = absint( $scan->discovered_occurrences );
		$processed_in_batch = 0;

		foreach ( $batch['items'] as $source ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$occurrences += Sources::store_source( $source, $scan->id );
			++$processed;
			++$processed_in_batch;
		}

		$consumed_all = count( $batch['items'] ) <= $processed_in_batch;
		if ( ! $consumed_all ) {
			$offset += $processed_in_batch;
		} elseif ( empty( $batch['page_complete'] ) ) {
			$offset += count( $batch['items'] );
		} elseif ( ! empty( $batch['has_more'] ) ) {
			++$page;
			$offset = 0;
		} elseif ( isset( $providers[ $index + 1 ] ) ) {
				$provider = $providers[ $index + 1 ];
				$page     = 1;
				$offset   = 0;
		} else {
			Database::update_scan(
				$scan->id,
				array(
					'state'                  => 'checking',
					'provider'               => 'done',
					'page'                   => 1,
					'resource_cursor'        => 0,
					'processed_sources'      => $processed,
					'discovered_occurrences' => $occurrences,
				)
			);
			return;
		}
		$progress_changed = $provider !== $scan->provider || $page !== absint( $scan->page ) || $offset !== absint( $scan->resource_cursor ) || $processed !== absint( $scan->processed_sources ) || $occurrences !== absint( $scan->discovered_occurrences );
		if ( $progress_changed ) {
			Database::update_scan(
				$scan->id,
				array(
					'provider'               => $provider,
					'page'                   => $page,
					'resource_cursor'        => $offset,
					'processed_sources'      => $processed,
					'discovered_occurrences' => $occurrences,
				)
			);
		}
	}

	/**
	 * Check discovered resources in bounded batches.
	 *
	 * @param object $scan     Scan.
	 * @param float  $deadline Deadline.
	 * @return void
	 */
	private static function check_resources( $scan, $deadline ) {
		$limits    = Settings::worker_limits();
		$resources = Database::resources_for_scan( $scan->id, $limits['batch_size'] );
		$parallel  = $limits['parallel_requests'];
		$chunks    = array_chunk( $resources, $parallel );
		foreach ( $chunks as $chunk ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$active  = array();
			$results = array();
			foreach ( $chunk as $resource ) {
				if ( ! empty( $resource->ignored ) ) {
					Database::mark_checked( $resource->id, $scan->id );
					continue;
				}
				$active[ $resource->id ] = $resource;
			}
			if ( ! empty( $active ) ) {
				/* A user-started or scheduled scan is a fresh verification pass. */
				$results = Http_Checker::check_many( $active, $deadline );
			}
			foreach ( $active as $resource_id => $resource ) {
				$result = isset( $results[ $resource_id ] ) ? $results[ $resource_id ] : Http_Checker::scan_time_limit_result( $resource->url, absint( $resource->retry_count ) );
				if ( ! empty( $result['deferred'] ) ) {
					$result = Http_Checker::scan_time_limit_result( $resource->url, absint( $resource->retry_count ) );
				}
				Database::save_result( $resource_id, $scan->id, $result );
			}
		}
		$stats = Database::scan_stats( $scan->id );
		if ( $stats['checked_resources'] > absint( $scan->checked_resources ) ) {
			Database::update_scan( $scan->id, array( 'checked_resources' => $stats['checked_resources'] ) );
		}
	}

	/**
	 * Return API-friendly progress data.
	 *
	 * @param object|null $scan Scan.
	 * @return array
	 */
	private static function progress( $scan ) {
		if ( ! $scan ) {
			return array(
				'scan_id' => 0,
				'state'   => 'idle',
				'phase'   => 'idle',
				'percent' => 0,
				'message' => __( 'No scan has been run yet.', 'linksolva-broken-link-checker' ),
				'counts'  => Database::counts(),
				'stats'   => array(
					'total_resources'   => 0,
					'checked_resources' => 0,
					'skipped_resources' => 0,
				),
			);
		}
		$stats = Database::scan_stats( $scan->id );
		$phase = 'discovering' === $scan->state ? 'discovery' : ( 'checking' === $scan->state ? 'verification' : $scan->state );
		if ( 'discovering' === $scan->state && $stats['checked_resources'] < $stats['total_resources'] ) {
			$phase = 'verification';
		}
		$percent = 0;
		if ( 'checking' === $scan->state || ( 'discovering' === $scan->state && 'verification' === $phase ) || in_array( $scan->state, array( 'completed', 'cancelled', 'failed' ), true ) ) {
			$percent = $stats['total_resources'] ? min( 100, (int) floor( ( $stats['checked_resources'] / $stats['total_resources'] ) * 100 ) ) : ( 'completed' === $scan->state ? 100 : 0 );
		}
		$counts = json_decode( $scan->counts, true );
		if ( 'discovering' === $scan->state ) {
			if ( 'verification' === $phase ) {
				/* translators: 1: checked URL count, 2: discovered URL count. */
				$message = sprintf( __( 'Verifying discovered URLs before reading more sources (%1$d of %2$d complete).', 'linksolva-broken-link-checker' ), absint( $stats['checked_resources'] ), absint( $stats['total_resources'] ) );
			} else {
				$message = __( 'Reading WordPress content. Each URL is verified before more sources are fetched.', 'linksolva-broken-link-checker' );
			}
		} elseif ( 'checking' === $scan->state ) {
			/* translators: 1: Checked URL count, 2: Total URL count. */
			$message = sprintf( __( 'Verifying unique URLs (%1$d of %2$d complete).', 'linksolva-broken-link-checker' ), absint( $stats['checked_resources'] ), absint( $stats['total_resources'] ) );
		} elseif ( 'completed' === $scan->state ) {
			$skipped = absint( $stats['skipped_resources'] );
			$message = $skipped
				? sprintf(
					/* translators: %d: URLs skipped because they could not be verified. */
					_n(
						'Scan completed. %d URL could not be verified and needs manual review.',
						'Scan completed. %d URLs could not be verified and need manual review.',
						$skipped,
						'linksolva-broken-link-checker'
					),
					$skipped
				)
				: __( 'Scan completed.', 'linksolva-broken-link-checker' );
		} elseif ( 'failed' === $scan->state && ! empty( $scan->error_message ) ) {
			$message = sanitize_text_field( $scan->error_message );
		} else {
			$message = __( 'Scan stopped.', 'linksolva-broken-link-checker' );
		}
		return array(
			'scan_id'                => absint( $scan->id ),
			'state'                  => sanitize_key( $scan->state ),
			'phase'                  => $phase,
			'percent'                => $percent,
			'message'                => $message,
			'counts'                 => is_array( $counts ) && $counts ? $counts : Database::counts(),
			'stats'                  => $stats,
			'processed_sources'      => absint( $scan->processed_sources ),
			'discovered_occurrences' => absint( $scan->discovered_occurrences ),
			'error_message'          => sanitize_text_field( $scan->error_message ),
		);
	}

	/**
	 * Acquire a recoverable lock.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$token = wp_generate_uuid4();
		$lock  = array(
			'token'     => $token,
			'locked_at' => time(),
			'scan_id'   => 0,
		);
		if ( add_option( self::LOCK_OPTION, $lock, '', false ) ) {
			return true;
		}
		$old = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $old ) && ! empty( $old['locked_at'] ) && time() - absint( $old['locked_at'] ) < 1800 ) {
			return false;
		}
		global $wpdb;
		$old_serialized = maybe_serialize( $old );
		$new_serialized = maybe_serialize( $lock );
		$query          = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new_serialized, self::LOCK_OPTION, $old_serialized );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Atomic compare-and-swap lock update; all values are prepared and the options table is trusted.
		return 1 === (int) $wpdb->query( $query );
	}

	/**
	 * Prevent overlapping cron and admin requests from checking the same batch.
	 *
	 * @return array|false Lock value or false when another worker is active.
	 */
	private static function acquire_worker_lock() {
		$lock = array(
			'token'     => wp_generate_uuid4(),
			'locked_at' => time(),
		);
		if ( add_option( self::WORKER_LOCK_OPTION, $lock, '', false ) ) {
			return $lock;
		}

		$old = get_option( self::WORKER_LOCK_OPTION, array() );
		if ( is_array( $old ) && ! empty( $old['locked_at'] ) && time() - absint( $old['locked_at'] ) < self::WORKER_STALE_SECONDS ) {
			return false;
		}

		global $wpdb;
		$old_serialized = maybe_serialize( $old );
		$new_serialized = maybe_serialize( $lock );
		$query          = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $new_serialized, self::WORKER_LOCK_OPTION, $old_serialized );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoCache, WordPress.DB.PreparedSQL.NotPrepared -- Atomic compare-and-swap recovery for an abandoned worker lock; all values are prepared.
		return 1 === (int) $wpdb->query( $query ) ? $lock : false;
	}

	/**
	 * Release only the worker lock owned by this request.
	 *
	 * @param array $lock Lock value.
	 * @return void
	 */
	private static function release_worker_lock( $lock ) {
		global $wpdb;
		$query = $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::WORKER_LOCK_OPTION, maybe_serialize( $lock ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.NoCache, WordPress.DB.PreparedSQL.NotPrepared -- Delete only this request's atomic prepared worker-lock value.
		$wpdb->query( $query );
		wp_cache_delete( self::WORKER_LOCK_OPTION, 'options' );
	}

	/**
	 * Put scan ID into the lock.
	 *
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private static function set_lock_scan( $scan_id ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) ) {
			$lock['scan_id']   = absint( $scan_id );
			$lock['locked_at'] = time();
			update_option( self::LOCK_OPTION, $lock, false );
		}
	}

	/**
	 * Refresh lock timestamp.
	 *
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private static function touch_lock( $scan_id ) {
		$lock = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $lock ) && isset( $lock['scan_id'] ) && absint( $lock['scan_id'] ) === absint( $scan_id ) ) {
			$lock['locked_at'] = time();
			update_option( self::LOCK_OPTION, $lock, false );
		}
	}

	/**
	 * Release only this worker's lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Set the next scheduled scan time when scheduling is enabled.
	 *
	 * @return void
	 */
	private static function schedule_next() {
		$schedule = sanitize_key( Settings::get( 'schedule', 'manual' ) );
		if ( 'manual' === $schedule ) {
			delete_option( 'sblc_next_scan_at' );
			return;
		}
		update_option( 'sblc_next_scan_at', time() + Settings::schedule_interval( $schedule ), false );
	}

	/**
	 * Send an optional local completion summary.
	 *
	 * @param int $scan_id Scan ID.
	 * @return void
	 */
	private static function notify_if_enabled( $scan_id ) {
		if ( ! Settings::get( 'email_notifications' ) ) {
			return;
		}
		$email = sanitize_email( Settings::get( 'notification_email' ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$counts  = Database::counts();
		$subject = __( 'LinkSolva - Broken Link Checker scan complete', 'linksolva-broken-link-checker' );
		$stats   = Database::scan_stats( $scan_id );
		$message = sprintf(
			/* translators: 1: Unique URL count, 2: Broken count, 3: Needs-review count, 4: Redirect count, 5: Healthy count. */
			__( "Your local link scan is complete.\n\nUnique URLs: %1\$d\nBroken: %2\$d\nNeeds review: %3\$d\nRedirects: %4\$d\nHealthy: %5\$d\n\nOpen the WordPress admin to review evidence and source locations.", 'linksolva-broken-link-checker' ),
			absint( $counts['all'] ),
			absint( $counts['broken'] ),
			absint( $counts['needs_review'] ),
			absint( $counts['redirect'] ),
			absint( $counts['healthy'] )
		);
		if ( ! empty( $stats['skipped_resources'] ) ) {
			$skipped_message = sprintf(
				/* translators: %d: URLs that timed out or were skipped. */
				_n(
					'Timed out or skipped for manual review: %d URL.',
					'Timed out or skipped for manual review: %d URLs.',
					absint( $stats['skipped_resources'] ),
					'linksolva-broken-link-checker'
				),
				absint( $stats['skipped_resources'] )
			);
			$message .= "\n\n" . $skipped_message;
		}
		wp_mail( $email, $subject, $message );
	}
}
