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
							self::add( $found, $node->getAttribute( $attribute ), $base, $source, $type, $node->textContent, $tag . '[' . $attribute . ']' );
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
									self::add( $found, $parts[0], $base, $source, 'image', '', $tag . '[' . $srcset_attribute . ']' );
								}
							}
						}
					}
				}
			}
		}

		$plain = wp_strip_all_tags( $content );
		if ( preg_match_all( '~https?://[^\s<>"\'()]+~iu', $plain, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url = rtrim( $url, '.,;:!?]' );
				self::add( $found, $url, $base, $source, 'link', $url, 'text' );
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
	private static function add( &$found, $raw, $base, $source, $type, $anchor, $location ) {
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
			'context'       => self::context( $source, $anchor, $normalized ),
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
	 * @param array  $source Source.
	 * @param string $anchor Anchor.
	 * @param string $url URL.
	 * @return string
	 */
	private static function context( $source, $anchor, $url ) {
		$context = ! empty( $source['content'] ) ? wp_strip_all_tags( $source['content'] ) : $anchor;
		$context = preg_replace( '/\s+/', ' ', trim( $context ) );
		if ( strlen( $context ) > 180 ) {
			$context = substr( $context, 0, 177 ) . '...';
		}
		return $context ? $context : $url;
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
	public static function edit( $content, $old_raw, $old_url, $new_url, $operation, $base = '' ) {
		$changed = 0;
		$matches = static function ( $candidate ) use ( $old_raw, $old_url, $base ) {
			return trim( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === trim( $old_raw ) || Url::normalize( $candidate, $base ) === $old_url;
		};

		if ( 'nofollow' === $operation ) {
			$result = preg_replace_callback(
				'~<a\b([^>]*)>~i',
				static function ( $tag_match ) use ( $matches, &$changed ) {
					if ( $changed || ! preg_match( '~\bhref\s*=\s*["\']([^"\']*)["\']~i', $tag_match[0], $href ) || ! $matches( $href[1] ) ) {
						return $tag_match[0];
					}
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

		if ( 'unlink' === $operation ) {
			$result = preg_replace_callback(
				'~<a\b[^>]*\bhref\s*=\s*(["\'])([^"\']*)\1[^>]*>(.*?)</a>~is',
				static function ( $tag_match ) use ( $matches, &$changed ) {
					if ( $changed || ! $matches( $tag_match[2] ) ) {
						return $tag_match[0];
					}
					++$changed;
					return $tag_match[3];
				},
				$content
			);
			if ( $changed ) {
				return $result;
			}
		}

		$result = preg_replace_callback(
			'~<(a|img|source|video|audio)\b([^>]*?)\b(src|href)\s*=\s*(["\'])([^"\']*)\4([^>]*)>~is',
			static function ( $tag_match ) use ( $matches, $new_url, &$changed ) {
				if ( $changed || ! $matches( $tag_match[5] ) ) {
					return $tag_match[0];
				}
				++$changed;
				return '<' . $tag_match[1] . $tag_match[2] . $tag_match[3] . '=' . $tag_match[4] . esc_attr( $new_url ) . $tag_match[4] . $tag_match[6] . '>';
			},
			$content
		);
		if ( $changed ) {
			return $result;
		}

		if ( $old_raw && false !== strpos( $content, $old_raw ) ) {
			$safe_new_url = esc_url_raw( $new_url );
			return preg_replace_callback(
				'/' . preg_quote( $old_raw, '/' ) . '/',
				static function () use ( $safe_new_url ) {
					return $safe_new_url;
				},
				$content,
				1,
				$changed
			);
		}
		return new \WP_Error( 'sblc_not_found', __( 'The selected URL was not found in the source content.', 'linksolva-broken-link-checker' ) );
	}
}
