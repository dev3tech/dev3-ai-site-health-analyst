<?php
/**
 * Privacy helpers: PII masking, the privacy-policy snippet, and GDPR exporter/eraser registration.
 *
 * Generated from the shared DEV3 core (_shared/dev3-wp-core): edit it there and re-sync.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Core3\Core;

use Dev3\AiSiteHealth\Core3\Core\Settings_Store as Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Call Privacy::init() once from the plugin boot. It adds the policy text and an exporter/eraser
 * for the shared usage log. Plugins add their own personal data with add_exporter()/add_eraser().
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
class Privacy {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( self::class, 'policy' ) );
		// Labels are translated on init: translating earlier triggers WP 6.7's "loaded too early" notice.
		add_action(
			'init',
			static function () {
				self::add_exporter( __( 'AI usage log', 'dev3-ai-site-health-analyst' ), array( self::class, 'export_usage' ) );
				self::add_eraser( __( 'AI usage log', 'dev3-ai-site-health-analyst' ), array( self::class, 'erase_usage' ) );
			}
		);
	}

	/**
	 * Masks emails, phone numbers, card-like numbers and IPs in text sent to the AI.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
		$text = preg_replace( '/\b(?:\d[ -]?){13,19}\b/', '[card]', $text );
		$text = preg_replace( '/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '[ip]', $text );
		$text = preg_replace_callback(
			'/(?<![\w])\+?\d[\d ().-]{7,}\d(?![\w])/',
			static function ( $m ) {
				$digits = strlen( preg_replace( '/\D/', '', $m[0] ) );
				// Dates (2026-10-25) and short numbers are not phone numbers.
				return $digits >= 9 && $digits <= 15 && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $m[0] ) ? '[phone]' : $m[0];
			},
			$text
		);
		/**
		 * Filters masked text (add your own patterns).
		 *
		 * @param string $text Masked text.
		 */
		return (string) apply_filters( 'dev3sh_mask_pii', $text );
	}

	/**
	 * Masks when the "PII masking" setting is on.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function maybe_mask( $text ) {
		return Settings::get( 'pii_masking', false ) ? self::mask( $text ) : (string) $text;
	}

	/**
	 * Suggested privacy-policy text (Tools → Privacy → Policy Guide).
	 *
	 * @return string HTML.
	 */
	public static function policy_text() {
		$text = '<p>' . sprintf(
			/* translators: %s: plugin name. */
			esc_html__( 'This site uses %s, which sends the content needed for AI features (for example product details, reviews or form text) to Google Gemini, operated by Google LLC, as a data processor. Only the fields needed for the requested feature are sent. Google\'s terms and privacy policy apply: https://ai.google.dev/gemini-api/terms', 'dev3-ai-site-health-analyst' ),
			esc_html( DEV3SH_NAME )
		) . '</p><p>' . esc_html__( 'The plugin keeps a usage log (time, feature, token counts and the user account that triggered the request) to show AI usage and costs to administrators. It does not store the prompts.', 'dev3-ai-site-health-analyst' ) . '</p>';
		/**
		 * Filters the privacy-policy snippet.
		 *
		 * @param string $text HTML.
		 */
		return (string) apply_filters( 'dev3sh_privacy_policy_text', $text );
	}

	/**
	 * Adds the policy snippet.
	 */
	public static function policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( DEV3SH_NAME, wp_kses_post( self::policy_text() ) );
		}
	}

	/**
	 * Registers a personal-data exporter.
	 *
	 * @param string   $label    Group label.
	 * @param callable $callback fn( string $email, int $page ): array { data, done } (WP exporter format).
	 */
	public static function add_exporter( $label, callable $callback ) {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( $exporters ) use ( $label, $callback ) {
				$exporters[ DEV3SH_SLUG . '-' . sanitize_title( $label ) ] = array(
					'exporter_friendly_name' => DEV3SH_NAME . ': ' . $label,
					'callback'               => $callback,
				);
				return $exporters;
			}
		);
	}

	/**
	 * Registers a personal-data eraser.
	 *
	 * @param string   $label    Label.
	 * @param callable $callback fn( string $email, int $page ): array { items_removed, items_retained, messages, done }.
	 */
	public static function add_eraser( $label, callable $callback ) {
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( $erasers ) use ( $label, $callback ) {
				$erasers[ DEV3SH_SLUG . '-' . sanitize_title( $label ) ] = array(
					'eraser_friendly_name' => DEV3SH_NAME . ': ' . $label,
					'callback'             => $callback,
				);
				return $erasers;
			}
		);
	}

	/**
	 * Exporter for the usage log.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export_usage( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$table = Schema::usage_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, task, model, total_tokens, created_at FROM {$table} WHERE user_id = %d ORDER BY id LIMIT 500 OFFSET %d", $user->ID, ( max( 1, (int) $page ) - 1 ) * 500 ), ARRAY_A );
		$data  = array();
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => DEV3SH_SLUG . '-usage',
				'group_label' => DEV3SH_NAME . ': ' . __( 'AI usage log', 'dev3-ai-site-health-analyst' ),
				'item_id'     => DEV3SH_SLUG . '-usage-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Feature', 'dev3-ai-site-health-analyst' ),
						'value' => $row['task'],
					),
					array(
						'name'  => __( 'Model', 'dev3-ai-site-health-analyst' ),
						'value' => $row['model'],
					),
					array(
						'name'  => __( 'Tokens', 'dev3-ai-site-health-analyst' ),
						'value' => $row['total_tokens'],
					),
					array(
						'name'  => __( 'Date', 'dev3-ai-site-health-analyst' ),
						'value' => $row['created_at'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( (array) $rows ) < 500,
		);
	}

	/**
	 * Eraser for the usage log: anonymises the user id (keeps the token totals for cost reports).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase_usage( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			$removed = (int) $wpdb->update( Schema::usage_table(), array( 'user_id' => 0 ), array( 'user_id' => $user->ID ), array( '%d' ), array( '%d' ) );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
