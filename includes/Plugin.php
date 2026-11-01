<?php
/**
 * Bootstrap and wiring.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth;

use Dev3\AiSiteHealth\Ai\Explainer;
use Dev3\AiSiteHealth\Ai\Prompt_Library;
use Dev3\AiSiteHealth\Collect\Change_Logger;
use Dev3\AiSiteHealth\Collect\Collector;
use Dev3\AiSiteHealth\Collect\Integrity;
use Dev3\AiSiteHealth\Collect\Security_Logger;
use Dev3\AiSiteHealth\Collect\Slow_Queries;
use Dev3\AiSiteHealth\Core3\Core\Assets;
use Dev3\AiSiteHealth\Core3\Core\Privacy;
use Dev3\AiSiteHealth\Core3\Core\Schema;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Tables;
use Dev3\AiSiteHealth\Fix\Firewall;
use Dev3\AiSiteHealth\Report\Weekly_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	const CRON_HOOK = 'dev3sh_daily';

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Assets registered (Assets::register adds the boot script on every call).
	 *
	 * @var bool
	 */
	private static $assets = false;

	/**
	 * Boots once.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		self::register_schema();
		Schema::install( DEV3SH_DB_VERSION );
		self::schedule();
		Change_Logger::save_snapshot();
	}

	/**
	 * Schedules the crons.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( Collector::CRON ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', Collector::CRON );
		}
		if ( ! wp_next_scheduled( Weekly_Report::CRON ) ) {
			Weekly_Report::schedule();
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {
		foreach ( array( self::CRON_HOOK, Collector::CRON, Weekly_Report::CRON, 'dev3sh_license_check' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Plugin tables / settings defaults are registered here so activation and runtime agree.
	 */
	public static function register_schema() {
		if ( has_filter( 'dev3sh_settings_defaults', array( Settings::class, 'defaults' ) ) ) {
			return;
		}
		add_filter( 'dev3sh_settings_defaults', array( Settings::class, 'defaults' ) );
		add_filter( 'dev3sh_settings_sanitize', array( Settings::class, 'sanitize' ), 10, 3 );
		add_filter( 'dev3sh_schema_sql', array( Tables::class, 'schema' ), 10, 2 );
	}

	/**
	 * Hooks.
	 */
	private function boot() {
		self::register_schema();
		add_action( 'init', array( $this, 'textdomain' ) );
		add_action( 'init', array( Prompt_Library::class, 'register' ), 5 );
		add_action( 'init', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( self::CRON_HOOK, array( Schema::class, 'prune_usage' ) );
		add_action( 'rest_api_init', array( $this, 'rest' ) );
		add_action( 'update_option_' . Settings_Store::OPTION, array( $this, 'settings_changed' ), 10, 2 );
		Privacy::init();
		add_action( 'init', array( Privacy_Data::class, 'register' ) );

		// Job types and collectors are registered on every request (the job runner looks them up).
		Integrity::register();
		Explainer::register();
		Collector::register();
		Weekly_Report::register();
		Firewall::register();
		Security_Logger::register();
		Change_Logger::register();
		Slow_Queries::register();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'dev3sh', Cli\Command::class );
		}
		if ( is_admin() ) {
			( new Admin\Admin_Page() )->register();
		}
	}

	/**
	 * Schema upgrade + crons (also covers sites that never ran activation, e.g. mu installs).
	 */
	public function maybe_upgrade() {
		Schema::maybe_install( DEV3SH_DB_VERSION );
		self::schedule();
	}

	/**
	 * Settings saved: refresh the cache and reschedule the weekly report.
	 *
	 * @param mixed $old Old.
	 * @param mixed $value New.
	 */
	public function settings_changed( $old, $value ) {
		Settings_Store::flush();
		$keys = array( 'weekly_report', 'report_day', 'report_hour' );
		foreach ( $keys as $key ) {
			if ( ( is_array( $old ) && isset( $old[ $key ] ) ? $old[ $key ] : null ) !== ( is_array( $value ) && isset( $value[ $key ] ) ? $value[ $key ] : null ) ) {
				Weekly_Report::schedule();
				break;
			}
		}
	}

	/**
	 * Translations (on init, as WP 6.7+ expects).
	 */
	public function textdomain() {
		load_plugin_textdomain( 'dev3-ai-site-health-analyst', false, dirname( plugin_basename( DEV3SH_FILE ) ) . '/languages' );
	}

	/**
	 * Registers the admin bundle (once).
	 */
	public function assets() {
		if ( self::$assets ) {
			return;
		}
		self::$assets = true;
		Assets::register( array( 'admin' ) );
	}

	/**
	 * REST routes.
	 */
	public function rest() {
		( new Core3\Rest\Core_Controller() )->register_routes();
		( new Rest\Admin_Controller() )->register_routes();
	}
}
