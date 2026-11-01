<?php
/**
 * Plugin settings in one option: shared AI keys plus plugin keys added through filters.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Plugins extend defaults with `dev3sh_settings_defaults` and sanitize their own keys with
 * `dev3sh_settings_sanitize` ( $out, $input, $current ).
 */
class Settings_Store {

	const OPTION = 'dev3sh_settings';

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		$core = array(
			'api_key'             => '',
			'default_model'       => 'gemini-2.5-flash',
			'pro_model'           => 'gemini-2.5-pro',
			'temperature'         => 0.4,
			'max_tokens'          => 4096,
			'reasoning'           => 'low',
			'usage_logging'       => true,
			'delete_on_uninstall' => false,
			'embedding_model'     => 'gemini-embedding-001',
			'image_model'         => 'gemini-2.5-flash-image',
			'ai_enabled'          => true,
			'public_ai'           => true,
			'monthly_cap'         => 0,
			'cache_enabled'       => true,
			'pii_masking'         => false,
		);
		/**
		 * Plugin-specific defaults merged over the shared AI settings.
		 *
		 * @param array $core Defaults.
		 */
		return apply_filters( 'dev3sh_settings_defaults', $core );
	}

	/**
	 * All settings (API key still encrypted).
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Decrypted key; the DEV3SH_GEMINI_API_KEY constant wins.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( self::key_from_constant() ) {
			return (string) constant( 'DEV3SH_GEMINI_API_KEY' );
		}
		return Encryption::decrypt( (string) self::get( 'api_key', '' ) );
	}

	/**
	 * Key defined in wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'DEV3SH_GEMINI_API_KEY' ) && constant( 'DEV3SH_GEMINI_API_KEY' );
	}

	/**
	 * Masked key for the UI.
	 *
	 * @return string
	 */
	public static function masked_key() {
		$key = self::api_key();
		return '' === $key ? '' : str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Settings safe for the browser.
	 *
	 * @return array
	 */
	public static function public_settings() {
		$all = self::all();
		unset( $all['api_key'] );
		$all['api_key_masked']       = self::masked_key();
		$all['api_key_set']          = '' !== self::api_key();
		$all['api_key_constant']     = self::key_from_constant();
		$all['encryption_available'] = Encryption::available();
		return $all;
	}

	/**
	 * Saves a partial update.
	 *
	 * @param array $input Raw input.
	 * @return array Public settings.
	 */
	public static function update( array $input ) {
		$clean = self::sanitize( $input, self::all() );
		update_option( self::OPTION, $clean, false );
		self::$cache = null;
		return self::public_settings();
	}

	/**
	 * Sanitizes shared keys, then lets the plugin sanitize its own.
	 *
	 * @param array $input   Raw input.
	 * @param array $current Current values.
	 * @return array
	 */
	public static function sanitize( array $input, array $current ) {
		$out = $current;
		if ( array_key_exists( 'api_key', $input ) ) {
			$key = trim( sanitize_text_field( wp_unslash( (string) $input['api_key'] ) ) );
			if ( false === strpos( $key, '•' ) ) {
				$out['api_key'] = '' === $key ? '' : Encryption::encrypt( $key );
			}
		}
		foreach ( array( 'default_model', 'pro_model', 'embedding_model', 'image_model' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== self::sanitize_model( $input[ $field ] ) ) {
				$out[ $field ] = self::sanitize_model( $input[ $field ] );
			}
		}
		if ( isset( $input['temperature'] ) ) {
			$out['temperature'] = round( min( 1.0, max( 0.0, (float) $input['temperature'] ) ), 2 );
		}
		if ( isset( $input['max_tokens'] ) ) {
			$out['max_tokens'] = min( 65536, max( 64, (int) $input['max_tokens'] ) );
		}
		if ( isset( $input['reasoning'] ) ) {
			$out['reasoning'] = 'auto' === $input['reasoning'] ? 'auto' : 'low';
		}
		foreach ( array( 'usage_logging', 'delete_on_uninstall', 'ai_enabled', 'public_ai', 'cache_enabled', 'pii_masking' ) as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$out[ $flag ] = (bool) $input[ $flag ];
			}
		}
		if ( isset( $input['monthly_cap'] ) ) {
			// Estimated USD per calendar month; 0 = no cap. Clamp instead of absint (negatives become 0).
			$out['monthly_cap'] = round( min( 100000, max( 0, (float) $input['monthly_cap'] ) ), 2 );
		}
		/**
		 * Sanitizes plugin-specific keys.
		 *
		 * @param array $out     Sanitized so far.
		 * @param array $input   Raw input.
		 * @param array $current Current settings.
		 */
		return apply_filters( 'dev3sh_settings_sanitize', $out, $input, $current );
	}

	/**
	 * Model id sanitizer.
	 *
	 * @param mixed $value Raw.
	 * @return string
	 */
	public static function sanitize_model( $value ) {
		$value = preg_replace( '#^models/#', '', strtolower( trim( (string) $value ) ) );
		return preg_match( '/^[a-z0-9][a-z0-9._-]{1,79}$/', $value ) ? $value : '';
	}

	/**
	 * Clears the request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Recursive merge keeping default keys.
	 *
	 * @param array $defaults Defaults.
	 * @param array $stored   Stored.
	 * @return array
	 */
	private static function merge( array $defaults, array $stored ) {
		foreach ( $stored as $key => $value ) {
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && is_array( $value ) && array_values( $defaults[ $key ] ) !== $defaults[ $key ] ) {
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value );
			} elseif ( array_key_exists( $key, $defaults ) ) {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}
}
