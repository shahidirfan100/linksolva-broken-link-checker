<?php
/** Duplicate-source completion and no-progress integration checks. */
use LinkSolva\BrokenLinkChecker\Scanner;
foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
update_option( 'sblc_settings', array_merge( get_option( 'sblc_settings', array() ), array( 'include_menus' => false, 'batch_size' => 5, 'parallel_requests' => 2, 'execution_seconds' => 3 ) ), false );
for ( $i = 0; $i < 22; ++$i ) {
	$id = wp_insert_post( array( 'post_title' => 'Duplicate fixture ' . $i, 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<a href="http://127.0.0.1/repeated-' . ( $i % 10 ) . '">link</a>' ) );
	if ( ! $id || is_wp_error( $id ) ) { throw new RuntimeException( 'Duplicate fixture could not be created.' ); }
}
$progress = Scanner::start();
if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'Duplicate scan did not start.' ); }
for ( $i = 0; $i < 100 && in_array( $progress['state'], array( 'discovering', 'checking' ), true ); ++$i ) {
	$progress = Scanner::step( $progress['scan_id'] );
	if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'Duplicate scan worker failed.' ); }
}
if ( 'completed' !== $progress['state'] || 10 !== $progress['stats']['total_resources'] || 10 !== $progress['stats']['checked_resources'] || 22 !== $progress['discovered_occurrences'] ) {
	throw new RuntimeException( 'Repeated URLs must finish with 10 checked URLs and 22 occurrences.' );
}
$slow = static function ( $sql ) { usleep( 3200000 ); return $sql; };
add_filter( 'posts_request', $slow );
$progress = Scanner::start();
if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'No-progress scan did not start.' ); }
for ( $i = 0; $i < 6 && 'discovering' === $progress['state']; ++$i ) {
	$progress = Scanner::step( $progress['scan_id'] );
	if ( is_wp_error( $progress ) ) { throw new RuntimeException( 'No-progress worker returned an unexpected error.' ); }
}
remove_filter( 'posts_request', $slow );
if ( 'failed' !== $progress['state'] || false === strpos( $progress['message'], 'incomplete' ) ) {
	throw new RuntimeException( 'Repeated no-progress requests must stop with an incomplete report.' );
}
echo "Duplicate sources completed and repeated no-progress steps stopped safely.\n";
