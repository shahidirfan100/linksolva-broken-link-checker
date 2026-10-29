<?php
/** WordPress integration checks. Run with wp eval-file on a disposable site. */
use LinkSolva\BrokenLinkChecker\Database;
use LinkSolva\BrokenLinkChecker\Scanner;

function sblc_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function sblc_complete_scan( $progress, $check_delay = false ) {
	global $wpdb;
	$scan_id = $progress['scan_id'];
	$saw_early_check = 'discovering' === $progress['state'] && $progress['stats']['checked_resources'] > 0 && $progress['processed_sources'] < 3;
	$checked_delay = false;
	for ( $i = 0; $i < 100 && in_array( $progress['state'], array( 'discovering', 'checking' ), true ); ++$i ) {
		if ( $check_delay && ! $checked_delay && 'discovering' === $progress['state'] && $progress['stats']['checked_resources'] > 0 ) {
			$wpdb->update( Database::tables()['scans'], array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ), array( 'id' => $scan_id ) );
			$delayed = Scanner::latest_progress();
			sblc_assert( 'discovering' === $delayed['state'] && $delayed['stats']['checked_resources'] === $progress['stats']['checked_resources'], 'A delayed worker incorrectly completed the scan.' );
			$checked_delay = true;
		}
		if ( $progress['stats']['total_resources'] > 0 ) {
			$expected = (int) floor( 100 * $progress['stats']['checked_resources'] / $progress['stats']['total_resources'] );
			sblc_assert( $progress['percent'] === $expected, 'Displayed progress does not match URLs checked so far.' );
		}
		$progress = Scanner::step( $scan_id );
		sblc_assert( ! is_wp_error( $progress ), 'Scan worker returned an error.' );
		$saw_early_check = $saw_early_check || ( 'discovering' === $progress['state'] && $progress['stats']['checked_resources'] > 0 && $progress['processed_sources'] < 3 );
	}
	sblc_assert( 'completed' === $progress['state'], 'The fixture scan did not complete.' );
	sblc_assert( 100 === $progress['percent'] && $progress['stats']['total_resources'] >= 3, 'Completed scan counts are incorrect.' );
	if ( $check_delay ) {
		sblc_assert( $checked_delay && $saw_early_check, 'The scan did not check URLs during discovery or resume after a delay.' );
	}

	return $progress;
}
update_option( 'sblc_settings', array_merge( get_option( 'sblc_settings', array() ), array( 'include_menus' => false, 'batch_size' => 1, 'parallel_requests' => 1, 'scan_history_limit' => 5 ) ), false );
foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
$post_id = 0;
for ( $i = 0; $i < 3; ++$i ) {
	$id = wp_insert_post( array( 'post_title' => 'LinkSolva fixture ' . $i, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<a href="https://example.invalid/link-' . $i . '">fixture</a>' ) );
	sblc_assert( $id && ! is_wp_error( $id ), 'Fixture post creation failed.' );
	if ( 0 === $i ) { $post_id = $id; }
}
$cancel = Scanner::start();
sblc_assert( ! is_wp_error( $cancel ) && 'discovering' === $cancel['state'], 'Could not start cancellation fixture.' );
$cancel = Scanner::cancel( $cancel['scan_id'] );
sblc_assert( ! is_wp_error( $cancel ) && 'cancelled' === $cancel['state'], 'Scan cancellation failed.' );
$progress = Scanner::start();
sblc_assert( ! is_wp_error( $progress ), 'Could not start scan.' );
$progress = sblc_complete_scan( $progress, true );
$first_id = $progress['scan_id'];
sblc_assert( Database::get_history_scan( $first_id ), 'Completed scan was not saved in history.' );
$tables = Database::tables();
global $wpdb;
$first_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['history_occurrences'], $first_id ) );
sblc_assert( $first_count >= 3, 'First scan source evidence was not saved.' );
wp_update_post( array( 'ID' => $post_id, 'post_content' => '<a href="https://example.invalid/changed">changed</a>' ) );
$progress = Scanner::start();
sblc_assert( ! is_wp_error( $progress ), 'Could not start second scan.' );
$progress = sblc_complete_scan( $progress );
$scan_id = $progress['scan_id'];
sblc_assert( Database::get_history_scan( $first_id ) && Database::get_history_scan( $scan_id ), 'Both completed scans should be available.' );
$old_url = $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM %i WHERE scan_id = %d AND url = %s', $tables['history_resources'], $first_id, 'https://example.invalid/link-0' ) );
$new_url = $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM %i WHERE scan_id = %d AND url = %s', $tables['history_resources'], $scan_id, 'https://example.invalid/changed' ) );
sblc_assert( $old_url && $new_url, 'Saved results changed after a later scan.' );
sblc_assert( $first_count === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['history_occurrences'], $first_id ) ), 'Saved source evidence changed after a later scan.' );
$occurrences = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['occurrences'], $scan_id ) );
$wpdb->update( $tables['scans'], array( 'history_saved' => 0 ), array( 'id' => $scan_id ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $tables['history_resources'] ) );
$result = Scanner::start();
sblc_assert( is_wp_error( $result ) && 'sblc_history_save_failed' === $result->get_error_code(), 'History failure did not stop the next scan.' );
$remaining = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['occurrences'], $scan_id ) );
sblc_assert( $remaining === $occurrences, 'History failure removed previous occurrences.' );
echo "WordPress scan, cancellation, delayed-worker, progress and history integration checks passed.\n";
