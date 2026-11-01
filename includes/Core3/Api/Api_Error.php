<?php
/**
 * Maps Gemini / transport failures to actionable WP_Error objects.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Every error carries data: status (HTTP for our REST response), hint, retryable.
 */
class Api_Error {

	/**
	 * Builds a WP_Error with the plugin's error envelope.
	 *
	 * @param string $code      Machine code (dev3sh_*).
	 * @param string $message   Human message.
	 * @param string $hint      Recovery hint.
	 * @param int    $status    HTTP status to send to the browser.
	 * @param bool   $retryable Whether retrying may succeed.
	 * @return \WP_Error
	 */
	public static function make( $code, $message, $hint = '', $status = 400, $retryable = false ) {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'    => $status,
				'hint'      => $hint,
				'retryable' => $retryable,
			)
		);
	}

	/**
	 * No key configured.
	 *
	 * @return \WP_Error
	 */
	public static function missing_key() {
		return self::make(
			'dev3sh_no_api_key',
			__( 'No Gemini API key is configured.', 'dev3-ai-site-health-analyst' ),
			__( 'Open the plugin settings → API Config and paste a key from Google AI Studio.', 'dev3-ai-site-health-analyst' ),
			400
		);
	}

	/**
	 * The admin's monthly AI budget is spent ("AI paused, cap reached").
	 *
	 * @return \WP_Error
	 */
	public static function cap_reached() {
		return self::make(
			'dev3sh_cap_reached',
			__( 'AI paused: the monthly AI budget is reached.', 'dev3-ai-site-health-analyst' ),
			__( 'Raise the monthly cap in API Config, or wait until the budget resets on the 1st of next month.', 'dev3-ai-site-health-analyst' ),
			429
		);
	}

	/**
	 * Transport-level failure from wp_remote_*.
	 *
	 * @param \WP_Error $error Transport error.
	 * @return \WP_Error
	 */
	public static function from_transport( \WP_Error $error ) {
		$message = strtolower( $error->get_error_message() );
		if ( false !== strpos( $message, 'timed out' ) || false !== strpos( $message, 'timeout' ) ) {
			return self::make(
				'dev3sh_timeout',
				__( 'The AI service took too long to respond.', 'dev3-ai-site-health-analyst' ),
				__( 'Try again, shorten the prompt, lower Max Output Tokens, or switch to the faster model.', 'dev3-ai-site-health-analyst' ),
				504,
				true
			);
		}
		if ( false !== strpos( $message, 'resolve' ) || false !== strpos( $message, 'connect' ) || false !== strpos( $message, 'network' ) ) {
			return self::make(
				'dev3sh_offline',
				__( 'Your server could not reach the AI service (Google Gemini API).', 'dev3-ai-site-health-analyst' ),
				__( 'Check the server\'s internet connection or firewall (outbound HTTPS to generativelanguage.googleapis.com).', 'dev3-ai-site-health-analyst' ),
				502,
				true
			);
		}
		return self::make(
			'dev3sh_http_error',
			/* translators: %s: transport error message. */
			sprintf( __( 'Request to the AI service failed: %s', 'dev3-ai-site-health-analyst' ), $error->get_error_message() ),
			__( 'Try again in a moment.', 'dev3-ai-site-health-analyst' ),
			502,
			true
		);
	}

	/**
	 * Non-2xx response from Gemini.
	 *
	 * @param int   $http HTTP status code.
	 * @param array $body Decoded body.
	 * @return \WP_Error
	 */
	public static function from_response( $http, $body ) {
		$err     = isset( $body['error'] ) && is_array( $body['error'] ) ? $body['error'] : array();
		$status  = isset( $err['status'] ) ? (string) $err['status'] : '';
		$raw     = isset( $err['message'] ) ? (string) $err['message'] : '';
		$reasons = array();
		if ( ! empty( $err['details'] ) && is_array( $err['details'] ) ) {
			foreach ( $err['details'] as $detail ) {
				if ( isset( $detail['reason'] ) ) {
					$reasons[] = (string) $detail['reason'];
				}
			}
		}

		if ( in_array( 'API_KEY_INVALID', $reasons, true ) || false !== stripos( $raw, 'API key not valid' ) ) {
			return self::make(
				'dev3sh_invalid_key',
				__( 'The Gemini API key is not valid.', 'dev3-ai-site-health-analyst' ),
				__( 'Create a new key in Google AI Studio and paste it in API Config.', 'dev3-ai-site-health-analyst' ),
				401
			);
		}
		if ( 429 === $http || 'RESOURCE_EXHAUSTED' === $status ) {
			$daily = false !== stripos( $raw, 'per day' ) || false !== stripos( $raw, 'quota' );
			return self::make(
				'dev3sh_quota',
				$daily ? __( 'Your Gemini quota is exhausted.', 'dev3-ai-site-health-analyst' ) : __( 'AI rate limit reached.', 'dev3-ai-site-health-analyst' ),
				$daily
					? __( 'Wait for the quota to reset, enable billing on your Google Cloud project, or switch to a model with remaining quota.', 'dev3-ai-site-health-analyst' )
					: __( 'Wait a few seconds and try again.', 'dev3-ai-site-health-analyst' ),
				429,
				true
			);
		}
		if ( 403 === $http || 'PERMISSION_DENIED' === $status ) {
			return self::make(
				'dev3sh_forbidden',
				__( 'This API key is not allowed to use Gemini.', 'dev3-ai-site-health-analyst' ),
				__( 'Enable the Generative Language API for the key\'s project, and check any key restrictions (HTTP referrer / IP).', 'dev3-ai-site-health-analyst' ),
				403
			);
		}
		if ( 404 === $http || 'NOT_FOUND' === $status ) {
			return self::make(
				'dev3sh_model_not_found',
				__( 'The selected Gemini model is not available.', 'dev3-ai-site-health-analyst' ),
				__( 'Pick another model in API Config — click "Refresh models" to load the ones your key can use.', 'dev3-ai-site-health-analyst' ),
				400
			);
		}
		if ( 400 === $http && false !== stripos( $raw, 'location' ) ) {
			return self::make(
				'dev3sh_region',
				__( 'The AI service is not available in your server\'s region.', 'dev3-ai-site-health-analyst' ),
				__( 'Host the site in a supported region or use a key from a supported project.', 'dev3-ai-site-health-analyst' ),
				400
			);
		}
		if ( $http >= 500 ) {
			return self::make(
				'dev3sh_upstream',
				__( 'The AI service is temporarily unavailable.', 'dev3-ai-site-health-analyst' ),
				__( 'This is on Google\'s side. Try again shortly or switch models.', 'dev3-ai-site-health-analyst' ),
				503,
				true
			);
		}
		return self::make(
			'dev3sh_bad_request',
			'' !== $raw ? $raw : __( 'The AI service rejected the request.', 'dev3-ai-site-health-analyst' ),
			__( 'Adjust the prompt or settings and try again.', 'dev3-ai-site-health-analyst' ),
			400
		);
	}

	/**
	 * Converts a WP_Error into the REST envelope.
	 *
	 * @param \WP_Error $error Error.
	 * @return \WP_REST_Response
	 */
	public static function to_response( \WP_Error $error ) {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 400;
		return new \WP_REST_Response(
			array(
				'code'      => $error->get_error_code(),
				'message'   => $error->get_error_message(),
				'hint'      => isset( $data['hint'] ) ? $data['hint'] : '',
				'retryable' => ! empty( $data['retryable'] ),
				'data'      => array( 'status' => $status ),
			),
			$status
		);
	}
}
