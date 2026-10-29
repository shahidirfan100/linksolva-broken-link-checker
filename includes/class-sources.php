<?php
/**
 * WordPress source adapters.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Discovers supported WordPress content sources in bounded batches.
 */
final class Sources {
	/**
	 * Ordered source providers.
	 *
	 * @return array
	 */
	public static function providers() {
		$providers = array( 'posts' );
		if ( Settings::get( 'include_comments' ) ) {
			$providers[] = 'comments';
		}
		if ( Settings::get( 'include_menus' ) ) {
			$providers[] = 'menus';
		}
		if ( Settings::get( 'include_custom_fields' ) ) {
			$providers[] = 'postmeta';
		}
		return $providers;
	}

	/**
	 * Get a batch of source records.
	 *
	 * @param string $provider Provider.
	 * @param int    $page     Page.
	 * @param int    $limit    Limit.
	 * @param int    $offset   Offset within the current provider page.
	 * @return array
	 */
	public static function batch( $provider, $page, $limit, $offset = 0 ) {
		switch ( $provider ) {
			case 'comments':
				return self::comments( $page, $limit, $offset );
			case 'menus':
				return self::menus( $page, $limit, $offset );
			case 'postmeta':
				return self::postmeta( $page, $limit, $offset );
			case 'posts':
			default:
				return self::posts( $page, $limit, $offset );
		}
	}

	/**
	 * Discover URLs from one source record.
	 *
	 * @param array $source Source.
	 * @param int   $scan_id Scan ID.
	 * @return int Number of occurrences.
	 */
	public static function store_source( $source, $scan_id ) {
		$candidates = Extractor::extract( $source['content'], $source['source_url'], $source );
		$count      = 0;
		foreach ( $candidates as $candidate_index => $candidate ) {
			$resource_id = Database::upsert_resource( $candidate['url'], $candidate['resource_type'], $scan_id );
			if ( ! $resource_id ) {
				throw new \RuntimeException( 'Could not save a discovered URL.' );
			}
			$occurrence_id = Database::add_occurrence(
				array(
					'resource_id'      => $resource_id,
					'scan_id'          => $scan_id,
					'source_type'      => $source['source_type'],
					'source_id'        => $source['source_id'],
					'source_field'     => $source['source_field'],
					'source_title'     => $source['source_title'],
					'source_url'       => $source['source_url'],
					'anchor_text'      => $candidate['anchor_text'],
					'context'          => $candidate['context'],
					'raw_url'          => $candidate['raw_url'],
					'location'         => $candidate['location'],
					'occurrence_index' => absint( $candidate_index ),
					'editable'         => ! empty( $source['editable'] ),
				)
			);
			if ( false === $occurrence_id ) {
				throw new \RuntimeException( 'Could not save a URL source location.' );
			}
			$count += $occurrence_id ? 1 : 0;
		}
		return $count;
	}

	/**
	 * Public post and page adapter, including public custom post types.
	 *
	 * @param int $page Page.
	 * @param int $limit  Limit.
	 * @param int $offset Offset within the page.
	 * @return array
	 */
	private static function posts( $page, $limit, $offset = 0 ) {
		$types = Settings::post_types();
		if ( empty( $types ) ) {
			return array(
				'items'         => array(),
				'page_complete' => true,
				'has_more'      => false,
			);
		}
		$ids   = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => absint( $limit ),
				'paged'            => absint( $page ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$items[] = array(
				'source_type'  => 'post',
				'source_id'    => (int) $post->ID,
				'source_field' => 'post_content',
				'source_title' => $post->post_title,
				'source_url'   => get_permalink( $post ),
				'content'      => $post->post_content,
				'editable'     => true,
			);
			if ( Settings::get( 'include_featured_images' ) && has_post_thumbnail( $post ) ) {
				$image_url = wp_get_attachment_url( get_post_thumbnail_id( $post ) );
				if ( $image_url ) {
					$items[] = array(
						'source_type'  => 'post_thumbnail',
						'source_id'    => (int) $post->ID,
						'source_field' => 'featured_image',
						'source_title' => $post->post_title,
						'source_url'   => get_permalink( $post ),
						'content'      => $image_url,
						'force_type'   => 'image',
						'editable'     => false,
					);
				}
			}
		}
		$all_count     = count( $items );
		$items         = array_slice( $items, absint( $offset ), absint( $limit ) );
		$page_complete = absint( $offset ) + count( $items ) >= $all_count;
		return array(
			'items'         => $items,
			'page_complete' => $page_complete,
			'has_more'      => count( $ids ) === absint( $limit ),
		);
	}

	/**
	 * Approved comment adapter.
	 *
	 * @param int $page Page.
	 * @param int $limit  Limit.
	 * @param int $offset Offset within the provider stream.
	 * @return array
	 */
	private static function comments( $page, $limit, $offset = 0 ) {
		$comments = get_comments(
			array(
				'status'  => 'approve',
				'number'  => absint( $limit ),
				'offset'  => ( absint( $page ) - 1 ) * absint( $limit ) + absint( $offset ),
				'orderby' => 'comment_ID',
				'order'   => 'ASC',
			)
		);
		$items    = array();
		foreach ( $comments as $comment ) {
			$items[] = array(
				'source_type'  => 'comment',
				'source_id'    => (int) $comment->comment_ID,
				'source_field' => 'comment_content',
				/* translators: %s: Title of the post that received the comment. */
				'source_title' => sprintf( __( 'Comment on %s', 'linksolva-broken-link-checker' ), get_the_title( $comment->comment_post_ID ) ),
				'source_url'   => get_comment_link( $comment ),
				'content'      => $comment->comment_content,
				'editable'     => true,
			);
		}
		return array(
			'items'         => $items,
			'page_complete' => count( $comments ) < absint( $limit ),
			'has_more'      => count( $comments ) === absint( $limit ),
		);
	}

	/**
	 * Navigation menu item adapter.
	 *
	 * @param int $page   Page.
	 * @param int $limit  Limit.
	 * @param int $offset Offset within the current page.
	 * @return array
	 */
	private static function menus( $page = 1, $limit = 5, $offset = 0 ) {
		$menu_ids = wp_list_pluck( wp_get_nav_menus(), 'term_id' );
		if ( empty( $menu_ids ) ) {
			return array(
				'items'         => array(),
				'page_complete' => true,
				'has_more'      => false,
			);
		}
		$ids   = get_posts(
			array(
				'post_type'        => 'nav_menu_item',
				'post_status'      => 'publish',
				'posts_per_page'   => absint( $limit ),
				'offset'           => ( max( 1, absint( $page ) ) - 1 ) * absint( $limit ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Restricts each small menu page to items assigned to an indexed nav_menu term.
				'tax_query'        => array(
					array(
						'taxonomy' => 'nav_menu',
						'field'    => 'term_id',
						'terms'    => array_map( 'absint', $menu_ids ),
					),
				),
			)
		);
		$items = array();
		foreach ( $ids as $id ) {
			$item    = wp_setup_nav_menu_item( get_post( $id ) );
			$items[] = array(
				'source_type'  => 'menu_item',
				'source_id'    => absint( $id ),
				'source_field' => 'menu_item_url',
				'source_title' => $item && ! empty( $item->title ) ? $item->title : '',
				'source_url'   => home_url( '/' ),
				'content'      => $item && ! empty( $item->url ) ? $item->url : '',
				'force_type'   => 'link',
				'editable'     => $item && 'custom' === $item->type,
			);
		}
		$all_count = count( $items );
		$items     = array_slice( $items, absint( $offset ) );
		return array(
			'items'         => $items,
			'page_complete' => absint( $offset ) + count( $items ) >= $all_count,
			'has_more'      => count( $ids ) === absint( $limit ),
		);
	}

	/**
	 * Common scalar post-meta adapter. It reads builder data but never edits serialized data automatically.
	 *
	 * @param int $page Page.
	 * @param int $limit  Limit.
	 * @param int $offset Offset within the page.
	 * @return array
	 */
	private static function postmeta( $page, $limit, $offset = 0 ) {
		$types = Settings::post_types();
		$ids   = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => 'publish',
				'posts_per_page'   => absint( $limit ),
				'paged'            => absint( $page ),
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
			)
		);
		$items = array();
		$allow = array_filter( array_map( 'sanitize_key', (array) Settings::get( 'custom_meta_keys', array() ) ) );
		foreach ( $ids as $id ) {
			$all_meta = get_post_meta( $id );
			foreach ( $all_meta as $key => $values ) {
				if ( ! empty( $allow ) && ! in_array( $key, $allow, true ) ) {
					continue;
				}
				foreach ( (array) $values as $value ) {
					if ( ! is_string( $value ) || strlen( $value ) > 500000 ) {
						continue;
					}
					$items[] = array(
						'source_type'  => 'post_meta',
						'source_id'    => (int) $id,
						'source_field' => $key,
						'source_title' => get_the_title( $id ),
						'source_url'   => get_permalink( $id ),
						'content'      => $value,
						'editable'     => false,
					);
				}
			}
		}
		$all_count     = count( $items );
		$items         = array_slice( $items, absint( $offset ), absint( $limit ) );
		$page_complete = absint( $offset ) + count( $items ) >= $all_count;
		return array(
			'items'         => $items,
			'page_complete' => $page_complete,
			'has_more'      => count( $ids ) === absint( $limit ),
		);
	}
}
