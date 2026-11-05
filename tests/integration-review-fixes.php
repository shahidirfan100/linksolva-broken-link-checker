<?php
/** Run against a disposable site and the HTTP fixture on 127.0.0.1:8080. */
use LinkSolva\BrokenLinkChecker\Database;
use LinkSolva\BrokenLinkChecker\Http_Checker;
use LinkSolva\BrokenLinkChecker\Extractor;
use LinkSolva\BrokenLinkChecker\Installer;
use LinkSolva\BrokenLinkChecker\Repair;
use LinkSolva\BrokenLinkChecker\Settings;
use LinkSolva\BrokenLinkChecker\Sources;

function review_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
wp_set_current_user( get_user_by( 'login', 'admin' )->ID );
$proxy_variables = array( 'http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY' );
$proxy_environment = array();
foreach ( $proxy_variables as $variable ) { $proxy_environment[ $variable ] = getenv( $variable ); putenv( $variable ); }
register_post_type( 'sblc_review_test', array( 'public' => true ) );
$old_home = get_option( 'home' );
$old_settings = get_option( Settings::OPTION );
update_option( 'home', 'http://127.0.0.1:8080' );
$old_url = home_url( '/sblc-test-target.php?case=missing' );
$new_url = home_url( '/sblc-test-target.php?case=redirect' );
$loop_url = home_url( '/sblc-test-target.php?case=loop' );
// The name is deliberately unresolvable: only the validated cURL pin can reach the fixture.
$target = 'http://pin-test.invalid:8080/sblc-test-target.php?case=healthy';
$args_method = new ReflectionMethod( Http_Checker::class, 'request_args' );
$options_method = new ReflectionMethod( Http_Checker::class, 'requests_options' );
$proxy_method = new ReflectionMethod( Http_Checker::class, 'uses_proxy' );
if ( PHP_VERSION_ID < 80100 ) { $args_method->setAccessible( true ); $options_method->setAccessible( true ); $proxy_method->setAccessible( true ); }
$args = $args_method->invoke( null, $target, 'HEAD', Settings::all(), 0.0 );
$safety = array( 'safe' => true, 'host' => 'pin-test.invalid', 'addresses' => array( '127.0.0.1' ) );
$options = $options_method->invoke( null, $target, $args, null, $safety );
$options['transport'] = '\\WpOrg\\Requests\\Transport\\Curl';
$single = \WpOrg\Requests\Requests::request( $target, $args['headers'], '', 'HEAD', $options );
review_assert( 200 === $single->status_code, 'Single request did not use the validated address.' );
$options = $options_method->invoke( null, $target, $args, null, $safety );
$multiple = \WpOrg\Requests\Requests::request_multiple( array( array( 'url' => $target, 'headers' => $args['headers'], 'type' => 'HEAD', 'options' => $options ) ), array( 'transport' => '\\WpOrg\\Requests\\Transport\\Curl' ) );
review_assert( isset( $multiple[0]->status_code ) && 200 === $multiple[0]->status_code, 'Parallel request did not use the validated address.' );
$previous_proxy = getenv( 'HTTP_PROXY' );
putenv( 'HTTP_PROXY=http://proxy.invalid:3128' );
review_assert( $proxy_method->invoke( null, $old_url ), 'Environment proxy settings were not detected.' );
false === $previous_proxy ? putenv( 'HTTP_PROXY' ) : putenv( 'HTTP_PROXY=' . $previous_proxy );
$policy_calls = 0;
$policy_filter = static function ( $preempt, $args, $url ) use ( &$policy_calls, $old_url ) {
	if ( $url === $old_url ) {
		++$policy_calls;
		return new WP_Error( 'sblc_test_policy', 'Blocked by the test policy.' );
	}
	return $preempt;
};
add_filter( 'pre_http_request', $policy_filter, 10, 3 );
$policy_result = Http_Checker::check_many( array( $old_url ) );
remove_filter( 'pre_http_request', $policy_filter, 10 );
review_assert( 1 === $policy_calls && 'unverified' === $policy_result[0]['status'], 'The WordPress pre_http_request policy was bypassed.' );
$fixture = '<a href="' . $old_url . '">first</a><a href="' . $old_url . '">second</a>';
$nofollow = Extractor::edit( $fixture, $old_url, $old_url, $old_url, 'nofollow', home_url( '/' ), 1, 'a[href]' );
review_assert( ! is_wp_error( $nofollow ) && false !== strpos( $nofollow, '<a rel="nofollow" href="' . $old_url . '">second</a>' ) && false !== strpos( $nofollow, '<a href="' . $old_url . '">first</a>' ), 'Nofollow targeted the wrong occurrence.' );
$images = '<img srcset="' . $old_url . ' 1x, ' . $old_url . ' 2x">';
$edited = Extractor::edit( $images, $old_url, $old_url, $new_url, 'replace', home_url( '/' ), 1, 'img[srcset]' );
review_assert( ! is_wp_error( $edited ) && false !== strpos( $edited, $old_url . ' 1x, ' . $new_url . ' 2x' ), 'Srcset targeted the wrong occurrence.' );
$original = '<p>\\test <a href="' . $old_url . '">first</a> <a href="' . $old_url . '">second</a></p>';
$post = wp_insert_post( wp_slash( array( 'post_type' => 'sblc_review_test', 'post_title' => 'Review repairs', 'post_status' => 'publish', 'post_content' => $original ) ) );
$scan = Database::insert_scan();
$source = array( 'source_type' => 'post', 'source_id' => $post, 'source_field' => 'post_content', 'source_title' => 'Review repairs', 'source_url' => get_permalink( $post ), 'content' => $original, 'editable' => true );
Sources::store_source( $source, (string) $scan );
global $wpdb;
$table = Database::tables()['occurrences'];
$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE source_id = %d AND scan_id = %d ORDER BY id', $table, $post, $scan ) );
review_assert( count( $ids ) === 2, 'Expected two occurrences.' );
$bad = Repair::apply( $ids[1], 'replace', $loop_url );
review_assert( is_wp_error( $bad ) && 'sblc_replacement_not_verified' === $bad->get_error_code() && get_post_field( 'post_content', $post ) === $original, 'Redirect loop was accepted.' );
$concurrent_content = '<p>Changed while verification was running.</p>';
$conflict_filter = static function ( $preempt, $args, $url ) use ( $new_url, $post, $concurrent_content ) {
	if ( $url === $new_url ) {
		wp_update_post( wp_slash( array( 'ID' => $post, 'post_content' => $concurrent_content ) ) );
		return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}
	return $preempt;
};
add_filter( 'pre_http_request', $conflict_filter, 10, 3 );
$conflict = Repair::apply( $ids[0], 'replace', $new_url );
remove_filter( 'pre_http_request', $conflict_filter, 10 );
review_assert( is_wp_error( $conflict ) && 'sblc_source_changed' === $conflict->get_error_code() && get_post_field( 'post_content', $post ) === $concurrent_content, 'A concurrent source edit was overwritten.' );
wp_update_post( wp_slash( array( 'ID' => $post, 'post_content' => $original ) ) );
$result = Repair::apply( $ids[1], 'replace', $new_url );
review_assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : 'Replacement failed.' );
$after = get_post_field( 'post_content', $post );
review_assert( false !== strpos( $after, '\\test' ) && false !== strpos( $after, 'href="' . $old_url . '">first' ) && false !== strpos( $after, 'href="' . $new_url . '">second' ), 'Wrong occurrence or unrelated content changed.' );
review_assert( is_wp_error( Repair::apply( $ids[0], 'unlink' ) ), 'Stale source was edited.' );
$undo = Repair::undo( $result['repair_id'] );
review_assert( ! is_wp_error( $undo ) && $original === get_post_field( 'post_content', $post ), 'Undo failed to preserve source.' );

$menu_id = wp_create_nav_menu( 'Review menu ' . wp_generate_uuid4() );
$item_id = wp_update_nav_menu_item( $menu_id, 0, array( 'menu-item-title' => 'Keep title', 'menu-item-url' => $old_url, 'menu-item-type' => 'custom', 'menu-item-status' => 'publish', 'menu-item-classes' => 'keep-class', 'menu-item-target' => '_blank', 'menu-item-description' => 'Keep description' ) );
$resource = Database::upsert_resource( $old_url, 'link', $scan );
$occ = Database::add_occurrence( array( 'resource_id' => $resource, 'scan_id' => $scan, 'source_type' => 'menu_item', 'source_id' => $item_id, 'source_field' => 'menu_item_url', 'source_title' => 'Keep title', 'source_url' => home_url( '/' ), 'anchor_text' => '', 'context' => '', 'raw_url' => $old_url, 'location' => 'text', 'occurrence_index' => 0, 'editable' => true ) );
$repair = Repair::apply( $occ, 'replace', $new_url );
review_assert( ! is_wp_error( $repair ), is_wp_error( $repair ) ? $repair->get_error_message() : 'Menu repair failed.' );
$item = wp_setup_nav_menu_item( get_post( $item_id ) );
review_assert( 'Keep title' === $item->title && '_blank' === $item->target && in_array( 'keep-class', $item->classes, true ) && 'Keep description' === $item->description, 'Menu fields were lost.' );
review_assert( ! is_wp_error( Repair::undo( $repair['repair_id'] ) ), 'Menu undo failed.' );
$item = wp_setup_nav_menu_item( get_post( $item_id ) );
review_assert( $old_url === $item->url && 'Keep title' === $item->title && '_blank' === $item->target, 'Menu undo lost fields.' );

for ( $i = 0; $i < 11; ++$i ) { add_post_meta( $post, 'review_key', 'https://example.com/' . $i ); }
Settings::save( array( 'post_types' => array( 'sblc_review_test' ), 'custom_meta_keys' => array( 'review_key' ) ) );
$all = array(); $page = 1; $offset = 0;
do {
 $batch = Sources::batch( 'postmeta', $page, 2, $offset );
 foreach ( $batch['items'] as $item ) { if ( $item['source_id'] === $post ) { $all[] = $item['content']; } }
 if ( ! $batch['page_complete'] ) { $offset = end( $batch['items'] )['cursor']; } elseif ( $batch['has_more'] ) { ++$page; $offset = 0; } else { break; }
} while ( $page < 100 );
review_assert( count( $all ) === 11 && count( array_unique( $all ) ) === 11, 'Metadata pagination skipped or duplicated values.' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
$blocked = Http_Checker::check_many( array( 'http://8.8.8.8/' ) );
review_assert( 'unverified' === $blocked[0]['status'], 'The WordPress outbound network policy was bypassed.' );
Database::update_scan( $scan, array( 'provider' => 'postmeta', 'resource_cursor' => 7 ) );
update_option( 'sblc_db_version', '1.1.2' );
Installer::maybe_upgrade();
review_assert( 0 === (int) Database::get_scan( $scan )->resource_cursor, 'Legacy metadata cursor was not restarted during upgrade.' );
Database::update_scan( $scan, array( 'state' => 'completed', 'finished_at' => Database::now() ) );
update_option( 'home', $old_home );
update_option( Settings::OPTION, $old_settings );
foreach ( $proxy_environment as $variable => $value ) { false === $value ? putenv( $variable ) : putenv( $variable . '=' . $value ); }
echo "Review repair, redirect, and metadata checks passed.\n";
