<?php
/**
 * Runs WordPress Site Health tests (direct + async tests that have a direct method) and keeps results.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health bridge.
 */
class Site_Health {

	const RESULTS = 'dev3sh_site_health';

	/**
	 * Runs the tests.
	 *
	 * @return array[] { test, label, status, badge, description, actions }
	 */
	public static function run() {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		foreach ( array( 'misc', 'plugin', 'update', 'file', 'theme' ) as $inc ) {
			require_once ABSPATH . 'wp-admin/includes/' . $inc . '.php';
		}
		$health = \WP_Site_Health::get_instance();
		$tests  = \WP_Site_Health::get_tests();
		/**
		 * Tests to skip (ids).
		 *
		 * @param string[] $skip Ids.
		 */
		$skip  = (array) apply_filters( 'dev3sh_site_health_skip', array() );
		$async = (bool) Settings_Store::get( 'site_health_async', true );
		$out   = array();
		$list  = isset( $tests['direct'] ) ? (array) $tests['direct'] : array();
		if ( $async && isset( $tests['async'] ) ) {
			foreach ( (array) $tests['async'] as $id => $test ) {
				if ( empty( $test['skip_cron'] ) ) {
					$list[ $id ] = $test;
				}
			}
		}
		foreach ( $list as $id => $test ) {
			if ( in_array( $id, $skip, true ) ) {
				continue;
			}
			$result = null;
			try {
				if ( isset( $test['async_direct_test'] ) && is_callable( $test['async_direct_test'] ) ) {
					$result = call_user_func( $test['async_direct_test'] );
				} elseif ( is_string( $test['test'] ) && method_exists( $health, 'get_test_' . $test['test'] ) ) {
					$result = call_user_func( array( $health, 'get_test_' . $test['test'] ) );
				} elseif ( is_callable( $test['test'] ) ) {
					$result = call_user_func( $test['test'] );
				}
			} catch ( \Throwable $e ) {
				$result = null;
			}
			if ( ! is_array( $result ) || empty( $result['status'] ) ) {
				continue;
			}
			$out[] = array(
				'test'        => (string) ( $result['test'] ?? $id ),
				'label'       => wp_strip_all_tags( (string) ( $result['label'] ?? $test['label'] ?? $id ) ),
				'status'      => (string) $result['status'],
				'badge'       => isset( $result['badge']['label'] ) ? wp_strip_all_tags( (string) $result['badge']['label'] ) : '',
				'description' => self::text( (string) ( $result['description'] ?? '' ) ),
				'actions'     => self::text( (string) ( $result['actions'] ?? '' ) ),
			);
		}
		update_option(
			self::RESULTS,
			array(
				'at'      => time(),
				'results' => $out,
			),
			false
		);
		return $out;
	}

	/**
	 * HTML → readable text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function text( $html ) {
		$html = preg_replace( '#<(?:/p|br\s*/?|/li|/h\d)>#i', "\n", (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		return trim( (string) preg_replace( "/\n\s*\n+/", "\n", (string) $text ) );
	}

	/**
	 * Last results.
	 *
	 * @return array { at, results }
	 */
	public static function last() {
		$r = get_option( self::RESULTS, array() );
		return is_array( $r ) && isset( $r['results'] ) ? $r : array(
			'at'      => 0,
			'results' => array(),
		);
	}

	/**
	 * Runs (when stale or forced) and records issues for critical/recommended results.
	 *
	 * @param bool $force Force.
	 * @return array
	 */
	public static function collect( $force = false ) {
		$last = self::last();
		if ( ! $force && $last['at'] > time() - DAY_IN_SECONDS && $last['results'] ) {
			$results = $last['results'];
		} else {
			$results = self::run();
		}
		$keep = array();
		$crit = 0;
		foreach ( $results as $r ) {
			if ( 'good' === $r['status'] ) {
				continue;
			}
			$crit  += 'critical' === $r['status'] ? 1 : 0;
			$keep[] = Issues::record(
				array(
					'source'      => 'site_health',
					'kind'        => $r['status'],
					'fingerprint' => 'site_health:' . $r['test'],
					'severity'    => 'critical' === $r['status'] ? 'high' : 'low',
					'title'       => $r['label'],
					'message'     => mb_substr( $r['description'], 0, 1500 ),
					'replace'     => true,
					'details'     => array(
						'test'    => $r['test'],
						'badge'   => $r['badge'],
						'actions' => mb_substr( $r['actions'], 0, 1500 ),
					),
				)
			);
		}
		Issues::resolve_missing( 'site_health', array_filter( $keep ) );
		return array(
			'status'   => 'ok',
			'tests'    => count( $results ),
			'issues'   => count( array_filter( $keep ) ),
			'critical' => $crit,
		);
	}
}
