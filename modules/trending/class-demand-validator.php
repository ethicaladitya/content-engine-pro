<?php
/**
 * Search Demand Validator
 *
 * Validates the search demand for a given keyword using the free
 * Google Autocomplete/Suggest API — no API key or account required.
 *
 * Scoring model (0-100):
 *   Autocomplete suggestions  5+  → 80 pts
 *   Autocomplete suggestions  3-4 → 60 pts
 *   Autocomplete suggestions  1-2 → 40 pts
 *   Autocomplete suggestions  0   → 10 pts
 *   Niche keyword match bonus      → +15 pts
 *   Google Trends source bonus     → +10 pts
 *   Score capped at 100.
 *
 * Results are cached per keyword for 6 hours via WordPress transients.
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
 * Validates search demand for trending keywords.
 */
class DemandValidator {

	/**
	 * Cache lifetime in seconds (6 hours).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	private const HTTP_TIMEOUT = 8;

	/**
	 * Validate a keyword's search demand and return a score from 0-100.
	 *
	 * @param string $keyword     The keyword or phrase to validate.
	 * @param string $source      Optional: source name (e.g. 'google_trends') for source bonuses.
	 * @return int Demand score 0-100.
	 */
	public static function validate( string $keyword, string $source = '' ): int {
		$keyword = sanitize_text_field( $keyword );

		if ( empty( $keyword ) ) {
			return 0;
		}

		$cache_key = 'cep_demand_' . md5( $keyword );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$suggestion_count = self::count_autocomplete_suggestions( $keyword );
		$score            = self::suggestions_to_score( $suggestion_count );

		// Source bonus: Google Trends topics are pre-validated as trending
		if ( 'google_trends' === $source ) {
			$score += 10;
		}

		// Niche relevance bonus
		$niche_keywords = Settings::get( 'niche_keywords', '' );
		if ( ! empty( $niche_keywords ) ) {
			$niche_list = array_map( 'trim', explode( ',', strtolower( $niche_keywords ) ) );
			$keyword_lc = strtolower( $keyword );
			foreach ( $niche_list as $niche_kw ) {
				if ( ! empty( $niche_kw ) && str_contains( $keyword_lc, $niche_kw ) ) {
					$score += 15;
					break;
				}
			}
		}

		$score = min( 100, max( 0, $score ) );

		set_transient( $cache_key, $score, self::CACHE_TTL );

		Logger::log( "Demand score for '{$keyword}': {$score} (suggestions: {$suggestion_count})", 'debug', 'trending' );

		return $score;
	}

	/**
	 * Validate multiple keywords and return scored results.
	 *
	 * @param array  $topics Array of topic items from TrendingDiscoverer.
	 * @return array[] Each item with 'demand_score' added.
	 */
	public static function validate_batch( array $topics ): array {
		foreach ( $topics as &$topic ) {
			$keyword             = $topic['keyword'] ?? $topic['topic'] ?? '';
			$source              = $topic['source'] ?? '';
			$topic['demand_score'] = self::validate( $keyword, $source );
			// Small pause to avoid hammering autocomplete endpoint
			usleep( 200000 ); // 0.2s
		}
		unset( $topic );

		return $topics;
	}

	// ── Internals ──────────────────────────────────────────────────────────────

	/**
	 * Count autocomplete suggestions for a keyword via Google Suggest API.
	 *
	 * @param string $keyword Keyword to look up.
	 * @return int Number of suggestions returned (0-10).
	 */
	private static function count_autocomplete_suggestions( string $keyword ): int {
		$url = add_query_arg( [
			'q'      => $keyword,
			'client' => 'firefox',
			'hl'     => 'en',
		], 'https://suggestqueries.google.com/complete/search' );

		$response = wp_remote_get( $url, [
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'Mozilla/5.0 (compatible; ContentEngineBot/1.0)',
			'headers'    => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Autocomplete request failed: ' . $response->get_error_message(), 'debug', 'trending' );
			return 0;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			return 0;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Google Suggest returns: ["query", ["suggestion1", "suggestion2", ...], ...]
		if ( ! is_array( $data ) || ! isset( $data[1] ) || ! is_array( $data[1] ) ) {
			return 0;
		}

		return count( $data[1] );
	}

	/**
	 * Convert a suggestion count to a base demand score.
	 *
	 * @param int $count Number of autocomplete suggestions.
	 * @return int Base score (10-80).
	 */
	private static function suggestions_to_score( int $count ): int {
		if ( $count >= 5 ) {
			return 80;
		}
		if ( $count >= 3 ) {
			return 60;
		}
		if ( $count >= 1 ) {
			return 40;
		}
		return 10;
	}
}
