<?php
/**
 * WP-CLI: wp dev3sh scan | issues | integrity | report.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Cli;

use Dev3\AiSiteHealth\Collect\Collector;
use Dev3\AiSiteHealth\Collect\Integrity;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Report\Weekly_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Site health and security log tools.
 */
class Command {

	/**
	 * Runs the collectors now.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<source>]
	 * : Comma-separated sources (php, site_health, updates, cron, query, changes, integrity, security).
	 *
	 * ## EXAMPLES
	 *
	 *     wp dev3sh scan
	 *     wp dev3sh scan --source=php,updates
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function scan( $args, $assoc_args ) {
		unset( $args );
		$sources = isset( $assoc_args['source'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['source'] ) ) ) : array();
		$sources = array_diff( $sources ? $sources : Collector::SOURCES, array( 'integrity' ) );
		$res     = Collector::run( array_values( $sources ), true );
		foreach ( $res['results'] as $source => $r ) {
			\WP_CLI::log( sprintf( '%-12s %-8s %s', $source, $r['status'] ?? 'ok', $r['message'] ?? '' ) );
		}
		\WP_CLI::success( sprintf( 'Score %d, %d new issues.', $res['score'], $res['created'] ) );
	}

	/**
	 * Runs the file integrity check inline.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function integrity( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$res = Integrity::run_now();
		\WP_CLI::success( wp_json_encode( $res['summary'] ) );
	}

	/**
	 * Lists issues.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : open, resolved or ignored.
	 * ---
	 * default: open
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function issues( $args, $assoc_args ) {
		unset( $args );
		$res = Issues::query(
			array(
				'status'   => $assoc_args['status'] ?? 'open',
				'per_page' => 200,
			)
		);
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $res['items'], array( 'id', 'severity', 'source', 'title', 'count', 'last_seen' ) );
	}

	/**
	 * Sends the weekly report now.
	 *
	 * @param array $args       Args.
	 * @param array $assoc_args Options.
	 */
	public function report( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$res = Weekly_Report::send();
		if ( $res['sent'] ) {
			\WP_CLI::success( 'Sent to ' . implode( ', ', $res['to'] ) );
		} else {
			\WP_CLI::warning( 'The email could not be sent.' );
		}
	}
}
