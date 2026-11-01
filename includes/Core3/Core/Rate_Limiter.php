<?php
/**
 * Fixed-window rate limiter on transients (works without a persistent object cache).
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Keys are hashed so IPs/emails are never stored in clear text.
 */
class Rate_Limiter {

	/**
	 * Records a hit and reports whether it is allowed.
	 *
	 * @param string $bucket Bucket name (e.g. "parse").
	 * @param string $who    Identity (IP, user id, session).
	 * @param int    $limit  Max hits per window (0 = unlimited).
	 * @param int    $window Window seconds.
	 * @return bool
	 */
	public static function hit( $bucket, $who, $limit, $window = 60 ) {
		if ( $limit <= 0 ) {
			return true;
		}
		$slot  = (int) floor( time() / max( 1, $window ) );
		$key   = 'dev3sh_rl_' . substr( md5( $bucket . '|' . $who . '|' . $slot ), 0, 20 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, $window + 5 );
		return true;
	}

	/**
	 * Best-effort client IP (REMOTE_ADDR unless the site opts into a proxy header).
	 *
	 * @return string
	 */
	public static function client_ip() {
		/**
		 * Header to trust for the client IP behind a reverse proxy, e.g. HTTP_X_REAL_IP.
		 *
		 * @param string $header $_SERVER key or ''.
		 */
		$header = (string) apply_filters( 'dev3sh_trusted_ip_header', '' );
		$raw    = '' !== $header && isset( $_SERVER[ $header ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$ip     = trim( explode( ',', $raw )[0] );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}
