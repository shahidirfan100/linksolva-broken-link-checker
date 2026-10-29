<?php
/**
 * Focused regression check for time-limited URL verification.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WPINC', 'wp-includes' );
	define( 'LINKSOLVA_BLC_VERSION', '1.0.15' );
	class WP_HTTP_Requests_Hooks {}

	$GLOBALS['sblc_test_options'] = array(
		'sblc_settings' => array(
			'batch_size'        => 25,
			'parallel_requests' => 8,
		),
	);

	function __( $text, $domain = null ) {
		return $text;
	}

	function _n( $single, $plural, $number, $domain = null ) {
		return 1 === (int) $number ? $single : $plural;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['sblc_test_options'][ $name ] ) ? $GLOBALS['sblc_test_options'][ $name ] : $default;
	}

	function delete_option( $name ) {
		unset( $GLOBALS['sblc_test_options'][ $name ] );
		return true;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}

	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}

	function home_url( $path = '/' ) {
		return 'https://site.example' . $path;
	}

	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_-]/', '', $value ) );
	}

	function sanitize_text_field( $value ) {
		return (string) $value;
	}
}

namespace WpOrg\Requests {
	final class Requests {
		public static $calls = 0;

		public static function request_multiple( $requests ) {
			++self::$calls;
			return array();
		}
	}
}

namespace LinkSolva\BrokenLinkChecker {
	final class Url {
		public static function hash( $url ) {
			return hash( 'sha256', $url );
		}
	}

	final class Ssrf_Guard {
		public static $calls = 0;

		public static function validate( $url ) {
			++self::$calls;
			usleep( 300000 );
			return array(
				'safe' => true,
				'code' => '',
				'host' => 'slow-dns.example',
			);
		}
	}

	final class Database {
		public static $saved_results = array();
		public static $updates = array();
		public static $stats_override = null;
		public static $scan = null;
		public static $skipped_updates = 0;
		public static $history_saves = 0;

		public static function resources_for_scan( $scan_id, $limit ) {
			return array(
				(object) array(
					'id'          => 101,
					'url'         => 'https://slow-dns.example/path',
					'retry_count' => 0,
					'ignored'     => 0,
				),
			);
		}

		public static function mark_checked( $resource_id, $scan_id ) { return true; }

		public static function save_result( $resource_id, $scan_id, $result ) {
			self::$saved_results[] = array(
				'resource_id' => $resource_id,
				'scan_id'     => $scan_id,
				'result'      => $result,
			);
			return true;
		}

		public static function scan_stats( $scan_id ) {
			if ( is_array( self::$stats_override ) ) {
				return self::$stats_override;
			}
			return array(
				'total_resources'   => count( self::$saved_results ),
				'checked_resources' => count( self::$saved_results ),
				'skipped_resources' => count( self::$saved_results ),
			);
		}

		public static function update_scan( $scan_id, $data ) {
			self::$updates[] = $data;
			if ( self::$scan && (int) self::$scan->id === (int) $scan_id ) {
				foreach ( $data as $key => $value ) {
					self::$scan->{$key} = $value;
				}
			}
		}

		public static function get_scan( $scan_id ) {
			return self::$scan && (int) self::$scan->id === (int) $scan_id ? self::$scan : null;
		}

		public static function skip_pending_resources( $scan_id ) {
			if ( ! is_array( self::$stats_override ) ) {
				return false;
			}
			$pending = self::$stats_override['total_resources'] - self::$stats_override['checked_resources'];
			self::$skipped_updates += $pending;
			self::$stats_override['checked_resources'] = self::$stats_override['total_resources'];
			self::$stats_override['skipped_resources'] += $pending;
			return $pending;
		}

		public static function now() {
			return gmdate( 'Y-m-d H:i:s' );
		}

		public static function counts() {
			return array(
				'all'         => count( self::$saved_results ),
				'broken'      => 0,
				'needs_review' => count( self::$saved_results ),
				'redirect'    => 0,
				'healthy'     => 0,
				'ignored'     => 0,
			);
		}

		public static function save_scan_history( $scan_id ) {
			++self::$history_saves;
			return true;
		}

		public static function prune_scan_history( $limit = null ) {
			return true;
		}
	}

	require_once __DIR__ . '/../includes/class-settings.php';
	require_once __DIR__ . '/../includes/class-http-checker.php';
	require_once __DIR__ . '/../includes/class-scanner.php';
}

namespace {
	use LinkSolva\BrokenLinkChecker\Database;
	use LinkSolva\BrokenLinkChecker\Scanner;
	use LinkSolva\BrokenLinkChecker\Settings;

	$settings = Settings::all();
	if ( 10 !== $settings['batch_size'] || 4 !== $settings['parallel_requests'] ) {
		fwrite( STDERR, "Legacy worker settings were not capped.\n" );
		exit( 1 );
	}

	$limits = Settings::worker_limits();
	if ( $limits['batch_size'] < 1 || $limits['batch_size'] > 10 || $limits['parallel_requests'] < 1 || $limits['parallel_requests'] > 4 || $limits['execution_seconds'] < 1 ) {
		fwrite( STDERR, "Adaptive worker limits are out of range.\n" );
		exit( 1 );
	}

	$method = new \ReflectionMethod( Scanner::class, 'check_resources' );
	$method->setAccessible( true );
	$method->invoke( null, (object) array( 'id' => 7 ), microtime( true ) + 0.25 );
	if ( 1 !== \LinkSolva\BrokenLinkChecker\Ssrf_Guard::$calls || 0 !== \WpOrg\Requests\Requests::$calls ) {
		fwrite( STDERR, "The worker did not stop before dispatching a URL that exceeded its remaining time.\n" );
		exit( 1 );
	}

	if ( 1 !== count( Database::$saved_results ) ) {
		fwrite( STDERR, "The deferred URL was left pending instead of being recorded.\n" );
		exit( 1 );
	}

	$result = Database::$saved_results[0]['result'];
	if ( 'scan_time_limit' !== $result['error_code'] || 'unverified' !== $result['status'] || false === strpos( $result['explanation'], 'Review it manually' ) ) {
		fwrite( STDERR, "The deferred URL was not classified for manual review.\n" );
		exit( 1 );
	}

	if ( empty( Database::$updates[0]['checked_resources'] ) ) {
		fwrite( STDERR, "The deferred URL was not counted as checked for this scan.\n" );
		exit( 1 );
	}

	$progress_method = new \ReflectionMethod( Scanner::class, 'progress' );
	$progress_method->setAccessible( true );
	Database::$stats_override = array(
		'total_resources'   => 12,
		'checked_resources' => 11,
		'skipped_resources' => 0,
	);
	$checking = $progress_method->invoke(
		null,
		(object) array(
			'id'                     => 7,
			'state'                  => 'checking',
			'counts'                 => '{}',
			'processed_sources'     => 12,
			'discovered_occurrences' => 12,
			'error_message'          => '',
		)
	);
	if ( 91 !== $checking['percent'] || false === strpos( $checking['message'], '11 of 12 complete' ) ) {
		fwrite( STDERR, "The active scan did not expose its checked-to-total progress.\n" );
		exit( 1 );
	}
	Database::$stats_override = null;
	$progress = $progress_method->invoke(
		null,
		(object) array(
			'id'                    => 7,
			'state'                 => 'completed',
			'counts'                => '{}',
			'processed_sources'    => 1,
			'discovered_occurrences' => 1,
			'error_message'         => '',
		)
	);
	if ( 100 !== $progress['percent'] || 1 !== $progress['stats']['skipped_resources'] || false === strpos( $progress['message'], '1 URL' ) || false === strpos( $progress['message'], 'manual review' ) ) {
		fwrite( STDERR, "The completed scan did not report the skipped URL count for manual review.\n" );
		exit( 1 );
	}

	$reconcile_method = new \ReflectionMethod( Scanner::class, 'reconcile_scan' );
	$reconcile_method->setAccessible( true );
	Database::$stats_override = array(
		'total_resources'   => 3,
		'checked_resources' => 1,
		'skipped_resources' => 0,
	);
	Database::$scan = (object) array(
		'id'                     => 9,
		'state'                  => 'checking',
		'updated_at'             => gmdate( 'Y-m-d H:i:s', time() - 120 ),
		'counts'                 => '{}',
		'checked_resources'      => 1,
		'processed_sources'      => 3,
		'discovered_occurrences' => 3,
		'error_message'          => '',
	);
	$recovered = $reconcile_method->invoke( null, Database::$scan, true );
	if ( 'checking' !== $recovered->state || Database::$skipped_updates !== 0 ) {
		fwrite( STDERR, "A delayed worker must not skip unchecked URLs.\n" );
		exit( 1 );
	}

	Database::$stats_override = array(
		'total_resources'   => 3,
		'checked_resources' => 3,
		'skipped_resources' => 0,
	);
	Database::$scan = (object) array(
		'id'                     => 10,
		'state'                  => 'checking',
		'updated_at'             => gmdate( 'Y-m-d H:i:s' ),
		'counts'                 => '{}',
		'checked_resources'      => 3,
		'processed_sources'      => 3,
		'discovered_occurrences' => 3,
		'error_message'          => '',
	);
	$completed = $reconcile_method->invoke( null, Database::$scan, true );
	if ( 'completed' !== $completed->state ) {
		fwrite( STDERR, "A scan with all URLs checked did not transition to completed.\n" );
		exit( 1 );
	}

	echo "Scan progress, timeout recovery, and completion regression checks passed.\n";
}
