<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin bootstrap — wires all modules together.
 */
class Plugin {

	private static ?Plugin $instance = null;

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run(): void {
		$this->load_textdomain();
		$this->register_cli();
		$this->register_cpts();
		$this->register_rewrite_rules();

		if ( is_admin() ) {
			$this->boot_admin();
		} else {
			$this->boot_frontend();
		}

		$this->boot_modules();
		$this->schedule_crons();

		do_action( 'cep_loaded' );
	}

	private function register_cli(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cep', Cli\CepCli::class );
		}
	}

	private function load_textdomain(): void {
		load_plugin_textdomain(
			'content-engine-pro',
			false,
			dirname( plugin_basename( CEP_FILE ) ) . '/languages/'
		);
	}

	private function register_cpts(): void {
		add_action( 'init', [ CptManager::class, 'register_all' ], 0 );
	}

	private function register_rewrite_rules(): void {
		add_action( 'init', [ RewriteManager::class, 'add_rules' ] );
		add_filter( 'query_vars', [ RewriteManager::class, 'add_query_vars' ] );
		add_action( 'template_redirect', [ RewriteManager::class, 'handle_redirects' ] );
	}

	private function boot_admin(): void {
		$admin = new Admin\AdminMenu();
		$admin->register();
		add_action( 'admin_init', [ $this, 'maybe_create_tables' ], 1 );
	}

	public function maybe_create_tables(): void {
		if ( get_option( 'cep_db_version' ) !== CEP_DB_VERSION ) {
			Activator::activate();
		}
	}

	private function boot_frontend(): void {
		$frontend = new Frontend\Frontend();
		$frontend->register();
	}

	private function boot_modules(): void {
		// Schema markup
		if ( Settings::is_enabled( 'enable_schema_markup' ) ) {
			( new Publisher\SchemaInjector() )->register();
		}

		// Internal linking
		if ( Settings::is_enabled( 'enable_internal_linking' ) ) {
			( new Publisher\InternalLinker() )->register();
		}

		// Strip sources section from frontend display
		if ( Settings::is_enabled( 'enable_source_stripping' ) ) {
			add_filter( 'the_content', [ Publisher\ContentFilter::class, 'strip_sources_section' ], 99 );
		}

		// Affiliate link insertion
		if ( Settings::is_enabled( 'enable_affiliate_links' ) ) {
			( new Affiliate\LinkInserter() )->register();
		}

		// Click tracking redirect
		if ( Settings::is_enabled( 'enable_click_tracking' ) ) {
			( new Affiliate\ClickTracker() )->register();
		}

		// Review shortcode
		if ( Settings::is_enabled( 'reviews_cpt_enabled' ) ) {
			add_shortcode( 'cep_review', [ new Reviews\ReviewShortcode(), 'render' ] );
			add_shortcode( 'cep_compare', [ new Reviews\CompareShortcode(), 'render' ] );
		}

		// REST API
		if ( Settings::is_enabled( 'enable_rest_api' ) ) {
			add_action( 'rest_api_init', [ new Api\RestApi(), 'register_routes' ] );
		}

		// Meta boxes
		add_action( 'add_meta_boxes', [ Admin\MetaBoxes::class, 'register' ] );
		add_action( 'save_post', [ Admin\MetaBoxes::class, 'save' ], 10, 2 );

		do_action( 'cep_modules_booted' );
	}

	private function schedule_crons(): void {
		add_filter( 'cron_schedules', [ Crawl\Scheduler::class, 'add_schedules' ] );
		Crawl\Scheduler::schedule_all();

		// Content crawling
		add_action( 'cep_crawl_morning', [ Crawl\Crawler::class, 'run_window' ], 10, 0 );
		add_action( 'cep_crawl_midday',  [ Crawl\Crawler::class, 'run_window' ], 10, 0 );
		add_action( 'cep_crawl_evening', [ Crawl\Crawler::class, 'run_window' ], 10, 0 );
		add_action( 'cep_crawl_weekly',  [ Crawl\Crawler::class, 'run_window' ], 10, 0 );

		// Article autopilot
		add_action( 'cep_article_autopilot', [ Articles\ArticleAutopilot::class, 'run' ], 10, 0 );

		// Review autopilot (legacy manager + new discoverer/generator)
		add_action( 'cep_reviews_discover', [ Reviews\ReviewDiscoverer::class, 'run' ], 10, 0 );
		add_action( 'cep_reviews_generate', [ Reviews\ReviewAutopilot::class, 'run' ], 10, 0 );
		add_action( 'cep_reviews_update',   [ Reviews\ReviewManager::class, 'run_updates' ], 10, 0 );

		// Jobs autopilot
		add_action( 'cep_jobs_aggregate', [ Jobs\JobAggregator::class, 'run' ], 10, 0 );

		// Trending topics autopilot
		add_action( 'cep_trending_autopilot', [ Trending\TrendingAutopilot::class, 'run' ], 10, 0 );

		// SEO Agent
		if ( Settings::is_enabled( 'enable_seo_agent' ) ) {
			add_action( 'cep_seo_analysis', [ Seo\SeoAgent::class, 'run' ], 10, 0 );
		}

		// Maintenance
		add_action( 'cep_log_prune', [ Logger::class, 'prune' ], 10, 0 );
	}
}
