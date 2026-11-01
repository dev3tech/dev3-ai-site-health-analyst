<?php
/**
 * Standard admin routes every DEV3 plugin shares: settings, key validation, models, usage, AI cache and jobs.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Rest;

use Dev3\AiSiteHealth\Core3\Api\Ai;
use Dev3\AiSiteHealth\Core3\Api\Gemini_Client;
use Dev3\AiSiteHealth\Core3\Core\Cache;
use Dev3\AiSiteHealth\Core3\Core\Capabilities;
use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store as Settings;
use Dev3\AiSiteHealth\Core3\Data\Usage_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under dev3sh/v1 (renamed per plugin).
 */
class Core_Controller extends Base_Controller {

	const MODELS_TRANSIENT = 'dev3sh_models';

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$manage = array( $this, 'can_manage' );
		$routes = array(
			'/settings'          => array(
				array( \WP_REST_Server::READABLE, fn() => $this->respond( Settings::public_settings() ) ),
				array( \WP_REST_Server::EDITABLE, array( $this, 'save_settings' ) ),
			),
			'/settings/validate' => array( array( \WP_REST_Server::CREATABLE, array( $this, 'validate_key' ) ) ),
			'/models'            => array( array( \WP_REST_Server::READABLE, array( $this, 'models' ) ) ),
			'/usage'             => array( array( \WP_REST_Server::READABLE, fn( \WP_REST_Request $r ) => $this->respond( Usage_Repository::summary( max( 1, min( 365, (int) ( $r->get_param( 'days' ) ? $r->get_param( 'days' ) : 30 ) ) ) ) ) ) ),
			'/usage/recent'      => array( array( \WP_REST_Server::READABLE, fn( \WP_REST_Request $r ) => $this->respond( Usage_Repository::recent( max( 1, (int) ( $r->get_param( 'page' ) ? $r->get_param( 'page' ) : 1 ) ) ) ) ) ),
			'/cache'             => array(
				array( \WP_REST_Server::READABLE, fn() => $this->respond( array( 'count' => Cache::count() ) ) ),
				array( \WP_REST_Server::DELETABLE, fn() => $this->respond( array( 'deleted' => Cache::flush() ) ) ),
			),
		);
		foreach ( $routes as $path => $endpoints ) {
			$args = array();
			foreach ( $endpoints as $endpoint ) {
				$args[] = array(
					'methods'             => $endpoint[0],
					'callback'            => $endpoint[1],
					'permission_callback' => $manage,
				);
			}
			register_rest_route( self::NS, $path, $args );
		}

		// Readable by anyone who may generate (UsageMeter, AI buttons show the paused state).
		register_rest_route(
			self::NS,
			'/usage/month',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => fn() => $this->respond( Ai::status() ),
				'permission_callback' => array( $this, 'can_generate' ),
			)
		);

		$job = array( 'id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) );
		register_rest_route(
			self::NS,
			'/jobs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'jobs' ),
				'permission_callback' => $manage,
			)
		);
		foreach ( array(
			''        => array( \WP_REST_Server::READABLE, 'job' ),
			'/cancel' => array( \WP_REST_Server::CREATABLE, 'job_cancel' ),
			'/retry'  => array( \WP_REST_Server::CREATABLE, 'job_retry' ),
		) as $suffix => $route ) {
			register_rest_route(
				self::NS,
				'/jobs/(?P<id>\d+)' . $suffix,
				array(
					'methods'             => $route[0],
					'callback'            => array( $this, $route[1] ),
					'permission_callback' => array( $this, 'can_access_job' ),
					'args'                => $job,
				)
			);
		}
	}

	/**
	 * Job routes: the user who started the job, or a manager.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_access_job( \WP_REST_Request $request ) {
		$ok = $this->can_generate( $request );
		if ( true !== $ok ) {
			return $ok;
		}
		$job = Jobs::get( (int) $request['id'] );
		if ( ! $job ) {
			return new \WP_Error( 'dev3sh_job_missing', __( 'Job not found.', 'dev3-ai-site-health-analyst' ), array( 'status' => 404 ) );
		}
		if ( get_current_user_id() !== (int) $job['user_id'] && ! Capabilities::can_manage() ) {
			return new \WP_Error( 'dev3sh_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-site-health-analyst' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * GET /jobs (?type=).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function jobs( \WP_REST_Request $request ) {
		return $this->respond( Jobs::recent( sanitize_key( (string) $request->get_param( 'type' ) ), 20 ) );
	}

	/**
	 * GET /jobs/<id>: status, and a nudge for stalled jobs (WP-Cron off or a crashed run).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function job( \WP_REST_Request $request ) {
		return $this->respond( Jobs::kick( (int) $request['id'] ) );
	}

	/**
	 * POST /jobs/<id>/cancel.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function job_cancel( \WP_REST_Request $request ) {
		return $this->respond( Jobs::cancel( (int) $request['id'] ) );
	}

	/**
	 * POST /jobs/<id>/retry (failed items, or what is left after a cancel).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function job_retry( \WP_REST_Request $request ) {
		return $this->respond( Jobs::retry( (int) $request['id'] ) );
	}

	/**
	 * POST /settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function save_settings( \WP_REST_Request $request ) {
		$old   = Settings::api_key();
		$saved = Settings::update( (array) $request->get_json_params() );
		Usage_Repository::flush();
		if ( Settings::api_key() !== $old ) {
			delete_transient( self::MODELS_TRANSIENT );
		}
		return $this->respond( $saved );
	}

	/**
	 * POST /settings/validate — live test with the typed key or the stored one.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function validate_key( \WP_REST_Request $request ) {
		$key = trim( sanitize_text_field( (string) $request->get_param( 'api_key' ) ) );
		if ( '' === $key || false !== strpos( $key, '•' ) ) {
			$key = Settings::api_key();
		}
		$models = ( new Gemini_Client( $key ) )->list_models();
		if ( is_wp_error( $models ) ) {
			return $this->respond( $models );
		}
		if ( Settings::api_key() === $key ) {
			set_transient( self::MODELS_TRANSIENT, $models, 12 * HOUR_IN_SECONDS );
		}
		return $this->respond(
			array(
				'valid'  => true,
				'models' => $models,
			)
		);
	}

	/**
	 * GET /models (?refresh=1 skips the 12h cache).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function models( \WP_REST_Request $request ) {
		$cached = get_transient( self::MODELS_TRANSIENT );
		if ( is_array( $cached ) && ! $request->get_param( 'refresh' ) ) {
			return $this->respond( $cached );
		}
		$models = ( new Gemini_Client() )->list_models();
		if ( ! is_wp_error( $models ) ) {
			set_transient( self::MODELS_TRANSIENT, $models, 12 * HOUR_IN_SECONDS );
		}
		return $this->respond( $models );
	}
}
