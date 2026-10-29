<?php
/**
 * Remove plugin data only when the administrator explicitly enabled deletion.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$sblc_settings = get_option( 'sblc_settings', array() );
delete_option( 'sblc_scan_lock' );
delete_option( 'sblc_scan_worker_lock' );
delete_option( 'sblc_scan_priority' );
if ( empty( $sblc_settings['delete_data_on_uninstall'] ) ) {
	delete_option( 'sblc_next_scan_at' );
	wp_clear_scheduled_hook( 'sblc_worker' );
	return;
}

global $wpdb;
$sblc_tables = array(
	$wpdb->prefix . 'sblc_repairs',
	$wpdb->prefix . 'sblc_history_occurrences',
	$wpdb->prefix . 'sblc_history_resources',
	$wpdb->prefix . 'sblc_occurrences',
	$wpdb->prefix . 'sblc_resources',
	$wpdb->prefix . 'sblc_scans',
);
foreach ( $sblc_tables as $sblc_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall explicitly removes only fixed plugin-owned tables derived from $wpdb->prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$sblc_table}`" );
}
delete_option( 'sblc_settings' );
delete_option( 'sblc_db_version' );
delete_option( 'sblc_next_scan_at' );
wp_clear_scheduled_hook( 'sblc_worker' );
