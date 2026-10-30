<?php
/** Disposable HTTP targets for browser-driven WordPress integration tests. */
$case = isset( $_GET['case'] ) ? $_GET['case'] : '';
if ( 'slow' === $case ) { sleep( 2 ); }
if ( 'missing' === $case ) { http_response_code( 404 ); exit; }
if ( 'redirect' === $case ) { header( 'Location: /sblc-test-target.php?case=healthy', true, 301 ); exit; }
if ( 'head-unsupported' === $case && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) { http_response_code( 405 ); exit; }
header( 'Content-Type: text/plain' );
echo 'Fixture response';
