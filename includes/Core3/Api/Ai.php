<?php
/**
 * One call = guard (kill switch, monthly cap) + cache + generate/embed/image + usage log. Plugins should go through this.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Api;

use Dev3\AiSiteHealth\Core3\Core\Cache;
use Dev3\AiSiteHealth\Core3\Core\Privacy;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store as Settings;
use Dev3\AiSiteHealth\Core3\Data\Usage_Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Thin facade over Gemini_Client.
 */
class Ai {

	/**
	 * Visitor-triggered contexts: honour the storefront kill switch and fail fast (no retries).
	 */
	const PUBLIC_CONTEXTS = array( 'storefront', 'public', 'frontend', 'submission' );

	/**
	 * Injectable client (tests).
	 *
	 * @var Gemini_Client|null
	 */
	public static $client = null;

	/**
	 * Client instance.
	 *
	 * @return Gemini_Client
	 */
	private static function client() {
		return self::$client ? self::$client : new Gemini_Client();
	}

	/**
	 * Runs a task.
	 *
	 * @param string $task    Task name for the usage log.
	 * @param array  $args    Gemini_Client::generate args (model may be 'default'|'pro'|id), plus:
	 *                        `cache` (int TTL seconds: return a stored result for identical inputs),
	 *                        `profile` ('fast' = 30s timeout; 'heavy' = 150s timeout + thinking 2048),
	 *                        `mask_pii` (bool, default = the PII masking setting),
	 *                        `retries` (default 1; 0 in visitor-facing contexts).
	 * @param string $context Where it ran (admin, cron, storefront…). PUBLIC_CONTEXTS honour the storefront AI switch and skip retries.
	 * @return array|\WP_Error generate() result, plus `data` when args contain a schema and `cached` => true on a cache hit.
	 */
	public static function run( $task, array $args, $context = 'admin' ) {
		$blocked = self::guard( $task, $context );
		if ( $blocked ) {
			return $blocked;
		}
		$args          = self::prepare( $args, $context );
		$args['model'] = self::model( isset( $args['model'] ) ? $args['model'] : 'default' );

		$ttl = isset( $args['cache'] ) ? (int) $args['cache'] : 0;
		$key = '';
		if ( $ttl > 0 && Settings::get( 'cache_enabled', true ) ) {
			$key    = Cache::key_for_args( $task, $args );
			$cached = Cache::get( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}
		unset( $args['cache'], $args['profile'], $args['mask_pii'] );

		$client = self::client();
		$json   = ! empty( $args['schema'] );
		$result = $json ? $client->generate_json( $args ) : $client->generate( $args );
		$log    = self::log_row( $task, $context, $args['model'] );
		if ( is_wp_error( $result ) ) {
			self::log_error( $log, $result );
			return $result;
		}
		$raw = $json ? $result['raw'] : $result;
		Usage_Repository::log(
			$log + array(
				'usage'       => $raw['usage'],
				'duration_ms' => $raw['duration_ms'],
			)
		);
		if ( $json ) {
			$raw['data'] = $result['data'];
		}
		if ( '' !== $key ) {
			Cache::set( $key, $raw, $ttl, $task );
		}
		return $raw;
	}

	/**
	 * Runs a task and falls back to non-AI behaviour on any error (AI off, cap reached, key missing,
	 * Gemini down). Use it for storefront features.
	 *
	 * @param string   $task     Task.
	 * @param array    $args     run() args (add `cache` for public endpoints).
	 * @param string   $context  Context.
	 * @param callable $fallback fn( \WP_Error $error ): mixed — its return value is returned.
	 * @return mixed
	 */
	public static function run_or( $task, array $args, $context, callable $fallback ) {
		$result = self::run( $task, $args, $context );
		return is_wp_error( $result ) ? call_user_func( $fallback, $result ) : $result;
	}

	/**
	 * Embeds texts (batched, 100 per request) and logs usage (tokens estimated at 4 chars each).
	 *
	 * @param string          $task      Task name.
	 * @param string|string[] $texts     Texts.
	 * @param string          $task_type Gemini task type (RETRIEVAL_DOCUMENT for items, RETRIEVAL_QUERY for searches…).
	 * @param string          $context   Context.
	 * @param array           $opts      Gemini_Client::embed opts, plus `cache` TTL (useful for search queries).
	 * @return array|\WP_Error { vectors, model, dimensions, usage, duration_ms, cached? }
	 */
	public static function embed( $task, $texts, $task_type = 'RETRIEVAL_DOCUMENT', $context = 'admin', array $opts = array() ) {
		$blocked = self::guard( $task, $context );
		if ( $blocked ) {
			return $blocked;
		}
		$model         = Settings::sanitize_model( isset( $opts['model'] ) ? $opts['model'] : Settings::get( 'embedding_model', 'gemini-embedding-001' ) );
		$opts['model'] = '' === $model ? 'gemini-embedding-001' : $model;
		$opts         += array( 'retries' => in_array( $context, self::PUBLIC_CONTEXTS, true ) ? 0 : 1 );
		$ttl           = isset( $opts['cache'] ) ? (int) $opts['cache'] : 0;
		$key           = '';
		if ( $ttl > 0 && Settings::get( 'cache_enabled', true ) ) {
			$key    = Cache::key( $task . ':embed', array( $texts, $task_type, $opts['model'], isset( $opts['dimensions'] ) ? $opts['dimensions'] : 768 ) );
			$cached = Cache::get( $key );
			if ( is_array( $cached ) ) {
				$cached['cached'] = true;
				return $cached;
			}
		}
		unset( $opts['cache'] );
		$result = self::client()->embed( $texts, $task_type, $opts );
		$log    = self::log_row( $task, $context, $opts['model'] );
		if ( is_wp_error( $result ) ) {
			self::log_error( $log, $result );
			return $result;
		}
		Usage_Repository::log(
			$log + array(
				'usage'       => $result['usage'],
				'duration_ms' => $result['duration_ms'],
			)
		);
		if ( '' !== $key ) {
			Cache::set( $key, $result, $ttl, $task );
		}
		return $result;
	}

	/**
	 * Generates an image and logs usage. The result is NOT saved anywhere: show it as a draft and
	 * save it to the media library only when the admin accepts (see Ai::save_image()).
	 *
	 * @param string $task    Task name.
	 * @param string $prompt  Prompt.
	 * @param array  $opts    Gemini_Client::generate_image opts.
	 * @param string $context Context.
	 * @return array|\WP_Error { data (base64), mime, images, text, model, usage, duration_ms }
	 */
	public static function image( $task, $prompt, array $opts = array(), $context = 'admin' ) {
		$blocked = self::guard( $task, $context );
		if ( $blocked ) {
			return $blocked;
		}
		$model         = Settings::sanitize_model( isset( $opts['model'] ) ? $opts['model'] : Settings::get( 'image_model', 'gemini-2.5-flash-image' ) );
		$opts['model'] = '' === $model ? 'gemini-2.5-flash-image' : $model;
		$opts         += array( 'retries' => in_array( $context, self::PUBLIC_CONTEXTS, true ) ? 0 : 1 );
		$result        = self::client()->generate_image( Privacy::maybe_mask( $prompt ), $opts );
		$log           = self::log_row( $task, $context, $opts['model'] );
		if ( is_wp_error( $result ) ) {
			self::log_error( $log, $result );
			return $result;
		}
		Usage_Repository::log(
			$log + array(
				'usage'       => $result['usage'],
				'duration_ms' => $result['duration_ms'],
			)
		);
		return $result;
	}

	/**
	 * Saves a base64 image (from image()) to the media library after the admin accepted it.
	 *
	 * @param string $data     Base64 data.
	 * @param string $mime     image/png|image/jpeg|image/webp.
	 * @param string $title    Attachment title.
	 * @param int    $post_id  Parent post id.
	 * @return int|\WP_Error Attachment id.
	 */
	public static function save_image( $data, $mime, $title, $post_id = 0 ) {
		$ext = array(
			'image/png'  => 'png',
			'image/jpeg' => 'jpg',
			'image/webp' => 'webp',
		);
		if ( ! isset( $ext[ $mime ] ) ) {
			return Api_Error::make( 'dev3sh_bad_image', __( 'Unsupported image type.', 'dev3-ai-site-health-analyst' ) );
		}
		$bytes = base64_decode( (string) $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $bytes || '' === $bytes ) {
			return Api_Error::make( 'dev3sh_bad_image', __( 'The image data is invalid.', 'dev3-ai-site-health-analyst' ) );
		}
		$upload = wp_upload_bits( sanitize_file_name( $title ) . '-' . wp_generate_password( 6, false ) . '.' . $ext[ $mime ], null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return Api_Error::make( 'dev3sh_upload', (string) $upload['error'] );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_text_field( $title ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			(int) $post_id,
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
		return (int) $id;
	}

	/**
	 * Whether AI may run in a context (kill switches, cap, key).
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function enabled( $context = 'admin' ) {
		return null === self::guard( '', $context ) && '' !== Settings::api_key();
	}

	/**
	 * State for the UI: { enabled, public, key_set, cap_reached, month }.
	 *
	 * @return array
	 */
	public static function status() {
		return array(
			'enabled'     => (bool) Settings::get( 'ai_enabled', true ),
			'public'      => (bool) Settings::get( 'public_ai', true ),
			'key_set'     => '' !== Settings::api_key(),
			'cap_reached' => Usage_Repository::cap_reached(),
			'month'       => Usage_Repository::month(),
		);
	}

	/**
	 * Resolves 'default' / 'pro' aliases.
	 *
	 * @param string $model Alias or id.
	 * @return string
	 */
	public static function model( $model ) {
		$model = (string) $model;
		if ( 'default' === $model || '' === $model ) {
			return (string) Settings::get( 'default_model' );
		}
		if ( 'pro' === $model ) {
			return (string) Settings::get( 'pro_model' );
		}
		return $model;
	}

	/**
	 * Returns a WP_Error when AI must not run, else null.
	 *
	 * @param string $task    Task.
	 * @param string $context Context.
	 * @return \WP_Error|null
	 */
	private static function guard( $task, $context ) {
		$error = null;
		if ( ! Settings::get( 'ai_enabled', true ) ) {
			$error = Api_Error::make( 'dev3sh_ai_disabled', __( 'AI features are turned off.', 'dev3-ai-site-health-analyst' ), __( 'Turn AI back on in API Config.', 'dev3-ai-site-health-analyst' ), 403 );
		} elseif ( in_array( $context, self::PUBLIC_CONTEXTS, true ) && ! Settings::get( 'public_ai', true ) ) {
			$error = Api_Error::make( 'dev3sh_public_ai_disabled', __( 'Storefront AI is turned off.', 'dev3-ai-site-health-analyst' ), __( 'Turn storefront AI back on in API Config.', 'dev3-ai-site-health-analyst' ), 403 );
		} elseif ( Usage_Repository::cap_reached() ) {
			$error = Api_Error::cap_reached();
		}
		/**
		 * Last word on whether a task may call the AI. Return a WP_Error to block it.
		 *
		 * @param \WP_Error|null $error   Error or null (allowed).
		 * @param string         $task    Task.
		 * @param string         $context Context.
		 */
		$error = apply_filters( 'dev3sh_ai_guard', $error, $task, $context );
		return is_wp_error( $error ) ? $error : null;
	}

	/**
	 * Applies profile defaults, retries and PII masking.
	 *
	 * @param array  $args    Args.
	 * @param string $context Context (visitor-facing contexts get no retries: the visitor is waiting).
	 * @return array
	 */
	private static function prepare( array $args, $context = 'admin' ) {
		$profile = isset( $args['profile'] ) ? (string) $args['profile'] : '';
		if ( 'fast' === $profile ) {
			$args += array( 'timeout' => 30 );
		} elseif ( 'heavy' === $profile ) {
			$args += array(
				'timeout'         => 150,
				'thinking_budget' => 2048,
			);
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
		}
		$args += array( 'retries' => in_array( $context, self::PUBLIC_CONTEXTS, true ) ? 0 : 1 );
		$mask  = isset( $args['mask_pii'] ) ? (bool) $args['mask_pii'] : (bool) Settings::get( 'pii_masking', false );
		if ( $mask ) {
			foreach ( array( 'prompt', 'system' ) as $field ) {
				if ( isset( $args[ $field ] ) ) {
					$args[ $field ] = Privacy::mask( $args[ $field ] );
				}
			}
		}
		return $args;
	}

	/**
	 * Base usage row.
	 *
	 * @param string $task    Task.
	 * @param string $context Context.
	 * @param string $model   Model.
	 * @return array
	 */
	private static function log_row( $task, $context, $model ) {
		return array(
			'user_id' => get_current_user_id(),
			'task'    => $task,
			'context' => $context,
			'model'   => $model,
		);
	}

	/**
	 * Logs a failed request.
	 *
	 * @param array     $log   Base row.
	 * @param \WP_Error $error Error.
	 */
	private static function log_error( array $log, \WP_Error $error ) {
		Usage_Repository::log(
			$log + array(
				'status'     => 'error',
				'error_code' => $error->get_error_code(),
			)
		);
	}
}
