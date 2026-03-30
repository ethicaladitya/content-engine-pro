<?php
/**
 * Trending Topics Autopilot
 *
 * Orchestrates the full sourceless content pipeline:
 *   1. Discover trending topics from configured sources
 *   2. Validate each topic's search demand score
 *   3. Fetch top news articles for approved topics via Google News RSS
 *   4. Queue approved topics into cep_raw_content for the article autopilot
 *   5. Mark topics in cep_trending_topics as processed
 *
 * The existing ArticleAutopilot::run() then picks up the queued items
 * exactly as it would with crawler-sourced content — no changes needed there.
 *
 * @package ContentEnginePro\Trending
 * @since   1.1.0
 */

namespace ContentEnginePro\Trending;

use ContentEnginePro\Logger;
use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trending Topics Autopilot — coordinates discovery, validation, and queuing.
 */
class TrendingAutopilot {

	/**
	 * HTTP timeout for news article fetches.
	 *
	 * @var int
	 */
	private const HTTP_TIMEOUT = 10;

	/**
	 * Run the full trending autopilot pipeline.
	 *
	 * Triggered by the 'cep_trending_autopilot' WP-Cron hook or manually
	 * from the admin Autopilot page.
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! Settings::is_enabled( 'trending_autopilot_enabled' ) ) {
			return;
		}

		Logger::log( 'Trending autopilot: starting run', 'info', 'trending' );

		do_action( 'cep_before_trending_autopilot' );

		$queued = self::discover_and_queue();

		Logger::log( "Trending autopilot: queued {$queued} new topics", 'info', 'trending' );

		do_action( 'cep_after_trending_autopilot', $queued );
	}

	/**
	 * Full pipeline: discover → validate → queue. Returns count of topics queued.
	 *
	 * @param bool $dry_run If true, returns topics without inserting to DB.
	 * @return int Number of topics successfully queued.
	 */
	public static function discover_and_queue( bool $dry_run = false ): int {
		$region      = sanitize_text_field( Settings::get( 'trending_region', 'US' ) );
		$max_queue   = (int) Settings::get( 'trending_max_per_run', 5 );
		$min_score   = (int) Settings::get( 'trending_min_demand_score', 50 );
		$sources_raw = Settings::get( 'trending_sources', 'google_trends,google_news' );
		$sources     = array_filter( array_map( 'trim', explode( ',', $sources_raw ) ) );

		// Build niche keywords for Google News queries.
		// get_active() already merges the admin niche_keywords override with preset defaults.
		$niche          = \ContentEnginePro\NicheManager::get_active();
		$niche_keywords = array_filter( array_map( 'trim', explode( ',', $niche['keywords'] ) ) );
		$niche_keywords = array_slice( $niche_keywords, 0, 5 ); // Top 5 keywords only

		// Build Reddit subreddits list
		$subreddits_raw = Settings::get( 'trending_subreddits', '' );
		$subreddits     = array_filter( array_map( 'trim', explode( ',', $subreddits_raw ) ) );

		// ── Step 1: Discover ──────────────────────────────────────────────────
		$discovered = [];

		foreach ( $sources as $source ) {
			$options = match ( $source ) {
				'google_trends' => [ 'region' => $region ],
				'google_news'   => [ 'keywords' => $niche_keywords ],
				'reddit'        => [ 'subreddits' => $subreddits ],
				default         => [],
			};

			if ( 'reddit' === $source && empty( $subreddits ) ) {
				continue; // Skip Reddit if no subreddits configured
			}
			if ( 'google_news' === $source && empty( $niche_keywords ) ) {
				continue; // Skip Google News if no keywords configured
			}

			$items      = TrendingDiscoverer::discover( $source, $options );
			$discovered = array_merge( $discovered, $items );
		}

		if ( empty( $discovered ) ) {
			Logger::log( 'Trending autopilot: no topics discovered', 'info', 'trending' );
			return 0;
		}

		// ── Step 2: Validate demand ───────────────────────────────────────────
		$validated = DemandValidator::validate_batch( $discovered );

		// Sort by demand_score descending, take the best candidates
		usort( $validated, static fn( $a, $b ) => ( $b['demand_score'] ?? 0 ) - ( $a['demand_score'] ?? 0 ) );
		$candidates = array_filter( $validated, static fn( $t ) => ( $t['demand_score'] ?? 0 ) >= $min_score );
		$candidates = array_slice( $candidates, 0, $max_queue * 3 ); // Buffer for dedup

		if ( empty( $candidates ) ) {
			Logger::log( "Trending autopilot: no topics above demand score {$min_score}", 'info', 'trending' );
			return 0;
		}

		// ── Step 3: Dedup & queue ─────────────────────────────────────────────
		$queued_count = 0;

		foreach ( $candidates as $topic ) {
			if ( $queued_count >= $max_queue ) {
				break;
			}

			if ( $dry_run ) {
				Logger::log( "[DRY RUN] Would queue: {$topic['topic']} (score: {$topic['demand_score']})", 'info', 'trending' );
				$queued_count++;
				continue;
			}

			$result = self::queue_topic( $topic, $region );
			if ( $result ) {
				$queued_count++;
			}
		}

		return $queued_count;
	}

	/**
	 * Queue a single topic into cep_trending_topics and cep_raw_content.
	 *
	 * @param array  $topic  Topic item from TrendingDiscoverer with demand_score set.
	 * @param string $region ISO region code.
	 * @return bool True if successfully queued, false if skipped/failed.
	 */
	private static function queue_topic( array $topic, string $region ): bool {
		global $wpdb;

		$trending_table = $wpdb->prefix . 'cep_trending_topics';
		$raw_table      = $wpdb->prefix . 'cep_raw_content';

		$topic_text   = sanitize_text_field( $topic['topic'] ?? '' );
		$keyword      = sanitize_text_field( $topic['keyword'] ?? $topic_text );
		$source       = sanitize_key( $topic['source'] ?? 'unknown' );
		$demand_score = (int) ( $topic['demand_score'] ?? 0 );

		if ( empty( $topic_text ) ) {
			return false;
		}

		// Check if this topic+source combo was already discovered
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$trending_table} WHERE topic = %s AND source = %s",
				$topic_text,
				$source
			)
		);

		if ( $existing ) {
			return false; // Already seen
		}

		// Fetch top news article URLs for this topic via Google News RSS
		$news_urls = self::fetch_news_urls_for_topic( $topic_text );

		if ( empty( $news_urls ) ) {
			Logger::log( "Trending: no news URLs found for topic '{$topic_text}'", 'info', 'trending' );
			// Still save the topic as processed, even without news
			$wpdb->insert(
				$trending_table,
				[
					'topic'        => $topic_text,
					'keyword'      => $keyword,
					'source'       => $source,
					'demand_score' => $demand_score,
					'region'       => $region,
					'status'       => 'no_news',
					'discovered_at' => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);
			return false;
		}

		$primary_url = $news_urls[0];

		// Dedup check: same canonical_url already in raw_content?
		$raw_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$raw_table} WHERE canonical_url = %s",
				$primary_url
			)
		);

		if ( $raw_exists ) {
			// Record topic but mark as duplicate
			$wpdb->insert(
				$trending_table,
				[
					'topic'        => $topic_text,
					'keyword'      => $keyword,
					'source'       => $source,
					'demand_score' => $demand_score,
					'region'       => $region,
					'status'       => 'duplicate',
					'discovered_at' => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
			);
			return false;
		}

		// Build content_hash for dedup in raw_content
		$content_hash = hash( 'sha256', $primary_url . '|' . $topic_text );

		$hash_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$raw_table} WHERE content_hash = %s",
				$content_hash
			)
		);

		if ( $hash_exists ) {
			return false;
		}

		// Insert into cep_raw_content so ArticleAutopilot picks it up
		$inserted = $wpdb->insert(
			$raw_table,
			[
				'source_id'     => 0, // 0 = no RSS source (trending)
				'content_hash'  => $content_hash,
				'title'         => $topic_text,
				'canonical_url' => $primary_url,
				'excerpt'       => sanitize_textarea_field( $topic['summary'] ?? '' ),
				'score'         => (float) $demand_score,
				'category'      => 'trending',
				'status'        => 'pending',
				'created_at'    => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			Logger::log( "Trending: failed to insert raw_content for '{$topic_text}'", 'error', 'trending' );
			return false;
		}

		$raw_content_id = (int) $wpdb->insert_id;

		// Record in trending topics table
		$wpdb->insert(
			$trending_table,
			[
				'topic'          => $topic_text,
				'keyword'        => $keyword,
				'source'         => $source,
				'demand_score'   => $demand_score,
				'region'         => $region,
				'raw_content_id' => $raw_content_id,
				'status'         => 'queued',
				'discovered_at'  => current_time( 'mysql' ),
				'processed_at'   => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ]
		);

		Logger::log( "Trending: queued topic '{$topic_text}' (score: {$demand_score}) → raw_content #{$raw_content_id}", 'info', 'trending' );

		return true;
	}

	/**
	 * Fetch the top news article URLs for a topic using Google News RSS.
	 *
	 * @param string $topic Topic/keyword to search for.
	 * @return string[] Array of article URLs (up to 3).
	 */
	private static function fetch_news_urls_for_topic( string $topic ): array {
		$cache_key = 'cep_trending_news_' . md5( $topic );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$url      = add_query_arg( [
			'q'    => $topic,
			'hl'   => 'en-US',
			'gl'   => 'US',
			'ceid' => 'US:en',
		], 'https://news.google.com/rss/search' );

		$response = wp_remote_get( $url, [
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'Mozilla/5.0 (compatible; ContentEngineBot/1.0)',
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			return [];
		}

		$urls  = [];
		$items = $xml->channel->item ?? [];

		foreach ( $items as $item ) {
			$link = esc_url_raw( (string) $item->link );
			if ( ! empty( $link ) ) {
				$urls[] = $link;
			}
			if ( count( $urls ) >= 3 ) {
				break;
			}
		}

		set_transient( $cache_key, $urls, HOUR_IN_SECONDS );

		return $urls;
	}

	// ── Stats helper ───────────────────────────────────────────────────────────

	/**
	 * Return counts from the trending topics table.
	 *
	 * @return array{total: int, pending: int, queued: int, duplicate: int}
	 */
	public static function get_stats(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'cep_trending_topics';

		// Check if table exists first (graceful handling before DB migration)
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [ 'total' => 0, 'pending' => 0, 'queued' => 0, 'duplicate' => 0 ];
		}

		return [
			'total'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ),
			'pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ),
			'queued'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'queued'" ),
			'duplicate' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'duplicate'" ),
		];
	}
}
