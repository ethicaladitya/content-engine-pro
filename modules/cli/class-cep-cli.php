<?php
namespace ContentEnginePro\Cli;

use ContentEnginePro\Settings;
use ContentEnginePro\Articles\ArticleAutopilot;
use ContentEnginePro\Crawl\Crawler;
use ContentEnginePro\Jobs\JobAggregator;
use ContentEnginePro\Trending\TrendingAutopilot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP-CLI commands for Content Engine Pro.
 *
 * Usage:
 *   wp cep status
 *   wp cep crawl [--window=<window>]
 *   wp cep publish [--count=<count>]
 *   wp cep jobs
 *   wp cep pipeline
 *   wp cep reset-threshold
 */
class CepCli {

	/**
	 * Show queue status and next scheduled cron times.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep status
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		global $wpdb;

		\WP_CLI::line( '' );
		\WP_CLI::line( '=== Content Engine Pro — Status ===' );
		\WP_CLI::line( '' );

		// Raw content queue
		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}cep_raw_content GROUP BY status",
			ARRAY_A
		);

		if ( $rows ) {
			\WP_CLI::line( '📋 Raw Content Queue:' );
			$items = [];
			foreach ( $rows as $row ) {
				$items[] = [ 'Status' => ucfirst( str_replace( '_', ' ', $row['status'] ) ), 'Count' => $row['cnt'] ];
			}
			\WP_CLI\Utils\format_items( 'table', $items, [ 'Status', 'Count' ] );
		} else {
			\WP_CLI::line( '📋 Raw Content Queue: empty' );
		}

		\WP_CLI::line( '' );

		// Sources
		$source_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_sources WHERE is_active = 1" );
		\WP_CLI::line( "🌐 Active sources: {$source_count}" );

		// Published posts
		$post_type = Settings::get( 'primary_cpt_slug', 'post' );
		$published  = (int) ( wp_count_posts( $post_type )->publish ?? 0 );
		\WP_CLI::line( "📰 Published articles: {$published}" );

		\WP_CLI::line( '' );

		// Cron schedule
		\WP_CLI::line( '⏰ Next Scheduled Runs:' );
		$cron_hooks = [
			'cep_crawl_morning'    => 'Crawl (morning)',
			'cep_crawl_midday'     => 'Crawl (midday)',
			'cep_crawl_evening'    => 'Crawl (evening)',
			'cep_article_autopilot' => 'Article Autopilot',
			'cep_jobs_aggregate'    => 'Jobs Aggregate',
			'cep_trending_autopilot' => 'Trending Topics',
		];

		$schedule_items = [];
		foreach ( $cron_hooks as $hook => $label ) {
			$next = wp_next_scheduled( $hook );
			$schedule_items[] = [
				'Hook'  => $label,
				'Next'  => $next ? human_time_diff( time(), $next ) . ' (' . gmdate( 'Y-m-d H:i', $next ) . ')' : 'Not scheduled',
			];
		}
		\WP_CLI\Utils\format_items( 'table', $schedule_items, [ 'Hook', 'Next' ] );

		\WP_CLI::line( '' );

		// Settings summary
		\WP_CLI::line( '⚙️  Settings:' );
		\WP_CLI::line( '   Signal threshold: ' . Settings::get( 'signal_threshold', 50 ) );
		\WP_CLI::line( '   Max per run: ' . Settings::get( 'article_max_per_run', 3 ) );
		\WP_CLI::line( '   Auto-publish: ' . ( Settings::is_enabled( 'auto_publish' ) ? 'yes' : 'no' ) );
		\WP_CLI::line( '   AI model: ' . Settings::get( 'ai_model', 'gpt-4o' ) );
		\WP_CLI::line( '' );
	}

	/**
	 * Crawl configured sources and populate the raw content queue.
	 *
	 * ## OPTIONS
	 *
	 * [--window=<window>]
	 * : Crawl window to run. Options: morning, midday, evening, weekly, all. Default: all.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep crawl
	 *   wp cep crawl --window=morning
	 *
	 * @when after_wp_load
	 */
	public function crawl( array $args, array $assoc_args ): void {
		$window = \WP_CLI\Utils\get_flag_value( $assoc_args, 'window', 'all' );

		global $wpdb;
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );

		if ( $window === 'all' ) {
			\WP_CLI::log( 'Running all crawl windows...' );
			foreach ( [ 'morning', 'midday', 'evening', 'weekly' ] as $w ) {
				\WP_CLI::log( "  → Crawling window: {$w}" );
				Crawler::run_window( $w );
			}
		} else {
			\WP_CLI::log( "Running crawl window: {$window}" );
			Crawler::run_window( $window );
		}

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );
		$added = $after - $before;

		\WP_CLI::success( "Crawl complete. {$added} new item(s) added to queue. Queue now has {$after} pending." );
	}

	/**
	 * Run the article autopilot to publish pending items.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : Number of articles to publish. Overrides the max_per_run setting.
	 *
	 * [--force]
	 * : Publish even if signal_threshold filters everything out (resets threshold to 0 temporarily).
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep publish
	 *   wp cep publish --count=5
	 *   wp cep publish --force
	 *
	 * @when after_wp_load
	 */
	public function publish( array $args, array $assoc_args ): void {
		$count = \WP_CLI\Utils\get_flag_value( $assoc_args, 'count', null );
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		global $wpdb;

		// Handle --force: temporarily set threshold to 0 and flip below_threshold → pending
		if ( $force ) {
			$settings = get_option( 'cep_settings', [] );
			$settings['signal_threshold'] = 0;
			update_option( 'cep_settings', $settings );

			$flipped = $wpdb->query( "UPDATE {$wpdb->prefix}cep_raw_content SET status = 'pending' WHERE status = 'below_threshold'" );
			\WP_CLI::log( "Force mode: threshold set to 0, {$flipped} below-threshold item(s) moved to pending." );
		}

		// Handle --count override
		$original_max = null;
		if ( $count !== null ) {
			$count = max( 1, (int) $count );
			$settings = get_option( 'cep_settings', [] );
			$original_max = $settings['article_max_per_run'] ?? 3;
			$settings['article_max_per_run'] = $count;
			update_option( 'cep_settings', $settings );
			\WP_CLI::log( "Publishing up to {$count} article(s)..." );
		} else {
			$max = Settings::get( 'article_max_per_run', 3 );
			\WP_CLI::log( "Running article autopilot (max {$max} per run)..." );
		}

		$before_published = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Settings::get( 'primary_cpt_slug', 'post' ) )
		);

		ArticleAutopilot::run();

		// Restore original max if we overrode it
		if ( $original_max !== null ) {
			$settings = get_option( 'cep_settings', [] );
			$settings['article_max_per_run'] = $original_max;
			update_option( 'cep_settings', $settings );
		}

		$after_published = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Settings::get( 'primary_cpt_slug', 'post' ) )
		);

		$new_count = $after_published - $before_published;
		$pending   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );

		\WP_CLI::success( "Published {$new_count} article(s). {$pending} item(s) still pending in queue." );
	}

	/**
	 * Re-format existing job posts (backfill structured content from stored meta).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Max jobs to reformat in this run. Default: all published jobs.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep reformat-jobs
	 *   wp cep reformat-jobs --limit=20
	 *
	 * @when after_wp_load
	 */
	public function reformat_jobs( array $args, array $assoc_args ): void {
		$limit = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 0 );

		\WP_CLI::log( 'Re-formatting published job posts…' );
		$updated = \ContentEnginePro\Jobs\JobReformat::run( $limit );
		\WP_CLI::success( "Re-formatted {$updated} job post(s)." );
	}

	/**
	 * Run the jobs aggregator.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep jobs
	 *
	 * @when after_wp_load
	 */
	public function jobs(): void {
		\WP_CLI::log( 'Running jobs aggregator...' );
		JobAggregator::run();
		\WP_CLI::success( 'Jobs aggregation complete.' );
	}

	/**
	 * Run the full pipeline: crawl all windows → publish articles → aggregate jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--count=<count>]
	 * : Max articles to publish in this run.
	 *
	 * [--force]
	 * : Reset signal threshold and flip below_threshold items to pending before publishing.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep pipeline
	 *   wp cep pipeline --count=5 --force
	 *
	 * @when after_wp_load
	 */
	public function pipeline( array $args, array $assoc_args ): void {
		global $wpdb;

		\WP_CLI::line( '' );
		\WP_CLI::line( '🚀 Content Engine Pro — Full Pipeline' );
		\WP_CLI::line( str_repeat( '─', 40 ) );

		// Step 1: Crawl
		\WP_CLI::log( '[1/3] Crawling all windows...' );
		$before_queue = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );
		foreach ( [ 'morning', 'midday', 'evening', 'weekly' ] as $w ) {
			Crawler::run_window( $w );
		}
		$after_queue = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );
		$crawled = $after_queue - $before_queue;
		\WP_CLI::log( "    ✓ Crawled {$crawled} new item(s) → {$after_queue} pending total" );

		// Handle --force
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		if ( $force ) {
			$settings = get_option( 'cep_settings', [] );
			$settings['signal_threshold'] = 0;
			update_option( 'cep_settings', $settings );
			$flipped = $wpdb->query( "UPDATE {$wpdb->prefix}cep_raw_content SET status = 'pending' WHERE status = 'below_threshold'" );
			\WP_CLI::log( "    ✓ Force: {$flipped} below-threshold item(s) moved to pending" );
		}

		// Handle --count
		$count        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'count', null );
		$original_max = null;
		if ( $count !== null ) {
			$count = max( 1, (int) $count );
			$settings = get_option( 'cep_settings', [] );
			$original_max = $settings['article_max_per_run'] ?? 3;
			$settings['article_max_per_run'] = $count;
			update_option( 'cep_settings', $settings );
		}

		// Step 2: Publish articles
		\WP_CLI::log( '[2/3] Running article autopilot...' );
		$post_type = Settings::get( 'primary_cpt_slug', 'post' );
		$before_pub = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type ) );
		ArticleAutopilot::run();
		$after_pub  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $post_type ) );
		$published  = $after_pub - $before_pub;
		\WP_CLI::log( "    ✓ Published {$published} article(s) → {$after_pub} total live" );

		// Restore max if overridden
		if ( $original_max !== null ) {
			$settings = get_option( 'cep_settings', [] );
			$settings['article_max_per_run'] = $original_max;
			update_option( 'cep_settings', $settings );
		}

		// Step 3: Jobs
		\WP_CLI::log( '[3/3] Running jobs aggregator...' );
		JobAggregator::run();
		\WP_CLI::log( '    ✓ Jobs aggregation complete' );

		\WP_CLI::line( str_repeat( '─', 40 ) );
		\WP_CLI::success( "Pipeline complete! Crawled: {$crawled} · Published: {$published}" );
		\WP_CLI::line( '' );
	}

	/**
	 * Reset signal threshold to 0 and move all below-threshold items back to pending.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep reset-threshold
	 *
	 * @when after_wp_load
	 */
	public function reset_threshold(): void {
		global $wpdb;

		$settings = get_option( 'cep_settings', [] );
		$old = $settings['signal_threshold'] ?? 50;
		$settings['signal_threshold'] = 0;
		update_option( 'cep_settings', $settings );

		$flipped = $wpdb->query( "UPDATE {$wpdb->prefix}cep_raw_content SET status = 'pending' WHERE status = 'below_threshold'" );

		\WP_CLI::success( "Threshold reset from {$old} → 0. {$flipped} item(s) moved from below_threshold to pending." );
	}
	/**
	 * Discover trending topics and queue them for article generation.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show topics that would be queued without actually inserting anything.
	 *
	 * [--force]
	 * : Run even if trending autopilot is disabled in settings.
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep trending
	 *   wp cep trending --dry-run
	 *
	 * @when after_wp_load
	 */
	public function trending( array $args, array $assoc_args ): void {
		$dry_run = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force   = (bool) \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

		if ( ! Settings::is_enabled( 'trending_autopilot_enabled' ) && ! $force ) {
			\WP_CLI::warning( 'Trending autopilot is disabled. Use --force to run anyway, or enable it in Settings → Niche & Autopilot.' );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( '[DRY RUN] Discovering trending topics (no DB changes)...' );
		} else {
			\WP_CLI::log( 'Discovering trending topics and queuing for article generation...' );
		}

		$min_score = (int) Settings::get( 'trending_min_demand_score', 50 );
		$region    = Settings::get( 'trending_region', 'US' );
		\WP_CLI::log( "  Region: {$region} | Min demand score: {$min_score}" );

		$queued = TrendingAutopilot::discover_and_queue( $dry_run );

		if ( $dry_run ) {
			\WP_CLI::success( "[DRY RUN] Would have queued {$queued} topic(s). Run without --dry-run to insert." );
		} else {
			\WP_CLI::success( "Trending discovery complete. {$queued} topic(s) queued for article generation." );
		}
	}

	/**
	 * Manage the Deals Engine.
	 *
	 * ## OPTIONS
	 *
	 * <subcommand>
	 * : status | discover | generate | monitor | seed-sources
	 *
	 * [--count=<count>]
	 * : Max deals to generate (overrides deals_max_per_run setting).
	 *
	 * ## EXAMPLES
	 *
	 *   wp cep deals status
	 *   wp cep deals discover
	 *   wp cep deals generate --count=3
	 *   wp cep deals monitor
	 *   wp cep deals seed-sources
	 *
	 * @when after_wp_load
	 */
	public function deals( array $args, array $assoc_args ): void {
		$sub = $args[0] ?? 'status';

		switch ( $sub ) {
			case 'status':
				$stats = \ContentEnginePro\Deals\DealDiscoverer::get_stats();
				\WP_CLI::line( '' );
				\WP_CLI::line( '=== Deals Engine Status ===' );
				\WP_CLI\Utils\format_items( 'table', array_map(
					static fn( $k, $v ) => [ 'Metric' => ucfirst( $k ), 'Count' => $v ],
					array_keys( $stats ), array_values( $stats )
				), [ 'Metric', 'Count' ] );
				$max = Settings::get( 'deals_max_per_run', 2 );
				\WP_CLI::line( "Max per run: {$max}" );
				\WP_CLI::line( 'Autopilot: ' . ( Settings::is_enabled( 'deals_autopilot_enabled' ) ? 'enabled' : 'disabled' ) );
				break;

			case 'discover':
				\WP_CLI::log( 'Running deal discovery...' );
				\ContentEnginePro\Deals\DealDiscoverer::run();
				$stats = \ContentEnginePro\Deals\DealDiscoverer::get_stats();
				\WP_CLI::success( "Discovery complete. Pending queue: {$stats['pending']}" );
				break;

			case 'generate':
				$count = \WP_CLI\Utils\get_flag_value( $assoc_args, 'count', null );
				if ( $count !== null ) {
					$original = Settings::get( 'deals_max_per_run', 2 );
					$settings = get_option( 'cep_settings', [] );
					$settings['deals_max_per_run'] = max( 1, (int) $count );
					update_option( 'cep_settings', $settings );
				}
				\WP_CLI::log( 'Running deal article generation...' );
				\ContentEnginePro\Deals\DealAutopilot::run();
				if ( $count !== null ) {
					$settings = get_option( 'cep_settings', [] );
					$settings['deals_max_per_run'] = $original;
					update_option( 'cep_settings', $settings );
				}
				\WP_CLI::success( 'Generation complete.' );
				break;

			case 'monitor':
				\WP_CLI::log( 'Running deal monitor (expiry check)...' );
				\ContentEnginePro\Deals\DealMonitor::run();
				\WP_CLI::success( 'Monitor run complete.' );
				break;

			case 'seed-sources':
				\ContentEnginePro\Deals\DealDiscoverer::seed_default_sources();
				\WP_CLI::success( 'Default deal sources seeded.' );
				break;

			default:
				\WP_CLI::error( "Unknown subcommand '{$sub}'. Use: status | discover | generate | monitor | seed-sources" );
		}
	}

}
