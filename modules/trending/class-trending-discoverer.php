<?php
/**
 * Trending Topics Discoverer
 *
 * Fetches trending topics and keywords from free public data sources:
 *  - Google Trends Daily RSS  (no API key required)
 *  - Google News RSS search   (no API key required)
 *  - Reddit Hot JSON API      (no API key required, public endpoint)
 *
 * Results are cached per source for 1 hour via WordPress transients.
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
 * Discovers trending topics from multiple free public data sources.
 */
class TrendingDiscoverer {

	/**
	 * Cache lifetime in seconds (1 hour).
	 *
	 * @var int
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private const HTTP_TIMEOUT = 12;

	/**
	 * Discover topics from the given source.
	 *
	 * @param string $source  One of 'google_trends', 'google_news', 'reddit'.
	 * @param array  $options Source-specific options:
	 *                        - region      (string) ISO country code, e.g. 'US'. Google Trends only.
	 *                        - keyword     (string) Search keyword. Google News only.
	 *                        - subreddits  (string[]) Subreddit names. Reddit only.
	 * @return array[] Array of topic items: [['topic'=>..., 'keyword'=>..., 'source'=>..., 'url'=>..., 'summary'=>...], ...]
	 */
	public static function discover( string $source, array $options = [] ): array {
		$source = sanitize_key( $source );

		switch ( $source ) {
			case 'google_trends':
				return self::from_google_trends( $options['region'] ?? 'US' );

			case 'google_news':
				$keywords = $options['keywords'] ?? [];
				$results  = [];
				foreach ( $keywords as $kw ) {
					$results = array_merge( $results, self::from_google_news( $kw ) );
				}
				return $results;

			case 'reddit':
				$subreddits = $options['subreddits'] ?? [];
				$results    = [];
				foreach ( $subreddits as $sub ) {
					$results = array_merge( $results, self::from_reddit( $sub ) );
				}
				return $results;

			default:
				Logger::log( "TrendingDiscoverer: unknown source '{$source}'", 'warning', 'trending' );
				return [];
		}
	}

	// ── Sources ────────────────────────────────────────────────────────────────

	/**
	 * Fetch daily trending searches from Google Trends RSS.
	 *
	 * @param string $region ISO country code (e.g. 'US', 'GB').
	 * @return array[]
	 */
	private static function from_google_trends( string $region = 'US' ): array {
		$region     = strtoupper( sanitize_text_field( $region ) );
		$cache_key  = 'cep_trends_gt_' . $region;
		$cached     = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$url      = 'https://trends.google.com/trends/trendingsearches/daily/rss?geo=' . rawurlencode( $region );
		$response = wp_remote_get( $url, [
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'Mozilla/5.0 (compatible; ContentEngineBot/1.0)',
		] );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Google Trends fetch failed: ' . $response->get_error_message(), 'warning', 'trending' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code || empty( $body ) ) {
			Logger::log( "Google Trends returned HTTP {$code}", 'warning', 'trending' );
			return [];
		}

		$topics = self::parse_rss( $body, 'google_trends' );

		set_transient( $cache_key, $topics, self::CACHE_TTL );

		return $topics;
	}

	/**
	 * Fetch top stories from Google News RSS for a given keyword.
	 *
	 * @param string $keyword The search keyword/phrase.
	 * @return array[]
	 */
	private static function from_google_news( string $keyword ): array {
		$keyword   = sanitize_text_field( $keyword );
		$cache_key = 'cep_trends_gn_' . md5( $keyword );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$url      = add_query_arg( [
			'q'    => $keyword,
			'hl'   => 'en-US',
			'gl'   => 'US',
			'ceid' => 'US:en',
		], 'https://news.google.com/rss/search' );

		$response = wp_remote_get( $url, [
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'Mozilla/5.0 (compatible; ContentEngineBot/1.0)',
		] );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Google News fetch failed: ' . $response->get_error_message(), 'warning', 'trending' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code || empty( $body ) ) {
			return [];
		}

		$topics = self::parse_rss( $body, 'google_news', $keyword );

		set_transient( $cache_key, $topics, self::CACHE_TTL );

		return $topics;
	}

	/**
	 * Fetch hot posts from a Reddit subreddit's public JSON API.
	 *
	 * @param string $subreddit Subreddit name (without r/ prefix).
	 * @return array[]
	 */
	private static function from_reddit( string $subreddit ): array {
		$subreddit = sanitize_text_field( $subreddit );
		$cache_key = 'cep_trends_rd_' . md5( $subreddit );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$url      = 'https://www.reddit.com/r/' . rawurlencode( $subreddit ) . '/hot.json?limit=20';
		$response = wp_remote_get( $url, [
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'ContentEngineBot/1.0 (contact@baetalk.com)',
		] );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Reddit fetch failed: ' . $response->get_error_message(), 'warning', 'trending' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== (int) $code || empty( $body ) ) {
			return [];
		}

		$data = json_decode( $body, true );

		if ( ! isset( $data['data']['children'] ) || ! is_array( $data['data']['children'] ) ) {
			return [];
		}

		$topics = [];
		foreach ( $data['data']['children'] as $child ) {
			$post = $child['data'] ?? [];

			// Skip pinned/stickied posts and low-upvote posts
			if ( ! empty( $post['stickied'] ) ) {
				continue;
			}

			$title = sanitize_text_field( $post['title'] ?? '' );
			$url   = esc_url_raw( $post['url'] ?? '' );

			if ( empty( $title ) || empty( $url ) ) {
				continue;
			}

			$topics[] = [
				'topic'   => $title,
				'keyword' => self::extract_keyword( $title ),
				'source'  => 'reddit',
				'url'     => $url,
				'summary' => sanitize_textarea_field( $post['selftext'] ?? '' ),
				'score'   => (int) ( $post['score'] ?? 0 ),
			];
		}

		// Sort by Reddit score descending
		usort( $topics, static fn( $a, $b ) => $b['score'] - $a['score'] );

		set_transient( $cache_key, $topics, self::CACHE_TTL );

		return $topics;
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Parse an RSS/Atom feed body into topic items.
	 *
	 * @param string $body   Raw RSS XML string.
	 * @param string $source Source identifier.
	 * @param string $keyword Optional keyword context (for google_news).
	 * @return array[]
	 */
	private static function parse_rss( string $body, string $source, string $keyword = '' ): array {
		// Suppress XML parse warnings on malformed feeds
		$prev_errors = libxml_use_internal_errors( true );
		$xml         = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev_errors );

		if ( false === $xml ) {
			Logger::log( "RSS parse failed for source: {$source}", 'warning', 'trending' );
			return [];
		}

		$topics = [];
		$items  = $xml->channel->item ?? [];

		foreach ( $items as $item ) {
			$title   = sanitize_text_field( (string) $item->title );
			$link    = esc_url_raw( (string) $item->link );
			$desc    = sanitize_textarea_field( (string) $item->description );

			if ( empty( $title ) ) {
				continue;
			}

			$topics[] = [
				'topic'   => $title,
				'keyword' => $keyword ?: self::extract_keyword( $title ),
				'source'  => $source,
				'url'     => $link,
				'summary' => wp_trim_words( $desc, 30 ),
			];
		}

		return array_slice( $topics, 0, 20 ); // Cap at 20 per source per fetch
	}

	/**
	 * Extract a concise keyword from a longer topic title.
	 *
	 * Strips stop words and returns the first 4-6 significant words.
	 *
	 * @param string $title Full topic title.
	 * @return string Keyword phrase.
	 */
	private static function extract_keyword( string $title ): string {
		static $stop_words = [
			'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
			'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been',
			'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
			'should', 'may', 'might', 'can', 'it', 'its', 'this', 'that', 'these',
			'those', 'what', 'which', 'who', 'how', 'why', 'when', 'where',
		];

		$words    = preg_split( '/\s+/', strtolower( strip_tags( $title ) ) );
		$keywords = [];

		foreach ( (array) $words as $word ) {
			$word = preg_replace( '/[^a-z0-9\'-]/', '', (string) $word );
			if ( strlen( $word ) >= 3 && ! in_array( $word, $stop_words, true ) ) {
				$keywords[] = $word;
			}
			if ( count( $keywords ) >= 5 ) {
				break;
			}
		}

		return implode( ' ', $keywords );
	}
}
