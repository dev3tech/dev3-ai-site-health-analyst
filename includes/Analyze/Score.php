<?php
/**
 * Health score (0-100) from open issues + 30-day history.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Analyze;

use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Score.
 */
class Score {

	const HISTORY = 'dev3sh_score_history';

	const WEIGHTS = array(
		'critical' => 25,
		'high'     => 10,
		'medium'   => 4,
		'low'      => 1,
		'info'     => 0,
	);

	/**
	 * Max penalty points one source can add, so one noisy log cannot floor the score.
	 */
	const SOURCE_CAP = 30;

	/**
	 * Penalty points that bring the score down to ~37 (1/e).
	 */
	const SCALE = 180;

	/**
	 * Highest score while any critical issue is open (never "good" with a critical problem).
	 */
	const CRITICAL_MAX = 69;

	/**
	 * Computes the score from open issues.
	 *
	 * @param array[]|null $open Open issues (source, severity, component) or null to load.
	 * @return int
	 */
	public static function compute( $open = null ) {
		$open      = is_array( $open ) ? $open : Issues::open_brief();
		$by_source = array();
		foreach ( $open as $i ) {
			$key               = (string) $i['source'];
			$w                 = isset( self::WEIGHTS[ $i['severity'] ] ) ? self::WEIGHTS[ $i['severity'] ] : 0;
			$by_source[ $key ] = min( self::SOURCE_CAP, ( isset( $by_source[ $key ] ) ? $by_source[ $key ] : 0 ) + $w );
		}
		// Diminishing curve: a few issues cost a few points each, many issues never floor the score at 0.
		$score = (int) max( 0, min( 100, round( 100 * exp( -array_sum( $by_source ) / self::SCALE ) ) ) );
		foreach ( $open as $i ) {
			if ( 'critical' === $i['severity'] ) {
				return min( $score, self::CRITICAL_MAX );
			}
		}
		return $score;
	}

	/**
	 * Grade label.
	 *
	 * @param int $score Score.
	 * @return string good|fair|poor
	 */
	public static function grade( $score ) {
		return $score >= 80 ? 'good' : ( $score >= 50 ? 'fair' : 'poor' );
	}

	/**
	 * Records today's score (one entry per day, 30 days).
	 *
	 * @param int|null $score Score.
	 * @return array History.
	 */
	public static function snapshot( $score = null ) {
		$score                        = null === $score ? self::compute() : (int) $score;
		$history                      = self::history();
		$history[ gmdate( 'Y-m-d' ) ] = $score;
		ksort( $history );
		$history = array_slice( $history, -30, 30, true );
		update_option( self::HISTORY, $history, false );
		return $history;
	}

	/**
	 * History.
	 *
	 * @return array date => score
	 */
	public static function history() {
		$h = get_option( self::HISTORY, array() );
		return is_array( $h ) ? $h : array();
	}

	/**
	 * Score a week ago (or null).
	 *
	 * @return int|null
	 */
	public static function week_ago() {
		$h   = self::history();
		$day = gmdate( 'Y-m-d', time() - WEEK_IN_SECONDS );
		$old = null;
		foreach ( $h as $d => $s ) {
			if ( $d <= $day ) {
				$old = (int) $s;
			}
		}
		return $old;
	}
}
