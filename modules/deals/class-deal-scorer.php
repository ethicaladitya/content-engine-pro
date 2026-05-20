<?php
namespace ContentEnginePro\Deals;

use ContentEnginePro\Settings;
use ContentEnginePro\NicheManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-dimensional deal quality scorer.
 *
 * Scores each deal 0–100 across five dimensions:
 *   - Discount depth    (0–30)
 *   - Merchant authority(0–20)
 *   - Time sensitivity  (0–15)
 *   - Relevance         (0–20)
 *   - Content quality   (0–15)
 *
 * @since 1.3.0
 */
class DealScorer {

	/**
	 * Score a normalized deal array.
	 *
	 * @param array $deal Normalized deal from a provider.
	 * @return float 0.0–100.0
	 */
	public static function score( array $deal ): float {
		$discount   = self::score_discount( $deal );
		$merchant   = self::score_merchant( $deal );
		$timeliness = self::score_timeliness( $deal );
		$relevance  = self::score_relevance( $deal );
		$quality    = self::score_content_quality( $deal );

		$total = $discount + $merchant + $timeliness + $relevance + $quality;

		return (float) apply_filters( 'cep_deal_quality_score', min( 100.0, max( 0.0, $total ) ), $deal );
	}

	// ─── Dimension Scorers ─────────────────────────────────────────────────

	/**
	 * Discount depth score (0–30).
	 * Higher discount = better. Below minimum threshold = 0.
	 */
	private static function score_discount( array $deal ): float {
		$min_pct = (int) Settings::get( 'deals_min_discount_pct', 15 );
		$pct     = (int) ( $deal['discount_pct'] ?? 0 );

		if ( $pct <= 0 ) {
			// No percentage but has a deal price — give partial credit
			if ( ! empty( $deal['deal_price'] ) ) {
				return 5.0;
			}
			return 0.0;
		}

		if ( $pct < $min_pct ) {
			return 0.0;
		}

		// 15% = 5pts, 30% = 12pts, 50% = 20pts, 70%+ = 30pts
		if ( $pct >= 70 ) {
			return 30.0;
		}
		if ( $pct >= 50 ) {
			return 20.0;
		}
		if ( $pct >= 30 ) {
			return 12.0;
		}
		return 5.0;
	}

	/**
	 * Merchant authority score (0–20).
	 * Well-known merchants score higher. Unknown domains score lower.
	 */
	private static function score_merchant( array $deal ): float {
		$domain = strtolower( $deal['merchant_domain'] ?? '' );
		if ( empty( $domain ) ) {
			return 5.0;
		}

		// Tier 1 merchants — high consumer trust
		$tier1 = [
			'amazon.com', 'amazon.co.uk', 'amazon.in',
			'bestbuy.com', 'walmart.com', 'target.com',
			'newegg.com', 'bhphotovideo.com', 'costco.com',
			'apple.com', 'samsung.com', 'microsoft.com',
			'ebay.com', 'homedepot.com', 'lowes.com',
			'nike.com', 'adidas.com', 'nordstrom.com',
		];

		// Tier 2 merchants — good trust
		$tier2 = [
			'adorama.com', 'rakuten.com', 'jet.com',
			'macys.com', 'kohls.com', 'gap.com',
			'dell.com', 'hp.com', 'lenovo.com',
			'zappos.com', 'wayfair.com', 'overstock.com',
		];

		foreach ( $tier1 as $t ) {
			if ( $domain === $t || str_ends_with( $domain, '.' . $t ) ) {
				return 20.0;
			}
		}
		foreach ( $tier2 as $t ) {
			if ( $domain === $t || str_ends_with( $domain, '.' . $t ) ) {
				return 12.0;
			}
		}

		// Unknown but has a domain — partial credit
		return 6.0;
	}

	/**
	 * Time-sensitivity score (0–15).
	 * Expiring soon = urgent = higher score.
	 */
	private static function score_timeliness( array $deal ): float {
		$type = $deal['deal_type'] ?? 'price_drop';

		// Flash deals always score max
		if ( 'flash' === $type ) {
			return 15.0;
		}

		$expires = $deal['expires_at'] ?? null;
		if ( ! $expires ) {
			return 5.0;
		}

		$now     = time();
		$exp     = strtotime( $expires );
		if ( ! $exp || $exp <= $now ) {
			return 0.0; // Already expired
		}

		$hours_left = ( $exp - $now ) / HOUR_IN_SECONDS;

		if ( $hours_left <= 6 ) {
			return 15.0;
		}
		if ( $hours_left <= 24 ) {
			return 12.0;
		}
		if ( $hours_left <= 72 ) {
			return 8.0;
		}
		return 5.0;
	}

	/**
	 * Niche relevance score (0–20).
	 * Keyword match against active niche keywords.
	 */
	private static function score_relevance( array $deal ): float {
		$niche_kws = NicheManager::get_keywords();
		if ( empty( $niche_kws ) ) {
			return 10.0; // Neutral if no niche configured
		}

		$text    = strtolower( ( $deal['product_name'] ?? '' ) . ' ' . ( $deal['description'] ?? '' ) . ' ' . ( $deal['category'] ?? '' ) );
		$matches = 0;

		foreach ( $niche_kws as $kw ) {
			if ( str_contains( $text, strtolower( $kw ) ) ) {
				$matches++;
			}
		}

		if ( $matches === 0 ) {
			return 3.0;
		}

		return min( 20.0, $matches * 5.0 );
	}

	/**
	 * Content quality score (0–15).
	 * Based on data completeness — more data = better article.
	 */
	private static function score_content_quality( array $deal ): float {
		$score = 0;

		if ( ! empty( $deal['product_name'] ) && strlen( $deal['product_name'] ) >= 5 ) {
			$score += 3;
		}
		if ( ! empty( $deal['description'] ) && strlen( $deal['description'] ) >= 50 ) {
			$score += 3;
		}
		if ( ! empty( $deal['image_url'] ) ) {
			$score += 3;
		}
		if ( ! empty( $deal['merchant_name'] ) ) {
			$score += 2;
		}
		if ( ! empty( $deal['coupon_code'] ) ) {
			$score += 2;
		}
		if ( ! empty( $deal['expires_at'] ) ) {
			$score += 2;
		}

		return (float) $score;
	}
}
