<?php
/**
 * Registers built bundles (build/<entry>.js + .asset.php) with the shared boot config.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Handles are "<slug>-<entry>". Boot data is window.dev3shConfig (renamed per plugin by sync).
 */
class Assets {

	/**
	 * Registers entries.
	 *
	 * @param array $entries Entry names.
	 * @param array $config  Boot data merged into the shared config.
	 */
	public static function register( array $entries, array $config = array() ) {
		if ( ! wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
			wp_register_script( 'react-jsx-runtime', DEV3SH_URL . 'assets/vendor/react-jsx-runtime.js', array( 'react' ), '18.3.1', true );
		}
		$boot = 'window.dev3shConfig = Object.assign( window.dev3shConfig || {}, ' . wp_json_encode( array_merge( self::config(), $config ) ) . ' );';
		foreach ( $entries as $entry ) {
			$asset_file = DEV3SH_PATH . 'build/' . $entry . '.asset.php';
			if ( ! is_readable( $asset_file ) ) {
				continue;
			}
			$asset  = include $asset_file;
			$handle = self::handle( $entry );
			wp_register_script( $handle, DEV3SH_URL . 'build/' . $entry . '.js', $asset['dependencies'], $asset['version'], true );
			wp_set_script_translations( $handle, 'dev3-ai-site-health-analyst', DEV3SH_PATH . 'languages' );
			wp_add_inline_script( $handle, $boot, 'before' );
			if ( is_readable( DEV3SH_PATH . 'build/' . $entry . '.css' ) ) {
				wp_register_style( $handle, DEV3SH_URL . 'build/' . $entry . '.css', array(), $asset['version'] );
			}
		}
	}

	/**
	 * Script/style handle for an entry.
	 *
	 * @param string $entry Entry.
	 * @return string
	 */
	public static function handle( $entry ) {
		return DEV3SH_SLUG . '-' . $entry;
	}

	/**
	 * Enqueues an entry (script + style).
	 *
	 * @param string $entry Entry.
	 */
	public static function enqueue( $entry ) {
		wp_enqueue_script( self::handle( $entry ) );
		if ( wp_style_is( self::handle( $entry ), 'registered' ) ) {
			wp_enqueue_style( self::handle( $entry ) );
		}
	}

	/**
	 * Shared boot data. Never contains secrets.
	 *
	 * @return array
	 */
	public static function config() {
		return array(
			'version'   => DEV3SH_VERSION,
			'slug'      => DEV3SH_SLUG,
			'restUrl'   => esc_url_raw( rest_url( 'dev3sh/v1/' ) ),
			'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'userId'    => get_current_user_id(),
			'canManage' => current_user_can( Capabilities::manage() ),
			'assetsUrl' => DEV3SH_URL . 'assets/',
			'name'      => defined( 'DEV3SH_NAME' ) ? DEV3SH_NAME : '',
			'aiEnabled' => (bool) Settings_Store::get( 'ai_enabled', true ),
			'adminUrl'  => admin_url(),
		);
	}
}
