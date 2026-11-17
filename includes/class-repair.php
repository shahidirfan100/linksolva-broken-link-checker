<?php
/**
 * Safe source editing with audit records and conflict-aware undo.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Applies audited source repairs and conflict-aware undo operations.
 */
final class Repair {
	/**
	 * Apply one supported repair.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $operation     replace, unlink, or nofollow.
	 * @param string $new_url       Replacement URL.
	 * @return array|\WP_Error
	 */
	public static function apply( $occurrence_id, $operation, $new_url = '' ) {
		$occurrence = Database::get_occurrence( $occurrence_id );
		if ( ! $occurrence ) {
			return new \WP_Error( 'sblc_occurrence_missing', __( 'The selected source occurrence was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		if ( ! $occurrence->editable ) {
			return new \WP_Error( 'sblc_source_read_only', __( 'This source is read-only. The plugin will not modify builder or metadata data automatically.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
		}
		$operation = sanitize_key( $operation );
		if ( ! in_array( $operation, array( 'replace', 'unlink', 'nofollow' ), true ) ) {
			return new \WP_Error( 'sblc_operation_invalid', __( 'This repair action is not supported.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_edit( $occurrence ) ) {
			return new \WP_Error( 'sblc_edit_forbidden', __( 'You do not have permission to edit this source.', 'linksolva-broken-link-checker' ), array( 'status' => 403 ) );
		}

		$resource = Database::get_resource( $occurrence->resource_id );
		$before   = self::source_value( $occurrence );
		if ( null === $before ) {
			return new \WP_Error( 'sblc_source_missing', __( 'The source content could not be loaded.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		$target          = $resource ? $resource->url : $occurrence->raw_url;
		$old_resource_id = absint( $occurrence->resource_id );
		$scan_id         = absint( $occurrence->scan_id );
		$ordinal         = null;
		if ( 'menu_item' === $occurrence->source_type ) {
			$menu_post = get_post( $occurrence->source_id );
			$item      = $menu_post ? wp_setup_nav_menu_item( $menu_post ) : false;
			$current   = $item && isset( $item->url ) ? $item->url : '';
			if ( ! $current || ! self::urls_match( $current, $occurrence->raw_url, $occurrence->source_url ) ) {
				return new \WP_Error( 'sblc_source_changed', __( 'The menu item changed since this finding was recorded. Recheck it before editing.', 'linksolva-broken-link-checker' ), array( 'status' => 409 ) );
			}
		} else {
			$ordinal = self::occurrence_ordinal( $occurrence, $before, $target );
			if ( null === $ordinal ) {
				return new \WP_Error( 'sblc_source_changed', __( 'The source changed since this finding was recorded. Run a new scan before editing it.', 'linksolva-broken-link-checker' ), array( 'status' => 409 ) );
			}
		}
		if ( ! $scan_id && $resource ) {
			$scan_id = absint( $resource->last_scan_id );
		}
		$replacement     = '';
		$new_resource_id = 0;
		$verification    = null;
		if ( 'replace' === $operation ) {
			$replacement = Url::normalize( $new_url, $occurrence->source_url );
			if ( ! $replacement ) {
				return new \WP_Error( 'sblc_replacement_invalid', __( 'Enter a valid HTTP or HTTPS replacement URL.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
			}
			$new_resource_id = Database::upsert_resource( $replacement, $resource ? $resource->resource_type : 'link', $scan_id );
			if ( ! $new_resource_id ) {
				return new \WP_Error( 'sblc_replacement_prepare_failed', __( 'The replacement URL could not be prepared for verification. The source was not changed.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
			}
			$verification = Http_Checker::check( $replacement );
			if ( ! in_array( $verification['status'], array( 'healthy', 'redirect' ), true ) || 'confirmed' !== $verification['confidence'] ) {
				Database::retire_resource_if_orphaned( $new_resource_id );
				return new \WP_Error(
					'sblc_replacement_not_verified',
					__( 'The replacement URL did not return healthy or redirect evidence, so the original source was not changed.', 'linksolva-broken-link-checker' ),
					array(
						'status'      => 422,
						'sblc_status' => $verification['status'],
					)
				);
			}
		} elseif ( 'nofollow' === $operation ) {
			$replacement = $target;
		}

		if ( 'menu_item' === $occurrence->source_type ) {
			if ( 'replace' !== $operation ) {
				return new \WP_Error( 'sblc_menu_action_invalid', __( 'Only URL replacement is supported for menu items.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
			}
			$after = $replacement;
		} else {
			$after = Extractor::edit( $before, $occurrence->raw_url, $target, $replacement, $operation, $occurrence->source_url, $ordinal, $occurrence->location );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			if ( $after === $before ) {
				return new \WP_Error( 'sblc_no_change', __( 'No source content was changed.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $occurrence->source_type, array( 'post', 'comment' ), true ) ) {
				return new \WP_Error( 'sblc_source_unsupported', __( 'This source type does not have a safe editor.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
			}
		}

		// Store the undo record before changing content so a successful edit can never be unaudited.
		$repair_id = Database::add_repair(
			array(
				'resource_id'     => absint( $occurrence->resource_id ),
				'occurrence_id'   => absint( $occurrence_id ),
				'operation'       => $operation,
				'source_type'     => sanitize_key( $occurrence->source_type ),
				'source_id'       => absint( $occurrence->source_id ),
				'source_field'    => sanitize_text_field( $occurrence->source_field ),
				'before_value'    => $before,
				'after_value'     => $after,
				'before_checksum' => hash( 'sha256', $before ),
				'after_checksum'  => hash( 'sha256', $after ),
				'user_id'         => get_current_user_id(),
				'created_at'      => Database::now(),
				'undone'          => 0,
				'undo_error'      => '',
			)
		);
		if ( ! $repair_id ) {
			return new \WP_Error( 'sblc_audit_failed', __( 'The source was not changed because the undo record could not be created.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
		}

		// Recheck after remote verification so an editor's intervening change is never overwritten.
		if ( self::source_value( $occurrence ) !== $before ) {
			if ( $new_resource_id && $new_resource_id !== $old_resource_id ) {
				Database::retire_resource_if_orphaned( $new_resource_id );
			}
			Database::update_repair( $repair_id, array( 'undone' => 1, 'undo_error' => __( 'The repair was not applied because the source changed during verification.', 'linksolva-broken-link-checker' ) ) );
			return new \WP_Error( 'sblc_source_changed', __( 'The source changed while the replacement was being verified. Run a new scan before editing it.', 'linksolva-broken-link-checker' ), array( 'status' => 409 ) );
		}

		$result = self::save_source( $occurrence, $after );
		if ( is_wp_error( $result ) || false === $result || 0 === $result ) {
			if ( $new_resource_id && $new_resource_id !== $old_resource_id ) {
				Database::retire_resource_if_orphaned( $new_resource_id );
			}
			Database::update_repair(
				$repair_id,
				array(
					'undone'     => 1,
					'undo_error' => __( 'The repair was not applied because WordPress could not save the source.', 'linksolva-broken-link-checker' ),
				)
			);
			return new \WP_Error( 'sblc_update_failed', __( 'WordPress could not save the repaired source.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
		}

		if ( 'replace' === $operation ) {
			$occurrence_updated = Database::update_occurrence(
				$occurrence_id,
				array(
					'resource_id' => $new_resource_id,
					'raw_url'     => $replacement,
				)
			);
			if ( ! $occurrence_updated ) {
				/* Keep source content and the resource index consistent if the mapping fails. */
				self::save_source( $occurrence, $before );
				Database::retire_resource_if_orphaned( $new_resource_id );
				Database::update_repair(
					$repair_id,
					array(
						'undone'     => 1,
						'undo_error' => __( 'The source was restored because its resource index could not be updated.', 'linksolva-broken-link-checker' ),
					)
				);
				return new \WP_Error( 'sblc_index_update_failed', __( 'The source was restored because the replacement could not be indexed.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
			}
			Database::update_resource(
				$new_resource_id,
				array(
					'ignored'         => 0,
					'manual_verified' => 0,
				)
			);
			Database::save_result( $new_resource_id, $scan_id, $verification );
			if ( $new_resource_id !== $old_resource_id ) {
				Database::retire_resource_if_orphaned( $old_resource_id );
			}
			return array(
				'repair_id'   => $repair_id,
				'resource_id' => $new_resource_id,
				'status'      => $verification['status'],
				'http_code'   => $verification['http_code'],
				'message'     => __( 'The source was updated, the replacement was verified, and the old finding was removed when no sources still used it.', 'linksolva-broken-link-checker' ),
			);
		}

		if ( 'unlink' === $operation ) {
			Database::update_occurrence( $occurrence_id, array( 'resource_id' => 0 ) );
			Database::retire_resource_if_orphaned( $old_resource_id );
			return array(
				'repair_id' => $repair_id,
				'message'   => __( 'The link was unlinked and removed from the finding when no sources still used it.', 'linksolva-broken-link-checker' ),
			);
		}

		Database::update_resource(
			$old_resource_id,
			array(
				'status'          => 'unverified',
				'confidence'      => 'unverified',
				'status_text'     => __( 'Recheck needed', 'linksolva-broken-link-checker' ),
				'explanation'     => __( 'The source was changed. Recheck this resource to collect fresh evidence.', 'linksolva-broken-link-checker' ),
				'checked_scan_id' => 0,
				'last_checked'    => null,
				'next_check_at'   => null,
				'retry_count'     => 0,
			)
		);
		return array(
			'repair_id' => $repair_id,
			'message'   => __( 'The source was updated and the change was recorded for undo.', 'linksolva-broken-link-checker' ),
		);
	}

	/**
	 * Undo one repair if its source has not changed since the repair.
	 *
	 * @param int $repair_id Repair ID.
	 * @return array|\WP_Error
	 */
	public static function undo( $repair_id ) {
		$repair = Database::get_repair( $repair_id );
		if ( ! $repair ) {
			return new \WP_Error( 'sblc_repair_missing', __( 'The repair record was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		if ( $repair->undone ) {
			return new \WP_Error( 'sblc_already_undone', __( 'This repair has already been undone.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
		}
		$occurrence        = Database::get_occurrence( $repair->occurrence_id );
		$stored_occurrence = (bool) $occurrence;
		if ( ! $occurrence ) {
			$occurrence = (object) array(
				'source_type'  => $repair->source_type,
				'source_id'    => absint( $repair->source_id ),
				'source_field' => $repair->source_field,
				'editable'     => 1,
			);
		}
		if ( ! $occurrence || ! self::can_edit( $occurrence ) ) {
			return new \WP_Error( 'sblc_edit_forbidden', __( 'You do not have permission to undo this repair.', 'linksolva-broken-link-checker' ), array( 'status' => 403 ) );
		}
		$current = self::source_value( $occurrence );
		if ( null === $current || hash( 'sha256', $current ) !== $repair->after_checksum ) {
			Database::update_repair( $repair_id, array( 'undo_error' => __( 'Undo was blocked because the source changed after this repair.', 'linksolva-broken-link-checker' ) ) );
			return new \WP_Error( 'sblc_undo_conflict', __( 'Undo was blocked because the source changed after this repair. Review the source manually.', 'linksolva-broken-link-checker' ), array( 'status' => 409 ) );
		}
		$result = self::save_source( $occurrence, $repair->before_value );
		if ( is_wp_error( $result ) || false === $result || 0 === $result ) {
			return new \WP_Error( 'sblc_undo_failed', __( 'WordPress could not restore the original source.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
		}

		/*
		 * A replacement or unlink changes the occurrence-to-resource mapping as
		 * well as the source text. Restore that mapping before marking the audit
		 * record undone so the next scan can verify the original URL again.
		 */
		$repair_occurrence_id = absint( $repair->occurrence_id );
		if ( $stored_occurrence && $repair_occurrence_id ) {
			$current_resource_id = isset( $occurrence->resource_id ) ? absint( $occurrence->resource_id ) : 0;
			$old_resource_id     = absint( $repair->resource_id );
			$old_resource        = Database::get_resource( $old_resource_id );
			$scan_id             = isset( $occurrence->scan_id ) ? absint( $occurrence->scan_id ) : 0;
			if ( ! $scan_id && $old_resource ) {
				$scan_id = absint( $old_resource->last_scan_id );
			}
			if ( ! $scan_id ) {
				$latest  = Database::get_latest_scan();
				$scan_id = $latest ? absint( $latest->id ) : 0;
			}

			if ( in_array( $repair->operation, array( 'replace', 'unlink' ), true ) ) {
				$old_url = $old_resource ? (string) $old_resource->url : (string) $occurrence->raw_url;
				$mapped  = Database::update_occurrence(
					$repair_occurrence_id,
					array(
						'resource_id' => $old_resource_id,
						'raw_url'     => $old_url,
					)
				);
				if ( ! $mapped ) {
					Database::update_repair( $repair_id, array( 'undo_error' => __( 'The source was restored, but its resource index could not be restored. Review the finding before scanning again.', 'linksolva-broken-link-checker' ) ) );
					return new \WP_Error( 'sblc_undo_index_failed', __( 'The source was restored, but the finding index could not be restored.', 'linksolva-broken-link-checker' ), array( 'status' => 500 ) );
				}
				if ( $current_resource_id && $current_resource_id !== $old_resource_id ) {
					Database::retire_resource_if_orphaned( $current_resource_id );
				}
			}
			if ( $old_resource_id && $scan_id ) {
				Database::restore_resource_for_scan( $old_resource_id, $scan_id );
			}
		}
		Database::update_repair(
			$repair_id,
			array(
				'undone'     => 1,
				'undo_error' => '',
			)
		);
		return array( 'message' => __( 'The original source was restored.', 'linksolva-broken-link-checker' ) );
	}

	/**
	 * Load a source value.
	 *
	 * @param object $occurrence Occurrence.
	 * @return string|null
	 */
	private static function source_value( $occurrence ) {
		if ( 'post' === $occurrence->source_type ) {
			$post = get_post( $occurrence->source_id );
			return $post ? (string) $post->post_content : null;
		}
		if ( 'comment' === $occurrence->source_type ) {
			$comment = get_comment( $occurrence->source_id );
			return $comment ? (string) $comment->comment_content : null;
		}
		if ( 'menu_item' === $occurrence->source_type ) {
			$item = get_post( $occurrence->source_id );
			return $item ? (string) get_post_meta( $item->ID, '_menu_item_url', true ) : null;
		}
		return null;
	}

	/** Find the original occurrence in the current source, failing closed if it changed. */
	private static function occurrence_ordinal( $occurrence, $content, $target ) {
		$candidates = Extractor::extract( $content, $occurrence->source_url, array( 'content' => $content ) );
		if ( ! empty( $occurrence->source_checksum ) && ! hash_equals( $occurrence->source_checksum, hash( 'sha256', $content ) ) ) {
			return null;
		}
		if ( empty( $occurrence->source_checksum ) ) {
			$matching = array_filter( $candidates, static function ( $candidate ) use ( $occurrence, $target ) {
				return $candidate['location'] === $occurrence->location && $candidate['url'] === $target;
			} );
			if ( count( $matching ) !== 1 ) { return null; }
		}
		$ordinal = 0;
		foreach ( $candidates as $index => $candidate ) {
			if ( $candidate['location'] !== $occurrence->location || $candidate['url'] !== $target ) {
				continue;
			}
			if ( 'text' === $candidate['location'] && $candidate['raw_url'] !== $occurrence->raw_url ) { continue; }
			$hash = hash( 'sha256', wp_json_encode( array( absint( $occurrence->resource_id ), absint( $occurrence->scan_id ), $occurrence->source_type, absint( $occurrence->source_id ), $occurrence->source_field, $candidate['raw_url'], $candidate['location'], absint( $index ) ) ) );
			if ( hash_equals( $occurrence->occurrence_hash, $hash ) ) {
				return $ordinal;
			}
			++$ordinal;
		}
		return null;
	}

	/**
	 * Save a source value.
	 *
	 * @param object $occurrence Occurrence.
	 * @param string $value      Value.
	 * @return mixed
	 */
	private static function save_source( $occurrence, $value ) {
		if ( 'post' === $occurrence->source_type ) {
			return wp_update_post(
				array(
					'ID'           => absint( $occurrence->source_id ),
					'post_content' => wp_slash( $value ),
				),
				true
			);
		}
		if ( 'comment' === $occurrence->source_type ) {
			return wp_update_comment(
				array(
					'comment_ID'      => absint( $occurrence->source_id ),
					'comment_content' => wp_slash( $value ),
				)
			);
		}
		if ( 'menu_item' === $occurrence->source_type ) {
			$menu_id = self::menu_id_for_item( $occurrence->source_id );
			$post = get_post( $occurrence->source_id );
			$item = $post ? wp_setup_nav_menu_item( $post ) : false;
			if ( ! $menu_id || ! $item ) {
				return false;
			}
			return $menu_id ? wp_update_nav_menu_item(
				$menu_id,
				$occurrence->source_id,
				wp_slash( array(
					'menu-item-url'    => esc_url_raw( $value ),
					'menu-item-status' => $post->post_status,
					'menu-item-title' => $post->post_title,
					'menu-item-description' => $post->post_content,
					'menu-item-attr-title' => $post->post_excerpt,
					'menu-item-parent-id' => $item->menu_item_parent,
					'menu-item-position' => $post->menu_order,
					'menu-item-type' => $item->type,
					'menu-item-object' => $item->object,
					'menu-item-object-id' => $item->object_id,
					'menu-item-target' => $item->target,
					'menu-item-classes' => implode( ' ', (array) $item->classes ),
					'menu-item-xfn' => $item->xfn,
				) )
			) : false;
		}
		return false;
	}

	/**
	 * Get the menu term containing an item.
	 *
	 * @param int $item_id Item ID.
	 * @return int
	 */
	private static function menu_id_for_item( $item_id ) {
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			foreach ( (array) $items as $item ) {
				if ( absint( $item->ID ) === absint( $item_id ) ) {
					return absint( $menu->term_id );
				}
			}
		}
		return 0;
	}

	/**
	 * Permission check for the source type.
	 *
	 * @param object $occurrence Occurrence.
	 * @return bool
	 */
	private static function can_edit( $occurrence ) {
		if ( 'post' === $occurrence->source_type ) {
			return current_user_can( 'edit_post', absint( $occurrence->source_id ) );
		}
		if ( 'comment' === $occurrence->source_type ) {
			return current_user_can( 'edit_comment', absint( $occurrence->source_id ) );
		}
		return 'menu_item' === $occurrence->source_type && current_user_can( 'edit_theme_options' );
	}

	/**
	 * Compare a raw source URL with a normalized resource URL.
	 *
	 * @param string $candidate Candidate.
	 * @param string $raw       Raw recorded value.
	 * @param string $base      Base URL.
	 * @return bool
	 */
	private static function urls_match( $candidate, $raw, $base ) {
		return trim( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === trim( $raw ) || Url::normalize( $candidate, $base ) === Url::normalize( $raw, $base );
	}
}
