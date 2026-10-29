<?php
/** WordPress integration checks. Run with wp eval-file on a disposable site. */
use LinkSolva\BrokenLinkChecker\Database;
use LinkSolva\BrokenLinkChecker\Scanner;

function sblc_assert( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
update_option( 'sblc_settings', array_merge( get_option( 'sblc_settings', array() ), array( 'include_menus' => false, 'batch_size' => 1, 'parallel_requests' => 1 ) ), false );
$post_id = wp_insert_post( array( 'post_title' => 'LinkSolva integration fixture', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<a href="https://example.invalid/link">fixture</a>' ) );
sblc_assert( $post_id && ! is_wp_error( $post_id ), 'Fixture post creation failed.' );
$progress = Scanner::start();
sblc_assert( ! is_wp_error( $progress ), 'Could not start scan.' );
$scan_id = $progress['scan_id'];
$last = 0;
for ( $i = 0; $i < 50 && in_array( $progress['state'], array( 'discovering', 'checking' ), true ); ++$i ) {
	$progress = Scanner::step( $scan_id );
	sblc_assert( ! is_wp_error( $progress ), 'Scan worker returned an error.' );
	if ( 'checking' === $progress['state'] ) {
		sblc_assert( $progress['percent'] >= $last, 'Verification progress went backwards.' );
		$last = $progress['percent'];
	}
}
sblc_assert( 'completed' === $progress['state'], 'The fixture scan did not complete.' );
sblc_assert( 100 === $progress['percent'] && $progress['stats']['total_resources'] > 0, 'Completed scan counts are incorrect.' );
sblc_assert( Database::get_history_scan( $scan_id ), 'Completed scan was not saved in history.' );
$tables = Database::tables();
global $wpdb;
$occurrences = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['occurrences'], $scan_id ) );
sblc_assert( $occurrences > 0, 'Fixture occurrence was not saved.' );
$wpdb->update( $tables['scans'], array( 'history_saved' => 0 ), array( 'id' => $scan_id ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $tables['history_resources'] ) );
$result = Scanner::start();
sblc_assert( is_wp_error( $result ) && 'sblc_history_save_failed' === $result->get_error_code(), 'History failure did not stop the next scan.' );
$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['occurrences'], $scan_id ) );
sblc_assert( $remaining === $occurrences, 'History failure removed previous occurrences.' );
echo "WordPress scan and history integration checks passed.\n";
