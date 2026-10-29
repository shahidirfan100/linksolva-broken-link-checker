<?php
/** Verify discovery does not hide database write failures. Disposable site only. */
use LinkSolva\BrokenLinkChecker\Scanner;
foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) { wp_delete_post( $id, true ); }
update_option( 'sblc_settings', array_merge( get_option( 'sblc_settings', array() ), array( 'include_menus' => false, 'batch_size' => 5 ) ), false );
$id = wp_insert_post( array( 'post_title' => 'Write failure fixture', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => '<a href="http://127.0.0.1/failure-fixture">link</a>' ) );
if ( ! $id || is_wp_error( $id ) ) { throw new RuntimeException( 'Could not create the failure fixture.' ); }
global $wpdb;
$previous_suppression = $wpdb->suppress_errors( true );
foreach ( array( 'resources', 'occurrences' ) as $suffix ) {
	$table = $wpdb->prefix . 'sblc_' . $suffix;
	$filter = static function ( $query ) use ( $table ) {
		if ( false !== strpos( $query, 'INSERT INTO `' . $table . '`' ) ) { return 'SELECT * FROM `sblc_intentionally_missing_table`'; }
		return $query;
	};
	add_filter( 'query', $filter );
	$result = Scanner::start();
	remove_filter( 'query', $filter );
	if ( ! is_wp_error( $result ) ) { throw new RuntimeException( 'Discovery did not report the ' . $suffix . ' write failure.' ); }
	$progress = Scanner::latest_progress();
	if ( 'failed' !== $progress['state'] ) { throw new RuntimeException( 'A failed discovery must not be marked complete.' ); }
}
$wpdb->suppress_errors( $previous_suppression );
echo "Discovery reports URL and occurrence write failures.\n";
