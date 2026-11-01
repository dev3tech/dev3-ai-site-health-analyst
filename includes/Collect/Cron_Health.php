<?php
/**
 * WP-Cron health: overdue events, hooks without callbacks, DISABLE_WP_CRON without a real cron.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Reads _get_cron_array().
 */
class Cron_Health {

	const HEARTBEAT = 'dev3sh_cron_heartbeat';

	/**
	 * Records that our hourly cron ran (proves WP-Cron or a real cron works).
	 */
	public static function beat() {
		update_option( self::HEARTBEAT, time(), false );
	}

	/**
	 * Status.
	 *
	 * @return array { disabled, total, overdue[], orphans[], next[], heartbeat, heartbeat_late }
	 */
	public static function status() {
		$crons    = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$crons    = is_array( $crons ) ? $crons : array();
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$grace    = $disabled ? HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS;
		/**
		 * Seconds an event may be late before it counts as overdue.
		 *
		 * @param int $grace Seconds.
		 */
		$grace     = (int) apply_filters( 'dev3sh_cron_grace', $grace );
		$now       = time();
		$overdue   = array();
		$orphans   = array();
		$next      = array();
		$total     = 0;
		$schedules = wp_get_schedules();
		foreach ( $crons as $ts => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				foreach ( (array) $events as $key => $event ) {
					++$total;
					$row = array(
						'hook'     => (string) $hook,
						'time'     => gmdate( 'c', (int) $ts ),
						'late_min' => max( 0, (int) floor( ( $now - (int) $ts ) / 60 ) ),
						'schedule' => isset( $event['schedule'] ) && $event['schedule'] ? (string) $event['schedule'] : '',
						'interval' => isset( $event['schedule'], $schedules[ $event['schedule'] ] ) ? (int) $schedules[ $event['schedule'] ]['interval'] : 0,
						'args'     => isset( $event['args'] ) ? count( (array) $event['args'] ) : 0,
						'key'      => (string) $key,
						'has_cb'   => has_action( $hook ) ? true : false,
					);
					if ( (int) $ts < $now - $grace ) {
						$overdue[] = $row;
					}
					if ( ! $row['has_cb'] && 0 !== strpos( $hook, 'wp_' ) && ! in_array( $hook, self::core_hooks(), true ) ) {
						$orphans[ $hook ] = $row;
					}
					if ( count( $next ) < 15 ) {
						$next[] = $row;
					}
				}
			}
		}
		$beat = (int) get_option( self::HEARTBEAT, 0 );
		return array(
			'disabled'       => $disabled,
			'total'          => $total,
			'overdue'        => array_slice( $overdue, 0, 50 ),
			'overdue_count'  => count( $overdue ),
			'orphans'        => array_values( $orphans ),
			'next'           => $next,
			'heartbeat'      => $beat ? gmdate( 'c', $beat ) : '',
			'heartbeat_late' => $beat > 0 && $now - $beat > 3 * HOUR_IN_SECONDS,
		);
	}

	/**
	 * Core hooks that are registered lazily (admin-only callbacks) and must not be reported as orphans.
	 *
	 * @return string[]
	 */
	private static function core_hooks() {
		return array( 'delete_expired_transients', 'recovery_mode_clean_expired_keys', 'do_pings', 'importer_scheduled_cleanup', 'upgrader_scheduled_cleanup', 'publish_future_post', 'dev3sh_run_job' );
	}

	/**
	 * Issues.
	 *
	 * @return array
	 */
	public static function collect() {
		$s    = self::status();
		$keep = array();
		if ( $s['overdue_count'] > 0 ) {
			$hooks  = array_values( array_unique( wp_list_pluck( $s['overdue'], 'hook' ) ) );
			$keep[] = Issues::record(
				array(
					'source'      => 'cron',
					'kind'        => 'overdue',
					'fingerprint' => 'cron_overdue',
					'severity'    => $s['overdue_count'] >= 10 ? 'high' : 'medium',
					/* translators: %d: count. */
					'title'       => sprintf( _n( '%d scheduled task is overdue', '%d scheduled tasks are overdue', $s['overdue_count'], 'dev3-ai-site-health-analyst' ), $s['overdue_count'] ),
					'message'     => $s['disabled'] ? __( 'WP-Cron is disabled (DISABLE_WP_CRON) and the server cron job is not running often enough.', 'dev3-ai-site-health-analyst' ) : __( 'Scheduled tasks are not running on time. Scheduled posts, emails, backups and updates may be late.', 'dev3-ai-site-health-analyst' ),
					'replace'     => true,
					'count'       => $s['overdue_count'],
					'details'     => array( 'hooks' => array_slice( $hooks, 0, 20 ) ),
				)
			);
		}
		foreach ( array_slice( $s['orphans'], 0, 20 ) as $o ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'cron',
					'kind'        => 'orphan',
					'fingerprint' => 'cron_orphan:' . $o['hook'],
					'severity'    => 'low',
					/* translators: %s: hook. */
					'title'       => sprintf( __( 'Scheduled task without code: %s', 'dev3-ai-site-health-analyst' ), $o['hook'] ),
					'message'     => __( 'This task is scheduled but nothing handles it, usually because the plugin that added it was removed.', 'dev3-ai-site-health-analyst' ),
					'replace'     => true,
					'details'     => $o,
				)
			);
		}
		if ( $s['heartbeat_late'] ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'cron',
					'kind'        => 'not_running',
					'fingerprint' => 'cron_not_running',
					'severity'    => 'high',
					'title'       => __( 'WP-Cron does not seem to run', 'dev3-ai-site-health-analyst' ),
					'message'     => __( 'Our hourly check has not run for more than three hours.', 'dev3-ai-site-health-analyst' ),
					'replace'     => true,
				)
			);
		}
		Issues::resolve_missing( 'cron', array_filter( $keep ) );
		return array(
			'issues'  => count( array_filter( $keep ) ),
			'overdue' => $s['overdue_count'],
			'orphans' => count( $s['orphans'] ),
			'status'  => 'ok',
		);
	}
}
