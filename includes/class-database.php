<?php
/**
 * Small data-access layer for plugin-owned tables.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Provides data access for plugin-owned scan, result, occurrence, and repair tables.
 */
final class Database {
	/**
	 * Table names.
	 *
	 * @return array
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'resources'           => $wpdb->prefix . 'sblc_resources',
			'occurrences'         => $wpdb->prefix . 'sblc_occurrences',
			'scans'               => $wpdb->prefix . 'sblc_scans',
			'history_resources'   => $wpdb->prefix . 'sblc_history_resources',
			'history_occurrences' => $wpdb->prefix . 'sblc_history_occurrences',
			'repairs'             => $wpdb->prefix . 'sblc_repairs',
		);
	}

	/**
	 * Current UTC database time.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Insert or update a URL resource for a scan.
	 *
	 * @param string $url          Normalized URL.
	 * @param string $type         Resource type.
	 * @param int    $scan_id      Scan ID.
	 * @return int
	 */
	public static function upsert_resource( $url, $type, $scan_id ) {
		global $wpdb;
		$tables = self::tables();
		$hash   = Url::hash( $url );
		$now    = self::now();
		$scope  = Url::scope( $url );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, last_scan_id, ignored FROM %i WHERE url_hash = %s LIMIT 1', $tables['resources'], $hash ) );
		$id       = $existing ? (int) $existing->id : 0;

		if ( $id ) {
			$data    = array(
				'url'           => $url,
				'resource_type' => $type,
				'link_scope'    => $scope,
				'last_seen'     => $now,
				'last_scan_id'  => absint( $scan_id ),
			);
			$formats = array( '%s', '%s', '%s', '%s', '%d' );

			/*
			 * A URL can occur in many source records. Only the first occurrence in
			 * a scan may reset its result; otherwise later pages would put an already
			 * verified URL back into the queue and the scan could never settle.
			 */
			if ( absint( $existing->last_scan_id ) !== absint( $scan_id ) ) {
				if ( ! empty( $existing->ignored ) ) {
					/* Ignored resources remain ignored, but are counted as checked. */
					$data['checked_scan_id'] = absint( $scan_id );
					$formats[]               = '%d';
				} else {
					$data    = array_merge(
						$data,
						array(
							'status'          => 'pending',
							'confidence'      => 'pending',
							'http_code'       => 0,
							'error_code'      => '',
							'status_text'     => __( 'Queued', 'linksolva-broken-link-checker' ),
							'explanation'     => __( 'This URL has been rediscovered and is waiting for its verification request.', 'linksolva-broken-link-checker' ),
							'final_url'       => '',
							'redirect_chain'  => '',
							'request_history' => '',
							'response_time'   => 0,
							'last_checked'    => null,
							'next_check_at'   => null,
							'retry_count'     => 0,
							'checked_scan_id' => 0,
						)
					);
					$formats = array_merge( $formats, array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d' ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
			$wpdb->update( $tables['resources'], $data, array( 'id' => $id ), $formats, array( '%d' ) );
			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		$wpdb->insert(
			$tables['resources'],
			array(
				'url'           => $url,
				'url_hash'      => $hash,
				'resource_type' => $type,
				'link_scope'    => $scope,
				'status'        => 'pending',
				'confidence'    => 'pending',
				'status_text'   => __( 'Queued', 'linksolva-broken-link-checker' ),
				'explanation'   => __( 'This URL has been discovered and is waiting for its verification request.', 'linksolva-broken-link-checker' ),
				'first_seen'    => $now,
				'last_seen'     => $now,
				'last_scan_id'  => absint( $scan_id ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add one source occurrence once.
	 *
	 * @param array $data Occurrence data.
	 * @return int
	 */
	public static function add_occurrence( $data ) {
		global $wpdb;
		$tables = self::tables();
		$hash   = hash( 'sha256', wp_json_encode( array( $data['resource_id'], $data['scan_id'], $data['source_type'], $data['source_id'], $data['source_field'], $data['raw_url'], $data['location'], absint( $data['occurrence_index'] ) ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		$found = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE occurrence_hash = %s LIMIT 1', $tables['occurrences'], $hash ) );
		if ( $found ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		$wpdb->insert(
			$tables['occurrences'],
			array(
				'resource_id'     => absint( $data['resource_id'] ),
				'scan_id'         => absint( $data['scan_id'] ),
				'source_type'     => sanitize_key( $data['source_type'] ),
				'source_id'       => absint( $data['source_id'] ),
				'source_field'    => sanitize_text_field( $data['source_field'] ),
				'source_title'    => sanitize_text_field( $data['source_title'] ),
				'source_url'      => esc_url_raw( $data['source_url'] ),
				'anchor_text'     => wp_strip_all_tags( $data['anchor_text'] ),
				'context'         => sanitize_textarea_field( $data['context'] ),
				'raw_url'         => sanitize_text_field( $data['raw_url'] ),
				'location'        => sanitize_text_field( $data['location'] ),
				'editable'        => ! empty( $data['editable'] ) ? 1 : 0,
				'occurrence_hash' => $hash,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return object|null
	 */
	public static function get_scan( $scan_id ) {
		global $wpdb;
		$table = self::tables()['scans'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, absint( $scan_id ) ) );
	}

	/**
	 * Fetch the newest active scan; Scanner reconciles stale rows before resuming.
	 *
	 * @return object|null
	 */
	public static function get_active_scan() {
		global $wpdb;
		$table = self::tables()['scans'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE state IN ('discovering','checking','cancelling') ORDER BY id DESC LIMIT 1", $table ) );
	}

	/**
	 * Get the most recent scan.
	 *
	 * @return object|null
	 */
	public static function get_latest_scan() {
		global $wpdb;
		$table = self::tables()['scans'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $table ) );
	}

	/**
	 * Start a clean occurrence view while preserving historical resource records.
	 *
	 * @return bool
	 */
	public static function reset_for_scan() {
		global $wpdb;
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Resetting plugin-owned scan state is intentionally direct.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $tables['occurrences'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Resetting plugin-owned scan state is intentionally direct.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_scan_id = 0, checked_scan_id = 0', $tables['resources'] ) );
		return false !== $deleted;
	}

	/**
	 * Mark a resource as checked without making a network request.
	 *
	 * @param int $resource_id Resource ID.
	 * @param int $scan_id     Scan ID.
	 * @return bool
	 */
	public static function mark_checked( $resource_id, $scan_id ) {
		global $wpdb;
		$table = self::tables()['resources'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		return false !== $wpdb->update( $table, array( 'checked_scan_id' => absint( $scan_id ) ), array( 'id' => absint( $resource_id ) ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Return scan progress numbers.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array
	 */
	public static function scan_stats( $scan_id ) {
		global $wpdb;
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live scan progress must not be cached.
		$stats = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS total_resources, SUM(CASE WHEN checked_scan_id = %d THEN 1 ELSE 0 END) AS checked_resources, SUM(CASE WHEN checked_scan_id = %d AND status IN ('timeout', 'unverified', 'redirect') AND error_code IN (%s, %s, %s, %s, %s) THEN 1 ELSE 0 END) AS skipped_resources FROM %i WHERE last_scan_id = %d",
				absint( $scan_id ),
				absint( $scan_id ),
				'scan_time_limit',
				'timeout',
				'verification_deferred',
				'redirect_deferred',
				'scan_stalled',
				$tables['resources'],
				absint( $scan_id )
			),
			ARRAY_A
		);
		$stats = is_array( $stats ) ? $stats : array();
		return array(
			'total_resources'   => isset( $stats['total_resources'] ) ? absint( $stats['total_resources'] ) : 0,
			'checked_resources' => isset( $stats['checked_resources'] ) ? absint( $stats['checked_resources'] ) : 0,
			'skipped_resources' => isset( $stats['skipped_resources'] ) ? absint( $stats['skipped_resources'] ) : 0,
		);
	}

	/**
	 * Save the completed scan's findings before the live rows are reused.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public static function save_scan_history( $scan_id ) {
		global $wpdb;
		if ( 0 === absint( Settings::get( 'scan_history_limit', 5 ) ) ) {
			return false;
		}

		$scan_id = absint( $scan_id );
		$scan    = self::get_scan( $scan_id );
		if ( ! $scan || 'completed' !== $scan->state ) {
			return false;
		}
		if ( ! empty( $scan->history_saved ) ) {
			return true;
		}
		$tables = self::tables();
		self::delete_history_rows( $tables, array( $scan_id ) );

		// Copy in SQL to avoid loading the site's full findings into PHP memory.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A completed scan must be snapshotted before the live resource rows are reused.
		$resources_saved = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (scan_id, resource_id, url, resource_type, link_scope, status, confidence, http_code, error_code, status_text, explanation, final_url, redirect_chain, request_history, response_time, last_checked, occurrence_count) SELECT %d, r.id, r.url, r.resource_type, r.link_scope, r.status, r.confidence, r.http_code, r.error_code, r.status_text, r.explanation, r.final_url, r.redirect_chain, r.request_history, r.response_time, r.last_checked, COALESCE(o.occurrence_count, 0) FROM %i r LEFT JOIN (SELECT resource_id, COUNT(*) AS occurrence_count FROM %i WHERE scan_id = %d GROUP BY resource_id) o ON o.resource_id = r.id WHERE r.last_scan_id = %d',
				$tables['history_resources'],
				$scan_id,
				$tables['resources'],
				$tables['occurrences'],
				$scan_id,
				$scan_id
			)
		);
		if ( false === $resources_saved ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Preserve source evidence from the just-completed scan.
		$occurrences_saved = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (scan_id, resource_id, source_type, source_id, source_field, source_title, source_url, anchor_text, context, raw_url, location, editable) SELECT %d, o.resource_id, o.source_type, o.source_id, o.source_field, o.source_title, o.source_url, o.anchor_text, o.context, o.raw_url, o.location, o.editable FROM %i o WHERE o.scan_id = %d',
				$tables['history_occurrences'],
				$scan_id,
				$tables['occurrences'],
				$scan_id
			)
		);
		if ( false === $occurrences_saved ) {
			self::delete_history_rows( $tables, array( $scan_id ) );
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Mark this completed scan as available in read-only history.
		$saved = $wpdb->update( $tables['scans'], array( 'history_saved' => 1 ), array( 'id' => $scan_id ), array( '%d' ), array( '%d' ) );
		if ( false === $saved ) {
			self::delete_history_rows( $tables, array( $scan_id ) );
			return false;
		}

		return true;
	}

	/**
	 * Save the latest completed scan if its live results have not been archived.
	 *
	 * @return bool
	 */
	public static function save_latest_scan_history() {
		$scan = self::get_latest_scan();
		if ( ! $scan || 'completed' !== $scan->state || ! empty( $scan->history_saved ) ) {
			return false;
		}
		return self::save_scan_history( $scan->id );
	}

	/**
	 * Keep only the configured number of saved completed scans.
	 *
	 * @param int|null $limit Maximum number of completed runs to keep.
	 * @return bool
	 */
	public static function prune_scan_history( $limit = null ) {
		global $wpdb;
		$limit = null === $limit ? absint( Settings::get( 'scan_history_limit', 5 ) ) : absint( $limit );
		$limit = min( 25, $limit );
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Retention is applied to plugin-owned scan records.
		$saved_ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM %i WHERE state = %s AND history_saved = 1 ORDER BY id DESC", $tables['scans'], 'completed' )
		);
		$saved_ids = array_map( 'absint', is_array( $saved_ids ) ? $saved_ids : array() );
		$remove_ids = array_slice( $saved_ids, $limit );
		if ( empty( $remove_ids ) ) {
			return true;
		}

		self::delete_history_rows( $tables, $remove_ids );
		$placeholders = implode( ', ', array_fill( 0, count( $remove_ids ), '%d' ) );
		$params       = array_merge( array( $tables['scans'] ), $remove_ids );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The list contains only one %d per sanitized scan ID; table and IDs are passed to prepare().
		return false !== $wpdb->query( $wpdb->prepare( "UPDATE %i SET history_saved = 0 WHERE id IN ({$placeholders})", ...$params ) );
	}

	/**
	 * Return saved completed scan summaries.
	 *
	 * @param int $limit Maximum number of runs to return.
	 * @return array
	 */
	public static function scan_history( $limit = 25 ) {
		global $wpdb;
		$tables = self::tables();
		$limit  = min( 25, absint( $limit ) );
		if ( 0 === $limit ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- History rows are plugin-owned and should not be served from a stale cache.
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, state, total_sources, processed_sources, discovered_occurrences, checked_resources, counts, started_at, finished_at FROM %i WHERE state = %s AND history_saved = 1 ORDER BY id DESC LIMIT %d",
				$tables['scans'],
				'completed',
				$limit
			)
		);
	}

	/**
	 * Get a completed scan that still has saved results.
	 *
	 * @param int $scan_id Scan ID.
	 * @return object|null
	 */
	public static function get_history_scan( $scan_id ) {
		global $wpdb;
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Validate that the requested saved scan is still retained.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, state, started_at, finished_at, counts, checked_resources, discovered_occurrences FROM %i WHERE id = %d AND state = %s AND history_saved = 1 LIMIT 1",
				$tables['scans'],
				absint( $scan_id ),
				'completed'
			)
		);
	}

	/**
	 * List resources from a saved scan.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $args    Pagination and filters.
	 * @return array
	 */
	public static function scan_history_resources( $scan_id, $args ) {
		global $wpdb;
		$table    = self::tables()['history_resources'];
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$where    = array( 'scan_id = %d' );
		$params   = array( absint( $scan_id ) );
		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$status = sanitize_key( $args['status'] );
			if ( 'needs_review' === $status ) {
				$where[] = "status IN ('blocked','rate_limited','temporary_error','ssl_error','dns_error','timeout','unverified')";
			} else {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
		}
		if ( ! empty( $args['type'] ) && 'all' !== $args['type'] ) {
			$where[]  = 'resource_type = %s';
			$params[] = sanitize_key( $args['type'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}
		$where_sql   = implode( ' AND ', $where );
		$count_args = array_merge( array( $table ), $params );
		$list_args  = array_merge( array( $table ), $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where_sql is assembled only from fixed clauses and %s/%d placeholders; request values are separately prepared.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", ...$count_args ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- where_sql is assembled only from fixed clauses and %s/%d placeholders; request values are separately prepared.
		$items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY FIELD(status, 'broken','redirect','blocked','rate_limited','temporary_error','ssl_error','dns_error','timeout','unverified','pending','healthy','ignored'), id DESC LIMIT %d OFFSET %d", ...$list_args ) );
		return array( 'total' => $total, 'items' => $items );
	}

	/**
	 * Get one saved resource result.
	 *
	 * @param int $scan_id     Scan ID.
	 * @param int $resource_id Resource ID.
	 * @return object|null
	 */
	public static function get_scan_history_resource( $scan_id, $resource_id ) {
		global $wpdb;
		$table = self::tables()['history_resources'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Historical evidence is read from the plugin-owned snapshot table.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE scan_id = %d AND resource_id = %d LIMIT 1', $table, absint( $scan_id ), absint( $resource_id ) ) );
	}

	/**
	 * Get source locations saved with a historical resource result.
	 *
	 * @param int $scan_id     Scan ID.
	 * @param int $resource_id Resource ID.
	 * @return array
	 */
	public static function scan_history_occurrences( $scan_id, $resource_id ) {
		global $wpdb;
		$table = self::tables()['history_occurrences'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Historical source evidence is intentionally read-only.
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE scan_id = %d AND resource_id = %d ORDER BY id ASC', $table, absint( $scan_id ), absint( $resource_id ) )
		);
	}

	/**
	 * Delete saved result and source rows for the supplied scans.
	 *
	 * @param array $tables   Plugin table names.
	 * @param array $scan_ids Scan IDs.
	 * @return void
	 */
	private static function delete_history_rows( $tables, $scan_ids ) {
		global $wpdb;
		$scan_ids = array_values( array_filter( array_map( 'absint', $scan_ids ) ) );
		if ( empty( $scan_ids ) ) {
			return;
		}
		$placeholders = implode( ', ', array_fill( 0, count( $scan_ids ), '%d' ) );
		foreach ( array( 'history_occurrences', 'history_resources' ) as $key ) {
			$params = array_merge( array( $tables[ $key ] ), $scan_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The list contains only one %d per sanitized scan ID; table and IDs are passed to prepare().
			$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE scan_id IN ({$placeholders})", ...$params ) );
		}
	}

	/**
	 * Get one repair record.
	 *
	 * @param int $repair_id Repair ID.
	 * @return object|null
	 */
	public static function get_repair( $repair_id ) {
		global $wpdb;
		$table = self::tables()['repairs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, absint( $repair_id ) ) );
	}

	/**
	 * Fetch compact repair history for one resource.
	 *
	 * @param int $resource_id Resource ID.
	 * @return array
	 */
	public static function repairs_for_resource( $resource_id ) {
		global $wpdb;
		$table = self::tables()['repairs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned audit history requires direct, uncached access.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, operation, source_type, source_id, source_field, user_id, created_at, undone, undo_error FROM %i WHERE resource_id = %d ORDER BY id DESC LIMIT 20', $table, absint( $resource_id ) ) );
	}

	/**
	 * Insert a scan.
	 *
	 * @return int
	 */
	public static function insert_scan() {
		global $wpdb;
		$table = self::tables()['scans'];
		$now   = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		$wpdb->insert(
			$table,
			array(
				'state'             => 'discovering',
				'provider'          => 'posts',
				'page'              => 1,
				'settings_snapshot' => wp_json_encode( Settings::all() ),
				'started_at'        => $now,
				'updated_at'        => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update scan fields.

	 * @param int   $scan_id Scan ID.
	 * @param array $data    Fields.
	 * @return bool
	 */
	public static function update_scan( $scan_id, $data ) {
		global $wpdb;
		$table              = self::tables()['scans'];
		$data['updated_at'] = self::now();
		$formats            = array();
		foreach ( $data as $key => $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		return false !== $wpdb->update( $table, $data, array( 'id' => absint( $scan_id ) ), $formats, array( '%d' ) );
	}

	/**
	 * Get resources waiting to be checked in a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $limit   Limit.
	 * @return array
	 */
	public static function resources_for_scan( $scan_id, $limit ) {
		global $wpdb;
		$table = self::tables()['resources'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live scan queue must not be cached.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE last_scan_id = %d AND COALESCE(checked_scan_id, 0) <> %d ORDER BY id ASC LIMIT %d', $table, absint( $scan_id ), absint( $scan_id ), absint( $limit ) ) );
	}

	/**
	 * Mark unverified resources for a stalled scan as skipped for manual review.
	 *
	 * @param int $scan_id Scan ID.
	 * @return int|false Number of updated resources or false on database error.
	 */
	public static function skip_pending_resources( $scan_id ) {
		global $wpdb;
		$table = self::tables()['resources'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Recovery must terminalize only this scan's unchecked resource rows.
		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = %s, confidence = %s, http_code = 0, error_code = %s, status_text = %s, explanation = %s, final_url = url, redirect_chain = '[]', request_history = '[]', response_time = 0, last_checked = %s, next_check_at = NULL, checked_scan_id = %d WHERE last_scan_id = %d AND COALESCE(checked_scan_id, 0) <> %d",
				$table,
				'unverified',
				'unverified',
				'scan_stalled',
				__( 'Skipped for review', 'linksolva-broken-link-checker' ),
				__( 'The scan worker stopped responding before this URL could be verified. Review it manually or select Recheck to try again.', 'linksolva-broken-link-checker' ),
				self::now(),
				absint( $scan_id ),
				absint( $scan_id ),
				absint( $scan_id )
			)
		);
	}

	/**
	 * Get a resource with occurrence count.
	 *
	 * @param int $resource_id Resource ID.
	 * @return object|null
	 */
	public static function get_resource( $resource_id ) {
		global $wpdb;
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable tables require direct, uncached access.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT r.*, COUNT(o.id) AS occurrence_count FROM %i r LEFT JOIN %i o ON o.resource_id = r.id WHERE r.id = %d GROUP BY r.id LIMIT 1', $tables['resources'], $tables['occurrences'], absint( $resource_id ) ) );
	}

	/**
	 * Update one occurrence after a source repair.
	 *
	 * @param int   $occurrence_id Occurrence ID.
	 * @param array $data          Allowed occurrence fields.
	 * @return bool
	 */
	public static function update_occurrence( $occurrence_id, $data ) {
		global $wpdb;
		$allowed = array( 'resource_id', 'raw_url', 'anchor_text', 'context', 'location' );
		$data    = array_intersect_key( $data, array_flip( $allowed ) );
		if ( empty( $data ) ) {
			return false;
		}
		$formats = array();
		foreach ( $data as $key => $value ) {
			if ( 'resource_id' === $key ) {
				$data[ $key ] = absint( $value );
				$formats[]    = '%d';
			} elseif ( 'raw_url' === $key ) {
				$data[ $key ] = sanitize_text_field( $value );
				$formats[]    = '%s';
			} else {
				$data[ $key ] = 'anchor_text' === $key ? wp_strip_all_tags( $value ) : sanitize_textarea_field( $value );
				$formats[]    = '%s';
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned occurrence metadata must be updated with direct access.
		return false !== $wpdb->update( self::tables()['occurrences'], $data, array( 'id' => absint( $occurrence_id ) ), $formats, array( '%d' ) );
	}

	/**
	 * Hide a resource that no longer has source occurrences.
	 *
	 * Historical resource evidence remains stored for audit and undo, while
	 * current dashboard counts omit the orphaned URL.
	 *
	 * @param int $resource_id Resource ID.
	 * @return bool
	 */
	public static function retire_resource_if_orphaned( $resource_id ) {
		global $wpdb;
		$tables = self::tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Orphan checks must read current occurrence state without cache staleness.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE resource_id = %d', $tables['occurrences'], absint( $resource_id ) ) );
		if ( $count > 0 ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Orphaned plugin resource is intentionally hidden from current findings.
		return false !== $wpdb->update(
			$tables['resources'],
			array(
				'last_scan_id'    => 0,
				'checked_scan_id' => 0,
			),
			array( 'id' => absint( $resource_id ) ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Restore a resource to a scan queue after an undo.
	 *
	 * @param int $resource_id Resource ID.
	 * @param int $scan_id     Scan ID.
	 * @return bool
	 */
	public static function restore_resource_for_scan( $resource_id, $scan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Undo must restore the plugin-owned resource to the source scan.
		return false !== $wpdb->update(
			self::tables()['resources'],
			array(
				'last_scan_id'    => absint( $scan_id ),
				'checked_scan_id' => 0,
				'ignored'         => 0,
				'manual_verified' => 0,
				'status'          => 'unverified',
				'confidence'      => 'unverified',
				'status_text'     => __( 'Recheck needed', 'linksolva-broken-link-checker' ),
				'explanation'     => __( 'The source was restored. Recheck this resource to collect fresh evidence.', 'linksolva-broken-link-checker' ),
				'last_checked'    => null,
				'next_check_at'   => null,
				'retry_count'     => 0,
			),
			array( 'id' => absint( $resource_id ) ),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * List resources.

	 * @param array $args Filter arguments.
	 * @return array
	 */
	public static function list_resources( $args ) {
		global $wpdb;
		$tables   = self::tables();
		$page     = max( 1, absint( $args['page'] ) );
		$per_page = min( 100, max( 1, absint( $args['per_page'] ) ) );
		$where    = array( 'r.last_scan_id > 0' );
		$params   = array();
		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$status = sanitize_key( $args['status'] );
			if ( 'needs_review' === $status ) {
				$where[] = "r.status IN ('blocked','rate_limited','temporary_error','ssl_error','dns_error','timeout','unverified')";
			} else {
				$where[]  = 'r.status = %s';
				$params[] = $status;
			}
		}
		if ( ! empty( $args['type'] ) && 'all' !== $args['type'] ) {
			$where[]  = 'r.resource_type = %s';
			$params[] = sanitize_key( $args['type'] );
		}
		if ( ! empty( $args['scope'] ) && in_array( $args['scope'], array( 'internal', 'external' ), true ) ) {
			$where[]  = 'r.link_scope = %s';
			$params[] = sanitize_key( $args['scope'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]  = 'r.url LIKE %s';
			$params[] = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
		}
		$where_sql    = implode( ' AND ', $where );
		$count_sql    = "SELECT COUNT(*) FROM %i r WHERE {$where_sql}";
		$list_sql     = "SELECT r.*, COUNT(o.id) AS occurrence_count FROM %i r LEFT JOIN %i o ON o.resource_id = r.id WHERE {$where_sql} GROUP BY r.id ORDER BY FIELD(r.status, 'broken','redirect','blocked','rate_limited','temporary_error','ssl_error','dns_error','timeout','unverified','pending','healthy','ignored'), r.last_checked DESC, r.id DESC LIMIT %d OFFSET %d";
		$count_params = $params;
		array_unshift( $count_params, $tables['resources'] );
		$list_params = $params;
		array_unshift( $list_params, $tables['resources'], $tables['occurrences'] );
		$list_params[] = $per_page;
		$list_params[] = ( $page - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query fragments are allowlisted above and all values use placeholders.
		$count_query = $wpdb->prepare( $count_sql, $count_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query fragments are allowlisted above and all values use placeholders.
		$list_query = $wpdb->prepare( $list_sql, $list_params );
		return array(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared from allowlisted clauses and must not be cached.
			'total' => (int) $wpdb->get_var( $count_query ),
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query is prepared from allowlisted clauses and must not be cached.
			'items' => $wpdb->get_results( $list_query ),
		);
	}

	/**
	 * Get occurrences for a resource.
	 *
	 * @param int $resource_id Resource ID.
	 * @return array
	 */
	public static function occurrences( $resource_id ) {
		global $wpdb;
		$table = self::tables()['occurrences'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE resource_id = %d ORDER BY id ASC', $table, absint( $resource_id ) ) );
	}

	/**
	 * Fetch one occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return object|null
	 */
	public static function get_occurrence( $occurrence_id ) {
		global $wpdb;
		$table = self::tables()['occurrences'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct, uncached access.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $table, absint( $occurrence_id ) ) );
	}

	/**
	 * Insert an audit record for a repair.
	 *
	 * @param array $data Repair data.
	 * @return int
	 */
	public static function add_repair( $data ) {
		global $wpdb;
		$table = self::tables()['repairs'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned audit table requires direct access.
		$wpdb->insert( $table, $data, array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a repair as undone or conflicted.
	 *
	 * @param int   $repair_id Repair ID.
	 * @param array $data      Data.
	 * @return bool
	 */
	public static function update_repair( $repair_id, $data ) {
		global $wpdb;
		$table   = self::tables()['repairs'];
		$formats = array();
		foreach ( $data as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned audit table requires direct access.
		return false !== $wpdb->update( $table, $data, array( 'id' => absint( $repair_id ) ), $formats, array( '%d' ) );
	}

	/**
	 * Counts for dashboard.
	 *
	 * @return array
	 */
	public static function counts() {
		global $wpdb;
		$table = self::tables()['resources'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Live dashboard counts must not be cached.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS total FROM %i WHERE last_scan_id > 0 GROUP BY status', $table ), ARRAY_A );
		$out  = array(
			'all'          => 0,
			'broken'       => 0,
			'redirect'     => 0,
			'needs_review' => 0,
			'blocked'      => 0,
			'healthy'      => 0,
			'ignored'      => 0,
		);
		foreach ( $rows as $row ) {
			$status      = sanitize_key( $row['status'] );
			$total       = absint( $row['total'] );
			$out['all'] += $total;
			if ( isset( $out[ $status ] ) ) {
				$out[ $status ] += $total;
			}
			if ( in_array( $status, array( 'blocked', 'rate_limited', 'temporary_error', 'ssl_error', 'dns_error', 'timeout', 'unverified' ), true ) ) {
				$out['needs_review'] += $total;
			}
		}
		return $out;
	}

	/**
	 * Update verification result.
	 *
	 * @param int   $resource_id Resource ID.
	 * @param int   $scan_id     Scan ID.
	 * @param array $result      Result fields.
	 * @return bool
	 */
	public static function save_result( $resource_id, $scan_id, $result ) {
		global $wpdb;
		$table = self::tables()['resources'];
		$data  = array(
			'status'          => sanitize_key( $result['status'] ),
			'confidence'      => sanitize_key( $result['confidence'] ),
			'http_code'       => absint( $result['http_code'] ),
			'error_code'      => sanitize_key( $result['error_code'] ),
			'status_text'     => sanitize_text_field( $result['status_text'] ),
			'explanation'     => sanitize_textarea_field( $result['explanation'] ),
			'final_url'       => esc_url_raw( $result['final_url'] ),
			'redirect_chain'  => wp_json_encode( $result['redirect_chain'] ),
			'request_history' => wp_json_encode( $result['request_history'] ),
			'response_time'   => (float) $result['response_time'],
			'last_checked'    => self::now(),
			'next_check_at'   => ! empty( $result['next_check_at'] ) ? sanitize_text_field( $result['next_check_at'] ) : null,
			'retry_count'     => absint( $result['retry_count'] ),
			'checked_scan_id' => absint( $scan_id ),
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		return false !== $wpdb->update( $table, $data, array( 'id' => absint( $resource_id ) ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d' ), array( '%d' ) );
	}

	/**
	 * Change resource flags.
	 *
	 * @param int   $resource_id Resource ID.
	 * @param array $data        Data.
	 * @return bool
	 */
	public static function update_resource( $resource_id, $data ) {
		global $wpdb;
		$table = self::tables()['resources'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Plugin-owned mutable table requires direct access.
		return false !== $wpdb->update( $table, $data, array( 'id' => absint( $resource_id ) ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) );
	}
}
