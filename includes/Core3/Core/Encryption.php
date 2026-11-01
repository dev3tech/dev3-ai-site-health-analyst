<?php
/**
 * Symmetric encryption for secrets stored in wp_options.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * AES-256-GCM using a key derived from the site's auth salt.
 *
 * The ciphertext is bound to this install: copying the option to another site
 * (or rotating salts) makes it undecryptable, and the user is asked to re-enter the key.
 */
class Encryption {

	const CIPHER = 'aes-256-gcm';
	const PREFIX = 'dev3sh1:';

	/**
	 * Whether OpenSSL with GCM is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derives the 32-byte key.
	 *
	 * @return string
	 */
	private static function key() {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'dev3c' );
		/**
		 * Filters the secret used to derive the encryption key.
		 *
		 * @param string $salt Secret material.
		 */
		$salt = (string) apply_filters( 'dev3sh_encryption_secret', $salt );
		return hash( 'sha256', DEV3SH_SLUG . '|' . $salt, true );
	}

	/**
	 * Encrypts a plaintext value.
	 *
	 * @param string $plain Plaintext.
	 * @return string Encoded payload, or '' for empty input.
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::available() ) {
			// Obfuscation only; the admin UI shows a notice when this fallback is active.
			return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return '';
		}
		return self::PREFIX . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypts a payload produced by encrypt().
	 *
	 * @param string $payload Encoded payload.
	 * @return string Plaintext, or '' when it cannot be decrypted.
	 */
	public static function decrypt( $payload ) {
		$payload = (string) $payload;
		if ( '' === $payload ) {
			return '';
		}
		if ( 0 === strpos( $payload, 'b64:' ) ) {
			return (string) base64_decode( substr( $payload, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 !== strpos( $payload, self::PREFIX ) || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $payload, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}
}
