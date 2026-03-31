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
		add_action( 'admin_init', [ $this, 'maybe_redirect_to_wizard' ] );
		add_action( 'admin_init', [ SourcesPage::class, 'handle_post' ] );
		add_action( 'wp_ajax_cep_run_trigger',        [ AutopilotPage::class, 'ajax_trigger' ] );
		add_action( 'wp_ajax_cep_seo_run_analysis',   [ SeoAgentPage::class, 'ajax_run_analysis' ] );
		add_action( 'wp_ajax_cep_seo_apply_fix',      [ SeoAgentPage::class, 'ajax_apply_fix' ] );
		add_action( 'wp_ajax_cep_seo_apply_ai_fix',   [ SeoAgentPage::class, 'ajax_apply_ai_fix' ] );
		add_action( 'wp_ajax_cep_seo_ignore_issue',   [ SeoAgentPage::class, 'ajax_ignore_issue' ] );
		add_action( 'wp_ajax_cep_seo_bulk_fix',       [ SeoAgentPage::class, 'ajax_bulk_fix' ] );

		// Register the Setup Wizard as a submenu page + its save handler
		( new SetupWizard() )->register();
	}

	/**
	 * Redirect to the Setup Wizard on first activation.
	 * The transient is set in Activator::activate().
	 */
	public function maybe_redirect_to_wizard(): void {
		if ( ! get_transient( 'cep_first_run_redirect' ) ) {
			return;
		}
		// Don't redirect during bulk plugin activation or on the wizard page itself
		if ( isset( $_GET['activate-multi'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| ( isset( $_GET['page'] ) && 'cep-setup-wizard' === $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		delete_transient( 'cep_first_run_redirect' );
		wp_safe_redirect( admin_url( 'admin.php?page=cep-setup-wizard' ) );
		exit;
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
		add_submenu_page( 'cep-dashboard', 'Logs', 'Logs', 'manage_options', 'cep-logs', [ $this, 'render_logs' ] );
		add_submenu_page( 'cep-dashboard', 'SEO Agent', 'SEO Agent', 'manage_options', 'cep-seo-agent', [ $this, 'render_seo_agent' ] );
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

	public function render_seo_agent(): void {
		SeoAgentPage::render();
	}
}
