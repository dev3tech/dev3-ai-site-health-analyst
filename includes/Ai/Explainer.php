<?php
/**
 * DEV3 AI explanations: builds the masked payload, calls the prompt, validates the answer.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Ai;

use Dev3\AiSiteHealth\Analyze\Knowledge_Base;
use Dev3\AiSiteHealth\Analyze\Redactor;
use Dev3\AiSiteHealth\Core3\Api\Api_Error;
use Dev3\AiSiteHealth\Core3\Api\Prompts;
use Dev3\AiSiteHealth\Core3\Core\Jobs;
use Dev3\AiSiteHealth\Data\Issues;
use Dev3\AiSiteHealth\Fix\Fixes;

defined( 'ABSPATH' ) || exit;

/**
 * Explainer.
 */
class Explainer {

	const JOB = 'dev3sh_explain';

	/**
	 * Registers the bulk job.
	 */
	public static function register() {
		Jobs::register( self::JOB, array( __CLASS__, 'run_chunk' ) );
	}

	/**
	 * Masked payload for an issue (exactly what is sent).
	 *
	 * @param array $issue Issue.
	 * @return array { issue, environment, actions }
	 */
	public static function payload( array $issue ) {
		self::prepare_literals();
		$details = isset( $issue['context']['details'] ) ? $issue['context']['details'] : null;
		if ( is_array( $details ) ) {
			unset( $details['ip'] ); // Never send IPs, even masked placeholders add nothing.
			foreach ( array( 'modified', 'missing', 'added', 'unknown', 'hooks' ) as $list ) {
				if ( isset( $details[ $list ] ) && is_array( $details[ $list ] ) ) {
					$details[ $list ] = array_slice( $details[ $list ], 0, 15 );
				}
			}
		}
		$samples = isset( $issue['context']['samples'] ) ? array_slice( (array) $issue['context']['samples'], 0, 3 ) : array();
		$samples = array_map(
			static function ( $s ) {
				return mb_substr( (string) $s, 0, 900 );
			},
			$samples
		);
		$data    = array(
			'source'      => $issue['source'],
			'kind'        => $issue['kind'],
			'severity'    => $issue['severity'],
			'title'       => $issue['title'],
			'message'     => mb_substr( (string) $issue['message'], 0, 1500 ),
			'file'        => $issue['file'],
			'line'        => $issue['line'],
			'component'   => $issue['component'],
			'occurrences' => self::bucket( (int) $issue['count'] ),
			'first_seen'  => substr( (string) $issue['first_seen'], 0, 10 ),
			'last_seen'   => substr( (string) $issue['last_seen'], 0, 10 ),
			'status'      => $issue['regressed'] ? 'came back after being resolved' : $issue['status'],
			'samples'     => $samples,
			'details'     => $details,
		);
		$actions = array_values( array_unique( array_merge( array( 'none' ), wp_list_pluck( Fixes::applicable( $issue ), 'type' ) ) ) );
		return array(
			'issue'       => Redactor::mask_deep( $data ),
			'environment' => Redactor::mask_deep( self::environment() ),
			'actions'     => $actions,
		);
	}

	/**
	 * Explains an issue with DEV3 AI and stores it (analysis only).
	 *
	 * @param int $id Issue id.
	 * @return array|\WP_Error
	 */
	public static function explain( $id ) {
		$issue = Issues::get( $id );
		if ( ! $issue ) {
			return Api_Error::make( 'dev3sh_issue_missing', __( 'Issue not found.', 'dev3-ai-site-health-analyst' ), '', 404 );
		}
		$payload = self::payload( $issue );
		$result  = Prompts::run(
			Prompt_Library::EXPLAIN,
			array(
				'issue'       => self::json( $payload['issue'] ),
				'environment' => self::json( $payload['environment'] ),
				'actions'     => implode( ', ', $payload['actions'] ),
				'language'    => self::language(),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$d       = is_array( $result['data'] ?? null ) ? $result['data'] : array();
		$steps   = array_slice( array_values( array_filter( array_map( array( __CLASS__, 'clean' ), (array) ( $d['steps'] ?? array() ) ) ) ), 0, 8 );
		$summary = self::clean( $d['summary'] ?? '' );
		if ( '' === $summary || ! $steps ) {
			return Api_Error::make( 'dev3sh_ai_empty', __( 'DEV3 AI returned an incomplete answer. Try again.', 'dev3-ai-site-health-analyst' ), '', 502, true );
		}
		// The AI may only propose a fix that the server considers applicable; the target comes from the server.
		$action = in_array( $d['fix_action'] ?? 'none', $payload['actions'], true ) ? (string) $d['fix_action'] : 'none';
		$target = '';
		if ( 'none' !== $action ) {
			foreach ( Fixes::applicable( $issue ) as $fix ) {
				if ( $fix['type'] === $action ) {
					$target = $fix['target'];
					break;
				}
			}
		}
		$explanation = array(
			'summary'        => mb_substr( $summary, 0, 500 ),
			'cause'          => mb_substr( self::clean( $d['cause'] ?? '' ), 0, 400 ),
			'impact'         => mb_substr( self::clean( $d['impact'] ?? '' ), 0, 300 ),
			'steps'          => $steps,
			'who'            => in_array( $d['who'] ?? '', array( 'owner', 'developer', 'host' ), true ) ? $d['who'] : 'owner',
			'urgency'        => in_array( $d['urgency'] ?? '', array( 'now', 'soon', 'normal', 'low' ), true ) ? $d['urgency'] : 'normal',
			'confidence'     => in_array( $d['confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $d['confidence'] : 'medium',
			'fix_action'     => '' !== $target ? $action : 'none',
			'fix_target'     => $target,
			'source'         => 'ai',
			'model'          => (string) $result['model'],
			'tokens'         => (int) ( $result['usage']['total'] ?? 0 ),
			'cached'         => ! empty( $result['cached'] ),
			'prompt_version' => (string) ( $result['prompt_version'] ?? '' ),
			'at'             => gmdate( 'c' ),
		);
		Issues::set_ai( $id, $explanation );
		return $explanation;
	}

	/**
	 * Follow-up question (not stored).
	 *
	 * @param int    $id       Issue id.
	 * @param string $question Question.
	 * @return array|\WP_Error
	 */
	public static function ask( $id, $question ) {
		$issue = Issues::get( $id );
		if ( ! $issue ) {
			return Api_Error::make( 'dev3sh_issue_missing', __( 'Issue not found.', 'dev3-ai-site-health-analyst' ), '', 404 );
		}
		$question = trim( mb_substr( sanitize_textarea_field( (string) $question ), 0, 500 ) );
		if ( '' === $question ) {
			return Api_Error::make( 'dev3sh_empty_question', __( 'Type a question first.', 'dev3-ai-site-health-analyst' ), '', 400 );
		}
		$payload = self::payload( $issue );
		$result  = Prompts::run(
			Prompt_Library::ASK,
			array(
				'issue'       => self::json( $payload['issue'] ),
				'environment' => self::json( $payload['environment'] ),
				'question'    => Redactor::mask( $question ),
				'language'    => self::language(),
			),
			'admin'
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$answer = self::clean( $result['data']['answer'] ?? '' );
		if ( '' === $answer ) {
			return Api_Error::make( 'dev3sh_ai_empty', __( 'DEV3 AI returned an empty answer. Try again.', 'dev3-ai-site-health-analyst' ), '', 502, true );
		}
		return array(
			'answer' => mb_substr( $answer, 0, 1500 ),
			'model'  => (string) $result['model'],
			'tokens' => (int) ( $result['usage']['total'] ?? 0 ),
			'cached' => ! empty( $result['cached'] ),
		);
	}

	/**
	 * Explanation to show: AI when present, else built-in.
	 *
	 * @param array $issue Issue.
	 * @return array { builtin, ai }
	 */
	public static function for_issue( array $issue ) {
		return array(
			'builtin' => Knowledge_Base::explain( $issue ),
			'ai'      => $issue['ai'],
		);
	}

	/**
	 * Starts a bulk job for open issues without an AI explanation.
	 *
	 * @param string[] $severities Severities.
	 * @return array|\WP_Error
	 */
	public static function start_bulk( array $severities = array( 'critical', 'high' ) ) {
		$active = Jobs::active( self::JOB );
		if ( $active ) {
			return $active;
		}
		$ids = array();
		foreach ( $severities as $sev ) {
			$res = Issues::query(
				array(
					'status'   => 'open',
					'severity' => $sev,
					'per_page' => 100,
				)
			);
			foreach ( $res['items'] as $i ) {
				if ( empty( $i['ai'] ) ) {
					$ids[] = $i['id'];
				}
			}
		}
		if ( ! $ids ) {
			return Api_Error::make( 'dev3sh_nothing_to_explain', __( 'Every open critical and high issue already has a DEV3 AI explanation.', 'dev3-ai-site-health-analyst' ), '', 400 );
		}
		$id = Jobs::create( self::JOB, array_slice( $ids, 0, 100 ), array(), 2 );
		return is_wp_error( $id ) ? $id : Jobs::get( $id );
	}

	/**
	 * Job handler.
	 *
	 * @param array $items Ids.
	 * @param array $job   Job.
	 * @return array
	 */
	public static function run_chunk( array $items, array $job ) {
		unset( $job );
		$out = array();
		foreach ( $items as $id ) {
			$r          = self::explain( (int) $id );
			$out[ $id ] = is_wp_error( $r ) ? $r : true;
		}
		return $out;
	}

	/**
	 * Environment summary (versions + active plugin slugs).
	 *
	 * @return array
	 */
	public static function environment() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = dirname( $file ) . ' ' . $data['Version'];
			}
		}
		$theme = wp_get_theme();
		return array(
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'multisite'      => is_multisite(),
			'theme'          => $theme->get_stylesheet() . ' ' . $theme->get( 'Version' ),
			'block_theme'    => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'active_plugins' => array_slice( $active, 0, 40 ),
			'object_cache'   => wp_using_ext_object_cache(),
			'debug'          => defined( 'WP_DEBUG' ) && WP_DEBUG,
		);
	}

	/**
	 * Literals masked in every payload: user logins, display names, admin email.
	 */
	private static function prepare_literals() {
		Redactor::reset();
		$users = get_users(
			array(
				'number' => 300,
				'fields' => array( 'user_login', 'display_name', 'user_nicename' ),
			)
		);
		$names = array();
		foreach ( $users as $u ) {
			$names[] = $u->user_login;
			$names[] = $u->user_nicename;
			$names[] = $u->display_name;
		}
		// Never mask generic words that would destroy the message.
		$names = array_filter(
			array_unique( $names ),
			static function ( $n ) {
				return strlen( $n ) >= 4 && ! in_array( strtolower( $n ), array( 'admin', 'user', 'test', 'editor', 'author' ), true );
			}
		);
		Redactor::add_literals( $names, '[user]' );
		Redactor::add_literals( array( get_bloginfo( 'admin_email' ) ), '[email]' );
		/**
		 * Extra literal strings to mask (e.g. a client's company name).
		 *
		 * @param string[] $literals Strings.
		 */
		Redactor::add_literals( (array) apply_filters( 'dev3sh_mask_literals', array() ), '[masked]' );
	}

	/**
	 * Occurrence bucket (keeps the AI cache warm while counts grow).
	 *
	 * @param int $n Count.
	 * @return string
	 */
	private static function bucket( $n ) {
		if ( $n <= 1 ) {
			return 'once';
		}
		if ( $n < 10 ) {
			return '2-9 times';
		}
		if ( $n < 100 ) {
			return '10-99 times';
		}
		return $n < 1000 ? '100-999 times' : '1000+ times';
	}

	/**
	 * Site language name.
	 *
	 * @return string
	 */
	private static function language() {
		$locale = determine_locale();
		return 0 === strpos( $locale, 'en' ) ? 'English' : $locale;
	}

	/**
	 * Readable JSON for prompts (no escaped slashes, so paths stay legible for the model).
	 *
	 * @param mixed $data Data.
	 * @return string
	 */
	public static function json( $data ) {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	}

	/**
	 * Plain text cleanup.
	 *
	 * @param mixed $text Text.
	 * @return string
	 */
	private static function clean( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/^\s*(?:\d+[.)]|[-*•])\s*/', '', $text );
		return trim( (string) preg_replace( '/[ \t]+/', ' ', (string) $text ) );
	}
}
