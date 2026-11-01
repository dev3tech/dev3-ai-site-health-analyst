<?php
/**
 * Background jobs: chunked batches on Action Scheduler (bundled with WooCommerce) or WP-Cron.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Usage:
 *
 *     Jobs::register( 'reindex', static function ( array $items, array $job ) {
 *         $out = array();
 *         foreach ( $items as $id ) {
 *             $out[ $id ] = do_work( $id ); // true, or a WP_Error / string on failure.
 *         }
 *         return $out;
 *     } );
 *     $job_id = Jobs::create( 'reindex', $ids, array( 'lang' => 'de' ), 10 );
 *
 * Register handlers on every request (e.g. on `init`). Each run processes chunks until a time
 * budget is spent, then re-schedules itself. Progress lives in {prefix}dev3sh_core_jobs; poll
 * GET dev3sh/v1/jobs/<id> (the JobProgress component does). Jobs resume after a crash: a stale
 * running job is picked up again by the next run or by a status poll.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class Jobs {

	const HOOK       = 'dev3sh_run_job';
	const GROUP      = 'dev3sh_core_jobs';
	const MAX_ERRORS = 50;

	/**
	 * Handlers by type.
	 *
	 * @var callable[]
	 */
	private static $handlers = array();

	/**
	 * Registers a job type and hooks the runner.
	 *
	 * @param string   $type    Job type (≤60 chars).
	 * @param callable $handler fn( array $items, array $job ): array keyed by item (scalar items) or chunk index, value true|WP_Error|string (failure message); missing keys count as done. Return a WP_Error to fail the whole chunk.
	 */
	public static function register( $type, callable $handler ) {
		self::$handlers[ (string) $type ] = $handler;
		if ( ! has_action( self::HOOK, array( self::class, 'run' ) ) ) {
			add_action( self::HOOK, array( self::class, 'run' ), 10, 1 );
		}
	}

	/**
	 * Whether a type has a handler.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function has( $type ) {
		return isset( self::$handlers[ (string) $type ] );
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		return Schema::tables()['jobs'];
	}

	/**
	 * Creates and schedules a job.
	 *
	 * @param string $type  Registered type.
	 * @param array  $items Work items (ids or small arrays), processed in order.
	 * @param array  $args  Job args handed to the handler as $job['args'].
	 * @param int    $chunk Items per handler call (1–500).
	 * @return int|\WP_Error Job id.
	 */
	public static function create( $type, array $items, array $args = array(), $chunk = 10 ) {
		global $wpdb;
		if ( ! self::has( $type ) ) {
			return new \WP_Error( 'dev3sh_job_type', __( 'Unknown job type.', 'dev3-ai-site-health-analyst' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			self::table(),
			array(
				'type'         => substr( (string) $type, 0, 60 ),
				'status'       => 'queued',
				'user_id'      => get_current_user_id(),
				'items'        => wp_json_encode( array_values( $items ) ),
				'args'         => wp_json_encode( $args ),
				'cursor_pos'   => 0,
				'total'        => count( $items ),
				'done'         => 0,
				'failed'       => 0,
				'chunk'        => max( 1, min( 500, (int) $chunk ) ),
				'errors'       => '[]',
				'result'       => '{}',
				'attempts'     => 0,
				'locked_until' => '1970-01-01 00:00:00',
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'dev3sh_job_db', __( 'The job could not be saved.', 'dev3-ai-site-health-analyst' ), array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( 0 === count( $items ) ) {
			self::update( $id, array( 'status' => 'done' ) );
		} else {
			self::schedule( $id );
		}
		return $id;
	}

	/**
	 * Whether Action Scheduler is used.
	 *
	 * @return bool
	 */
	public static function uses_action_scheduler() {
		/**
		 * Forces (or disables) Action Scheduler for jobs.
		 *
		 * @param bool $use Detected availability.
		 */
		return (bool) apply_filters( 'dev3sh_jobs_use_action_scheduler', function_exists( 'as_enqueue_async_action' ) );
	}

	/**
	 * Schedules the next run of a job.
	 *
	 * @param int $id    Job id.
	 * @param int $delay Seconds.
	 */
	public static function schedule( $id, $delay = 0 ) {
		$args = array( (int) $id );
		if ( self::uses_action_scheduler() ) {
			if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
			} else {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), self::HOOK, $args );
		}
		if ( ! defined( 'WP_TESTS_DOMAIN' ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Runner (hooked to dev3sh_run_job): processes chunks until the time budget is spent.
	 *
	 * @param int $id     Job id.
	 * @param int $budget Seconds (default 20, filter dev3sh_job_time_budget).
	 * @return array|null Job after the run.
	 */
	public static function run( $id, $budget = 0 ) {
		global $wpdb;
		$id = (int) $id;
		/**
		 * Seconds a single run may work before it re-schedules itself.
		 *
		 * @param int $seconds Seconds.
		 */
		$budget = $budget > 0 ? (int) $budget : (int) apply_filters( 'dev3sh_job_time_budget', 20 );
		$now    = current_time( 'mysql', true );
		$table  = self::table();
		// Atomic lock: only one runner per job.
		$locked = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status IN ('queued','running') AND locked_until < %s",
				gmdate( 'Y-m-d H:i:s', time() + $budget + 60 ),
				$now,
				$id,
				$now
			)
		);
		if ( ! $locked ) {
			return self::get( $id );
		}
		$row = self::row( $id );
		if ( ! $row || ! self::has( $row['type'] ) ) {
			self::update(
				$id,
				array(
					'status'       => 'failed',
					'locked_until' => '1970-01-01 00:00:00',
				)
			);
			return self::get( $id );
		}
		$handler = self::$handlers[ $row['type'] ];
		$items   = (array) json_decode( $row['items'], true );
		$errors  = (array) json_decode( $row['errors'], true );
		$cursor  = (int) $row['cursor_pos'];
		$done    = (int) $row['done'];
		$failed  = (int) $row['failed'];
		$started = microtime( true );
		$job     = self::shape( $row );
		$count   = count( $items );

		while ( $cursor < $count && ( microtime( true ) - $started ) < $budget ) {
			// Cancelled from another request?
			if ( 'cancelled' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) ) ) {
				return self::get( $id );
			}
			$chunk = array_slice( $items, $cursor, (int) $row['chunk'] );
			try {
				$result = call_user_func( $handler, $chunk, $job );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'dev3sh_job_exception', $e->getMessage() );
			}
			foreach ( $chunk as $i => $item ) {
				$key     = is_scalar( $item ) ? (string) $item : (string) $i;
				$outcome = is_wp_error( $result ) ? $result : ( is_array( $result ) && array_key_exists( $key, $result ) ? $result[ $key ] : true );
				if ( true === $outcome || null === $outcome ) {
					++$done;
					continue;
				}
				++$failed;
				$errors[] = array(
					'item'    => $item,
					'message' => is_wp_error( $outcome ) ? $outcome->get_error_message() : ( is_string( $outcome ) ? $outcome : __( 'Failed', 'dev3-ai-site-health-analyst' ) ),
				);
			}
			$cursor += count( $chunk );
			$errors  = array_slice( $errors, -self::MAX_ERRORS );
			self::update(
				$id,
				array(
					'cursor_pos' => $cursor,
					'done'       => $done,
					'failed'     => $failed,
					'errors'     => wp_json_encode( $errors ),
				)
			);
		}

		if ( $cursor >= $count ) {
			$status = 0 === $done && $failed > 0 ? 'failed' : 'done';
			self::update(
				$id,
				array(
					'status'       => $status,
					'locked_until' => '1970-01-01 00:00:00',
				)
			);
			$final = self::get( $id );
			/**
			 * A job finished (done or failed).
			 *
			 * @param array $job Job (see Jobs::get()).
			 */
			do_action( 'dev3sh_job_finished', $final );
			/**
			 * A job of a given type finished.
			 *
			 * @param array $job Job.
			 */
			do_action( 'dev3sh_job_finished_' . $row['type'], $final );
			return $final;
		}
		self::update( $id, array( 'locked_until' => '1970-01-01 00:00:00' ) );
		self::schedule( $id );
		return self::get( $id );
	}

	/**
	 * Re-schedules (or runs a short slice inline) when a queued/running job made no progress for
	 * 30 seconds, e.g. because WP-Cron is disabled. Called by the status endpoint.
	 *
	 * @param int $id Job id.
	 * @return array|null
	 */
	public static function kick( $id ) {
		$row = self::row( $id );
		if ( ! $row || ! in_array( $row['status'], array( 'queued', 'running' ), true ) ) {
			return $row ? self::shape( $row ) : null;
		}
		/**
		 * Seconds without progress before a status poll runs the job inline.
		 *
		 * @param int $seconds Seconds.
		 */
		if ( time() - strtotime( $row['updated_at'] . ' UTC' ) >= (int) apply_filters( 'dev3sh_job_stale_after', 30 ) ) {
			return self::run( $id, 8 );
		}
		return self::shape( $row );
	}

	/**
	 * Cancels a job.
	 *
	 * @param int $id Job id.
	 * @return array|null
	 */
	public static function cancel( $id ) {
		$job = self::get( $id );
		if ( $job && in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			self::update(
				$id,
				array(
					'status'       => 'cancelled',
					'locked_until' => '1970-01-01 00:00:00',
				)
			);
			wp_clear_scheduled_hook( self::HOOK, array( (int) $id ) );
			if ( self::uses_action_scheduler() && function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::HOOK, array( (int) $id ), self::GROUP );
			}
		}
		return self::get( $id );
	}

	/**
	 * Retries a finished job: re-queues its failed items (or everything left, after a cancel).
	 *
	 * @param int $id Job id.
	 * @return array|\WP_Error
	 */
	public static function retry( $id ) {
		$row = self::row( $id );
		if ( ! $row ) {
			return new \WP_Error( 'dev3sh_job_missing', __( 'Job not found.', 'dev3-ai-site-health-analyst' ), array( 'status' => 404 ) );
		}
		if ( in_array( $row['status'], array( 'queued', 'running' ), true ) ) {
			return self::get( $id );
		}
		$items  = (array) json_decode( $row['items'], true );
		$errors = (array) json_decode( $row['errors'], true );
		$failed = array_map( static fn( $e ) => $e['item'], $errors );
		$left   = array_slice( $items, (int) $row['cursor_pos'] );
		$queue  = array_merge( $failed, $left );
		if ( ! $queue ) {
			return self::get( $id );
		}
		self::update(
			$id,
			array(
				'status'       => 'queued',
				'items'        => wp_json_encode( array_values( $queue ) ),
				'cursor_pos'   => 0,
				'total'        => count( $queue ),
				'done'         => 0,
				'failed'       => 0,
				'errors'       => '[]',
				'locked_until' => '1970-01-01 00:00:00',
			)
		);
		self::schedule( $id );
		return self::get( $id );
	}

	/**
	 * Stores a JSON-encodable result on the job (e.g. a summary for the UI).
	 *
	 * @param int   $id     Job id.
	 * @param array $result Result (merged into the stored one).
	 */
	public static function set_result( $id, array $result ) {
		$row = self::row( $id );
		if ( $row ) {
			self::update( $id, array( 'result' => wp_json_encode( array_merge( (array) json_decode( $row['result'], true ), $result ) ) ) );
		}
	}

	/**
	 * Public job shape.
	 *
	 * @param int $id Job id.
	 * @return array|null { id, type, status, total, done, failed, processed, percent, errors, result, args, user_id, created_at, updated_at, runner }
	 */
	public static function get( $id ) {
		$row = self::row( $id );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Recent jobs.
	 *
	 * @param string $type  Type filter ('' = all).
	 * @param int    $limit Max.
	 * @return array
	 */
	public static function recent( $type = '', $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 100, (int) $limit ) );
		$rows  = '' === $type
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT %d", $type, $limit ), ARRAY_A );
		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * Latest queued/running job of a type (avoid starting duplicates).
	 *
	 * @param string $type Type.
	 * @return array|null
	 */
	public static function active( $type ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s AND status IN ('queued','running') ORDER BY id DESC LIMIT 1", $type ), ARRAY_A );
		return $row ? self::shape( $row ) : null;
	}

	/**
	 * Raw row.
	 *
	 * @param int $id Job id.
	 * @return array|null
	 */
	private static function row( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Updates columns and touches updated_at.
	 *
	 * @param int   $id   Job id.
	 * @param array $data Columns.
	 */
	private static function update( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Row → public shape.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function shape( array $row ) {
		$total     = (int) $row['total'];
		$processed = (int) $row['done'] + (int) $row['failed'];
		return array(
			'id'         => (int) $row['id'],
			'type'       => $row['type'],
			'status'     => $row['status'],
			'total'      => $total,
			'done'       => (int) $row['done'],
			'failed'     => (int) $row['failed'],
			'processed'  => $processed,
			'percent'    => $total > 0 ? (int) floor( $processed / $total * 100 ) : 100,
			'errors'     => (array) json_decode( $row['errors'], true ),
			'result'     => (object) json_decode( $row['result'], true ),
			'args'       => (array) json_decode( $row['args'], true ),
			'user_id'    => (int) $row['user_id'],
			'created_at' => mysql_to_rfc3339( $row['created_at'] ),
			'updated_at' => mysql_to_rfc3339( $row['updated_at'] ),
			'runner'     => self::uses_action_scheduler() ? 'action-scheduler' : 'wp-cron',
		);
	}
}
