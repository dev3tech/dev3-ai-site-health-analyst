<?php
/**
 * Runs the collectors (hourly cron, "Scan now", WP-CLI) and records per-source status.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Analyze\Score;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Report\Alerts;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrator.
 */
class Collector {

	const CRON   = 'dev3sh_collect';
	const STATUS = 'dev3sh_collector_status';

	const SOURCES = array( 'php', 'site_health', 'updates', 'cron', 'query', 'changes', 'integrity', 'security' );

	/**
	 * Registers the hourly hook.
	 */
	public static function register() {
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
	}

	/**
	 * Hourly cron.
	 */
	public static function cron() {
		Cron_Health::beat();
		self::run( array(), false );
	}

	/**
	 * Runs collectors.
	 *
	 * @param string[] $sources Sources ([] = all due).
	 * @param bool     $force   Force slow sources (Site Health, integrity) now.
	 * @return array { results{source: summary}, score, integrity_job, alerts }
	 */
	public static function run( array $sources = array(), $force = false ) {
		$all     = empty( $sources );
		$sources = $all ? self::SOURCES : array_values( array_intersect( $sources, self::SOURCES ) );
		$enabled = (array) Settings_Store::get( 'collectors', array() );
		$status  = self::status();
		$results = array();
		$job     = null;

		Issues::$created   = array();
		Issues::$regressed = array();

		foreach ( $sources as $source ) {
			if ( isset( $enabled[ $source ] ) && ! $enabled[ $source ] ) {
				$results[ $source ] = array(
					'status'  => 'off',
					'message' => __( 'Switched off in Settings.', 'dev3-ai-site-health-analyst' ),
				);
				$status[ $source ]  = $results[ $source ] + array( 'at' => time() );
				continue;
			}
			$started = microtime( true );
			try {
				switch ( $source ) {
					case 'php':
						$r = Php_Log::collect();
						break;
					case 'site_health':
						$r = Site_Health::collect( $force );
						break;
					case 'updates':
						$r = Updates::collect();
						break;
					case 'cron':
						$r = Cron_Health::collect();
						break;
					case 'query':
						$r = Slow_Queries::collect();
						break;
					case 'changes':
						$changes = Change_Logger::diff();
						$r       = array(
							'status'  => 'ok',
							'changes' => count( $changes ),
						);
						break;
					case 'security':
						$r = array(
							'status' => Settings_Store::get( 'security_logger', true ) ? 'ok' : 'off',
							'events' => Events::count( 'login_failed', time() - DAY_IN_SECONDS ) + Events::count( 'suspicious', time() - DAY_IN_SECONDS ),
						);
						break;
					case 'integrity':
						$r = self::maybe_integrity( $force || ! $all );
						if ( ! empty( $r['job'] ) ) {
							$job = $r['job'];
						}
						unset( $r['job'] );
						break;
					default:
						$r = array( 'status' => 'off' );
				}
			} catch ( \Throwable $e ) {
				$r = array(
					'status'  => 'error',
					'message' => $e->getMessage(),
				);
			}
			$r['ms']            = (int) round( ( microtime( true ) - $started ) * 1000 );
			$results[ $source ] = $r;
			$status[ $source ]  = array(
				'at'      => time(),
				'status'  => isset( $r['status'] ) ? $r['status'] : 'ok',
				'message' => isset( $r['message'] ) ? (string) $r['message'] : '',
			) + $r;
		}
		update_option( self::STATUS, $status, false );

		$resolved = Issues::resolve_stale( (int) Settings_Store::get( 'stale_days', 14 ) );
		Events::prune( (int) Settings_Store::get( 'retention_days', 30 ) );
		$score  = Score::snapshot();
		$alerts = Alerts::check_issues( Issues::$created, Issues::$regressed );
		update_option( 'dev3sh_last_scan', time(), false );
		return array(
			'results'        => $results,
			'score'          => $score[ gmdate( 'Y-m-d' ) ],
			'created'        => count( Issues::$created ),
			'regressed'      => count( Issues::$regressed ),
			'stale_resolved' => $resolved,
			'integrity_job'  => $job,
			'alerts'         => $alerts,
		);
	}

	/**
	 * Starts the integrity job when due (or forced).
	 *
	 * @param bool $force Force.
	 * @return array
	 */
	private static function maybe_integrity( $force ) {
		$freq = (string) Settings_Store::get( 'integrity_schedule', 'weekly' );
		$last = (int) get_option( 'dev3sh_integrity_last_run', 0 );
		$due  = 'off' !== $freq && time() - $last >= ( 'daily' === $freq ? DAY_IN_SECONDS : WEEK_IN_SECONDS ) - HOUR_IN_SECONDS;
		if ( ! $force && ! $due ) {
			return array(
				'status'  => 'off' === $freq ? 'off' : 'ok',
				'message' => 'off' === $freq ? __( 'Scheduled checks are off.', 'dev3-ai-site-health-analyst' ) : '',
				'last'    => $last ? gmdate( 'c', $last ) : '',
			);
		}
		$job = Integrity::start();
		if ( is_wp_error( $job ) ) {
			return array(
				'status'  => 'error',
				'message' => $job->get_error_message(),
			);
		}
		return array(
			'status'  => 'running',
			'message' => __( 'The integrity check runs in the background.', 'dev3-ai-site-health-analyst' ),
			'job'     => $job,
		);
	}

	/**
	 * Stored per-source status.
	 *
	 * @return array
	 */
	public static function status() {
		$s = get_option( self::STATUS, array() );
		return is_array( $s ) ? $s : array();
	}
}
