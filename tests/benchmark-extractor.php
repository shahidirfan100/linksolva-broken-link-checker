<?php
/** Benchmark extraction from a long article with many links on a disposable site. */

use LinkSolva\BrokenLinkChecker\Extractor;

$context = str_repeat( 'Article introduction text. ', 4000 );
$content = '<p>' . $context . '</p>';
for ( $link = 0; $link < 1000; ++$link ) {
	$content .= '<a href="https://example.invalid/page-' . $link . '">Details</a> ';
}

$started = microtime( true );
$items   = Extractor::extract( $content, home_url( '/' ), array( 'content' => $content ) );
$elapsed = microtime( true ) - $started;

if ( 1000 !== count( $items ) ) {
	throw new RuntimeException( 'Long-content extraction did not return all 1,000 links.' );
}
foreach ( $items as $item ) {
	if ( strlen( $item['context'] ) > 180 || false === strpos( $item['context'], 'Article introduction text.' ) ) {
		throw new RuntimeException( 'Long-content extraction changed the source context.' );
	}
}

printf( "Long-content extraction: links=%d, source_bytes=%d, elapsed=%.3fs, peak_memory=%.1fMiB.\n", count( $items ), strlen( $content ), $elapsed, memory_get_peak_usage( true ) / 1048576 );
