<?php
/**
 * Plugin Name:       DEV3 AI Site Health & Security Log Analyst for WordPress
 * Plugin URI:        https://dev3.tech/item/dev3-ai-site-health-analyst
 * Description:       Collects PHP errors, Site Health, security events, updates, cron, slow queries and file integrity, groups them into issues and explains each with DEV3 AI.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DEV3 Technologies
 * Author URI:        https://dev3.tech
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dev3-ai-site-health-analyst
 * Domain Path:       /languages
 *
 * @package Dev3\AiSiteHealth
 */

defined( 'ABSPATH' ) || exit;

define( 'DEV3SH_VERSION', '1.0.0' );
define( 'DEV3SH_FILE', __FILE__ );
define( 'DEV3SH_PATH', plugin_dir_path( __FILE__ ) );
define( 'DEV3SH_URL', plugin_dir_url( __FILE__ ) );
define( 'DEV3SH_SLUG', 'dev3-ai-site-health-analyst' );
define( 'DEV3SH_NAME', 'DEV3 AI Site Health & Security Log Analyst for WordPress' );
define( 'DEV3SH_MIN_PHP', '7.4' );
define( 'DEV3SH_MIN_WP', '6.0' );
define( 'DEV3SH_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, DEV3SH_MIN_PHP, '<' ) ) {
	return;
}

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'Dev3\AiSiteHealth\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = DEV3SH_PATH . 'includes/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( '\Dev3\AiSiteHealth\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\Dev3\AiSiteHealth\Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( '\Dev3\AiSiteHealth\Plugin', 'instance' ) );
