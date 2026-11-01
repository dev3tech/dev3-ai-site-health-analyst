<?php
/**
 * GDPR exporter/eraser for the security + change log (rows linked to a user).
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth;

use Dev3\AiSiteHealth\Core3\Core\Privacy;
use Dev3\AiSiteHealth\Data\Tables;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy callbacks.
 */
class Privacy_Data {

	/**
	 * Registers on init (labels are translated).
	 */
	public static function register() {
		Privacy::add_exporter( __( 'Security log', 'dev3-ai-site-health-analyst' ), array( __CLASS__, 'export' ) );
		Privacy::add_eraser( __( 'Security log', 'dev3-ai-site-health-analyst' ), array( __CLASS__, 'erase' ) );
	}

	/**
	 * Exporter.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		$table = Tables::events();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, type, ip, path, message, created_at FROM {$table} WHERE user_id = %d OR user_login = %s ORDER BY id LIMIT 500 OFFSET %d", $user->ID, $user->user_login, ( max( 1, (int) $page ) - 1 ) * 500 ), ARRAY_A );
		$data = array();
		foreach ( (array) $rows as $row ) {
			$data[] = array(
				'group_id'    => DEV3SH_SLUG . '-security',
				'group_label' => DEV3SH_NAME . ': ' . __( 'Security log', 'dev3-ai-site-health-analyst' ),
				'item_id'     => DEV3SH_SLUG . '-event-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Event', 'dev3-ai-site-health-analyst' ),
						'value' => $row['type'] . ': ' . $row['message'],
					),
					array(
						'name'  => __( 'IP address', 'dev3-ai-site-health-analyst' ),
						'value' => $row['ip'],
					),
					array(
						'name'  => __( 'Path', 'dev3-ai-site-health-analyst' ),
						'value' => $row['path'],
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
	 * Eraser: anonymises the user link and IP of the user's events (keeps counts for security stats).
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 * @return array
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		unset( $page );
		$user    = get_user_by( 'email', $email );
		$removed = 0;
		if ( $user ) {
			$table = Tables::events();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$removed = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET user_id = 0, user_login = '', ip = '' WHERE user_id = %d OR user_login = %s", $user->ID, $user->user_login ) );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
