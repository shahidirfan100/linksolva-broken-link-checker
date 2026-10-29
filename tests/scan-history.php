<?php
/**
 * Focused regression checks for saved scan history and retention.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['sblc_test_options'] = array(
		'sblc_settings' => array( 'scan_history_limit' => 5 ),
	);

	function __( $text, $domain = null ) {
		return $text;
	}

	function absint( $value ) {
		return abs( (int) $value );
	}

	function get_option( $name, $default = false ) {
		return isset( $GLOBALS['sblc_test_options'][ $name ] ) ? $GLOBALS['sblc_test_options'][ $name ] : $default;
	}

	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}

	function sanitize_key( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) );
	}

	function sanitize_text_field( $value ) {
		return (string) $value;
	}

	final class Sblc_History_Test_Wpdb {
		public $prefix = 'wp_';
		public $queries = array();
		public $scan;
		public $saved_ids = array( 30, 29, 28, 27 );
		public $zero_insert_rows = false;
		public $fail_occurrence_insert = false;
		public $insert_calls = 0;
		public $fail_delete = false;
		public $fail_stats = false;

		public function __construct() {
			$this->scan = (object) array(
				'id'           => 8,
				'state'        => 'completed',
				'history_saved' => 0,
			);
		}

		public function prepare( $query, ...$args ) {
			return $query;
		}

		public function get_row( $query, $output = null ) {
			if ( $this->fail_stats ) { return null; }
			return clone $this->scan;
		}

		public function get_col( $query ) {
			$this->queries[] = $query;
			return $this->saved_ids;
		}

		public function get_results( $query ) {
			$this->queries[] = $query;
			return array();
		}

		public function get_var( $query ) {
			$this->queries[] = $query;
			return 0;
		}

		public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
			$this->queries[] = 'UPDATE ' . $table;
			if ( 'wp_sblc_scans' === $table && isset( $data['history_saved'] ) ) {
				$this->scan->history_saved = $data['history_saved'];
			}
			return 1;
		}

		public function query( $query ) {
			$this->queries[] = $query;
			if ( $this->fail_delete && false !== strpos( $query, 'DELETE FROM' ) ) { return false; }
			if ( false !== strpos( $query, 'INSERT INTO' ) ) {
				++$this->insert_calls;
				if ( 2 === $this->insert_calls && $this->fail_occurrence_insert ) {
					return false;
				}
				if ( $this->zero_insert_rows ) {
					return 0;
				}
			}
			return 1;
		}

		public function esc_like( $value ) {
			return addcslashes( $value, '_%\\' );
		}
	}

	$GLOBALS['wpdb'] = new Sblc_History_Test_Wpdb();
}

namespace LinkSolva\BrokenLinkChecker {
	require_once __DIR__ . '/../includes/class-settings.php';
	require_once __DIR__ . '/../includes/class-database.php';

	$settings = Settings::all();
	if ( 5 !== $settings['scan_history_limit'] ) {
		fwrite( STDERR, "The default history limit should keep five completed scans.\n" );
		exit( 1 );
	}
	if ( 0 !== Settings::sanitize( array( 'scan_history_limit' => 0 ) )['scan_history_limit'] ) {
		fwrite( STDERR, "History should allow an explicit off setting.\n" );
		exit( 1 );
	}
	if ( 5 !== Settings::sanitize( array( 'scan_history_limit' => 7 ) )['scan_history_limit'] ) {
		fwrite( STDERR, "Unsupported history limits should fall back to five.\n" );
		exit( 1 );
	}

	global $wpdb;
	if ( ! Database::save_latest_scan_history() || 1 !== (int) $wpdb->scan->history_saved ) {
		fwrite( STDERR, "The completed scan was not marked as archived.\n" );
		exit( 1 );
	}
	$insert_queries = array_filter(
		$wpdb->queries,
		static function ( $query ) {
			return false !== strpos( $query, 'INSERT INTO' );
		}
	);
	if ( 2 !== count( $insert_queries ) ) {
		fwrite( STDERR, "History must copy both URL results and source locations.\n" );
		exit( 1 );
	}

	$query_count = count( $wpdb->queries );
	if ( Database::save_latest_scan_history() || $query_count !== count( $wpdb->queries ) ) {
		fwrite( STDERR, "Saving the same completed scan twice should not duplicate its snapshot.\n" );
		exit( 1 );
	}

	$wpdb->scan->history_saved = 0;
	$wpdb->zero_insert_rows   = true;
	if ( ! Database::save_scan_history( 8 ) || 1 !== (int) $wpdb->scan->history_saved ) {
		fwrite( STDERR, "A completed scan with no results should still be saved in history.\n" );
		exit( 1 );
	}
	$wpdb->zero_insert_rows = false;

	$wpdb->scan->history_saved      = 0;
	$wpdb->fail_occurrence_insert   = true;
	$wpdb->insert_calls             = 0;
	if ( Database::save_scan_history( 8 ) || 1 === (int) $wpdb->scan->history_saved ) {
		fwrite( STDERR, "A partial snapshot should not be marked as saved.\n" );
		exit( 1 );
	}
	$wpdb->fail_occurrence_insert = false;

	$wpdb->fail_stats = true;
	try {
		Database::scan_stats( 8 );
		fwrite( STDERR, "A failed stats query must not report an empty scan.\n" );
		exit( 1 );
	} catch ( \RuntimeException $expected ) {}
	$wpdb->fail_stats = false;

	$wpdb->fail_delete = true;
	if ( Database::prune_scan_history( 2 ) ) {
		fwrite( STDERR, "History pruning must report failed deletes.\n" );
		exit( 1 );
	}
	$wpdb->fail_delete = false;

	$before_prune = count( $wpdb->queries );
	if ( ! Database::prune_scan_history( 2 ) ) {
		fwrite( STDERR, "History pruning failed.\n" );
		exit( 1 );
	}
	$prune_queries = array_slice( $wpdb->queries, $before_prune );
	$prune_sql = implode( ' ', $prune_queries );
	if ( 2 !== substr_count( $prune_sql, 'DELETE FROM' ) || false === strpos( $prune_sql, 'UPDATE %i SET history_saved = 0' ) ) {
		fwrite( STDERR, "Pruning must remove both snapshot tables and clear the old scan markers.\n" );
		exit( 1 );
	}

	$before_clear = count( $wpdb->queries );
	if ( ! Database::prune_scan_history( 0 ) || 2 !== substr_count( implode( ' ', array_slice( $wpdb->queries, $before_clear ) ), 'DELETE FROM' ) ) {
		fwrite( STDERR, "Turning history off should remove every saved snapshot.\n" );
		exit( 1 );
	}
	$GLOBALS['sblc_test_options']['sblc_settings']['scan_history_limit'] = 0;
	$before_disabled_save = count( $wpdb->queries );
	$wpdb->scan->history_saved = 0;
	if ( Database::save_scan_history( 8 ) || $before_disabled_save !== count( $wpdb->queries ) ) {
		fwrite( STDERR, "A completed scan should not be archived when history is off.\n" );
		exit( 1 );
	}

	echo "Scan history snapshot and retention checks passed.\n";
}
