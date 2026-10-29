<?php
/** WordPress integration checks. Run with wp eval-file on a disposable site. */
use LinkSolva\BrokenLinkChecker\Database;
use LinkSolva\BrokenLinkChecker\Scanner;
use LinkSolva\BrokenLinkChecker\Settings;

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
$cancel_id = $cancel['scan_id'];
$cancel_saved = Database::get_history_scan( $cancel_id );
sblc_assert( $cancel_saved && 'cancelled' === $cancel_saved->state, 'Stopped scan was not saved as incomplete history.' );
$progress = Scanner::start();
sblc_assert( ! is_wp_error( $progress ), 'Could not start scan.' );
$internal_id = Database::upsert_resource( 'http://localhost/priority-fixture', 'link', $progress['scan_id'] );
$external_id = Database::upsert_resource( 'http://127.0.0.1/priority-fixture', 'link', $progress['scan_id'] );
sblc_assert( $internal_id && $external_id, 'Could not create priority fixtures.' );
$progress = Scanner::step( $progress['scan_id'], 'external' );
$external = Database::get_resource( $external_id );
$internal = Database::get_resource( $internal_id );
sblc_assert( ! is_wp_error( $progress ) && (int) $external->checked_scan_id === (int) $progress['scan_id'] && (int) $internal->checked_scan_id !== (int) $progress['scan_id'], 'External URL was not checked first.' );
sblc_assert( 'external' === Scanner::latest_progress()['priority_scope'], 'Selected priority was not retained for background workers.' );
$progress = Scanner::step( $progress['scan_id'], 'internal' );
$internal = Database::get_resource( $internal_id );
sblc_assert( ! is_wp_error( $progress ) && (int) $internal->checked_scan_id === (int) $progress['scan_id'], 'Internal URL was not checked after priority changed.' );
// An abandoned worker lock must be reclaimed without classifying all pending URLs as skipped.
update_option( Scanner::WORKER_LOCK_OPTION, array( 'token' => 'abandoned-test', 'locked_at' => time() - 120 ), false );
$progress = Scanner::step( $progress['scan_id'] );
sblc_assert( ! is_wp_error( $progress ) && in_array( $progress['state'], array( 'discovering', 'checking', 'completed' ), true ), 'An abandoned worker lock was not recovered.' );
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
// Check capability protection and paginated history against WordPress REST routing.
rest_get_server();
wp_set_current_user( 0 );
$denied = rest_do_request( new WP_REST_Request( 'GET', '/sblc/v1/history' ) );
sblc_assert( 401 === $denied->get_status(), 'Anonymous users must not read scan history.' );
$admin = get_user_by( 'login', 'admin' );
sblc_assert( (bool) $admin, 'Fixture administrator not found.' );
wp_set_current_user( $admin->ID );
$history = rest_do_request( new WP_REST_Request( 'GET', '/sblc/v1/history' ) );
sblc_assert( 200 === $history->get_status() && count( $history->get_data()['items'] ) >= 2, 'Admin history endpoint failed.' );
$history_states = array();
foreach ( $history->get_data()['items'] as $entry ) { $history_states[ $entry->id ] = $entry->state; }
sblc_assert( isset( $history_states[ $cancel_id ], $history_states[ $scan_id ] ) && 'cancelled' === $history_states[ $cancel_id ] && 'completed' === $history_states[ $scan_id ], 'History does not distinguish stopped and completed scans.' );
$request = new WP_REST_Request( 'GET', '/sblc/v1/history/' . $first_id . '/resources' );
$request->set_param( 'per_page', 1 );
$page = rest_do_request( $request );
sblc_assert( 200 === $page->get_status() && 1 === count( $page->get_data()['items'] ) && $page->get_data()['total'] >= 3, 'History pagination failed.' );
// Status counts must reflect the non-status filters applied to the live list.
$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE last_scan_id = %d ORDER BY id ASC', $tables['resources'], $scan_id ) );
sblc_assert( count( $ids ) >= 3, 'Expected live URL fixtures.' );
$wpdb->update( $tables['resources'], array( 'link_scope' => 'internal', 'status' => 'healthy' ), array( 'id' => $ids[0] ) );
$wpdb->update( $tables['resources'], array( 'link_scope' => 'external', 'status' => 'unverified' ), array( 'id' => $ids[1] ) );
$wpdb->update( $tables['resources'], array( 'link_scope' => 'external', 'status' => 'healthy' ), array( 'id' => $ids[2] ) );
$filtered = new WP_REST_Request( 'GET', '/sblc/v1/resources' );
$filtered->set_param( 'scope', 'external' );
$filtered->set_param( 'status', 'healthy' );
$response = rest_do_request( $filtered );
$data = $response->get_data();
sblc_assert( 200 === $response->get_status() && 2 === $data['filter_counts']['all'] && 1 === $data['filter_counts']['healthy'] && 1 === $data['total'] && 1 === count( $data['items'] ), 'Filtered status counts do not match the live results.' );
$wpdb->update( $tables['resources'], array( 'status' => 'healthy' ), array( 'id' => $ids[1] ) );
$data = rest_do_request( $filtered )->get_data();
sblc_assert( 2 === $data['filter_counts']['healthy'] && 2 === $data['total'] && 2 === count( $data['items'] ), 'Live status counts and results did not refresh.' );
foreach ( array( 0, 5, 10, 25 ) as $limit ) {
	$sanitized = Settings::sanitize( array( 'scan_history_limit' => $limit ) );
	sblc_assert( $limit === $sanitized['scan_history_limit'], 'A supported history retention option was rejected.' );
}
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
