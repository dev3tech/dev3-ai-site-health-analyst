<?php
/**
 * Alerts, weekly report, REST permissions and screen payloads.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Admin\Admin_Page;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Report\Alerts;
use Dev3\AiSiteHealth\Report\Weekly_Report;

class ReportsRestTest extends Dev3sh_Base_Case {

	/**
	 * Sent mails.
	 *
	 * @return array
	 */
	private function mails() {
		return tests_retrieve_phpmailer_instance()->mock_sent;
	}

	public function test_failed_login_alert_fires_once_per_cooldown() {
		Settings_Store::update(
			array(
				'alert_failed_logins' => 3,
				'alert_emails'        => 'ops@example.com',
			)
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.20';
		for ( $i = 0; $i < 6; $i++ ) {
			do_action( 'wp_login_failed', 'ghost' . $i, null );
		}
		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$this->assertSame( 'ops@example.com', $mails[0]['to'][0][0] );
		$this->assertStringContainsString( 'failed logins in the last hour', $mails[0]['subject'] );
		$this->assertStringContainsString( '203.0.113.20', $mails[0]['body'] );
		$this->assertSame( 1, Events::count( 'alert', time() - 60 ) );
		$this->assertFalse( Alerts::cooled( 'failed_logins' ) );
	}

	public function test_new_fatal_and_critical_alerts_and_switches() {
		Settings_Store::update( array( 'alert_emails' => 'ops@example.com' ) );
		$fatal = Issues::record(
			array(
				'source'   => 'php',
				'kind'     => 'fatal',
				'severity' => 'critical',
				'title'    => 'Fatal error: boom',
				'message'  => 'boom',
			)
		);
		$this->assertSame( array( 'fatal' ), Alerts::check_issues( array( $fatal ) ) );
		$this->assertTrue( Issues::get( $fatal )['notified'] );
		$this->assertSame( array(), Alerts::check_issues( array( $fatal ) ), 'Already notified' );

		Settings_Store::update( array( 'alerts_enabled' => false ) );
		$other = Issues::record(
			array(
				'source'   => 'cron',
				'kind'     => 'x',
				'severity' => 'critical',
				'title'    => 'Critical cron thing',
				'message'  => 'x',
			)
		);
		$this->assertSame( array(), Alerts::check_issues( array( $other ) ) );
		$this->assertCount( 1, $this->mails() );
	}

	public function test_weekly_report_html_plain_and_schedule() {
		Issues::record(
			array(
				'source'   => 'updates',
				'kind'     => 'plugin_update',
				'severity' => 'medium',
				'title'    => 'Update available: Acme 1.0 → 2.0',
				'message'  => 'x',
			)
		);
		Settings_Store::update( array( 'report_emails' => 'boss@example.com, bad-address', 'report_ai_summary' => false ) );
		$data = Weekly_Report::data( false );
		$html = Weekly_Report::html( $data );
		$this->assertStringContainsString( 'Health score', $html );
		$this->assertStringContainsString( 'Update available: Acme 1.0 → 2.0', $html );
		$this->assertStringContainsString( 'Health score', Weekly_Report::plain( $data ) );
		$res = Weekly_Report::send();
		$this->assertTrue( $res['sent'] );
		$this->assertSame( array( 'boss@example.com' ), $res['to'] );
		$mail = $this->mails()[0];
		$this->assertStringContainsString( 'Weekly site health report', $mail['subject'] );
		$this->assertStringContainsString( 'multipart/alternative', implode( "\n", (array) $mail['header'] ) );
		$this->assertStringContainsString( 'Health score', $mail['body'] );
		$this->assertCount( 0, $this->requests, 'No AI call when the AI summary is off' );

		Settings_Store::update( array( 'report_day' => 'friday', 'report_hour' => 7 ) );
		Weekly_Report::schedule();
		$next = wp_next_scheduled( Weekly_Report::CRON );
		$this->assertNotFalse( $next );
		$dt = ( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
		$this->assertSame( 'Friday 07', $dt->format( 'l H' ) );
		Settings_Store::update( array( 'weekly_report' => false ) );
		Weekly_Report::schedule();
		$this->assertFalse( wp_next_scheduled( Weekly_Report::CRON ) );
	}

	public function test_every_route_requires_admin_and_nonce() {
		$routes = array(
			array( 'GET', 'overview' ),
			array( 'POST', 'scan' ),
			array( 'GET', 'issues' ),
			array( 'POST', 'issues/bulk' ),
			array( 'POST', 'issues/explain-job' ),
			array( 'GET', 'issues/1' ),
			array( 'POST', 'issues/1/status' ),
			array( 'GET', 'issues/1/payload' ),
			array( 'POST', 'issues/1/explain' ),
			array( 'POST', 'issues/1/ask' ),
			array( 'GET', 'events' ),
			array( 'GET', 'security' ),
			array( 'POST', 'security/unblock' ),
			array( 'GET', 'log' ),
			array( 'GET', 'integrity' ),
			array( 'POST', 'integrity/run' ),
			array( 'GET', 'system' ),
			array( 'GET', 'report/preview' ),
			array( 'POST', 'report/send' ),
			array( 'GET', 'fixes' ),
			array( 'POST', 'fixes/preview' ),
			array( 'POST', 'fixes/apply' ),
			array( 'POST', 'fixes/1/undo' ),
		);
		foreach ( array( 'subscriber', 'editor' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			foreach ( $routes as $r ) {
				$this->assertContains( $this->rest( $r[0], $r[1] )->get_status(), array( 401, 403 ), "{$role} {$r[0]} {$r[1]}" );
			}
		}
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->rest( 'GET', 'overview' )->get_status() );
		$this->admin();
		$this->assertSame( 403, $this->rest( 'GET', 'overview', array(), false )->get_status(), 'Nonce required' );
		$this->assertCount( 0, $this->requests );
	}

	public function test_admin_screens_return_data() {
		$this->admin();
		$this->log_file( $this->line( 'PHP Warning:  x in ' . WP_PLUGIN_DIR . '/acme/a.php on line 1' ) );
		$scan = $this->rest( 'POST', 'scan', array( 'sources' => array( 'php', 'cron' ) ) );
		$this->assertSame( 200, $scan->get_status() );
		$this->assertSame( array( 'php', 'cron' ), array_keys( $scan->get_data()['results'] ) );

		$o = $this->rest( 'GET', 'overview' )->get_data();
		foreach ( array( 'score', 'history', 'counts', 'collectors', 'top', 'security', 'log', 'ai', 'fixes_enabled' ) as $k ) {
			$this->assertArrayHasKey( $k, $o );
		}
		$this->assertFalse( $o['fixes_enabled'] );

		$list = $this->rest( 'GET', 'issues', array( 'status' => 'open', 'source' => 'php' ) )->get_data();
		$this->assertSame( 1, $list['total'] );
		$this->assertArrayNotHasKey( 'context', $list['items'][0], 'Lists do not ship samples' );
		$id = $list['items'][0]['id'];
		$this->assertSame( 'resolved', $this->rest( 'POST', "issues/{$id}/status", array( 'status' => 'resolved' ) )->get_data()['status'] );
		$this->assertSame( 400, $this->rest( 'POST', "issues/{$id}/status", array( 'status' => 'deleted' ) )->get_status() );
		$this->assertSame( 1, $this->rest( 'POST', 'issues/bulk', array( 'ids' => array( $id ), 'status' => 'ignored' ) )->get_data()['updated'] );
		$this->assertSame( 404, $this->rest( 'GET', 'issues/999999' )->get_status() );

		foreach ( array( 'events', 'security', 'log', 'integrity', 'system', 'report/preview', 'fixes' ) as $route ) {
			$this->assertSame( 200, $this->rest( 'GET', $route )->get_status(), $route );
		}
		$sys = $this->rest( 'GET', 'system' )->get_data();
		$this->assertArrayHasKey( 'cron', $sys );
		$this->assertArrayHasKey( 'site_health', $sys );
		$this->assertStringContainsString( '<html', $this->rest( 'GET', 'report/preview' )->get_data()['html'] );
	}

	public function test_dashboard_widget_renders() {
		$this->admin();
		ob_start();
		( new Admin_Page() )->render_widget();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Health score', $html );
		$this->assertStringContainsString( 'Open DEV3 AI Site Health', $html );
	}

	public function test_privacy_export_and_erase_of_security_events() {
		$user = self::factory()->user->create( array( 'user_email' => 'kim@example.com', 'user_login' => 'kim' ) );
		Events::add(
			'login_failed',
			array(
				'ip'         => '198.51.100.30',
				'user_login' => 'kim',
				'user_id'    => $user,
				'message'    => 'Wrong password',
			)
		);
		$export = \Dev3\AiSiteHealth\Privacy_Data::export( 'kim@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 1, \Dev3\AiSiteHealth\Privacy_Data::erase( 'kim@example.com' )['items_removed'] );
		$this->assertSame( '', Events::query( array() )['items'][0]['ip'] );
	}
}
