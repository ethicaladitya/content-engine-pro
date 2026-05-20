<?php
namespace ContentEnginePro\Deals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for all deal feed providers.
 *
 * Extend this to add new deal sources: CJ Affiliate, ShareASale,
 * Impact, Awin, custom APIs, etc.
 *
 * @since 1.3.0
 */
abstract class ProviderInterface {

	/**
	 * Unique provider identifier (e.g. 'generic_rss', 'cj', 'shareasale').
	 */
	abstract public function get_id(): string;

	/**
	 * Human-readable provider name.
	 */
	abstract public function get_label(): string;

	/**
	 * Fetch raw deal items from the source.
	 *
	 * @param array $config Source config row from cep_deal_sources.
	 * @return array[] Array of normalized deal arrays.
	 */
	abstract public function fetch_deals( array $config ): array;

	/**
	 * Normalize a raw feed item into the standard deal array shape.
	 *
	 * Standard deal array shape:
	 * [
	 *   'product_name'   => string,
	 *   'product_url'    => string (canonical product URL),
	 *   'affiliate_url'  => string (tracked link, if available),
	 *   'merchant_name'  => string,
	 *   'merchant_domain'=> string,
	 *   'original_price' => float|null,
	 *   'deal_price'     => float|null,
	 *   'discount_pct'   => int|null,
	 *   'currency'       => string (ISO 4217),
	 *   'coupon_code'    => string,
	 *   'deal_type'      => string (price_drop|coupon|bundle|clearance|seasonal|flash),
	 *   'category'       => string,
	 *   'region'         => string (ISO 3166-1 alpha-2),
	 *   'image_url'      => string,
	 *   'description'    => string,
	 *   'expires_at'     => string|null (MySQL datetime),
	 *   'source_feed'    => string (feed_url),
	 *   'source_type'    => string,
	 * ]
	 *
	 * @param array $raw Raw item from the feed.
	 * @return array Normalized deal array.
	 */
	abstract public function normalize_deal( array $raw ): array;

	// ─── Shared Helpers ───────────────────────────────────────────────────

	/**
	 * Extract a price (float) from a string like "$49.99", "49.99", "£29".
	 */
	protected function extract_price( string $text ): ?float {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		if ( preg_match( '/[\$£€]?\s*([\d,]+\.?\d*)/', $text, $m ) ) {
			return (float) str_replace( ',', '', $m[1] );
		}
		return null;
	}

	/**
	 * Extract a discount percentage from strings like "50% off", "Save 30%".
	 */
	protected function extract_discount_pct( string $text ): ?int {
		if ( preg_match( '/(\d+)\s*%\s*off/i', $text, $m ) ) {
			return (int) $m[1];
		}
		if ( preg_match( '/save\s+(\d+)\s*%/i', $text, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Calculate discount percentage from original and deal prices.
	 */
	protected function calc_discount_pct( ?float $original, ?float $deal ): ?int {
		if ( ! $original || ! $deal || $original <= 0 || $deal >= $original ) {
			return null;
		}
		return (int) round( ( ( $original - $deal ) / $original ) * 100 );
	}

	/**
	 * Extract a coupon code from text (common patterns).
	 */
	protected function extract_coupon( string $text ): string {
		// Patterns: "use code SAVE20", "promo code: DEAL15", "coupon: FLASH30"
		if ( preg_match( '/(?:use\s+code|promo\s+code|coupon\s*code?|code)[:\s]+([A-Z0-9_\-]{3,20})/i', $text, $m ) ) {
			return strtoupper( trim( $m[1] ) );
		}
		return '';
	}

	/**
	 * Determine deal type from text signals.
	 */
	protected function detect_deal_type( string $text ): string {
		$text_lower = strtolower( $text );
		if ( str_contains( $text_lower, 'coupon' ) || str_contains( $text_lower, 'promo code' ) ) {
			return 'coupon';
		}
		if ( str_contains( $text_lower, 'bundle' ) || str_contains( $text_lower, 'combo' ) ) {
			return 'bundle';
		}
		if ( str_contains( $text_lower, 'clearance' ) || str_contains( $text_lower, 'liquidation' ) ) {
			return 'clearance';
		}
		if ( str_contains( $text_lower, 'flash' ) || str_contains( $text_lower, 'lightning' ) ) {
			return 'flash';
		}
		if ( preg_match( '/\b(christmas|black friday|cyber monday|prime day|holiday)\b/i', $text ) ) {
			return 'seasonal';
		}
		return 'price_drop';
	}

	/**
	 * Get the domain from a URL.
	 */
	protected function extract_domain( string $url ): string {
		$host = parse_url( $url, PHP_URL_HOST ) ?: '';
		return preg_replace( '/^www\./', '', $host );
	}
}
