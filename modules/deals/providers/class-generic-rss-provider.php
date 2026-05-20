<?php
namespace ContentEnginePro\Deals\Providers;

use ContentEnginePro\Deals\ProviderInterface;
use ContentEnginePro\Settings;
use ContentEnginePro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic RSS deal feed provider.
 *
 * Handles any RSS/Atom feed that contains deal/price information.
 * Supports: Slickdeals, DealNews, RetailMeNot, Brad's Deals,
 * Dealnews, Woot, Kinja Deals, niche deal blogs, affiliate feeds.
 *
 * @since 1.3.0
 */
class GenericRssProvider extends ProviderInterface {

	public function get_id(): string {
		return 'generic_rss';
	}

	public function get_label(): string {
		return 'Generic RSS Deal Feed';
	}

	/**
	 * Fetch and parse deal items from an RSS feed.
	 *
	 * @param array $config Source config row.
	 * @return array[] Normalized deal arrays.
	 */
	public function fetch_deals( array $config ): array {
		$feed_url   = esc_url_raw( $config['feed_url'] ?? '' );
		$category   = $config['category'] ?? 'general';
		$region     = $config['region'] ?? Settings::get( 'deals_default_region', 'US' );
		$user_agent = Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' );

		if ( empty( $feed_url ) ) {
			return [];
		}

		$response = wp_remote_get( $feed_url, [
			'timeout'    => 30,
			'user-agent' => $user_agent,
			'headers'    => [
				'Accept'          => 'application/rss+xml, application/xml, text/xml, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
			],
		] );

		if ( is_wp_error( $response ) ) {
			Logger::log( "Deal RSS fetch failed for {$config['name']}: " . $response->get_error_message(), 'warning', 'deals' );
			return [];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			Logger::log( "Deal RSS HTTP {$code} for {$config['name']}", 'warning', 'deals' );
			return [];
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return [];
		}

		return $this->parse_rss( $body, $config, $category, $region, $feed_url );
	}

	/**
	 * Normalize a raw feed item.
	 *
	 * @param array $raw Raw parsed feed item.
	 * @return array Normalized deal array.
	 */
	public function normalize_deal( array $raw ): array {
		$title       = wp_strip_all_tags( $raw['title'] ?? '' );
		$description = wp_strip_all_tags( $raw['description'] ?? '' );
		$link        = esc_url_raw( $raw['link'] ?? '' );
		$image_url   = esc_url_raw( $raw['image_url'] ?? '' );
		$full_text   = $title . ' ' . $description;

		// Extract pricing
		$deal_price     = $this->extract_price_from_title( $title ) ?? $this->extract_price( $description );
		$original_price = $this->extract_original_price( $full_text );
		$discount_pct   = $this->extract_discount_pct( $full_text )
			?? $this->calc_discount_pct( $original_price, $deal_price );

		// Product name: strip price/coupon suffixes from title
		$product_name = $this->clean_product_name( $title );

		// Merchant detection
		$merchant_domain = $raw['merchant_domain'] ?? $this->extract_domain( $link );
		$merchant_name   = $raw['merchant_name'] ?? $this->guess_merchant_name( $merchant_domain );

		// Affiliate URL — use link directly; ClickTracker handles wrapping
		$affiliate_url = $link;

		return [
			'product_name'    => sanitize_text_field( $product_name ),
			'product_url'     => $link,
			'affiliate_url'   => $affiliate_url,
			'merchant_name'   => sanitize_text_field( $merchant_name ),
			'merchant_domain' => sanitize_text_field( $merchant_domain ),
			'original_price'  => $original_price,
			'deal_price'      => $deal_price,
			'discount_pct'    => $discount_pct,
			'currency'        => $raw['currency'] ?? 'USD',
			'coupon_code'     => $this->extract_coupon( $full_text ),
			'deal_type'       => $this->detect_deal_type( $full_text ),
			'category'        => sanitize_text_field( $raw['category'] ?? 'general' ),
			'region'          => sanitize_text_field( $raw['region'] ?? 'US' ),
			'image_url'       => $image_url,
			'description'     => sanitize_textarea_field( substr( $description, 0, 1000 ) ),
			'expires_at'      => $raw['expires_at'] ?? null,
			'source_feed'     => sanitize_url( $raw['source_feed'] ?? '' ),
			'source_type'     => 'rss',
		];
	}

	// ─── Private Parsing ──────────────────────────────────────────────────

	private function parse_rss( string $body, array $config, string $category, string $region, string $feed_url ): array {
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $body );
		libxml_use_internal_errors( $prev );

		if ( false === $xml ) {
			return [];
		}

		$items = [];
		$raws  = [];

		// RSS 2.0
		if ( isset( $xml->channel->item ) ) {
			foreach ( $xml->channel->item as $item ) {
				$ns    = $item->getNamespaces( true );
				$image = '';

				// media:thumbnail or media:content
				if ( isset( $ns['media'] ) ) {
					$media = $item->children( $ns['media'] );
					$image = (string) ( $media->thumbnail['url'] ?? $media->content['url'] ?? '' );
				}
				// enclosure
				if ( ! $image && isset( $item->enclosure ) ) {
					$type = (string) ( $item->enclosure['type'] ?? '' );
					if ( str_starts_with( $type, 'image/' ) ) {
						$image = (string) ( $item->enclosure['url'] ?? '' );
					}
				}

				// Pub date → expires (typically deals are valid 24-72hrs if undated)
				$pub_date = (string) ( $item->pubDate ?? '' );
				$expires  = $pub_date ? gmdate( 'Y-m-d H:i:s', strtotime( $pub_date ) + 3 * DAY_IN_SECONDS ) : null;

				$raws[] = [
					'title'          => (string) $item->title,
					'link'           => (string) $item->link,
					'description'    => (string) ( $item->description ?? '' ),
					'image_url'      => $image,
					'category'       => $category,
					'region'         => $region,
					'expires_at'     => $expires,
					'source_feed'    => $feed_url,
					'merchant_domain'=> '',
					'merchant_name'  => '',
					'currency'       => 'USD',
				];
			}
		} elseif ( isset( $xml->entry ) ) {
			// Atom
			foreach ( $xml->entry as $entry ) {
				$link = '';
				foreach ( $entry->link as $l ) {
					if ( 'alternate' === (string) $l['rel'] || '' === (string) $l['rel'] ) {
						$link = (string) $l['href'];
						break;
					}
				}
				$raws[] = [
					'title'          => (string) $entry->title,
					'link'           => $link,
					'description'    => strip_tags( (string) ( $entry->content ?? $entry->summary ?? '' ) ),
					'image_url'      => '',
					'category'       => $category,
					'region'         => $region,
					'expires_at'     => null,
					'source_feed'    => $feed_url,
					'merchant_domain'=> '',
					'merchant_name'  => '',
					'currency'       => 'USD',
				];
			}
		}

		foreach ( $raws as $raw ) {
			$normalized = $this->normalize_deal( $raw );
			if ( ! empty( $normalized['product_name'] ) && ! empty( $normalized['product_url'] ) ) {
				$items[] = $normalized;
			}
		}

		return $items;
	}

	/**
	 * Extract the deal/sale price specifically from a deal title.
	 * e.g. "Apple AirPods Pro for $189.99" → 189.99
	 */
	private function extract_price_from_title( string $title ): ?float {
		// Pattern: "for $X", "at $X", "only $X", "just $X"
		if ( preg_match( '/(?:for|at|only|just)\s+[\$£€]([\d,]+\.?\d*)/i', $title, $m ) ) {
			return (float) str_replace( ',', '', $m[1] );
		}
		// Pattern: "$X (was $Y)" — pick the first (deal price)
		if ( preg_match( '/[\$£€]([\d,]+\.?\d*)\s*\(?\s*was/i', $title, $m ) ) {
			return (float) str_replace( ',', '', $m[1] );
		}
		// Pattern: "down to $X"
		if ( preg_match( '/down\s+to\s+[\$£€]([\d,]+\.?\d*)/i', $title, $m ) ) {
			return (float) str_replace( ',', '', $m[1] );
		}
		return null;
	}

	/**
	 * Extract the original (was) price.
	 */
	private function extract_original_price( string $text ): ?float {
		// Pattern: "was $X", "reg $X", "MSRP $X", "retails for $X"
		if ( preg_match( '/(?:was|reg(?:ular)?|msrp|retail(?:s)?\s+for|originally)\s+[\$£€]([\d,]+\.?\d*)/i', $text, $m ) ) {
			return (float) str_replace( ',', '', $m[1] );
		}
		// Pattern: "$X $Y" where X>Y (struck-through price first in some feeds)
		if ( preg_match( '/[\$£€]([\d,]+\.?\d*)\s+[\$£€]([\d,]+\.?\d*)/i', $text, $m ) ) {
			$p1 = (float) str_replace( ',', '', $m[1] );
			$p2 = (float) str_replace( ',', '', $m[2] );
			if ( $p1 > $p2 ) {
				return $p1;
			}
		}
		return null;
	}

	/**
	 * Strip price/coupon/deal suffix noise from a title to get the product name.
	 */
	private function clean_product_name( string $title ): string {
		// Remove trailing "for $X", "at $X", "- $X off", "- XX% off" etc.
		$title = preg_replace( '/\s*[-–—|]\s*(?:for\s+)?[\$£€]\d[\d,.]*.*$/i', '', $title );
		$title = preg_replace( '/\s*[-–—|]\s*\d+%\s*off.*$/i', '', $title );
		$title = preg_replace( '/\s*[\(\[]\s*(?:deal|sale|coupon|discount|offer)[^\)\]]*[\)\]]/i', '', $title );
		// If it still looks like "X for $Y just use everything before the price
		$title = preg_replace( '/\s+for\s+[\$£€]\d.*$/i', '', $title );
		return trim( $title );
	}

	/**
	 * Guess a merchant display name from a domain.
	 */
	private function guess_merchant_name( string $domain ): string {
		if ( empty( $domain ) ) {
			return '';
		}
		// Strip TLD: "bestbuy.com" → "BestBuy"
		$name = preg_replace( '/\.(com|co\.uk|net|org|io|us|ca|in)$/i', '', $domain );
		// Title-case
		return ucwords( str_replace( [ '-', '_', '.' ], ' ', $name ) );
	}
}
