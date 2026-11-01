<?php
/**
 * Enforces fixes the admin confirmed: IP blocklist and "XML-RPC disabled". Inactive by default.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Fix;

use Dev3\AiSiteHealth\Core3\Core\Rate_Limiter;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Blocklist.
 */
class Firewall {

	const OPTION = 'dev3sh_blocklist';

	/**
	 * Registers enforcement (runs early, on plugin load).
	 */
	public static function register() {
		if ( Settings_Store::get( 'xmlrpc_disabled', false ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', '__return_empty_array', 999 );
		}
		if ( self::all() ) {
			add_action( 'init', array( __CLASS__, 'enforce' ), 0 );
		}
	}

	/**
	 * Sends 403 to blocked IPs.
	 */
	public static function enforce() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			return;
		}
		if ( self::is_blocked( Rate_Limiter::client_ip() ) ) {
			status_header( 403 );
			nocache_headers();
			// Neutral, unbranded text for visitors.
			wp_die( esc_html__( 'Access denied.', 'dev3-ai-site-health-analyst' ), esc_html__( 'Forbidden', 'dev3-ai-site-health-analyst' ), array( 'response' => 403 ) );
		}
	}

	/**
	 * Blocklist (expired entries removed).
	 *
	 * @return array ip => { added, expires, by, reason }
	 */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		$now  = time();
		$live = array_filter(
			$list,
			static function ( $e ) use ( $now ) {
				return empty( $e['expires'] ) || (int) $e['expires'] > $now;
			}
		);
		if ( count( $live ) !== count( $list ) ) {
			update_option( self::OPTION, $live, true );
		}
		return $live;
	}

	/**
	 * Is blocked.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public static function is_blocked( $ip ) {
		$list = self::all();
		return isset( $list[ (string) $ip ] );
	}

	/**
	 * Adds an IP.
	 *
	 * @param string $ip     IP.
	 * @param string $reason Reason.
	 */
	public static function block( $ip, $reason = '' ) {
		$list        = self::all();
		$list[ $ip ] = array(
			'added'   => time(),
			'expires' => time() + max( 1, (int) Settings_Store::get( 'block_days', 30 ) ) * DAY_IN_SECONDS,
			'by'      => get_current_user_id(),
			'reason'  => mb_substr( (string) $reason, 0, 200 ),
		);
		update_option( self::OPTION, $list, true );
	}

	/**
	 * Removes an IP.
	 *
	 * @param string $ip IP.
	 * @return bool Was listed.
	 */
	public static function unblock( $ip ) {
		$list = self::all();
		if ( ! isset( $list[ $ip ] ) ) {
			return false;
		}
		unset( $list[ $ip ] );
		update_option( self::OPTION, $list, true );
		return true;
	}

	/**
	 * List for the admin.
	 *
	 * @return array[]
	 */
	public static function rows() {
		$out = array();
		foreach ( self::all() as $ip => $e ) {
			$out[] = array(
				'ip'      => (string) $ip,
				'added'   => gmdate( 'c', (int) $e['added'] ),
				'expires' => ! empty( $e['expires'] ) ? gmdate( 'c', (int) $e['expires'] ) : '',
				'reason'  => (string) $e['reason'],
			);
		}
		return $out;
	}
}
