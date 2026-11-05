<?php
/**
 * SSRF and request-target validation.
 *
 * @package LinkSolvaBrokenLinkChecker
 */

namespace LinkSolva\BrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects unsafe network targets before the HTTP transport is invoked.
 */
final class Ssrf_Guard {
	/**
	 * Reserved address ranges that must never be requested.
	 *
	 * @var array
	 */
	private static $blocked_ranges = array(
		'0.0.0.0/8',
		'10.0.0.0/8',
		'100.64.0.0/10',
		'127.0.0.0/8',
		'169.254.0.0/16',
		'172.16.0.0/12',
		'192.0.0.0/24',
		'192.0.2.0/24',
		'192.168.0.0/16',
		'198.18.0.0/15',
		'198.51.100.0/24',
		'203.0.113.0/24',
		'224.0.0.0/4',
		'240.0.0.0/4',
		'::/128',
		'::1/128',
		'::ffff:0:0/96',
		'100::/64',
		'2001:db8::/32',
		'fc00::/7',
		'fe80::/10',
		'ff00::/8',
	);

	/**
	 * Validate a URL before any HTTP request.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	public static function validate( $url ) {
		$parts = wp_parse_url( $url );
		if ( false === $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return array(
				'safe' => false,
				'code' => 'invalid_url',
				'host' => '',
			);
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( trim( trim( $parts['host'], '[]' ), '.' ) );
		$port   = isset( $parts['port'] ) ? absint( $parts['port'] ) : 0;

		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array(
				'safe' => false,
				'code' => 'invalid_scheme',
				'host' => $host,
			);
		}
		$home_parts = wp_parse_url( home_url( '/' ) );
		$home_host  = ! empty( $home_parts['host'] ) ? strtolower( trim( trim( $home_parts['host'], '[]' ), '.' ) ) : '';
		$home_scheme = ! empty( $home_parts['scheme'] ) ? strtolower( $home_parts['scheme'] ) : '';
		$home_port   = isset( $home_parts['port'] ) ? absint( $home_parts['port'] ) : 0;
		$port        = $port ? $port : self::default_port( $scheme );
		$home_port   = $home_port ? $home_port : self::default_port( $home_scheme );
		$is_home     = $home_host && $host === $home_host && $scheme === $home_scheme && $port === $home_port;

		if ( $port && ! in_array( $port, array( 80, 443 ), true ) && ! $is_home ) {
			return array(
				'safe' => false,
				'code' => 'blocked_port',
				'host' => $host,
			);
		}

		if ( self::is_forbidden_hostname( $host ) && ! $is_home ) {
			return array(
				'safe' => false,
				'code' => 'blocked_host',
				'host' => $host,
			);
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( self::is_blocked_ip( $host ) && ! $is_home ) {
				return array(
					'safe' => false,
					'code' => 'blocked_address',
					'host' => $host,
				);
			}
			return array(
				'safe' => true,
				'code' => '',
				'host' => $host,
			);
		}

		$addresses = self::resolve( $host );
		if ( empty( $addresses ) ) {
			return array(
				'safe' => false,
				'code' => 'dns_error',
				'host' => $host,
			);
		}
		if ( ! $is_home && function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
			return array(
				'safe' => false,
				'code' => 'invalid_url',
				'host' => $host,
			);
		}
		if ( ! $is_home ) {
			foreach ( $addresses as $address ) {
				if ( self::is_blocked_ip( $address ) ) {
					return array(
						'safe' => false,
						'code' => 'blocked_address',
						'host' => $host,
					);
				}
			}
		}
		return array(
			'safe' => true,
			'code' => '',
			'host' => $host,
		);
	}

	/**
	 * Reject names commonly used for internal services.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	private static function is_forbidden_hostname( $host ) {
		return in_array( $host, array( 'localhost', 'localhost.localdomain', 'ip6-localhost', 'metadata.google.internal' ), true )
			|| (bool) preg_match( '/\.(local|internal|intranet|lan)$/i', $host );
	}

	/**
	 * Resolve all A and AAAA records available to PHP.
	 *
	 * @param string $host Host name.
	 * @return array
	 */
	private static function resolve( $host ) {
		$addresses = array();
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_A' ) && defined( 'DNS_AAAA' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS lookup failure is an expected unverified result and is handled below.
			$records = @dns_get_record( $host, DNS_A | DNS_AAAA );
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$addresses[] = $record['ip'];
					}
					if ( ! empty( $record['ipv6'] ) ) {
						$addresses[] = $record['ipv6'];
					}
				}
			}
		}
		if ( empty( $addresses ) && function_exists( 'gethostbynamel' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS lookup failure is an expected unverified result and is handled below.
			$resolved = @gethostbynamel( $host );
			if ( is_array( $resolved ) ) {
				$addresses = $resolved;
			}
		}
		return array_values( array_unique( $addresses ) );
	}

	/**
	 * Return the default port for a supported scheme.
	 *
	 * @param string $scheme URL scheme.
	 * @return int
	 */
	private static function default_port( $scheme ) {
		return 'https' === $scheme ? 443 : ( 'http' === $scheme ? 80 : 0 );
	}

	/**
	 * Test a literal address against the blocked ranges.
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_blocked_ip( $ip ) {
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return true;
		}
		foreach ( self::$blocked_ranges as $cidr ) {
			list( $network, $prefix ) = explode( '/', $cidr, 2 );
			$network_packed           = inet_pton( $network );
			if ( false === $network_packed || strlen( $packed ) !== strlen( $network_packed ) ) {
				continue;
			}
			$prefix = absint( $prefix );
			$whole  = intdiv( $prefix, 8 );
			$bits   = $prefix % 8;
			if ( $whole && 0 !== strncmp( $packed, $network_packed, $whole ) ) {
				continue;
			}
			if ( 0 === $bits || ( ord( $packed[ $whole ] ) & ( 0xFF << ( 8 - $bits ) ) ) === ( ord( $network_packed[ $whole ] ) & ( 0xFF << ( 8 - $bits ) ) ) ) {
				return true;
			}
		}
		return false;
	}
}
