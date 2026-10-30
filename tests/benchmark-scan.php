<?php
/** Synthetic WordPress scan benchmark on a disposable site. */
use LinkSolva\BrokenLinkChecker\Database;
use LinkSolva\BrokenLinkChecker\Scanner;

foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
update_option( 'sblc_settings', array_merge( get_option( 'sblc_settings', array() ), array( 'include_menus' => false, 'batch_size' => 10, 'parallel_requests' => 4, 'scan_history_limit' => 5 ) ), false );
for ( $post = 0; $post < 200; ++$post ) {
	$html = '';
	for ( $link = 0; $link < 10; ++$link ) {
		// The SSRF guard blocks these literal private addresses before any network request.
		$html .= '<a href="http://127.0.0.1/fixture-' . $post . '-' . $link . '">link</a> ';
	}
	$id = wp_insert_post( array( 'post_title' => 'Benchmark fixture ' . $post, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => $html ) );
	if ( ! $id || is_wp_error( $id ) ) { throw new RuntimeException( 'Unable to create benchmark fixture.' ); }
}
global $wpdb;
$start_queries = $wpdb->num_queries;
$start = microtime( true );
$max_step = 0;
$step_start = microtime( true );
$progress = Scanner::start();
$max_step = microtime( true ) - $step_start;
if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'Benchmark scan could not start.' ); }
$scan_id = $progress['scan_id'];
$steps = 1;
while ( in_array( $progress['state'], array( 'discovering', 'checking' ), true ) && $steps < 1000 ) {
	$step_start = microtime( true );
	$progress = Scanner::step( $scan_id );
	$max_step = max( $max_step, microtime( true ) - $step_start );
	++$steps;
	if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'Benchmark scan worker failed.' ); }
}
if ( 'completed' !== $progress['state'] || 2000 !== $progress['stats']['total_resources'] || 2000 !== $progress['stats']['checked_resources'] ) {
	throw new RuntimeException( 'Benchmark did not complete all 2,000 URLs.' );
}
if ( ! Database::get_history_scan( $scan_id ) ) { throw new RuntimeException( 'Benchmark history snapshot missing.' ); }
$tables = Database::tables();
$history_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE scan_id = %d', $tables['history_occurrences'], $scan_id ) );
if ( 2000 !== $history_count ) { throw new RuntimeException( 'Benchmark history is incomplete.' ); }
$list_start = microtime( true );
$list_queries = $wpdb->num_queries;
$list = Database::list_resources( array( 'page' => 1, 'per_page' => 20, 'status' => 'all', 'scope' => 'all', 'type' => 'all', 'search' => '' ) );
if ( 2000 !== $list['total'] || 20 !== count( $list['items'] ) ) { throw new RuntimeException( 'Findings page did not return the expected results.' ); }
foreach ( $list['items'] as $item ) {
	if ( 1 !== (int) $item->occurrence_count ) { throw new RuntimeException( 'Findings page source counts are incorrect.' ); }
}
printf( "Findings page: URLs=2000, page_size=20, elapsed=%.3fs, queries=%d.\n", microtime( true ) - $list_start, $wpdb->num_queries - $list_queries );
printf( "Synthetic benchmark: URLs=%d, steps=%d, elapsed=%.2fs, max_step=%.3fs, peak_memory=%.1fMiB, queries=%d, history_occurrences=%d; network requests=0 (private targets rejected).\n", $progress['stats']['total_resources'], $steps, microtime( true ) - $start, $max_step, memory_get_peak_usage( true ) / 1048576, $wpdb->num_queries - $start_queries, $history_count );
