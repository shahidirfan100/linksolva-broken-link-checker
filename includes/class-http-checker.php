<?php
/**
 * Evidence-based local HTTP verifier.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies one URL with bounded requests and evidence-based classification.
 */
final class Http_Checker {
	/**
	 * Check one normalized URL.
	 *
	 * @param string $url         URL.
	 * @param int    $retry_count Previous retry count.
	 * @return array
	 */
	public static function check( $url, $retry_count = 0 ) {
		return self::check_with_initial( $url, $retry_count, null );
	}

	/**
	 * Check several resources with bounded parallel HEAD requests.
	 *
	 * The first request for each URL is dispatched together through the
	 * WordPress-bundled Requests transport. Redirect hops and selective GET
	 * fallbacks remain per-URL and are handled by the same evidence engine as
	 * single checks. Older WordPress transports fall back to sequential checks.
	 *
	 * @param array $resources Resource objects or URL strings.
	 * @param float $deadline  Optional worker deadline as a microtime timestamp.
	 * @return array Results keyed by resource ID or input index.
	 */
	public static function check_many( $resources, $deadline = 0.0 ) {
		$deadline = (float) $deadline;
		$entries  = array();
		foreach ( (array) $resources as $key => $resource ) {
			$url   = is_object( $resource ) && isset( $resource->url ) ? (string) $resource->url : (string) $resource;
			$retry = is_object( $resource ) && isset( $resource->retry_count ) ? absint( $resource->retry_count ) : 0;
			if ( '' === $url ) {
				continue;
			}
			$entries[ $key ] = array(
				'url'   => $url,
				'retry' => $retry,
			);
		}
		if ( empty( $entries ) ) {
			return array();
		}

		/* Requests::request_multiple() is available in the supported WP 6.2+ range. */
		if ( ! class_exists( '\\WpOrg\\Requests\\Requests' ) || ! class_exists( '\\WP_HTTP_Requests_Hooks' ) ) {
			$results = array();
			foreach ( $entries as $key => $entry ) {
				$result = self::check_with_initial( $entry['url'], $entry['retry'], null, $deadline );
				$results[ $key ] = ! empty( $result['deferred'] )
					? self::scan_time_limit_result( $entry['url'], $entry['retry'] )
					: $result;
			}
			return $results;
		}

		$settings      = Settings::all();
		$results       = array();
		$requests      = array();
		$safeties      = array();
		$starts        = array();
		$timings       = array();
		$batch_started = microtime( true );
		$complete      = static function ( $response, $id ) use ( &$timings, &$starts ) {
			$timings[ $id ] = isset( $starts[ $id ] ) ? microtime( true ) - $starts[ $id ] : 0.0;
		};

		foreach ( $entries as $key => $entry ) {
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				$results[ $key ] = self::scan_time_limit_result( $entry['url'], $entry['retry'] );
				continue;
			}
			$safety = Ssrf_Guard::validate( $entry['url'] );
			if ( empty( $safety['safe'] ) ) {
				/* Preserve the single-check safety classification without a network call. */
				$results[ $key ] = self::check_with_initial( $entry['url'], $entry['retry'], null, $deadline, $safety );
				continue;
			}
			$args = self::request_args( $entry['url'], 'HEAD', $settings, $deadline );
			if ( $deadline > 0 && $args['timeout'] < 1 ) {
				$results[ $key ] = self::scan_time_limit_result( $entry['url'], $entry['retry'] );
				continue;
			}
			$starts[ $key ]   = $batch_started;
			$safeties[ $key ] = $safety;
			$requests[ $key ] = array(
				'url'     => $entry['url'],
				'headers' => $args['headers'],
				'data'    => '',
				'type'    => 'HEAD',
				'options' => self::requests_options( $entry['url'], $args, $complete ),
			);
		}

		if ( empty( $requests ) ) {
			return $results;
		}

		try {
			$responses = \WpOrg\Requests\Requests::request_multiple( $requests );
		} catch ( \Throwable $error ) {
			/* Do not repeat the same stalled network work in a sequential fallback. */
			foreach ( $requests as $key => $request ) {
				$started         = isset( $starts[ $key ] ) ? $starts[ $key ] : $batch_started;
				$duration        = microtime( true ) - $started;
				$results[ $key ] = self::parallel_failure(
					$entries[ $key ]['url'],
					$entries[ $key ]['retry'],
					$duration,
					$error->getMessage(),
					$settings
				);
			}
			return $results;
		}

		foreach ( $requests as $key => $request ) {
			if ( ! array_key_exists( $key, $responses ) ) {
				$started         = isset( $starts[ $key ] ) ? $starts[ $key ] : $batch_started;
				$duration        = microtime( true ) - $started;
				$results[ $key ] = self::parallel_failure(
					$entries[ $key ]['url'],
					$entries[ $key ]['retry'],
					$duration,
					__( 'The parallel request returned no response.', 'linksolva-broken-link-checker' ),
					$settings
				);
				continue;
			}
			$duration        = isset( $timings[ $key ] ) ? $timings[ $key ] : microtime( true ) - $batch_started;
			$initial         = self::parallel_response( $responses[ $key ], $duration );
			$results[ $key ] = self::check_with_initial( $entries[ $key ]['url'], $entries[ $key ]['retry'], $initial, $deadline, $safeties[ $key ] );
		}
		return $results;
	}

	/**
	 * Return an inconclusive result for a URL that cannot fit in the current worker.
	 *
	 * Saving this result completes the URL for this scan, preventing an exhausted
	 * worker budget from leaving the same queue item pending indefinitely.
	 *
	 * @param string $url         URL.
	 * @param int    $retry_count Previous retry count.
	 * @return array
	 */
	public static function scan_time_limit_result( $url, $retry_count = 0 ) {
		return self::result(
			'unverified',
			'unverified',
			0,
			$url,
			'scan_time_limit',
			__( 'Skipped for review', 'linksolva-broken-link-checker' ),
			__( 'This URL was skipped because the worker reached its time limit before verification finished. Review it manually or select Recheck to try again.', 'linksolva-broken-link-checker' ),
			array(),
			array(),
			0,
			$retry_count,
			$url
		);
	}

	/**
	 * Check one URL, optionally reusing a parallel HEAD response.
	 *
	 * @param string     $url              URL.
	 * @param int        $retry_count      Previous retry count.
	 * @param array|null $initial_response Initial HEAD response.
	 * @return array
	 */
	private static function check_with_initial( $url, $retry_count, $initial_response, $deadline = 0.0, $initial_safety = null ) {
		$settings    = Settings::all();
		$max_retries = absint( $settings['max_retries'] );
		if ( $deadline > 0 && microtime( true ) >= $deadline ) {
			return self::scan_time_limit_result( $url, $retry_count );
		}
		$safety      = is_array( $initial_safety ) ? $initial_safety : Ssrf_Guard::validate( $url );
		if ( empty( $safety['safe'] ) ) {
			$category    = ! empty( $safety['code'] ) ? sanitize_key( $safety['code'] ) : 'unsafe_request';
			$status_text = 'dns_error' === $category ? __( 'DNS error', 'linksolva-broken-link-checker' ) : __( 'Request not sent', 'linksolva-broken-link-checker' );
			$explanation = 'dns_error' === $category ? __( 'The domain could not be resolved from this server, so no request was sent.', 'linksolva-broken-link-checker' ) : __( 'This URL was not requested because it failed the local safety checks.', 'linksolva-broken-link-checker' );
			return self::result( 'unverified', 'unverified', 0, $url, $category, $status_text, $explanation, array(), array(), 0, $retry_count );
		}

		$history        = array();
		$redirects      = array();
		$visited        = array( Url::hash( $url ) => true );
		$current        = $url;
		$total_time     = 0.0;
		$redirect_count = 0;
		$max_redirects  = absint( $settings['max_redirects'] );
		$final_response = null;

		$first_request = true;
		while ( true ) {
			if ( $first_request && is_array( $initial_response ) ) {
				$head = $initial_response;
			} else {
				$head = self::request( $current, 'HEAD', $settings, $deadline );
				if ( ! empty( $head['deferred'] ) ) {
					return self::scan_time_limit_result( $url, $retry_count );
				}
			}
			$first_request = false;
			$total_time   += (float) $head['duration'];
			$history[]     = self::history_item( 'HEAD', $current, $head );
			$response      = $head;

			/*
			 * Retry with GET only after an HTTP response shows that HEAD was
			 * unsupported or inconclusive. A transport failure (such as a timeout)
			 * gets recorded and retried later instead of repeating the same wait.
			 */
			$head_needs_get = ! $head['error'] && (
				0 === (int) $head['code'] ||
				in_array( (int) $head['code'], array( 405, 406 ), true ) ||
				( $head['code'] >= 500 && $head['code'] <= 599 )
			);
			if ( $head_needs_get ) {
				$get = self::request( $current, 'GET', $settings, $deadline );
				if ( ! empty( $get['deferred'] ) ) {
					if ( in_array( (int) $head['code'], array( 405, 406 ), true ) ) {
						return self::result( 'unverified', 'unverified', $head['code'], $url, 'verification_deferred', __( 'Verification deferred', 'linksolva-broken-link-checker' ), __( 'The HEAD request did not establish the link status, and the worker time limit was reached before a GET request could confirm it.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, $retry_count, $current, $redirect_count, time() + 120 );
					}
				} else {
					$total_time += (float) $get['duration'];
					$history[]   = self::history_item( 'GET', $current, $get );
					$response    = $get;
				}
			}

			$final_response = $response;
			$code           = absint( $response['code'] );
			if ( $response['error'] || $code < 300 || $code >= 400 ) {
				break;
			}

			$location = wp_remote_retrieve_header( $response['response'], 'location' );
			$next     = $location ? Url::resolve_location( $location, $current ) : '';
			if ( ! $next ) {
				break;
			}
			if ( $redirect_count >= $max_redirects ) {
				/* translators: %d: Maximum number of allowed redirect hops. */
				return self::result( 'redirect', 'likely', $code, $url, 'too_many_redirects', self::status_name( $code ), sprintf( __( 'The link redirected more than the configured limit of %d hops.', 'linksolva-broken-link-checker' ), $max_redirects ), $redirects, $history, $total_time, 0, $current, $redirect_count );
			}
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				return self::deferred_redirect( $url, $code, $current, $next, $redirects, $history, $total_time, $redirect_count );
			}
			$next_safety = Ssrf_Guard::validate( $next );
			if ( empty( $next_safety['safe'] ) ) {
				return self::result( 'unverified', 'unverified', $code, $url, 'unsafe_redirect', self::status_name( $code ), __( 'The redirect chain was stopped because its next destination failed the local safety checks.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $url, 0 );
			}
			if ( $deadline > 0 && microtime( true ) >= $deadline ) {
				return self::deferred_redirect( $url, $code, $current, $next, $redirects, $history, $total_time, $redirect_count );
			}
			$next_hash   = Url::hash( $next );
			$redirects[] = array(
				'from'   => $current,
				'status' => $code,
				'to'     => $next,
			);
			if ( isset( $visited[ $next_hash ] ) ) {
				return self::result( 'redirect', 'likely', $code, $url, 'redirect_loop', __( 'Redirect loop', 'linksolva-broken-link-checker' ), __( 'The redirect chain returned to a URL that was already visited.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $next, ++$redirect_count );
			}
			$visited[ $next_hash ] = true;
			$current               = $next;
			++$redirect_count;
		}

		$code = $final_response ? absint( $final_response['code'] ) : 0;
		if ( $final_response && $final_response['error'] ) {
			return self::network_result( $final_response['error_code'], $final_response['message'], $url, $redirects, $history, $total_time, $retry_count, $redirect_count, $max_retries );
		}
		if ( $code >= 200 && $code < 300 ) {
			if ( $redirect_count > 0 ) {
				return self::result( 'redirect', 'confirmed', $code, $url, 'redirect', self::status_name( $code ), __( 'The URL works, but it reaches a different destination through a redirect chain.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, $redirect_count );
			}
			return self::result( 'healthy', 'confirmed', $code, $url, '', self::status_name( $code ), __( 'The resource responded successfully.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, 0 );
		}
		if ( $code >= 300 && $code < 400 ) {
			return self::result( 'redirect', 'likely', $code, $url, 'redirect', self::status_name( $code ), __( 'The server returned a redirect that could not be fully followed.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, max( 1, $redirect_count ) );
		}
		if ( 404 === $code || 410 === $code ) {
			/* translators: %d: HTTP response status code, such as 404 or 410. */
			return self::result( 'broken', 'confirmed', $code, $url, 'http_' . $code, self::status_name( $code ), sprintf( __( 'The target returned definitive HTTP %d evidence. It is highly likely that the resource no longer exists.', 'linksolva-broken-link-checker' ), $code ), $redirects, $history, $total_time, 0, $current, $redirect_count );
		}
		if ( 429 === $code ) {
			$next_retry   = $retry_count + 1;
			$stored_retry = min( $max_retries, $next_retry );
			$delay        = $next_retry <= $max_retries ? min( 3600, 120 * max( 1, $next_retry ) ) : DAY_IN_SECONDS;
			return self::result( 'rate_limited', 'unverified', $code, $url, 'http_429', self::status_name( $code ), __( 'The remote server asked the scanner to slow down. This does not mean the link is broken.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, $stored_retry, $current, $redirect_count, time() + $delay );
		}
		if ( $code >= 500 && $code <= 599 ) {
			$next_retry   = $retry_count + 1;
			$stored_retry = min( $max_retries, $next_retry );
			$delay        = $next_retry <= $max_retries ? min( 3600, 120 * max( 1, $next_retry ) ) : DAY_IN_SECONDS;
			return self::result( 'temporary_error', 'unverified', $code, $url, 'http_' . $code, self::status_name( $code ), __( 'The remote server returned a temporary error. The scanner will leave this for a later recheck instead of calling it broken.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, $stored_retry, $current, $redirect_count, time() + $delay );
		}
		if ( in_array( $code, array( 401, 403, 405, 406, 407, 451 ), true ) ) {
			return self::result( 'blocked', 'unverified', $code, $url, 'http_' . $code, self::status_name( $code ), __( 'The remote website rejected automated verification. This does not necessarily mean the link is broken.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, $redirect_count );
		}
		return self::result( 'unverified', 'unverified', $code, $url, $code ? 'http_' . $code : 'no_response', self::status_name( $code ), __( 'The scanner could not obtain enough evidence to call this link broken.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, $redirect_count );
	}

	/**
	 * Make a single bounded request without following redirects.
	 *
	 * @param string $url      URL.
	 * @param string $method   HEAD or GET.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private static function request( $url, $method, $settings, $deadline = 0.0 ) {
		$start = microtime( true );
		$args  = self::request_args( $url, $method, $settings, $deadline );
		if ( $deadline > 0 && $args['timeout'] < 1 ) {
			return array( 'deferred' => true );
		}
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $home && $host && strtolower( $home ) === strtolower( $host ) ) {
			$response = wp_remote_request( $url, $args );
		} else {
			$response = wp_safe_remote_request( $url, $args );
		}
		$duration = microtime( true ) - $start;
		if ( is_wp_error( $response ) ) {
			return array(
				'response'   => array(),
				'code'       => 0,
				'message'    => self::safe_error_message( $response ),
				'error'      => true,
				'error_code' => self::error_code( $response ),
				'duration'   => $duration,
			);
		}
		return array(
			'response'   => $response,
			'code'       => (int) wp_remote_retrieve_response_code( $response ),
			'message'    => sanitize_text_field( wp_remote_retrieve_response_message( $response ) ),
			'error'      => false,
			'error_code' => '',
			'duration'   => $duration,
		);
	}

	/**
	 * Build the common WordPress HTTP arguments for a bounded request.
	 *
	 * @param string $url      URL.
	 * @param string $method   HEAD or GET.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private static function request_args( $url, $method, $settings, $deadline = 0.0 ) {
		$timeout = absint( $settings['timeout'] );
		if ( $deadline > 0 ) {
			$remaining = $deadline - microtime( true );
			$timeout   = $remaining >= 2 ? min( $timeout, (int) floor( $remaining ) - 1 ) : 0;
		}
		$args = array(
			'method'              => $method,
			'timeout'             => $timeout,
			'connect_timeout'     => $timeout,
			'redirection'         => 0,
			'limit_response_size' => absint( $settings['max_body_bytes'] ),
			'sslverify'           => true,
			'sslcertificates'     => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
			'reject_unsafe_urls'  => true,
			'blocking'            => true,
			'body'                => '',
			'user-agent'          => 'Mozilla/5.0 (compatible; LinkSolva - Broken Link Checker/' . LINKSOLVA_BLC_VERSION . '; +' . home_url( '/' ) . ')',
			'headers'             => array(
				'Accept'     => '*/*',
				'Connection' => 'close',
			),
		);
		if ( 'GET' === $method ) {
			$args['headers']['Range'] = 'bytes=0-' . max( 0, absint( $settings['max_body_bytes'] ) - 1 );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress HTTP request-arguments filter.
		$args = apply_filters( 'http_request_args', $args, $url );
		if ( $deadline > 0 ) {
			$remaining = $deadline - microtime( true );
			if ( $remaining < 2 ) {
				$args['timeout']         = 0;
				$args['connect_timeout'] = 0;
			} else {
				$budget                  = (int) floor( $remaining ) - 1;
				$args['timeout']         = min( max( 1, (int) $args['timeout'] ), $budget );
				$args['connect_timeout'] = min( max( 1, (int) $args['connect_timeout'] ), $args['timeout'] );
			}
		}
		return $args;
	}

	/**
	 * Record a known redirect without starting another request after the worker deadline.
	 *
	 * @param string $url           Original URL.
	 * @param int    $code          Redirect response code.
	 * @param string $current       Current URL.
	 * @param string $next          Redirect destination.
	 * @param array  $redirects     Redirects already recorded.
	 * @param array  $history       Request history.
	 * @param float  $total_time    Total request time.
	 * @param int    $redirect_count Redirect count.
	 * @return array
	 */
	private static function deferred_redirect( $url, $code, $current, $next, $redirects, $history, $total_time, $redirect_count ) {
		$redirects[] = array(
			'from'   => $current,
			'status' => $code,
			'to'     => $next,
		);
		return self::result( 'redirect', 'likely', $code, $url, 'redirect_deferred', self::status_name( $code ), __( 'The redirect was recorded, but the next destination was not requested because this worker reached its time limit.', 'linksolva-broken-link-checker' ), $redirects, $history, $total_time, 0, $next, $redirect_count + 1 );
	}

	/**
	 * Translate WordPress HTTP arguments into Requests options.
	 *
	 * @param string   $url      URL.
	 * @param array    $args     WordPress HTTP arguments.
	 * @param callable $complete Completion callback.
	 * @return array
	 */
	private static function requests_options( $url, $args, $complete ) {
		$options = array(
			'timeout'          => (float) $args['timeout'],
			'connect_timeout'  => (float) $args['connect_timeout'],
			'useragent'        => (string) $args['user-agent'],
			'blocking'         => true,
			'follow_redirects' => false,
			'redirects'        => 0,
			'max_bytes'        => absint( $args['limit_response_size'] ),
			'verify'           => ! empty( $args['sslverify'] ) ? $args['sslcertificates'] : false,
			'verifyname'       => ! empty( $args['sslverify'] ),
			'hooks'            => new \WP_HTTP_Requests_Hooks( $url, $args ),
			'complete'         => $complete,
		);
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress SSL verification filter.
		$options['verify'] = apply_filters( 'https_ssl_verify', $options['verify'], $url );
		$proxy             = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			$options['proxy'] = new \WpOrg\Requests\Proxy\Http( $proxy->host() . ':' . $proxy->port() );
			if ( $proxy->use_authentication() ) {
				$options['proxy']->use_authentication = true;
				$options['proxy']->user               = $proxy->username();
				$options['proxy']->pass               = $proxy->password();
			}
		}
		return $options;
	}

	/**
	 * Convert one Requests response into the internal response shape.
	 *
	 * @param mixed $response Response or exception.
	 * @param float $duration Elapsed request time.
	 * @return array
	 */
	private static function parallel_response( $response, $duration ) {
		if ( $response instanceof \WpOrg\Requests\Response ) {
			$wp_response = new \WP_HTTP_Requests_Response( $response );
			$array       = $wp_response->to_array();
			return array(
				'response'   => $array,
				'code'       => (int) $response->status_code,
				'message'    => sanitize_text_field( get_status_header_desc( (int) $response->status_code ) ),
				'error'      => false,
				'error_code' => '',
				'duration'   => (float) $duration,
			);
		}
		$message = is_object( $response ) && method_exists( $response, 'getMessage' ) ? $response->getMessage() : __( 'The parallel request failed.', 'linksolva-broken-link-checker' );
		return array(
			'response'   => array(),
			'code'       => 0,
			'message'    => sanitize_text_field( $message ),
			'error'      => true,
			'error_code' => 'http_request_failed',
			'duration'   => (float) $duration,
		);
	}

	/**
	 * Turn a failed batch request into an unverified result without retrying it immediately.
	 *
	 * @param string $url         URL.
	 * @param int    $retry_count Previous retry count.
	 * @param float  $duration    Elapsed time.
	 * @param string $message     Transport error message.
	 * @param array  $settings    Plugin settings.
	 * @return array
	 */
	private static function parallel_failure( $url, $retry_count, $duration, $message, $settings ) {
		$failure = array(
			'code'       => 0,
			'message'    => sanitize_text_field( $message ),
			'error'      => true,
			'error_code' => 'http_request_failed',
			'duration'   => (float) $duration,
		);
		return self::network_result(
			$failure['error_code'],
			$failure['message'],
			$url,
			array(),
			array( self::history_item( 'HEAD', $url, $failure ) ),
			$duration,
			$retry_count,
			0,
			absint( $settings['max_retries'] )
		);
	}

	/**
	 * Make a compact request-history entry.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @param array  $item   Request result.
	 * @return array
	 */
	private static function history_item( $method, $url, $item ) {
		return array(
			'method'   => $method,
			'url'      => $url,
			'status'   => absint( $item['code'] ),
			'message'  => $item['error'] ? $item['error_code'] : $item['message'],
			'duration' => round( (float) $item['duration'], 3 ),
		);
	}

	/**
	 * Convert transport errors into user-safe categories.
	 *
	 * @param string $error_code Error code.
	 * @param string $message     Error message.
	 * @param string $url         URL.
	 * @param array  $redirects   Redirects.
	 * @param array  $history     History.
	 * @param float  $duration    Duration.
	 * @param int    $retry_count Retry count.
	 * @param int    $redirect_count Redirect count.
	 * @param int    $max_retries   Retry limit.
	 * @return array
	 */
	private static function network_result( $error_code, $message, $url, $redirects, $history, $duration, $retry_count, $redirect_count, $max_retries ) {
		$haystack     = strtolower( $error_code . ' ' . $message );
		$status       = 'unverified';
		$category     = 'network_error';
		$title        = __( 'Network error', 'linksolva-broken-link-checker' );
		$explanation  = __( 'The scanner could not complete a safe request. This is not enough evidence to call the link broken.', 'linksolva-broken-link-checker' );
		$next_retry   = $retry_count + 1;
		$stored_retry = min( absint( $max_retries ), $next_retry );
		$next         = time() + ( $next_retry <= absint( $max_retries ) ? min( 3600, 120 * max( 1, $next_retry ) ) : DAY_IN_SECONDS );
		if ( false !== strpos( $haystack, 'ssl' ) || false !== strpos( $haystack, 'certificate' ) ) {
			$status      = 'ssl_error';
			$category    = 'ssl_error';
			$title       = __( 'SSL error', 'linksolva-broken-link-checker' );
			$explanation = __( 'The HTTPS certificate or TLS negotiation could not be verified.', 'linksolva-broken-link-checker' );
		} elseif ( false !== strpos( $haystack, 'resolve' ) || false !== strpos( $haystack, 'dns' ) || false !== strpos( $haystack, 'host' ) ) {
			$status      = 'dns_error';
			$category    = 'dns_error';
			$title       = __( 'DNS error', 'linksolva-broken-link-checker' );
			$explanation = __( 'The domain could not be resolved from this server.', 'linksolva-broken-link-checker' );
		} elseif ( false !== strpos( $haystack, 'timed out' ) || false !== strpos( $haystack, 'timeout' ) || false !== strpos( $haystack, 'curl error 28' ) ) {
			$status      = 'timeout';
			$category    = 'timeout';
			$title       = __( 'Timeout', 'linksolva-broken-link-checker' );
			$explanation = __( 'The server did not respond within the time available for this request.', 'linksolva-broken-link-checker' );
		}
		return self::result( $status, 'unverified', 0, $url, $category, $title, $explanation, $redirects, $history, $duration, $stored_retry, $url, $redirect_count, $next );
	}

	/**
	 * Return a normalized result structure.
	 *
	 * @param string $status Status.
	 * @param string $confidence Confidence.
	 * @param int    $code HTTP code.
	 * @param string $url Original URL.
	 * @param string $error_code Error code.
	 * @param string $status_text Status text.
	 * @param string $explanation Explanation.
	 * @param array  $redirects Redirects.
	 * @param array  $history Request history.
	 * @param float  $duration Duration.
	 * @param int    $retry_count Retry count.
	 * @param string $final_url Final URL.
	 * @param int    $redirect_count Redirect count.
	 * @param int    $next_check_at Unix timestamp.
	 * @return array
	 */
	private static function result( $status, $confidence, $code, $url, $error_code, $status_text, $explanation, $redirects, $history, $duration, $retry_count, $final_url = '', $redirect_count = 0, $next_check_at = 0 ) {
		return array(
			'status'          => $status,
			'confidence'      => $confidence,
			'http_code'       => absint( $code ),
			'error_code'      => $error_code,
			'status_text'     => $status_text,
			'explanation'     => $explanation,
			'final_url'       => $final_url ? $final_url : $url,
			'redirect_chain'  => $redirects,
			'request_history' => $history,
			'response_time'   => round( (float) $duration, 3 ),
			'retry_count'     => absint( $retry_count ),
			'next_check_at'   => $next_check_at ? gmdate( 'Y-m-d H:i:s', $next_check_at ) : null,
			'redirect_count'  => absint( $redirect_count ),
		);
	}

	/**
	 * Get the safe WordPress error code.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function error_code( $error ) {
		$codes = $error->get_error_codes();
		return ! empty( $codes ) ? sanitize_key( reset( $codes ) ) : 'http_request_failed';
	}

	/**
	 * Reduce transport details to a non-sensitive message.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function safe_error_message( $error ) {
		$message = sanitize_text_field( $error->get_error_message() );
		return strlen( $message ) > 160 ? substr( $message, 0, 160 ) : $message;
	}

	/**
	 * Friendly HTTP status name.
	 *
	 * @param int $code HTTP code.
	 * @return string
	 */
	private static function status_name( $code ) {
		$names = array(
			200 => 'OK',
			201 => 'Created',
			204 => 'No Content',
			301 => 'Moved Permanently',
			302 => 'Found',
			303 => 'See Other',
			307 => 'Temporary Redirect',
			308 => 'Permanent Redirect',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			410 => 'Gone',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
		);
		return isset( $names[ $code ] ) ? $names[ $code ] : ( $code ? 'HTTP ' . absint( $code ) : __( 'No response', 'linksolva-broken-link-checker' ) );
	}
}
