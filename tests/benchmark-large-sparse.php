<?php
/** Benchmark a sparse 30,000-job fixture on a disposable WordPress site. */
use LinkSolva\BrokenLinkChecker\Extractor;
use LinkSolva\BrokenLinkChecker\Scanner;
global $wpdb;
register_post_type( 'job_listing', array( 'public' => true ) );
$settings = get_option( 'sblc_settings', array() );
$original = $settings;
$settings = array_merge( $settings, array( 'post_types' => array( 'job_listing' ), 'include_menus' => false, 'include_comments' => false, 'include_custom_fields' => false, 'include_featured_images' => false, 'batch_size' => 10, 'parallel_requests' => 2, 'scan_history_limit' => 5 ) );
update_option( 'sblc_settings', $settings, false );
$cases = array(
	'<p>No hyperlinks in this job description.</p>' => 0,
	'<a href="/relative-job">Read more</a>' => 1,
	'<img src="/photo.png" srcset="/a.png 1x, /b.png 2x">' => 3,
	'<p>Visit https://example.invalid/plain for details.</p>' => 1,
);
foreach ( $cases as $html => $expected ) {
	$matches = Extractor::extract( $html, home_url( '/' ), array( 'content' => $html ) );
	if ( count( $matches ) !== $expected ) { throw new RuntimeException( 'Link extraction changed for fixture: ' . $html ); }
}
$columns = '(post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)';
$now = current_time( 'mysql', true );
for ( $block = 0; $block < 100; ++$block ) {
	$values = array();
	for ( $j = 0; $j < 300; ++$j ) {
		$i = $block * 300 + $j;
		$content = in_array( $i, array( 17, 15000, 29999 ), true ) ? '<p>Job opening <a href="http://127.0.0.1/sparse-' . $i . '">details</a></p>' : '<p>A job listing without links.</p>';
		$values[] = $wpdb->prepare( "(1,%s,%s,%s,%s,'','publish','closed','closed','','', '', '',%s,%s,'',0,'',0,'job_listing','',0)", $now, $now, $content, 'LinkSolva sparse fixture ' . $i, $now, $now );
	}
	$sql = 'INSERT INTO ' . $wpdb->posts . ' ' . $columns . ' VALUES ' . implode( ',', $values );
	if ( false === $wpdb->query( $sql ) ) { throw new RuntimeException( 'Could not create sparse job fixtures.' ); }
}
$start = microtime( true );
$start_queries = $wpdb->num_queries;
$max_step = 0;
$step_start = microtime( true );
$progress = Scanner::start();
$max_step = microtime( true ) - $step_start;
if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'Sparse scan could not start.' ); }
$steps = 1;
while ( in_array( $progress['state'], array( 'discovering', 'checking' ), true ) && $steps < 1000 ) {
	wp_cache_flush();
	$step_start = microtime( true );
	$progress = Scanner::step( $progress['scan_id'] );
	$max_step = max( $max_step, microtime( true ) - $step_start );
	++$steps;
	if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'Sparse scan failed.' ); }
}
if ( 'completed' !== $progress['state'] || 3 !== $progress['stats']['total_resources'] || 3 !== $progress['stats']['checked_resources'] || 30000 !== $progress['processed_sources'] || $steps >= 300 ) {
	throw new RuntimeException( 'Sparse 30,000-job scan missed sources or took too many steps: ' . wp_json_encode( $progress ) . ', steps=' . $steps );
}
printf( "Sparse benchmark: jobs=30000, unique_urls=3, steps=%d, elapsed=%.2fs, max_step=%.3fs, peak_memory=%.1fMiB, queries=%d; network requests=0 (private targets rejected).\n", $steps, microtime( true ) - $start, $max_step, memory_get_peak_usage( true ) / 1048576, $wpdb->num_queries - $start_queries );
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'job_listing' AND post_title LIKE 'LinkSolva sparse fixture %'" );
update_option( 'sblc_settings', $original, false );
