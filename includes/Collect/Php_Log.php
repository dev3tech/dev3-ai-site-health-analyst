<?php
/**
 * PHP error log collector: finds debug.log, tails new bytes only, detects rotation/truncation.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

use Dev3\AiSiteHealth\Analyze\Fingerprint;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Tail state lives in the dev3sh_log_state option: { path, offset, size, inode, head }.
 */
class Php_Log {

	const STATE = 'dev3sh_log_state';

	/**
	 * Bytes hashed at the start of the file to notice rotation.
	 */
	const HEAD_BYTES = 256;

	/**
	 * Resolves the log path + why.
	 *
	 * @return array { path, source (wp_debug_log|ini|custom|none), enabled, readable, size, mtime, message }
	 */
	public static function locate() {
		$custom = trim( (string) Settings_Store::get( 'log_path', '' ) );
		$path   = '';
		$source = 'none';
		if ( '' !== $custom ) {
			$path   = $custom;
			$source = 'custom';
		} elseif ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$path   = is_string( WP_DEBUG_LOG ) && ! in_array( strtolower( WP_DEBUG_LOG ), array( '1', 'true' ), true ) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
			$source = 'wp_debug_log';
		} elseif ( ini_get( 'log_errors' ) && ini_get( 'error_log' ) && ! in_array( ini_get( 'error_log' ), array( 'syslog', 'stderr' ), true ) ) {
			$path   = (string) ini_get( 'error_log' );
			$source = 'ini';
		}
		/**
		 * Filters the error log path the collector reads.
		 *
		 * @param string $path   Path ('' = none).
		 * @param string $source Where it came from.
		 */
		$path = (string) apply_filters( 'dev3sh_log_path', $path, $source );
		$out  = array(
			'path'     => $path,
			'display'  => Fingerprint::relative( $path ),
			'source'   => '' === $path ? 'none' : $source,
			'enabled'  => '' !== $path,
			'exists'   => false,
			'readable' => false,
			'size'     => 0,
			'mtime'    => 0,
			'message'  => '',
		);
		if ( '' === $path ) {
			$out['message'] = __( 'PHP error logging is not enabled. Add WP_DEBUG, WP_DEBUG_LOG and WP_DEBUG_DISPLAY (false) to wp-config.php, or set a custom log path in Settings.', 'dev3-ai-site-health-analyst' );
			return $out;
		}
		$valid = self::validate_path( $path );
		if ( is_wp_error( $valid ) ) {
			$out['message'] = $valid->get_error_message();
			return $out;
		}
		clearstatcache( true, $path );
		$out['exists'] = file_exists( $path );
		if ( ! $out['exists'] ) {
			$out['message'] = __( 'Logging is enabled, but the log file does not exist yet. It is created with the first error.', 'dev3-ai-site-health-analyst' );
			return $out;
		}
		$out['readable'] = is_readable( $path );
		$out['size']     = (int) filesize( $path );
		$out['mtime']    = (int) filemtime( $path );
		if ( ! $out['readable'] ) {
			$out['message'] = __( 'The log file exists but PHP cannot read it. Ask your host to fix the file permissions.', 'dev3-ai-site-health-analyst' );
		}
		return $out;
	}

	/**
	 * Custom paths must be a .log/.txt file and not a symlink (keeps the reader away from other files).
	 *
	 * @param string $path Path.
	 * @return true|\WP_Error
	 */
	public static function validate_path( $path ) {
		$path = (string) $path;
		if ( ! preg_match( '/\.(log|txt)(\.\d+)?$/i', $path ) || false !== strpos( $path, "\0" ) || preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return new \WP_Error( 'dev3sh_log_path', __( 'The log path must point to a .log or .txt file.', 'dev3-ai-site-health-analyst' ) );
		}
		if ( is_link( $path ) ) {
			return new \WP_Error( 'dev3sh_log_path', __( 'The log path must not be a symbolic link.', 'dev3-ai-site-health-analyst' ) );
		}
		return true;
	}

	/**
	 * Reads new log text since the last run (rotation aware).
	 *
	 * @param int $max_bytes Max bytes to read this run.
	 * @return array { text, bytes, rotated (bool), skipped (bytes not read because the backlog was too big), info }
	 */
	public static function read_new( $max_bytes = 0 ) {
		$max_bytes = $max_bytes > 0 ? (int) $max_bytes : max( 65536, (int) Settings_Store::get( 'log_max_kb', 2048 ) * 1024 );
		$info      = self::locate();
		$out       = array(
			'text'    => '',
			'bytes'   => 0,
			'rotated' => false,
			'skipped' => 0,
			'info'    => $info,
		);
		if ( ! $info['readable'] ) {
			return $out;
		}
		$path  = $info['path'];
		$state = get_option( self::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		$size  = (int) $info['size'];
		$inode = (int) @fileinode( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$head  = self::head_hash( $path );

		$offset = 0;
		if ( isset( $state['path'] ) && $state['path'] === $path ) {
			$offset  = (int) $state['offset'];
			$rotated = $size < $offset
				|| ( ! empty( $state['inode'] ) && $inode && (int) $state['inode'] !== $inode )
				|| ( ! empty( $state['head'] ) && (int) $state['size'] >= self::HEAD_BYTES && $state['head'] !== $head );
			if ( $rotated ) {
				$out['rotated'] = true;
				$out['text']   .= self::read_rotated_rest( $path, $state, $max_bytes );
				$offset         = 0;
			}
		} elseif ( $size > $max_bytes ) {
			// First run on a big log: start near the end (tail) instead of parsing years of history.
			$offset         = $size - $max_bytes;
			$out['skipped'] = $offset;
		}

		$budget = max( 0, $max_bytes - strlen( $out['text'] ) );
		if ( $size - $offset > $budget ) {
			$out['skipped'] += ( $size - $offset ) - $budget;
			$offset          = $size - $budget;
		}
		$chunk = self::read_range( $path, $offset, $size - $offset );
		// Only consume complete lines; a half-written last line is read next time.
		$cut = strrpos( $chunk, "\n" );
		if ( false === $cut ) {
			$chunk = '';
			$end   = $offset;
		} else {
			$chunk = substr( $chunk, 0, $cut + 1 );
			$end   = $offset + $cut + 1;
		}
		// When we jumped into the middle of the file, drop the partial first line.
		if ( $out['skipped'] > 0 && $offset > 0 ) {
			$nl    = strpos( $chunk, "\n" );
			$chunk = false === $nl ? '' : substr( $chunk, $nl + 1 );
		}
		$out['text'] .= $chunk;
		$out['bytes'] = strlen( $out['text'] );
		update_option(
			self::STATE,
			array(
				'path'    => $path,
				'offset'  => $end,
				'size'    => $size,
				'inode'   => $inode,
				'head'    => $head,
				'read_at' => time(),
				'rotated' => $out['rotated'] ? time() : ( isset( $state['rotated'] ) ? (int) $state['rotated'] : 0 ),
			),
			false
		);
		return $out;
	}

	/**
	 * Collects: reads new text, parses it and records issues.
	 *
	 * @return array { entries, issues, bytes, rotated, status, message }
	 */
	public static function collect() {
		$read    = self::read_new();
		$entries = Log_Parser::parse( $read['text'] );
		$touched = array();
		foreach ( $entries as $entry ) {
			$id = self::record( $entry );
			if ( $id ) {
				$touched[ $id ] = true;
			}
		}
		return array(
			'entries' => count( $entries ),
			'issues'  => count( $touched ),
			'bytes'   => $read['bytes'],
			'rotated' => $read['rotated'],
			'skipped' => $read['skipped'],
			'status'  => $read['info']['readable'] ? 'ok' : ( $read['info']['enabled'] ? 'warning' : 'off' ),
			'message' => $read['info']['message'],
		);
	}

	/**
	 * Records one parsed entry as an issue occurrence.
	 *
	 * @param array $entry Entry.
	 * @return int Issue id.
	 */
	public static function record( array $entry ) {
		if ( 'deprecated' === $entry['level'] && ! Settings_Store::get( 'log_deprecated', true ) ) {
			return 0;
		}
		if ( 'notice' === $entry['level'] && ! Settings_Store::get( 'log_notices', true ) ) {
			return 0;
		}
		$file      = Fingerprint::relative( $entry['file'] );
		$component = Fingerprint::component( $entry['file'] );
		if ( '' === $component && $entry['trace'] ) {
			// Fatal inside core but caused by a plugin: blame the first non-core frame.
			foreach ( $entry['trace'] as $frame ) {
				if ( preg_match( '/(\S+\.php)/', $frame, $m ) ) {
					$c = Fingerprint::component( $m[1] );
					if ( '' !== $c && 'core' !== $c ) {
						$component = $c;
						break;
					}
				}
			}
		}
		return Issues::record(
			array(
				'source'    => 'php',
				'kind'      => $entry['level'],
				'severity'  => Log_Parser::SEVERITY[ $entry['level'] ],
				'title'     => Fingerprint::relative_in_text( Log_Parser::title( $entry ) ),
				'message'   => Fingerprint::relative_in_text( $entry['message'] ),
				'file'      => $file,
				'line'      => $entry['line'],
				'component' => $component,
				'time'      => $entry['time'] ? $entry['time'] : time(),
				'sample'    => Fingerprint::relative_in_text( $entry['raw'] ),
			)
		);
	}

	/**
	 * Last N lines of the log for the viewer (parsed, newest first).
	 *
	 * @param int    $lines Lines.
	 * @param string $level Level filter ('' = all).
	 * @return array
	 */
	public static function tail( $lines = 200, $level = '' ) {
		$info = self::locate();
		$out  = array(
			'info'    => self::public_info( $info ),
			'entries' => array(),
		);
		if ( ! $info['readable'] ) {
			return $out;
		}
		$bytes   = min( (int) $info['size'], max( 8192, $lines * 400 ) );
		$text    = self::read_range( $info['path'], (int) $info['size'] - $bytes, $bytes );
		$nl      = strpos( $text, "\n" );
		$text    = ( $bytes < (int) $info['size'] && false !== $nl ) ? substr( $text, $nl + 1 ) : $text;
		$entries = array_reverse( Log_Parser::parse( $text ) );
		foreach ( $entries as $entry ) {
			if ( '' !== $level && $entry['level'] !== $level ) {
				continue;
			}
			$out['entries'][] = array(
				'level'     => $entry['level'],
				'severity'  => Log_Parser::SEVERITY[ $entry['level'] ],
				'message'   => Fingerprint::relative_in_text( $entry['message'] ),
				'file'      => Fingerprint::relative( $entry['file'] ),
				'line'      => $entry['line'],
				'time'      => $entry['time'] ? gmdate( 'c', $entry['time'] ) : '',
				'component' => Fingerprint::component( $entry['file'] ),
				'trace'     => array_map( array( Fingerprint::class, 'relative_in_text' ), array_slice( $entry['trace'], 0, 12 ) ),
				'issue'     => Issues::id_for( Fingerprint::make( 'php', $entry['level'], $entry['message'], $entry['file'], $entry['line'] ) ),
			);
			if ( count( $out['entries'] ) >= $lines ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Info safe to show in the admin (relative path, state).
	 *
	 * @param array $info locate() result.
	 * @return array
	 */
	public static function public_info( array $info ) {
		$state = get_option( self::STATE, array() );
		return array(
			'path'       => $info['display'],
			'source'     => $info['source'],
			'enabled'    => $info['enabled'],
			'exists'     => $info['exists'],
			'readable'   => $info['readable'],
			'size'       => $info['size'],
			'modified'   => $info['mtime'] ? gmdate( 'c', $info['mtime'] ) : '',
			'message'    => $info['message'],
			'read_at'    => ! empty( $state['read_at'] ) ? gmdate( 'c', (int) $state['read_at'] ) : '',
			'offset'     => isset( $state['offset'] ) ? (int) $state['offset'] : 0,
			'rotated_at' => ! empty( $state['rotated'] ) ? gmdate( 'c', (int) $state['rotated'] ) : '',
			'public'     => self::is_public( $info['path'] ),
		);
	}

	/**
	 * Whether the log sits in a web-reachable folder with a default name (wp-content/debug.log).
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_public( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/';
		return '' !== $path && 0 === strpos( $path, $root );
	}

	/**
	 * Reads the unread end of a rotated file (path.1, path.0, path-YYYYMMDD).
	 *
	 * @param string $path      Current path.
	 * @param array  $state     Previous state.
	 * @param int    $max_bytes Budget.
	 * @return string
	 */
	private static function read_rotated_rest( $path, array $state, $max_bytes ) {
		$candidates = array( $path . '.1', $path . '.0' );
		$dated      = glob( $path . '-[0-9]*' );
		if ( $dated ) {
			rsort( $dated );
			$candidates[] = $dated[0];
		}
		foreach ( $candidates as $old ) {
			if ( ! is_readable( $old ) || is_link( $old ) ) {
				continue;
			}
			$old_size = (int) filesize( $old );
			// Same file as before the rotation: its head matches and it is at least as long as we read.
			// Without a head hash (tiny files) we cannot prove it, so nothing is re-read (no double counting).
			if ( ! empty( $state['head'] ) && $old_size >= (int) $state['offset'] && self::head_hash( $old ) === $state['head'] ) {
				$len  = min( $old_size - (int) $state['offset'], (int) ( $max_bytes / 2 ) );
				$text = self::read_range( $old, (int) $state['offset'], $len );
				$cut  = strrpos( $text, "\n" );
				return false === $cut ? '' : substr( $text, 0, $cut + 1 );
			}
		}
		return '';
	}

	/**
	 * Hash of the first bytes.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function head_hash( $path ) {
		$head = self::read_range( $path, 0, self::HEAD_BYTES );
		return strlen( $head ) >= self::HEAD_BYTES ? md5( $head ) : '';
	}

	/**
	 * Reads a byte range.
	 *
	 * @param string $path   Path.
	 * @param int    $offset Offset.
	 * @param int    $length Length.
	 * @return string
	 */
	private static function read_range( $path, $offset, $length ) {
		if ( $length <= 0 ) {
			return '';
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming a byte range of a log file; WP_Filesystem has no ranged read.
		$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $fh ) {
			return '';
		}
		fseek( $fh, max( 0, (int) $offset ) );
		$data = (string) fread( $fh, (int) $length );
		fclose( $fh );
		// phpcs:enable
		return $data;
	}
}
