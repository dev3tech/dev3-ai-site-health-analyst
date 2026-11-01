<?php
/**
 * Event log (security, changes, fixes, alerts).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Data;

use Dev3\AiSiteHealth\Analyze\Redactor;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;

defined( 'ABSPATH' ) || exit;

/**
 * Repository.
 */
class Events {

	const TYPES = array( 'login_failed', 'login_ok', 'suspicious', 'not_found_flood', 'change', 'fix', 'alert', 'report' );

	/**
	 * Inserts an event.
	 *
	 * @param string $type Type.
	 * @param array  $data { rule, severity, ip, user_login, user_id, path, message, meta, time }.
	 * @return int
	 */
	public static function add( $type, array $data = array() ) {
		global $wpdb;
		$ip = isset( $data['ip'] ) ? (string) $data['ip'] : '';
		if ( '' !== $ip && Settings_Store::get( 'anonymize_ip', false ) ) {
			$ip = Redactor::anonymize_ip( $ip );
		}
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::events(),
			array(
				'type'       => in_array( $type, self::TYPES, true ) ? $type : 'change',
				'rule'       => mb_substr( (string) ( $data['rule'] ?? '' ), 0, 40 ),
				'severity'   => mb_substr( (string) ( $data['severity'] ?? 'info' ), 0, 10 ),
				'ip'         => mb_substr( $ip, 0, 45 ),
				'user_login' => mb_substr( (string) ( $data['user_login'] ?? '' ), 0, 60 ),
				'user_id'    => (int) ( $data['user_id'] ?? 0 ),
				'path'       => mb_substr( (string) ( $data['path'] ?? '' ), 0, 255 ),
				'message'    => mb_substr( (string) ( $data['message'] ?? '' ), 0, 500 ),
				'meta'       => isset( $data['meta'] ) ? (string) wp_json_encode( $data['meta'] ) : '',
				'created_at' => gmdate( 'Y-m-d H:i:s', isset( $data['time'] ) ? (int) $data['time'] : time() ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Lists events.
	 *
	 * @param array $args { type (string|array), rule, ip, search, since, page, per_page }.
	 * @return array { items, total, page, per_page }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = Tables::events();
		$args  = wp_parse_args(
			$args,
			array(
				'type'     => '',
				'rule'     => '',
				'ip'       => '',
				'search'   => '',
				'since'    => 0,
				'page'     => 1,
				'per_page' => 25,
			)
		);
		$where = array( '1=1' );
		$vals  = array();
		$types = array_values( array_intersect( (array) ( is_string( $args['type'] ) && '' !== $args['type'] ? explode( ',', $args['type'] ) : $args['type'] ), self::TYPES ) );
		if ( $types ) {
			$where[] = 'type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			$vals    = array_merge( $vals, $types );
		}
		foreach ( array( 'rule', 'ip' ) as $col ) {
			if ( '' !== (string) $args[ $col ] ) {
				$where[] = "{$col} = %s";
				$vals[]  = (string) $args[ $col ];
			}
		}
		if ( $args['since'] ) {
			$where[] = 'created_at >= %s';
			$vals[]  = gmdate( 'Y-m-d H:i:s', (int) $args['since'] );
		}
		if ( '' !== trim( (string) $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[] = '(message LIKE %s OR path LIKE %s OR user_login LIKE %s OR ip LIKE %s)';
			array_push( $vals, $like, $like, $like, $like );
		}
		$w        = implode( ' AND ', $where );
		$per_page = min( 200, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$w}", $vals ) : "SELECT COUNT(*) FROM {$table} WHERE {$w}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$w} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items'    => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * One event.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::events();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Updates an event's meta.
	 *
	 * @param int   $id   Id.
	 * @param array $meta Meta.
	 */
	public static function set_meta( $id, array $meta ) {
		global $wpdb;
		$wpdb->update( Tables::events(), array( 'meta' => wp_json_encode( $meta ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Counts by type since a time.
	 *
	 * @param string $type  Type.
	 * @param int    $since Unix time.
	 * @param string $ip    Optional IP.
	 * @return int
	 */
	public static function count( $type, $since, $ip = '' ) {
		global $wpdb;
		$table = Tables::events();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $ip ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND ip = %s AND created_at >= %s", $type, $ip, gmdate( 'Y-m-d H:i:s', (int) $since ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE type = %s AND created_at >= %s", $type, gmdate( 'Y-m-d H:i:s', (int) $since ) ) );
		// phpcs:enable
	}

	/**
	 * Top values of a column for a type since a time.
	 *
	 * @param string $type   Type (or '' for security types).
	 * @param string $column ip|rule|user_login.
	 * @param int    $since  Unix time.
	 * @param int    $limit  Limit.
	 * @return array[] { value, count, last }
	 */
	public static function top( $type, $column, $since, $limit = 5 ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'ip', 'rule', 'user_login' ), true ) ) {
			return array();
		}
		$table = Tables::events();
		$types = '' === $type ? array( 'login_failed', 'suspicious', 'not_found_flood' ) : array( $type );
		$in    = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$column} AS value, COUNT(*) AS n, MAX(created_at) AS last FROM {$table} WHERE type IN ({$in}) AND {$column} <> '' AND created_at >= %s GROUP BY {$column} ORDER BY n DESC LIMIT %d",
				array_merge( $types, array( gmdate( 'Y-m-d H:i:s', (int) $since ), (int) $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map(
			static function ( $r ) {
				return array(
					'value' => (string) $r['value'],
					'count' => (int) $r['n'],
					'last'  => Issues::iso( $r['last'] ),
				);
			},
			(array) $rows
		);
	}

	/**
	 * Daily counts of a type for the last N days (oldest first).
	 *
	 * @param string $type Type.
	 * @param int    $days Days.
	 * @return int[]
	 */
	public static function daily( $type, $days = 14 ) {
		global $wpdb;
		$table = Tables::events();
		$since = gmdate( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(created_at) AS d, COUNT(*) AS n FROM {$table} WHERE type = %s AND created_at >= %s GROUP BY DATE(created_at)", $type, $since ), ARRAY_A );
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$map[ $r['d'] ] = (int) $r['n'];
		}
		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$out[] = isset( $map[ $d ] ) ? $map[ $d ] : 0;
		}
		return $out;
	}

	/**
	 * Deletes events older than the retention (fix events are kept for undo history).
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function prune( $days ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$table = Tables::events();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE type <> 'fix' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$meta = json_decode( (string) $row['meta'], true );
		return array(
			'id'         => (int) $row['id'],
			'type'       => (string) $row['type'],
			'rule'       => (string) $row['rule'],
			'severity'   => (string) $row['severity'],
			'ip'         => (string) $row['ip'],
			'user_login' => (string) $row['user_login'],
			'user_id'    => (int) $row['user_id'],
			'path'       => (string) $row['path'],
			'message'    => (string) $row['message'],
			'meta'       => is_array( $meta ) ? $meta : array(),
			'created_at' => Issues::iso( $row['created_at'] ),
		);
	}

	/**
	 * Deletes everything (tests).
	 */
	public static function clear() {
		global $wpdb;
		$table = Tables::events();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
