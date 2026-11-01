<?php
/**
 * Slow query capture (only when SAVEQUERIES is on): buffers at shutdown, turned into issues hourly.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Analyze\Fingerprint;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * SAVEQUERIES reader.
 */
class Slow_Queries {

	const BUFFER = 'dev3sh_slow_queries';
	const MAX    = 50;

	/**
	 * Registers the shutdown hook when SAVEQUERIES is on.
	 */
	public static function register() {
		if ( self::available() && Settings_Store::get( 'slow_queries', true ) ) {
			add_action( 'shutdown', array( __CLASS__, 'capture' ), 1 );
		}
	}

	/**
	 * SAVEQUERIES is on.
	 *
	 * @return bool
	 */
	public static function available() {
		return defined( 'SAVEQUERIES' ) && SAVEQUERIES;
	}

	/**
	 * Threshold in seconds.
	 *
	 * @return float
	 */
	public static function threshold() {
		return max( 0.01, (float) Settings_Store::get( 'slow_query_ms', 250 ) / 1000 );
	}

	/**
	 * Collapses literals: 'abc' → ?, 123 → ?, IN (?, ?, ?) → IN (?+).
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	public static function normalize( $sql ) {
		$s = (string) $sql;
		$s = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $s );
		$s = preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/s', '?', $s );
		$s = preg_replace( '/(?<![\w$.])-?\d+(?:\.\d+)?\b/', '?', $s );
		$s = preg_replace( '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		return mb_substr( trim( (string) $s ), 0, 1500 );
	}

	/**
	 * Shutdown: buffers slow queries of this request.
	 *
	 * @param array|null $queries Queries (tests) or null for $wpdb->queries.
	 * @return int Buffered.
	 */
	public static function capture( $queries = null ) {
		global $wpdb;
		$queries = is_array( $queries ) ? $queries : ( isset( $wpdb->queries ) ? (array) $wpdb->queries : array() );
		$limit   = self::threshold();
		$slow    = array();
		foreach ( $queries as $q ) {
			if ( ! is_array( $q ) || ! isset( $q[1] ) || (float) $q[1] < $limit ) {
				continue;
			}
			if ( false !== strpos( (string) $q[0], 'dev3sh_' ) ) {
				continue; // Never report our own writes.
			}
			$slow[] = array(
				'sql'    => mb_substr( (string) $q[0], 0, 2000 ),
				'time'   => round( (float) $q[1], 4 ),
				'caller' => mb_substr( (string) ( $q[2] ?? '' ), 0, 600 ),
				'at'     => time(),
				'uri'    => isset( $_SERVER['REQUEST_URI'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 200 ) : '',
			);
		}
		if ( ! $slow ) {
			return 0;
		}
		usort(
			$slow,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);
		$buffer = get_transient( self::BUFFER );
		$buffer = is_array( $buffer ) ? $buffer : array();
		$buffer = array_slice( array_merge( array_slice( $slow, 0, 5 ), $buffer ), 0, self::MAX );
		set_transient( self::BUFFER, $buffer, DAY_IN_SECONDS );
		return count( $slow );
	}

	/**
	 * Turns the buffer into issues.
	 *
	 * @return array
	 */
	public static function collect() {
		if ( ! self::available() ) {
			return array(
				'status'  => 'off',
				'issues'  => 0,
				'message' => __( 'Slow-query capture needs SAVEQUERIES in wp-config.php (development/staging only; it costs memory).', 'dev3-ai-site-health-analyst' ),
			);
		}
		$buffer = get_transient( self::BUFFER );
		delete_transient( self::BUFFER );
		$ids = array();
		foreach ( is_array( $buffer ) ? $buffer : array() as $q ) {
			$norm      = self::normalize( $q['sql'] );
			$component = self::component_from_caller( $q['caller'] );
			$ms        = (int) round( $q['time'] * 1000 );
			$ids[]     = Issues::record(
				array(
					'source'      => 'query',
					'kind'        => 'slow_query',
					'fingerprint' => 'slow:' . md5( $norm ),
					'severity'    => $ms >= 1000 ? 'high' : 'medium',
					/* translators: %d: milliseconds. */
					'title'       => sprintf( __( 'Slow database query (%d ms)', 'dev3-ai-site-health-analyst' ), $ms ),
					'message'     => $norm,
					'component'   => $component,
					'time'        => (int) $q['at'],
					'sample'      => $ms . ' ms · ' . $q['uri'] . "\n" . Fingerprint::relative_in_text( $q['caller'] ),
					'details'     => array(
						'ms'     => $ms,
						'caller' => $q['caller'],
					),
				)
			);
		}
		return array(
			'status' => 'ok',
			'issues' => count( array_unique( array_filter( $ids ) ) ),
		);
	}

	/**
	 * Component from a $wpdb caller string ("require('wp-blog-header.php'), ..., MyPlugin->query").
	 *
	 * @param string $caller Caller.
	 * @return string
	 */
	public static function component_from_caller( $caller ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$frames = array_reverse( array_map( 'trim', explode( ',', (string) $caller ) ) );
		// Class prefixes of active plugins (e.g. WC_ → woocommerce) are guessed from the folder name.
		foreach ( $frames as $frame ) {
			if ( preg_match( "#(?:include|require)(?:_once)?\\('([^']+)'\\)#", $frame, $m ) ) {
				$c = Fingerprint::component( $m[1] );
				if ( '' !== $c && 'core' !== $c ) {
					return $c;
				}
			}
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			$slug = dirname( $file );
			$hint = strtolower( str_replace( '-', '_', $slug ) );
			foreach ( $frames as $frame ) {
				if ( strlen( $hint ) > 3 && false !== strpos( strtolower( $frame ), $hint ) ) {
					return 'plugin:' . $slug;
				}
			}
		}
		return '';
	}
}
