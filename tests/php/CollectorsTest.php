<?php
/**
 * Issues grouping, score, security logger, change log, updates, cron, slow queries, Site Health.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Analyze\Knowledge_Base;
use Dev3\AiSiteHealth\Analyze\Score;
use Dev3\AiSiteHealth\Collect\Change_Logger;
use Dev3\AiSiteHealth\Collect\Collector;
use Dev3\AiSiteHealth\Collect\Cron_Health;
use Dev3\AiSiteHealth\Collect\Security_Logger;
use Dev3\AiSiteHealth\Collect\Site_Health;
use Dev3\AiSiteHealth\Collect\Slow_Queries;
use Dev3\AiSiteHealth\Collect\Updates;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

class CollectorsTest extends Dev3sh_Base_Case {

	public function test_issue_grouping_regression_and_stale_resolution() {
		$data = array(
			'source'   => 'php',
			'kind'     => 'warning',
			'severity' => 'medium',
			'title'    => 'PHP warning: x',
			'message'  => 'Undefined index: 5 at row 77',
			'file'     => 'wp-content/plugins/a/a.php',
			'line'     => 3,
			'time'     => time() - 3 * DAY_IN_SECONDS,
			'sample'   => 'one',
		);
		$id   = Issues::record( $data );
		$this->assertSame( $id, Issues::record( array_merge( $data, array( 'message' => 'Undefined index: 9 at row 12', 'time' => time(), 'sample' => 'two' ) ) ) );
		$issue = Issues::get( $id );
		$this->assertSame( 2, $issue['count'] );
		$this->assertSame( array( 'two', 'one' ), $issue['context']['samples'] );
		$this->assertLessThan( strtotime( $issue['last_seen'] ), strtotime( $issue['first_seen'] ) );
		$this->assertContains( $id, Issues::$created );

		Issues::set_status( $id, 'resolved' );
		Issues::record( $data );
		$issue = Issues::get( $id );
		$this->assertSame( 'open', $issue['status'] );
		$this->assertTrue( $issue['regressed'] );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'dev3sh_issues', array( 'last_seen' => gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), array( 'id' => $id ) );
		$this->assertSame( 1, Issues::resolve_stale( 14 ) );
		$this->assertSame( 'resolved', Issues::get( $id )['status'] );
	}

	public function test_score_weights_and_caps() {
		$this->assertSame( 100, Score::compute( array() ) );
		$typical = array(
			array( 'source' => 'updates', 'severity' => 'medium', 'component' => 'plugin:a' ),
			array( 'source' => 'updates', 'severity' => 'medium', 'component' => 'plugin:b' ),
			array( 'source' => 'site_health', 'severity' => 'low', 'component' => '' ),
			array( 'source' => 'php', 'severity' => 'low', 'component' => 'plugin:a' ),
		);
		$this->assertSame( 95, Score::compute( $typical ) );
		$this->assertSame( 'good', Score::grade( 95 ) );
		$critical = array_merge( $typical, array( array( 'source' => 'php', 'severity' => 'critical', 'component' => 'plugin:a' ) ) );
		$this->assertSame( 69, Score::compute( $critical ), 'Never "good" while a critical issue is open' );
		$noisy = array_fill( 0, 50, array( 'source' => 'php', 'severity' => 'high', 'component' => 'plugin:noisy' ) );
		$this->assertSame( 85, Score::compute( $noisy ), 'One source adds at most 30 penalty points' );
		$sick = array();
		foreach ( array( 'php', 'security', 'integrity', 'site_health', 'updates', 'cron', 'query', 'change' ) as $src ) {
			$sick = array_merge( $sick, array_fill( 0, 5, array( 'source' => $src, 'severity' => 'high', 'component' => '' ) ) );
		}
		$this->assertSame( 26, Score::compute( $sick ) );
		$this->assertSame( 'poor', Score::grade( 26 ) );
		$h = Score::snapshot( 77 );
		$this->assertSame( 77, end( $h ) );
	}

	public function test_request_rules() {
		$this->assertSame( 'traversal', Security_Logger::match( '/index.php?file=../../wp-config.php' ) );
		$this->assertSame( 'system_file', Security_Logger::match( '/?page=%2e%2e%2f%2e%2e%2fetc%2fpasswd' ) );
		$this->assertSame( 'sqli', Security_Logger::match( '/?id=1%20UNION%20SELECT%20user_pass%20FROM%20wp_users' ) );
		$this->assertSame( 'sqli', Security_Logger::match( "/?s=1' or '1'='1" ) );
		$this->assertSame( 'xss', Security_Logger::match( '/?q=<script>alert(1)</script>' ) );
		$this->assertSame( 'sensitive_file', Security_Logger::match( '/.env' ) );
		$this->assertSame( 'sensitive_file', Security_Logger::match( '/wp-config.php.bak' ) );
		$this->assertSame( 'sensitive_file', Security_Logger::match( '/.git/config' ) );
		$this->assertSame( 'author_enum', Security_Logger::match( '/?author=1' ) );
		$this->assertSame( '', Security_Logger::match( '/?author=1', true ), 'Logged-in users may browse author archives' );
		$this->assertSame( 'rest_user_enum', Security_Logger::match( '/wp-json/wp/v2/users' ) );
		foreach ( array( '/', '/shop/blue-shirt/?color=blue', '/blog/union-station-selects-new-chef/', '/wp-admin/admin-ajax.php', '/?s=select+a+union+jacket' ) as $ok ) {
			$this->assertSame( '', Security_Logger::match( $ok ), $ok );
		}
	}

	public function test_suspicious_requests_are_logged_and_capped() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertGreaterThan( 0, Security_Logger::log_suspicious( 'sensitive_file', '/.env' ) );
		add_filter( 'dev3sh_security_writes_per_minute', static fn() => 2 );
		Security_Logger::log_suspicious( 'sensitive_file', '/.env.bak' );
		$this->assertSame( 0, Security_Logger::log_suspicious( 'sensitive_file', '/.env.old' ), 'Write cap per IP' );
		$this->assertSame( 2, Events::count( 'suspicious', time() - 60 ) );
		$issue = Issues::query( array( 'source' => 'security' ) )['items'][0];
		$this->assertSame( 'suspicious_sensitive_file', $issue['kind'] );
		$this->assertSame( 2, $issue['count'] );
		$x = Knowledge_Base::explain( $issue );
		$this->assertStringContainsString( '.env', $x['summary'] );
	}

	public function test_failed_logins_brute_force_and_success_after_failures() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
		Settings_Store::update( array( 'brute_force_threshold' => 5, 'alert_failed_logins' => 0 ) );
		$user = self::factory()->user->create( array( 'user_login' => 'shopowner', 'role' => 'administrator' ) );
		for ( $i = 0; $i < 5; $i++ ) {
			do_action( 'wp_login_failed', 0 === $i ? 'nobody_here' : 'shopowner', new WP_Error( 'incorrect_password' ) );
		}
		$this->assertSame( 5, Events::count( 'login_failed', time() - 60 ) );
		$events = Events::query( array( 'type' => 'login_failed' ) )['items'];
		$this->assertSame( '203.0.113.50', $events[0]['ip'] );
		$this->assertTrue( $events[0]['meta']['is_admin'] );
		$this->assertFalse( end( $events )['meta']['exists'] );
		$issues = wp_list_pluck( Issues::query( array( 'source' => 'security' ) )['items'], 'count', 'kind' );
		$this->assertSame( 5, $issues['login_failed'] );
		$this->assertArrayHasKey( 'brute_force', $issues );
		$brute = wp_list_filter( Issues::query( array( 'source' => 'security' ) )['items'], array( 'kind' => 'brute_force' ) );
		$this->assertSame( '203.0.113.50', reset( $brute )['context']['details']['ip'] );

		do_action( 'wp_login', 'shopowner', get_user_by( 'id', $user ) );
		$ok = Events::query( array( 'type' => 'login_ok' ) )['items'][0];
		$this->assertSame( 'after_failures', $ok['rule'] );
		$this->assertSame( 5, $ok['meta']['failures'] );

		$sum = Security_Logger::summary();
		$this->assertSame( 5, $sum['failed_24h'] );
		$this->assertSame( '203.0.113.50', $sum['top_ips'][0]['value'] );
	}

	public function test_anonymized_ips_and_retention() {
		Settings_Store::update( array( 'anonymize_ip' => true ) );
		Events::add( 'login_failed', array( 'ip' => '192.0.2.77' ) );
		$this->assertSame( '192.0.2.0', Events::query( array() )['items'][0]['ip'] );
		Events::add( 'suspicious', array( 'time' => time() - 40 * DAY_IN_SECONDS ) );
		Events::add( 'fix', array( 'time' => time() - 40 * DAY_IN_SECONDS ) );
		$this->assertSame( 1, Events::prune( 30 ), 'Fix history is kept for undo' );
	}

	public function test_change_log_and_snapshot_diff() {
		$this->admin();
		Change_Logger::save_snapshot();
		do_action( 'activated_plugin', 'hello.php', false );
		do_action( 'deactivated_plugin', 'hello.php', false );
		$changes = Events::query( array( 'type' => 'change' ) )['items'];
		$this->assertSame( array( 'plugin_deactivated', 'plugin_activated' ), wp_list_pluck( $changes, 'rule' ) );
		$this->assertNotEmpty( $changes[0]['user_login'] );

		// Simulate an FTP change: the stored snapshot has an older version of a plugin.
		$snap = get_option( Change_Logger::SNAPSHOT );
		$file = array_key_first( $snap['plugins'] );
		$snap['plugins'][ $file ]['v'] = '0.0.1';
		update_option( Change_Logger::SNAPSHOT, $snap );
		$diff = Change_Logger::diff();
		$this->assertSame( 'plugin_version_changed', $diff[0]['rule'] );
		$this->assertSame( 1, Issues::query( array( 'source' => 'change' ) )['total'] );
		$this->assertSame( array(), Change_Logger::diff(), 'Reported once' );
	}

	public function test_updates_become_state_issues_and_resolve() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugins = get_plugins();
		$file    = array_key_first( $plugins );
		set_site_transient(
			'update_plugins',
			(object) array(
				'last_checked' => time(),
				'response'     => array( $file => (object) array( 'new_version' => '99.0' ) ),
			)
		);
		set_site_transient(
			'update_core',
			(object) array(
				'updates' => array(
					(object) array(
						'response' => 'upgrade',
						'current'  => '99.1',
					),
				),
			)
		);
		$r = Updates::collect( Updates::status( false ) );
		$this->assertSame( 2, $r['pending'] );
		$kinds = wp_list_pluck( Issues::query( array( 'source' => 'updates' ) )['items'], 'kind' );
		$this->assertContains( 'plugin_update', $kinds );
		$this->assertContains( 'core_update', $kinds );

		set_site_transient( 'update_plugins', (object) array( 'last_checked' => time(), 'response' => array() ) );
		delete_site_transient( 'update_core' );
		Updates::collect( Updates::status( false ) );
		$this->assertSame( 0, Issues::query( array( 'source' => 'updates', 'kind' => 'plugin_update' ) )['total'] );
		$this->assertSame( 2, Issues::query( array( 'source' => 'updates', 'status' => 'resolved' ) )['total'] );
	}

	public function test_cron_overdue_and_orphans() {
		wp_schedule_single_event( time() - 2 * HOUR_IN_SECONDS, 'dev3sh_test_orphan_hook' );
		add_action( 'dev3sh_test_handled_hook', '__return_true' );
		wp_schedule_single_event( time() - 3 * HOUR_IN_SECONDS, 'dev3sh_test_handled_hook' );
		$s = Cron_Health::status();
		$this->assertGreaterThanOrEqual( 2, $s['overdue_count'] );
		$this->assertContains( 'dev3sh_test_orphan_hook', wp_list_pluck( $s['orphans'], 'hook' ) );
		$this->assertNotContains( 'dev3sh_test_handled_hook', wp_list_pluck( $s['orphans'], 'hook' ) );
		Cron_Health::collect();
		$kinds = wp_list_pluck( Issues::query( array( 'source' => 'cron' ) )['items'], 'kind' );
		$this->assertContains( 'overdue', $kinds );
		$this->assertContains( 'orphan', $kinds );
		wp_clear_scheduled_hook( 'dev3sh_test_orphan_hook' );
		wp_clear_scheduled_hook( 'dev3sh_test_handled_hook' );
	}

	public function test_slow_queries_are_normalised_and_grouped() {
		$this->assertSame( 'SELECT * FROM wp_posts WHERE ID IN (?+) AND post_title = ? LIMIT ?', Slow_Queries::normalize( "SELECT * FROM wp_posts WHERE ID IN (1, 2, 3) AND post_title = 'Hello' LIMIT 10" ) );
		Settings_Store::update( array( 'slow_query_ms' => 100 ) );
		$n = Slow_Queries::capture(
			array(
				array( "SELECT * FROM wp_postmeta WHERE meta_value = 'a'", 0.4, "require('wp-blog-header.php'), Acme_Report->run" ),
				array( "SELECT * FROM wp_postmeta WHERE meta_value = 'b'", 1.2, "require('wp-blog-header.php'), Acme_Report->run" ),
				array( 'SELECT 1', 0.001, 'x' ),
				array( 'INSERT INTO wp_dev3sh_events ...', 2.0, 'x' ),
			)
		);
		$this->assertSame( 2, $n );
		if ( Slow_Queries::available() ) {
			$r = Slow_Queries::collect();
			$this->assertSame( 1, $r['issues'], 'Same query shape = one issue' );
			$issue = Issues::query( array( 'source' => 'query' ) )['items'][0];
			$this->assertSame( 2, $issue['count'] );
		} else {
			$this->assertSame( 'off', Slow_Queries::collect()['status'] );
		}
	}

	public function test_site_health_results_become_issues() {
		add_filter(
			'site_status_tests',
			static function () {
				return array(
					'direct' => array(
						'dev3sh_fake_bad'  => array(
							'label' => 'Fake bad',
							'test'  => static function () {
								return array(
									'label'       => 'Your site is missing something',
									'status'      => 'critical',
									'badge'       => array( 'label' => 'Security' ),
									'description' => '<p>Something important is missing.</p>',
									'actions'     => '<p>Do this first.</p><p>Then that.</p>',
									'test'        => 'dev3sh_fake_bad',
								);
							},
						),
						'dev3sh_fake_good' => array(
							'label' => 'Fake good',
							'test'  => static function () {
								return array(
									'label'  => 'All good',
									'status' => 'good',
									'test'   => 'dev3sh_fake_good',
								);
							},
						),
					),
					'async'  => array(),
				);
			},
			999
		);
		$r = Site_Health::collect( true );
		$this->assertSame( 2, $r['tests'] );
		$this->assertSame( 1, $r['issues'] );
		$issue = Issues::query( array( 'source' => 'site_health' ) )['items'][0];
		$this->assertSame( 'high', $issue['severity'] );
		$x = Knowledge_Base::explain( Issues::get( $issue['id'] ) );
		$this->assertSame( array( 'Do this first.', 'Then that.' ), $x['steps'] );
	}

	public function test_collector_run_records_status_and_respects_switches() {
		$this->log_file( '' );
		Settings_Store::update(
			array(
				'collectors' => array(
					'site_health' => false,
					'integrity'   => false,
				),
			)
		);
		$r = Collector::run();
		$this->assertSame( 'off', $r['results']['site_health']['status'] );
		$this->assertSame( 'ok', $r['results']['php']['status'] );
		$this->assertArrayHasKey( 'updates', $r['results'] );
		$status = Collector::status();
		$this->assertSame( 'off', $status['integrity']['status'] );
		$this->assertIsInt( $r['score'] );
		$this->assertNotEmpty( get_option( 'dev3sh_last_scan' ) );
	}
}
