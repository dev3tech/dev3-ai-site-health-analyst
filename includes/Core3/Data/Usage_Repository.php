<?php
/**
 * Usage log (tokens, latency, errors).
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Data;

use Dev3\AiSiteHealth\Core3\Core\Schema as Installer;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store as Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and aggregates {prefix}dev3sh_usage.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class Usage_Repository {

	/**
	 * Request cache for month().
	 *
	 * @var array|null
	 */
	private static $month = null;

	/**
	 * Estimated USD per 1M tokens { input, output }, matched by longest model-id prefix.
	 * Google's list prices (paid tier, prompts ≤200k) as of 2026-10; image output is billed as tokens
	 * (≈1290 per 1024px image). Override with the `dev3sh_model_prices` filter.
	 *
	 * @return array
	 */
	public static function prices() {
		$prices = array(
			'gemini-2.5-flash-lite'  => array( 0.10, 0.40 ),
			'gemini-2.5-flash-image' => array( 0.30, 30.00 ),
			'gemini-2.5-flash'       => array( 0.30, 2.50 ),
			'gemini-2.5-pro'         => array( 1.25, 10.00 ),
			'gemini-2.0-flash-lite'  => array( 0.075, 0.30 ),
			'gemini-2.0-flash'       => array( 0.10, 0.40 ),
			'gemini-3-pro'           => array( 2.00, 12.00 ),
			'gemini-3-flash'         => array( 0.50, 3.00 ),
			'gemini-embedding'       => array( 0.15, 0.00 ),
			'text-embedding'         => array( 0.15, 0.00 ),
			'gemini'                 => array( 0.30, 2.50 ),
		);
		/**
		 * Filters the per-model price table (USD per 1M tokens, [ input, output ]).
		 *
		 * @param array $prices Prices keyed by model-id prefix.
		 */
		return (array) apply_filters( 'dev3sh_model_prices', $prices );
	}

	/**
	 * Estimated USD cost of a request.
	 *
	 * @param string $model         Model id.
	 * @param int    $prompt_tokens Input tokens.
	 * @param int    $output_tokens Output tokens (thinking included).
	 * @return float
	 */
	public static function estimate_cost( $model, $prompt_tokens, $output_tokens ) {
		$best  = '';
		$price = array( 0, 0 );
		foreach ( self::prices() as $prefix => $p ) {
			if ( 0 === strpos( (string) $model, (string) $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best  = $prefix;
				$price = array_values( (array) $p );
			}
		}
		return ( (int) $prompt_tokens * (float) $price[0] + (int) $output_tokens * (float) ( isset( $price[1] ) ? $price[1] : 0 ) ) / 1000000;
	}

	/**
	 * This calendar month (UTC): estimated cost against the cap.
	 *
	 * @return array { cost, cap, percent, reached, requests, tokens, month, resets_at, currency }
	 */
	public static function month() {
		if ( null !== self::$month ) {
			return self::$month;
		}
		global $wpdb;
		$table = Installer::usage_table();
		$start = gmdate( 'Y-m-01 00:00:00' );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT model, COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS p, COALESCE(SUM(output_tokens),0) AS o, COALESCE(SUM(total_tokens),0) AS t FROM {$table} WHERE created_at >= %s GROUP BY model", $start ), ARRAY_A );
		$cost  = 0.0;
		$req   = 0;
		$tok   = 0;
		foreach ( (array) $rows as $row ) {
			$cost += self::estimate_cost( $row['model'], (int) $row['p'], (int) $row['o'] );
			$req  += (int) $row['requests'];
			$tok  += (int) $row['t'];
		}
		$cap         = (float) Settings::get( 'monthly_cap', 0 );
		self::$month = array(
			'cost'      => round( $cost, 4 ),
			'cap'       => $cap,
			'percent'   => $cap > 0 ? min( 100, round( $cost / $cap * 100, 1 ) ) : 0,
			'reached'   => $cap > 0 && $cost >= $cap,
			'requests'  => $req,
			'tokens'    => $tok,
			'month'     => gmdate( 'Y-m' ),
			'resets_at' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( 'first day of next month 00:00:00 UTC' ) ),
			'currency'  => 'USD',
		);
		return self::$month;
	}

	/**
	 * Whether the monthly cap is reached.
	 *
	 * @return bool
	 */
	public static function cap_reached() {
		if ( (float) Settings::get( 'monthly_cap', 0 ) <= 0 ) {
			return false;
		}
		return self::month()['reached'];
	}

	/**
	 * Clears the month() request cache (tests, settings changes).
	 */
	public static function flush() {
		self::$month = null;
	}

	/**
	 * Logs one request.
	 *
	 * @param array $row { user_id, task, context, model, usage{prompt,output,total}, duration_ms, status, error_code }.
	 */
	public static function log( array $row ) {
		// The monthly cap needs the log, so it keeps logging while a cap is set.
		if ( ! Settings::get( 'usage_logging', true ) && (float) Settings::get( 'monthly_cap', 0 ) <= 0 ) {
			return;
		}
		self::$month = null;
		global $wpdb;
		$usage = isset( $row['usage'] ) ? $row['usage'] : array();
		$wpdb->insert(
			Installer::usage_table(),
			array(
				'user_id'       => (int) $row['user_id'],
				'task'          => substr( (string) $row['task'], 0, 40 ),
				'context'       => substr( (string) $row['context'], 0, 40 ),
				'model'         => substr( (string) $row['model'], 0, 80 ),
				'prompt_tokens' => isset( $usage['prompt'] ) ? (int) $usage['prompt'] : 0,
				'output_tokens' => isset( $usage['output'] ) ? (int) $usage['output'] : 0,
				'total_tokens'  => isset( $usage['total'] ) ? (int) $usage['total'] : 0,
				'duration_ms'   => isset( $row['duration_ms'] ) ? (int) $row['duration_ms'] : 0,
				'status'        => isset( $row['status'] ) ? substr( (string) $row['status'], 0, 20 ) : 'ok',
				'error_code'    => isset( $row['error_code'] ) ? substr( (string) $row['error_code'], 0, 40 ) : '',
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Aggregates for the Usage Logs tab.
	 *
	 * @param int $days Window in days.
	 * @return array { totals, cost, month, by_day, by_task, by_model (with cost), by_user }
	 */
	public static function summary( $days = 30 ) {
		global $wpdb;
		$table = Installer::usage_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$totals   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests, COALESCE(SUM(prompt_tokens),0) AS prompt_tokens, COALESCE(SUM(output_tokens),0) AS output_tokens,
				COALESCE(SUM(total_tokens),0) AS total_tokens, COALESCE(ROUND(AVG(duration_ms)),0) AS avg_ms, COALESCE(SUM(status <> 'ok'),0) AS errors
				FROM {$table} WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);
		$by_day   = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS day, COUNT(*) AS requests, SUM(total_tokens) AS tokens FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at) ORDER BY day", $since ), ARRAY_A );
		$by_task  = $wpdb->get_results( $wpdb->prepare( "SELECT task, COUNT(*) AS requests, SUM(total_tokens) AS tokens FROM {$table} WHERE created_at >= %s GROUP BY task ORDER BY requests DESC", $since ), ARRAY_A );
		$by_model = $wpdb->get_results( $wpdb->prepare( "SELECT model, COUNT(*) AS requests, SUM(total_tokens) AS tokens, SUM(prompt_tokens) AS prompt_tokens, SUM(output_tokens) AS output_tokens FROM {$table} WHERE created_at >= %s GROUP BY model ORDER BY requests DESC", $since ), ARRAY_A );
		$by_user  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, COUNT(*) AS requests, SUM(total_tokens) AS tokens FROM {$table} WHERE created_at >= %s GROUP BY user_id ORDER BY requests DESC LIMIT 20", $since ), ARRAY_A );

		foreach ( $by_user as $i => $u ) {
			$user                  = get_userdata( (int) $u['user_id'] );
			$by_user[ $i ]['name'] = $user ? $user->display_name : __( 'Deleted user', 'dev3-ai-site-health-analyst' );
		}

		$by_model = self::ints( $by_model );
		$cost     = 0.0;
		foreach ( $by_model as $i => $m ) {
			$by_model[ $i ]['cost'] = round( self::estimate_cost( $m['model'], $m['prompt_tokens'], $m['output_tokens'] ), 4 );
			$cost                  += $by_model[ $i ]['cost'];
		}

		return array(
			'days'     => $days,
			'totals'   => array_map( 'intval', (array) $totals ),
			'cost'     => round( $cost, 4 ),
			'month'    => self::month(),
			'by_day'   => self::ints( $by_day ),
			'by_task'  => self::ints( $by_task ),
			'by_model' => $by_model,
			'by_user'  => self::ints( $by_user ),
		);
	}

	/**
	 * Recent rows.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Page size.
	 * @return array { items, total }
	 */
	public static function recent( $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table = Installer::usage_table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		$names = array();
		foreach ( (array) $rows as $i => $row ) {
			$uid = (int) $row['user_id'];
			if ( ! isset( $names[ $uid ] ) ) {
				$user          = get_userdata( $uid );
				$names[ $uid ] = $user ? $user->display_name : '—';
			}
			$rows[ $i ]['user']       = $names[ $uid ];
			$rows[ $i ]['created_at'] = mysql_to_rfc3339( $row['created_at'] );
		}
		return array(
			'items' => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Requests by a user in the last minute (server-side rate limit).
	 *
	 * @param int $user_id User.
	 * @return int
	 */
	public static function last_minute( $user_id ) {
		global $wpdb;
		$table = Installer::usage_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND created_at >= %s", $user_id, gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ) ) );
	}

	/**
	 * Casts numeric columns.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function ints( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			foreach ( $row as $k => $v ) {
				if ( is_numeric( $v ) && 'day' !== $k ) {
					$row[ $k ] = (int) $v;
				}
			}
			$out[] = $row;
		}
		return $out;
	}
}
