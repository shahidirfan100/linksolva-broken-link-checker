<?php
/**
 * Install, upgrade, and lifecycle operations.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Creates schema and manages activation and deactivation state.
 */
final class Installer {
	const DB_VERSION = '1.1.2';

	/**
	 * Activate the plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		add_option( Settings::OPTION, Settings::defaults(), '', false );
		update_option( 'sblc_db_version', self::DB_VERSION, false );
		if ( ! wp_next_scheduled( 'sblc_worker' ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'sblc_minute', 'sblc_worker' );
		}
	}

	/**
	 * Add the single continuation interval used by the bounded scan worker.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules['sblc_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Every minute (LinkSolva - Broken Link Checker)', 'linksolva-broken-link-checker' ),
		);
		return $schedules;
	}

	/**
	 * Deactivate without deleting findings.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'sblc_worker' );
		delete_option( 'sblc_scan_lock' );
		delete_option( 'sblc_scan_worker_lock' );
		delete_option( 'sblc_next_scan_at' );
	}

	/**
	 * Create or upgrade tables after a plugin update.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION !== get_option( 'sblc_db_version' ) ) {
			self::create_tables();
			global $wpdb;
			$table = self::table_name( 'resources' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Normalize nullable legacy queue markers during a schema upgrade.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET checked_scan_id = 0 WHERE checked_scan_id IS NULL', $table ) );
			update_option( 'sblc_db_version', self::DB_VERSION, false );
			if ( Settings::get( 'scan_history_limit', 5 ) > 0 ) {
				Database::save_latest_scan_history();
				Database::prune_scan_history();
			}
		}
	}

	/**
	 * Return one plugin table name for upgrade maintenance.
	 *
	 * @param string $suffix Table suffix.
	 * @return string
	 */
	private static function table_name( $suffix ) {
		global $wpdb;
		return $wpdb->prefix . 'sblc_' . sanitize_key( $suffix );
	}

	/**
	 * Create or update plugin-owned tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset     = $wpdb->get_charset_collate();
		$resources           = $wpdb->prefix . 'sblc_resources';
		$occurrences         = $wpdb->prefix . 'sblc_occurrences';
		$scans               = $wpdb->prefix . 'sblc_scans';
		$history_resources   = $wpdb->prefix . 'sblc_history_resources';
		$history_occurrences = $wpdb->prefix . 'sblc_history_occurrences';
		$repairs             = $wpdb->prefix . 'sblc_repairs';

		$sql   = array();
		$sql[] = "CREATE TABLE {$resources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url longtext NOT NULL,
			url_hash char(64) NOT NULL,
			resource_type varchar(20) NOT NULL DEFAULT 'link',
			link_scope varchar(20) NOT NULL DEFAULT 'external',
			status varchar(32) NOT NULL DEFAULT 'unverified',
			confidence varchar(20) NOT NULL DEFAULT 'unverified',
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			error_code varchar(80) NOT NULL DEFAULT '',
			status_text varchar(191) NOT NULL DEFAULT '',
			explanation text NOT NULL,
			final_url longtext NOT NULL,
			redirect_chain longtext NOT NULL,
			request_history longtext NOT NULL,
			response_time decimal(10,3) NOT NULL DEFAULT 0,
			last_checked datetime NULL,
			next_check_at datetime NULL,
			retry_count smallint(5) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			last_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			checked_scan_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ignored tinyint(1) unsigned NOT NULL DEFAULT 0,
			manual_verified tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY status (status),
			KEY link_scope (link_scope),
			KEY last_scan_id (last_scan_id),
			KEY checked_scan_id (checked_scan_id),
			KEY next_check_at (next_check_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$occurrences} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL,
			scan_id bigint(20) unsigned NOT NULL,
			source_type varchar(32) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_field varchar(191) NOT NULL DEFAULT '',
			source_title varchar(255) NOT NULL DEFAULT '',
			source_url longtext NOT NULL,
			anchor_text text NOT NULL,
			context text NOT NULL,
			raw_url text NOT NULL,
			location varchar(80) NOT NULL DEFAULT '',
			editable tinyint(1) unsigned NOT NULL DEFAULT 0,
			occurrence_hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY occurrence_hash (occurrence_hash),
			KEY resource_id (resource_id),
			KEY scan_id (scan_id),
			KEY scan_resource (scan_id, resource_id),
			KEY source (source_type, source_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			state varchar(32) NOT NULL DEFAULT 'idle',
			provider varchar(32) NOT NULL DEFAULT 'posts',
			page bigint(20) unsigned NOT NULL DEFAULT 1,
			resource_cursor bigint(20) unsigned NOT NULL DEFAULT 0,
			settings_snapshot longtext NOT NULL,
			total_sources bigint(20) unsigned NOT NULL DEFAULT 0,
			processed_sources bigint(20) unsigned NOT NULL DEFAULT 0,
			discovered_occurrences bigint(20) unsigned NOT NULL DEFAULT 0,
			checked_resources bigint(20) unsigned NOT NULL DEFAULT 0,
			counts longtext NOT NULL,
			started_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			finished_at datetime NULL,
			error_message text NOT NULL,
			history_saved tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY state (state),
			KEY state_history (state, history_saved, id),
			KEY updated_at (updated_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$history_resources} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			resource_id bigint(20) unsigned NOT NULL,
			url longtext NOT NULL,
			resource_type varchar(20) NOT NULL DEFAULT 'link',
			link_scope varchar(20) NOT NULL DEFAULT 'external',
			status varchar(32) NOT NULL DEFAULT 'unverified',
			confidence varchar(20) NOT NULL DEFAULT 'unverified',
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			error_code varchar(80) NOT NULL DEFAULT '',
			status_text varchar(191) NOT NULL DEFAULT '',
			explanation text NOT NULL,
			final_url longtext NOT NULL,
			redirect_chain longtext NOT NULL,
			request_history longtext NOT NULL,
			response_time decimal(10,3) NOT NULL DEFAULT 0,
			last_checked datetime NULL,
			occurrence_count bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY scan_resource (scan_id, resource_id),
			KEY scan_status (scan_id, status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$history_occurrences} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			resource_id bigint(20) unsigned NOT NULL,
			source_type varchar(32) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_field varchar(191) NOT NULL DEFAULT '',
			source_title varchar(255) NOT NULL DEFAULT '',
			source_url longtext NOT NULL,
			anchor_text text NOT NULL,
			context text NOT NULL,
			raw_url text NOT NULL,
			location varchar(80) NOT NULL DEFAULT '',
			editable tinyint(1) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY scan_resource (scan_id, resource_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$repairs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			resource_id bigint(20) unsigned NOT NULL,
			occurrence_id bigint(20) unsigned NOT NULL,
			operation varchar(32) NOT NULL,
			source_type varchar(32) NOT NULL,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			source_field varchar(191) NOT NULL DEFAULT '',
			before_value longtext NOT NULL,
			after_value longtext NOT NULL,
			before_checksum char(64) NOT NULL,
			after_checksum char(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			undone tinyint(1) unsigned NOT NULL DEFAULT 0,
			undo_error text NOT NULL,
			PRIMARY KEY  (id),
			KEY resource_id (resource_id),
			KEY occurrence_id (occurrence_id),
			KEY undone (undone)
		) {$charset};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}
}
