<?php
/**
 * Capability checks.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Central place for who may do what.
 */
class Capabilities {

	/**
	 * Capability for settings, and templates management.
	 *
	 * @return string
	 */
	public static function manage() {
		/**
		 * Filters the capability needed to manage plugin settings.
		 *
		 * @param string $cap Capability.
		 */
		return (string) apply_filters( 'dev3sh_manage_capability', 'manage_options' );
	}

	/**
	 * Capability for generating content.
	 *
	 * @return string
	 */
	public static function generate() {
		/**
		 * Filters the capability needed to generate content.
		 *
		 * @param string $cap Capability.
		 */
		return (string) apply_filters( 'dev3sh_capability', 'edit_posts' );
	}

	/**
	 * Current user may manage settings.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( self::manage() );
	}

	/**
	 * Current user may generate content.
	 *
	 * @return bool
	 */
	public static function can_generate() {
		return current_user_can( self::generate() );
	}
}
