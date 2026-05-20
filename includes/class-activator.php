<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation: creates DB tables, sets defaults, flushes rewrites.
 */
class Activator {

	public static function activate(): void {
		self::create_tables();
		self::set_defaults();
		// Explicitly load CptManager (activation hook runs before init)
		require_once CEP_DIR . 'post-types/class-cpt-manager.php';
		CptManager::register_all();
		flush_rewrite_rules();
		update_option( 'cep_activated_at', time() );
		// Trigger first-run wizard redirect (expires in 60 seconds — enough for one page load)
		set_transient( 'cep_first_run_redirect', true, 60 );
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		$sql = [];

		// Logs
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			context VARCHAR(60) NOT NULL DEFAULT 'general',
			message TEXT NOT NULL,
			data LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_level_context (level, context),
			KEY idx_created (created_at)
		) {$charset};";

		// Sources
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_sources (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			homepage_url VARCHAR(500) NOT NULL DEFAULT '',
			feed_url VARCHAR(500) NOT NULL,
			source_type ENUM('rss','api','html') NOT NULL DEFAULT 'rss',
			category VARCHAR(60) NOT NULL DEFAULT 'general',
			crawl_window ENUM('morning','midday','evening','weekly') NOT NULL DEFAULT 'morning',
			tier TINYINT NOT NULL DEFAULT 1,
			reliability_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
			last_crawled_at DATETIME NULL,
			last_success_at DATETIME NULL,
			consecutive_fails INT NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			extra LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_feed_url (feed_url(191)),
			KEY idx_active_window (is_active, crawl_window),
			KEY idx_category (category)
		) {$charset};";

		// Raw content
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_raw_content (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL,
			content_hash CHAR(64) NOT NULL,
			title VARCHAR(500) NOT NULL DEFAULT '',
			canonical_url VARCHAR(500) NOT NULL DEFAULT '',
			raw_html LONGTEXT NULL,
			clean_text LONGTEXT NULL,
			excerpt TEXT NULL,
			score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			category VARCHAR(60) NOT NULL DEFAULT 'general',
			status ENUM('pending','processing','published','duplicate','rejected','below_threshold') NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_hash (content_hash),
			KEY idx_source (source_id),
			KEY idx_status (status),
			KEY idx_updated (updated_at)
		) {$charset};";

		// Reviews
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_reviews (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL,
			product_slug VARCHAR(200) NOT NULL,
			product_url VARCHAR(500) NOT NULL DEFAULT '',
			product_version VARCHAR(50) NOT NULL DEFAULT '',
			review_type VARCHAR(50) NOT NULL DEFAULT 'plugin',
			star_rating DECIMAL(3,1) NOT NULL DEFAULT 0.0,
			price_from VARCHAR(50) NOT NULL DEFAULT '',
			pros LONGTEXT NULL,
			cons LONGTEXT NULL,
			verdict TEXT NULL,
			affiliate_slug VARCHAR(200) NOT NULL DEFAULT '',
			ai_generated TINYINT(1) NOT NULL DEFAULT 0,
			auto_update TINYINT(1) NOT NULL DEFAULT 1,
			last_verified_at DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_product_slug (product_slug),
			KEY idx_wp_post (wp_post_id),
			KEY idx_type_rating (review_type, star_rating)
		) {$charset};";

		// Product discovery queue (for review autopilot)
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_product_discovery (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_name VARCHAR(255) NOT NULL,
			product_slug VARCHAR(200) NOT NULL,
			product_url VARCHAR(500) NOT NULL DEFAULT '',
			product_type VARCHAR(50) NOT NULL DEFAULT 'plugin',
			description TEXT NULL,
			source_url VARCHAR(500) NOT NULL DEFAULT '',
			active_installs INT NOT NULL DEFAULT 0,
			initial_rating DECIMAL(3,1) NOT NULL DEFAULT 0.0,
			wporg_slug VARCHAR(200) NOT NULL DEFAULT '',
			status ENUM('pending','processing','published','failed','skipped') NOT NULL DEFAULT 'pending',
			discovered_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_product_slug (product_slug),
			KEY idx_status (status),
			KEY idx_discovered (discovered_at)
		) {$charset};";

		// Jobs raw table (for deduplication + tracking)
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_jobs_raw (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(500) NOT NULL DEFAULT '',
			content_hash CHAR(32) NOT NULL,
			source_url VARCHAR(500) NOT NULL DEFAULT '',
			job_url VARCHAR(500) NOT NULL DEFAULT '',
			status ENUM('processing','published','failed') NOT NULL DEFAULT 'processing',
			discovered_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY idx_hash_date (content_hash, discovered_at),
			KEY idx_status (status)
		) {$charset};";

		// Click tracking
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_clicks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			provider_slug VARCHAR(200) NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			click_type VARCHAR(50) NOT NULL DEFAULT 'affiliate',
			user_agent VARCHAR(500) NOT NULL DEFAULT '',
			referer VARCHAR(500) NOT NULL DEFAULT '',
			clicked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_provider (provider_slug),
			KEY idx_post (post_id),
			KEY idx_clicked (clicked_at)
		) {$charset};";

		// Trending topics discovery queue
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_trending_topics (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			topic VARCHAR(500) NOT NULL,
			keyword VARCHAR(255) NOT NULL DEFAULT '',
			source VARCHAR(50) NOT NULL DEFAULT 'google_trends',
			demand_score TINYINT NOT NULL DEFAULT 0,
			region VARCHAR(10) NOT NULL DEFAULT 'US',
			raw_content_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			processed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_topic_source (topic(200), source),
			KEY idx_status (status),
			KEY idx_demand_score (demand_score)
		) {$charset};";

		// SEO Agent — issue tracker
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_seo_issues (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id       BIGINT UNSIGNED NOT NULL,
			post_type     VARCHAR(60) NOT NULL DEFAULT 'post',
			issue_type    VARCHAR(60) NOT NULL,
			severity      ENUM('critical','warning','info') NOT NULL DEFAULT 'warning',
			description   TEXT NOT NULL,
			auto_fixable  TINYINT(1) NOT NULL DEFAULT 0,
			status        ENUM('open','fixed','ignored') NOT NULL DEFAULT 'open',
			fix_applied   TEXT NULL,
			detected_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			fixed_at      DATETIME NULL,
			PRIMARY KEY (id),
			KEY idx_seo_post     (post_id),
			KEY idx_seo_status   (status),
			KEY idx_seo_type     (issue_type),
			KEY idx_seo_severity (severity)
		) {$charset};";

		// SEO Agent — run history
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_seo_runs (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			started_at      DATETIME NOT NULL,
			completed_at    DATETIME NULL,
			posts_scanned   INT NOT NULL DEFAULT 0,
			issues_found    INT NOT NULL DEFAULT 0,
			issues_fixed    INT NOT NULL DEFAULT 0,
			issues_ai_fixed INT NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY idx_seo_run_started (started_at)
		) {$charset};";

		// ── Deals Engine ─────────────────────────────────────────────────────

		// Deal feed sources
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_deal_sources (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name            VARCHAR(255) NOT NULL,
			feed_url        VARCHAR(500) NOT NULL,
			source_type     ENUM('rss','api','scrape') NOT NULL DEFAULT 'rss',
			provider        VARCHAR(60) NOT NULL DEFAULT 'generic_rss',
			category        VARCHAR(100) NOT NULL DEFAULT 'general',
			region          VARCHAR(10) NOT NULL DEFAULT 'US',
			api_config      LONGTEXT NULL,
			check_interval  INT NOT NULL DEFAULT 360,
			is_active       TINYINT(1) NOT NULL DEFAULT 1,
			last_checked_at DATETIME NULL,
			consecutive_fails INT NOT NULL DEFAULT 0,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY idx_feed_url (feed_url(191)),
			KEY idx_active_provider (is_active, provider)
		) {$charset};";

		// Deals queue + lifecycle registry
		$sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}cep_deals (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_post_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			deal_hash       CHAR(64) NOT NULL,
			product_name    VARCHAR(500) NOT NULL,
			product_url     VARCHAR(500) NOT NULL,
			merchant_name   VARCHAR(255) NOT NULL DEFAULT '',
			merchant_domain VARCHAR(255) NOT NULL DEFAULT '',
			affiliate_url   VARCHAR(1000) NOT NULL DEFAULT '',
			original_price  DECIMAL(10,2) DEFAULT NULL,
			deal_price      DECIMAL(10,2) DEFAULT NULL,
			discount_pct    TINYINT DEFAULT NULL,
			currency        CHAR(3) NOT NULL DEFAULT 'USD',
			coupon_code     VARCHAR(100) NOT NULL DEFAULT '',
			deal_type       ENUM('price_drop','coupon','bundle','clearance','seasonal','flash') NOT NULL DEFAULT 'price_drop',
			category        VARCHAR(100) NOT NULL DEFAULT 'general',
			region          VARCHAR(10) NOT NULL DEFAULT 'US',
			source_feed     VARCHAR(500) NOT NULL DEFAULT '',
			source_type     ENUM('rss','api','scrape','manual') NOT NULL DEFAULT 'rss',
			image_url       VARCHAR(500) NOT NULL DEFAULT '',
			expires_at      DATETIME DEFAULT NULL,
			is_active       TINYINT(1) NOT NULL DEFAULT 1,
			quality_score   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
			status          ENUM('pending','generating','published','expired','failed','duplicate') NOT NULL DEFAULT 'pending',
			price_history   LONGTEXT DEFAULT NULL,
			discovered_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			published_at    DATETIME DEFAULT NULL,
			last_checked_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_deal_hash (deal_hash),
			KEY idx_wp_post   (wp_post_id),
			KEY idx_status    (status),
			KEY idx_expires   (expires_at),
			KEY idx_cat_region (category, region)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		update_option( 'cep_db_version', CEP_DB_VERSION );
	}

	private static function set_defaults(): void {
		$current = get_option( 'cep_settings', [] );
		if ( empty( $current ) ) {
			update_option( 'cep_settings', Settings::defaults() );
		}
	}
}
