<?php
/**
 * Uninstall: removes data only when "delete data on uninstall" is enabled.
 *
 * @package Dev3\AiSiteHealth
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dev3sh_settings = get_option( 'dev3sh_settings', array() );
if ( empty( $dev3sh_settings['delete_on_uninstall'] ) ) {
	return;
}
global $wpdb;
foreach ( array( 'dev3sh_usage', 'dev3sh_core_cache', 'dev3sh_core_jobs', 'dev3sh_core_embeddings', 'dev3sh_issues', 'dev3sh_events' ) as $dev3sh_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$dev3sh_table}" );
}
foreach ( array( 'dev3sh_settings', 'dev3sh_license', 'dev3sh_db_version', 'dev3sh_log_state', 'dev3sh_collector_status', 'dev3sh_integrity', 'dev3sh_integrity_last_run', 'dev3sh_snapshot', 'dev3sh_score_history', 'dev3sh_blocklist', 'dev3sh_alert_state', 'dev3sh_site_health', 'dev3sh_cron_heartbeat', 'dev3sh_last_scan', 'dev3sh_report_last' ) as $dev3sh_option ) {
	delete_option( $dev3sh_option );
}
delete_transient( 'dev3sh_models' );
delete_site_transient( 'dev3sh_update_info' );
foreach ( array( 'dev3sh_run_job', 'dev3sh_daily', 'dev3sh_collect', 'dev3sh_weekly_report' ) as $dev3sh_hook ) {
	wp_unschedule_hook( $dev3sh_hook );
}
delete_transient( 'dev3sh_slow_queries' );
$dev3sh_uploads = wp_upload_dir( null, false );
$dev3sh_backups = trailingslashit( $dev3sh_uploads['basedir'] ) . 'dev3sh-backups';
if ( is_dir( $dev3sh_backups ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	if ( WP_Filesystem() ) {
		global $wp_filesystem;
		$wp_filesystem->delete( $dev3sh_backups, true );
	}
}
