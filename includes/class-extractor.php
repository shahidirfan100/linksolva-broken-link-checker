<?php
/**
 * Content URL extraction and conservative HTML edits.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts HTTP resources and applies conservative edits to supported content.
 */
final class Extractor {
	/**
	 * Extract links and images from HTML or text.
	 *
	 * @param string $content Content.
	 * @param string $base    Base URL.
	 * @param array  $source  Source metadata.
	 * @return array
	 */
	public static function extract( $content, $base, $source ) {
		$found   = array();
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return $found;
		}
		// Skip DOM parsing when neither a supported element nor a plain HTTP URL is present.
		if ( ! preg_match( '~https?://|<\s*(?:a|img|source|video|audio)\b~i', $content ) ) {
			return $found;
		}
		$plain          = wp_strip_all_tags( $content );
		$source_context = ! empty( $source['content'] ) ? self::compact_context( $plain ) : null;

		if ( class_exists( 'DOMDocument' ) && self::looks_like_html( $content ) ) {
			$previous = libxml_use_internal_errors( true );
			$dom      = new \DOMDocument();
			$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $content . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			foreach ( array(
				'a'      => 'link',
				'img'    => 'image',
				'source' => 'image',
				'video'  => 'image',
				'audio'  => 'image',
			) as $tag => $type ) {
				$nodes = $dom->getElementsByTagName( $tag );
				foreach ( $nodes as $node ) {
					$attributes = 'a' === $tag ? array( 'href' ) : array( 'src', 'data-src', 'data-lazy-src', 'data-original' );
					foreach ( $attributes as $attribute ) {
						if ( $node->hasAttribute( $attribute ) ) {
							// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- textContent is the native DOMNode API property.
							self::add( $found, $node->getAttribute( $attribute ), $base, $source, $type, $node->textContent, $tag . '[' . $attribute . ']', $source_context );
						}
					}
					if ( 'image' === $type ) {
						foreach ( array( 'srcset', 'data-srcset' ) as $srcset_attribute ) {
							if ( ! $node->hasAttribute( $srcset_attribute ) ) {
								continue;
							}
							$srcset = preg_split( '/\s*,\s*/', $node->getAttribute( $srcset_attribute ) );
							foreach ( $srcset as $candidate ) {
								$parts = preg_split( '/\s+/', trim( $candidate ) );
								if ( ! empty( $parts[0] ) ) {
									self::add( $found, $parts[0], $base, $source, 'image', '', $tag . '[' . $srcset_attribute . ']', $source_context );
								}
							}
						}
					}
				}
			}
		}

		if ( preg_match_all( '~https?://[^\s<>"\'()]+~iu', $plain, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url = rtrim( $url, '.,;:!?]' );
				self::add( $found, $url, $base, $source, 'link', $url, 'text', $source_context );
			}
		}
		return $found;
	}

	/**
	 * Add one normalized occurrence candidate.
	 *
	 * @param array  &$found  Output.
	 * @param string $raw     Raw URL.
	 * @param string $base     Base URL.
	 * @param array  $source   Source metadata.
	 * @param string $type     Resource type.
	 * @param string $anchor   Anchor or alt text.
	 * @param string $location Location.
	 * @return void
	 */
	private static function add( &$found, $raw, $base, $source, $type, $anchor, $location, $source_context = null ) {
		$normalized = Url::normalize( $raw, $base );
		if ( ! $normalized || self::excluded( $normalized ) ) {
			return;
		}
		$found[] = array(
			'url'           => $normalized,
			'raw_url'       => trim( html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			'resource_type' => ! empty( $source['force_type'] ) ? $source['force_type'] : $type,
			'anchor_text'   => wp_strip_all_tags( (string) $anchor ),
			'location'      => $location,
			'context'       => ( null === $source_context ? self::context_text( $anchor ) : $source_context ) ?: $normalized,
		);
	}

	/**
	 * Check configured exclusions.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function excluded( $url ) {
		$scope = Url::scope( $url );
		if ( ( 'internal' === $scope && ! Settings::get( 'check_internal_links' ) ) || ( 'external' === $scope && ! Settings::get( 'check_external_links' ) ) ) {
			return true;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( (array) Settings::get( 'excluded_domains', array() ) as $domain ) {
			$domain = strtolower( trim( $domain ) );
			if ( $domain && ( $host === $domain || substr( $host, -strlen( '.' . $domain ) ) === '.' . $domain ) ) {
				return true;
			}
		}
		foreach ( (array) Settings::get( 'excluded_url_fragments', array() ) as $fragment ) {
			if ( '' !== trim( $fragment ) && false !== stripos( $url, trim( $fragment ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build a compact context string.
	 *
	 * @param string $content Content to reduce to plain text.
	 * @return string
	 */
	private static function context_text( $content ) {
		return self::compact_context( wp_strip_all_tags( (string) $content ) );
	}

	/**
	 * Normalize and truncate text for stored context.
	 *
	 * @param string $content Plain text content.
	 * @return string
	 */
	private static function compact_context( $content ) {
		$context = (string) $content;
		$context = preg_replace( '/\s+/', ' ', trim( $context ) );
		if ( strlen( $context ) > 180 ) {
			$context = substr( $context, 0, 177 ) . '...';
		}
		return $context;
	}

	/**
	 * Determine whether DOM parsing is useful.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	private static function looks_like_html( $content ) {
		return (bool) preg_match( '/<\s*(a|img|source|video|audio|p|div|figure)\b/i', $content );
	}

	/**
	 * Replace one URL in supported HTML or plain text.
	 *
	 * @param string $content Content.
	 * @param string $old_raw Old raw URL.
	 * @param string $old_url Old normalized URL.
	 * @param string $new_url New URL.
	 * @param string $operation replace or unlink.
	 * @param string $base Base URL.
	 * @return string|\WP_Error
	 */
	public static function edit( $content, $old_raw, $old_url, $new_url, $operation, $base = '', $ordinal = 0, $location = '' ) {
		$changed = 0;
		$seen = 0;
		$matches = static function ( $candidate ) use ( $old_raw, $old_url, $base ) {
			return trim( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === trim( $old_raw ) || Url::normalize( $candidate, $base ) === $old_url;
		};

		if ( 'nofollow' === $operation ) {
			if ( $location && 'a[href]' !== $location ) {
				return new \WP_Error( 'sblc_not_found', __( 'The selected URL is not an editable link.', 'linksolva-broken-link-checker' ) );
			}
			$result = preg_replace_callback(
				'~<a\b([^>]*)>~i',
				static function ( $tag_match ) use ( $matches, &$changed, &$seen, $ordinal ) {
					if ( $changed || ! preg_match( '~\bhref\s*=\s*["\']([^"\']*)["\']~i', $tag_match[0], $href ) || ! $matches( $href[1] ) ) {
						return $tag_match[0];
					}
					if ( $seen++ !== $ordinal ) { return $tag_match[0]; }
					if ( preg_match( '~\brel\s*=\s*(["\'])([^"\']*)\1~i', $tag_match[0], $rel ) ) {
						if ( preg_match( '/(?:^|\s)nofollow(?:\s|$)/i', $rel[2] ) ) {
							return $tag_match[0];
						}
						++$changed;
						$value = trim( $rel[2] . ' nofollow' );
						return str_replace( $rel[0], 'rel=' . $rel[1] . esc_attr( $value ) . $rel[1], $tag_match[0] );
					}
					++$changed;
					return preg_replace( '~^(<a\b)~i', '$1 rel="nofollow"', $tag_match[0], 1 );
				},
				$content
			);
			return $changed ? $result : new \WP_Error( 'sblc_not_found', __( 'The selected URL was not found in the source content.', 'linksolva-broken-link-checker' ) );
		}

		if ( 'unlink' === $operation && ( ! $location || 'a[href]' === $location ) ) {
			$result = preg_replace_callback(
				'~<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']*)\1[^>]*>(.*?)</a>~is',
				static function ( $tag_match ) use ( $matches, &$changed, &$seen, $ordinal ) {
					if ( $changed || ! $matches( $tag_match[2] ) ) {
						return $tag_match[0];
					}
					if ( $seen++ !== $ordinal ) { return $tag_match[0]; }
					++$changed;
					return $tag_match[3];
				},
				$content
			);
			if ( $changed ) {
				return $result;
			}
			if ( $location ) { return new \WP_Error( 'sblc_not_found', __( 'The selected URL was not found in the source content.', 'linksolva-broken-link-checker' ) ); }
		}

		if ( preg_match( '/^(img|source|video|audio)\[(srcset|data-srcset)\]$/', $location, $srcset_parts ) ) {
			$pattern = '~<(' . $srcset_parts[1] . ')\b([^>]*?)(?<![\w-])(' . $srcset_parts[2] . ')\s*=\s*(["\'])([^"\']*)\4([^>]*)>~is';
			$result = preg_replace_callback( $pattern, static function ( $tag_match ) use ( $matches, $new_url, $operation, &$changed, &$seen, $ordinal ) {
				$candidates = preg_split( '/\s*,\s*/', $tag_match[5] );
				foreach ( $candidates as $index => $candidate ) {
					$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
					if ( $changed || empty( $parts[0] ) || ! $matches( $parts[0] ) || $seen++ !== $ordinal ) { continue; }
					++$changed;
					if ( 'unlink' === $operation ) { unset( $candidates[ $index ] ); } else { $candidates[ $index ] = esc_attr( $new_url ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' ); }
				}
				return '<' . $tag_match[1] . $tag_match[2] . $tag_match[3] . '=' . $tag_match[4] . implode( ', ', $candidates ) . $tag_match[4] . $tag_match[6] . '>';
			}, $content );
			return $changed ? $result : new \WP_Error( 'sblc_not_found', __( 'The selected URL was not found in the source content.', 'linksolva-broken-link-checker' ) );
		}
		if ( $location && 'text' !== $location && ! preg_match( '/^(a|img|source|video|audio)\[(href|src|data-src|data-lazy-src|data-original)\]$/', $location, $location_parts ) ) {
			return new \WP_Error( 'sblc_not_found', __( 'This source location cannot be safely edited.', 'linksolva-broken-link-checker' ) );
		}
		if ( 'text' !== $location ) {
			$tag = $location ? $location_parts[1] : '(?:a|img|source|video|audio)';
			$attribute = $location ? $location_parts[2] : '(?:src|href)';
			$pattern = '~<(' . $tag . ')\b([^>]*?)(?<![\w-])(' . $attribute . ')\s*=\s*(["\'])([^"\']*)\4([^>]*)>~is';
			$seen = 0;
			$result = preg_replace_callback( $pattern, static function ( $tag_match ) use ( $matches, $new_url, &$changed, &$seen, $ordinal ) {
				if ( $changed || ! $matches( $tag_match[5] ) || $seen++ !== $ordinal ) {
					return $tag_match[0];
				}
				++$changed;
				return '<' . $tag_match[1] . $tag_match[2] . $tag_match[3] . '=' . $tag_match[4] . esc_attr( $new_url ) . $tag_match[4] . $tag_match[6] . '>';
			}, $content );
			if ( $changed ) { return $result; }
			if ( $location ) { return new \WP_Error( 'sblc_not_found', __( 'The selected URL was not found in the source content.', 'linksolva-broken-link-checker' ) ); }
		}

		if ( $old_raw && false !== strpos( $content, $old_raw ) ) {
			// Match text nodes, never a URL that happens to appear in an HTML attribute.
			$parts = preg_split( '/(<[^>]*>)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
			$seen = 0;
			foreach ( $parts as $index => $part ) {
				if ( 1 === $index % 2 ) { continue; }
				$parts[ $index ] = preg_replace_callback( '/' . preg_quote( $old_raw, '/' ) . '/', static function ( $match ) use ( &$changed, &$seen, $ordinal, $new_url ) {
					if ( $changed || $seen++ !== $ordinal ) { return $match[0]; }
					++$changed;
					return esc_url_raw( $new_url );
				}, $part );
			}
			if ( $changed ) { return implode( '', $parts ); }
		}
		return new \WP_Error( 'sblc_not_found', __( 'The selected URL was not found in the source content.', 'linksolva-broken-link-checker' ) );
	}
}
