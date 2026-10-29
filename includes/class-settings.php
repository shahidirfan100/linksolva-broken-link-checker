<?php
/**
 * Settings storage and sanitization.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Stores defaults and sanitizes administrator-controlled settings.
 */
final class Settings {
	const OPTION = 'sblc_settings';

	/**
	 * Return defaults for a new installation.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'batch_size'               => 5,
			'parallel_requests'        => 3,
			'execution_seconds'        => 8,
			'timeout'                  => 12,
			'max_redirects'            => 8,
			'max_retries'              => 2,
			'max_body_bytes'           => 4096,
			'include_comments'         => false,
			'include_menus'            => true,
			'include_custom_fields'    => false,
			'include_featured_images'  => true,
			'check_internal_links'     => true,
			'check_external_links'     => true,
			'post_types'               => array(),
			'custom_meta_keys'         => array(),
			'schedule'                 => 'manual',
			'email_notifications'      => false,
			'notification_email'       => '',
			'delete_data_on_uninstall' => false,
			'excluded_domains'         => array(),
			'excluded_url_fragments'   => array(),
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function all() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = wp_parse_args( $stored, self::defaults() );
		$settings['batch_size']        = min( 10, max( 1, absint( $settings['batch_size'] ) ) );
		$settings['parallel_requests'] = min( 4, max( 1, absint( $settings['parallel_requests'] ) ) );
		if ( ! array_key_exists( 'schedule', $stored ) && ! empty( $stored['schedule_enabled'] ) ) {
			$settings['schedule'] = 'daily';
		}
		return $settings;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Save sanitized settings.
	 *
	 * @param array $input Settings input.
	 * @return array
	 */
	public static function save( $input ) {
		$previous  = self::all();
		$sanitized = self::sanitize( $input );
		update_option( self::OPTION, $sanitized, false );
		if ( 'manual' === $sanitized['schedule'] ) {
			delete_option( 'sblc_next_scan_at' );
		} elseif ( $previous['schedule'] !== $sanitized['schedule'] || ! get_option( 'sblc_next_scan_at' ) ) {
			update_option( 'sblc_next_scan_at', time() + self::schedule_interval( $sanitized['schedule'] ), false );
		}
		return $sanitized;
	}

	/**
	 * Sanitize settings exposed through REST.
	 *
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$output   = self::all();

		$integer_keys = array( 'batch_size', 'parallel_requests', 'execution_seconds', 'timeout', 'max_redirects', 'max_retries', 'max_body_bytes' );
		foreach ( $integer_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = absint( $input[ $key ] );
			}
		}

		$output['batch_size']        = min( 10, max( 1, (int) $output['batch_size'] ) );
		$output['parallel_requests'] = min( 4, max( 1, (int) $output['parallel_requests'] ) );
		$output['execution_seconds'] = min( 20, max( 3, (int) $output['execution_seconds'] ) );
		$output['timeout']           = min( 60, max( 3, (int) $output['timeout'] ) );
		$output['max_redirects']     = min( 15, max( 0, (int) $output['max_redirects'] ) );
		$output['max_retries']       = min( 4, max( 0, (int) $output['max_retries'] ) );
		$output['max_body_bytes']    = min( 65536, max( 1024, (int) $output['max_body_bytes'] ) );

		$boolean_keys = array( 'include_comments', 'include_menus', 'include_custom_fields', 'include_featured_images', 'check_internal_links', 'check_external_links', 'email_notifications', 'delete_data_on_uninstall' );
		foreach ( $boolean_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = ! empty( $input[ $key ] );
			}
		}

		$array_keys = array( 'post_types', 'custom_meta_keys', 'excluded_domains', 'excluded_url_fragments' );
		foreach ( $array_keys as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$values         = is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$values         = array_map( 'sanitize_text_field', wp_unslash( $values ) );
			$values         = array_filter( array_map( 'trim', $values ) );
			$output[ $key ] = array_values( array_unique( $values ) );
		}

		if ( array_key_exists( 'notification_email', $input ) ) {
			$output['notification_email'] = sanitize_email( $input['notification_email'] );
		}

		if ( array_key_exists( 'schedule', $input ) ) {
			$schedule           = sanitize_key( $input['schedule'] );
			$output['schedule'] = in_array( $schedule, array( 'manual', 'daily', 'weekly' ), true ) ? $schedule : 'manual';
		}

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $output ) ) {
				$output[ $key ] = $default;
			}
		}
		unset( $output['schedule_enabled'] );

		return $output;
	}

	/**
	 * Return scan limits adjusted to the PHP process resources available now.
	 *
	 * PHP does not expose a reliable per-account CPU quota on shared hosting.
	 * Worker duration follows the PHP execution limit, while batch size and
	 * parallel requests are reduced when the current process has little memory
	 * headroom.
	 *
	 * @return array
	 */
	public static function worker_limits() {
		$settings = self::all();
		$limits   = array(
			'batch_size'        => min( 10, max( 1, absint( $settings['batch_size'] ) ) ),
			'parallel_requests' => min( 4, max( 1, absint( $settings['parallel_requests'] ) ) ),
			'execution_seconds' => max( 1, absint( $settings['execution_seconds'] ) ),
		);

		$max_execution_time = absint( ini_get( 'max_execution_time' ) );
		if ( $max_execution_time > 0 ) {
			$limits['execution_seconds'] = min( $limits['execution_seconds'], max( 1, $max_execution_time - 3 ) );
		}

		$memory_limit = self::memory_limit_bytes( ini_get( 'memory_limit' ) );
		if ( $memory_limit > 0 && function_exists( 'memory_get_usage' ) ) {
			$memory_reserve = max( 16 * 1024 * 1024, (int) floor( $memory_limit * 0.2 ) );
			$memory_headroom = max( 0, $memory_limit - memory_get_usage( true ) - $memory_reserve );
			$memory_slots    = max( 1, (int) floor( $memory_headroom / ( 16 * 1024 * 1024 ) ) );
			$limits['parallel_requests'] = min( $limits['parallel_requests'], $memory_slots );

			if ( $memory_headroom < 32 * 1024 * 1024 ) {
				$limits['batch_size'] = min( $limits['batch_size'], 2 );
			} elseif ( $memory_headroom < 64 * 1024 * 1024 ) {
				$limits['batch_size'] = min( $limits['batch_size'], 4 );
			} elseif ( $memory_headroom < 128 * 1024 * 1024 ) {
				$limits['batch_size'] = min( $limits['batch_size'], 7 );
			}
		}

		return $limits;
	}

	/**
	 * Convert a PHP memory limit to bytes.
	 *
	 * @param string|false $limit PHP memory limit.
	 * @return int Zero when the limit is unlimited or cannot be parsed.
	 */
	private static function memory_limit_bytes( $limit ) {
		$limit = trim( (string) $limit );
		if ( '' === $limit || '-1' === $limit ) {
			return 0;
		}

		$value = (float) $limit;
		$unit  = strtolower( substr( $limit, -1 ) );
		if ( 'k' === $unit ) {
			$value *= 1024;
		} elseif ( 'm' === $unit ) {
			$value *= 1024 * 1024;
		} elseif ( 'g' === $unit ) {
			$value *= 1024 * 1024 * 1024;
		}

		return max( 0, (int) $value );
	}

	/**
	 * Return the safe delay before the next scheduled scan.
	 *
	 * @param string $schedule Schedule key.
	 * @return int
	 */
	public static function schedule_interval( $schedule ) {
		return 'weekly' === $schedule ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
	}

	/**
	 * Get post types selected for scanning.
	 *
	 * @return array
	 */
	public static function post_types() {
		$selected = self::get( 'post_types', array() );
		$public   = get_post_types( array( 'public' => true ), 'names' );
		$public   = array_values( array_diff( $public, array( 'attachment', 'nav_menu_item' ) ) );

		if ( empty( $selected ) ) {
			return $public;
		}

		return array_values( array_intersect( $selected, $public ) );
	}
}
