<?php
namespace ContentEnginePro\Reviews;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\NicheManager;
use ContentEnginePro\Research\WebResearcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review Discoverer — Stage 1 of the review autopilot.
 *
 * Discovers new products/services to review by:
 * 1. Parsing configured RSS feeds (WordPress.org, product directories, etc.)
 * 2. Extracting product details
 * 3. Queueing them in cep_product_discovery for the review generator
 *
 * Skips products that already have a published review.
 */
class ReviewDiscoverer {

	public static function run(): void {
		if ( ! Settings::is_enabled( 'review_autopilot_enabled' ) ) {
			return;
		}

		$niche         = NicheManager::get_active();
		$sources       = $niche['review_sources'];
		$max_per_run   = (int) Settings::get( 'review_discovery_max_per_run', 20 );

		if ( empty( $sources ) ) {
			Logger::log( 'Review discoverer: no sources configured', 'info', 'review_discoverer' );
			return;
		}

		do_action( 'cep_before_review_discovery' );

		$discovered = 0;
		foreach ( $sources as $feed_url ) {
			$items = self::parse_feed( $feed_url );
			foreach ( $items as $item ) {
				if ( $discovered >= $max_per_run ) {
					break 2;
				}
				if ( self::queue_product( $item ) ) {
					$discovered++;
				}
			}
		}

		Logger::log( "Review discoverer: queued {$discovered} new products", 'info', 'review_discoverer' );
		do_action( 'cep_after_review_discovery', $discovered );
	}

	/**
	 * Parse a product feed URL and extract product candidates.
	 */
	private static function parse_feed( string $url ): array {
		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );
		$response   = wp_remote_get( $url, [
			'timeout'    => 20,
			'user-agent' => $user_agent,
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Logger::log( "Review discovery feed failed: {$url}", 'warning', 'review_discoverer' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );

		// Try RSS/Atom first
		$items = self::parse_rss( $body, $url );

		// If feed is WordPress.org plugin API format
		if ( empty( $items ) && strpos( $url, 'wordpress.org/plugins' ) !== false ) {
			$items = self::parse_wporg_plugins( $url );
		}

		return $items;
	}

	private static function parse_rss( string $body, string $feed_url ): array {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		if ( false === $xml ) {
			return [];
		}

		$items        = [];
		$niche_types  = NicheManager::get_review_types();
		$default_type = $niche_types[0] ?? 'plugin';

		// RSS
		foreach ( $xml->channel->item ?? [] as $item ) {
			$name = html_entity_decode( (string) $item->title, ENT_QUOTES, 'UTF-8' );
			$url  = (string) $item->link;
			$desc = html_entity_decode( wp_strip_all_tags( (string) $item->description ), ENT_QUOTES, 'UTF-8' );

			if ( empty( $name ) || empty( $url ) ) {
				continue;
			}

			$type = self::guess_product_type( $name . ' ' . $desc, $niche_types, $default_type, $feed_url );

			$items[] = [
				'name'        => sanitize_text_field( $name ),
				'url'         => esc_url_raw( $url ),
				'description' => sanitize_textarea_field( substr( $desc, 0, 500 ) ),
				'type'        => $type,
				'source_url'  => $feed_url,
			];
		}

		// Atom
		if ( empty( $items ) ) {
			foreach ( $xml->entry ?? [] as $entry ) {
				$name = html_entity_decode( (string) $entry->title, ENT_QUOTES, 'UTF-8' );
				$url  = '';
				foreach ( $entry->link as $link ) {
					if ( in_array( (string) $link['rel'], [ 'alternate', '' ], true ) ) {
						$url = (string) $link['href'];
						break;
					}
				}
				if ( empty( $name ) || empty( $url ) ) {
					continue;
				}
				$type    = self::guess_product_type( $name, $niche_types, $default_type, $feed_url );
				$items[] = [
					'name'        => sanitize_text_field( $name ),
					'url'         => esc_url_raw( $url ),
					'description' => '',
					'type'        => $type,
					'source_url'  => $feed_url,
				];
			}
		}

		return $items;
	}

	/**
	 * WordPress.org Plugin API: fetch top/featured plugins.
	 */
	private static function parse_wporg_plugins( string $feed_url ): array {
		$api_url = 'https://api.wordpress.org/plugins/info/1.2/?action=query_plugins&request[per_page]=20&request[page]=1&request[browse]=popular';

		$response = wp_remote_get( $api_url, [
			'timeout' => 20,
			'user-agent' => Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' ),
		] );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['plugins'] ) ) {
			return [];
		}

		$items = [];
		foreach ( $data['plugins'] as $plugin ) {
			$items[] = [
				'name'           => $plugin['name'] ?? '',
				'url'            => 'https://wordpress.org/plugins/' . ( $plugin['slug'] ?? '' ) . '/',
				'description'    => wp_strip_all_tags( $plugin['short_description'] ?? '' ),
				'type'           => 'plugin',
				'source_url'     => $feed_url,
				'active_installs'=> $plugin['active_installs'] ?? 0,
				'rating'         => isset( $plugin['rating'] ) ? round( $plugin['rating'] / 20, 1 ) : 0,
				'wporg_slug'     => $plugin['slug'] ?? '',
			];
		}

		return $items;
	}

	/**
	 * Queue a discovered product for review.
	 * Returns true if newly queued, false if already known.
	 */
	private static function queue_product( array $product ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_product_discovery';
		$slug  = sanitize_title( $product['name'] );

		if ( empty( $slug ) ) {
			return false;
		}

		// Already in discovery table?
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE product_slug = %s", $slug ) );
		if ( $exists ) {
			return false;
		}

		// Already has a published review?
		$review_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
		$existing   = get_posts( [
			'post_type'   => $review_cpt,
			'name'        => $slug,
			'numberposts' => 1,
			'post_status' => 'any',
		] );
		if ( ! empty( $existing ) ) {
			return false;
		}

		// Check reviews DB table too
		$in_reviews = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}cep_reviews WHERE product_slug = %s", $slug
		) );
		if ( $in_reviews ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$table,
			[
				'product_name'    => sanitize_text_field( $product['name'] ),
				'product_slug'    => $slug,
				'product_url'     => esc_url_raw( $product['url'] ),
				'product_type'    => sanitize_key( $product['type'] ?? 'plugin' ),
				'description'     => sanitize_textarea_field( $product['description'] ?? '' ),
				'source_url'      => esc_url_raw( $product['source_url'] ?? '' ),
				'active_installs' => (int) ( $product['active_installs'] ?? 0 ),
				'initial_rating'  => (float) ( $product['rating'] ?? 0 ),
				'wporg_slug'      => sanitize_title( $product['wporg_slug'] ?? '' ),
				'status'          => 'pending',
				'discovered_at'   => current_time( 'mysql', true ),
			],
			[ '%s','%s','%s','%s','%s','%s','%d','%f','%s','%s','%s' ]
		);

		return (bool) $inserted;
	}

	/**
	 * Guess product type from content and feed context.
	 */
	private static function guess_product_type( string $text, array $types, string $default, string $feed_url ): string {
		$text = strtolower( $text );

		// Feed-based hints
		if ( strpos( $feed_url, 'themes' ) !== false ) {
			return 'theme';
		}
		if ( strpos( $feed_url, 'plugins' ) !== false || strpos( $feed_url, 'wordpress.org' ) !== false ) {
			return 'plugin';
		}
		if ( strpos( $feed_url, 'hosting' ) !== false ) {
			return 'hosting';
		}
		if ( strpos( $feed_url, 'allure.com' ) !== false || strpos( $feed_url, 'byrdie.com' ) !== false || strpos( $feed_url, 'intothegloss.com' ) !== false || strpos( $feed_url, 'glamour.com' ) !== false ) {
			return 'beauty';
		}
		if ( strpos( $feed_url, 'wellandgood.com' ) !== false || strpos( $feed_url, 'greatist.com' ) !== false ) {
			return 'wellness';
		}
		if ( strpos( $feed_url, 'dating-apps' ) !== false || strpos( $feed_url, 'dating_apps' ) !== false ) {
			return 'dating-app';
		}
		if ( strpos( $feed_url, 'refinery29.com' ) !== false || strpos( $feed_url, 'cosmopolitan.com' ) !== false ) {
			return 'skincare';
		}

		// Text-based hints
		$type_hints = [
			'plugin'     => [ 'plugin', 'addon', 'extension', 'wordpress plugin' ],
			'hosting'    => [ 'hosting', 'host', 'server', 'vps', 'cloud hosting', 'cpanel' ],
			'theme'      => [ 'theme', 'template', 'wordpress theme' ],
			'service'    => [ 'service', 'platform', 'saas', 'subscription' ],
			'tool'       => [ 'tool', 'software', 'app', 'utility' ],
			'beauty'     => [ 'foundation', 'lipstick', 'mascara', 'blush', 'eyeshadow', 'concealer', 'primer', 'bronzer', 'highlighter', 'makeup', 'cosmetic' ],
			'skincare'   => [ 'serum', 'moisturizer', 'sunscreen', 'cleanser', 'toner', 'retinol', 'vitamin c', 'face mask', 'eye cream', 'spf', 'skincare', 'skin care' ],
			'fragrance'  => [ 'perfume', 'fragrance', 'cologne', 'eau de parfum', 'scent' ],
			'haircare'   => [ 'shampoo', 'conditioner', 'hair mask', 'hair oil', 'haircare', 'hair care', 'hair serum', 'dry shampoo' ],
			'fashion'    => [ 'dress', 'jeans', 'sneakers', 'handbag', 'jacket', 'coat', 'boots', 'accessories', 'outfit', 'clothing', 'apparel', 'shoes' ],
			'wellness'   => [ 'supplement', 'vitamin', 'probiotic', 'collagen', 'protein powder', 'wellness', 'health', 'yoga mat', 'fitness' ],
			'self-care'  => [ 'self-care', 'self care', 'bath', 'body lotion', 'candle', 'spa', 'face roller', 'gua sha', 'sleep', 'meditation' ],
			'dating-app' => [ 'dating app', 'dating site', 'tinder', 'bumble', 'hinge', 'match', 'okcupid', 'online dating' ],
			'streaming'  => [ 'streaming', 'netflix', 'hulu', 'disney+', 'spotify', 'subscription box' ],
		];

		foreach ( $type_hints as $type => $hints ) {
			if ( ! in_array( $type, $types, true ) ) {
				continue;
			}
			foreach ( $hints as $hint ) {
				if ( strpos( $text, $hint ) !== false ) {
					return $type;
				}
			}
		}

		return in_array( $default, $types, true ) ? $default : ( $types[0] ?? 'plugin' );
	}
}
