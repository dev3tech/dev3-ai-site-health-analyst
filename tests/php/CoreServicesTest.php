<?php
/**
 * Core v2 services: embeddings, image generation, cache, cost cap, embeddings store, jobs,
 * prompts and privacy. Gemini is mocked through pre_http_request (no key or network needed).
 *
 * @package Dev3\AiSiteHealth
 */

use Dev3\AiSiteHealth\Core3\Api\Ai;
use Dev3\AiSiteHealth\Core3\Api\Gemini_Client;
use Dev3\AiSiteHealth\Core3\Api\Prompts;
use Dev3\AiSiteHealth\Core3\Core\Cache;
use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Core3\Core\Privacy;
use Dev3\AiSiteHealth\Core3\Core\Schema;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Core3\Data\Embeddings_Store;
use Dev3\AiSiteHealth\Core3\Data\Usage_Repository;

class CoreServicesTest extends WP_UnitTestCase {

	/**
	 * Captured outgoing requests.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Queue of fake responses (each: [ status, body array ]); the last one repeats.
	 *
	 * @var array
	 */
	private $responses = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3SH_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update( array( 'api_key' => 'AIza-test-key-000000000000' ) );
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		$this->requests  = array();
		$this->responses = array();
		add_filter( 'dev3sh_retry_delay_ms', '__return_zero' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3sh_retry_delay_ms', '__return_zero' );
		parent::tear_down();
	}

	/**
	 * Fake Gemini.
	 *
	 * @param mixed  $pre  Short-circuit.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array
	 */
	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'body' => json_decode( (string) $args['body'], true ),
		);
		$next = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : array( 200, array() ) );
		if ( is_callable( $next ) ) {
			$next = $next( end( $this->requests ) );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next[1] ),
			'response' => array(
				'code'    => $next[0],
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	private function text_response( $text, $prompt = 100, $output = 50 ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => $text ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => $prompt,
					'candidatesTokenCount' => $output,
					'totalTokenCount'      => $prompt + $output,
				),
			),
		);
	}

	public function test_embed_batches_and_normalises() {
		$this->responses = array(
			static function ( $req ) {
				$out = array();
				foreach ( $req['body']['requests'] as $i => $r ) {
					$out[] = array( 'values' => array( 3.0, 4.0, (float) $i ) );
				}
				return array( 200, array( 'embeddings' => $out ) );
			},
		);
		$texts  = array_map( static fn( $i ) => "text {$i}", range( 1, 150 ) );
		$result = Ai::embed( 'index', $texts, 'RETRIEVAL_DOCUMENT' );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $this->requests, '150 texts = 2 batches of ≤100' );
		$this->assertStringContainsString( 'gemini-embedding-001:batchEmbedContents', $this->requests[0]['url'] );
		$this->assertCount( 100, $this->requests[0]['body']['requests'] );
		$this->assertSame( 'RETRIEVAL_DOCUMENT', $this->requests[0]['body']['requests'][0]['taskType'] );
		$this->assertSame( 768, $this->requests[0]['body']['requests'][0]['outputDimensionality'] );
		$this->assertCount( 150, $result['vectors'] );
		$this->assertEqualsWithDelta( 0.6, $result['vectors'][0][0], 0.0001 );
		$this->assertGreaterThan( 0, $result['usage']['total'] );
		$this->assertSame( 1, (int) Usage_Repository::recent()['total'] );
	}

	public function test_generate_image_returns_base64_and_logs() {
		$this->responses = array(
			array(
				200,
				array(
					'candidates'    => array(
						array(
							'content' => array(
								'parts' => array(
									array( 'text' => 'Here you go' ),
									array(
										'inlineData' => array(
											'mimeType' => 'image/png',
											'data'     => base64_encode( 'png-bytes' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
										),
									),
								),
							),
						),
					),
					'usageMetadata' => array(
						'promptTokenCount'     => 10,
						'candidatesTokenCount' => 1290,
						'totalTokenCount'      => 1300,
					),
				),
			),
		);
		$result = Ai::image( 'banner', 'A red mug', array( 'aspect_ratio' => '16:9' ) );
		$this->assertSame( 'image/png', $result['mime'] );
		$this->assertSame( 'png-bytes', base64_decode( $result['data'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertStringContainsString( 'gemini-2.5-flash-image:generateContent', $this->requests[0]['url'] );
		$this->assertSame( '16:9', $this->requests[0]['body']['generationConfig']['imageConfig']['aspectRatio'] );
		$this->assertContains( 'IMAGE', $this->requests[0]['body']['generationConfig']['responseModalities'] );
		$this->assertSame( 'gemini-2.5-flash-image', Usage_Repository::recent()['items'][0]['model'] );
	}

	public function test_image_without_inline_data_is_an_error() {
		$this->responses = array( $this->text_response( 'I cannot draw that' ) );
		$result          = Ai::image( 'banner', 'x' );
		$this->assertWPError( $result );
		$this->assertSame( 'dev3sh_no_image', $result->get_error_code() );
	}

	public function test_cache_skips_repeat_calls() {
		$this->responses = array( $this->text_response( 'Hello' ) );
		$args            = array(
			'prompt' => 'Say hello',
			'cache'  => HOUR_IN_SECONDS,
		);
		$first           = Ai::run( 'greet', $args );
		Cache::reset_memo();
		$second = Ai::run( 'greet', $args );
		$this->assertSame( 'Hello', $first['text'] );
		$this->assertTrue( $second['cached'] );
		$this->assertCount( 1, $this->requests );
		Ai::run( 'greet', array( 'prompt' => 'Say bye' ) + $args );
		$this->assertCount( 2, $this->requests, 'Different inputs miss the cache' );
		$this->assertGreaterThan( 0, Cache::flush( 'greet' ) );
		Ai::run( 'greet', $args );
		$this->assertCount( 3, $this->requests );
	}

	public function test_monthly_cap_pauses_ai() {
		$this->responses = array( $this->text_response( 'ok', 1000000, 0 ) );
		$this->assertEqualsWithDelta( 0.30, Usage_Repository::estimate_cost( 'gemini-2.5-flash', 1000000, 0 ), 0.0001 );
		$this->assertEqualsWithDelta( 10.0, Usage_Repository::estimate_cost( 'gemini-2.5-pro', 0, 1000000 ), 0.0001 );
		$this->assertEqualsWithDelta( 0.10, Usage_Repository::estimate_cost( 'gemini-2.5-flash-lite-preview', 1000000, 0 ), 0.0001, 'Longest prefix wins' );

		Settings_Store::update( array( 'monthly_cap' => 0.25 ) );
		Usage_Repository::flush();
		$this->assertIsArray( Ai::run( 'one', array( 'prompt' => 'x' ) ) );
		$month = Usage_Repository::month();
		$this->assertTrue( $month['reached'] );
		$this->assertEquals( 100, $month['percent'] );
		$blocked = Ai::run( 'two', array( 'prompt' => 'x' ) );
		$this->assertWPError( $blocked );
		$this->assertSame( 'dev3sh_cap_reached', $blocked->get_error_code() );
		$this->assertCount( 1, $this->requests, 'No HTTP call once the cap is reached' );
		$this->assertTrue( Ai::status()['cap_reached'] );
	}

	public function test_kill_switches_and_fallback() {
		Settings_Store::update( array( 'public_ai' => false ) );
		$this->assertSame( 'dev3sh_public_ai_disabled', Ai::run( 't', array( 'prompt' => 'x' ), 'storefront' )->get_error_code() );
		$this->assertSame( 'fallback', Ai::run_or( 't', array( 'prompt' => 'x' ), 'storefront', static fn() => 'fallback' ) );
		Settings_Store::update( array( 'ai_enabled' => false ) );
		$this->assertSame( 'dev3sh_ai_disabled', Ai::run( 't', array( 'prompt' => 'x' ) )->get_error_code() );
		$this->assertFalse( Ai::enabled() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_retries_rate_limits_with_backoff() {
		$this->responses = array(
			array( 503, array( 'error' => array( 'message' => 'overloaded' ) ) ),
			$this->text_response( 'second time lucky' ),
		);
		$result = Ai::run( 'retry', array( 'prompt' => 'x' ) );
		$this->assertSame( 'second time lucky', $result['text'] );
		$this->assertCount( 2, $this->requests );

		$this->requests  = array();
		$this->responses = array( array( 503, array( 'error' => array( 'message' => 'overloaded' ) ) ) );
		$this->assertWPError( Ai::run( 'retry', array( 'prompt' => 'x' ), 'storefront' ) );
		$this->assertCount( 1, $this->requests, 'Visitor-facing calls fail fast' );

		$this->requests  = array();
		$this->responses = array( array( 429, array( 'error' => array( 'message' => 'Quota exceeded: requests per day' ) ) ) );
		$this->assertWPError( Ai::run( 'retry', array( 'prompt' => 'x' ) ) );
		$this->assertCount( 1, $this->requests, 'Daily quota is not retried' );
	}

	public function test_function_calls_are_parsed() {
		$this->responses = array(
			array(
				200,
				array(
					'candidates' => array(
						array(
							'content' => array(
								'parts' => array(
									array(
										'functionCall' => array(
											'name' => 'find_products',
											'args' => array( 'color' => 'red' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
		$result = Ai::run(
			'search',
			array(
				'prompt' => 'red mugs',
				'tools'  => array(
					array(
						'name'       => 'find_products',
						'parameters' => array( 'type' => 'OBJECT' ),
					),
				),
			)
		);
		$this->assertSame( 'find_products', $result['function_calls'][0]['name'] );
		$this->assertSame( 'red', $result['function_calls'][0]['args']['color'] );
		$this->assertSame( 'find_products', $this->requests[0]['body']['tools'][0]['functionDeclarations'][0]['name'] );
	}

	public function test_embeddings_store_search_and_stale() {
		$store = Embeddings_Store::instance();
		$store->upsert( 'product', 1, array( 1, 0, 0 ), 'h1' );
		$store->upsert( 'product', 2, array( 0.9, 0.1, 0 ), 'h2' );
		$store->upsert( 'product', 3, array( 0, 1, 0 ), 'h3' );
		$store->upsert( 'post', 9, array( 1, 0, 0 ), 'h9' );
		$store->upsert( 'product', 3, array( 0, 0, 1 ), 'h3b' );

		$this->assertSame( 3, $store->count( 'product' ) );
		$hits = $store->search( array( 1, 0, 0 ), 'product', 2 );
		$this->assertSame( array( 1, 2 ), wp_list_pluck( $hits, 'object_id' ) );
		$this->assertEqualsWithDelta( 1.0, $hits[0]['score'], 0.0001 );
		$this->assertSame( array( 2 ), wp_list_pluck( $store->search( array( 1, 0, 0 ), 'product', 5, array( 'exclude' => array( 1 ), 'min_score' => 0.5 ) ), 'object_id' ) );

		$this->assertSame( array( 2, 4 ), $store->stale( 'product', array( 1 => 'h1', 2 => 'changed', 3 => 'h3b', 4 => 'new' ) ) );
		$this->assertTrue( $store->is_stale( 'product', 3, 'h3' ) );
		$this->assertSame( array( 3 ), $store->orphans( 'product', array( 1, 2 ) ) );
		$this->assertEqualsWithDelta( 1.0, $store->get( 'product', 3 )['vector'][2], 0.0001 );
		$this->assertSame( 1, $store->delete( 'product', 2 ) );
		$this->assertSame( 2, $store->delete( 'product' ) );
		$this->assertSame( 1, $store->count( 'post' ) );
		$this->assertEqualsWithDelta( 0.0, Embeddings_Store::cosine( array( 1, 0 ), array( 0, 1 ) ), 0.0001 );
	}

	public function test_jobs_run_in_chunks_with_retry_and_cancel() {
		$seen = array();
		Jobs::register(
			'dev3sh_test_job',
			static function ( array $items ) use ( &$seen ) {
				$out = array();
				foreach ( $items as $item ) {
					$seen[]       = $item;
					$out[ $item ] = 3 === $item ? new WP_Error( 'x', 'Item 3 failed' ) : true;
				}
				return $out;
			}
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$id = Jobs::create( 'dev3sh_test_job', array( 1, 2, 3, 4, 5 ), array( 'a' => 1 ), 2 );
		$this->assertIsInt( $id );
		$this->assertSame( 'queued', Jobs::get( $id )['status'] );
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( $id ) ), 'WP-Cron fallback without Action Scheduler' );

		$job = Jobs::run( $id );
		$this->assertSame( 'done', $job['status'] );
		$this->assertSame( array( 1, 2, 3, 4, 5 ), $seen );
		$this->assertSame( 4, $job['done'] );
		$this->assertSame( 1, $job['failed'] );
		$this->assertSame( 'Item 3 failed', $job['errors'][0]['message'] );
		$this->assertSame( 100, $job['percent'] );

		$retry = Jobs::retry( $id );
		$this->assertSame( 'queued', $retry['status'] );
		$this->assertSame( 1, $retry['total'] );
		$this->assertSame( 1, Jobs::run( $id )['failed'] );

		$id2 = Jobs::create( 'dev3sh_test_job', range( 10, 20 ), array(), 1 );
		$this->assertSame( 'cancelled', Jobs::cancel( $id2 )['status'] );
		$this->assertSame( 'cancelled', Jobs::run( $id2 )['status'], 'Cancelled jobs do not run' );
		$this->assertSame( 11, Jobs::retry( $id2 )['total'], 'Resume re-queues what was left' );
		$this->assertWPError( Jobs::create( 'nope', array( 1 ) ) );
	}

	public function test_jobs_time_budget_reschedules() {
		Jobs::register(
			'dev3sh_slow_job',
			static function () {
				usleep( 1100000 );
				return array();
			}
		);
		$id  = Jobs::create( 'dev3sh_slow_job', array( 1, 2, 3 ), array(), 1 );
		$job = Jobs::run( $id, 1 );
		$this->assertSame( 'running', $job['status'] );
		$this->assertSame( 1, $job['processed'] );
		$this->assertNotFalse( wp_next_scheduled( Jobs::HOOK, array( $id ) ) );
	}

	public function test_job_rest_routes_check_ownership() {
		Jobs::register( 'dev3sh_test_job', static fn( $items ) => array() );
		do_action( 'rest_api_init' );
		$owner = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $owner );
		$id  = Jobs::create( 'dev3sh_test_job', array( 1, 2 ) );
		$req = new WP_REST_Request( 'GET', '/dev3sh/v1/jobs/' . $id );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $id, $res->get_data()['id'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 403, rest_do_request( $req )->get_status(), 'Another editor cannot see the job' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$cancel = new WP_REST_Request( 'POST', '/dev3sh/v1/jobs/' . $id . '/cancel' );
		$cancel->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertSame( 'cancelled', rest_do_request( $cancel )->get_data()['status'] );

		$month = new WP_REST_Request( 'GET', '/dev3sh/v1/usage/month' );
		$month->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$this->assertArrayHasKey( 'month', rest_do_request( $month )->get_data() );
	}

	public function test_prompts_render_and_filter() {
		Prompts::register(
			'title',
			array(
				'version'  => '3',
				'system'   => 'You write titles for {{site}}.',
				'template' => 'Product: {{name}} / tags: {{tags}} / {{missing}}',
				'cache'    => 60,
			)
		);
		$args = Prompts::build(
			'title',
			array(
				'name' => 'Mug',
				'tags' => array( 'red' ),
				'site' => 'Shop',
			)
		);
		$this->assertSame( 'Product: Mug / tags: ["red"] / ', $args['prompt'] );
		$this->assertSame( 'You write titles for Shop.', $args['system'] );
		$this->assertSame( 60, $args['cache'] );

		add_filter(
			'dev3sh_prompt_title',
			static function ( $p ) {
				$p['template'] = 'Override {{name}}';
				$p['version']  = '3-custom';
				return $p;
			}
		);
		$this->responses = array( $this->text_response( 'A title' ) );
		$result          = Prompts::run( 'title', array( 'name' => 'Mug' ) );
		$this->assertSame( '3-custom', $result['prompt_version'] );
		$this->assertSame( 'Override Mug', $this->requests[0]['body']['contents'][0]['parts'][0]['text'] );
		$this->assertWPError( Prompts::build( 'unknown' ) );
	}

	public function test_pii_masking() {
		$masked = Privacy::mask( 'Mail jane@example.com or call +1 (555) 123-4567, card 4242 4242 4242 4242, ip 10.0.0.1 on 2026-10-25, order 12345.' );
		$this->assertStringNotContainsString( 'jane@example.com', $masked );
		$this->assertStringNotContainsString( '4242 4242', $masked );
		$this->assertStringNotContainsString( '555', $masked );
		$this->assertStringContainsString( '[email]', $masked );
		$this->assertStringContainsString( '2026-10-25', $masked, 'Dates are kept' );
		$this->assertStringContainsString( '12345', $masked, 'Short numbers are kept' );

		Settings_Store::update( array( 'pii_masking' => true ) );
		$this->responses = array( $this->text_response( 'ok' ) );
		Ai::run( 'mask', array( 'prompt' => 'Contact bob@example.org' ) );
		$this->assertSame( 'Contact [email]', $this->requests[0]['body']['contents'][0]['parts'][0]['text'] );
	}

	public function test_privacy_exporter_and_eraser() {
		$user = self::factory()->user->create( array( 'user_email' => 'pat@example.com' ) );
		wp_set_current_user( $user );
		$this->responses = array( $this->text_response( 'ok' ) );
		Ai::run( 'export_me', array( 'prompt' => 'x' ) );
		$export = Privacy::export_usage( 'pat@example.com' );
		$this->assertCount( 1, $export['data'] );
		$this->assertTrue( $export['done'] );
		$this->assertSame( 1, Privacy::erase_usage( 'pat@example.com' )['items_removed'] );
		$this->assertCount( 0, Privacy::export_usage( 'pat@example.com' )['data'] );
		$this->assertArrayHasKey( DEV3SH_SLUG . '-ai-usage-log', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertStringContainsString( 'Google Gemini', Privacy::policy_text() );
	}

	public function test_schema_version_includes_core_version() {
		$this->assertSame( DEV3SH_DB_VERSION . '+core' . Schema::CORE_VERSION, get_option( Schema::VERSION_OPTION ) );
		update_option( Schema::VERSION_OPTION, DEV3SH_DB_VERSION );
		Schema::maybe_install( DEV3SH_DB_VERSION );
		$this->assertSame( Schema::stamp( DEV3SH_DB_VERSION ), get_option( Schema::VERSION_OPTION ), 'Old stamps trigger a core table upgrade' );
	}
}
