<?php
/** Verify unlink, undo, and conflict protection on a disposable WordPress site. */
use LinkSolva\BrokenLinkChecker\Database;
use LinkSolva\BrokenLinkChecker\Repair;

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$url = 'https://example.com/repair-fixture';
$original = '<p>Read <a href="' . $url . '">the guide</a>.</p>';
$post = wp_insert_post( array( 'post_title' => 'Repair fixture', 'post_status' => 'publish', 'post_content' => $original ) );
$scan = Database::insert_scan();
$resource = Database::upsert_resource( $url, 'link', $scan );
$occurrence = Database::add_occurrence( array( 'resource_id' => $resource, 'scan_id' => $scan, 'source_type' => 'post', 'source_id' => $post, 'source_field' => 'post_content', 'source_title' => 'Repair fixture', 'source_url' => get_permalink( $post ), 'anchor_text' => 'the guide', 'context' => '', 'raw_url' => $url, 'location' => 'a[href]', 'occurrence_index' => 0, 'editable' => true ) );
if ( ! $post || ! $scan || ! $resource || ! $occurrence ) { throw new RuntimeException( 'Unable to create repair fixture.' ); }
$result = Repair::apply( $occurrence, 'unlink' );
if ( is_wp_error( $result ) || false !== strpos( get_post_field( 'post_content', $post ), '<a ' ) || (int) Database::get_occurrence( $occurrence )->resource_id !== 0 ) { throw new RuntimeException( 'Unlink did not update the source and finding.' ); }
$undo = Repair::undo( $result['repair_id'] );
if ( is_wp_error( $undo ) || get_post_field( 'post_content', $post ) !== $original || (int) Database::get_occurrence( $occurrence )->resource_id !== (int) $resource ) { throw new RuntimeException( 'Undo did not restore the source and finding.' ); }
$result = Repair::apply( $occurrence, 'unlink' );
if ( is_wp_error( $result ) ) { throw new RuntimeException( 'Could not prepare conflict fixture.' ); }
wp_update_post( array( 'ID' => $post, 'post_content' => 'Edited after repair.' ) );
$conflict = Repair::undo( $result['repair_id'] );
if ( ! is_wp_error( $conflict ) || 'sblc_undo_conflict' !== $conflict->get_error_code() || 'Edited after repair.' !== get_post_field( 'post_content', $post ) ) { throw new RuntimeException( 'Undo did not preserve a later source edit.' ); }
Database::update_scan( $scan, array( 'state' => 'completed', 'finished_at' => Database::now() ) );
echo "Unlink, undo, and conflict protection passed.\n";
