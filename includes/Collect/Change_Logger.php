<?php
/**
 * Plugin / theme / core change log + snapshot diff for changes made outside WordPress (FTP/SSH).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Core3\Core\Rate_Limiter;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks.
 */
class Change_Logger {

	const SNAPSHOT = 'dev3sh_snapshot';

	/**
	 * Registers hooks.
	 */
	public static function register() {
		add_action( 'activated_plugin', array( __CLASS__, 'activated' ), 10, 2 );
		add_action( 'deactivated_plugin', array( __CLASS__, 'deactivated' ), 10, 2 );
		add_action( 'deleted_plugin', array( __CLASS__, 'deleted' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'switched_theme' ), 10, 3 );
		add_action( 'deleted_theme', array( __CLASS__, 'deleted_theme' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'upgraded' ), 10, 2 );
		add_action( '_core_updated_successfully', array( __CLASS__, 'core_updated' ) );
	}

	/**
	 * Adds a change event and refreshes the snapshot so the diff does not report it again.
	 *
	 * @param string $rule    Rule (plugin_activated…).
	 * @param string $message Message.
	 * @param array  $meta    Meta.
	 * @param string $sev     Severity.
	 */
	public static function log( $rule, $message, array $meta = array(), $sev = 'info' ) {
		$user = wp_get_current_user();
		Events::add(
			'change',
			array(
				'rule'       => $rule,
				'severity'   => $sev,
				'ip'         => ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ? '' : Rate_Limiter::client_ip(),
				'user_login' => $user && $user->exists() ? $user->user_login : '',
				'user_id'    => get_current_user_id(),
				'message'    => $message,
				'meta'       => $meta,
			)
		);
		if ( did_action( 'init' ) ) {
			self::save_snapshot();
		}
	}

	/**
	 * Plugin activated.
	 *
	 * @param string $plugin  Basename.
	 * @param bool   $network Network wide.
	 */
	public static function activated( $plugin, $network = false ) {
		/* translators: %s: plugin name. */
		self::log( 'plugin_activated', sprintf( __( 'Plugin activated: %s', 'dev3-ai-site-health-analyst' ), self::plugin_label( $plugin ) ), self::plugin_meta( $plugin, $network ) );
	}

	/**
	 * Plugin deactivated.
	 *
	 * @param string $plugin  Basename.
	 * @param bool   $network Network wide.
	 */
	public static function deactivated( $plugin, $network = false ) {
		/* translators: %s: plugin name. */
		self::log( 'plugin_deactivated', sprintf( __( 'Plugin deactivated: %s', 'dev3-ai-site-health-analyst' ), self::plugin_label( $plugin ) ), self::plugin_meta( $plugin, $network ) );
	}

	/**
	 * Plugin deleted.
	 *
	 * @param string $plugin  Basename.
	 * @param bool   $deleted Success.
	 */
	public static function deleted( $plugin, $deleted = true ) {
		if ( $deleted ) {
			/* translators: %s: plugin file. */
			self::log( 'plugin_deleted', sprintf( __( 'Plugin deleted: %s', 'dev3-ai-site-health-analyst' ), $plugin ), array( 'plugin' => $plugin ), 'low' );
		}
	}

	/**
	 * Theme switched.
	 *
	 * @param string    $name      New name.
	 * @param \WP_Theme $new_theme New theme.
	 * @param \WP_Theme $old_theme Old theme.
	 */
	public static function switched_theme( $name, $new_theme = null, $old_theme = null ) {
		self::log(
			'theme_switched',
			/* translators: %s: theme name. */
			sprintf( __( 'Theme switched to %s', 'dev3-ai-site-health-analyst' ), $name ),
			array(
				'theme' => $new_theme instanceof \WP_Theme ? $new_theme->get_stylesheet() : '',
				'from'  => $old_theme instanceof \WP_Theme ? $old_theme->get_stylesheet() : '',
			)
		);
	}

	/**
	 * Theme deleted.
	 *
	 * @param string $stylesheet Stylesheet.
	 * @param bool   $deleted    Success.
	 */
	public static function deleted_theme( $stylesheet, $deleted = true ) {
		if ( $deleted ) {
			/* translators: %s: theme. */
			self::log( 'theme_deleted', sprintf( __( 'Theme deleted: %s', 'dev3-ai-site-health-analyst' ), $stylesheet ), array( 'theme' => $stylesheet ), 'low' );
		}
	}

	/**
	 * Install / update of plugins, themes and core.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader.
	 * @param array        $extra    Hook extra.
	 */
	public static function upgraded( $upgrader, $extra ) {
		$type   = isset( $extra['type'] ) ? $extra['type'] : '';
		$action = isset( $extra['action'] ) ? $extra['action'] : '';
		if ( 'plugin' === $type ) {
			$items = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
			if ( 'install' === $action && ! $items && is_object( $upgrader ) && method_exists( $upgrader, 'plugin_info' ) ) {
				$items = array_filter( array( $upgrader->plugin_info() ) );
			}
			foreach ( $items as $plugin ) {
				$rule = 'install' === $action ? 'plugin_installed' : 'plugin_updated';
				/* translators: 1: plugin name, 2: version. */
				$msg  = 'install' === $action ? __( 'Plugin installed: %1$s %2$s', 'dev3-ai-site-health-analyst' ) : __( 'Plugin updated: %1$s to %2$s', 'dev3-ai-site-health-analyst' );
				$meta = self::plugin_meta( $plugin, false );
				self::log( $rule, sprintf( $msg, self::plugin_label( $plugin ), $meta['version'] ), $meta );
			}
		} elseif ( 'theme' === $type ) {
			$items = isset( $extra['themes'] ) ? (array) $extra['themes'] : ( isset( $extra['theme'] ) ? array( $extra['theme'] ) : array() );
			foreach ( $items as $theme ) {
				$t = wp_get_theme( $theme );
				/* translators: 1: theme name, 2: version. */
				$msg = 'install' === $action ? __( 'Theme installed: %1$s %2$s', 'dev3-ai-site-health-analyst' ) : __( 'Theme updated: %1$s to %2$s', 'dev3-ai-site-health-analyst' );
				self::log(
					'install' === $action ? 'theme_installed' : 'theme_updated',
					sprintf( $msg, $t->exists() ? $t->get( 'Name' ) : $theme, $t->get( 'Version' ) ),
					array(
						'theme'   => $theme,
						'version' => (string) $t->get( 'Version' ),
					)
				);
			}
		}
	}

	/**
	 * Core updated.
	 *
	 * @param string $version New version.
	 */
	public static function core_updated( $version ) {
		/* translators: %s: version. */
		self::log( 'core_updated', sprintf( __( 'WordPress updated to %s', 'dev3-ai-site-health-analyst' ), $version ), array( 'version' => $version ) );
	}

	/**
	 * Current plugin/theme/core versions.
	 *
	 * @return array { core, plugins{file:{v,active}}, themes{slug:v}, theme }
	 */
	public static function snapshot() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[ $file ] = array(
				'v' => (string) $data['Version'],
				'a' => is_plugin_active( $file ),
			);
		}
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[ $slug ] = (string) $theme->get( 'Version' );
		}
		return array(
			'core'    => get_bloginfo( 'version' ),
			'plugins' => $plugins,
			'themes'  => $themes,
			'theme'   => get_stylesheet(),
		);
	}

	/**
	 * Saves the snapshot.
	 */
	public static function save_snapshot() {
		update_option( self::SNAPSHOT, self::snapshot() + array( 'at' => time() ), false );
	}

	/**
	 * Diffs the stored snapshot against now and logs "changed outside WordPress" events.
	 *
	 * @return array[] Changes { rule, message }.
	 */
	public static function diff() {
		$old = get_option( self::SNAPSHOT, array() );
		$now = self::snapshot();
		if ( ! is_array( $old ) || empty( $old['plugins'] ) ) {
			self::save_snapshot();
			return array();
		}
		$changes = array();
		foreach ( $now['plugins'] as $file => $p ) {
			if ( ! isset( $old['plugins'][ $file ] ) ) {
				/* translators: 1: plugin, 2: version. */
				$changes[] = array( 'plugin_added', sprintf( __( 'Plugin added outside WordPress: %1$s %2$s', 'dev3-ai-site-health-analyst' ), self::plugin_label( $file ), $p['v'] ), $file );
			} elseif ( $old['plugins'][ $file ]['v'] !== $p['v'] ) {
				/* translators: 1: plugin, 2: old version, 3: new version. */
				$changes[] = array( 'plugin_version_changed', sprintf( __( 'Plugin version changed outside WordPress: %1$s %2$s → %3$s', 'dev3-ai-site-health-analyst' ), self::plugin_label( $file ), $old['plugins'][ $file ]['v'], $p['v'] ), $file );
			} elseif ( (bool) $old['plugins'][ $file ]['a'] !== (bool) $p['a'] ) {
				/* translators: %s: plugin. */
				$changes[] = array( $p['a'] ? 'plugin_activated' : 'plugin_deactivated', sprintf( $p['a'] ? __( 'Plugin activated outside the admin: %s', 'dev3-ai-site-health-analyst' ) : __( 'Plugin deactivated outside the admin: %s', 'dev3-ai-site-health-analyst' ), self::plugin_label( $file ) ), $file );
			}
		}
		foreach ( array_keys( $old['plugins'] ) as $file ) {
			if ( ! isset( $now['plugins'][ $file ] ) ) {
				/* translators: %s: plugin file. */
				$changes[] = array( 'plugin_removed', sprintf( __( 'Plugin removed outside WordPress: %s', 'dev3-ai-site-health-analyst' ), $file ), $file );
			}
		}
		foreach ( $now['themes'] as $slug => $v ) {
			if ( isset( $old['themes'][ $slug ] ) && $old['themes'][ $slug ] !== $v ) {
				/* translators: 1: theme, 2: old, 3: new. */
				$changes[] = array( 'theme_version_changed', sprintf( __( 'Theme version changed outside WordPress: %1$s %2$s → %3$s', 'dev3-ai-site-health-analyst' ), $slug, $old['themes'][ $slug ], $v ), $slug );
			} elseif ( ! isset( $old['themes'][ $slug ] ) ) {
				/* translators: %s: theme. */
				$changes[] = array( 'theme_added', sprintf( __( 'Theme added outside WordPress: %s', 'dev3-ai-site-health-analyst' ), $slug ), $slug );
			}
		}
		if ( ! empty( $old['core'] ) && $old['core'] !== $now['core'] ) {
			/* translators: 1: old, 2: new. */
			$changes[] = array( 'core_version_changed', sprintf( __( 'WordPress version changed: %1$s → %2$s', 'dev3-ai-site-health-analyst' ), $old['core'], $now['core'] ), 'core' );
		}
		foreach ( $changes as $c ) {
			Events::add(
				'change',
				array(
					'rule'     => $c[0],
					'severity' => 'low',
					'message'  => $c[1],
					'meta'     => array(
						'target'  => $c[2],
						'outside' => true,
					),
				)
			);
			if ( in_array( $c[0], array( 'plugin_added', 'plugin_version_changed', 'theme_added', 'theme_version_changed' ), true ) ) {
				Issues::record(
					array(
						'source'      => 'change',
						'kind'        => $c[0],
						'fingerprint' => 'outside:' . $c[0] . ':' . $c[2],
						'severity'    => 'medium',
						'title'       => $c[1],
						'message'     => __( 'Code changed without going through the WordPress admin. Confirm that you or your developer made this change.', 'dev3-ai-site-health-analyst' ),
						'component'   => false !== strpos( $c[0], 'theme' ) ? 'theme:' . $c[2] : 'plugin:' . dirname( $c[2] ),
					)
				);
			}
		}
		update_option( self::SNAPSHOT, $now + array( 'at' => time() ), false );
		return array_map(
			static function ( $c ) {
				return array(
					'rule'    => $c[0],
					'message' => $c[1],
				);
			},
			$changes
		);
	}

	/**
	 * Plugin name + version.
	 *
	 * @param string $plugin Basename.
	 * @return string
	 */
	private static function plugin_label( $plugin ) {
		$meta = self::plugin_meta( $plugin, false );
		return '' !== $meta['name'] ? $meta['name'] : $plugin;
	}

	/**
	 * Plugin meta.
	 *
	 * @param string $plugin  Basename.
	 * @param bool   $network Network.
	 * @return array
	 */
	private static function plugin_meta( $plugin, $network ) {
		$name    = '';
		$version = '';
		$path    = WP_PLUGIN_DIR . '/' . $plugin;
		if ( is_readable( $path ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$data    = get_plugin_data( $path, false, false );
			$name    = (string) $data['Name'];
			$version = (string) $data['Version'];
		}
		return array(
			'plugin'  => $plugin,
			'name'    => $name,
			'version' => $version,
			'network' => (bool) $network,
		);
	}
}
