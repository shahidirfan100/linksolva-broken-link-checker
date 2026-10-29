<?php
/**
 * Privileged REST API for the admin application.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Defines the capability-protected REST API used by the admin interface.
 */
final class Rest {
	const NAMESPACE = 'sblc/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register() {
		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'dashboard' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/resources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'resources' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'scan_history' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/history/(?P<id>\d+)/resources',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'scan_history_resources' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/history/(?P<id>\d+)/resource/(?P<resource_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'scan_history_resource' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/resources/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'bulk' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/resource/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'resource' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/resource/(?P<id>\d+)/action',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'resource_action' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scan/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'scan_start' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scan/step',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'scan_step' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/scan/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'scan_cancel' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/repair',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'repair' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/repair/(?P<id>\d+)/undo',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'undo' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'settings_get' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'settings_save' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public static function permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error( 'sblc_forbidden', __( 'You are not allowed to use LinkSolva - Broken Link Checker.', 'linksolva-broken-link-checker' ), array( 'status' => rest_authorization_required_code() ) );
	}

	/**
	 * Dashboard data.
	 *
	 * @return \WP_REST_Response
	 */
	public static function dashboard() {
		return rest_ensure_response(
			array(
				'progress' => Scanner::latest_progress(),
				'counts'   => Database::counts(),
			)
		);
	}

	/**
	 * Paginated resources.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function resources( $request ) {
		$args = array(
			'page'     => absint( $request->get_param( 'page' ) ) ? absint( $request->get_param( 'page' ) ) : 1,
			'per_page' => absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 20,
			'status'   => sanitize_key( $request->get_param( 'status' ) ),
			'type'     => sanitize_key( $request->get_param( 'type' ) ),
			'scope'    => sanitize_key( $request->get_param( 'scope' ) ),
			'search'   => sanitize_text_field( $request->get_param( 'search' ) ),
		);
		return rest_ensure_response( Database::list_resources( $args ) );
	}

	/**
	 * Return saved completed scans.
	 *
	 * @return \WP_REST_Response
	 */
	public static function scan_history() {
		$items = Database::scan_history( Settings::get( 'scan_history_limit', 5 ) );
		foreach ( $items as $item ) {
			$item->counts = self::decode( $item->counts );
		}
		return rest_ensure_response(
			array(
				'items' => $items,
				'limit' => Settings::get( 'scan_history_limit', 5 ),
			)
		);
	}

	/**
	 * Return one page of saved results from a completed scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function scan_history_resources( $request ) {
		$scan_id = absint( $request->get_param( 'id' ) );
		if ( ! Database::get_history_scan( $scan_id ) ) {
			return new \WP_Error( 'sblc_history_missing', __( 'The saved scan was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			Database::scan_history_resources(
				$scan_id,
				array(
					'page'     => absint( $request->get_param( 'page' ) ) ? absint( $request->get_param( 'page' ) ) : 1,
					'per_page' => absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 20,
					'status'   => sanitize_key( $request->get_param( 'status' ) ),
					'type'     => sanitize_key( $request->get_param( 'type' ) ),
					'search'   => sanitize_text_field( $request->get_param( 'search' ) ),
				)
			)
		);
	}

	/**
	 * Return read-only evidence from a saved scan result.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function scan_history_resource( $request ) {
		$scan_id     = absint( $request->get_param( 'id' ) );
		$resource_id = absint( $request->get_param( 'resource_id' ) );
		if ( ! Database::get_history_scan( $scan_id ) ) {
			return new \WP_Error( 'sblc_history_missing', __( 'The saved scan was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		$resource = Database::get_scan_history_resource( $scan_id, $resource_id );
		if ( ! $resource ) {
			return new \WP_Error( 'sblc_history_resource_missing', __( 'The saved URL result was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		$data                    = self::object_to_array( $resource );
		$data['historical']      = true;
		$data['redirect_chain']  = self::decode( $resource->redirect_chain );
		$data['request_history'] = self::decode( $resource->request_history );
		$data['occurrences']     = array_map( array( __CLASS__, 'object_to_array' ), Database::scan_history_occurrences( $scan_id, $resource_id ) );
		return rest_ensure_response( $data );
	}

	/**
	 * Resource details and occurrences.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function resource( $request ) {
		$resource = Database::get_resource( absint( $request->get_param( 'id' ) ) );
		if ( ! $resource ) {
			return new \WP_Error( 'sblc_resource_missing', __( 'The requested resource was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		$data                    = self::object_to_array( $resource );
		$data['redirect_chain']  = self::decode( $resource->redirect_chain );
		$data['request_history'] = self::decode( $resource->request_history );
		$data['occurrences']     = array_map( array( __CLASS__, 'object_to_array' ), Database::occurrences( $resource->id ) );
		$data['repairs']         = array_map( array( __CLASS__, 'object_to_array' ), Database::repairs_for_resource( $resource->id ) );
		return rest_ensure_response( $data );
	}

	/**
	 * Start a scan.
	 *
	 * @return array|\WP_Error
	 */
	public static function scan_start() {
		return self::response_or_error( Scanner::start() );
	}

	/**
	 * Process a bounded scan step.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function scan_step( $request ) {
		$scan_id = absint( $request->get_param( 'scan_id' ) );
		if ( ! $scan_id ) {
			$progress = Scanner::latest_progress();
			$scan_id  = absint( $progress['scan_id'] );
		}
		return self::response_or_error( $scan_id ? Scanner::step( $scan_id ) : Scanner::latest_progress() );
	}

	/**
	 * Cancel a scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function scan_cancel( $request ) {
		return self::response_or_error( Scanner::cancel( absint( $request->get_param( 'scan_id' ) ) ) );
	}

	/**
	 * Handle single-resource state actions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function resource_action( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$action   = sanitize_key( $request->get_param( 'action' ) );
		$resource = Database::get_resource( $id );
		if ( ! $resource ) {
			return new \WP_Error( 'sblc_resource_missing', __( 'The requested resource was not found.', 'linksolva-broken-link-checker' ), array( 'status' => 404 ) );
		}
		switch ( $action ) {
			case 'recheck':
				$result = Http_Checker::check( $resource->url, absint( $resource->retry_count ) );
				Database::update_resource(
					$id,
					array(
						'ignored'         => 0,
						'manual_verified' => 0,
					)
				);
				Database::save_result( $id, 0, $result );
				break;
			case 'ignore':
				Database::update_resource(
					$id,
					array(
						'ignored'         => 1,
						'status'          => 'ignored',
						'confidence'      => 'unverified',
						'status_text'     => __( 'Ignored', 'linksolva-broken-link-checker' ),
						'explanation'     => __( 'This resource is hidden from action-focused filters until it is restored.', 'linksolva-broken-link-checker' ),
						'manual_verified' => 0,
					)
				);
				break;
			case 'restore':
				Database::update_resource(
					$id,
					array(
						'ignored'         => 0,
						'status'          => 'unverified',
						'confidence'      => 'unverified',
						'status_text'     => __( 'Recheck needed', 'linksolva-broken-link-checker' ),
						'explanation'     => __( 'The resource was restored to the review queue.', 'linksolva-broken-link-checker' ),
						'checked_scan_id' => 0,
						'manual_verified' => 0,
						'next_check_at'   => null,
						'retry_count'     => 0,
					)
				);
				break;
			case 'manual_verify':
				Database::update_resource(
					$id,
					array(
						'ignored'         => 0,
						'manual_verified' => 1,
						'status'          => 'healthy',
						'confidence'      => 'manual',
						'status_text'     => __( 'Manually verified', 'linksolva-broken-link-checker' ),
						'explanation'     => __( 'An administrator marked this resource as verified without an automated request.', 'linksolva-broken-link-checker' ),
						'last_checked'    => Database::now(),
						'next_check_at'   => null,
					)
				);
				break;
			default:
				return new \WP_Error( 'sblc_action_invalid', __( 'This resource action is not supported.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
		}
		$detail_request = new \WP_REST_Request( 'GET', '/sblc/v1/resource/' . $id );
		$detail_request->set_param( 'id', $id );
		return self::resource( $detail_request );
	}

	/**
	 * Apply bulk state actions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function bulk( $request ) {
		$ids    = $request->get_param( 'ids' );
		$ids    = is_array( $ids ) ? array_slice( array_values( array_filter( array_map( 'absint', $ids ) ) ), 0, 100 ) : array();
		$action = sanitize_key( $request->get_param( 'action' ) );
		if ( empty( $ids ) || ! in_array( $action, array( 'ignore', 'restore', 'recheck' ), true ) ) {
			return new \WP_Error( 'sblc_bulk_invalid', __( 'Choose valid resources and an action.', 'linksolva-broken-link-checker' ), array( 'status' => 400 ) );
		}
		foreach ( $ids as $id ) {
			if ( 'recheck' === $action ) {
				Database::update_resource(
					$id,
					array(
						'ignored'         => 0,
						'manual_verified' => 0,
						'status'          => 'unverified',
						'confidence'      => 'unverified',
						'status_text'     => __( 'Queued for recheck', 'linksolva-broken-link-checker' ),
						'explanation'     => __( 'This resource will be checked by the next scan.', 'linksolva-broken-link-checker' ),
						'checked_scan_id' => 0,
						'next_check_at'   => null,
						'retry_count'     => 0,
					)
				);
			} elseif ( 'ignore' === $action ) {
				Database::update_resource(
					$id,
					array(
						'ignored'     => 1,
						'status'      => 'ignored',
						'confidence'  => 'unverified',
						'status_text' => __( 'Ignored', 'linksolva-broken-link-checker' ),
					)
				);
			} else {
				Database::update_resource(
					$id,
					array(
						'ignored'         => 0,
						'manual_verified' => 0,
						'status'          => 'unverified',
						'confidence'      => 'unverified',
						'checked_scan_id' => 0,
						'next_check_at'   => null,
						'retry_count'     => 0,
					)
				);
			}
		}
		return array( 'message' => __( 'The selected resources were updated.', 'linksolva-broken-link-checker' ) );
	}

	/**
	 * Apply an audited source repair.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function repair( $request ) {
		return self::response_or_error( Repair::apply( absint( $request->get_param( 'occurrence_id' ) ), sanitize_key( $request->get_param( 'operation' ) ), esc_url_raw( $request->get_param( 'new_url' ) ) ) );
	}

	/**
	 * Undo an audited repair.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array|\WP_Error
	 */
	public static function undo( $request ) {
		return self::response_or_error( Repair::undo( absint( $request['id'] ) ) );
	}

	/**
	 * Get settings.
	 *
	 * @return array
	 */
	public static function settings_get() {
		$post_types = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$post_types[] = array(
				'name'  => $post_type->name,
				'label' => $post_type->labels->name,
			);
		}
		return array(
			'settings'   => Settings::all(),
			'post_types' => $post_types,
		);
	}

	/**
	 * Save settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	public static function settings_save( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}
		return array( 'settings' => Settings::save( $data ) );
	}

	/**
	 * Return a response or pass a WP_Error through.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function response_or_error( $value ) {
		return $value;
	}

	/**
	 * Decode stored JSON.
	 *
	 * @param string $value JSON.
	 * @return array
	 */
	private static function decode( $value ) {
		$data = json_decode( (string) $value, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Convert a row object to an escaped API-safe array.
	 *
	 * @param object|mixed $value Value.
	 * @return mixed
	 */
	public static function object_to_array( $value ) {
		return is_object( $value ) ? get_object_vars( $value ) : $value;
	}
}
