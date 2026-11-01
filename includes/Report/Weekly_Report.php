<?php
/**
 * Weekly email report (HTML + plain text), optional DEV3 AI summary paragraph.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Report;

use Dev3\AiSiteHealth\Ai\Prompt_Library;
use Dev3\AiSiteHealth\Analyze\Redactor;
use Dev3\AiSiteHealth\Analyze\Score;
use Dev3\AiSiteHealth\Collect\Cron_Health;
use Dev3\AiSiteHealth\Collect\Integrity;
use Dev3\AiSiteHealth\Collect\Security_Logger;
use Dev3\AiSiteHealth\Core3\Api\Ai;
use Dev3\AiSiteHealth\Core3\Api\Prompts;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Report builder + sender.
 */
class Weekly_Report {

	const CRON = 'dev3sh_weekly_report';
	const LAST = 'dev3sh_report_last';

	/**
	 * Registers the cron handler.
	 */
	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
	}

	/**
	 * (Re)schedules the weekly event at the configured day/hour (site time).
	 */
	public static function schedule() {
		wp_clear_scheduled_hook( self::CRON );
		if ( ! Settings_Store::get( 'weekly_report', true ) ) {
			return;
		}
		wp_schedule_event( self::next_run(), 'weekly', self::CRON );
	}

	/**
	 * Next run timestamp.
	 *
	 * @return int
	 */
	public static function next_run() {
		$days = array( 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );
		$day  = (string) Settings_Store::get( 'report_day', 'monday' );
		$day  = in_array( $day, $days, true ) ? $day : 'monday';
		$hour = min( 23, max( 0, (int) Settings_Store::get( 'report_hour', 8 ) ) );
		try {
			$tz   = wp_timezone();
			$next = new \DateTimeImmutable( 'now', $tz );
			$cand = $next->modify( $day . ' this week' )->setTime( $hour, 0 );
			if ( $cand <= $next ) {
				$cand = $next->modify( 'next ' . $day )->setTime( $hour, 0 );
			}
			return $cand->getTimestamp();
		} catch ( \Exception $e ) {
			return time() + WEEK_IN_SECONDS;
		}
	}

	/**
	 * Cron: send.
	 */
	public static function cron() {
		if ( Settings_Store::get( 'weekly_report', true ) ) {
			self::send();
		}
	}

	/**
	 * Report data for the last 7 days.
	 *
	 * @param bool $with_ai Ask DEV3 AI for a summary paragraph.
	 * @return array
	 */
	public static function data( $with_ai = false ) {
		$since    = time() - WEEK_IN_SECONDS;
		$score    = Score::compute();
		$prev     = Score::week_ago();
		$counts   = Issues::counts();
		$changes  = Issues::since( $since );
		$top      = Issues::query(
			array(
				'status'   => 'open',
				'per_page' => 5,
			)
		);
		$security = Security_Logger::summary();
		$updates  = Issues::query(
			array(
				'status'   => 'open',
				'source'   => 'updates',
				'per_page' => 50,
			)
		);
		$integ    = Integrity::last();
		$cron     = Cron_Health::status();
		$data     = array(
			'site'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'        => home_url( '/' ),
			'period'     => array(
				'from' => wp_date( get_option( 'date_format' ), $since ),
				'to'   => wp_date( get_option( 'date_format' ) ),
			),
			'score'      => $score,
			'grade'      => Score::grade( $score ),
			'previous'   => $prev,
			'open'       => $counts,
			'new'        => array_slice( $changes['new'], 0, 10 ),
			'new_count'  => count( $changes['new'] ),
			'regressed'  => $changes['regressed'],
			'resolved'   => $changes['resolved'],
			'top'        => $top['items'],
			'security'   => array(
				'failed_logins' => $security['failed_7d'],
				'suspicious'    => $security['suspicious_7d'],
				'top_ips'       => array_map(
					static function ( $t ) {
						return array(
							'ip'    => Redactor::anonymize_ip( $t['value'] ),
							'count' => $t['count'],
						);
					},
					array_slice( $security['top_ips'], 0, 5 )
				),
			),
			'updates'    => wp_list_pluck( $updates['items'], 'title' ),
			'integrity'  => $integ ? $integ['summary'] + array( 'at' => gmdate( 'c', (int) $integ['at'] ) ) : null,
			'cron'       => array(
				'overdue' => $cron['overdue_count'],
				'orphans' => count( $cron['orphans'] ),
			),
			'fixes'      => Events::count( 'fix', $since ),
			'ai_summary' => '',
		);
		if ( $with_ai && Settings_Store::get( 'report_ai_summary', true ) && Ai::enabled( 'admin' ) && Settings_Store::api_key() ) {
			$data['ai_summary'] = self::ai_summary( $data );
		}
		return $data;
	}

	/**
	 * AI summary paragraph ('' on failure: the report never depends on AI).
	 *
	 * @param array $data Report data.
	 * @return string
	 */
	public static function ai_summary( array $data ) {
		$brief  = array(
			'score'          => $data['score'],
			'score_week_ago' => $data['previous'],
			'open_by_level'  => $data['open']['severity'],
			'new_issues'     => array_map(
				static function ( $i ) {
					return $i['severity'] . ': ' . $i['title'];
				},
				array_slice( $data['new'], 0, 8 )
			),
			'top_open'       => array_map(
				static function ( $i ) {
					return $i['severity'] . ': ' . $i['title'] . ' (' . $i['count'] . 'x)';
				},
				$data['top']
			),
			'resolved'       => $data['resolved'],
			'regressed'      => $data['regressed'],
			'failed_logins'  => $data['security']['failed_logins'],
			'suspicious'     => $data['security']['suspicious'],
			'updates'        => count( $data['updates'] ),
			'integrity'      => $data['integrity'],
			'cron'           => $data['cron'],
		);
		$result = Prompts::run(
			Prompt_Library::WEEKLY,
			array(
				'report'   => \Dev3\AiSiteHealth\Ai\Explainer::json( Redactor::mask_deep( $brief ) ),
				'language' => 0 === strpos( determine_locale(), 'en' ) ? 'English' : determine_locale(),
			),
			'cron'
		);
		if ( is_wp_error( $result ) ) {
			return '';
		}
		return mb_substr( trim( wp_strip_all_tags( (string) ( $result['data']['summary'] ?? '' ) ) ), 0, 900 );
	}

	/**
	 * Renders the HTML email.
	 *
	 * @param array $d Data.
	 * @return string
	 */
	public static function html( array $d ) {
		$colors = array(
			'good' => '#15803d',
			'fair' => '#b45309',
			'poor' => '#b91c1c',
		);
		$sev    = array(
			'critical' => '#b91c1c',
			'high'     => '#c2410c',
			'medium'   => '#b45309',
			'low'      => '#4b5563',
			'info'     => '#4b5563',
		);
		$trend  = '';
		if ( null !== $d['previous'] ) {
			$diff  = $d['score'] - (int) $d['previous'];
			$trend = 0 === $diff ? esc_html__( 'same as last week', 'dev3-ai-site-health-analyst' ) : sprintf(
				/* translators: %s: +/- points. */
				esc_html__( '%s points vs last week', 'dev3-ai-site-health-analyst' ),
				( $diff > 0 ? '+' : '' ) . $diff
			);
		}
		$b  = '';
		$b .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px"><tr>';
		$b .= '<td style="width:110px;vertical-align:middle"><div style="width:88px;height:88px;border-radius:44px;border:6px solid ' . esc_attr( $colors[ $d['grade'] ] ) . ';text-align:center;line-height:88px;font-size:30px;font-weight:700;color:#111827">' . (int) $d['score'] . '</div></td>';
		$b .= '<td style="vertical-align:middle"><div style="font-size:18px;font-weight:600;color:#111827">' . esc_html__( 'Health score', 'dev3-ai-site-health-analyst' ) . '</div><div style="color:#4b5563;font-size:14px">' . $trend . '</div>';
		/* translators: 1: open issues, 2: critical. */
		$b .= '<div style="color:#4b5563;font-size:14px;margin-top:4px">' . esc_html( sprintf( __( '%1$d open issues · %2$d critical', 'dev3-ai-site-health-analyst' ), $d['open']['total'], $d['open']['severity']['critical'] ) ) . '</div></td></tr></table>';
		if ( '' !== $d['ai_summary'] ) {
			$b .= '<div style="background:#eef2ff;border-radius:8px;padding:14px 16px;margin:0 0 20px;color:#1e1b4b"><div style="font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;margin-bottom:6px">' . esc_html__( 'DEV3 AI summary', 'dev3-ai-site-health-analyst' ) . '</div>' . esc_html( $d['ai_summary'] ) . '</div>';
		}
		$kpis = array(
			array( __( 'New issues', 'dev3-ai-site-health-analyst' ), $d['new_count'] ),
			array( __( 'Resolved', 'dev3-ai-site-health-analyst' ), $d['resolved'] ),
			array( __( 'Failed logins', 'dev3-ai-site-health-analyst' ), $d['security']['failed_logins'] ),
			array( __( 'Suspicious requests', 'dev3-ai-site-health-analyst' ), $d['security']['suspicious'] ),
		);
		$b   .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px"><tr>';
		foreach ( $kpis as $k ) {
			$b .= '<td style="width:25%;padding:10px;border:1px solid #e5e7eb;text-align:center"><div style="font-size:22px;font-weight:700;color:#111827">' . (int) $k[1] . '</div><div style="font-size:12px;color:#4b5563">' . esc_html( $k[0] ) . '</div></td>';
		}
		$b .= '</tr></table>';
		$b .= self::section( __( 'Top open issues', 'dev3-ai-site-health-analyst' ), $d['top'], $sev, __( 'No open issues. Nice work.', 'dev3-ai-site-health-analyst' ) );
		if ( $d['new'] ) {
			$b .= self::section( __( 'New this week', 'dev3-ai-site-health-analyst' ), $d['new'], $sev, '' );
		}
		$b .= '<h2 style="font-size:16px;margin:24px 0 8px;color:#111827">' . esc_html__( 'Updates', 'dev3-ai-site-health-analyst' ) . '</h2>';
		$b .= $d['updates'] ? '<ul style="margin:0;padding-left:18px;color:#374151">' . implode( '', array_map( static fn( $t ) => '<li>' . esc_html( $t ) . '</li>', array_slice( $d['updates'], 0, 10 ) ) ) . '</ul>' : '<p style="margin:0;color:#374151">' . esc_html__( 'Everything is up to date.', 'dev3-ai-site-health-analyst' ) . '</p>';
		$b .= '<h2 style="font-size:16px;margin:24px 0 8px;color:#111827">' . esc_html__( 'Security and integrity', 'dev3-ai-site-health-analyst' ) . '</h2><p style="margin:0;color:#374151">';
		if ( $d['security']['top_ips'] ) {
			$b .= esc_html__( 'Most active addresses:', 'dev3-ai-site-health-analyst' ) . ' ' . esc_html(
				implode(
					', ',
					array_map(
						static function ( $t ) {
							return $t['ip'] . ' (' . $t['count'] . ')';
						},
						$d['security']['top_ips']
					)
				)
			) . '<br>';
		}
		if ( $d['integrity'] ) {
			/* translators: 1: modified core files, 2: plugins changed, 3: plugins verified. */
			$b .= esc_html( sprintf( __( 'Integrity: %1$d core files changed, %2$d plugins differ, %3$d plugins verified.', 'dev3-ai-site-health-analyst' ), $d['integrity']['core_modified'] + $d['integrity']['core_missing'], $d['integrity']['plugins_changed'], $d['integrity']['plugins_ok'] ) );
		} else {
			$b .= esc_html__( 'Integrity: not checked yet.', 'dev3-ai-site-health-analyst' );
		}
		/* translators: 1: overdue tasks, 2: orphan tasks. */
		$b .= '<br>' . esc_html( sprintf( __( 'Scheduled tasks: %1$d overdue, %2$d without code.', 'dev3-ai-site-health-analyst' ), $d['cron']['overdue'], $d['cron']['orphans'] ) ) . '</p>';
		$b .= '<p style="margin:28px 0 0"><a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SH_SLUG ) ) . '" style="background:#4f46e5;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;display:inline-block">' . esc_html__( 'Open the full report', 'dev3-ai-site-health-analyst' ) . '</a></p>';
		/* translators: 1: site, 2: from, 3: to. */
		return self::wrap( esc_html( sprintf( __( 'Weekly site health: %1$s (%2$s – %3$s)', 'dev3-ai-site-health-analyst' ), $d['site'], $d['period']['from'], $d['period']['to'] ) ), $b );
	}

	/**
	 * Issue list section.
	 *
	 * @param string $title  Title.
	 * @param array  $issues Issues.
	 * @param array  $sev    Colors.
	 * @param string $none   Empty text.
	 * @return string
	 */
	private static function section( $title, array $issues, array $sev, $none ) {
		$h = '<h2 style="font-size:16px;margin:24px 0 8px;color:#111827">' . esc_html( $title ) . '</h2>';
		if ( ! $issues ) {
			return $h . '<p style="margin:0;color:#374151">' . esc_html( $none ) . '</p>';
		}
		$h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
		foreach ( $issues as $i ) {
			$h .= '<tr><td style="padding:8px 0;border-bottom:1px solid #e5e7eb"><span style="display:inline-block;min-width:64px;font-size:12px;font-weight:600;color:' . esc_attr( $sev[ $i['severity'] ] ?? '#4b5563' ) . '">' . esc_html( ucfirst( $i['severity'] ) ) . '</span> ' . esc_html( $i['title'] ) . ' <span style="color:#6b7280;font-size:12px">×' . (int) $i['count'] . '</span></td></tr>';
		}
		return $h . '</table>';
	}

	/**
	 * Email shell.
	 *
	 * @param string $title Escaped title.
	 * @param string $body  Escaped body HTML.
	 * @return string
	 */
	public static function wrap( $title, $body ) {
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head><body style="margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;color:#111827">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:10px;border:1px solid #e5e7eb"><tr><td style="padding:24px 24px 8px">'
			. '<div style="font-size:12px;font-weight:600;letter-spacing:.06em;color:#4f46e5;text-transform:uppercase">DEV3 AI Site Health</div>'
			. '<h1 style="font-size:20px;margin:6px 0 18px;color:#111827">' . $title . '</h1>' . $body
			. '</td></tr><tr><td style="padding:16px 24px 24px;color:#6b7280;font-size:12px">' . esc_html__( 'Sent by DEV3 AI Site Health & Security Log Analyst. Change recipients and thresholds under Reports & alerts.', 'dev3-ai-site-health-analyst' ) . '</td></tr></table>'
			. '</td></tr></table></body></html>';
	}

	/**
	 * Plain-text version.
	 *
	 * @param array $d Data.
	 * @return string
	 */
	public static function plain( array $d ) {
		$out  = sprintf( "%s\n%s - %s\n\n", $d['site'], $d['period']['from'], $d['period']['to'] );
		$out .= sprintf( "Health score: %d\nOpen issues: %d (critical %d)\n", $d['score'], $d['open']['total'], $d['open']['severity']['critical'] );
		if ( '' !== $d['ai_summary'] ) {
			$out .= "\n" . $d['ai_summary'] . "\n";
		}
		$out .= sprintf( "\nNew: %d, resolved: %d, failed logins: %d, suspicious requests: %d\n", $d['new_count'], $d['resolved'], $d['security']['failed_logins'], $d['security']['suspicious'] );
		$out .= "\nTop open issues:\n";
		foreach ( $d['top'] as $i ) {
			$out .= '- [' . $i['severity'] . '] ' . $i['title'] . ' x' . $i['count'] . "\n";
		}
		$out .= "\n" . admin_url( 'admin.php?page=' . DEV3SH_SLUG ) . "\n";
		return $out;
	}

	/**
	 * Builds and sends.
	 *
	 * @param string[]|null $to Recipients (null = settings).
	 * @return array { sent, to, subject }
	 */
	public static function send( $to = null ) {
		$to   = is_array( $to ) ? $to : self::recipients();
		$data = self::data( true );
		/* translators: 1: site, 2: score. */
		$subject = sprintf( __( '[%1$s] Weekly site health report: score %2$d', 'dev3-ai-site-health-analyst' ), $data['site'], $data['score'] );
		$ok      = $to ? self::mail( $to, $subject, self::html( $data ), self::plain( $data ) ) : false;
		update_option(
			self::LAST,
			array(
				'at'    => time(),
				'sent'  => $ok,
				'score' => $data['score'],
			),
			false
		);
		Events::add(
			'report',
			array(
				'rule'     => 'weekly',
				'severity' => 'info',
				'message'  => $subject,
				'meta'     => array(
					'sent' => $ok,
					'to'   => count( $to ),
				),
			)
		);
		return array(
			'sent'    => $ok,
			'to'      => $to,
			'subject' => $subject,
		);
	}

	/**
	 * Report recipients.
	 *
	 * @return string[]
	 */
	public static function recipients() {
		$raw = trim( (string) Settings_Store::get( 'report_emails', '' ) );
		if ( '' === $raw ) {
			return Alerts::recipients();
		}
		return array_values( array_filter( array_map( 'trim', preg_split( '/[\s,;]+/', $raw ) ), 'is_email' ) );
	}

	/**
	 * Sends a multipart HTML email.
	 *
	 * @param string[] $to      Recipients.
	 * @param string   $subject Subject.
	 * @param string   $html    HTML.
	 * @param string   $plain   Plain text.
	 * @return bool
	 */
	public static function mail( array $to, $subject, $html, $plain ) {
		$alt = static function ( $phpmailer ) use ( $plain ) {
			$phpmailer->AltBody = $plain; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		};
		add_action( 'phpmailer_init', $alt );
		$ok = wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $alt );
		return (bool) $ok;
	}
}
