<?php
namespace ContentEnginePro\Deals;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\Deals\Providers\GenericRssProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deal Discoverer — ingests deals from all configured deal sources.
 *
 * Pipeline:
 *   1. Load active deal sources from wp_cep_deal_sources
 *   2. Dispatch each source to the appropriate provider
 *   3. Score each deal via DealScorer
 *   4. Deduplicate against existing deals in wp_cep_deals
 *   5. Insert qualifying deals for content generation
 *
 * @since 1.3.0
 */
class DealDiscoverer {

	/** Registered feed providers (provider_id => ProviderInterface instance) */
	private static array $providers = [];

	/**
	 * Entry point — called by cron hook `cep_deals_discover`.
	 */
	public static function run(): void {
		if ( ! Settings::is_enabled( 'deals_enabled' ) ) {
			return;
		}

		global $wpdb;
		$sources_table = $wpdb->prefix . 'cep_deal_sources';

		// Guard: table might not exist yet if migration hasn't run
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sources_table ) );
		if ( ! $table_exists ) {
			Logger::log( 'Deal sources table not found — run plugin reactivation to migrate DB.', 'warning', 'deals' );
			return;
		}

		$sources = $wpdb->get_results(
			"SELECT * FROM {$sources_table} WHERE is_active = 1 AND consecutive_fails < 5 ORDER BY id ASC",
			ARRAY_A
		);

		if ( empty( $sources ) ) {
			// Auto-seed default deal sources on first run
			self::seed_default_sources();
			$sources = $wpdb->get_results(
				"SELECT * FROM {$sources_table} WHERE is_active = 1 ORDER BY id ASC",
				ARRAY_A
			);
		}

		if ( empty( $sources ) ) {
			Logger::log( 'Deal discoverer: no active deal sources configured.', 'info', 'deals' );
			return;
		}

		self::register_providers();

		$total_inserted = 0;

		foreach ( $sources as $source ) {
			$inserted = self::process_source( $source );
			$total_inserted += $inserted;

			// Update last_checked_at + reset/increment fails
			$wpdb->update(
				$sources_table,
				[
					'last_checked_at'  => current_time( 'mysql', true ),
					'consecutive_fails'=> $inserted < 0 ? $source['consecutive_fails'] + 1 : 0,
				],
				[ 'id' => $source['id'] ],
				[ '%s', '%d' ],
				[ '%d' ]
			);
		}

		Logger::log( "Deal discoverer: inserted {$total_inserted} new deals", 'info', 'deals' );
		do_action( 'cep_deals_discovered', $total_inserted );
	}

	/**
	 * Process a single source — fetch, score, deduplicate, insert.
	 * Returns count of inserted deals, or -1 on hard failure.
	 */
	private static function process_source( array $source ): int {
		global $wpdb;

		$provider_id = $source['provider'] ?? 'generic_rss';
		$provider    = self::$providers[ $provider_id ] ?? self::$providers['generic_rss'] ?? null;

		if ( ! $provider ) {
			Logger::log( "Deal discoverer: no provider for '{$provider_id}'", 'warning', 'deals' );
			return -1;
		}

		Logger::log( "Deal discoverer: fetching from {$source['name']}", 'info', 'deals' );

		$deals = $provider->fetch_deals( $source );

		if ( empty( $deals ) ) {
			Logger::log( "Deal discoverer: no deals from {$source['name']}", 'info', 'deals' );
			return 0;
		}

		$min_score = (float) Settings::get( 'deals_min_quality_score', 40 );
		$inserted  = 0;

		foreach ( $deals as $deal ) {
			$deal['source_feed'] = $source['feed_url'];
			$deal['source_type'] = $source['source_type'];
			$deal['region']      = $deal['region'] ?: ( $source['region'] ?? Settings::get( 'deals_default_region', 'US' ) );
			$deal['category']    = $deal['category'] ?: $source['category'];

			// Score the deal
			$score = DealScorer::score( $deal );
			if ( $score < $min_score ) {
				continue;
			}

			// Deduplication hash: product_url + merchant_domain
			$hash = hash( 'sha256', $deal['product_url'] . '|' . $deal['merchant_domain'] );

			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cep_deals WHERE deal_hash = %s", $hash )
			);
			if ( $exists ) {
				continue;
			}

			// Also deduplicate by product name similarity against recent deals
			if ( self::is_similar_deal_existing( $deal['product_name'] ) ) {
				continue;
			}

			$wpdb->insert(
				$wpdb->prefix . 'cep_deals',
				[
					'deal_hash'       => $hash,
					'product_name'    => substr( $deal['product_name'], 0, 500 ),
					'product_url'     => substr( $deal['product_url'], 0, 500 ),
					'merchant_name'   => substr( $deal['merchant_name'], 0, 255 ),
					'merchant_domain' => substr( $deal['merchant_domain'], 0, 255 ),
					'affiliate_url'   => substr( $deal['affiliate_url'] ?? $deal['product_url'], 0, 1000 ),
					'original_price'  => $deal['original_price'],
					'deal_price'      => $deal['deal_price'],
					'discount_pct'    => $deal['discount_pct'],
					'currency'        => $deal['currency'] ?? 'USD',
					'coupon_code'     => substr( $deal['coupon_code'] ?? '', 0, 100 ),
					'deal_type'       => $deal['deal_type'] ?? 'price_drop',
					'category'        => substr( $deal['category'], 0, 100 ),
					'region'          => substr( $deal['region'], 0, 10 ),
					'source_feed'     => substr( $deal['source_feed'], 0, 500 ),
					'source_type'     => $deal['source_type'] ?? 'rss',
					'image_url'       => substr( $deal['image_url'] ?? '', 0, 500 ),
					'expires_at'      => $deal['expires_at'] ?: null,
					'quality_score'   => $score,
					'status'          => 'pending',
					'discovered_at'   => current_time( 'mysql', true ),
					'price_history'   => wp_json_encode( [
						[ 'price' => $deal['deal_price'], 'date' => current_time( 'mysql', true ) ],
					] ),
				],
				[ '%s','%s','%s','%s','%s','%s','%f','%f','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%f','%s','%s','%s' ]
			);

			$inserted++;
		}

		Logger::log( "Deal discoverer: {$source['name']} → {$inserted} new deals inserted", 'info', 'deals' );

		return $inserted;
	}

	/**
	 * Check if a very similar deal product name already exists in recent deals.
	 * Prevents "Apple AirPods Pro Deal" and "Apple AirPods Pro Sale" being treated as different.
	 */
	private static function is_similar_deal_existing( string $product_name ): bool {
		global $wpdb;

		$words = array_filter(
			str_word_count( strtolower( $product_name ), 1 ),
			static fn( string $w ): bool => strlen( $w ) > 3
		);

		if ( empty( $words ) ) {
			return false;
		}

		// Check the last 7 days
		$recent = $wpdb->get_col(
			"SELECT product_name FROM {$wpdb->prefix}cep_deals
			 WHERE discovered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
		);

		foreach ( $recent as $existing_name ) {
			$existing_words = array_filter(
				str_word_count( strtolower( $existing_name ), 1 ),
				static fn( string $w ): bool => strlen( $w ) > 3
			);
			if ( empty( $existing_words ) ) {
				continue;
			}
			$matches    = count( array_intersect( $words, $existing_words ) );
			$similarity = $matches / max( count( $words ), count( $existing_words ) );
			if ( $similarity >= 0.75 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Register all available providers.
	 */
	private static function register_providers(): void {
		self::$providers['generic_rss'] = new Providers\GenericRssProvider();

		// Allow third-party providers
		do_action( 'cep_register_deal_providers', self::$providers );
	}

	/**
	 * Seed default deal RSS sources based on the active niche.
	 */
	public static function seed_default_sources(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_deal_sources';

		$niche  = \ContentEnginePro\NicheManager::get_active();
		$region = Settings::get( 'deals_default_region', 'US' );

		// Universal high-quality deal feeds
		$defaults = [
			[
				'name'       => 'Slickdeals Hot Deals',
				'feed_url'   => 'https://slickdeals.net/newsearch.php?mode=frontpage&searcharea=deals&searchin=first&rss=1',
				'provider'   => 'generic_rss',
				'category'   => 'general',
				'region'     => 'US',
			],
			[
				'name'       => 'DealNews Top Deals',
				'feed_url'   => 'https://www.dealnews.com/c202/Technology/?rss=1',
				'provider'   => 'generic_rss',
				'category'   => 'tech',
				'region'     => 'US',
			],
			[
				'name'       => 'Woot Deals',
				'feed_url'   => 'https://www.woot.com/feeds/all.rss',
				'provider'   => 'generic_rss',
				'category'   => 'general',
				'region'     => 'US',
			],
			[
				'name'       => "Brad's Deals",
				'feed_url'   => 'https://www.bradsdeals.com/blog/feed',
				'provider'   => 'generic_rss',
				'category'   => 'general',
				'region'     => 'US',
			],
			[
				'name'       => 'TechBargains',
				'feed_url'   => 'https://www.techbargains.com/rss.cfm',
				'provider'   => 'generic_rss',
				'category'   => 'tech',
				'region'     => 'US',
			],
		];

		// Niche-specific sources
		$deal_sources = apply_filters( 'cep_default_deal_sources', $defaults, $niche );

		foreach ( $deal_sources as $source ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE feed_url = %s", $source['feed_url'] )
			);
			if ( $exists ) {
				continue;
			}

			$wpdb->insert(
				$table,
				[
					'name'           => $source['name'],
					'feed_url'       => $source['feed_url'],
					'source_type'    => 'rss',
					'provider'       => $source['provider'] ?? 'generic_rss',
					'category'       => $source['category'] ?? 'general',
					'region'         => $source['region'] ?? $region,
					'check_interval' => 360,
					'is_active'      => 1,
					'created_at'     => current_time( 'mysql', true ),
				],
				[ '%s','%s','%s','%s','%s','%s','%d','%d','%s' ]
			);
		}

		Logger::log( 'Deal discoverer: seeded ' . count( $deal_sources ) . ' default sources', 'info', 'deals' );
	}

	/**
	 * Get queue statistics.
	 */
	public static function get_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_deals';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return [ 'total' => 0, 'pending' => 0, 'published' => 0, 'expired' => 0, 'failed' => 0 ];
		}

		$rows = $wpdb->get_results(
			"SELECT status, COUNT(*) as cnt FROM {$table} GROUP BY status",
			ARRAY_A
		);

		$stats = [ 'total' => 0, 'pending' => 0, 'generating' => 0, 'published' => 0, 'expired' => 0, 'failed' => 0, 'duplicate' => 0 ];
		foreach ( $rows as $row ) {
			$stats[ $row['status'] ] = (int) $row['cnt'];
			$stats['total']         += (int) $row['cnt'];
		}
		return $stats;
	}
}
