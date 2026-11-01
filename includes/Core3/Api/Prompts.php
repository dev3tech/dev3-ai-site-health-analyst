<?php
/**
 * Prompt library: versioned prompt templates per feature, overridable by filters.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Register templates once (e.g. on `init`), then build Ai::run args or run them:
 *
 *     Prompts::register( 'product_title', array(
 *         'version'  => '2',
 *         'system'   => 'You write concise WooCommerce product titles.',
 *         'template' => "Write a title for: {{name}}\nKeywords: {{keywords}}",
 *         'schema'   => array( 'type' => 'OBJECT', 'properties' => array( 'title' => array( 'type' => 'STRING', 'maxLength' => 120 ) ) ),
 *         'model'    => 'default',
 *     ) );
 *     $result = Prompts::run( 'product_title', array( 'name' => 'Mug', 'keywords' => 'ceramic' ) );
 *
 * Sites override a template with `dev3sh_prompt` ( $prompt, $id ) or `dev3sh_prompt_{id}` ( $prompt ).
 */
class Prompts {

	/**
	 * Registered templates.
	 *
	 * @var array
	 */
	private static $prompts = array();

	/**
	 * Registers (or replaces) a template.
	 *
	 * @param string $id     Prompt id (also the default usage-log task name).
	 * @param array  $prompt { version, template, system, schema, model, temperature, max_tokens, thinking_budget, profile, cache (ttl), task }.
	 */
	public static function register( $id, array $prompt ) {
		self::$prompts[ (string) $id ] = array_merge(
			array(
				'version'  => '1',
				'template' => '',
				'system'   => '',
			),
			$prompt
		);
	}

	/**
	 * A template after filters, or null.
	 *
	 * @param string $id Prompt id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$prompt = isset( self::$prompts[ $id ] ) ? self::$prompts[ $id ] : null;
		/**
		 * Filters any prompt template.
		 *
		 * @param array|null $prompt Template.
		 * @param string     $id     Prompt id.
		 */
		$prompt = apply_filters( 'dev3sh_prompt', $prompt, $id );
		/**
		 * Filters one prompt template.
		 *
		 * @param array|null $prompt Template.
		 */
		$prompt = apply_filters( 'dev3sh_prompt_' . $id, $prompt );
		return is_array( $prompt ) ? $prompt : null;
	}

	/**
	 * All registered ids => version (for diagnostics / the admin).
	 *
	 * @return array
	 */
	public static function versions() {
		$out = array();
		foreach ( array_keys( self::$prompts ) as $id ) {
			$p          = self::get( $id );
			$out[ $id ] = $p ? (string) $p['version'] : '';
		}
		return $out;
	}

	/**
	 * Replaces {{var}} placeholders. Arrays are JSON-encoded; unknown placeholders become ''.
	 *
	 * @param string $template Template.
	 * @param array  $vars     Variables.
	 * @return string
	 */
	public static function fill( $template, array $vars ) {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
			static function ( $m ) use ( $vars ) {
				if ( ! array_key_exists( $m[1], $vars ) ) {
					return '';
				}
				$v = $vars[ $m[1] ];
				return is_scalar( $v ) || null === $v ? (string) $v : (string) wp_json_encode( $v );
			},
			(string) $template
		);
	}

	/**
	 * Builds Ai::run args from a template.
	 *
	 * @param string $id    Prompt id.
	 * @param array  $vars  Variables.
	 * @param array  $extra Extra/override Ai::run args.
	 * @return array|\WP_Error
	 */
	public static function build( $id, array $vars = array(), array $extra = array() ) {
		$prompt = self::get( $id );
		if ( ! $prompt ) {
			return Api_Error::make( 'dev3sh_prompt_missing', __( 'Unknown prompt template.', 'dev3-ai-site-health-analyst' ), '', 500 );
		}
		$args = array(
			'prompt' => self::fill( $prompt['template'], $vars ),
			'system' => self::fill( $prompt['system'], $vars ),
		);
		foreach ( array( 'schema', 'model', 'temperature', 'max_tokens', 'thinking_budget', 'profile', 'cache', 'timeout', 'retries', 'tools' ) as $key ) {
			if ( isset( $prompt[ $key ] ) ) {
				$args[ $key ] = $prompt[ $key ];
			}
		}
		return array_merge( $args, $extra );
	}

	/**
	 * Builds and runs a template through Ai::run.
	 *
	 * @param string $id      Prompt id.
	 * @param array  $vars    Variables.
	 * @param string $context Usage context.
	 * @param array  $extra   Extra Ai::run args.
	 * @return array|\WP_Error
	 */
	public static function run( $id, array $vars = array(), $context = 'admin', array $extra = array() ) {
		$args = self::build( $id, $vars, $extra );
		if ( is_wp_error( $args ) ) {
			return $args;
		}
		$prompt = self::get( $id );
		$task   = isset( $prompt['task'] ) ? (string) $prompt['task'] : (string) $id;
		$result = Ai::run( $task, $args, $context );
		if ( is_array( $result ) ) {
			$result['prompt_version'] = (string) $prompt['version'];
		}
		return $result;
	}

	/**
	 * Clears registrations (tests).
	 */
	public static function reset() {
		self::$prompts = array();
	}
}
