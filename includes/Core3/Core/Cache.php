<?php
/**
 * AI result cache: sha256( task + model + prompt + inputs ) → stored result with a TTL.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Rows live in {prefix}dev3sh_core_cache so a plugin can flush one task or everything at once.
 * Expired rows are deleted on read and by the daily Schema::prune_usage() run.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class Cache {

	/**
	 * Per-request memo (avoids a second query for the same key).
	 *
	 * @var array
	 */
	private static $memo = array();

	/**
	 * Builds a cache key from a task and the inputs that change the answer.
	 *
	 * @param string $task   Task name.
	 * @param mixed  $inputs Anything JSON-encodable (prompt, system, schema, model, images…).
	 * @return string 64-char hex key.
	 */
	public static function key( $task, $inputs ) {
		return hash( 'sha256', (string) $task . '|' . wp_json_encode( self::canonical( $inputs ) ) );
	}

	/**
	 * Key for Ai::run args: ignores transport-only options and hashes image payloads.
	 *
	 * @param string $task Task.
	 * @param array  $args Ai::run args (after model resolution).
	 * @return string
	 */
	public static function key_for_args( $task, array $args ) {
		unset( $args['cache'], $args['timeout'], $args['retries'], $args['profile'] );
		if ( ! empty( $args['images'] ) ) {
			foreach ( $args['images'] as $i => $image ) {
				$args['images'][ $i ] = isset( $image['data'] ) ? md5( (string) $image['data'] ) : '';
			}
		}
		return self::key( $task, $args );
	}

	/**
	 * Reads a value.
	 *
	 * @param string $key Key.
	 * @return mixed|null Null on miss/expired.
	 */
	public static function get( $key ) {
		if ( array_key_exists( $key, self::$memo ) ) {
			return self::$memo[ $key ];
		}
		global $wpdb;
		$table = Schema::tables()['cache'];
		$wpdb->suppress_errors( true );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT value, expires_at FROM {$table} WHERE cache_key = %s", $key ), ARRAY_A );
		$wpdb->suppress_errors( false );
		if ( ! $row ) {
			return null;
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			self::delete( $key );
			return null;
		}
		$value              = json_decode( $row['value'], true );
		self::$memo[ $key ] = $value;
		return $value;
	}

	/**
	 * Stores a value.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value JSON-encodable value.
	 * @param int    $ttl   Seconds (min 1).
	 * @param string $task  Task (for per-task flushes).
	 * @return bool
	 */
	public static function set( $key, $value, $ttl, $task = '' ) {
		global $wpdb;
		$table = Schema::tables()['cache'];
		$wpdb->suppress_errors( true );
		$ok = $wpdb->replace(
			$table,
			array(
				'cache_key'  => $key,
				'task'       => substr( (string) $task, 0, 40 ),
				'value'      => wp_json_encode( $value ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $ttl ) ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( false );
		self::$memo[ $key ] = $value;
		return false !== $ok;
	}

	/**
	 * Deletes one key.
	 *
	 * @param string $key Key.
	 */
	public static function delete( $key ) {
		global $wpdb;
		unset( self::$memo[ $key ] );
		$wpdb->delete( Schema::tables()['cache'], array( 'cache_key' => $key ), array( '%s' ) );
	}

	/**
	 * Flushes one task's rows, or everything.
	 *
	 * @param string $task Task or '' for all.
	 * @return int Rows deleted.
	 */
	public static function flush( $task = '' ) {
		global $wpdb;
		self::$memo = array();
		$table      = Schema::tables()['cache'];
		if ( '' === $task ) {
			return (int) $wpdb->query( "DELETE FROM {$table}" );
		}
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE task = %s", $task ) );
	}

	/**
	 * Number of live rows (admin display).
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = Schema::tables()['cache'];
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE expires_at >= %s", current_time( 'mysql', true ) ) );
	}

	/**
	 * Clears the request memo (tests).
	 */
	public static function reset_memo() {
		self::$memo = array();
	}

	/**
	 * Sorts associative keys recursively so equivalent inputs hash the same.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( array_values( $value ) !== $value ) {
			ksort( $value );
		}
		return array_map( array( self::class, 'canonical' ), $value );
	}
}
