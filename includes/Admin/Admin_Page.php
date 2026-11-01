<?php
/**
 * Admin menu + React app mount.
 *
 * @package Dev3\AiSiteHealth
 */

namespace Dev3\AiSiteHealth\Admin;

use Dev3\AiSiteHealth\Core3\Core\Assets;
use Dev3\AiSiteHealth\Analyze\Score;
use Dev3\AiSiteHealth\Core3\Core\Capabilities;
use Dev3\AiSiteHealth\Core3\Core\Settings_Store;
use Dev3\AiSiteHealth\Data\Events;
use Dev3\AiSiteHealth\Data\Issues;

defined( 'ABSPATH' ) || exit;

/**
 * Top-level menu page.
 */
class Admin_Page {

	/**
	 * Hook suffix.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Registers hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DEV3SH_FILE ), array( $this, 'links' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}

	/**
	 * WP dashboard widget (score, critical issues, failed logins).
	 */
	public function dashboard_widget() {
		if ( ! Settings_Store::get( 'dashboard_widget', true ) || ! current_user_can( Capabilities::manage() ) ) {
			return;
		}
		wp_add_dashboard_widget( 'dev3sh_widget', esc_html__( 'DEV3 AI Site Health', 'dev3-ai-site-health-analyst' ), array( $this, 'render_widget' ) );
	}

	/**
	 * Widget body.
	 */
	public function render_widget() {
		$score  = Score::compute();
		$counts = Issues::counts();
		$failed = Events::count( 'login_failed', time() - DAY_IN_SECONDS );
		$color  = array(
			'good' => '#15803d',
			'fair' => '#b45309',
			'poor' => '#b91c1c',
		)[ Score::grade( $score ) ];
		$url    = admin_url( 'admin.php?page=' . DEV3SH_SLUG );
		echo '<div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">';
		echo '<div style="width:64px;height:64px;border-radius:50%;border:5px solid ' . esc_attr( $color ) . ';display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700" aria-label="' . esc_attr__( 'Health score', 'dev3-ai-site-health-analyst' ) . '">' . (int) $score . '</div>';
		echo '<ul style="margin:0;list-style:none;padding:0">';
		/* translators: %d: count. */
		echo '<li>' . esc_html( sprintf( _n( '%d critical issue', '%d critical issues', $counts['severity']['critical'], 'dev3-ai-site-health-analyst' ), $counts['severity']['critical'] ) ) . '</li>';
		/* translators: %d: count. */
		echo '<li>' . esc_html( sprintf( _n( '%d open issue', '%d open issues', $counts['total'], 'dev3-ai-site-health-analyst' ), $counts['total'] ) ) . '</li>';
		/* translators: %d: count. */
		echo '<li>' . esc_html( sprintf( _n( '%d failed login (24 h)', '%d failed logins (24 h)', $failed, 'dev3-ai-site-health-analyst' ), $failed ) ) . '</li>';
		echo '</ul></div>';
		echo '<p style="margin:12px 0 0"><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open DEV3 AI Site Health', 'dev3-ai-site-health-analyst' ) . '</a></p>';
	}

	/**
	 * Menu.
	 */
	public function menu() {
		$this->hook = (string) add_menu_page( DEV3SH_NAME, __( 'DEV3 AI Site Health', 'dev3-ai-site-health-analyst' ), Capabilities::manage(), DEV3SH_SLUG, array( $this, 'render' ), 'dashicons-shield-alt', 81 );
	}

	/**
	 * Mount point.
	 */
	public function render() {
		// The h1 names the page for screen readers; wp-header-end keeps admin notices above the app.
		echo '<div class="wrap dev3sh-wrap"><h1 class="screen-reader-text">' . esc_html( DEV3SH_NAME ) . '</h1><hr class="wp-header-end"><div id="dev3sh-admin-root" class="dev3sh-root dev3sh-root--app"></div></div>';
	}

	/**
	 * Assets on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function enqueue( $hook ) {
		if ( $hook === $this->hook ) {
			Assets::enqueue( 'admin' );
		}
	}

	/**
	 * Settings link.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . DEV3SH_SLUG ) ) . '">' . esc_html__( 'Settings', 'dev3-ai-site-health-analyst' ) . '</a>' );
		return $links;
	}
}
