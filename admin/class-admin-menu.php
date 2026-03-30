<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu and all submenu pages.
 */
class AdminMenu {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_early_actions' ] );
		add_action( 'admin_init', [ SourcesPage::class, 'handle_post' ] );

		// SEO Autopilot AJAX actions
		add_action( 'wp_ajax_cep_seo_fix_issue',    [ $this, 'ajax_seo_fix' ] );
		add_action( 'wp_ajax_cep_seo_ai_fix_issue', [ $this, 'ajax_seo_ai_fix' ] );
		add_action( 'wp_ajax_cep_seo_ignore_issue', [ $this, 'ajax_seo_ignore' ] );
	}

	public function add_menus(): void {
		$brand = Settings::get( 'brand_name', 'Content Engine' );

		add_menu_page(
			$brand,
			$brand,
			'manage_options',
			'cep-dashboard',
			[ $this, 'render_dashboard' ],
			'dashicons-database',
			3
		);

		add_submenu_page( 'cep-dashboard', 'Dashboard', 'Dashboard', 'manage_options', 'cep-dashboard', [ $this, 'render_dashboard' ] );
		add_submenu_page( 'cep-dashboard', 'Autopilot', 'Autopilot', 'manage_options', 'cep-autopilot', [ $this, 'render_autopilot' ] );
		add_submenu_page( 'cep-dashboard', 'Sources', 'Sources', 'manage_options', 'cep-sources', [ $this, 'render_sources' ] );
		add_submenu_page( 'cep-dashboard', 'Content Queue', 'Content Queue', 'manage_options', 'cep-queue', [ $this, 'render_queue' ] );
		add_submenu_page( 'cep-dashboard', 'Reviews', 'Reviews', 'manage_options', 'cep-reviews', [ $this, 'render_reviews' ] );
		add_submenu_page( 'cep-dashboard', 'Affiliates', 'Affiliates', 'manage_options', 'cep-affiliates', [ $this, 'render_affiliates' ] );
		add_submenu_page( 'cep-dashboard', 'SEO Autopilot', 'SEO Autopilot', 'manage_options', 'cep-seo', [ new SeoPage(), 'render' ] );
		add_submenu_page( 'cep-dashboard', 'Logs', 'Logs', 'manage_options', 'cep-logs', [ $this, 'render_logs' ] );
		add_submenu_page( 'cep-dashboard', 'Settings', 'Settings', 'manage_options', 'cep-settings', [ new SettingsPage(), 'render' ] );
	}

	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'cep-' ) && false === strpos( $hook, 'content-engine' ) ) {
			return;
		}
		wp_enqueue_style( 'cep-admin', CEP_URL . 'assets/css/admin.css', [], CEP_VERSION );
		wp_enqueue_script( 'cep-admin', CEP_URL . 'assets/js/admin.js', [ 'jquery' ], CEP_VERSION, true );
		wp_localize_script( 'cep-admin', 'cepAdmin', [
			'nonce'   => wp_create_nonce( 'cep_admin_nonce' ),
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		] );
	}

	public function handle_early_actions(): void {
		if ( ! isset( $_POST['cep_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'cep_admin_action' );

		$action = sanitize_key( $_POST['cep_action'] );
		do_action( 'cep_admin_action_' . $action, $_POST );
	}

	// ─── Page Renderers ──────────────────────────────────────────────────────

	public function render_dashboard(): void {
		DashboardPage::render();
	}

	public function render_autopilot(): void {
		AutopilotPage::render();
	}

	public function render_sources(): void {
		SourcesPage::render();
	}

	public function render_queue(): void {
		QueuePage::render();
	}

	public function render_reviews(): void {
		ReviewsPage::render();
	}

	public function render_affiliates(): void {
		AffiliatesPage::render();
	}

	public function render_logs(): void {
		LogsPage::render();
	}

	// ─── SEO Autopilot AJAX ───────────────────────────────────────────────────

	public function ajax_seo_fix(): void {
		check_ajax_referer( 'cep_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
			return;
		}
		$issue_id = (int) ( $_POST['issue_id'] ?? 0 );
		if ( $issue_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid issue ID.' ] );
			return;
		}
		$fixed = \ContentEnginePro\Seo\SeoAutopilot::apply_manual_fix( $issue_id );
		if ( $fixed ) {
			wp_send_json_success( [ 'message' => 'Fix applied successfully.' ] );
		} else {
			wp_send_json_error( [ 'message' => 'Could not apply fix automatically. Try AI Fix or edit the post manually.' ] );
		}
	}

	public function ajax_seo_ai_fix(): void {
		check_ajax_referer( 'cep_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
			return;
		}
		$issue_id = (int) ( $_POST['issue_id'] ?? 0 );
		if ( $issue_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid issue ID.' ] );
			return;
		}
		$fixed = \ContentEnginePro\Seo\SeoAutopilot::apply_ai_fix( $issue_id );
		if ( $fixed ) {
			wp_send_json_success( [ 'message' => 'AI fix applied successfully.' ] );
		} else {
			wp_send_json_error( [ 'message' => 'AI fix could not be applied. Please review the post manually.' ] );
		}
	}

	public function ajax_seo_ignore(): void {
		check_ajax_referer( 'cep_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ] );
			return;
		}
		$issue_id = (int) ( $_POST['issue_id'] ?? 0 );
		if ( $issue_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid issue ID.' ] );
			return;
		}
		$done = \ContentEnginePro\Seo\SeoAutopilot::ignore_issue( $issue_id );
		if ( $done ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( [ 'message' => 'Could not update issue status.' ] );
		}
	}
}
