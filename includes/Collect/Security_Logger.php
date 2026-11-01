<?php
/**
 * Lightweight security logger: failed/successful logins and suspicious requests.
 *
 * Only matching requests write to the database (capped per IP per minute); normal traffic costs a few
 * regex checks on the request line.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Core3\Core\Capabilities;
use Dev3\AiSiteHealth\Core3\Core\Rate_Limiter;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Report\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks + request rules.
 */
class Security_Logger {

	/**
	 * Registers hooks.
	 */
	public static function register() {
		if ( ! Settings_Store::get( 'security_logger', true ) ) {
			return;
		}
		add_action( 'wp_login_failed', array( __CLASS__, 'login_failed' ), 10, 2 );
		add_action( 'wp_login', array( __CLASS__, 'login_ok' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'inspect_request' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'track_404' ), 99 );
	}

	/**
	 * Rule labels.
	 *
	 * @return array
	 */
	public static function rules() {
		return array(
			'traversal'      => array( __( 'Path traversal probes', 'dev3-ai-site-health-analyst' ), 'high' ),
			'system_file'    => array( __( 'System file probes (/etc/passwd)', 'dev3-ai-site-health-analyst' ), 'high' ),
			'sqli'           => array( __( 'SQL injection attempts', 'dev3-ai-site-health-analyst' ), 'high' ),
			'xss'            => array( __( 'Script injection attempts', 'dev3-ai-site-health-analyst' ), 'medium' ),
			'sensitive_file' => array( __( 'Probes for sensitive files (.env, backups, .git)', 'dev3-ai-site-health-analyst' ), 'medium' ),
			'author_enum'    => array( __( 'User enumeration (?author=)', 'dev3-ai-site-health-analyst' ), 'low' ),
			'rest_user_enum' => array( __( 'User enumeration (REST users endpoint)', 'dev3-ai-site-health-analyst' ), 'low' ),
			'not_found'      => array( __( '404 floods (scanners)', 'dev3-ai-site-health-analyst' ), 'medium' ),
		);
	}

	/**
	 * Matches a request against the rules.
	 *
	 * @param string $uri       Raw request URI.
	 * @param bool   $logged_in Visitor is logged in.
	 * @return string Rule id or ''.
	 */
	public static function match( $uri, $logged_in = false ) {
		$raw = (string) $uri;
		$dec = strtolower( rawurldecode( rawurldecode( $raw ) ) );
		if ( preg_match( '#(?:\.\.[/\\\\]|[/\\\\]\.\.(?:$|[/\\\\?]))#', $dec ) ) {
			return preg_match( '#/etc/passwd|/proc/self/environ|win\.ini|boot\.ini#', $dec ) ? 'system_file' : 'traversal';
		}
		if ( preg_match( '#/etc/passwd|/proc/self/environ|c:\\\\windows\\\\win\.ini#', $dec ) ) {
			return 'system_file';
		}
		if ( preg_match( '/\bunion(?:\s|\+|\/\*.*?\*\/)+(?:all(?:\s|\+)+)?select\b|information_schema|\bsleep\s*\(\s*\d|\bbenchmark\s*\(|\'\s*or\s*\'?1\'?\s*=\s*\'?1|\bor\s+1\s*=\s*1\b|\bwaitfor\s+delay\b/i', $dec ) ) {
			return 'sqli';
		}
		if ( preg_match( '/<\s*script\b|javascript:|\bon(?:error|load|mouseover)\s*=|<\s*svg[^>]*onload/i', $dec ) ) {
			return 'xss';
		}
		$path = (string) wp_parse_url( $dec, PHP_URL_PATH );
		if ( preg_match( '#(?:wp-config\.php(?:\.|~|_)[a-z0-9]*|/\.env(?:\.[a-z]+)?$|/\.git/|/\.svn/|/\.aws/|/\.ssh/|/\.ds_store$|phpinfo\.php$|/vendor/phpunit/|/(?:backup|db|dump|database|site)\.(?:sql|zip|tar|tar\.gz|gz)$|\.sql(?:\.gz)?$|/phpmyadmin|/adminer\.php$|/xmlrpc\.php\.bak)#', $path ) ) {
			return 'sensitive_file';
		}
		if ( ! $logged_in ) {
			$query = (string) wp_parse_url( $dec, PHP_URL_QUERY );
			if ( preg_match( '/(?:^|&)author=\d+/', $query ) ) {
				return 'author_enum';
			}
			if ( preg_match( '#/wp-json/wp/v2/users|rest_route=/wp/v2/users#', $dec ) ) {
				return 'rest_user_enum';
			}
		}
		return '';
	}

	/**
	 * Checks the current request (init, priority 1).
	 */
	public static function inspect_request() {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || Capabilities::can_manage() ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- matched against patterns only; stored sanitized.
		$rule = self::match( $uri, is_user_logged_in() );
		if ( '' !== $rule ) {
			self::log_suspicious( $rule, $uri );
		}
	}

	/**
	 * Logs a suspicious request.
	 *
	 * @param string $rule Rule.
	 * @param string $uri  URI.
	 * @param string $ip   IP (defaults to the client).
	 * @return int Event id (0 when capped).
	 */
	public static function log_suspicious( $rule, $uri, $ip = '' ) {
		$ip = '' !== $ip ? $ip : Rate_Limiter::client_ip();
		if ( ! Rate_Limiter::hit( 'sec_write', $ip, (int) apply_filters( 'dev3sh_security_writes_per_minute', 30 ) ) ) {
			return 0;
		}
		$rules = self::rules();
		$label = isset( $rules[ $rule ] ) ? $rules[ $rule ] : array( $rule, 'low' );
		$path  = mb_substr( sanitize_text_field( $uri ), 0, 255 );
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '';
		$id    = Events::add(
			'not_found' === $rule ? 'not_found_flood' : 'suspicious',
			array(
				'rule'     => $rule,
				'severity' => $label[1],
				'ip'       => $ip,
				'user_id'  => get_current_user_id(),
				'path'     => $path,
				'message'  => $label[0],
				'meta'     => array(
					'ua'     => $ua,
					'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
				),
			)
		);
		Issues::record(
			array(
				'source'      => 'security',
				'kind'        => 'suspicious_' . $rule,
				'fingerprint' => 'suspicious:' . $rule,
				'severity'    => $label[1],
				/* translators: %s: rule label. */
				'title'       => sprintf( __( 'Suspicious requests: %s', 'dev3-ai-site-health-analyst' ), $label[0] ),
				'message'     => $label[0],
				'sample'      => $path,
			)
		);
		Alerts::check_security();
		return $id;
	}

	/**
	 * 404 flood tracking (template_redirect).
	 */
	public static function track_404() {
		if ( ! is_404() || Capabilities::can_manage() ) {
			return;
		}
		$ip        = Rate_Limiter::client_ip();
		$threshold = max( 5, (int) Settings_Store::get( 'not_found_threshold', 20 ) );
		$slot      = (int) floor( time() / 300 );
		$key       = 'dev3sh_404_' . substr( md5( $ip . '|' . $slot ), 0, 20 );
		$count     = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, 330 );
		if ( $count === $threshold ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			self::log_suspicious( 'not_found', $uri, $ip );
		}
	}

	/**
	 * Failed login.
	 *
	 * @param string         $username User name.
	 * @param \WP_Error|null $error    Error.
	 */
	public static function login_failed( $username, $error = null ) {
		$ip = Rate_Limiter::client_ip();
		if ( ! Rate_Limiter::hit( 'login_write', $ip, (int) apply_filters( 'dev3sh_security_writes_per_minute', 30 ) ) ) {
			return;
		}
		$code    = is_wp_error( $error ) ? $error->get_error_code() : '';
		$channel = defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ? 'xmlrpc' : ( defined( 'REST_REQUEST' ) && REST_REQUEST ? 'rest' : 'login' );
		$user    = get_user_by( 'login', $username );
		if ( ! $user && is_email( $username ) ) {
			$user = get_user_by( 'email', $username );
		}
		Events::add(
			'login_failed',
			array(
				'rule'       => $channel,
				'severity'   => 'medium',
				'ip'         => $ip,
				'user_login' => sanitize_user( (string) $username ),
				'user_id'    => $user ? (int) $user->ID : 0,
				'path'       => 'xmlrpc' === $channel ? '/xmlrpc.php' : '/wp-login.php',
				'message'    => $user ? __( 'Wrong password for an existing user', 'dev3-ai-site-health-analyst' ) : __( 'Unknown user name', 'dev3-ai-site-health-analyst' ),
				'meta'       => array(
					'code'     => sanitize_key( $code ),
					'exists'   => (bool) $user,
					'is_admin' => $user ? user_can( $user, 'manage_options' ) : false,
				),
			)
		);
		Issues::record(
			array(
				'source'      => 'security',
				'kind'        => 'login_failed',
				'fingerprint' => 'login_failed',
				'severity'    => 'low',
				'title'       => __( 'Failed login attempts', 'dev3-ai-site-health-analyst' ),
				'message'     => __( 'Someone tried to log in with a wrong user name or password.', 'dev3-ai-site-health-analyst' ),
			)
		);
		$threshold = max( 3, (int) Settings_Store::get( 'brute_force_threshold', 10 ) );
		$recent    = Events::count( 'login_failed', time() - HOUR_IN_SECONDS, $ip );
		if ( $recent >= $threshold ) {
			Issues::record(
				array(
					'source'      => 'security',
					'kind'        => 'brute_force',
					'fingerprint' => 'brute_force',
					'severity'    => 'high',
					'title'       => __( 'Brute-force login attack', 'dev3-ai-site-health-analyst' ),
					/* translators: %d: threshold. */
					'message'     => sprintf( __( 'One address failed to log in %d or more times within an hour.', 'dev3-ai-site-health-analyst' ), $threshold ),
					'details'     => array(
						'ip'       => $ip,
						'attempts' => $recent,
					),
				)
			);
		}
		Alerts::check_security();
	}

	/**
	 * Successful login: logged after recent failures from the same IP, and for administrators.
	 *
	 * @param string   $login User login.
	 * @param \WP_User $user  User.
	 */
	public static function login_ok( $login, $user = null ) {
		$ip       = Rate_Limiter::client_ip();
		$failures = Events::count( 'login_failed', time() - HOUR_IN_SECONDS, $ip );
		$admin    = $user instanceof \WP_User && user_can( $user, 'manage_options' );
		if ( 0 === $failures && ! ( $admin && Settings_Store::get( 'log_admin_logins', true ) ) ) {
			return;
		}
		Events::add(
			'login_ok',
			array(
				'rule'       => $failures ? 'after_failures' : 'admin_login',
				'severity'   => $failures >= 3 ? 'medium' : 'info',
				'ip'         => $ip,
				'user_login' => sanitize_user( (string) $login ),
				'user_id'    => $user instanceof \WP_User ? (int) $user->ID : 0,
				'path'       => '/wp-login.php',
				'message'    => $failures
					/* translators: %d: failed attempts. */
					? sprintf( _n( 'Logged in after %d failed attempt', 'Logged in after %d failed attempts', $failures, 'dev3-ai-site-health-analyst' ), $failures )
					: __( 'Administrator logged in', 'dev3-ai-site-health-analyst' ),
				'meta'       => array( 'failures' => $failures ),
			)
		);
	}

	/**
	 * Security KPIs.
	 *
	 * @return array
	 */
	public static function summary() {
		$day  = time() - DAY_IN_SECONDS;
		$week = time() - WEEK_IN_SECONDS;
		return array(
			'failed_24h'     => Events::count( 'login_failed', $day ),
			'failed_7d'      => Events::count( 'login_failed', $week ),
			'suspicious_24h' => Events::count( 'suspicious', $day ) + Events::count( 'not_found_flood', $day ),
			'suspicious_7d'  => Events::count( 'suspicious', $week ) + Events::count( 'not_found_flood', $week ),
			'admin_logins'   => Events::count( 'login_ok', $week ),
			'top_ips'        => Events::top( '', 'ip', $week, 8 ),
			'top_rules'      => Events::top( 'suspicious', 'rule', $week, 8 ),
			'top_users'      => Events::top( 'login_failed', 'user_login', $week, 5 ),
			'daily_failed'   => Events::daily( 'login_failed', 14 ),
			'daily_suspect'  => Events::daily( 'suspicious', 14 ),
			'rules'          => array_map(
				static function ( $r ) {
					return $r[0];
				},
				self::rules()
			),
		);
	}
}
