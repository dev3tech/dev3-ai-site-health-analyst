<?php
/**
 * File integrity: core files vs api.wordpress.org checksums, wordpress.org plugins vs plugin checksums.
 * Runs as a background job (one item = core or one plugin).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Checksum comparison.
 */
class Integrity {

	const JOB    = 'dev3sh_integrity';
	const RESULT = 'dev3sh_integrity';

	/**
	 * Core files that may legitimately be missing.
	 */
	const OPTIONAL = array( 'wp-config-sample.php', 'readme.html', 'license.txt', 'licencia.txt', 'liesmich.html', 'xmlrpc.php' );

	/**
	 * Registers the job handler (every request).
	 */
	public static function register() {
		Jobs::register( self::JOB, array( __CLASS__, 'run_chunk' ) );
		add_action( 'dev3sh_job_finished_' . self::JOB, array( __CLASS__, 'finish' ) );
	}

	/**
	 * Items for a full check.
	 *
	 * @return string[]
	 */
	public static function items() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$items = array( 'core' );
		foreach ( array_keys( get_plugins() ) as $file ) {
			$items[] = 'plugin:' . $file;
		}
		return $items;
	}

	/**
	 * Starts a job (or returns the running one).
	 *
	 * @return array|\WP_Error Job.
	 */
	public static function start() {
		$active = Jobs::active( self::JOB );
		if ( $active ) {
			return $active;
		}
		$id = Jobs::create( self::JOB, self::items(), array(), 3 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Items.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		$out     = array();
		$partial = array();
		foreach ( $items as $item ) {
			if ( 'core' === $item ) {
				$partial['core'] = self::check_core();
				$out[ $item ]    = 'error' === $partial['core']['status'] ? $partial['core']['message'] : true;
			} elseif ( 0 === strpos( $item, 'plugin:' ) ) {
				$file                        = substr( $item, 7 );
				$partial['plugins'][ $file ] = self::check_plugin( $file );
				$out[ $item ]                = true;
			}
		}
		$prev = isset( $job['id'] ) ? Jobs::get( $job['id'] ) : null;
		$res  = $prev ? self::to_array( $prev['result'] ) : array();
		if ( isset( $partial['core'] ) ) {
			$res['core'] = $partial['core'];
		}
		if ( isset( $partial['plugins'] ) ) {
			$res['plugins'] = array_merge( isset( $res['plugins'] ) ? (array) $res['plugins'] : array(), $partial['plugins'] );
		}
		if ( isset( $job['id'] ) ) {
			Jobs::set_result( $job['id'], $res );
		}
		return $out;
	}

	/**
	 * Job finished: stores the result and records issues.
	 *
	 * @param array $job Job.
	 */
	public static function finish( $job ) {
		$res = self::to_array( $job['result'] );
		self::save( isset( $res['core'] ) ? $res['core'] : null, isset( $res['plugins'] ) ? (array) $res['plugins'] : array() );
	}

	/**
	 * Job results may come back as objects: normalise to arrays.
	 *
	 * @param mixed $value Value.
	 * @return array
	 */
	private static function to_array( $value ) {
		$value = json_decode( (string) wp_json_encode( $value ), true );
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Runs everything inline (WP-CLI / tests).
	 *
	 * @return array Result.
	 */
	public static function run_now() {
		$plugins = array();
		foreach ( self::items() as $item ) {
			if ( 0 === strpos( $item, 'plugin:' ) ) {
				$plugins[ substr( $item, 7 ) ] = self::check_plugin( substr( $item, 7 ) );
			}
		}
		return self::save( self::check_core(), $plugins );
	}

	/**
	 * Saves the final result + issues.
	 *
	 * @param array|null $core    Core result.
	 * @param array      $plugins Plugin results by file.
	 * @return array
	 */
	public static function save( $core, array $plugins ) {
		$result = array(
			'at'      => time(),
			'core'    => $core,
			'plugins' => array_values( $plugins ),
			'summary' => array(
				'core_modified'   => $core ? count( $core['modified'] ) : 0,
				'core_missing'    => $core ? count( $core['missing'] ) : 0,
				'core_unknown'    => $core ? count( $core['unknown'] ) : 0,
				'plugins_ok'      => count( wp_list_filter( $plugins, array( 'status' => 'ok' ) ) ),
				'plugins_changed' => count( wp_list_filter( $plugins, array( 'status' => 'modified' ) ) ),
				'plugins_unknown' => count( wp_list_filter( $plugins, array( 'status' => 'unverifiable' ) ) ),
			),
		);
		update_option( self::RESULT, $result, false );
		$keep = array();
		if ( $core && ( $core['modified'] || $core['missing'] ) ) {
			$n      = count( $core['modified'] ) + count( $core['missing'] );
			$keep[] = Issues::record(
				array(
					'source'      => 'integrity',
					'kind'        => 'core_modified',
					'fingerprint' => 'integrity:core',
					'severity'    => 'critical',
					/* translators: %d: files. */
					'title'       => sprintf( _n( '%d WordPress core file does not match wordpress.org', '%d WordPress core files do not match wordpress.org', $n, 'dev3-ai-site-health-analyst' ), $n ),
					'message'     => __( 'Core files should never change. A modified core file is a common sign of a hacked site or an unsafe manual edit.', 'dev3-ai-site-health-analyst' ),
					'component'   => 'core',
					'replace'     => true,
					'count'       => $n,
					'details'     => array(
						'modified' => array_slice( $core['modified'], 0, 100 ),
						'missing'  => array_slice( $core['missing'], 0, 100 ),
						'version'  => $core['version'],
					),
				)
			);
		}
		if ( $core && $core['unknown'] ) {
			$keep[] = Issues::record(
				array(
					'source'      => 'integrity',
					'kind'        => 'core_unknown',
					'fingerprint' => 'integrity:core_unknown',
					'severity'    => 'high',
					/* translators: %d: files. */
					'title'       => sprintf( _n( '%d unknown PHP file inside wp-admin or wp-includes', '%d unknown PHP files inside wp-admin or wp-includes', count( $core['unknown'] ), 'dev3-ai-site-health-analyst' ), count( $core['unknown'] ) ),
					'message'     => __( 'These folders contain only WordPress files. Extra PHP files there are often backdoors left by an attacker.', 'dev3-ai-site-health-analyst' ),
					'component'   => 'core',
					'replace'     => true,
					'count'       => count( $core['unknown'] ),
					'details'     => array( 'unknown' => array_slice( $core['unknown'], 0, 100 ) ),
				)
			);
		}
		foreach ( $plugins as $file => $p ) {
			if ( 'modified' !== $p['status'] ) {
				continue;
			}
			$n      = count( $p['modified'] ) + count( $p['missing'] ) + count( $p['added'] );
			$keep[] = Issues::record(
				array(
					'source'      => 'integrity',
					'kind'        => 'plugin_modified',
					'fingerprint' => 'integrity:plugin:' . $file,
					'severity'    => $p['added'] ? 'high' : 'medium',
					/* translators: 1: plugin, 2: files. */
					'title'       => sprintf( __( '%1$s: %2$d files differ from wordpress.org', 'dev3-ai-site-health-analyst' ), $p['name'], $n ),
					'message'     => __( 'Plugin files differ from the official release of this version.', 'dev3-ai-site-health-analyst' ),
					'component'   => 'plugin:' . $p['slug'],
					'replace'     => true,
					'count'       => $n,
					'details'     => array(
						'modified' => array_slice( $p['modified'], 0, 100 ),
						'missing'  => array_slice( $p['missing'], 0, 100 ),
						'added'    => array_slice( $p['added'], 0, 100 ),
						'version'  => $p['version'],
					),
				)
			);
		}
		Issues::resolve_missing( 'integrity', array_filter( $keep ) );
		update_option( 'dev3sh_integrity_last_run', time(), false );
		$status              = get_option( Collector::STATUS, array() );
		$status              = is_array( $status ) ? $status : array();
		$status['integrity'] = array(
			'at'      => time(),
			'status'  => $core && 'error' === $core['status'] ? 'warning' : 'ok',
			'message' => $core && 'error' === $core['status'] ? $core['message'] : '',
		);
		update_option( Collector::STATUS, $status, false );
		return $result;
	}

	/**
	 * Last result.
	 *
	 * @return array|null
	 */
	public static function last() {
		$r = get_option( self::RESULT, null );
		return is_array( $r ) ? $r : null;
	}

	/**
	 * Core checksums (cached a day).
	 *
	 * @param string $version Version.
	 * @return array|false file => md5
	 */
	public static function core_checksums( $version ) {
		$key    = 'dev3sh_core_sums_' . md5( $version . get_locale() );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		require_once ABSPATH . 'wp-admin/includes/update.php';
		$sums = get_core_checksums( $version, get_locale() );
		if ( ! is_array( $sums ) || ! $sums ) {
			$sums = 'en_US' !== get_locale() ? get_core_checksums( $version, 'en_US' ) : false;
		}
		if ( is_array( $sums ) && $sums ) {
			set_transient( $key, $sums, DAY_IN_SECONDS );
			return $sums;
		}
		return false;
	}

	/**
	 * Checks core.
	 *
	 * @return array { status, version, checked, modified[], missing[], unknown[], message }
	 */
	public static function check_core() {
		$version = get_bloginfo( 'version' );
		$out     = array(
			'status'   => 'ok',
			'version'  => $version,
			'checked'  => 0,
			'modified' => array(),
			'missing'  => array(),
			'unknown'  => array(),
			'message'  => '',
		);
		$sums    = self::core_checksums( $version );
		if ( ! $sums ) {
			$out['status']  = 'error';
			$out['message'] = __( 'Could not download the core checksums from wordpress.org.', 'dev3-ai-site-health-analyst' );
			return $out;
		}
		$root = trailingslashit( ABSPATH );
		foreach ( $sums as $file => $md5 ) {
			if ( 0 === strpos( $file, 'wp-content/' ) ) {
				continue;
			}
			++$out['checked'];
			$path = $root . $file;
			if ( ! file_exists( $path ) ) {
				if ( ! in_array( $file, self::OPTIONAL, true ) ) {
					$out['missing'][] = $file;
				}
				continue;
			}
			if ( md5_file( $path ) !== $md5 ) {
				$out['modified'][] = $file;
			}
		}
		foreach ( array( 'wp-admin', 'wp-includes' ) as $dir ) {
			foreach ( self::php_files( $root . $dir, $root ) as $rel ) {
				if ( ! isset( $sums[ $rel ] ) ) {
					$out['unknown'][] = $rel;
				}
			}
		}
		/**
		 * Filters the core integrity result (e.g. to allow known custom files).
		 *
		 * @param array $out Result.
		 */
		$out = apply_filters( 'dev3sh_core_integrity', $out );
		if ( $out['modified'] || $out['missing'] || $out['unknown'] ) {
			$out['status'] = 'modified';
		}
		return $out;
	}

	/**
	 * Checks a plugin against downloads.wordpress.org/plugin-checksums.
	 *
	 * @param string $file Plugin basename.
	 * @return array { file, slug, name, version, status (ok|modified|unverifiable|error), modified[], missing[], added[], message }
	 */
	public static function check_plugin( $file ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all  = get_plugins();
		$data = isset( $all[ $file ] ) ? $all[ $file ] : array(
			'Name'    => $file,
			'Version' => '',
		);
		$slug = dirname( $file );
		$out  = array(
			'file'     => $file,
			'slug'     => '.' === $slug ? basename( $file, '.php' ) : $slug,
			'name'     => (string) $data['Name'],
			'version'  => (string) $data['Version'],
			'status'   => 'ok',
			'modified' => array(),
			'missing'  => array(),
			'added'    => array(),
			'message'  => '',
		);
		if ( '.' === $slug || '' === $out['version'] ) {
			$out['status']  = 'unverifiable';
			$out['message'] = __( 'Single-file plugins and plugins without a version cannot be verified.', 'dev3-ai-site-health-analyst' );
			return $out;
		}
		$url = sprintf( 'https://downloads.wordpress.org/plugin-checksums/%s/%s.json', rawurlencode( $slug ), rawurlencode( $out['version'] ) );
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
			)
		);
		if ( is_wp_error( $res ) ) {
			$out['status']  = 'error';
			$out['message'] = $res->get_error_message();
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['files'] ) ) {
			$out['status']  = 'unverifiable';
			$out['message'] = __( 'Not hosted on wordpress.org (premium or custom plugin), so there are no official checksums.', 'dev3-ai-site-health-analyst' );
			return $out;
		}
		$dir = WP_PLUGIN_DIR . '/' . $slug . '/';
		foreach ( (array) $body['files'] as $rel => $hashes ) {
			$path = $dir . $rel;
			if ( ! file_exists( $path ) ) {
				// Readmes, screenshots and language files are often removed by optimisers: only code counts.
				if ( preg_match( '/\.php$/i', $rel ) ) {
					$out['missing'][] = $rel;
				}
				continue;
			}
			$expected = isset( $hashes['md5'] ) ? (array) $hashes['md5'] : array();
			if ( $expected && ! in_array( md5_file( $path ), $expected, true ) ) {
				$out['modified'][] = $rel;
			}
		}
		foreach ( self::php_files( rtrim( $dir, '/' ), $dir ) as $rel ) {
			if ( ! isset( $body['files'][ $rel ] ) ) {
				$out['added'][] = $rel;
			}
		}
		if ( $out['modified'] || $out['missing'] || $out['added'] ) {
			$out['status'] = 'modified';
		}
		return $out;
	}

	/**
	 * PHP files below a folder (relative to $base), max 5000.
	 *
	 * @param string $dir  Folder.
	 * @param string $base Base to strip.
	 * @return string[]
	 */
	private static function php_files( $dir, $base ) {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$base = trailingslashit( str_replace( '\\', '/', $base ) );
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && preg_match( '/\.(php|phtml|php\d)$/i', $f->getFilename() ) ) {
				$out[] = substr( str_replace( '\\', '/', $f->getPathname() ), strlen( $base ) );
				if ( count( $out ) >= 5000 ) {
					break;
				}
			}
		}
		return $out;
	}
}
