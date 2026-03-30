<?php
namespace ContentEnginePro\Signal;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-dimensional signal scorer.
 * Scores incoming content based on keyword relevance, category weighting, and source quality.
 * All scoring weights and keyword lists are filterable.
 */
class SignalScorer {

	/**
	 * Score a content item.
	 *
	 * @param array  $item     Content item: title, content, url.
	 * @param string $category Source category.
	 * @return float 0–100
	 */
	public static function score( array $item, string $category = 'general' ): float {
		// Normalise category to a valid PHP method name component.
		// e.g. "Love & Dating" → "love_dating", "Self-Care" → "self_care"
		$method_key = preg_replace( '/[^a-z0-9]+/', '_', strtolower( $category ) );
		$method_key = trim( $method_key, '_' );
		$method     = 'score_' . $method_key;

		$short_kws = self::get_category_keywords( $method_key );

		// RSS feeds typically only supply a short excerpt or just the title.
		// Score in two passes so title always contributes meaningfully:
		// Pass 1 — title-only (weighted heavily so even excerpt-only items score).
		$title_text  = strtolower( $item['title'] ?? '' );
		$title_score = self::keyword_match_score( $title_text, $short_kws ) * 60;

		// Pass 2 — body content (uses full keyword list, lower multiplier).
		$body_text   = strtolower( $item['content'] ?? '' );
		$body_score  = 0.0;
		if ( strlen( $body_text ) > 50 ) {
			if ( method_exists( self::class, $method ) ) {
				$body_score = self::$method( $body_text ) * 0.5; // cap body contribution at 50%
			} else {
				$body_score = self::score_general( $body_text ) * 0.5;
			}
		}

		// Base score: every item from a known category gets a small base to avoid 0.
		$base  = 5.0;
		$score = min( 100, max( 0, $base + $title_score + $body_score ) );

		return apply_filters( 'cep_signal_score', $score, $item, $category );
	}

	/**
	 * Match-count-based scoring — not diluted by total keyword count.
	 * Each keyword match in $text adds 15 points (up to 1.0 max return).
	 *
	 * @param string   $text     Lowercase text to search.
	 * @param string[] $keywords Short keyword list (7–10 items).
	 * @return float 0.0–1.0
	 */
	private static function keyword_match_score( string $text, array $keywords ): float {
		if ( empty( $text ) || empty( $keywords ) ) {
			return 0.0;
		}
		$matches = 0;
		foreach ( $keywords as $kw ) {
			if ( false !== strpos( $text, strtolower( $kw ) ) ) {
				$matches++;
			}
		}
		// 1 match = 0.15, 2 = 0.30, 3 = 0.45 … capped at 1.0 (≥7 matches).
		return min( 1.0, $matches * 0.15 );
	}

	// ── Lifestyle & Entertainment categories ──────────────────────────────────

	private static function score_celebrity_gossip( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_celebrity_gossip', [
			'celebrity', 'breakup', 'split', 'relationship', 'dating', 'romance',
			'spotted', 'cheating', 'affair', 'couple', 'engaged', 'married',
			'divorce', 'baby', 'pregnant', 'feud', 'drama', 'rumor', 'rumour',
			'insider', 'source', 'exclusive', 'confirms', 'denies', 'shocking',
		] );
		return self::keyword_density( $text, $keywords ) * 85;
	}

	private static function score_celebrity_news( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_celebrity_news', [
			'celebrity', 'star', 'actor', 'actress', 'singer', 'musician',
			'award', 'interview', 'film', 'movie', 'album', 'tour', 'premiere',
			'red carpet', 'fashion', 'style', 'career', 'net worth', 'announcement',
			'statement', 'response', 'opens up', 'reveals', 'exclusive',
		] );
		return self::keyword_density( $text, $keywords ) * 80;
	}

	private static function score_love_dating( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_love_dating', [
			'dating', 'relationship', 'boyfriend', 'girlfriend', 'partner', 'love',
			'crush', 'breakup', 'heartbreak', 'romance', 'attraction', 'flirting',
			'first date', 'couple', 'hookup', 'situationship', 'talking stage',
			'red flag', 'green flag', 'texting', 'ghosting', 'commitment',
		] );
		return self::keyword_density( $text, $keywords ) * 80;
	}

	private static function score_relationship_advice( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_relationship_advice', [
			'relationship', 'communication', 'trust', 'boundaries', 'attachment',
			'commitment', 'toxic', 'healthy', 'couples therapy', 'love language',
			'intimacy', 'conflict', 'partner', 'emotional', 'vulnerable', 'therapist',
			'psychology', 'advice', 'tips', 'signs', 'patterns', 'behavior',
		] );
		return self::keyword_density( $text, $keywords ) * 80;
	}

	private static function score_self_care( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_self_care', [
			'skincare', 'wellness', 'mental health', 'anxiety', 'mindfulness',
			'beauty', 'routine', 'self-love', 'burnout', 'stress', 'therapy',
			'skin', 'hair', 'body', 'health', 'sleep', 'exercise', 'diet',
			'confidence', 'healing', 'glow', 'product', 'serum', 'moisturizer',
		] );
		return self::keyword_density( $text, $keywords ) * 80;
	}

	private static function score_lifestyle_trends( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_lifestyle_trends', [
			'trend', 'aesthetic', 'style', 'fashion', 'outfit', 'home', 'decor',
			'lifestyle', 'vibe', 'culture', 'viral', 'tiktok', 'instagram',
			'generation', 'millennial', 'gen z', 'habits', 'wellness', 'living',
			'work life', 'minimalist', 'sustainable', 'must-have',
		] );
		return self::keyword_density( $text, $keywords ) * 75;
	}

	private static function score_gift_ideas( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_gift_ideas', [
			'gift', 'deal', 'sale', 'discount', 'product', 'review', 'best',
			'top', 'worth it', 'buy', 'shop', 'recommendation', 'under', 'budget',
			'holiday', 'birthday', 'anniversary', 'subscription', 'box', 'kit',
			'affordable', 'luxury', 'splurge', 'editor', 'tested', 'tried',
		] );
		return self::keyword_density( $text, $keywords ) * 80;
	}

	private static function score_review( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_review', [
			'review', 'rating', 'tested', 'pros', 'cons', 'worth it', 'verdict',
			'recommend', 'compared', 'vs', 'best', 'alternatives', 'score',
			'performance', 'quality', 'value', 'hands on', 'tried', 'honest',
		] );
		return self::keyword_density( $text, $keywords ) * 80;
	}

	// ── Legacy / Tech categories (kept for backward compat) ───────────────────

	private static function score_general( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_general', [
			'celebrity', 'relationship', 'dating', 'lifestyle', 'wellness',
			'beauty', 'fashion', 'love', 'advice', 'health', 'trending',
			'viral', 'exclusive', 'reveals', 'star', 'update',
		] );
		return self::keyword_density( $text, $keywords ) * 70;
	}

	private static function score_security( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_security', [
			'vulnerability', 'cve', 'exploit', 'patch', 'security fix', 'xss', 'injection',
			'malware', 'breach', 'critical', 'advisory', 'disclosure', 'zero-day',
		] );
		return self::keyword_density( $text, $keywords ) * 100;
	}

	private static function score_jobs( string $text ): float {
		$keywords = apply_filters( 'cep_signal_keywords_jobs', [
			'remote', 'full-time', 'part-time', 'contract', 'freelance', 'engineer',
			'developer', 'designer', 'marketing', 'hiring', 'apply', 'salary',
			'position', 'role', 'opportunity', 'team', 'startup', 'company',
		] );
		return self::keyword_density( $text, $keywords ) * 85;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function keyword_density( string $text, array $keywords ): float {
		if ( empty( $text ) || empty( $keywords ) ) {
			return 0.0;
		}
		$matches = 0;
		foreach ( $keywords as $kw ) {
			if ( false !== strpos( $text, strtolower( $kw ) ) ) {
				$matches++;
			}
		}
		return min( 1.0, $matches / max( 1, count( $keywords ) ) );
	}

	private static function get_category_keywords( string $method_key ): array {
		$map = [
			'celebrity_gossip'    => [ 'celebrity', 'breakup', 'split', 'romance', 'dating', 'affair', 'drama' ],
			'celebrity_news'      => [ 'celebrity', 'star', 'actor', 'award', 'interview', 'film', 'album' ],
			'love_dating'         => [ 'dating', 'relationship', 'love', 'romance', 'couple', 'heartbreak' ],
			'relationship_advice' => [ 'relationship', 'communication', 'trust', 'boundaries', 'attachment' ],
			'self_care'           => [ 'skincare', 'wellness', 'health', 'beauty', 'routine', 'mindfulness' ],
			'lifestyle_trends'    => [ 'trend', 'lifestyle', 'fashion', 'style', 'viral', 'aesthetic' ],
			'gift_ideas'          => [ 'gift', 'product', 'deal', 'review', 'best', 'recommend' ],
			'review'              => [ 'review', 'tested', 'best', 'worth it', 'verdict', 'pros', 'cons' ],
			'jobs'                => [ 'remote', 'hiring', 'developer', 'salary', 'position', 'apply' ],
			'general'             => [ 'celebrity', 'relationship', 'dating', 'lifestyle', 'wellness' ],
		];
		return $map[ $method_key ] ?? $map['general'];
	}
}
