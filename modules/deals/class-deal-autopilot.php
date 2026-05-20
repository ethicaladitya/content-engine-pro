<?php
namespace ContentEnginePro\Deals;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;
use ContentEnginePro\NicheManager;
use ContentEnginePro\Research\WebResearcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deal Autopilot — AI content generation for deals.
 *
 * Pipeline:
 *   1. Pick top-scored pending deals from wp_cep_deals
 *   2. Research the product page for real context
 *   3. Generate premium deal article via AI
 *   4. Publish as 'deal' CPT with schema, SEO meta, featured image
 *   5. Mark deal as published
 *
 * @since 1.3.0
 */
class DealAutopilot {

	public static function run(): void {
		if ( ! Settings::is_enabled( 'deals_enabled' ) || ! Settings::is_enabled( 'deals_autopilot_enabled' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cep_deals';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		$max = (int) Settings::get( 'deals_max_per_run', 2 );

		Logger::log( 'Deal autopilot: run started', 'info', 'deals', [ 'max_per_run' => $max ] );

		// Reset stuck items
		$wpdb->query(
			"UPDATE {$table} SET status = 'pending'
			 WHERE status = 'generating'
			   AND discovered_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
		);

		$deals = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE status = 'pending'
				 ORDER BY quality_score DESC, discovered_at ASC
				 LIMIT %d",
				$max
			),
			ARRAY_A
		);

		if ( empty( $deals ) ) {
			Logger::log( 'Deal autopilot: no pending deals', 'info', 'deals' );
			return;
		}

		do_action( 'cep_before_deal_autopilot' );

		$published = 0;
		foreach ( $deals as $deal ) {
			// Atomic claim
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'generating' WHERE id = %d AND status = 'pending'",
					$deal['id']
				)
			);
			if ( ! $claimed ) {
				continue;
			}

			$result = self::process_deal( $deal );

			if ( $result && ! is_wp_error( $result ) ) {
				$wpdb->update( $table, [ 'status' => 'published', 'published_at' => current_time( 'mysql', true ) ], [ 'id' => $deal['id'] ], [ '%s', '%s' ], [ '%d' ] );
				$published++;
			} else {
				$msg = is_wp_error( $result ) ? $result->get_error_message() : 'unknown';
				Logger::log( "Deal autopilot: failed for {$deal['product_name']} — {$msg}", 'warning', 'deals' );
				$wpdb->update( $table, [ 'status' => 'failed' ], [ 'id' => $deal['id'] ], [ '%s' ], [ '%d' ] );
			}

			if ( $published < count( $deals ) ) {
				sleep( 2 );
			}
		}

		Logger::log( "Deal autopilot: published {$published} deal articles", 'info', 'deals' );
		do_action( 'cep_after_deal_autopilot', $published );
	}

	public static function process_deal( array $deal ) {
		// Research product page
		$research_text = '';
		if ( ! empty( $deal['product_url'] ) ) {
			$research_text = WebResearcher::research_urls( [ $deal['product_url'] ], 8000 );
		}

		// Generate article
		$article = self::generate_deal_article( $deal, $research_text );
		if ( is_wp_error( $article ) || empty( $article['title'] ) || empty( $article['content'] ) ) {
			return is_wp_error( $article ) ? $article : new \WP_Error( 'bad_response', 'Invalid AI response' );
		}

		// Deduplicate by slug
		$slug = sanitize_title( $deal['product_name'] . '-deal' );
		$slug_exists = get_posts( [
			'post_type'   => 'deal',
			'post_status' => [ 'publish', 'draft' ],
			'name'        => $slug,
			'fields'      => 'ids',
			'numberposts' => 1,
			'no_found_rows' => true,
		] );
		if ( ! empty( $slug_exists ) ) {
			return new \WP_Error( 'duplicate_slug', 'Deal slug already exists' );
		}

		$author_id = (int) Settings::get( 'default_author_id', 1 );
		$status    = Settings::is_enabled( 'auto_publish' ) ? 'publish' : 'draft';

		$post_id = wp_insert_post( apply_filters( 'cep_deal_autopilot_post_args', [
			'post_title'   => sanitize_text_field( $article['title'] ),
			'post_content' => wp_kses_post( $article['content'] ),
			'post_excerpt' => sanitize_textarea_field( $article['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => 'deal',
			'post_author'  => $author_id,
			'post_name'    => $slug,
		], $deal ) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Taxonomy — deal-category
		if ( ! empty( $article['category'] ) ) {
			$term = get_term_by( 'name', $article['category'], 'deal-category' );
			if ( ! $term ) {
				$ins = wp_insert_term( sanitize_text_field( $article['category'] ), 'deal-category' );
				$tid = ! is_wp_error( $ins ) ? $ins['term_id'] : 0;
			} else {
				$tid = $term->term_id;
			}
			if ( ! empty( $tid ) ) {
				wp_set_post_terms( $post_id, [ $tid ], 'deal-category' );
			}
		}

		// Post meta — deal data
		update_post_meta( $post_id, '_cep_deal_id',          (int) $deal['id'] );
		update_post_meta( $post_id, '_cep_deal_price',       (float) $deal['deal_price'] );
		update_post_meta( $post_id, '_cep_deal_original',    (float) $deal['original_price'] );
		update_post_meta( $post_id, '_cep_deal_discount_pct',(int) $deal['discount_pct'] );
		update_post_meta( $post_id, '_cep_deal_merchant',    sanitize_text_field( $deal['merchant_name'] ) );
		update_post_meta( $post_id, '_cep_deal_url',         esc_url_raw( $deal['affiliate_url'] ?: $deal['product_url'] ) );
		update_post_meta( $post_id, '_cep_deal_coupon',      sanitize_text_field( $deal['coupon_code'] ) );
		update_post_meta( $post_id, '_cep_deal_expires',     sanitize_text_field( $deal['expires_at'] ?? '' ) );
		update_post_meta( $post_id, '_cep_deal_type',        sanitize_key( $deal['deal_type'] ) );
		update_post_meta( $post_id, '_cep_deal_currency',    sanitize_text_field( $deal['currency'] ?? 'USD' ) );
		update_post_meta( $post_id, '_cep_deal_quality',     (float) $deal['quality_score'] );
		update_post_meta( $post_id, '_cep_ai_generated',     '1' );
		update_post_meta( $post_id, '_cep_ai_model',         sanitize_text_field( Settings::get( 'ai_model' ) ) );

		// Update cep_deals table with wp_post_id
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'cep_deals',
			[ 'wp_post_id' => $post_id ],
			[ 'id' => $deal['id'] ],
			[ '%d' ], [ '%d' ]
		);

		// SEO meta
		self::write_deal_seo_meta( $post_id, $article, $deal );

		// Featured image
		if ( ! empty( $deal['image_url'] ) ) {
			self::set_featured_image( $post_id, $deal['image_url'], $deal['product_name'] );
		}

		Logger::log( "Deal autopilot: published deal #{$post_id}: {$article['title']}", 'info', 'deals' );
		do_action( 'cep_deal_autopilot_published', $post_id, $deal, $article );

		return $post_id;
	}

	private static function generate_deal_article( array $deal, string $research_text ) {
		$ai    = AiClient::get_instance();
		$niche = NicheManager::get_active();
		$brand = Settings::get( 'brand_name', 'our publication' );

		$price_str    = $deal['deal_price']    ? '$' . number_format( (float) $deal['deal_price'], 2 )    : 'discounted price';
		$original_str = $deal['original_price'] ? '$' . number_format( (float) $deal['original_price'], 2 ) : '';
		$discount_str = $deal['discount_pct']   ? "{$deal['discount_pct']}% off" : '';
		$coupon_str   = $deal['coupon_code']    ? "Coupon code: {$deal['coupon_code']}" : '';
		$expires_str  = $deal['expires_at']     ? "Expires: {$deal['expires_at']}" : '';
		$merchant_str = $deal['merchant_name']  ?: $deal['merchant_domain'];

		$savings = '';
		if ( $deal['original_price'] && $deal['deal_price'] ) {
			$saved   = (float) $deal['original_price'] - (float) $deal['deal_price'];
			$savings = 'Save $' . number_format( $saved, 2 );
		}

		$system_prompt = "You are a senior deals editor at \"{$brand}\". You write deal articles that feel like advice from a smart friend who found a great bargain. You NEVER write generic \"this item is on sale\" filler. Every article you write explains WHY this deal is good, whether the product is actually worth buying, and what alternatives exist. Your writing is direct, specific, and genuinely useful to someone about to spend money.";

		$prompt = apply_filters( 'cep_deal_autopilot_prompt', <<<PROMPT
TASK: Write a premium deal article for "{$brand}" about the following deal.

DEAL DETAILS:
Product: {$deal['product_name']}
Current Deal Price: {$price_str}
Original Price: {$original_str}
Discount: {$discount_str}
{$savings}
Merchant: {$merchant_str}
{$coupon_str}
{$expires_str}
Deal Type: {$deal['deal_type']}
Category: {$deal['category']}

━━━━━━━━━━━━━━━━━━━━━━━
❌ FORBIDDEN PHRASES — NEVER USE
━━━━━━━━━━━━━━━━━━━━━━━
- "this deal won't last long"
- "grab it while you can"
- "in today's world"
- "it's important to note"
- "at the end of the day"
- "needless to say"
- Any AI-spam filler phrase

━━━━━━━━━━━━━━━━━━━━━━━
📰 ARTICLE STRUCTURE (MANDATORY)
━━━━━━━━━━━━━━━━━━━━━━━

**SECTION 1 — HOOK (no H2, 1-2 short paragraphs)**
Lead with the buyer context: who needs this product, what problem it solves, why NOW is the right time to buy. Include the deal price in the first sentence. NO generic intros.

**SECTION 2 — THE DEAL (H2: "The Deal: [Price] at [Merchant]")**
Precise deal breakdown: current price, original price, discount amount/%, coupon code if any, where to buy, estimated expiry. Use a <ul> for clarity. State clearly: is this a genuinely good deal based on the product's typical pricing?

**SECTION 3 — IS IT WORTH BUYING? (H2: "Is [Product] Worth $X?")**
Honest product analysis. What does this product actually do well? What are its limitations? Who is it best for? Use research data where available. Include a <ul> of 3-4 key features. Be specific — no marketing copy.

**SECTION 4 — ALTERNATIVES (H2: "Best Alternatives If This Deal Isn't Right For You")**
2-3 alternatives at different price points. Format each as: <strong>Under $X: [Product Name]</strong> — 1 sentence on why. This section shows editorial depth and drives additional affiliate opportunities.

**SECTION 5 — FAQ (H2: "Frequently Asked Questions")**
Exactly 3 Q&A pairs using <strong>Q:</strong> and <strong>A:</strong> format:
1. "Is [Product] worth buying at [price]?" — Direct honest answer
2. "How long will this deal last?" — Factual based on expiry/deal type
3. "What's the best alternative to [Product] at this price point?" — Specific recommendation

━━━━━━━━━━━━━━━━━━━━━━━
🔎 SEO REQUIREMENTS
━━━━━━━━━━━━━━━━━━━━━━━
- focus_keyword: "[product name] deal" or "[product name] sale" — what someone googles
- Title: ≤70 chars, include price, use one of:
  • "[Product] Deal: $X Off at [Merchant] (Was $Y)"
  • "Save $X on [Product] — Best Price We've Found"
  • "[Product] Drops to $X: Is It Worth Buying?"
- seo_title: focus keyword first, ≤60 chars, ends " | {$brand}"
- seo_description: ≤155 chars, price + what you save + curiosity hook
- url_slug: "product-name-deal" format, ≤5 words

━━━━━━━━━━━━━━━━━━━━━━━
📤 OUTPUT — valid JSON only
━━━━━━━━━━━━━━━━━━━━━━━
{
  "title": "...",
  "focus_keyword": "...",
  "seo_title": "...",
  "seo_description": "...",
  "url_slug": "...",
  "content": "<p>Full HTML article, 600-900 words, all 5 sections...</p>",
  "excerpt": "2 punchy sentences with the price and savings",
  "category": "best matching deals category",
  "tags": ["brand", "product-type", "deal-type", "merchant"],
  "faq": [
    {"q": "...", "a": "..."},
    {"q": "...", "a": "..."},
    {"q": "...", "a": "..."}
  ]
}

━━━━━━━━━━━━━━━━━━━━━━━
📋 RESEARCH DATA
━━━━━━━━━━━━━━━━━━━━━━━
{$research_text}
PROMPT
		, $deal, $research_text, $niche );

		$result = $ai->complete( $prompt, $system_prompt, [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => 0.65,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['content'], true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_json', 'AI returned invalid JSON for deal article' );
		}

		// Append FAQ to content as structured HTML
		if ( ! empty( $data['faq'] ) && is_array( $data['faq'] ) ) {
			$faq_html = '';
			foreach ( $data['faq'] as $qa ) {
				$q         = esc_html( $qa['q'] ?? '' );
				$a         = wp_kses_post( $qa['a'] ?? '' );
				$faq_html .= "<div class=\"cep-deal-faq-item\"><p><strong>Q: {$q}</strong></p><p>{$a}</p></div>\n";
			}
			if ( $faq_html ) {
				$data['content'] = ( $data['content'] ?? '' ) . "\n<div class=\"cep-deal-faq\">\n{$faq_html}</div>";
			}
		}

		return $data;
	}

	private static function write_deal_seo_meta( int $post_id, array $article, array $deal ): void {
		$title   = sanitize_text_field( $article['seo_title'] ?? $article['title'] ?? '' );
		$desc    = sanitize_textarea_field( $article['seo_description'] ?? $article['excerpt'] ?? '' );
		$keyword = sanitize_text_field( $article['focus_keyword'] ?? $deal['product_name'] ?? '' );

		// SmartCrawl (active on this site)
		if ( defined( 'SMARTCRAWL_VERSION' ) || class_exists( 'Smartcrawl\Smartcrawl', false ) ) {
			update_post_meta( $post_id, '_wds_title',          $title );
			update_post_meta( $post_id, '_wds_metadesc',       $desc );
			update_post_meta( $post_id, '_wds_focus-keywords', $keyword );
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title',    $title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw',  $keyword );
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_title',          $title );
			update_post_meta( $post_id, 'rank_math_description',    $desc );
			update_post_meta( $post_id, 'rank_math_focus_keyword',  $keyword );
		}
	}

	private static function set_featured_image( int $post_id, string $image_url, string $alt_text ): void {
		if ( empty( $image_url ) || ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$alt        = sanitize_text_field( $alt_text );
		$att_id     = media_sideload_image( $image_url, $post_id, $alt, 'id' );
		if ( ! is_wp_error( $att_id ) ) {
			update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
			set_post_thumbnail( $post_id, $att_id );
		}
	}
}
