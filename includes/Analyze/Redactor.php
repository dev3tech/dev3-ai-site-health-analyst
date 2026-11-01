<?php
/**
 * Masks secrets, paths, emails, IPs, user names and the site host before anything leaves the site.
 *
 * Every string that goes to DEV3 AI passes through mask() (see Ai\Explainer). Unit tests cover each rule.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Analyze;

use Dev3\AiSiteHealth\Core3\Core\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Redaction rules.
 */
class Redactor {

	/**
	 * Extra literal values to mask (e.g. user names from login events) for the current payload.
	 *
	 * @var string[]
	 */
	private static $literals = array();

	/**
	 * Adds literal values (user names, custom secrets) masked as $label.
	 *
	 * @param array  $values Values.
	 * @param string $label  Replacement.
	 */
	public static function add_literals( array $values, $label = '[user]' ) {
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( strlen( $value ) >= 3 ) {
				self::$literals[ $value ] = $label;
			}
		}
	}

	/**
	 * Clears literals.
	 */
	public static function reset() {
		self::$literals = array();
	}

	/**
	 * Masks a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( $text ) {
		$t = (string) $text;
		if ( '' === $t ) {
			return '';
		}

		// 0) Emails first (before the site host is replaced inside them).
		$t = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $t );

		// 1) URLs with credentials, then secrets in well-known formats.
		$t        = preg_replace( '#\b([a-z][a-z0-9+.-]*://)[^\s/:@]+:[^\s/@]+@#i', '$1[credentials]@', $t );
		$patterns = array(
			'/\bAIza[0-9A-Za-z_\-]{20,}/'          => '[api-key]',
			'/\b(?:sk|pk|rk)_(?:live|test)_[0-9A-Za-z]{8,}/' => '[api-key]',
			'/\bsk-(?:proj-)?[0-9A-Za-z_\-]{16,}/' => '[api-key]',
			'/\bgh[pousr]_[0-9A-Za-z]{20,}/'       => '[api-key]',
			'/\bxox[abposr]-[0-9A-Za-z-]{10,}/'    => '[api-key]',
			'/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/'      => '[api-key]',
			'/\beyJ[0-9A-Za-z_-]{8,}\.[0-9A-Za-z_-]{8,}\.[0-9A-Za-z_-]{8,}/' => '[jwt]',
			'/\b(Bearer|Basic|Token)\s+[0-9A-Za-z._~+\/=-]{8,}/i' => '$1 [token]',
		);
		foreach ( $patterns as $re => $rep ) {
			$t = preg_replace( $re, $rep, $t );
		}

		// 2) Key/value secrets: query strings, JSON, PHP defines/arrays, headers, cookies.
		$keys = '(?:[a-z0-9_]*(?:password|passwd|pwd|secret|token|api[_-]?key|apikey|authorization|auth_key|auth_salt|salt|nonce_key|private[_-]?key|access[_-]?key|client[_-]?secret|license[_-]?key)[a-z0-9_]*|key|pass|auth|sig|signature)';
		$t    = preg_replace( "/(define\\(\\s*['\"]" . $keys . "['\"]\\s*,\\s*)(['\"]).*?\\2/i", '$1$2[secret]$2', $t );
		$t    = preg_replace( "/(['\"]" . $keys . "['\"]\\s*(?:=>|:)\\s*)(['\"])(?:\\\\.|(?!\\2).)*\\2/i", '$1$2[secret]$2', $t );
		$t    = preg_replace( '/(\b' . $keys . '\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s&;,]+)/i', '$1[secret]', $t );
		$t    = preg_replace( '/(\b' . $keys . '\s*:\s*)(?!\[secret\]|(?:Bearer|Basic|Token)\s)("[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '$1[secret]', $t );
		$t    = preg_replace( '/\b(wordpress_(?:logged_in_|sec_)?[0-9a-f]{0,32}|wp-settings-\d+|PHPSESSID)=[^\s;]+/i', '$1=[secret]', $t );

		// 3) Database credentials in error messages.
		$t = preg_replace( "/(Access denied for user\\s+)'[^']*'@'[^']*'/i", "\$1'[db-user]'@'[db-host]'", $t );
		$t = preg_replace( '/(\(using password:\s*)(YES|NO)\)/i', '$1[masked])', $t );
		$t = preg_replace( '/(mysql|mysqli|pgsql|redis|mongodb)(\+srv)?:\/\/[^\s"\']+/i', '$1://[dsn]', $t );

		// 4) Site host / home URL, then absolute paths.
		foreach ( self::hosts() as $host ) {
			$t = str_ireplace( $host, '[site]', $t );
		}
		$t = preg_replace( '/[A-Z0-9._%+-]+@\[site\]/i', '[email]', $t ); // user@localhost style addresses.
		$t = Fingerprint::relative_in_text( $t );
		// Absolute paths outside WordPress keep only the file name. URLs (after "//" or a host) are kept.
		$t = preg_replace( '#(?<![\w.\]:/\\-])(?:[A-Za-z]:)?(?:/|\\\\)(?:[\w .@-]+(?:/|\\\\))+([\w.@-]+)#', '[path]/$1', $t );

		// 5) Long opaque blobs (hashes, base64) that may be secrets.
		$t = preg_replace( '/\b[0-9a-f]{32,}\b/i', '[hash]', $t );
		$t = preg_replace( '/(?<![\w\/])[A-Za-z0-9+\/]{40,}={0,2}(?![\w\/])/', '[blob]', $t );

		// 6) Literals (user names from events) and core PII (emails, cards, IPv4, phones).
		foreach ( self::$literals as $value => $label ) {
			$t = preg_replace( '/(?<![\w])' . preg_quote( $value, '/' ) . '(?![\w])/i', $label, $t );
		}
		$t = self::mask_ipv6( $t );
		$t = Privacy::mask( $t );

		/**
		 * Filters the redacted text (add your own masking rules).
		 *
		 * @param string $t    Masked text.
		 * @param string $text Original text.
		 */
		return (string) apply_filters( 'dev3sh_redact', $t, (string) $text );
	}

	/**
	 * Masks every string inside an array recursively (keys are kept).
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	public static function mask_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::mask_deep( $v );
			}
			return $out;
		}
		return is_string( $value ) ? self::mask( $value ) : $value;
	}

	/**
	 * IPv6 addresses.
	 *
	 * @param string $t Text.
	 * @return string
	 */
	private static function mask_ipv6( $t ) {
		return (string) preg_replace_callback(
			'/(?<![\w:])(?:(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,6}:(?:[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,5})?|::(?:ffff:)?[0-9a-f]{1,4}(?::[0-9a-f]{1,4}){0,5})(?![\w:])/i',
			static function ( $m ) {
				// Class::method names have no digits; real IPv6 addresses do.
				return preg_match( '/\d/', $m[0] ) ? '[ip]' : $m[0];
			},
			$t
		);
	}

	/**
	 * Host names of this site (home + site URL), longest first.
	 *
	 * @return string[]
	 */
	private static function hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			$port = wp_parse_url( $url, PHP_URL_PORT );
			if ( $host ) {
				if ( $port ) {
					$hosts[] = $host . ':' . $port;
				}
				$hosts[] = $host;
			}
		}
		$hosts = array_unique( $hosts );
		usort(
			$hosts,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $hosts;
	}

	/**
	 * Anonymises an IP for display/report (/24 for IPv4, /48 for IPv6).
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	public static function anonymize_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip ) {
			return '';
		}
		if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
			return wp_privacy_anonymize_ip( $ip );
		}
		return preg_replace( '/\.\d+$/', '.0', $ip );
	}
}
