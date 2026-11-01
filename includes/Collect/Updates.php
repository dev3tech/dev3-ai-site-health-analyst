<?php
/**
 * Core / plugin / theme update status, inactive extensions and PHP/database versions.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the update transients (refreshing them at most every 12 hours, as WordPress itself does).
 */
class Updates {

	/**
	 * Current status.
	 *
	 * @param bool $refresh Ask wordpress.org when the transients are older than 12 h.
	 * @return array { core, plugins[], themes[], inactive_plugins[], inactive_themes[], php, db, checked_at }
	 */
	public static function status( $refresh = false ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( $refresh ) {
			$t = get_site_transient( 'update_plugins' );
			if ( ! is_object( $t ) || empty( $t->last_checked ) || time() - (int) $t->last_checked > 12 * HOUR_IN_SECONDS ) {
				wp_version_check();
				wp_update_plugins();
				wp_update_themes();
			}
		}
		$core      = array(
			'current'   => get_bloginfo( 'version' ),
			'available' => '',
			'type'      => '',
		);
		$core_info = get_site_transient( 'update_core' );
		if ( is_object( $core_info ) && ! empty( $core_info->updates ) ) {
			foreach ( $core_info->updates as $u ) {
				if ( isset( $u->response ) && 'upgrade' === $u->response && version_compare( $u->current, $core['current'], '>' ) ) {
					$core['available'] = (string) $u->current;
					$core['type']      = self::major( $core['current'] ) === self::major( $u->current ) ? 'minor' : 'major';
					break;
				}
			}
		}

		$all     = get_plugins();
		$plugins = array();
		$pt      = get_site_transient( 'update_plugins' );
		if ( is_object( $pt ) && ! empty( $pt->response ) ) {
			foreach ( (array) $pt->response as $file => $u ) {
				if ( ! isset( $all[ $file ] ) ) {
					continue;
				}
				$plugins[] = array(
					'file'    => $file,
					'slug'    => dirname( $file ),
					'name'    => (string) $all[ $file ]['Name'],
					'current' => (string) $all[ $file ]['Version'],
					'new'     => isset( $u->new_version ) ? (string) $u->new_version : '',
					'active'  => is_plugin_active( $file ),
				);
			}
		}
		$themes = array();
		$tt     = get_site_transient( 'update_themes' );
		if ( is_object( $tt ) && ! empty( $tt->response ) ) {
			foreach ( (array) $tt->response as $slug => $u ) {
				$theme    = wp_get_theme( $slug );
				$themes[] = array(
					'slug'    => (string) $slug,
					'name'    => $theme->exists() ? (string) $theme->get( 'Name' ) : (string) $slug,
					'current' => (string) $theme->get( 'Version' ),
					'new'     => isset( $u['new_version'] ) ? (string) $u['new_version'] : '',
					'active'  => get_stylesheet() === $slug || get_template() === $slug,
				);
			}
		}
		$inactive_plugins = array();
		foreach ( $all as $file => $data ) {
			if ( ! is_plugin_active( $file ) && ! is_plugin_active_for_network( $file ) ) {
				$inactive_plugins[] = array(
					'file' => $file,
					'name' => (string) $data['Name'],
				);
			}
		}
		$inactive_themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			if ( get_stylesheet() !== $slug && get_template() !== $slug ) {
				$inactive_themes[] = array(
					'slug' => (string) $slug,
					'name' => (string) $theme->get( 'Name' ),
				);
			}
		}
		global $wpdb;
		return array(
			'core'             => $core,
			'plugins'          => $plugins,
			'themes'           => $themes,
			'inactive_plugins' => $inactive_plugins,
			'inactive_themes'  => $inactive_themes,
			'php'              => PHP_VERSION,
			'php_recommended'  => '8.1',
			'db'               => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version(),
			'checked_at'       => is_object( $pt ) && ! empty( $pt->last_checked ) ? gmdate( 'c', (int) $pt->last_checked ) : '',
		);
	}

	/**
	 * Turns the status into issues (state-type: resolved automatically when gone).
	 *
	 * @param array|null $status Status (null = read now).
	 * @return array { issues, pending }
	 */
	public static function collect( $status = null ) {
		$status = is_array( $status ) ? $status : self::status( true );
		$keep   = array();
		if ( '' !== $status['core']['available'] ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'updates',
					'kind'        => 'core_update',
					'fingerprint' => 'core_update',
					'severity'    => 'minor' === $status['core']['type'] ? 'high' : 'medium',
					/* translators: 1: current, 2: new. */
					'title'       => sprintf( __( 'WordPress %1$s can be updated to %2$s', 'dev3-ai-site-health-analyst' ), $status['core']['current'], $status['core']['available'] ),
					'message'     => 'minor' === $status['core']['type'] ? __( 'A maintenance/security release of WordPress is available.', 'dev3-ai-site-health-analyst' ) : __( 'A new major version of WordPress is available.', 'dev3-ai-site-health-analyst' ),
					'component'   => 'core',
					'replace'     => true,
					'details'     => $status['core'],
				)
			);
		}
		foreach ( $status['plugins'] as $p ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'updates',
					'kind'        => 'plugin_update',
					'fingerprint' => 'plugin_update:' . $p['file'],
					'severity'    => $p['active'] ? 'medium' : 'low',
					/* translators: 1: plugin, 2: current, 3: new. */
					'title'       => sprintf( __( 'Update available: %1$s %2$s → %3$s', 'dev3-ai-site-health-analyst' ), $p['name'], $p['current'], $p['new'] ),
					'message'     => __( 'Plugin updates often contain security fixes.', 'dev3-ai-site-health-analyst' ),
					'component'   => 'plugin:' . $p['slug'],
					'replace'     => true,
					'details'     => $p,
				)
			);
		}
		foreach ( $status['themes'] as $t ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'updates',
					'kind'        => 'theme_update',
					'fingerprint' => 'theme_update:' . $t['slug'],
					'severity'    => $t['active'] ? 'medium' : 'low',
					/* translators: 1: theme, 2: current, 3: new. */
					'title'       => sprintf( __( 'Theme update available: %1$s %2$s → %3$s', 'dev3-ai-site-health-analyst' ), $t['name'], $t['current'], $t['new'] ),
					'message'     => __( 'Theme updates often contain security fixes.', 'dev3-ai-site-health-analyst' ),
					'component'   => 'theme:' . $t['slug'],
					'replace'     => true,
					'details'     => $t,
				)
			);
		}
		if ( count( $status['inactive_plugins'] ) >= 3 ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'updates',
					'kind'        => 'inactive_plugins',
					'fingerprint' => 'inactive_plugins',
					'severity'    => 'low',
					/* translators: %d: count. */
					'title'       => sprintf( __( '%d inactive plugins are installed', 'dev3-ai-site-health-analyst' ), count( $status['inactive_plugins'] ) ),
					'message'     => __( 'Inactive plugins still contain code that can be attacked. Delete the ones you do not need.', 'dev3-ai-site-health-analyst' ),
					'replace'     => true,
					'count'       => count( $status['inactive_plugins'] ),
					'details'     => wp_list_pluck( $status['inactive_plugins'], 'name' ),
				)
			);
		}
		if ( version_compare( $status['php'], '8.0', '<' ) ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'updates',
					'kind'        => 'php_version',
					'fingerprint' => 'php_version',
					'severity'    => version_compare( $status['php'], '7.4', '<' ) ? 'high' : 'medium',
					/* translators: %s: version. */
					'title'       => sprintf( __( 'PHP %s no longer receives security updates', 'dev3-ai-site-health-analyst' ), $status['php'] ),
					'message'     => __( 'Ask your host to move the site to PHP 8.1 or newer.', 'dev3-ai-site-health-analyst' ),
					'replace'     => true,
				)
			);
		}
		Issues::resolve_missing( 'updates', array_filter( $keep ) );
		return array(
			'issues'  => count( array_filter( $keep ) ),
			'pending' => ( '' !== $status['core']['available'] ? 1 : 0 ) + count( $status['plugins'] ) + count( $status['themes'] ),
			'status'  => 'ok',
		);
	}

	/**
	 * "6.8.1" → "6.8".
	 *
	 * @param string $version Version.
	 * @return string
	 */
	private static function major( $version ) {
		$parts = explode( '.', (string) $version );
		return $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : '0' );
	}
}
