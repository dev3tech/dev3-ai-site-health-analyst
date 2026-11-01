<?php
/**
 * Shared REST helpers.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Rest;

use Dev3\AiSiteHealth\Core3\Api\Api_Error;
use Dev3\AiSiteHealth\Core3\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Base controller: namespace, nonce + capability checks, error envelope.
 */
abstract class Base_Controller {

	const NS = 'dev3sh/v1';

	/**
	 * Registers routes.
	 */
	abstract public function register_routes();

	/**
	 * Verifies the wp_rest nonce explicitly (cookie auth already requires it, this makes it strict for every route).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function nonce_ok( \WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		return $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * Permission: generate content.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_generate( \WP_REST_Request $request ) {
		return $this->permission( $request, Capabilities::can_generate() );
	}

	/**
	 * Permission: manage settings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function can_manage( \WP_REST_Request $request ) {
		return $this->permission( $request, Capabilities::can_manage() );
	}

	/**
	 * Combined check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param bool             $allowed Capability result.
	 * @return bool|\WP_Error
	 */
	private function permission( \WP_REST_Request $request, $allowed ) {
		if ( ! $this->nonce_ok( $request ) ) {
			return new \WP_Error( 'dev3sh_bad_nonce', __( 'Your session has expired. Reload the editor and try again.', 'dev3-ai-site-health-analyst' ), array( 'status' => 403 ) );
		}
		if ( ! $allowed ) {
			return new \WP_Error( 'dev3sh_forbidden', __( 'You do not have permission to do that.', 'dev3-ai-site-health-analyst' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Permission for public storefront endpoints (logged-out visitors): same-origin + rate limit.
	 *
	 * Nonces are not required here because full-page caches serve stale nonces to visitors;
	 * callbacks must still validate input and never expose private data.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $bucket  Rate-limit bucket.
	 * @param int              $limit   Requests per minute per IP.
	 * @return bool|\WP_Error
	 */
	protected function public_permission( \WP_REST_Request $request, $bucket, $limit = 20 ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' !== $origin && wp_parse_url( $origin, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return new \WP_Error( 'dev3sh_forbidden_origin', __( 'Cross-origin requests are not allowed.', 'dev3-ai-site-health-analyst' ), array( 'status' => 403 ) );
		}
		/**
		 * Per-IP requests per minute for a public bucket.
		 *
		 * @param int    $limit  Limit.
		 * @param string $bucket Bucket.
		 */
		$limit = (int) apply_filters( 'dev3sh_public_rate_limit', $limit, $bucket );
		if ( ! \Dev3\AiSiteHealth\Core3\Core\Rate_Limiter::hit( $bucket, \Dev3\AiSiteHealth\Core3\Core\Rate_Limiter::client_ip(), $limit ) ) {
			return new \WP_Error( 'dev3sh_rate_limited', __( 'Too many requests. Please wait a moment.', 'dev3-ai-site-health-analyst' ), array( 'status' => 429 ) );
		}
		return true;
	}

	/**
	 * Wraps a result or WP_Error into a response.
	 *
	 * @param mixed $result Result.
	 * @param int   $status Status for success.
	 * @return \WP_REST_Response
	 */
	protected function respond( $result, $status = 200 ) {
		if ( is_wp_error( $result ) ) {
			return Api_Error::to_response( $result );
		}
		return new \WP_REST_Response( $result, $status );
	}
}
