<?php
/**
 * Focused regression checks for request-target validation.
 *
 * Run with: php tests/ssrf-guard.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function absint( $value ) {
	return abs( (int) $value );
}

function home_url( $path = '' ) {
	$base = isset( $GLOBALS['sblc_home_url'] ) ? $GLOBALS['sblc_home_url'] : 'https://linksolva.test';
	return $base . $path;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function wp_http_validate_url( $url ) {
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-ssrf-guard.php';

use LinkSolva\BrokenLinkChecker\Ssrf_Guard;

$cases = array(
	'loopback IPv4'              => array( 'http://127.0.0.1/', false, 'blocked_address' ),
	'private IPv4'                => array( 'http://10.2.3.4/', false, 'blocked_address' ),
	'link-local metadata IPv4'    => array( 'http://169.254.169.254/', false, 'blocked_address' ),
	'loopback IPv6'               => array( 'http://[::1]/', false, 'blocked_address' ),
	'private IPv6'                => array( 'http://[fd00::1]/', false, 'blocked_address' ),
	'link-local IPv6'             => array( 'http://[fe80::1]/', false, 'blocked_address' ),
	'multicast IPv6'              => array( 'http://[ff02::1]/', false, 'blocked_address' ),
	'IPv4-mapped loopback IPv6'   => array( 'http://[::ffff:127.0.0.1]/', false, 'blocked_address' ),
	'localhost hostname'          => array( 'http://localhost/', false, 'blocked_host' ),
	'internal hostname'           => array( 'http://db.internal/', false, 'blocked_host' ),
	'embedded credentials'        => array( 'https://user:pass@example.com/', false, 'invalid_scheme' ),
	'non-http scheme'             => array( 'ftp://example.com/file', false, 'invalid_scheme' ),
	'nonstandard external port'   => array( 'https://example.com:8080/', false, 'blocked_port' ),
	'public IPv4 literal'         => array( 'https://8.8.8.8/', true, '' ),
	'public IPv6 literal'         => array( 'https://[2001:4860:4860::8888]/', true, '' ),
);

foreach ( $cases as $label => $case ) {
	$result = Ssrf_Guard::validate( $case[0] );
	if ( $case[1] !== $result['safe'] || $case[2] !== $result['code'] ) {
		fwrite( STDERR, sprintf( "%s: expected safe=%s code=%s, got safe=%s code=%s\n", $label, $case[1] ? 'true' : 'false', $case[2], ! empty( $result['safe'] ) ? 'true' : 'false', $result['code'] ) );
		exit( 1 );
	}
}

$GLOBALS['sblc_home_url'] = 'https://127.0.0.1';
$site_target              = Ssrf_Guard::validate( 'https://127.0.0.1/' );
if ( empty( $site_target['safe'] ) || '' !== $site_target['code'] ) {
	fwrite( STDERR, "Configured site address should remain available for local checks.\n" );
	exit( 1 );
}
$other_site_port = Ssrf_Guard::validate( 'https://127.0.0.1:8080/' );
if ( ! empty( $other_site_port['safe'] ) || 'blocked_port' !== $other_site_port['code'] ) {
	fwrite( STDERR, "An alternate port on the configured site host must be blocked.\n" );
	exit( 1 );
}
$explicit_default_port = Ssrf_Guard::validate( 'https://127.0.0.1:443/' );
if ( empty( $explicit_default_port['safe'] ) || '' !== $explicit_default_port['code'] ) {
	fwrite( STDERR, "An explicit default port should match the configured site authority.\n" );
	exit( 1 );
}
$other_scheme = Ssrf_Guard::validate( 'http://127.0.0.1/' );
if ( ! empty( $other_scheme['safe'] ) || 'blocked_address' !== $other_scheme['code'] ) {
	fwrite( STDERR, "A different scheme must not inherit the configured site's private-address exception.\n" );
	exit( 1 );
}
$GLOBALS['sblc_home_url'] = 'https://127.0.0.1:8443';
$configured_site_port    = Ssrf_Guard::validate( 'https://127.0.0.1:8443/' );
if ( empty( $configured_site_port['safe'] ) || '' !== $configured_site_port['code'] ) {
	fwrite( STDERR, "The configured site authority should remain available for local checks.\n" );
	exit( 1 );
}
$other_configured_port = Ssrf_Guard::validate( 'https://127.0.0.1:8444/' );
if ( ! empty( $other_configured_port['safe'] ) || 'blocked_port' !== $other_configured_port['code'] ) {
	fwrite( STDERR, "Only the configured site port may bypass the nonstandard-port block.\n" );
	exit( 1 );
}

echo sprintf( "SSRF guard regression checks passed (%d cases).\n", count( $cases ) + 6 );
