<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized settings wrapper for Content Engine Pro.
 *
 * All plugin behavior is driven through this class.
 * Developers can filter any individual setting or all settings.
 *
 * Usage:
 *   Settings::get('brand_name')
 *   Settings::get('primary_cpt_slug', 'article')
 */
class Settings {

	private static array $cache   = [];
	private static ?array $loaded = null;

	/**
	 * Default values for all settings.
	 */
	private static array $defaults = [
		// ── General ─────────────────────────────────────────────────────────
		'brand_name'                   => 'My Publication',
		'brand_tagline'                => 'News & Reviews',
		'contact_email'                => '',
		'site_url'                     => '',

		// ── Content ─────────────────────────────────────────────────────────
		// Primary content — defaults to built-in 'post'. Set to a custom slug to register a new CPT.
		// If 'post', no new CPT is registered; taxonomies attach to the built-in posts type.
		'primary_cpt_slug'             => 'post',
		'primary_cpt_singular'         => 'Post',
		'primary_cpt_plural'           => 'Posts',
		'primary_cpt_archive_slug'     => 'news',
		'primary_cpt_icon'             => 'dashicons-media-document',

		// Primary Taxonomy — uses WordPress built-in 'category' taxonomy.
		'primary_tax_slug'             => 'category',
		'primary_tax_singular'         => 'Category',
		'primary_tax_plural'           => 'Categories',
		'primary_tax_archive_slug'     => 'news',
		'primary_tax_terms'            => '',

		// Secondary Taxonomy — uses WordPress built-in 'post_tag' taxonomy.
		'secondary_tax_slug'           => 'post_tag',
		'secondary_tax_singular'       => 'Tag',
		'secondary_tax_plural'         => 'Tags',
		'secondary_tax_archive_slug'   => 'topic',

		// Reviews CPT
		'reviews_cpt_enabled'          => '1',
		'reviews_cpt_slug'             => 'review',
		'reviews_cpt_singular'         => 'Review',
		'reviews_cpt_plural'           => 'Reviews',
		'reviews_cpt_archive_slug'     => 'reviews',
		'reviews_tax_slug'             => 'review-type',
		'reviews_tax_terms'            => 'plugin,hosting,theme,service,tool',

		// Jobs CPT
		'jobs_cpt_enabled'             => '1',
		'jobs_cpt_slug'                => 'job',
		'jobs_cpt_singular'            => 'Job',
		'jobs_cpt_plural'              => 'Jobs',
		'jobs_cpt_archive_slug'        => 'jobs',

		// Providers CPT (affiliate providers)
		'providers_cpt_enabled'        => '1',
		'providers_cpt_slug'           => 'provider',
		'providers_cpt_singular'       => 'Provider',
		'providers_cpt_plural'         => 'Providers',

		// ── Display / UI ─────────────────────────────────────────────────────
		'posts_per_page'               => '12',
		'excerpt_length'               => '30',
		'show_author'                  => '1',
		'show_date'                    => '1',
		'show_category'                => '1',
		'show_read_time'               => '1',
		'card_layout'                  => 'grid',
		'sidebar_position'             => 'right',
		'hero_image_width'             => '1200',
		'hero_image_height'            => '675',
		'card_image_width'             => '600',
		'card_image_height'            => '338',
		'thumb_image_width'            => '160',
		'thumb_image_height'           => '120',

		// ── AI & Integrations ────────────────────────────────────────────────
		'ai_provider'                  => 'openai',
		'ai_model'                     => 'gpt-4o',
		'ai_model_mini'                => 'gpt-4o-mini',
		'openai_key'                   => '',
		'azure_key'                    => '',
		'azure_endpoint'               => '',
		'azure_api_version'            => '2024-08-01-preview',
		'azure_deployment'             => '',
		'azure_deployment_mini'        => '',
		'pexels_key'                   => '',
		'ai_temperature'               => '0.7',
		'ai_system_prompt'             => '',
		'ai_timeout'                   => '90',
		'ai_retries'                   => '3',

		// ── Feature Toggles ──────────────────────────────────────────────────
		'enable_crawling'              => '1',
		'enable_ai_publishing'         => '1',
		'enable_reviews'               => '1',
		'enable_reviews_autopilot'     => '1',
		'enable_affiliates'            => '1',
		'enable_affiliate_links'       => '1',
		'enable_click_tracking'        => '1',
		'enable_jobs'                  => '1',
		'enable_schema_markup'         => '1',
		'enable_internal_linking'      => '1',
		'enable_rest_api'              => '1',
		'enable_source_stripping'      => '1',
		'enable_seo_meta'              => '1',
		'enable_image_fetching'        => '1',

		// ── Advanced / Crawl ─────────────────────────────────────────────────
		'signal_threshold'             => '50',
		'signal_threshold_security'    => '40',
		'max_concurrent_jobs'          => '3',
		'auto_publish'                 => '1',
		'default_author_id'            => '1',
		'log_retention_days'           => '90',
		'crawl_user_agent'             => 'Content Engine Pro/1.0',
		'wp_path'                      => '',

		// Reviews engine
		'reviews_max_per_run'          => '3',
		'reviews_ai_temperature'       => '0.6',

		// Affiliate
		'affiliate_disclosure_text'    => 'This post may contain affiliate links. We may earn a commission if you click and make a purchase — at no extra cost to you.',
		'affiliate_redirect_base'      => 'go',
		'affiliate_encrypt_key'        => '',

		// Comparison shortcode
		'compare_default_title'        => 'Top Picks Compared',

		// ── Niche / Vertical ─────────────────────────────────────────────────
		'niche_vertical'               => 'wordpress',
		'niche_name'                   => '',        // override niche label
		'niche_keywords'               => '',        // override niche keywords
		'niche_review_types'           => '',        // override review type list
		'niche_article_categories'     => '',        // override article categories
		'niche_search_queries'         => '',        // extra search queries (newline-separated)
		'review_discovery_sources'     => '',        // extra review RSS feeds (newline-separated)
		'job_sources'                  => '',        // extra job RSS feeds (newline-separated)
		'article_sources'              => '',        // extra article RSS feeds (newline-separated)

		// ── Article Autopilot ────────────────────────────────────────────────
		'article_autopilot_enabled'    => '1',
		'article_max_per_run'          => '3',
		'article_research_depth'       => '3',       // max URLs to research per article

		// ── Review Autopilot ─────────────────────────────────────────────────
		'review_autopilot_enabled'     => '1',
		'review_discovery_max_per_run' => '20',      // products to discover per run
		'review_max_per_run'           => '3',       // reviews to generate per run
		'review_research_depth'        => '2',       // URLs to research per review

		// ── Jobs Autopilot ───────────────────────────────────────────────────
		'jobs_autopilot_enabled'       => '1',
		'jobs_max_per_run'             => '10',
		'jobs_dedup_days'              => '30',      // days to consider a job "duplicate"

		// ── Web Research ─────────────────────────────────────────────────────
		'research_timeout'             => '20',
		'research_max_text_chars'      => '8000',    // max chars extracted per page
		// ── Built-in Theme ──────────────────────────────────────────────────────
		'content_engine_use_builtin_theme' => '0',
		'content_engine_use_homepage'      => '0',
		// ── Trending Topics Autopilot ─────────────────────────────────────────
		'trending_autopilot_enabled'   => '0',
		'trending_sources'             => 'google_trends,google_news',
		'trending_region'              => 'US',
		'trending_subreddits'          => '',
		'trending_max_per_run'         => '5',
		'trending_min_demand_score'    => '50',

		// ── SEO Agent ─────────────────────────────────────────────────────────
		'enable_seo_agent'             => '1',
		'seo_agent_interval'           => '3',   // days between full scans
		'seo_min_word_count'           => '300',
		'seo_title_min_length'         => '30',
		'seo_title_max_length'         => '60',
		'seo_meta_desc_min_length'     => '100',
		'seo_meta_desc_max_length'     => '160',
		'seo_slug_max_length'          => '75',
		'seo_auto_fix_enabled'         => '1',
	];

	/**
	 * Get a setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Override default (optional).
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		if ( array_key_exists( $key, self::$cache ) ) {
			return self::$cache[ $key ];
		}

		// Lazy-load all options once
		if ( null === self::$loaded ) {
			self::$loaded = self::stored();
		}

		$defaults = self::defaults();
		$fallback = null !== $default ? $default : ( $defaults[ $key ] ?? null );
		$value    = self::$loaded[ $key ] ?? $fallback;

		// Allow developers to override any setting
		$value = apply_filters( 'cep_setting_' . $key, $value );
		$value = apply_filters( 'cep_setting', $value, $key );

		self::$cache[ $key ] = $value;

		return $value;
	}

	/**
	 * Get all settings (merged with defaults).
	 */
	public static function all(): array {
		$stored   = self::stored();
		$defaults = self::defaults();

		return array_merge( $defaults, $stored );
	}

	/**
	 * Get default values (filterable).
	 */
	public static function defaults(): array {
		return apply_filters( 'cep_setting_defaults', self::$defaults );
	}

	/**
	 * Update a single setting value.
	 */
	public static function update( string $key, $value ): void {
		$stored         = self::stored();
		$stored[ $key ] = $value;
		update_option( 'cep_settings', self::clean( $stored ) );
		self::clear_cache();
	}

	/**
	 * Save multiple settings at once.
	 */
	public static function save( array $data ): void {
		$stored  = self::stored();
		$updated = array_merge( $stored, $data );
		update_option( 'cep_settings', self::clean( $updated ) );
		self::clear_cache();
	}

	/**
	 * Read the stored settings array.
	 *
	 * If the serialized row is damaged (string byte lengths no longer match,
	 * e.g. after the database stripped invalid UTF-8), get_option() returns
	 * false and every setting silently falls back to its default. Recover the
	 * values in memory instead; the next save rewrites a clean row.
	 */
	private static function stored(): array {
		$stored = get_option( 'cep_settings', [] );
		if ( is_array( $stored ) ) {
			return $stored;
		}

		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'cep_settings' ) );
		if ( ! is_string( $raw ) || '' === $raw || ! is_serialized( $raw ) ) {
			return [];
		}

		$repaired = preg_replace_callback(
			'/s:(\d+):"(.*?)";(?=s:|i:|b:|d:|a:|N;|\})/s',
			static fn( $m ) => 's:' . strlen( $m[2] ) . ':"' . $m[2] . '";',
			$raw
		);
		$data = is_string( $repaired ) ? @unserialize( $repaired, [ 'allowed_classes' => false ] ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions

		return is_array( $data ) ? $data : [];
	}

	/**
	 * Drop invalid UTF-8 bytes before saving, so the database cannot alter
	 * string lengths inside the serialized row.
	 *
	 * @param mixed $value Setting value (scalar or nested array).
	 * @return mixed
	 */
	private static function clean( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'clean' ], $value );
		}
		if ( is_string( $value ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			return (string) iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
		}
		return $value;
	}

	/**
	 * Clear the in-memory cache (e.g., after saving settings).
	 */
	public static function clear_cache(): void {
		self::$cache  = [];
		self::$loaded = null;
	}

	/**
	 * Check if a boolean-ish feature setting is enabled.
	 */
	public static function is_enabled( string $key ): bool {
		return (bool) filter_var( self::get( $key ), FILTER_VALIDATE_BOOLEAN );
	}
}
