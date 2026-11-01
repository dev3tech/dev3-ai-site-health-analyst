<?php
/**
 * Email alerts when thresholds are crossed (with a cool-down per alert type).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Report;

use Dev3\AiSiteHealth\Analyze\Score;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts.
 */
class Alerts {

	const STATE = 'dev3sh_alert_state';

	/**
	 * Recipients (validated).
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$raw = (string) Settings_Store::get( 'alert_emails', '' );
		$raw = '' === trim( $raw ) ? (string) get_option( 'admin_email' ) : $raw;
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' ) );
	}

	/**
	 * Evaluates the issue-based alerts after a collection run.
	 *
	 * @param int[] $created   New issue ids.
	 * @param int[] $regressed Re-opened issue ids.
	 * @return string[] Alert types sent.
	 */
	public static function check_issues( array $created, array $regressed = array() ) {
		if ( ! Settings_Store::get( 'alerts_enabled', true ) ) {
			return array();
		}
		$sent  = array();
		$fresh = array();
		foreach ( array_unique( array_merge( $created, $regressed ) ) as $id ) {
			$i = Issues::get( $id );
			if ( $i && 'open' === $i['status'] && empty( $i['notified'] ) ) {
				$fresh[] = $i;
			}
		}
		$fatal    = array_values(
			array_filter(
				$fresh,
				static function ( $i ) {
					return 'php' === $i['source'] && in_array( $i['kind'], array( 'fatal', 'parse' ), true );
				}
			)
		);
		$critical = array_values(
			array_filter(
				$fresh,
				static function ( $i ) {
					return 'critical' === $i['severity'];
				}
			)
		);
		$core     = array_values(
			array_filter(
				$fresh,
				static function ( $i ) {
					return 'integrity' === $i['source'];
				}
			)
		);
		if ( $fatal && Settings_Store::get( 'alert_fatal', true ) && self::send( 'fatal', __( 'New fatal PHP error', 'dev3-ai-site-health-analyst' ), $fatal ) ) {
			$sent[] = 'fatal';
		}
		if ( $core && Settings_Store::get( 'alert_integrity', true ) && self::send( 'integrity', __( 'Files changed: integrity check failed', 'dev3-ai-site-health-analyst' ), $core ) ) {
			$sent[] = 'integrity';
		}
		$critical = array_values(
			array_filter(
				$critical,
				static function ( $i ) use ( $fatal, $core ) {
					return ! in_array( $i['id'], array_merge( wp_list_pluck( $fatal, 'id' ), wp_list_pluck( $core, 'id' ) ), true );
				}
			)
		);
		if ( $critical && Settings_Store::get( 'alert_critical', true ) && self::send( 'critical', __( 'New critical issue', 'dev3-ai-site-health-analyst' ), $critical ) ) {
			$sent[] = 'critical';
		}
		$min = (int) Settings_Store::get( 'alert_score_below', 0 );
		if ( $min > 0 ) {
			$score = Score::compute();
			if ( $score < $min && self::send(
				'score',
				/* translators: %d: score. */
				sprintf( __( 'Health score dropped to %d', 'dev3-ai-site-health-analyst' ), $score ),
				array(),
				/* translators: 1: score, 2: threshold. */
				sprintf( __( 'The health score is %1$d, below your alert threshold of %2$d.', 'dev3-ai-site-health-analyst' ), $score, $min )
			) ) {
				$sent[] = 'score';
			}
		}
		if ( $fresh ) {
			Issues::mark_notified( wp_list_pluck( $fresh, 'id' ) );
		}
		return $sent;
	}

	/**
	 * Security thresholds (called after each security event; cheap COUNT queries).
	 *
	 * @return string[] Alert types sent.
	 */
	public static function check_security() {
		if ( ! Settings_Store::get( 'alerts_enabled', true ) ) {
			return array();
		}
		$sent  = array();
		$hour  = time() - HOUR_IN_SECONDS;
		$fails = (int) Settings_Store::get( 'alert_failed_logins', 20 );
		if ( $fails > 0 && self::cooled( 'failed_logins' ) ) {
			$n = Events::count( 'login_failed', $hour );
			if ( $n >= $fails && self::send(
				'failed_logins',
				/* translators: %d: count. */
				sprintf( __( '%d failed logins in the last hour', 'dev3-ai-site-health-analyst' ), $n ),
				array(),
				self::security_body( 'login_failed', $n )
			) ) {
				$sent[] = 'failed_logins';
			}
		}
		$sus = (int) Settings_Store::get( 'alert_suspicious', 50 );
		if ( $sus > 0 && self::cooled( 'suspicious' ) ) {
			$n = Events::count( 'suspicious', $hour ) + Events::count( 'not_found_flood', $hour );
			if ( $n >= $sus && self::send(
				'suspicious',
				/* translators: %d: count. */
				sprintf( __( '%d suspicious requests in the last hour', 'dev3-ai-site-health-analyst' ), $n ),
				array(),
				self::security_body( 'suspicious', $n )
			) ) {
				$sent[] = 'suspicious';
			}
		}
		return $sent;
	}

	/**
	 * Whether a type is outside its cool-down.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function cooled( $type ) {
		$state = get_option( self::STATE, array() );
		$hours = max( 1, (int) Settings_Store::get( 'alert_cooldown_hours', 6 ) );
		return empty( $state[ $type ] ) || time() - (int) $state[ $type ] >= $hours * HOUR_IN_SECONDS;
	}

	/**
	 * Sends one alert (respecting the cool-down).
	 *
	 * @param string $type    Type.
	 * @param string $subject Subject (without site name).
	 * @param array  $issues  Issues to list.
	 * @param string $intro   Intro text.
	 * @return bool
	 */
	public static function send( $type, $subject, array $issues = array(), $intro = '' ) {
		$to = self::recipients();
		if ( ! $to || ! self::cooled( $type ) ) {
			return false;
		}
		$state          = get_option( self::STATE, array() );
		$state          = is_array( $state ) ? $state : array();
		$state[ $type ] = time();
		update_option( self::STATE, $state, false );

		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$rows  = '';
		$plain = '' !== $intro ? $intro . "\n\n" : '';
		foreach ( array_slice( $issues, 0, 10 ) as $i ) {
			$rows  .= '<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb"><strong>' . esc_html( $i['title'] ) . '</strong><br><span style="color:#4b5563;font-size:13px">' . esc_html( ucfirst( $i['severity'] ) . ' · ' . $i['source'] . ( $i['component_label'] ? ' · ' . $i['component_label'] : '' ) ) . '</span></td></tr>';
			$plain .= '- ' . $i['title'] . ' (' . $i['severity'] . ")\n";
		}
		$link   = admin_url( 'admin.php?page=' . DEV3SH_SLUG . '#/issues' );
		$html   = Weekly_Report::wrap(
			esc_html( $subject ),
			( '' !== $intro ? '<p style="margin:0 0 16px">' . esc_html( $intro ) . '</p>' : '' )
			. ( $rows ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>' : '' )
			. '<p style="margin:24px 0 0"><a href="' . esc_url( $link ) . '" style="background:#4f46e5;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">' . esc_html__( 'Open the issues', 'dev3-ai-site-health-analyst' ) . '</a></p>'
		);
		$plain .= "\n" . $link;
		/* translators: 1: site name, 2: subject. */
		$ok = Weekly_Report::mail( $to, sprintf( __( '[%1$s] Alert: %2$s', 'dev3-ai-site-health-analyst' ), $site, $subject ), $html, $plain );
		\Dev3\AiSiteHealth\Data\Events::add(
			'alert',
			array(
				'rule'     => $type,
				'severity' => 'info',
				'message'  => $subject,
				'meta'     => array(
					'sent'   => $ok,
					'issues' => wp_list_pluck( $issues, 'id' ),
				),
			)
		);
		return $ok;
	}

	/**
	 * Body text for security alerts.
	 *
	 * @param string $type Event type.
	 * @param int    $n    Count.
	 * @return string
	 */
	private static function security_body( $type, $n ) {
		$top = Events::top( $type, 'ip', time() - HOUR_IN_SECONDS, 3 );
		$ips = implode(
			', ',
			array_map(
				static function ( $t ) {
					return $t['value'] . ' (' . $t['count'] . ')';
				},
				$top
			)
		);
		/* translators: 1: count, 2: top addresses. */
		return sprintf( __( '%1$d events in the last hour. Top addresses: %2$s. You can block addresses on the Security screen (with confirmation).', 'dev3-ai-site-health-analyst' ), $n, '' !== $ips ? $ips : '-' );
	}
}
