<?php
/**
 * Message normalisation, path shortening and component attribution used to group signals into issues.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Analyze;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers (no I/O).
 */
class Fingerprint {

	/**
	 * Collapses the variable parts of a message so repeats group together.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	public static function normalize( $message ) {
		$m = (string) $message;
		$m = preg_replace( '/^\[[^\]]{6,40}\]\s*/', '', $m );                    // Leading timestamp.
		$m = preg_replace( '/\b0x[0-9a-f]+\b/i', '0x?', $m );                     // Addresses.
		$m = preg_replace( '/\b[0-9a-f]{12,}\b/i', '#', $m );                     // Hashes / ids.
		$m = preg_replace( '/\b\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?\b/', 'T', $m );
		$m = preg_replace( '/(?<![\w.\/-])\d+(\.\d+)?(?![\w\/-])/', 'N', $m );     // Numbers (not inside paths/versions).
		$m = preg_replace( '/\s+/', ' ', $m );
		return trim( (string) $m );
	}

	/**
	 * Fingerprint for an issue.
	 *
	 * @param string $source  Source.
	 * @param string $kind    Kind.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return string sha1.
	 */
	public static function make( $source, $kind, $message, $file = '', $line = 0 ) {
		return sha1( implode( '|', array( $source, $kind, self::normalize( $message ), self::relative( $file ), (int) $line ) ) );
	}

	/**
	 * Absolute path → path relative to the WordPress root (or the bare file name outside it).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function relative( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		if ( '' === $path ) {
			return '';
		}
		foreach ( self::roots() as $root => $label ) {
			if ( 0 === strpos( $path, $root ) ) {
				return $label . ltrim( substr( $path, strlen( $root ) ), '/' );
			}
		}
		return $path;
	}

	/**
	 * Known roots, longest first ([ abs => label ]).
	 *
	 * @return array
	 */
	public static function roots() {
		$roots = array();
		$add   = static function ( $abs, $label ) use ( &$roots ) {
			$abs = rtrim( str_replace( '\\', '/', (string) $abs ), '/' );
			if ( '' !== $abs && '.' !== $abs ) {
				$roots[ $abs . '/' ] = $label;
			}
		};
		$add( WP_PLUGIN_DIR, 'wp-content/plugins/' );
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$add( WPMU_PLUGIN_DIR, 'wp-content/mu-plugins/' );
		}
		$add( get_theme_root(), 'wp-content/themes/' );
		$add( WP_CONTENT_DIR, 'wp-content/' );
		$add( ABSPATH, '' );
		uksort(
			$roots,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		return $roots;
	}

	/**
	 * Replaces every absolute path inside a text with its relative form.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function relative_in_text( $text ) {
		$text = (string) $text;
		foreach ( self::roots() as $root => $label ) {
			$text = str_replace( $root, '' === $label ? '' : $label, $text );
			$text = str_replace( rtrim( $root, '/' ), '' === $label ? '.' : rtrim( $label, '/' ), $text );
		}
		return $text;
	}

	/**
	 * Component from a (relative or absolute) path: plugin:slug, theme:slug, mu-plugin:file, core or ''.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function component( $path ) {
		$rel = self::relative( $path );
		if ( preg_match( '#(?:^|/)wp-content/plugins/([^/]+)#', $rel, $m ) ) {
			return 'plugin:' . preg_replace( '/\.php$/', '', $m[1] );
		}
		if ( preg_match( '#(?:^|/)wp-content/mu-plugins/([^/]+)#', $rel, $m ) ) {
			return 'mu-plugin:' . preg_replace( '/\.php$/', '', $m[1] );
		}
		if ( preg_match( '#(?:^|/)wp-content/themes/([^/]+)#', $rel, $m ) ) {
			return 'theme:' . $m[1];
		}
		if ( preg_match( '#^(wp-includes|wp-admin)/#', $rel ) || preg_match( '#^wp-[a-z-]+\.php$#', $rel ) ) {
			return 'core';
		}
		return '';
	}

	/**
	 * Human label for a component.
	 *
	 * @param string $component Component id.
	 * @return string
	 */
	public static function component_label( $component ) {
		if ( 'core' === $component ) {
			return __( 'WordPress core', 'dev3-ai-site-health-analyst' );
		}
		if ( 0 === strpos( $component, 'plugin:' ) ) {
			$slug = substr( $component, 7 );
			$name = self::plugin_name( $slug );
			/* translators: %s: plugin name. */
			return sprintf( __( 'Plugin: %s', 'dev3-ai-site-health-analyst' ), $name ? $name : $slug );
		}
		if ( 0 === strpos( $component, 'theme:' ) ) {
			$theme = wp_get_theme( substr( $component, 6 ) );
			/* translators: %s: theme name. */
			return sprintf( __( 'Theme: %s', 'dev3-ai-site-health-analyst' ), $theme->exists() ? $theme->get( 'Name' ) : substr( $component, 6 ) );
		}
		if ( 0 === strpos( $component, 'mu-plugin:' ) ) {
			/* translators: %s: must-use plugin file. */
			return sprintf( __( 'Must-use plugin: %s', 'dev3-ai-site-health-analyst' ), substr( $component, 10 ) );
		}
		return '';
	}

	/**
	 * Plugin basename (folder/file.php) for a folder slug, or ''.
	 *
	 * @param string $slug Folder slug.
	 * @return string
	 */
	public static function plugin_file( $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( 0 === strpos( $file, $slug . '/' ) || $file === $slug . '.php' ) {
				return $file;
			}
		}
		return '';
	}

	/**
	 * Plugin display name for a folder slug.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	public static function plugin_name( $slug ) {
		$file = self::plugin_file( $slug );
		if ( '' === $file ) {
			return '';
		}
		$plugins = get_plugins();
		return isset( $plugins[ $file ]['Name'] ) ? (string) $plugins[ $file ]['Name'] : '';
	}
}
