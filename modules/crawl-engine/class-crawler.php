<?php
namespace ContentEnginePro\Crawl;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\Signal\SignalScorer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crawls configured RSS/HTML sources and stores raw content.
 */
class Crawler {

	public static function run_window( string $window = '' ): void {
		if ( ! Settings::is_enabled( 'enable_crawling' ) ) {
			return;
		}

		global $wpdb;
		$sources_table = $wpdb->prefix . 'cep_sources';
		$content_table = $wpdb->prefix . 'cep_raw_content';

		$where_window = $window ? $wpdb->prepare( 'AND crawl_window = %s', $window ) : '';

		$sources = $wpdb->get_results(
			"SELECT * FROM {$sources_table} WHERE is_active = 1 {$where_window} AND consecutive_fails < 5 ORDER BY tier ASC, reliability_score DESC",
			ARRAY_A
		);

		if ( empty( $sources ) ) {
			return;
		}

		// Default to 10 so all sources across the three daily windows get covered.
		// Prioritise never-crawled sources first, then least-recently-crawled.
		usort( $sources, static function ( array $a, array $b ): int {
			// NULL last_crawled_at (never crawled) always goes first.
			if ( empty( $a['last_crawled_at'] ) && ! empty( $b['last_crawled_at'] ) ) {
				return -1;
			}
			if ( ! empty( $a['last_crawled_at'] ) && empty( $b['last_crawled_at'] ) ) {
				return 1;
			}
			return strcmp( (string) $a['last_crawled_at'], (string) $b['last_crawled_at'] );
		} );

		$max_jobs = (int) Settings::get( 'max_concurrent_jobs', 10 );
		$batch    = array_slice( $sources, 0, $max_jobs );

		foreach ( $batch as $source ) {
			self::crawl_source( $source, $content_table, $sources_table );
		}

		do_action( 'cep_after_crawl_window', $window );
	}

	private static function crawl_source( array $source, string $content_table, string $sources_table ): void {
		global $wpdb;

		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );

		Logger::log( "Crawling source: {$source['name']}", 'info', 'crawler', [ 'id' => $source['id'] ] );

		$response = wp_remote_get( $source['feed_url'], [
			'timeout'    => 30,
			'user-agent' => $user_agent,
		] );

		if ( is_wp_error( $response ) ) {
			$wpdb->update( $sources_table, [
				'consecutive_fails' => $source['consecutive_fails'] + 1,
			], [ 'id' => $source['id'] ], [ '%d' ], [ '%d' ] );
			Logger::log( "Crawl failed for {$source['name']}: " . $response->get_error_message(), 'warning', 'crawler' );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			Logger::log( "Crawl HTTP {$code} for {$source['name']}", 'warning', 'crawler' );
			return;
		}

		$body  = wp_remote_retrieve_body( $response );
		$items = self::parse_feed( $body, $source['source_type'] );
		$saved = 0;

		foreach ( $items as $item ) {
			$hash = hash( 'sha256', $item['url'] . $item['title'] );

			// Deduplication check: same URL+title hash already in queue
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$content_table} WHERE content_hash = %s", $hash ) );
			if ( $exists ) {
				continue;
			}

			// Deduplication check: same canonical URL in any state (including already published to WP)
			$url_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$content_table} WHERE canonical_url = %s", esc_url_raw( $item['url'] ) ) );
			if ( $url_exists ) {
				continue;
			}

			// Score the content.
			$score = SignalScorer::score( $item, $source['category'] );

			// Source-quality boost: high-tier, reliable sources earn an uplift so their
			// content reaches the threshold even when RSS excerpts are short (< 200 chars).
			// Tier 1 (flagship sources) → +35 pts. Tier 2 → +20 pts. Tier 3+ → +5 pts.
			$tier_bonus        = match ( (int) ( $source['tier'] ?? 3 ) ) {
				1       => 35,
				2       => 20,
				default => 5,
			};
			$reliability_bonus = ( (int) ( $source['reliability_score'] ?? 0 ) ) >= 70 ? 5 : 0;
			$score             = min( 100.0, $score + $tier_bonus + $reliability_bonus );

			$threshold = self::get_threshold( $source['category'] );
			$status    = $score >= $threshold ? 'pending' : 'below_threshold';

			$wpdb->insert(
				$content_table,
				[
					'source_id'     => $source['id'],
					'content_hash'  => $hash,
					'title'         => substr( sanitize_text_field( $item['title'] ), 0, 500 ),
					'canonical_url' => esc_url_raw( $item['url'] ),
					'clean_text'    => wp_strip_all_tags( $item['content'] ?? '' ),
					'excerpt'       => substr( wp_strip_all_tags( $item['excerpt'] ?? $item['content'] ?? '' ), 0, 500 ),
					'score'         => $score,
					'category'      => $source['category'],
					'status'        => $status,
					'created_at'    => current_time( 'mysql', true ),
				],
				[ '%d','%s','%s','%s','%s','%s','%f','%s','%s','%s' ]
			);

			$saved++;
		}

		// Update source stats
		$wpdb->update(
			$sources_table,
			[
				'last_crawled_at'   => current_time( 'mysql', true ),
				'last_success_at'   => current_time( 'mysql', true ),
				'consecutive_fails' => 0,
				'reliability_score' => min( 100, $source['reliability_score'] + 1 ),
			],
			[ 'id' => $source['id'] ],
			[ '%s','%s','%d','%f' ],
			[ '%d' ]
		);

		Logger::log( "Crawled {$source['name']}: {$saved} new items", 'info', 'crawler' );
		do_action( 'cep_source_crawled', $source, $saved );
	}

	private static function parse_feed( string $body, string $type ): array {
		$items = [];

		if ( 'html' === $type ) {
			return self::parse_html_feed( $body );
		}

		// RSS/Atom
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		if ( false === $xml ) {
			return [];
		}

		// Detect Atom vs RSS
		if ( isset( $xml->entry ) ) {
			// Atom
			foreach ( $xml->entry as $entry ) {
				$url = '';
				foreach ( $entry->link as $link ) {
					if ( 'alternate' === (string) $link['rel'] || '' === (string) $link['rel'] ) {
						$url = (string) $link['href'];
						break;
					}
				}
				$items[] = [
					'title'   => (string) $entry->title,
					'url'     => $url,
					'content' => strip_tags( (string) ( $entry->content ?? $entry->summary ?? '' ) ),
					'excerpt' => strip_tags( (string) ( $entry->summary ?? '' ) ),
				];
			}
		} else {
			// RSS
			foreach ( $xml->channel->item ?? [] as $item ) {
				$ns      = $item->getNamespaces( true );
				$content = '';
				if ( isset( $ns['content'] ) ) {
					$content_ns = $item->children( $ns['content'] );
					$content    = strip_tags( (string) ( $content_ns->encoded ?? '' ) );
				}
				$items[] = [
					'title'   => (string) $item->title,
					'url'     => (string) $item->link,
					'content' => $content ?: strip_tags( (string) $item->description ),
					'excerpt' => strip_tags( (string) $item->description ),
				];
			}
		}

		return $items;
	}

	private static function parse_html_feed( string $body ): array {
		// Basic HTML link extraction (for non-RSS sources)
		$items = [];
		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>([^<]+)<\/a>/i', $body, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			if ( filter_var( $match[1], FILTER_VALIDATE_URL ) ) {
				$items[] = [
					'title'   => strip_tags( $match[2] ),
					'url'     => $match[1],
					'content' => '',
					'excerpt' => '',
				];
			}
		}
		return $items;
	}

	private static function get_threshold( string $category ): float {
		if ( 'security' === strtolower( $category ) ) {
			return (float) Settings::get( 'signal_threshold_security', 40 );
		}
		return (float) Settings::get( 'signal_threshold', 50 );
	}
}
