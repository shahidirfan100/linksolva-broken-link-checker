<?php
/** Populate the disposable screenshot site with labelled sample findings. */
use LinkSolva\BrokenLinkChecker\Database;

$scan = Database::insert_scan();
$post = wp_insert_post( array( 'post_title' => 'Sample article', 'post_status' => 'publish', 'post_content' => '<p>Sample data: <a href="https://example.com/missing-page">missing page</a>, <a href="https://example.com/old-page">old page</a>, <a href="https://example.com/slow-page">slow page</a>, <a href="https://example.com/working-page">working page</a>.</p>' ) );
if ( ! $scan || ! $post || is_wp_error( $post ) ) { throw new RuntimeException( 'Could not create screenshot fixtures.' ); }
$fixtures = array(
 array( 'https://example.com/missing-page', 'broken', 'confirmed', 404, 'http_404', 'Not Found', 'The server returned HTTP 404.', 'https://example.com/missing-page', array() ),
 array( 'https://example.com/old-page', 'redirect', 'confirmed', 301, 'http_301', 'Moved Permanently', 'The URL redirected to a new address.', 'https://example.com/new-page', array( array( 'status' => 301, 'from' => 'https://example.com/old-page', 'to' => 'https://example.com/new-page' ) ) ),
 array( 'https://example.com/slow-page', 'timeout', 'unverified', 0, 'timeout', 'Timeout', 'The server did not respond within the time available for this request.', 'https://example.com/slow-page', array() ),
 array( 'https://example.com/working-page', 'healthy', 'confirmed', 200, '', 'OK', 'The server returned HTTP 200.', 'https://example.com/working-page', array() ),
);
foreach ( $fixtures as $fixture ) {
 list( $url, $status, $confidence, $code, $error, $title, $explanation, $final_url, $redirects ) = $fixture;
 $id = Database::upsert_resource( $url, 'link', $scan );
 $result = array( 'status' => $status, 'confidence' => $confidence, 'http_code' => $code, 'error_code' => $error, 'status_text' => $title, 'explanation' => $explanation, 'final_url' => $final_url, 'redirect_chain' => $redirects, 'request_history' => array( array( 'method' => 'HEAD', 'url' => $url, 'status' => $code, 'message' => $title, 'duration' => 0.12 ) ), 'response_time' => 0.12, 'next_check_at' => null, 'retry_count' => 0 );
 if ( ! $id || ! Database::save_result( $id, $scan, $result ) || false === Database::add_occurrence( array( 'resource_id' => $id, 'scan_id' => $scan, 'source_type' => 'post', 'source_id' => $post, 'source_field' => 'post_content', 'source_title' => 'Sample article', 'source_url' => get_permalink( $post ), 'anchor_text' => 'Sample link', 'context' => 'Sample data for screenshots.', 'raw_url' => $url, 'location' => 'post_content', 'occurrence_index' => 0, 'editable' => 'timeout' !== $status ) ) ) { throw new RuntimeException( 'Could not save screenshot fixture.' ); }
}
Database::update_scan( $scan, array( 'state' => 'completed', 'finished_at' => Database::now(), 'checked_resources' => 4, 'discovered_occurrences' => 4, 'counts' => wp_json_encode( Database::counts() ) ) );
echo "Screenshot fixtures created.\n";
