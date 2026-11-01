<?php
/**
 * Issues repository: groups occurrences by fingerprint (count, first/last seen, samples, status).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Data;

use Dev3\AiSiteHealth\Analyze\Fingerprint;

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + queries.
 */
class Issues {

	const SOURCES    = array( 'php', 'site_health', 'security', 'change', 'updates', 'cron', 'query', 'integrity' );
	const SEVERITIES = array( 'critical', 'high', 'medium', 'low', 'info' );
	const STATUSES   = array( 'open', 'resolved', 'ignored' );
	const RANK       = array(
		'critical' => 5,
		'high'     => 4,
		'medium'   => 3,
		'low'      => 2,
		'info'     => 1,
	);

	/**
	 * New issues created during this request (for alerts).
	 *
	 * @var int[]
	 */
	public static $created = array();

	/**
	 * Issues re-opened during this request.
	 *
	 * @var int[]
	 */
	public static $regressed = array();

	/**
	 * Records an occurrence (insert or bump).
	 *
	 * @param array $data { source, kind, severity, title, message, file, line, component, time, sample, count, details, fingerprint, replace }.
	 *                    `replace` = true sets count to `count` instead of adding (state-type sources like updates/cron).
	 * @return int Issue id (0 on failure).
	 */
	public static function record( array $data ) {
		global $wpdb;
		$table = Tables::issues();
		$data  = wp_parse_args(
			$data,
			array(
				'source'      => 'php',
				'kind'        => 'other',
				'severity'    => 'low',
				'title'       => '',
				'message'     => '',
				'file'        => '',
				'line'        => 0,
				'component'   => '',
				'time'        => time(),
				'sample'      => '',
				'count'       => 1,
				'details'     => null,
				'fingerprint' => '',
				'replace'     => false,
			)
		);
		$fp    = '' !== $data['fingerprint'] ? sha1( $data['source'] . '|' . $data['fingerprint'] ) : Fingerprint::make( $data['source'], $data['kind'], $data['message'], $data['file'], $data['line'] );
		$when  = gmdate( 'Y-m-d H:i:s', (int) $data['time'] );
		$sev   = in_array( $data['severity'], self::SEVERITIES, true ) ? $data['severity'] : 'low';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, count, context, first_seen, last_seen FROM {$table} WHERE fingerprint = %s", $fp ), ARRAY_A );
		if ( $row ) {
			$context = json_decode( (string) $row['context'], true );
			$context = is_array( $context ) ? $context : array();
			if ( '' !== $data['sample'] ) {
				$samples = isset( $context['samples'] ) ? (array) $context['samples'] : array();
				array_unshift( $samples, mb_substr( (string) $data['sample'], 0, 2000 ) );
				$context['samples'] = array_slice( array_values( array_unique( $samples ) ), 0, 3 );
			}
			if ( null !== $data['details'] ) {
				$context['details'] = $data['details'];
			}
			$update = array(
				'count'     => $data['replace'] ? max( 0, (int) $data['count'] ) : (int) $row['count'] + max( 1, (int) $data['count'] ),
				'last_seen' => $when > $row['last_seen'] ? $when : $row['last_seen'],
				'context'   => wp_json_encode( $context ),
				'title'     => mb_substr( (string) $data['title'], 0, 255 ),
				'severity'  => $sev,
			);
			if ( $when < $row['first_seen'] ) {
				$update['first_seen'] = $when;
			}
			if ( 'resolved' === $row['status'] ) {
				$update['status']      = 'open';
				$update['regressed']   = 1;
				$update['resolved_at'] = null;
				self::$regressed[]     = (int) $row['id'];
			}
			$wpdb->update( $table, $update, array( 'id' => (int) $row['id'] ) );
			return (int) $row['id'];
		}
		$context = array( 'samples' => '' !== $data['sample'] ? array( mb_substr( (string) $data['sample'], 0, 2000 ) ) : array() );
		if ( null !== $data['details'] ) {
			$context['details'] = $data['details'];
		}
		$ok = $wpdb->insert(
			$table,
			array(
				'fingerprint' => $fp,
				'source'      => in_array( $data['source'], self::SOURCES, true ) ? $data['source'] : 'php',
				'kind'        => mb_substr( (string) $data['kind'], 0, 40 ),
				'severity'    => $sev,
				'title'       => mb_substr( (string) $data['title'], 0, 255 ),
				'message'     => mb_substr( (string) $data['message'], 0, 4000 ),
				'file'        => mb_substr( (string) $data['file'], 0, 255 ),
				'line'        => max( 0, (int) $data['line'] ),
				'component'   => mb_substr( (string) $data['component'], 0, 120 ),
				'count'       => max( 1, (int) $data['count'] ),
				'first_seen'  => $when,
				'last_seen'   => $when,
				'status'      => 'open',
				'context'     => wp_json_encode( $context ),
				'ai'          => '',
			)
		);
		// phpcs:enable
		if ( ! $ok ) {
			return 0;
		}
		$id              = (int) $wpdb->insert_id;
		self::$created[] = $id;
		/**
		 * Fires when a new issue is created.
		 *
		 * @param int   $id   Issue id.
		 * @param array $data Data.
		 */
		do_action( 'dev3sh_issue_created', $id, $data );
		return $id;
	}

	/**
	 * Resolves state-type issues of a source that are no longer present (e.g. an update was installed).
	 *
	 * @param string $source   Source.
	 * @param int[]  $keep_ids Issue ids still present.
	 * @param string $kind     Optional kind restriction.
	 * @return int Resolved count.
	 */
	public static function resolve_missing( $source, array $keep_ids, $kind = '' ) {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$sql  = $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND status = 'open'", $source );
		$sql .= '' !== $kind ? $wpdb->prepare( ' AND kind = %s', $kind ) : '';
		$ids  = array_map( 'intval', (array) $wpdb->get_col( $sql ) );
		$gone = array_diff( $ids, array_map( 'intval', $keep_ids ) );
		foreach ( $gone as $id ) {
			$wpdb->update(
				$table,
				array(
					'status'      => 'resolved',
					'resolved_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
		}
		// phpcs:enable
		return count( $gone );
	}

	/**
	 * Auto-resolves PHP/query issues not seen for N days.
	 *
	 * @param int $days Days.
	 * @return int
	 */
	public static function resolve_stale( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days <= 0 ) {
			return 0;
		}
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'resolved', resolved_at = %s WHERE status = 'open' AND source IN ('php','query') AND last_seen < %s", current_time( 'mysql', true ), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Issue id for a fingerprint (0 if none).
	 *
	 * @param string $fingerprint Fingerprint.
	 * @return int
	 */
	public static function id_for( $fingerprint ) {
		global $wpdb;
		$table = Tables::issues();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE fingerprint = %s", $fingerprint ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * One issue (decoded).
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::issues();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Lists issues.
	 *
	 * @param array $args { status, source, severity, component, search, orderby, order, page, per_page }.
	 * @return array { items, total, page, per_page }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = Tables::issues();
		$args  = wp_parse_args(
			$args,
			array(
				'status'    => 'open',
				'source'    => '',
				'severity'  => '',
				'component' => '',
				'kind'      => '',
				'search'    => '',
				'orderby'   => 'severity',
				'order'     => 'desc',
				'page'      => 1,
				'per_page'  => 25,
			)
		);
		$where = array( '1=1' );
		$vals  = array();
		if ( in_array( $args['status'], self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$vals[]  = $args['status'];
		}
		if ( in_array( $args['source'], self::SOURCES, true ) ) {
			$where[] = 'source = %s';
			$vals[]  = $args['source'];
		}
		if ( in_array( $args['severity'], self::SEVERITIES, true ) ) {
			$where[] = 'severity = %s';
			$vals[]  = $args['severity'];
		}
		if ( '' !== (string) $args['kind'] ) {
			$where[] = 'kind = %s';
			$vals[]  = (string) $args['kind'];
		}
		if ( '' !== (string) $args['component'] ) {
			$where[] = 'component = %s';
			$vals[]  = (string) $args['component'];
		}
		if ( '' !== trim( (string) $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[] = '(title LIKE %s OR message LIKE %s OR file LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		$dir      = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$order    = array(
			'severity'   => "FIELD(severity,'info','low','medium','high','critical') {$dir}, last_seen DESC",
			'count'      => "count {$dir}",
			'last_seen'  => "last_seen {$dir}",
			'first_seen' => "first_seen {$dir}",
			'title'      => "title {$dir}",
		);
		$by       = isset( $order[ $args['orderby'] ] ) ? $order[ $args['orderby'] ] : $order['severity'];
		$per_page = min( 200, max( 1, (int) $args['per_page'] ) );
		$page     = max( 1, (int) $args['page'] );
		$w        = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
		$total = (int) $wpdb->get_var( $vals ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$w}", $vals ) : "SELECT COUNT(*) FROM {$table} WHERE {$w}" );
		$sql   = "SELECT * FROM {$table} WHERE {$w} ORDER BY {$by} LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $vals, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A );
		// phpcs:enable
		return array(
			'items'    => array_map( array( __CLASS__, 'shape' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Sets status.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$data = array(
			'status'      => $status,
			'resolved_at' => 'resolved' === $status ? current_time( 'mysql', true ) : null,
		);
		if ( 'open' === $status ) {
			$data['regressed'] = 0;
		}
		return false !== $wpdb->update( Tables::issues(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Stores the AI explanation (analysis only, it never changes the site).
	 *
	 * @param int   $id          Id.
	 * @param array $explanation Explanation.
	 * @return bool
	 */
	public static function set_ai( $id, array $explanation ) {
		global $wpdb;
		return false !== $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::issues(),
			array(
				'ai'    => wp_json_encode( $explanation ),
				'ai_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Marks issues as notified.
	 *
	 * @param int[] $ids Ids.
	 */
	public static function mark_notified( array $ids ) {
		global $wpdb;
		foreach ( $ids as $id ) {
			$wpdb->update( Tables::issues(), array( 'notified_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Open counts by severity and by source.
	 *
	 * @return array { severity: {..}, source: {..}, total }
	 */
	public static function counts() {
		global $wpdb;
		$table = Tables::issues();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT severity, source, COUNT(*) AS n FROM {$table} WHERE status = 'open' GROUP BY severity, source", ARRAY_A );
		$out  = array(
			'severity' => array_fill_keys( self::SEVERITIES, 0 ),
			'source'   => array_fill_keys( self::SOURCES, 0 ),
			'total'    => 0,
		);
		foreach ( (array) $rows as $r ) {
			$n = (int) $r['n'];
			if ( isset( $out['severity'][ $r['severity'] ] ) ) {
				$out['severity'][ $r['severity'] ] += $n;
			}
			if ( isset( $out['source'][ $r['source'] ] ) ) {
				$out['source'][ $r['source'] ] += $n;
			}
			$out['total'] += $n;
		}
		return $out;
	}

	/**
	 * Open issues (for the score).
	 *
	 * @return array[] { source, severity, component }
	 */
	public static function open_brief() {
		global $wpdb;
		$table = Tables::issues();
		return (array) $wpdb->get_results( "SELECT id, source, severity, component FROM {$table} WHERE status = 'open'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Issues created/regressed/resolved since a time (weekly report).
	 *
	 * @param int $since Unix time.
	 * @return array { new, regressed, resolved }
	 */
	public static function since( $since ) {
		global $wpdb;
		$table = Tables::issues();
		$when  = gmdate( 'Y-m-d H:i:s', (int) $since );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE first_seen >= %s ORDER BY FIELD(severity,'info','low','medium','high','critical') DESC, count DESC LIMIT 50", $when ), ARRAY_A );
		$regressed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE regressed = 1 AND status = 'open' AND last_seen >= %s", $when ) );
		$resolved  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = 'resolved' AND resolved_at >= %s", $when ) );
		// phpcs:enable
		return array(
			'new'       => array_map( array( __CLASS__, 'shape' ), (array) $new ),
			'regressed' => $regressed,
			'resolved'  => $resolved,
		);
	}

	/**
	 * Row → API shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ) {
		$context = json_decode( (string) $row['context'], true );
		$ai      = json_decode( (string) $row['ai'], true );
		return array(
			'id'              => (int) $row['id'],
			'fingerprint'     => (string) $row['fingerprint'],
			'source'          => (string) $row['source'],
			'kind'            => (string) $row['kind'],
			'severity'        => (string) $row['severity'],
			'title'           => (string) $row['title'],
			'message'         => (string) $row['message'],
			'file'            => (string) $row['file'],
			'line'            => (int) $row['line'],
			'component'       => (string) $row['component'],
			'component_label' => Fingerprint::component_label( (string) $row['component'] ),
			'count'           => (int) $row['count'],
			'first_seen'      => self::iso( $row['first_seen'] ),
			'last_seen'       => self::iso( $row['last_seen'] ),
			'status'          => (string) $row['status'],
			'regressed'       => (bool) $row['regressed'],
			'context'         => is_array( $context ) ? $context : array(),
			'ai'              => is_array( $ai ) ? $ai : null,
			'ai_at'           => self::iso( $row['ai_at'] ),
			'resolved_at'     => self::iso( $row['resolved_at'] ),
			'notified'        => ! empty( $row['notified_at'] ),
		);
	}

	/**
	 * MySQL UTC → ISO 8601.
	 *
	 * @param string|null $value Value.
	 * @return string
	 */
	public static function iso( $value ) {
		return $value && '0000-00-00 00:00:00' !== $value ? gmdate( 'c', (int) strtotime( $value . ' UTC' ) ) : '';
	}

	/**
	 * Deletes everything (tests, "clear all").
	 */
	public static function clear() {
		global $wpdb;
		$table = Tables::issues();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$created   = array();
		self::$regressed = array();
	}
}
