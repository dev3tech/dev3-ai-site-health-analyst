<?php
/**
 * Plugin tables (issues + events).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Table names + dbDelta SQL.
 */
class Tables {

	/**
	 * Issues table.
	 *
	 * @return string
	 */
	public static function issues() {
		global $wpdb;
		return $wpdb->prefix . 'dev3sh_issues';
	}

	/**
	 * Events table.
	 *
	 * @return string
	 */
	public static function events() {
		global $wpdb;
		return $wpdb->prefix . 'dev3sh_events';
	}

	/**
	 * Adds our CREATE TABLE statements (filter dev3sh_schema_sql).
	 *
	 * @param array  $sql     Statements.
	 * @param string $charset Charset collate.
	 * @return array
	 */
	public static function schema( $sql, $charset ) {
		$issues = self::issues();
		$events = self::events();
		$sql[]  = "CREATE TABLE {$issues} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			fingerprint char(40) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT '',
			kind varchar(40) NOT NULL DEFAULT '',
			severity varchar(10) NOT NULL DEFAULT 'low',
			title varchar(255) NOT NULL DEFAULT '',
			message text NOT NULL,
			file varchar(255) NOT NULL DEFAULT '',
			line int(10) unsigned NOT NULL DEFAULT 0,
			component varchar(120) NOT NULL DEFAULT '',
			count bigint(20) unsigned NOT NULL DEFAULT 0,
			first_seen datetime NOT NULL,
			last_seen datetime NOT NULL,
			status varchar(10) NOT NULL DEFAULT 'open',
			regressed tinyint(1) unsigned NOT NULL DEFAULT 0,
			context longtext NOT NULL,
			ai longtext NOT NULL,
			ai_at datetime NULL DEFAULT NULL,
			resolved_at datetime NULL DEFAULT NULL,
			notified_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY fingerprint (fingerprint),
			KEY status_severity (status,severity),
			KEY source (source),
			KEY last_seen (last_seen)
		) {$charset};";
		$sql[]  = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			type varchar(30) NOT NULL DEFAULT '',
			rule varchar(40) NOT NULL DEFAULT '',
			severity varchar(10) NOT NULL DEFAULT 'info',
			ip varchar(45) NOT NULL DEFAULT '',
			user_login varchar(60) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			path varchar(255) NOT NULL DEFAULT '',
			message varchar(500) NOT NULL DEFAULT '',
			meta text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY type_created (type,created_at),
			KEY ip_created (ip,created_at),
			KEY created (created_at)
		) {$charset};";
		return $sql;
	}

	/**
	 * Drops plugin tables (uninstall).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( array( self::issues(), self::events() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
