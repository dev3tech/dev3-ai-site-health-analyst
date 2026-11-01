<?php
/**
 * Admin REST routes under dev3sh/v1 (all require manage_options + a strict nonce).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Rest;

use Dev3\AiSiteHealth\Ai\Explainer;
use Dev3\AiSiteHealth\Analyze\Score;
use Dev3\AiSiteHealth\Collect\Collector;
use Dev3\AiSiteHealth\Collect\Cron_Health;
use Dev3\AiSiteHealth\Collect\Integrity;
use Dev3\AiSiteHealth\Collect\Php_Log;
use Dev3\AiSiteHealth\Collect\Security_Logger;
use Dev3\AiSiteHealth\Collect\Site_Health;
use Dev3\AiSiteHealth\Collect\Slow_Queries;
use Dev3\AiSiteHealth\Collect\Updates;
use Dev3\AiSiteHealth\Core3\Api\Ai;
use Dev3\AiSiteHealth\Core3\Api\Api_Error;
use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Core3\Rest\Base_Controller;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Fix\Firewall;
use Dev3\AiSiteHealth\Fix\Fixes;
use Dev3\AiSiteHealth\Report\Alerts;
use Dev3\AiSiteHealth\Report\Weekly_Report;

defined( 'ABSPATH' ) || exit;

/**
 * Routes.
 */
class Admin_Controller extends Base_Controller {

	/**
	 * Registers routes.
	 */
	public function register_routes() {
		$id     = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
		$routes = array(
			'/overview'                   => array( 'GET', 'overview' ),
			'/scan'                       => array( 'POST', 'scan' ),
			'/issues'                     => array( 'GET', 'issues' ),
			'/issues/bulk'                => array( 'POST', 'issues_bulk' ),
			'/issues/explain-job'         => array( 'POST', 'explain_job' ),
			'/issues/(?P<id>\d+)'         => array( 'GET', 'issue', $id ),
			'/issues/(?P<id>\d+)/status'  => array( 'POST', 'issue_status', $id ),
			'/issues/(?P<id>\d+)/payload' => array( 'GET', 'issue_payload', $id ),
			'/issues/(?P<id>\d+)/explain' => array( 'POST', 'issue_explain', $id ),
			'/issues/(?P<id>\d+)/ask'     => array( 'POST', 'issue_ask', $id ),
			'/events'                     => array( 'GET', 'events' ),
			'/security'                   => array( 'GET', 'security' ),
			'/security/unblock'           => array( 'POST', 'unblock' ),
			'/log'                        => array( 'GET', 'log' ),
			'/integrity'                  => array( 'GET', 'integrity' ),
			'/integrity/run'              => array( 'POST', 'integrity_run' ),
			'/system'                     => array( 'GET', 'system' ),
			'/report/preview'             => array( 'GET', 'report_preview' ),
			'/report/send'                => array( 'POST', 'report_send' ),
			'/fixes'                      => array( 'GET', 'fixes' ),
			'/fixes/preview'              => array( 'POST', 'fix_preview' ),
			'/fixes/apply'                => array( 'POST', 'fix_apply' ),
			'/fixes/(?P<id>\d+)/undo'     => array( 'POST', 'fix_undo', $id ),
		);
		foreach ( $routes as $path => $r ) {
			register_rest_route(
				self::NS,
				$path,
				array(
					'methods'             => $r[0],
					'callback'            => array( $this, $r[1] ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => isset( $r[2] ) ? $r[2] : array(),
				)
			);
		}
	}

	/**
	 * Source labels.
	 *
	 * @return array
	 */
	public static function source_labels() {
		return array(
			'php'         => __( 'PHP error log', 'dev3-ai-site-health-analyst' ),
			'site_health' => __( 'Site Health', 'dev3-ai-site-health-analyst' ),
			'security'    => __( 'Security log', 'dev3-ai-site-health-analyst' ),
			'changes'     => __( 'Plugin & theme changes', 'dev3-ai-site-health-analyst' ),
			'change'      => __( 'Changes', 'dev3-ai-site-health-analyst' ),
			'updates'     => __( 'Updates', 'dev3-ai-site-health-analyst' ),
			'cron'        => __( 'Cron health', 'dev3-ai-site-health-analyst' ),
			'query'       => __( 'Slow queries', 'dev3-ai-site-health-analyst' ),
			'integrity'   => __( 'File integrity', 'dev3-ai-site-health-analyst' ),
		);
	}

	/**
	 * GET overview.
	 *
	 * @return \WP_REST_Response
	 */
	public function overview() {
		$score   = Score::compute();
		$history = array();
		foreach ( Score::history() as $date => $value ) {
			$history[] = array(
				'date'  => $date,
				'score' => (int) $value,
			);
		}
		$status     = Collector::status();
		$collectors = array();
		$labels     = self::source_labels();
		foreach ( Collector::SOURCES as $source ) {
			$s            = isset( $status[ $source ] ) ? $status[ $source ] : array();
			$collectors[] = array(
				'id'      => $source,
				'label'   => $labels[ $source ],
				'status'  => isset( $s['status'] ) ? $s['status'] : 'pending',
				'message' => isset( $s['message'] ) ? (string) $s['message'] : '',
				'at'      => ! empty( $s['at'] ) ? gmdate( 'c', (int) $s['at'] ) : '',
			);
		}
		$sec   = Security_Logger::summary();
		$top   = Issues::query(
			array(
				'status'   => 'open',
				'per_page' => 6,
			)
		);
		$integ = Integrity::last();
		return $this->respond(
			array(
				'score'         => $score,
				'grade'         => Score::grade( $score ),
				'previous'      => Score::week_ago(),
				'history'       => $history,
				'counts'        => Issues::counts(),
				'collectors'    => $collectors,
				'top'           => $top['items'],
				'security'      => array(
					'failed_24h'     => $sec['failed_24h'],
					'suspicious_24h' => $sec['suspicious_24h'],
					'daily_failed'   => $sec['daily_failed'],
				),
				'updates'       => Issues::counts()['source']['updates'],
				'integrity'     => $integ ? $integ['summary'] + array( 'at' => gmdate( 'c', (int) $integ['at'] ) ) : null,
				'integrity_job' => Jobs::active( Integrity::JOB ),
				'log'           => Php_Log::public_info( Php_Log::locate() ),
				'last_scan'     => get_option( 'dev3sh_last_scan' ) ? gmdate( 'c', (int) get_option( 'dev3sh_last_scan' ) ) : '',
				'ai'            => Ai::status(),
				'fixes_enabled' => Fixes::enabled(),
				'savequeries'   => Slow_Queries::available(),
				'sources'       => $labels,
			)
		);
	}

	/**
	 * POST scan.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function scan( \WP_REST_Request $request ) {
		$sources = array_map( 'sanitize_key', (array) $request->get_param( 'sources' ) );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $this->respond( Collector::run( array_filter( $sources ), (bool) $request->get_param( 'force' ) ) );
	}

	/**
	 * GET issues.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issues( \WP_REST_Request $request ) {
		$res           = Issues::query(
			array(
				'status'    => sanitize_key( (string) ( $request['status'] ?? 'open' ) ),
				'source'    => sanitize_key( (string) ( $request['source'] ?? '' ) ),
				'severity'  => sanitize_key( (string) ( $request['severity'] ?? '' ) ),
				'component' => sanitize_text_field( (string) ( $request['component'] ?? '' ) ),
				'search'    => sanitize_text_field( (string) ( $request['search'] ?? '' ) ),
				'orderby'   => sanitize_key( (string) ( $request['orderby'] ?? 'severity' ) ),
				'order'     => sanitize_key( (string) ( $request['order'] ?? 'desc' ) ),
				'page'      => (int) ( $request['page'] ?? 1 ),
				'per_page'  => (int) ( $request['per_page'] ?? 25 ),
			)
		);
		$res['items']  = array_map( array( $this, 'brief' ), $res['items'] );
		$res['counts'] = Issues::counts();
		$all           = Issues::query(
			array(
				'status'   => '',
				'per_page' => 1,
			)
		);
		$res['all']    = $all['total'];
		return $this->respond( $res );
	}

	/**
	 * Smaller issue shape for lists.
	 *
	 * @param array $i Issue.
	 * @return array
	 */
	private function brief( array $i ) {
		unset( $i['context'] );
		$i['has_ai'] = ! empty( $i['ai'] );
		unset( $i['ai'] );
		return $i;
	}

	/**
	 * GET issues/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issue( \WP_REST_Request $request ) {
		$issue = Issues::get( (int) $request['id'] );
		if ( ! $issue ) {
			return $this->respond( Api_Error::make( 'dev3sh_issue_missing', __( 'Issue not found.', 'dev3-ai-site-health-analyst' ), '', 404 ) );
		}
		$x = Explainer::for_issue( $issue );
		return $this->respond(
			$issue + array(
				'builtin'       => $x['builtin'],
				'fixes'         => Fixes::applicable( $issue ),
				'fixes_enabled' => Fixes::enabled(),
			)
		);
	}

	/**
	 * POST issues/<id>/status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issue_status( \WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request['status'] );
		if ( ! Issues::set_status( (int) $request['id'], $status ) ) {
			return $this->respond( Api_Error::make( 'dev3sh_bad_status', __( 'Unknown status.', 'dev3-ai-site-health-analyst' ), '', 400 ) );
		}
		return $this->respond( Issues::get( (int) $request['id'] ) );
	}

	/**
	 * POST issues/bulk.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issues_bulk( \WP_REST_Request $request ) {
		$status = sanitize_key( (string) $request['status'] );
		$ids    = array_slice( array_filter( array_map( 'absint', (array) $request['ids'] ) ), 0, 500 );
		if ( ! in_array( $status, Issues::STATUSES, true ) || ! $ids ) {
			return $this->respond( Api_Error::make( 'dev3sh_bad_bulk', __( 'Pick issues and a status.', 'dev3-ai-site-health-analyst' ), '', 400 ) );
		}
		foreach ( $ids as $id ) {
			Issues::set_status( $id, $status );
		}
		return $this->respond( array( 'updated' => count( $ids ) ) );
	}

	/**
	 * GET issues/<id>/payload: what DEV3 AI would receive.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issue_payload( \WP_REST_Request $request ) {
		$issue = Issues::get( (int) $request['id'] );
		if ( ! $issue ) {
			return $this->respond( Api_Error::make( 'dev3sh_issue_missing', __( 'Issue not found.', 'dev3-ai-site-health-analyst' ), '', 404 ) );
		}
		return $this->respond( Explainer::payload( $issue ) );
	}

	/**
	 * POST issues/<id>/explain.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issue_explain( \WP_REST_Request $request ) {
		return $this->respond( Explainer::explain( (int) $request['id'] ) );
	}

	/**
	 * POST issues/<id>/ask.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function issue_ask( \WP_REST_Request $request ) {
		return $this->respond( Explainer::ask( (int) $request['id'], (string) $request['question'] ) );
	}

	/**
	 * POST issues/explain-job.
	 *
	 * @return \WP_REST_Response
	 */
	public function explain_job() {
		if ( ! Settings_Store::api_key() ) {
			return $this->respond( Api_Error::missing_key() );
		}
		$job = Explainer::start_bulk();
		return $this->respond( $job, is_wp_error( $job ) ? 200 : 201 );
	}

	/**
	 * GET events.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function events( \WP_REST_Request $request ) {
		$type = sanitize_text_field( (string) ( $request['type'] ?? '' ) );
		return $this->respond(
			Events::query(
				array(
					'type'     => '' !== $type ? $type : array( 'login_failed', 'login_ok', 'suspicious', 'not_found_flood', 'change', 'fix', 'alert', 'report' ),
					'rule'     => sanitize_key( (string) ( $request['rule'] ?? '' ) ),
					'ip'       => sanitize_text_field( (string) ( $request['ip'] ?? '' ) ),
					'search'   => sanitize_text_field( (string) ( $request['search'] ?? '' ) ),
					'page'     => (int) ( $request['page'] ?? 1 ),
					'per_page' => (int) ( $request['per_page'] ?? 25 ),
				)
			)
		);
	}

	/**
	 * GET security.
	 *
	 * @return \WP_REST_Response
	 */
	public function security() {
		return $this->respond(
			Security_Logger::summary() + array(
				'blocklist'       => Firewall::rows(),
				'xmlrpc_disabled' => (bool) Settings_Store::get( 'xmlrpc_disabled', false ),
				'logger'          => (bool) Settings_Store::get( 'security_logger', true ),
				'fixes_enabled'   => Fixes::enabled(),
				'your_ip'         => \Dev3\AiSiteHealth\Core3\Core\Rate_Limiter::client_ip(),
			)
		);
	}

	/**
	 * POST security/unblock (relaxing a block is not a risky change, but it is logged).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function unblock( \WP_REST_Request $request ) {
		$ip = sanitize_text_field( (string) $request['ip'] );
		if ( ! Firewall::unblock( $ip ) ) {
			return $this->respond( Api_Error::make( 'dev3sh_not_blocked', __( 'That address is not blocked.', 'dev3-ai-site-health-analyst' ), '', 404 ) );
		}
		Events::add(
			'fix',
			array(
				'rule'       => 'unblock_ip',
				'user_id'    => get_current_user_id(),
				'user_login' => wp_get_current_user()->user_login,
				'path'       => $ip,
				/* translators: %s: IP. */
				'message'    => sprintf( __( 'Unblocked %s', 'dev3-ai-site-health-analyst' ), $ip ),
				'meta'       => array(
					'type'   => 'unblock_ip',
					'target' => $ip,
					'undone' => true,
				),
			)
		);
		return $this->respond( array( 'blocklist' => Firewall::rows() ) );
	}

	/**
	 * GET log.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function log( \WP_REST_Request $request ) {
		$lines = min( 500, max( 10, (int) ( $request['lines'] ? $request['lines'] : 150 ) ) );
		$level = sanitize_key( (string) ( $request['level'] ?? '' ) );
		return $this->respond( Php_Log::tail( $lines, $level ) );
	}

	/**
	 * GET integrity.
	 *
	 * @return \WP_REST_Response
	 */
	public function integrity() {
		return $this->respond(
			array(
				'result'   => Integrity::last(),
				'job'      => Jobs::active( Integrity::JOB ),
				'schedule' => Settings_Store::get( 'integrity_schedule', 'weekly' ),
				'version'  => get_bloginfo( 'version' ),
			)
		);
	}

	/**
	 * POST integrity/run.
	 *
	 * @return \WP_REST_Response
	 */
	public function integrity_run() {
		$job = Integrity::start();
		return $this->respond( $job, 201 );
	}

	/**
	 * GET system.
	 *
	 * @return \WP_REST_Response
	 */
	public function system() {
		$slow = Issues::query(
			array(
				'status'   => 'open',
				'source'   => 'query',
				'orderby'  => 'count',
				'per_page' => 20,
			)
		);
		$sh   = Site_Health::last();
		return $this->respond(
			array(
				'updates'     => Updates::status( false ),
				'cron'        => Cron_Health::status(),
				'slow'        => array(
					'available' => Slow_Queries::available(),
					'threshold' => (int) Settings_Store::get( 'slow_query_ms', 250 ),
					'items'     => array_map( array( $this, 'brief' ), $slow['items'] ),
				),
				'site_health' => array(
					'at'      => $sh['at'] ? gmdate( 'c', (int) $sh['at'] ) : '',
					'results' => $sh['results'],
				),
				'changes'     => Events::query(
					array(
						'type'     => 'change',
						'per_page' => 15,
					)
				)['items'],
				'environment' => Explainer::environment(),
			)
		);
	}

	/**
	 * GET report/preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function report_preview( \WP_REST_Request $request ) {
		$data = Weekly_Report::data( (bool) $request['ai'] );
		$last = get_option( Weekly_Report::LAST, array() );
		$next = wp_next_scheduled( Weekly_Report::CRON );
		return $this->respond(
			array(
				'html'       => Weekly_Report::html( $data ),
				'data'       => $data,
				'recipients' => Weekly_Report::recipients(),
				'alerts_to'  => Alerts::recipients(),
				'last'       => is_array( $last ) && ! empty( $last['at'] ) ? $last + array( 'at_iso' => gmdate( 'c', (int) $last['at'] ) ) : null,
				'next'       => $next ? gmdate( 'c', (int) $next ) : '',
			)
		);
	}

	/**
	 * POST report/send.
	 *
	 * @return \WP_REST_Response
	 */
	public function report_send() {
		$res = Weekly_Report::send();
		if ( ! $res['sent'] ) {
			return $this->respond( Api_Error::make( 'dev3sh_mail_failed', __( 'The email could not be sent. Check the recipients and your site\'s mail setup (an SMTP plugin helps).', 'dev3-ai-site-health-analyst' ), '', 500 ) );
		}
		return $this->respond( $res );
	}

	/**
	 * GET fixes.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fixes( \WP_REST_Request $request ) {
		return $this->respond( Fixes::history( max( 1, (int) $request['page'] ) ) );
	}

	/**
	 * POST fixes/preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fix_preview( \WP_REST_Request $request ) {
		return $this->respond( Fixes::preview( sanitize_key( (string) $request['type'] ), sanitize_text_field( (string) $request['target'] ), (int) $request['issue_id'] ) );
	}

	/**
	 * POST fixes/apply.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fix_apply( \WP_REST_Request $request ) {
		return $this->respond( Fixes::apply( (string) $request['token'], true === $request['confirm'] ) );
	}

	/**
	 * POST fixes/<id>/undo.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function fix_undo( \WP_REST_Request $request ) {
		return $this->respond( Fixes::undo( (int) $request['id'] ) );
	}
}
