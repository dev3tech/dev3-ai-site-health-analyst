<?php
/**
 * Shared tables (usage log, AI cache, jobs, embeddings). Plugins add their own tables through `dev3sh_schema_sql`.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Runs dbDelta and records the schema version.
 */
class Schema {

	const VERSION_OPTION = 'dev3sh_db_version';

	/**
	 * Bumped whenever a core table changes. Stored next to the plugin's version ("1.0.0+core2"),
	 * so plugins get new core tables without bumping their own DB version.
	 */
	const CORE_VERSION = '2';

	/**
	 * Usage table name.
	 *
	 * @return string
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'dev3sh_usage';
	}

	/**
	 * Core table names (all prefixed per plugin). The `core_` infix keeps them clear of plugin tables
	 * (dev3tl already has its own `dev3tl_jobs`).
	 *
	 * @return string[] { usage, cache, jobs, embeddings }
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'usage'      => self::usage_table(),
			'cache'      => $wpdb->prefix . 'dev3sh_core_cache',
			'jobs'       => $wpdb->prefix . 'dev3sh_core_jobs',
			'embeddings' => $wpdb->prefix . 'dev3sh_core_embeddings',
		);
	}

	/**
	 * Stored version string for a plugin schema version.
	 *
	 * @param string $version Plugin schema version.
	 * @return string
	 */
	public static function stamp( $version ) {
		return $version . '+core' . self::CORE_VERSION;
	}

	/**
	 * Creates/updates tables when the plugin or core schema version changed.
	 *
	 * @param string $version Plugin schema version.
	 */
	public static function maybe_install( $version ) {
		if ( get_option( self::VERSION_OPTION ) !== self::stamp( $version ) ) {
			self::install( $version );
		}
	}

	/**
	 * Drops every core table (uninstall). Plugin tables are the plugin's job.
	 */
	public static function drop_tables() {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}
	}

	/**
	 * Runs dbDelta for shared + plugin tables.
	 *
	 * @param string $version Schema version to record.
	 */
	public static function install( $version ) {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$usage   = self::usage_table();
		$tables  = self::tables();
		$sql     = array(
			"CREATE TABLE {$usage} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				task varchar(40) NOT NULL DEFAULT '',
				context varchar(40) NOT NULL DEFAULT '',
				model varchar(80) NOT NULL DEFAULT '',
				prompt_tokens int(10) unsigned NOT NULL DEFAULT 0,
				output_tokens int(10) unsigned NOT NULL DEFAULT 0,
				total_tokens int(10) unsigned NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'ok',
				error_code varchar(40) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY created (created_at),
				KEY user_created (user_id,created_at)
			) {$charset};",
			"CREATE TABLE {$tables['cache']} (
				cache_key char(64) NOT NULL,
				task varchar(40) NOT NULL DEFAULT '',
				value longtext NOT NULL,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (cache_key),
				KEY task (task),
				KEY expires (expires_at)
			) {$charset};",
			"CREATE TABLE {$tables['jobs']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(60) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'queued',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				items longtext NOT NULL,
				args longtext NOT NULL,
				cursor_pos int(10) unsigned NOT NULL DEFAULT 0,
				total int(10) unsigned NOT NULL DEFAULT 0,
				done int(10) unsigned NOT NULL DEFAULT 0,
				failed int(10) unsigned NOT NULL DEFAULT 0,
				chunk smallint(5) unsigned NOT NULL DEFAULT 10,
				errors longtext NOT NULL,
				result longtext NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				locked_until datetime NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY type_status (type,status),
				KEY updated (updated_at)
			) {$charset};",
			"CREATE TABLE {$tables['embeddings']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(40) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				hash char(40) NOT NULL DEFAULT '',
				dims smallint(5) unsigned NOT NULL DEFAULT 0,
				embedding longblob NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY object (object_type,object_id),
				KEY object_type (object_type)
			) {$charset};",
		);
		/**
		 * Extra CREATE TABLE statements for dbDelta.
		 *
		 * @param array  $sql     Statements.
		 * @param string $charset Charset collate.
		 */
		$sql = apply_filters( 'dev3sh_schema_sql', $sql, $charset );
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		update_option( self::VERSION_OPTION, self::stamp( $version ), false );
	}

	/**
	 * Daily housekeeping (hooked to each plugin's daily cron): prunes usage older than a year,
	 * expired AI cache rows and finished jobs older than 30 days.
	 */
	public static function prune_usage() {
		global $wpdb;
		$tables = self::tables();
		$now    = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['usage']} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS ) ) );
		$wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['cache']} WHERE expires_at < %s", $now ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tables['jobs']} WHERE status IN ('done','cancelled','failed') AND updated_at < %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$wpdb->suppress_errors( false );
		// phpcs:enable
	}
}
