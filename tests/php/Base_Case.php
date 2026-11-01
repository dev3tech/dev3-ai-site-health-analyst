<?php
/**
 * Shared fixtures: fake HTTP (Gemini + wordpress.org), temp log files, REST helpers.
 *
 * @package Dev3\AiSiteHealth
 */

use Dev3\AiSiteHealth\Ai\Prompt_Library;
use Dev3\AiSiteHealth\Analyze\Redactor;
use Dev3\AiSiteHealth\Collect\Php_Log;
use Dev3\AiSiteHealth\Core3\Api\Ai;
use Dev3\AiSiteHealth\Core3\Api\Prompts;
use Dev3\AiSiteHealth\Core3\Core\Cache;
use Dev3\AiSiteHealth\Core3\Core\Schema;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Core3\Data\Usage_Repository;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

abstract class Dev3sh_Base_Case extends WP_UnitTestCase {

	/**
	 * Captured Gemini requests ({ url, body }).
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Captured non-Gemini requests (urls).
	 *
	 * @var array
	 */
	protected $other_requests = array();

	/**
	 * Fake Gemini responses ([ status, body ]); the last one repeats.
	 *
	 * @var array
	 */
	protected $responses = array();

	/**
	 * Fake responses for other URLs: substring => [ status, body string ].
	 *
	 * @var array
	 */
	protected $routes = array();

	/**
	 * Temp files to delete.
	 *
	 * @var string[]
	 */
	protected $tmp = array();

	public function set_up() {
		parent::set_up();
		Schema::install( DEV3SH_DB_VERSION );
		update_option( Settings_Store::OPTION, array() );
		Settings_Store::flush();
		Settings_Store::update(
			array(
				'api_key'           => 'AIza-test-key-000000000000',
				'site_health_async' => false,
			)
		);
		Usage_Repository::flush();
		Cache::reset_memo();
		Ai::$client = null;
		Prompts::reset();
		Prompt_Library::register();
		Redactor::reset();
		Issues::clear();
		Events::clear();
		foreach ( array( Php_Log::STATE, 'dev3sh_alert_state', 'dev3sh_blocklist', 'dev3sh_snapshot', 'dev3sh_score_history', 'dev3sh_integrity', 'dev3sh_collector_status' ) as $opt ) {
			delete_option( $opt );
		}
		delete_transient( 'dev3sh_slow_queries' );
		$this->requests       = array();
		$this->other_requests = array();
		$this->responses      = array();
		$this->routes         = array();
		reset_phpmailer_instance();
		add_filter( 'dev3sh_retry_delay_ms', '__return_zero' );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
		add_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		remove_filter( 'dev3sh_retry_delay_ms', '__return_zero' );
		remove_filter( 'wp_mail_from', array( $this, 'mail_from' ) );
		remove_all_filters( 'dev3sh_log_path' );
		foreach ( $this->tmp as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file ); // phpcs:ignore
			}
		}
		parent::tear_down();
	}

	public function mail_from() {
		return 'wordpress@example.org';
	}

	/**
	 * Fake HTTP.
	 *
	 * @param mixed  $pre  Short-circuit.
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function fake_http( $pre, $args, $url ) {
		if ( false !== strpos( $url, 'generativelanguage.googleapis.com' ) ) {
			$this->requests[] = array(
				'url'  => $url,
				'raw'  => (string) $args['body'],
				'body' => json_decode( (string) $args['body'], true ),
			);
			$next             = count( $this->responses ) > 1 ? array_shift( $this->responses ) : ( $this->responses ? $this->responses[0] : $this->json_response( array() ) );
			return $this->http( $next[0], wp_json_encode( $next[1] ) );
		}
		$this->other_requests[] = $url;
		foreach ( $this->routes as $needle => $resp ) {
			if ( false !== strpos( $url, $needle ) ) {
				return $this->http( $resp[0], $resp[1] );
			}
		}
		return $this->http( 404, '{}' );
	}

	/**
	 * All text sent to Gemini in request $i (system + prompt parts), decoded.
	 *
	 * @param int $i Request index.
	 * @return string
	 */
	protected function sent_text( $i = 0 ) {
		$out  = array();
		$walk = static function ( $v ) use ( &$walk, &$out ) {
			if ( is_array( $v ) ) {
				array_map( $walk, $v );
			} elseif ( is_string( $v ) ) {
				$out[] = $v;
			}
		};
		$walk( $this->requests[ $i ]['body'] );
		return implode( "\n", $out );
	}

	/**
	 * HTTP response array.
	 *
	 * @param int    $code Code.
	 * @param string $body Body.
	 * @return array
	 */
	protected function http( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 'x',
			),
			'cookies'  => array(),
		);
	}

	/**
	 * Gemini structured response.
	 *
	 * @param array $data Output.
	 * @return array
	 */
	protected function json_response( array $data ) {
		return array(
			200,
			array(
				'candidates'    => array(
					array(
						'content'      => array( 'parts' => array( array( 'text' => wp_json_encode( $data ) ) ) ),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 300,
					'candidatesTokenCount' => 60,
					'totalTokenCount'      => 360,
				),
			),
		);
	}

	/**
	 * Administrator.
	 *
	 * @return int
	 */
	protected function admin() {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $id );
		}
		wp_set_current_user( $id );
		return $id;
	}

	/**
	 * REST call with a nonce.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param array  $params Params.
	 * @param bool   $nonce  Send a nonce.
	 * @return WP_REST_Response
	 */
	protected function rest( $method, $route, array $params = array(), $nonce = true ) {
		$req = new WP_REST_Request( $method, '/dev3sh/v1/' . ltrim( $route, '/' ) );
		if ( $nonce ) {
			$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( 'GET' === $method ) {
			$req->set_query_params( $params );
		} else {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $params ) );
		}
		return rest_do_request( $req );
	}

	/**
	 * Creates a temp log file and points the collector at it.
	 *
	 * @param string $content Content.
	 * @return string Path.
	 */
	protected function log_file( $content = '' ) {
		$path        = get_temp_dir() . 'dev3sh-test-' . wp_generate_password( 6, false ) . '.log';
		$this->tmp[] = $path;
		$this->tmp[] = $path . '.1';
		file_put_contents( $path, $content ); // phpcs:ignore
		add_filter(
			'dev3sh_log_path',
			static function () use ( $path ) {
				return $path;
			}
		);
		return $path;
	}

	/**
	 * Log line helper.
	 *
	 * @param string $body Body.
	 * @param string $time Time.
	 * @return string
	 */
	protected function line( $body, $time = '25-Oct-2026 10:11:12 UTC' ) {
		return '[' . $time . '] ' . $body . "\n";
	}
}
