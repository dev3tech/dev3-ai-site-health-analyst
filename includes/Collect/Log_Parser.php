<?php
/**
 * Parses PHP / WordPress error log text into entries (multi-line stack traces folded in).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Collect;

defined( 'ABSPATH' ) || exit;

/**
 * Pure parser.
 */
class Log_Parser {

	/**
	 * Level => severity.
	 */
	const SEVERITY = array(
		'fatal'      => 'critical',
		'parse'      => 'critical',
		'database'   => 'high',
		'warning'    => 'medium',
		'notice'     => 'low',
		'deprecated' => 'low',
		'other'      => 'low',
	);

	/**
	 * Parses a chunk of log text.
	 *
	 * @param string $text Text (complete lines).
	 * @return array[] Entries: { level, message, file, line, time (unix|0), raw, trace[] }.
	 */
	public static function parse( $text ) {
		$entries = array();
		$current = null;
		foreach ( preg_split( '/\r\n|\n|\r/', (string) $text ) as $raw ) {
			if ( '' === trim( $raw ) ) {
				continue;
			}
			$head = self::head( $raw );
			if ( $head ) {
				if ( $current ) {
					$entries[] = self::finish( $current );
				}
				$current = $head;
				continue;
			}
			if ( null === $current ) {
				// Continuation without a header (log was cut mid-entry): start an "other" entry.
				$current = array(
					'time'    => 0,
					'body'    => trim( $raw ),
					'raw'     => $raw,
					'trace'   => array(),
					'details' => array(),
				);
				continue;
			}
			$line = trim( $raw );
			if ( preg_match( '/^#\d+\s/', $line ) || 'Stack trace:' === $line || 0 === strpos( $line, 'thrown in ' ) ) {
				if ( count( $current['trace'] ) < 30 ) {
					$current['trace'][] = $line;
				}
			} elseif ( count( $current['details'] ) < 20 ) {
				$current['details'][] = $line; // Extra lines (e.g. the query of a database error).
			}
			$current['raw'] .= "\n" . $raw;
		}
		if ( $current ) {
			$entries[] = self::finish( $current );
		}
		return $entries;
	}

	/**
	 * Detects an entry header line.
	 *
	 * @param string $raw Line.
	 * @return array|null
	 */
	private static function head( $raw ) {
		// [25-Oct-2026 10:11:12 UTC] message  |  [2026-10-25 10:11:12] message  |  [Sat Oct 25 10:11:12.123 2026] [php:error] message.
		if ( preg_match( '/^\[([^\]]{8,40})\]\s*(.*)$/', $raw, $m ) ) {
			$time = strtotime( preg_replace( '/\.\d+/', '', $m[1] ) );
			if ( false !== $time ) {
				$body = preg_replace( '/^(?:\[[a-z_]+:[a-z]+\]\s*|\[pid \d+(?::tid \d+)?\]\s*|\[client [^\]]+\]\s*)+/i', '', $m[2] );
				return array(
					'time'    => (int) $time,
					'body'    => trim( (string) $body ),
					'raw'     => $raw,
					'trace'   => array(),
					'details' => array(),
				);
			}
		}
		// 2026/10/25 10:11:12 [error] 123#0: *45 FastCGI sent in stderr: "PHP message: ...".
		if ( preg_match( '#^(\d{4}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}) \[(?:error|warn|crit|alert|emerg)\][^:]*:\s*(?:\*\d+\s*)?(.*)$#', $raw, $m ) ) {
			$body = $m[2];
			if ( preg_match( '/PHP message:\s*(.*?)(?:"|$)/', $body, $pm ) ) {
				$body = $pm[1];
			}
			return array(
				'time'    => (int) strtotime( str_replace( '/', '-', $m[1] ) ),
				'body'    => trim( (string) $body ),
				'raw'     => $raw,
				'trace'   => array(),
				'details' => array(),
			);
		}
		// PHP CLI style without a timestamp.
		if ( preg_match( '/^(?:PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards|Catchable fatal error):/i', $raw ) ) {
			return array(
				'time'    => 0,
				'body'    => trim( $raw ),
				'raw'     => $raw,
				'trace'   => array(),
				'details' => array(),
			);
		}
		return null;
	}

	/**
	 * Classifies + extracts file/line.
	 *
	 * @param array $entry Entry under construction.
	 * @return array
	 */
	private static function finish( array $entry ) {
		$body  = (string) $entry['body'];
		$level = 'other';
		if ( preg_match( '/^(?:PHP\s+)?(Fatal error|Parse error|Catchable fatal error|Recoverable fatal error|Warning|Notice|Deprecated|Strict Standards|User (?:Error|Warning|Notice|Deprecated))\s*:\s*/i', $body, $m ) ) {
			$label = strtolower( $m[1] );
			if ( false !== strpos( $label, 'parse' ) ) {
				$level = 'parse';
			} elseif ( false !== strpos( $label, 'fatal' ) || 'user error' === $label ) {
				$level = 'fatal';
			} elseif ( false !== strpos( $label, 'warning' ) ) {
				$level = 'warning';
			} elseif ( false !== strpos( $label, 'deprecated' ) || false !== strpos( $label, 'strict' ) ) {
				$level = 'deprecated';
			} else {
				$level = 'notice';
			}
			$body = substr( $body, strlen( $m[0] ) );
		} elseif ( preg_match( '/^WordPress database error\s*/i', $body, $m ) ) {
			$level = 'database';
		} elseif ( preg_match( '/^PHP\s+/', $body ) ) {
			$body = (string) preg_replace( '/^PHP\s+/', '', $body );
		}
		if ( 'other' === $level && preg_match( '/Uncaught (?:Error|Exception|TypeError|ArgumentCountError|ValueError|DivisionByZeroError)/', $body ) ) {
			$level = 'fatal';
		}

		$file = '';
		$line = 0;
		if ( preg_match( '/ in (\S+?\.(?:php|inc)):(\d+)(?:\s|$)/', $body, $m ) || preg_match( '/ in (\S+?\.(?:php|inc)) on line (\d+)/', $body, $m ) ) {
			$file = $m[1];
			$line = (int) $m[2];
		} elseif ( $entry['trace'] && preg_match( '/thrown in (\S+) on line (\d+)/', implode( "\n", $entry['trace'] ), $m ) ) {
			$file = $m[1];
			$line = (int) $m[2];
		} elseif ( $entry['trace'] && preg_match( '/^#\d+ (\S+?\.php)\((\d+)\)/', $entry['trace'][0] ?? '', $m ) ) {
			$file = $m[1];
			$line = (int) $m[2];
		}

		// Message without "in file on line" and the stack trace preamble.
		$message = preg_replace( '/\s+in \S+?\.(?:php|inc)(?::\d+| on line \d+)\b.*$/s', '', $body );
		$message = preg_replace( '/\s*Stack trace:.*$/s', '', (string) $message );
		if ( 'database' === $level ) {
			if ( $entry['details'] ) {
				$message = trim( $message . ' ' . $entry['details'][0] );
			}
			$message = (string) preg_replace( '/^WordPress database error\s*/i', '', (string) $message );
			if ( preg_match( '/^(.*?) made by (.*)$/s', $message, $m ) ) {
				$message          = $m[1];
				$entry['trace'][] = 'made by ' . mb_substr( $m[2], 0, 600 );
			}
			// Literals in the query vary per request: collapse them so repeats group.
			if ( preg_match( '/^(.*?) for query (.*)$/s', $message, $m ) ) {
				$message = $m[1] . ' for query ' . Slow_Queries::normalize( $m[2] );
			}
		}
		return array(
			'level'   => $level,
			'message' => mb_substr( trim( (string) $message ), 0, 1000 ),
			'file'    => $file,
			'line'    => $line,
			'time'    => (int) $entry['time'],
			'raw'     => mb_substr( (string) $entry['raw'], 0, 4000 ),
			'trace'   => $entry['trace'],
		);
	}

	/**
	 * Short title for an entry.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public static function title( array $entry ) {
		$labels = array(
			'fatal'      => __( 'Fatal error', 'dev3-ai-site-health-analyst' ),
			'parse'      => __( 'Parse error', 'dev3-ai-site-health-analyst' ),
			'database'   => __( 'Database error', 'dev3-ai-site-health-analyst' ),
			'warning'    => __( 'PHP warning', 'dev3-ai-site-health-analyst' ),
			'notice'     => __( 'PHP notice', 'dev3-ai-site-health-analyst' ),
			'deprecated' => __( 'Deprecated', 'dev3-ai-site-health-analyst' ),
			'other'      => __( 'Log entry', 'dev3-ai-site-health-analyst' ),
		);
		$msg    = (string) preg_replace( '/^(?:PHP\s+)?Uncaught\s+/', 'Uncaught ', (string) $entry['message'] );
		$msg    = (string) preg_replace( '/^WordPress database error\s*/i', '', $msg );
		return mb_substr( $labels[ $entry['level'] ] . ': ' . $msg, 0, 180 );
	}
}
