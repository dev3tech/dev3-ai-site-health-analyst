<?php
/**
 * Plugin-specific settings (shared AI settings live in Core3\Core\Settings_Store).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth;

use Dev3\AiSiteHealth\Collect\Php_Log;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked into dev3sh_settings_defaults / dev3sh_settings_sanitize.
 */
class Settings {

	const BOOLS = array( 'log_notices', 'log_deprecated', 'site_health_async', 'security_logger', 'anonymize_ip', 'log_admin_logins', 'slow_queries', 'alerts_enabled', 'alert_fatal', 'alert_critical', 'alert_integrity', 'weekly_report', 'report_ai_summary', 'allow_fixes', 'xmlrpc_disabled', 'dashboard_widget' );

	const INTS = array(
		'log_max_kb'            => array( 64, 20480 ),
		'not_found_threshold'   => array( 5, 500 ),
		'brute_force_threshold' => array( 3, 500 ),
		'retention_days'        => array( 1, 365 ),
		'slow_query_ms'         => array( 10, 60000 ),
		'stale_days'            => array( 0, 365 ),
		'alert_failed_logins'   => array( 0, 10000 ),
		'alert_suspicious'      => array( 0, 10000 ),
		'alert_score_below'     => array( 0, 100 ),
		'alert_cooldown_hours'  => array( 1, 168 ),
		'report_hour'           => array( 0, 23 ),
		'block_days'            => array( 1, 365 ),
	);

	const DAYS = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

	/**
	 * Adds plugin defaults.
	 *
	 * @param array $defaults Shared defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		return array_merge(
			$defaults,
			array(
				'collectors'            => array_fill_keys( Collect\Collector::SOURCES, true ),
				'log_path'              => '',
				'log_max_kb'            => 2048,
				'log_notices'           => true,
				'log_deprecated'        => true,
				'site_health_async'     => true,
				'security_logger'       => true,
				'anonymize_ip'          => false,
				'not_found_threshold'   => 20,
				'brute_force_threshold' => 10,
				'log_admin_logins'      => true,
				'retention_days'        => 30,
				'slow_queries'          => true,
				'slow_query_ms'         => 250,
				'integrity_schedule'    => 'weekly',
				'stale_days'            => 14,
				'alerts_enabled'        => true,
				'alert_emails'          => '',
				'alert_fatal'           => true,
				'alert_critical'        => true,
				'alert_integrity'       => true,
				'alert_failed_logins'   => 20,
				'alert_suspicious'      => 50,
				'alert_score_below'     => 0,
				'alert_cooldown_hours'  => 6,
				'weekly_report'         => true,
				'report_emails'         => '',
				'report_day'            => 'monday',
				'report_hour'           => 8,
				'report_ai_summary'     => true,
				'allow_fixes'           => false,
				'block_days'            => 30,
				'xmlrpc_disabled'       => false,
				'dashboard_widget'      => true,
			)
		);
	}

	/**
	 * Sanitizes plugin keys.
	 *
	 * @param array $out     Sanitized so far.
	 * @param array $input   Raw input.
	 * @param array $current Current.
	 * @return array
	 */
	public static function sanitize( $out, $input, $current ) {
		unset( $current );
		foreach ( self::BOOLS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}
		foreach ( self::INTS as $key => $range ) {
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $key ] = min( $range[1], max( $range[0], (int) $input[ $key ] ) );
			}
		}
		if ( isset( $input['collectors'] ) && is_array( $input['collectors'] ) ) {
			$c = array();
			foreach ( Collect\Collector::SOURCES as $source ) {
				$c[ $source ] = ! isset( $input['collectors'][ $source ] ) || (bool) $input['collectors'][ $source ];
			}
			$out['collectors'] = $c;
		}
		if ( isset( $input['log_path'] ) ) {
			$path = trim( sanitize_text_field( wp_unslash( (string) $input['log_path'] ) ) );
			if ( '' === $path || ! is_wp_error( Php_Log::validate_path( $path ) ) ) {
				$out['log_path'] = $path;
			}
		}
		if ( isset( $input['integrity_schedule'] ) && in_array( $input['integrity_schedule'], array( 'daily', 'weekly', 'off' ), true ) ) {
			$out['integrity_schedule'] = $input['integrity_schedule'];
		}
		if ( isset( $input['report_day'] ) && in_array( $input['report_day'], self::DAYS, true ) ) {
			$out['report_day'] = $input['report_day'];
		}
		foreach ( array( 'alert_emails', 'report_emails' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$emails      = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $input[ $key ] ) ), 'is_email' );
				$out[ $key ] = implode( ', ', array_slice( array_unique( $emails ), 0, 10 ) );
			}
		}
		return $out;
	}
}
