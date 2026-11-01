<?php
/**
 * One-click fixes: read-only default, preview token, explicit confirmation, apply, undo.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Fix\Firewall;
use Dev3\AiSiteHealth\Fix\Fixes;

class FixesTest extends Dev3sh_Base_Case {

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
	}

	public function tear_down() {
		deactivate_plugins( 'hello.php', true );
		parent::tear_down();
	}

	/**
	 * Allows fixes.
	 */
	private function allow() {
		Settings_Store::update( array( 'allow_fixes' => true ) );
	}

	public function test_read_only_by_default() {
		$this->admin();
		$this->assertFalse( Fixes::enabled() );
		$res = $this->rest( 'POST', 'fixes/preview', array( 'type' => 'block_ip', 'target' => '203.0.113.5' ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'dev3sh_fixes_disabled', $res->get_data()['code'] );
		$this->assertSame( 403, $this->rest( 'POST', 'fixes/apply', array( 'token' => 'x', 'confirm' => true ) )->get_status() );
		$this->assertSame( array(), Firewall::all() );
	}

	public function test_confirmation_and_single_use_token() {
		$this->admin();
		$this->allow();
		$plan = $this->rest( 'POST', 'fixes/preview', array( 'type' => 'block_ip', 'target' => '203.0.113.5' ) )->get_data();
		$this->assertNotEmpty( $plan['token'] );
		$this->assertNotEmpty( $plan['effects'] );
		$this->assertFalse( Firewall::is_blocked( '203.0.113.5' ), 'Preview changes nothing' );

		$this->assertSame( 400, $this->rest( 'POST', 'fixes/apply', array( 'token' => $plan['token'] ) )->get_status(), 'No confirm' );
		$this->assertSame( 400, $this->rest( 'POST', 'fixes/apply', array( 'token' => $plan['token'], 'confirm' => 'yes' ) )->get_status(), 'confirm must be true' );
		$this->assertSame( 400, $this->rest( 'POST', 'fixes/apply', array( 'token' => 'forged', 'confirm' => true ) )->get_status() );

		$res = $this->rest( 'POST', 'fixes/apply', array( 'token' => $plan['token'], 'confirm' => true ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( Firewall::is_blocked( '203.0.113.5' ) );
		$this->assertSame( 400, $this->rest( 'POST', 'fixes/apply', array( 'token' => $plan['token'], 'confirm' => true ) )->get_status(), 'Single use' );

		// Another admin cannot use my token.
		$plan2 = Fixes::preview( 'disable_xmlrpc', 'xmlrpc' );
		$this->admin();
		$this->assertWPError( Fixes::apply( $plan2['token'], true ) );

		$history = $this->rest( 'GET', 'fixes' )->get_data();
		$this->assertSame( 1, $history['total'] );
		$this->assertSame( 'block_ip', $history['items'][0]['rule'] );
		$this->assertSame( 200, $this->rest( 'POST', 'fixes/' . $history['items'][0]['id'] . '/undo' )->get_status() );
		$this->assertFalse( Firewall::is_blocked( '203.0.113.5' ) );
		$this->assertSame( 400, $this->rest( 'POST', 'fixes/' . $history['items'][0]['id'] . '/undo' )->get_status(), 'Undo once' );
	}

	public function test_cannot_block_yourself_or_garbage() {
		$this->admin();
		$this->allow();
		$this->assertSame( 'dev3sh_fix_self', Fixes::preview( 'block_ip', '192.0.2.1' )->get_error_code() );
		$this->assertSame( 'dev3sh_fix_target', Fixes::preview( 'block_ip', 'not-an-ip' )->get_error_code() );
		$this->assertSame( 'dev3sh_fix_type', Fixes::preview( 'rm_rf', '/' )->get_error_code() );
	}

	public function test_blocklist_enforcement_and_expiry() {
		Firewall::block( '203.0.113.99' );
		$this->assertTrue( Firewall::is_blocked( '203.0.113.99' ) );
		update_option(
			Firewall::OPTION,
			array(
				'203.0.113.99' => array(
					'added'   => time() - 100,
					'expires' => time() - 1,
					'by'      => 1,
					'reason'  => '',
				),
			)
		);
		$this->assertFalse( Firewall::is_blocked( '203.0.113.99' ), 'Expired entries are dropped' );
	}

	public function test_deactivate_plugin_and_undo() {
		$this->admin();
		$this->allow();
		activate_plugin( 'hello.php' );
		$this->assertTrue( is_plugin_active( 'hello.php' ) );
		$id    = Issues::record(
			array(
				'source'    => 'php',
				'kind'      => 'fatal',
				'severity'  => 'critical',
				'title'     => 'Fatal in Hello Dolly',
				'message'   => 'Uncaught Error: Call to undefined function x()',
				'file'      => 'wp-content/plugins/hello.php',
				'component' => 'plugin:hello',
			)
		);
		$fixes = $this->rest( 'GET', "issues/{$id}" )->get_data()['fixes'];
		$this->assertSame( 'deactivate_plugin', $fixes[0]['type'] );
		$plan = Fixes::preview( 'deactivate_plugin', 'hello', $id );
		$res  = Fixes::apply( $plan['token'], true );
		$this->assertTrue( $res['applied'] );
		$this->assertFalse( is_plugin_active( 'hello.php' ) );
		$event = Events::get( $res['id'] );
		$this->assertSame( $id, $event['meta']['issue_id'] );
		Fixes::undo( $res['id'] );
		$this->assertTrue( is_plugin_active( 'hello.php' ) );
		$this->assertWPError( Fixes::preview( 'deactivate_plugin', 'dev3-ai-site-health-analyst' ) );
	}

	public function test_unschedule_orphan_cron_and_undo() {
		$this->admin();
		$this->allow();
		wp_schedule_event( time() + 600, 'daily', 'dev3sh_test_orphan', array( 'a' ) );
		$plan = Fixes::preview( 'unschedule_cron', 'dev3sh_test_orphan' );
		$res  = Fixes::apply( $plan['token'], true );
		$this->assertFalse( wp_next_scheduled( 'dev3sh_test_orphan', array( 'a' ) ) );
		Fixes::undo( $res['id'] );
		$this->assertNotFalse( wp_next_scheduled( 'dev3sh_test_orphan', array( 'a' ) ) );
		add_action( 'dev3sh_test_orphan', '__return_true' );
		$this->assertWPError( Fixes::preview( 'unschedule_cron', 'dev3sh_test_orphan' ), 'Handled hooks are not removed' );
		wp_clear_scheduled_hook( 'dev3sh_test_orphan', array( 'a' ) );
	}

	public function test_restore_core_file_verifies_checksum_and_keeps_backup() {
		$this->admin();
		$this->allow();
		$rel      = 'wp-includes/dev3sh-restore-test.php';
		$path     = ABSPATH . $rel;
		$official = "<?php\n// official\n";
		file_put_contents( $path, "<?php\n// hacked\n" ); // phpcs:ignore
		$this->routes['api.wordpress.org/core/checksums'] = array( 200, wp_json_encode( array( 'checksums' => array( $rel => md5( $official ) ) ) ) );

		$this->routes['core.svn.wordpress.org'] = array( 200, "<?php\n// tampered download\n" );
		$plan                                   = Fixes::preview( 'restore_core_file', $rel );
		$this->assertSame( 'dev3sh_fix_checksum', Fixes::apply( $plan['token'], true )->get_error_code(), 'Bad download never overwrites' );
		$this->assertStringContainsString( 'hacked', file_get_contents( $path ) ); // phpcs:ignore

		$this->routes['core.svn.wordpress.org'] = array( 200, $official );
		$plan                                   = Fixes::preview( 'restore_core_file', $rel );
		$res                                    = Fixes::apply( $plan['token'], true );
		$this->assertTrue( $res['applied'] );
		$this->assertSame( $official, file_get_contents( $path ) ); // phpcs:ignore
		$this->assertSame( 'dev3sh_fix_target', Fixes::preview( 'restore_core_file', $rel )->get_error_code(), 'Already matches' );
		$this->assertSame( 'dev3sh_fix_target', Fixes::preview( 'restore_core_file', '../wp-config.php' )->get_error_code() );

		Fixes::undo( $res['id'] );
		$this->assertStringContainsString( 'hacked', file_get_contents( $path ) ); // phpcs:ignore
		unlink( $path ); // phpcs:ignore
		$backup = Events::get( $res['id'] )['meta']['undo']['backup'];
		$this->assertFileExists( $backup );
		unlink( $backup ); // phpcs:ignore
	}

	public function test_disable_xmlrpc() {
		$this->admin();
		$this->allow();
		$plan = Fixes::preview( 'disable_xmlrpc', 'xmlrpc' );
		Fixes::apply( $plan['token'], true );
		$this->assertTrue( Settings_Store::get( 'xmlrpc_disabled' ) );
		Firewall::register();
		$this->assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		remove_filter( 'xmlrpc_enabled', '__return_false' );
		remove_filter( 'xmlrpc_methods', '__return_empty_array', 999 );
	}
}
