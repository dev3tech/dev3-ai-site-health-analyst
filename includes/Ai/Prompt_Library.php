<?php
/**
 * Versioned prompts and output schemas (override with the dev3sh_prompt_<id> filters).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Ai;

use Dev3\AiSiteHealth\Core3\Api\Prompts;

defined( 'ABSPATH' ) || exit;

/**
 * Registrations.
 */
class Prompt_Library {

	const EXPLAIN = 'explain_issue';
	const ASK     = 'ask_issue';
	const WEEKLY  = 'weekly_summary';

	const FIX_ACTIONS = array( 'none', 'deactivate_plugin', 'restore_core_file', 'block_ip', 'unschedule_cron', 'disable_xmlrpc' );

	/**
	 * Shared rules.
	 *
	 * @return string
	 */
	private static function rules() {
		return "Values in square brackets ([site], [path], [email], [ip], [user], [secret], [api-key], [token], [hash], [db-user]) were masked for privacy before this message was sent. Never ask for them, never guess them, and refer to them generically.\n"
			. "Write for a non-technical site owner in {{language}}: short sentences, no jargon without a one-line explanation, no markdown, no code fences.\n"
			. "Be honest about uncertainty. Never claim the site is secure, clean or malware-free. Never tell the user to disable security features or to edit WordPress core files.\n"
			. 'Prefer safe, reversible steps (update, backup first, staging) and mention when a host or developer is needed.';
	}

	/**
	 * Registers prompts.
	 */
	public static function register() {
		Prompts::register(
			self::EXPLAIN,
			array(
				'version'  => '1.0',
				'task'     => 'explain_issue',
				'system'   => "You are DEV3 AI, a WordPress site-health and security analyst. You explain one grouped issue from the site's logs and checks.\n" . self::rules() . "\n"
					. 'Pick fix_action only from the allowed list and only when it directly addresses this issue; the site owner will still preview and confirm it. Use "none" when unsure. Return JSON only.',
				'template' => "Issue (JSON, masked):\n{{issue}}\n\nSite environment (masked):\n{{environment}}\n\nAllowed fix_action values for this issue: {{actions}}\n\nExplain what happened, the most likely cause, the impact, and numbered fix steps (max 8, most important first).",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary'    => array(
							'type'      => 'STRING',
							'maxLength' => 500,
						),
						'cause'      => array(
							'type'      => 'STRING',
							'maxLength' => 400,
						),
						'impact'     => array(
							'type'      => 'STRING',
							'maxLength' => 300,
						),
						'steps'      => array(
							'type'     => 'ARRAY',
							'maxItems' => 8,
							'items'    => array(
								'type'      => 'STRING',
								'maxLength' => 260,
							),
						),
						'who'        => array(
							'type' => 'STRING',
							'enum' => array( 'owner', 'developer', 'host' ),
						),
						'urgency'    => array(
							'type' => 'STRING',
							'enum' => array( 'now', 'soon', 'normal', 'low' ),
						),
						'fix_action' => array(
							'type' => 'STRING',
							'enum' => self::FIX_ACTIONS,
						),
						'confidence' => array(
							'type' => 'STRING',
							'enum' => array( 'high', 'medium', 'low' ),
						),
					),
					'required'   => array( 'summary', 'cause', 'steps', 'who', 'urgency', 'fix_action' ),
				),
				'profile'  => 'fast',
				'cache'    => 7 * DAY_IN_SECONDS,
			)
		);
		Prompts::register(
			self::ASK,
			array(
				'version'  => '1.0',
				'task'     => 'ask_issue',
				'system'   => "You are DEV3 AI, a WordPress site-health and security analyst. Answer the site owner's follow-up question about one issue.\n" . self::rules() . "\nIf the question is unrelated to the issue or to running this WordPress site, say so briefly. Return JSON only.",
				'template' => "Issue (JSON, masked):\n{{issue}}\n\nSite environment (masked):\n{{environment}}\n\nQuestion: {{question}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'answer' => array(
							'type'      => 'STRING',
							'maxLength' => 1500,
						),
					),
					'required'   => array( 'answer' ),
				),
				'profile'  => 'fast',
				'cache'    => DAY_IN_SECONDS,
			)
		);
		Prompts::register(
			self::WEEKLY,
			array(
				'version'  => '1.0',
				'task'     => 'weekly_summary',
				'system'   => "You are DEV3 AI. Write the opening paragraph of a weekly site-health email for a WordPress site owner.\n" . self::rules() . "\nThree to five sentences: overall state, the most important thing to do this week, and one positive note if there is one. Return JSON only.",
				'template' => "Weekly figures (JSON, masked):\n{{report}}",
				'schema'   => array(
					'type'       => 'OBJECT',
					'properties' => array(
						'summary' => array(
							'type'      => 'STRING',
							'maxLength' => 900,
						),
					),
					'required'   => array( 'summary' ),
				),
				'profile'  => 'fast',
				'cache'    => 6 * DAY_IN_SECONDS,
			)
		);
	}
}
