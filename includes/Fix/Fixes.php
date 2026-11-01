<?php
/**
 * Opt-in one-click fixes: preview → single-use token → explicit confirmation → apply → log → undo.
 *
 * Nothing here runs from cron or from AI output. Fixes are off until "Allow one-click fixes" is on.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Fix;

use Dev3\AiSiteHealth\Analyze\Fingerprint;
use Dev3\AiSiteHealth\Collect\Integrity;
use Dev3\AiSiteHealth\Core3\Api\Api_Error;
use Dev3\AiSiteHealth\Core3\Core\Rate_Limiter;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Fix engine.
 */
class Fixes {

	const TYPES     = array( 'deactivate_plugin', 'restore_core_file', 'block_ip', 'unschedule_cron', 'disable_xmlrpc' );
	const TOKEN_TTL = 600;

	/**
	 * Fixes allowed (read-only mode off).
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings_Store::get( 'allow_fixes', false );
	}

	/**
	 * Fixes that make sense for an issue: [ { type, target, label } ].
	 *
	 * @param array $issue Issue.
	 * @return array[]
	 */
	public static function applicable( array $issue ) {
		$out       = array();
		$component = (string) $issue['component'];
		$details   = isset( $issue['context']['details'] ) ? (array) $issue['context']['details'] : array();
		if ( 0 === strpos( $component, 'plugin:' ) && in_array( $issue['source'], array( 'php', 'query', 'integrity' ), true ) ) {
			$slug = substr( $component, 7 );
			$file = Fingerprint::plugin_file( $slug );
			if ( $file && is_plugin_active( $file ) && plugin_basename( DEV3SH_FILE ) !== $file ) {
				$out[] = array(
					'type'   => 'deactivate_plugin',
					'target' => $slug,
					/* translators: %s: plugin. */
					'label'  => sprintf( __( 'Deactivate %s', 'dev3-ai-site-health-analyst' ), Fingerprint::plugin_name( $slug ) ),
				);
			}
		}
		if ( 'integrity' === $issue['source'] && 'core_modified' === $issue['kind'] ) {
			foreach ( array_slice( array_merge( (array) ( $details['modified'] ?? array() ), (array) ( $details['missing'] ?? array() ) ), 0, 20 ) as $file ) {
				$out[] = array(
					'type'   => 'restore_core_file',
					'target' => (string) $file,
					/* translators: %s: file. */
					'label'  => sprintf( __( 'Restore %s from wordpress.org', 'dev3-ai-site-health-analyst' ), $file ),
				);
			}
		}
		if ( 'security' === $issue['source'] && ! empty( $details['ip'] ) && ! Firewall::is_blocked( (string) $details['ip'] ) ) {
			$out[] = array(
				'type'   => 'block_ip',
				'target' => (string) $details['ip'],
				/* translators: %s: IP. */
				'label'  => sprintf( __( 'Block %s', 'dev3-ai-site-health-analyst' ), $details['ip'] ),
			);
		}
		if ( 'security' === $issue['source'] && in_array( $issue['kind'], array( 'login_failed', 'brute_force' ), true ) && ! Settings_Store::get( 'xmlrpc_disabled', false ) ) {
			$out[] = array(
				'type'   => 'disable_xmlrpc',
				'target' => 'xmlrpc',
				'label'  => __( 'Disable XML-RPC', 'dev3-ai-site-health-analyst' ),
			);
		}
		if ( 'cron' === $issue['source'] && 'orphan' === $issue['kind'] && ! empty( $details['hook'] ) ) {
			$out[] = array(
				'type'   => 'unschedule_cron',
				'target' => (string) $details['hook'],
				/* translators: %s: hook. */
				'label'  => sprintf( __( 'Remove scheduled task %s', 'dev3-ai-site-health-analyst' ), $details['hook'] ),
			);
		}
		return $out;
	}

	/**
	 * Previews a fix and issues a single-use confirmation token.
	 *
	 * @param string $type     Type.
	 * @param string $target   Target.
	 * @param int    $issue_id Issue (optional, for the log).
	 * @return array|\WP_Error
	 */
	public static function preview( $type, $target, $issue_id = 0 ) {
		if ( ! self::enabled() ) {
			return self::disabled_error();
		}
		$plan = self::plan( (string) $type, (string) $target );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$token = wp_generate_password( 32, false );
		set_transient(
			'dev3sh_fix_' . md5( $token ),
			array(
				'type'     => $plan['type'],
				'target'   => $plan['target'],
				'issue_id' => (int) $issue_id,
				'user'     => get_current_user_id(),
			),
			self::TOKEN_TTL
		);
		return $plan + array(
			'token'      => $token,
			'expires_in' => self::TOKEN_TTL,
		);
	}

	/**
	 * Applies a previewed fix.
	 *
	 * @param string $token   Token from preview().
	 * @param bool   $confirm Explicit confirmation.
	 * @return array|\WP_Error
	 */
	public static function apply( $token, $confirm ) {
		if ( ! self::enabled() ) {
			return self::disabled_error();
		}
		if ( true !== $confirm ) {
			return Api_Error::make( 'dev3sh_fix_unconfirmed', __( 'Please confirm the change first.', 'dev3-ai-site-health-analyst' ), '', 400 );
		}
		$key  = 'dev3sh_fix_' . md5( (string) $token );
		$data = '' !== (string) $token ? get_transient( $key ) : false;
		if ( ! is_array( $data ) || get_current_user_id() !== (int) $data['user'] ) {
			return Api_Error::make( 'dev3sh_fix_token', __( 'This confirmation has expired. Preview the fix again.', 'dev3-ai-site-health-analyst' ), '', 400 );
		}
		delete_transient( $key ); // Single use.
		$plan = self::plan( $data['type'], $data['target'] );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$undo = self::run( $plan );
		if ( is_wp_error( $undo ) ) {
			return $undo;
		}
		$user = wp_get_current_user();
		$id   = Events::add(
			'fix',
			array(
				'rule'       => $plan['type'],
				'severity'   => 'info',
				'ip'         => Rate_Limiter::client_ip(),
				'user_login' => $user->user_login,
				'user_id'    => (int) $user->ID,
				'path'       => mb_substr( $plan['target'], 0, 255 ),
				'message'    => $plan['title'],
				'meta'       => array(
					'type'     => $plan['type'],
					'target'   => $plan['target'],
					'issue_id' => (int) $data['issue_id'],
					'undo'     => $undo,
					'undone'   => false,
				),
			)
		);
		/**
		 * Fires after a one-click fix was applied.
		 *
		 * @param array $plan     Plan.
		 * @param int   $event_id Fix log id.
		 */
		do_action( 'dev3sh_fix_applied', $plan, $id );
		return array(
			'applied' => true,
			'id'      => $id,
			'type'    => $plan['type'],
			'target'  => $plan['target'],
			'message' => $plan['done'],
		);
	}

	/**
	 * Undoes a logged fix.
	 *
	 * @param int $event_id Fix event id.
	 * @return array|\WP_Error
	 */
	public static function undo( $event_id ) {
		$event = Events::get( $event_id );
		if ( ! $event || 'fix' !== $event['type'] ) {
			return Api_Error::make( 'dev3sh_fix_missing', __( 'Fix not found.', 'dev3-ai-site-health-analyst' ), '', 404 );
		}
		$meta = $event['meta'];
		if ( ! empty( $meta['undone'] ) ) {
			return Api_Error::make( 'dev3sh_fix_undone', __( 'This fix was already undone.', 'dev3-ai-site-health-analyst' ), '', 400 );
		}
		$undo = isset( $meta['undo'] ) ? (array) $meta['undo'] : array();
		switch ( $meta['type'] ) {
			case 'deactivate_plugin':
				$r = activate_plugin( (string) $undo['file'] );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				break;
			case 'restore_core_file':
				if ( empty( $undo['backup'] ) || ! is_readable( $undo['backup'] ) ) {
					return Api_Error::make( 'dev3sh_fix_no_backup', __( 'The backup of this file is gone, so it cannot be undone.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				if ( ! self::write_file( trailingslashit( ABSPATH ) . $meta['target'], (string) file_get_contents( $undo['backup'] ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					return Api_Error::make( 'dev3sh_fix_write', __( 'The file could not be written.', 'dev3-ai-site-health-analyst' ), '', 500 );
				}
				break;
			case 'block_ip':
				Firewall::unblock( (string) $meta['target'] );
				break;
			case 'unschedule_cron':
				foreach ( (array) ( $undo['events'] ?? array() ) as $ev ) {
					if ( $ev['schedule'] ) {
						wp_schedule_event( max( time() + 60, (int) $ev['ts'] ), $ev['schedule'], $meta['target'], (array) $ev['args'] );
					} else {
						wp_schedule_single_event( max( time() + 60, (int) $ev['ts'] ), $meta['target'], (array) $ev['args'] );
					}
				}
				break;
			case 'disable_xmlrpc':
				Settings_Store::update( array( 'xmlrpc_disabled' => false ) );
				break;
		}
		$meta['undone']    = true;
		$meta['undone_at'] = time();
		$meta['undone_by'] = get_current_user_id();
		Events::set_meta( $event_id, $meta );
		return array(
			'undone' => true,
			'id'     => (int) $event_id,
		);
	}

	/**
	 * Fix history.
	 *
	 * @param int $page Page.
	 * @return array
	 */
	public static function history( $page = 1 ) {
		$res            = Events::query(
			array(
				'type'     => 'fix',
				'page'     => $page,
				'per_page' => 20,
			)
		);
		$res['enabled'] = self::enabled();
		return $res;
	}

	/**
	 * Validates a fix and describes it.
	 *
	 * @param string $type   Type.
	 * @param string $target Target.
	 * @return array|\WP_Error { type, target, title, description, effects[], undo, done }
	 */
	public static function plan( $type, $target ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return Api_Error::make( 'dev3sh_fix_type', __( 'Unknown fix.', 'dev3-ai-site-health-analyst' ), '', 400 );
		}
		switch ( $type ) {
			case 'deactivate_plugin':
				$slug = sanitize_title( $target );
				$file = Fingerprint::plugin_file( $slug );
				if ( '' === $file || ! is_plugin_active( $file ) ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'That plugin is not active.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				if ( plugin_basename( DEV3SH_FILE ) === $file ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'This plugin cannot deactivate itself from here.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				$name = Fingerprint::plugin_name( $slug );
				return array(
					'type'        => $type,
					'target'      => $slug,
					/* translators: %s: plugin. */
					'title'       => sprintf( __( 'Deactivate %s', 'dev3-ai-site-health-analyst' ), $name ),
					'description' => __( 'The plugin is switched off. Its files and settings stay on the site.', 'dev3-ai-site-health-analyst' ),
					'effects'     => array(
						__( 'Features of this plugin (shortcodes, blocks, payment methods, forms…) stop working until it is activated again.', 'dev3-ai-site-health-analyst' ),
						__( 'Errors caused by the plugin stop.', 'dev3-ai-site-health-analyst' ),
					),
					'undo'        => true,
					/* translators: %s: plugin. */
					'done'        => sprintf( __( '%s was deactivated.', 'dev3-ai-site-health-analyst' ), $name ),
					'file'        => $file,
				);
			case 'restore_core_file':
				$rel  = ltrim( str_replace( '\\', '/', (string) $target ), '/' );
				$sums = Integrity::core_checksums( get_bloginfo( 'version' ) );
				if ( ! $sums || ! isset( $sums[ $rel ] ) || 0 === strpos( $rel, 'wp-content/' ) || false !== strpos( $rel, '..' ) ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'That is not a WordPress core file.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				$path = trailingslashit( ABSPATH ) . $rel;
				if ( file_exists( $path ) && md5_file( $path ) === $sums[ $rel ] ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'That file already matches wordpress.org.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				return array(
					'type'        => $type,
					'target'      => $rel,
					/* translators: %s: file. */
					'title'       => sprintf( __( 'Restore %s from wordpress.org', 'dev3-ai-site-health-analyst' ), $rel ),
					'description' => __( 'The official copy of this file is downloaded, checked against the wordpress.org checksum and written over the changed file. A backup of the current file is kept first.', 'dev3-ai-site-health-analyst' ),
					'effects'     => array(
						__( 'Custom edits in this file are lost (the backup keeps them).', 'dev3-ai-site-health-analyst' ),
						__( 'If the change was malware, other infected files may remain: change passwords and run the check again.', 'dev3-ai-site-health-analyst' ),
					),
					'undo'        => true,
					/* translators: %s: file. */
					'done'        => sprintf( __( '%s was restored.', 'dev3-ai-site-health-analyst' ), $rel ),
					'md5'         => $sums[ $rel ],
				);
			case 'block_ip':
				$ip = trim( (string) $target );
				if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'That is not a valid IP address.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				if ( Rate_Limiter::client_ip() === $ip ) {
					return Api_Error::make( 'dev3sh_fix_self', __( 'That is your own IP address. Blocking it would lock you out.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				$days = max( 1, (int) Settings_Store::get( 'block_days', 30 ) );
				return array(
					'type'        => $type,
					'target'      => $ip,
					/* translators: %s: IP. */
					'title'       => sprintf( __( 'Block %s', 'dev3-ai-site-health-analyst' ), $ip ),
					/* translators: %d: days. */
					'description' => sprintf( _n( 'Requests from this address get "403 Forbidden" for %d day.', 'Requests from this address get "403 Forbidden" for %d days.', $days, 'dev3-ai-site-health-analyst' ), $days ),
					'effects'     => array(
						__( 'If the address is shared (office, mobile network, VPN), real visitors behind it are blocked too.', 'dev3-ai-site-health-analyst' ),
						__( 'Behind a CDN or proxy, set the trusted IP header first, or every visitor may share one address.', 'dev3-ai-site-health-analyst' ),
					),
					'undo'        => true,
					/* translators: %s: IP. */
					'done'        => sprintf( __( '%s is blocked.', 'dev3-ai-site-health-analyst' ), $ip ),
				);
			case 'unschedule_cron':
				$hook = sanitize_text_field( (string) $target );
				if ( '' === $hook || ! self::cron_events( $hook ) ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'That scheduled task does not exist.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				if ( has_action( $hook ) ) {
					return Api_Error::make( 'dev3sh_fix_target', __( 'Code still handles this task, so it is not removed.', 'dev3-ai-site-health-analyst' ), '', 400 );
				}
				return array(
					'type'        => $type,
					'target'      => $hook,
					/* translators: %s: hook. */
					'title'       => sprintf( __( 'Remove scheduled task %s', 'dev3-ai-site-health-analyst' ), $hook ),
					/* translators: %d: count. */
					'description' => sprintf( _n( '%d scheduled run of this task is removed.', '%d scheduled runs of this task are removed.', count( self::cron_events( $hook ) ), 'dev3-ai-site-health-analyst' ), count( self::cron_events( $hook ) ) ),
					'effects'     => array( __( 'Nothing handles this task today, so nothing stops working. A plugin that is re-installed later schedules it again.', 'dev3-ai-site-health-analyst' ) ),
					'undo'        => true,
					/* translators: %s: hook. */
					'done'        => sprintf( __( '%s was removed.', 'dev3-ai-site-health-analyst' ), $hook ),
				);
			case 'disable_xmlrpc':
				return array(
					'type'        => $type,
					'target'      => 'xmlrpc',
					'title'       => __( 'Disable XML-RPC', 'dev3-ai-site-health-analyst' ),
					'description' => __( 'xmlrpc.php stops accepting logins and method calls. Attackers use it to try many passwords in one request.', 'dev3-ai-site-health-analyst' ),
					'effects'     => array( __( 'The old WordPress mobile app, Jetpack and some remote publishing tools need XML-RPC. Do not disable it if you use them.', 'dev3-ai-site-health-analyst' ) ),
					'undo'        => true,
					'done'        => __( 'XML-RPC is disabled.', 'dev3-ai-site-health-analyst' ),
				);
		}
		return Api_Error::make( 'dev3sh_fix_type', __( 'Unknown fix.', 'dev3-ai-site-health-analyst' ), '', 400 );
	}

	/**
	 * Executes a plan.
	 *
	 * @param array $plan Plan.
	 * @return array|\WP_Error Undo data.
	 */
	private static function run( array $plan ) {
		switch ( $plan['type'] ) {
			case 'deactivate_plugin':
				deactivate_plugins( $plan['file'] );
				return array( 'file' => $plan['file'] );
			case 'restore_core_file':
				$url = sprintf( 'https://core.svn.wordpress.org/tags/%s/%s', rawurlencode( get_bloginfo( 'version' ) ), implode( '/', array_map( 'rawurlencode', explode( '/', $plan['target'] ) ) ) );
				$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
				if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
					return Api_Error::make( 'dev3sh_fix_download', __( 'The official file could not be downloaded from wordpress.org.', 'dev3-ai-site-health-analyst' ), '', 502 );
				}
				$body = (string) wp_remote_retrieve_body( $res );
				if ( md5( $body ) !== $plan['md5'] ) {
					return Api_Error::make( 'dev3sh_fix_checksum', __( 'The downloaded file did not match the official checksum, so nothing was changed.', 'dev3-ai-site-health-analyst' ), '', 502 );
				}
				$path   = trailingslashit( ABSPATH ) . $plan['target'];
				$backup = '';
				if ( file_exists( $path ) ) {
					$backup = self::backup( $path, $plan['target'] );
					if ( '' === $backup ) {
						return Api_Error::make( 'dev3sh_fix_backup', __( 'A backup of the current file could not be written, so nothing was changed.', 'dev3-ai-site-health-analyst' ), '', 500 );
					}
				}
				if ( ! self::write_file( $path, $body ) ) {
					return Api_Error::make( 'dev3sh_fix_write', __( 'The file could not be written. Check file permissions.', 'dev3-ai-site-health-analyst' ), '', 500 );
				}
				return array( 'backup' => $backup );
			case 'block_ip':
				Firewall::block( $plan['target'], __( 'Blocked from DEV3 AI Site Health', 'dev3-ai-site-health-analyst' ) );
				return array();
			case 'unschedule_cron':
				$events = self::cron_events( $plan['target'] );
				wp_unschedule_hook( $plan['target'] );
				return array( 'events' => $events );
			case 'disable_xmlrpc':
				Settings_Store::update( array( 'xmlrpc_disabled' => true ) );
				return array();
		}
		return new \WP_Error( 'dev3sh_fix_type', 'unknown' );
	}

	/**
	 * Scheduled runs of a hook.
	 *
	 * @param string $hook Hook.
	 * @return array[] { ts, schedule, args }
	 */
	private static function cron_events( $hook ) {
		$out = array();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( isset( $hooks[ $hook ] ) ) {
				foreach ( (array) $hooks[ $hook ] as $event ) {
					$out[] = array(
						'ts'       => (int) $ts,
						'schedule' => isset( $event['schedule'] ) && $event['schedule'] ? (string) $event['schedule'] : '',
						'args'     => isset( $event['args'] ) ? (array) $event['args'] : array(),
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Backup folder (uploads/dev3sh-backups, not web-listable).
	 *
	 * @return string
	 */
	public static function backup_dir() {
		$up  = wp_upload_dir( null, false );
		$dir = trailingslashit( $up['basedir'] ) . 'dev3sh-backups';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::write_file( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			self::write_file( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		return $dir;
	}

	/**
	 * Copies a file into the backup folder.
	 *
	 * @param string $path Absolute path.
	 * @param string $rel  Relative name.
	 * @return string Backup path or ''.
	 */
	private static function backup( $path, $rel ) {
		$dest = self::backup_dir() . '/' . gmdate( 'Ymd-His' ) . '-' . str_replace( '/', '__', $rel ) . '.bak';
		return copy( $path, $dest ) ? $dest : '';
	}

	/**
	 * Writes a file with WP_Filesystem (direct).
	 *
	 * @param string $path    Path.
	 * @param string $content Content.
	 * @return bool
	 */
	private static function write_file( $path, $content ) {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! $wp_filesystem && ! WP_Filesystem( false, false, true ) ) {
			return false;
		}
		if ( ! $wp_filesystem ) {
			return false;
		}
		wp_mkdir_p( dirname( $path ) );
		return (bool) $wp_filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
	}

	/**
	 * Error for read-only mode.
	 *
	 * @return \WP_Error
	 */
	private static function disabled_error() {
		return Api_Error::make( 'dev3sh_fixes_disabled', __( 'Read-only mode is on: one-click fixes are switched off. Turn on "Allow one-click fixes" in Settings to use them.', 'dev3-ai-site-health-analyst' ), '', 403 );
	}
}
