<?php
/**
 * DEV3 AI explanations (mocked Gemini): what is sent is masked, answers are validated, no-key fallback.
 *
 * @package Dev3\AiSiteHealth
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiSiteHealth\Ai\Explainer;
use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Report\Weekly_Report;

class AiTest extends Dev3sh_Base_Case {

	/**
	 * An issue full of things that must never leave the site.
	 *
	 * @return int
	 */
	private function leaky_issue() {
		self::factory()->user->create( array( 'user_login' => 'maria_shop', 'display_name' => 'Maria Lopez', 'user_email' => 'maria@clientshop.test' ) );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$abs  = rtrim( ABSPATH, '/' );
		return Issues::record(
			array(
				'source'    => 'php',
				'kind'      => 'fatal',
				'severity'  => 'critical',
				'title'     => 'Fatal error: Uncaught Exception: Stripe error for maria@clientshop.test',
				'message'   => "Uncaught Exception: API call to https://{$host}/wp-json/acme failed with key sk_live_51AbCdEfGhIjKlMn and password=Hunter22 for user maria_shop from 203.0.113.9",
				'file'      => 'wp-content/plugins/acme/gateway.php',
				'line'      => 120,
				'component' => 'plugin:acme',
				'sample'    => "[25-Oct-2026] PHP Fatal error: in {$abs}/wp-content/plugins/acme/gateway.php:120 /home/clientftp/backup/keys.php AIzaSyFAKEFAKEFAKEFAKEFAKEFAKE123 Maria Lopez",
				'details'   => array( 'ip' => '203.0.113.9' ),
			)
		);
	}

	public function test_payload_and_request_body_are_masked() {
		$this->admin();
		$id              = $this->leaky_issue();
		$this->responses = array(
			$this->json_response(
				array(
					'summary'    => 'The Acme payment plugin crashed while talking to Stripe.',
					'cause'      => 'An invalid or expired Stripe key.',
					'impact'     => 'Checkout fails.',
					'steps'      => array( '1. Open Acme settings.', '- Paste a fresh Stripe key.', 'Test a payment.' ),
					'who'        => 'owner',
					'urgency'    => 'now',
					'fix_action' => 'deactivate_plugin',
					'confidence' => 'high',
				)
			),
		);
		$res = $this->rest( 'POST', "issues/{$id}/explain" );
		$this->assertSame( 200, $res->get_status() );
		$this->assertCount( 1, $this->requests );
		$sent = $this->sent_text( 0 ) . $this->requests[0]['raw'];
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 'maria@clientshop.test', 'maria_shop', 'Maria Lopez', 'sk_live_51', 'Hunter22', '203.0.113.9', 'AIzaSyFAKE', 'clientftp', rtrim( ABSPATH, '/' ), $host, get_option( 'admin_email' ) ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $sent, "Leaked: {$secret}" );
		}
		$this->assertStringNotContainsString( 'AIza-test-key', $sent, 'The API key travels in the header, never in the body' );
		$this->assertStringContainsString( 'wp-content/plugins/acme/gateway.php', $sent, 'Relative paths stay useful' );
		$this->assertStringContainsString( '[api-key]', $sent );
		$this->assertStringContainsString( '[user]', $sent );

		$x = $res->get_data();
		$this->assertSame( array( 'Open Acme settings.', 'Paste a fresh Stripe key.', 'Test a payment.' ), $x['steps'] );
		$this->assertSame( 'ai', $x['source'] );
		$this->assertSame( 'none', $x['fix_action'], 'acme is not an installed+active plugin, so the server drops the AI proposal' );
		$this->assertSame( 'The Acme payment plugin crashed while talking to Stripe.', Issues::get( $id )['ai']['summary'] );

		$payload = $this->rest( 'GET', "issues/{$id}/payload" )->get_data();
		$this->assertStringNotContainsString( '203.0.113.9', wp_json_encode( $payload ) );
		$this->assertArrayNotHasKey( 'ip', (array) $payload['issue']['details'] );
		$this->assertSame( array( 'none' ), $payload['actions'] );
	}

	public function test_ask_masks_the_question_and_is_not_stored() {
		$this->admin();
		$id              = $this->leaky_issue();
		$this->responses = array( $this->json_response( array( 'answer' => 'Yes, update it first on a staging copy.' ) ) );
		$res             = $this->rest( 'POST', "issues/{$id}/ask", array( 'question' => 'Can maria@clientshop.test update it? My IP is 198.51.100.4' ) );
		$this->assertSame( 'Yes, update it first on a staging copy.', $res->get_data()['answer'] );
		$this->assertStringNotContainsString( 'maria@clientshop.test', $this->sent_text( 0 ) );
		$this->assertStringNotContainsString( '198.51.100.4', $this->sent_text( 0 ) );
		$this->assertNull( Issues::get( $id )['ai'] );
		$this->assertSame( 400, $this->rest( 'POST', "issues/{$id}/ask", array( 'question' => '  ' ) )->get_status() );
	}

	public function test_incomplete_answers_are_rejected_and_ai_off_falls_back() {
		$this->admin();
		$id              = $this->leaky_issue();
		$this->responses = array( $this->json_response( array( 'summary' => '', 'steps' => array() ) ) );
		$this->assertSame( 'dev3sh_ai_empty', $this->rest( 'POST', "issues/{$id}/explain" )->get_data()['code'] );

		Settings_Store::update( array( 'api_key' => '' ) );
		$this->requests = array();
		$res            = $this->rest( 'POST', "issues/{$id}/explain" );
		$this->assertGreaterThanOrEqual( 400, $res->get_status() );
		$this->assertCount( 0, $this->requests, 'No HTTP call without a key' );
		$detail = $this->rest( 'GET', "issues/{$id}" )->get_data();
		$this->assertSame( 'built-in', $detail['builtin']['source'] );
		$this->assertNotEmpty( $detail['builtin']['steps'] );

		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000', 'ai_enabled' => false ) );
		$this->rest( 'POST', "issues/{$id}/explain" );
		$this->assertCount( 0, $this->requests, 'AI switched off' );
	}

	public function test_bulk_explain_job() {
		$this->admin();
		$this->leaky_issue();
		Issues::record(
			array(
				'source'   => 'cron',
				'kind'     => 'not_running',
				'severity' => 'high',
				'title'    => 'WP-Cron does not run',
				'message'  => 'x',
			)
		);
		$this->responses = array(
			$this->json_response(
				array(
					'summary'    => 'Explained.',
					'cause'      => 'c',
					'steps'      => array( 's' ),
					'who'        => 'host',
					'urgency'    => 'soon',
					'fix_action' => 'none',
				)
			),
		);
		add_filter( 'dev3sh_jobs_use_action_scheduler', '__return_false' );
		$res = $this->rest( 'POST', 'issues/explain-job' );
		$this->assertSame( 201, $res->get_status() );
		$job = Jobs::run( $res->get_data()['id'] );
		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( 2, $job['done'] );
		foreach ( Issues::query( array() )['items'] as $issue ) {
			$this->assertSame( 'Explained.', $issue['ai']['summary'] );
		}
		$this->assertSame( 'dev3sh_nothing_to_explain', $this->rest( 'POST', 'issues/explain-job' )->get_data()['code'] );
	}

	public function test_weekly_ai_summary_uses_masked_figures_and_never_blocks_the_report() {
		$this->admin();
		$this->leaky_issue();
		$this->responses = array( $this->json_response( array( 'summary' => 'Your site had one critical crash this week.' ) ) );
		$data            = Weekly_Report::data( true );
		$this->assertSame( 'Your site had one critical crash this week.', $data['ai_summary'] );
		$this->assertStringNotContainsString( 'maria@clientshop.test', $this->sent_text( 0 ) );
		$this->responses = array( array( 500, array( 'error' => array( 'message' => 'boom' ) ) ) );
		\Dev3\AiSiteHealth\Core3\Core\Cache::flush();
		\Dev3\AiSiteHealth\Core3\Core\Cache::reset_memo();
		$data = Weekly_Report::data( true );
		$this->assertSame( '', $data['ai_summary'] );
		$this->assertStringContainsString( 'Health score', Weekly_Report::html( $data ) );
	}

	public function test_environment_has_no_paths() {
		$env = wp_json_encode( Explainer::environment() );
		$this->assertStringNotContainsString( ABSPATH, $env );
		$this->assertStringContainsString( get_bloginfo( 'version' ), $env );
	}
}
