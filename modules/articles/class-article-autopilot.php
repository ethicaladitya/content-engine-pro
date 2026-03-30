<?php
namespace ContentEnginePro\Articles;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;
use ContentEnginePro\NicheManager;
use ContentEnginePro\Research\WebResearcher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Article Autopilot Pipeline:
 *
 * 1. Pick highest-scored pending items from raw_content queue
 * 2. Fetch and research the original source URL + related pages
 * 3. Use AI to write a comprehensive, SEO-optimised, original article
 * 4. Publish to WordPress posts with taxonomy, Rank Math meta, featured image, internal links
 * 5. Mark raw_content item as published
 */
class ArticleAutopilot {

	public static function run(): void {
		if ( ! Settings::is_enabled( 'article_autopilot_enabled' ) ) {
			return;
		}

		global $wpdb;
		$raw_table = $wpdb->prefix . 'cep_raw_content';
		$max       = (int) Settings::get( 'article_max_per_run', 3 );

		do_action( 'cep_before_article_autopilot' );

		// Reset items stuck in 'processing' for more than 10 minutes (crashed runs).
		$wpdb->query(
			"UPDATE {$raw_table}
			 SET status = 'pending'
			 WHERE status = 'processing'
			   AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
		);

		// Category-aware item selection: pick the best pending item from each category,
		// prioritising categories that have published the fewest posts in the last 7 days.
		// This ensures every active category gets content, not just whichever has the most raw items.
		$threshold  = (float) Settings::get( 'signal_threshold', 50 );
		$items      = self::pick_category_balanced_items( $raw_table, $threshold, $max );

		if ( empty( $items ) ) {
			Logger::log( 'Article autopilot: no items above threshold', 'info', 'article_autopilot' );
			return;
		}

		$published = 0;
		foreach ( $items as $item ) {
			$result = self::process_item( $item );
			if ( $result && ! is_wp_error( $result ) ) {
				$published++;
			}
			// Rate limit: pause between AI calls to avoid hammering the API.
			if ( $published < count( $items ) ) {
				sleep( 2 );
			}
		}

		Logger::log( "Article autopilot: published {$published} articles", 'info', 'article_autopilot' );
		do_action( 'cep_after_article_autopilot', $published );
	}

	/**
	 * Process one raw content item into a published article.
	 */
	public static function process_item( array $item ) {
		global $wpdb;

		Logger::log( "Processing: {$item['title']}", 'info', 'article_autopilot' );

		$post_type = Settings::get( 'primary_cpt_slug', 'post' );
		$raw_table = $wpdb->prefix . 'cep_raw_content';

		// 0. Atomic claim — atomically move status from 'pending' → 'processing'.
		//    If rows_affected = 0, another concurrent process already claimed this item.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$raw_table} SET status = 'processing', updated_at = NOW() WHERE id = %d AND status = 'pending'",
				$item['id']
			)
		);
		if ( ! $claimed ) {
			Logger::log( "Item #{$item['id']} already claimed by another process — skipping.", 'info', 'article_autopilot' );
			return new \WP_Error( 'already_processing', 'Item already claimed' );
		}

		// 0a. Deduplicate: skip if this source URL already has a published/draft post.
		if ( ! empty( $item['canonical_url'] ) ) {
			$url_exists = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'pending' ],
				'meta_key'       => '_cep_source_url',
				'meta_value'     => $item['canonical_url'],
				'fields'         => 'ids',
				'numberposts'    => 1,
				'no_found_rows'  => true,
			] );
			if ( ! empty( $url_exists ) ) {
				Logger::log( "Skipping duplicate source URL: {$item['canonical_url']}", 'info', 'article_autopilot' );
				$wpdb->update( $raw_table, [ 'status' => 'duplicate' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
				return new \WP_Error( 'duplicate_source', 'Source URL already published' );
			}
		}

		// 1. Gather research from source URL + related sources
		$research_urls  = [ $item['canonical_url'] ];
		$research_depth = (int) Settings::get( 'article_research_depth', 3 );

		if ( $research_depth > 1 && ! empty( $item['canonical_url'] ) ) {
			$additional    = self::find_related_urls( $item['canonical_url'], $item['title'], $research_depth - 1 );
			$research_urls = array_merge( $research_urls, $additional );
		}

		$research_text = WebResearcher::research_urls( $research_urls );

		// Fallback: use stored clean_text if research is thin
		if ( strlen( $research_text ) < 500 && ! empty( $item['clean_text'] ) ) {
			$research_text = $item['clean_text'];
		}

		if ( empty( $research_text ) ) {
			Logger::log( "No research data for: {$item['title']}", 'warning', 'article_autopilot' );
			$wpdb->update( $raw_table, [ 'status' => 'rejected' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
			return new \WP_Error( 'no_research', 'No research data available' );
		}

		// 2. Generate article with AI
		$article = self::generate_article( $item, $research_text );

		if ( is_wp_error( $article ) || empty( $article['title'] ) || empty( $article['content'] ) ) {
			Logger::log( "Article generation failed for: {$item['title']}", 'error', 'article_autopilot' );
			// Release the lock so this can be retried later.
			$wpdb->update( $raw_table, [ 'status' => 'pending' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
			return is_wp_error( $article ) ? $article : new \WP_Error( 'generation_failed', 'Invalid AI response' );
		}

		// 3. Publish post
		$author_id = (int) Settings::get( 'default_author_id', 1 );
		$status    = Settings::is_enabled( 'auto_publish' ) ? 'publish' : 'draft';

		// Build a short, clean slug from AI-provided url_slug or fallback to title
		$slug = ! empty( $article['url_slug'] )
			? sanitize_title( $article['url_slug'] )
			: self::build_short_slug( $article['title'] );

		// 3a. Deduplicate by focus keyword (same topic already published).
		if ( ! empty( $article['focus_keyword'] ) ) {
			$kw_exists = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'pending' ],
				'meta_query'     => [ [ 'key' => 'rank_math_focus_keyword', 'value' => $article['focus_keyword'], 'compare' => '=' ] ],
				'fields'         => 'ids',
				'numberposts'    => 1,
				'no_found_rows'  => true,
			] );
			if ( ! empty( $kw_exists ) ) {
				Logger::log( "Skipping duplicate focus keyword: {$article['focus_keyword']}", 'info', 'article_autopilot' );
				$wpdb->update( $raw_table, [ 'status' => 'duplicate' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
				return new \WP_Error( 'duplicate_keyword', 'Article with same focus keyword already exists' );
			}
		}

		// 3b. Deduplicate by slug.
		$slug_exists = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'draft', 'pending' ],
			'name'           => $slug,
			'fields'         => 'ids',
			'numberposts'    => 1,
			'no_found_rows'  => true,
		] );
		if ( ! empty( $slug_exists ) ) {
			Logger::log( "Skipping duplicate slug: {$slug}", 'info', 'article_autopilot' );
			$wpdb->update( $raw_table, [ 'status' => 'duplicate' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
			return new \WP_Error( 'duplicate_slug', 'Article with same slug already exists' );
		}

		// 3c. Title-similarity check — catches "same story, different headline" duplicates.
		//     Extracts meaningful words (>4 chars) from the new title and compares against
		//     titles of posts published in the last 30 days. If ≥60% of words overlap, skip.
		$new_title_words = array_filter(
			str_word_count( strtolower( $article['title'] ), 1 ),
			static fn( string $w ): bool => strlen( $w ) > 4
		);
		if ( ! empty( $new_title_words ) ) {
			$recent_ids = get_posts( [
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft' ],
				'numberposts'    => 200,
				'date_query'     => [ [ 'after' => '30 days ago' ] ],
				'fields'         => 'ids',
				'no_found_rows'  => true,
			] );
			foreach ( $recent_ids as $recent_id ) {
				$existing_title = strtolower( get_the_title( $recent_id ) );
				$matches        = array_filter(
					$new_title_words,
					static fn( string $w ): bool => str_contains( $existing_title, $w )
				);
				$similarity = count( $matches ) / count( $new_title_words );
				if ( $similarity >= 0.6 ) {
					Logger::log(
						"Skipping title-similar article (similarity={$similarity}): \"{$article['title']}\" ≈ post #{$recent_id}",
						'info',
						'article_autopilot'
					);
					$wpdb->update( $raw_table, [ 'status' => 'duplicate' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
					return new \WP_Error( 'duplicate_title', 'Article title too similar to recent post' );
				}
			}
		}

		$post_id = wp_insert_post( apply_filters( 'cep_article_autopilot_post_args', [
			'post_title'   => sanitize_text_field( $article['title'] ),
			'post_content' => wp_kses_post( $article['content'] ),
			'post_excerpt' => sanitize_textarea_field( $article['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => $post_type,
			'post_author'  => $author_id,
			'post_name'    => $slug,
		], $item ) );

		if ( is_wp_error( $post_id ) ) {
			// Release the lock so this item can be retried.
			$wpdb->update( $raw_table, [ 'status' => 'pending' ], [ 'id' => $item['id'] ], [ '%s' ], [ '%d' ] );
			return $post_id;
		}

		// 4. Assign primary taxonomy (WP built-in 'category' by default).
		if ( ! empty( $article['category'] ) ) {
			$tax     = Settings::get( 'primary_tax_slug', 'category' );
			$term_id = self::resolve_term( $article['category'], $tax );
			if ( $term_id ) {
				wp_set_post_terms( $post_id, [ $term_id ], $tax );
			}
		}

		// 5. Assign tags (WP built-in 'post_tag' by default).
		if ( ! empty( $article['tags'] ) && is_array( $article['tags'] ) ) {
			$tag_tax  = Settings::get( 'secondary_tax_slug', 'post_tag' );
			$tag_ids  = [];
			foreach ( $article['tags'] as $tag_name ) {
				$tid = self::resolve_term( sanitize_text_field( $tag_name ), $tag_tax );
				if ( $tid ) {
					$tag_ids[] = $tid;
				}
			}
			if ( $tag_ids ) {
				wp_set_post_terms( $post_id, $tag_ids, $tag_tax );
			}
		}

		// 6. Save CEP meta
		update_post_meta( $post_id, '_cep_source_url',       esc_url_raw( $item['canonical_url'] ) );
		update_post_meta( $post_id, '_cep_source_name',      sanitize_text_field( $item['source_name'] ?? '' ) );
		update_post_meta( $post_id, '_cep_signal_score',     (float) $item['score'] );
		update_post_meta( $post_id, '_cep_ai_model',         sanitize_text_field( Settings::get( 'ai_model' ) ) );
		update_post_meta( $post_id, '_cep_word_count',       str_word_count( wp_strip_all_tags( $article['content'] ) ) );
		update_post_meta( $post_id, '_cep_article_type',     'NewsArticle' );
		update_post_meta( $post_id, '_cep_researched_urls',  wp_json_encode( $research_urls ) );

		// 7. Theme-compat meta (old _baetalk_* keys the theme also reads)
		update_post_meta( $post_id, '_baetalk_source_name', sanitize_text_field( $item['source_name'] ?? '' ) );
		update_post_meta( $post_id, '_baetalk_source_url',  esc_url_raw( $item['canonical_url'] ) );
		update_post_meta( $post_id, '_baetalk_word_count',  str_word_count( wp_strip_all_tags( $article['content'] ) ) );

		// 8. Rank Math SEO meta
		if ( ! empty( $article['focus_keyword'] ) ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', sanitize_text_field( $article['focus_keyword'] ) );
		}
		if ( ! empty( $article['seo_title'] ) ) {
			update_post_meta( $post_id, 'rank_math_title', sanitize_text_field( $article['seo_title'] ) );
		}
		if ( ! empty( $article['seo_description'] ) ) {
			update_post_meta( $post_id, 'rank_math_description', sanitize_text_field( $article['seo_description'] ) );
		}

		// 9. Featured image
		self::assign_featured_image( $post_id, $item['canonical_url'], $article );

		// 10. Inject internal links into stored content
		self::inject_internal_links( $post_id );

		// 11. Mark as published in queue
		$wpdb->update(
			$wpdb->prefix . 'cep_raw_content',
			[ 'status' => 'published' ],
			[ 'id' => $item['id'] ],
			[ '%s' ], [ '%d' ]
		);

		Logger::log( "Published article #{$post_id}: {$article['title']}", 'info', 'article_autopilot' );
		do_action( 'cep_article_autopilot_published', $post_id, $item, $article );

		return $post_id;
	}

	/**
	 * Select up to $max pending items, one per category, prioritising the categories
	 * that have published the fewest articles in the last 7 days.
	 *
	 * @param string $raw_table  Full table name (with prefix).
	 * @param float  $threshold  Minimum signal score.
	 * @param int    $max        Maximum items to return.
	 * @return array<int, array<string, mixed>>
	 */
	private static function pick_category_balanced_items( string $raw_table, float $threshold, int $max ): array {
		global $wpdb;

		// 1. Count WP category posts published in the last 7 days per category slug.
		$recent_counts = [];
		$wp_cats       = get_categories( [ 'hide_empty' => false ] );
		foreach ( $wp_cats as $cat ) {
			if ( 'uncategorized' === $cat->slug ) {
				continue;
			}
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					WHERE tr.term_taxonomy_id = %d
					  AND p.post_type = 'post'
					  AND p.post_status = 'publish'
					  AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
					$cat->term_taxonomy_id
				)
			);
			$recent_counts[ strtolower( $cat->name ) ] = $count;
			// Also index by slug for flexible matching.
			$recent_counts[ $cat->slug ] = $count;
		}

		// 2. For each distinct source category in the pending queue, pick the single best item.
		$pending_by_cat = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rc.category,
				        (SELECT rc2.id FROM {$raw_table} rc2
				         WHERE rc2.status = 'pending'
				           AND rc2.score >= %f
				           AND rc2.category = rc.category
				         ORDER BY rc2.score DESC LIMIT 1) as best_id
				FROM {$raw_table} rc
				WHERE rc.status = 'pending' AND rc.score >= %f
				GROUP BY rc.category",
				$threshold, $threshold
			),
			ARRAY_A
		);

		if ( empty( $pending_by_cat ) ) {
			return [];
		}

		// 3. Sort categories by fewest recent posts first (most underrepresented gets priority).
		usort( $pending_by_cat, static function ( array $a, array $b ) use ( $recent_counts ): int {
			$cat_a  = strtolower( $a['category'] );
			$cat_b  = strtolower( $b['category'] );
			$count_a = $recent_counts[ $cat_a ] ?? $recent_counts[ str_replace( ' ', '-', $cat_a ) ] ?? 0;
			$count_b = $recent_counts[ $cat_b ] ?? $recent_counts[ str_replace( ' ', '-', $cat_b ) ] ?? 0;
			return $count_a <=> $count_b;
		} );

		// 4. Fetch the top $max item rows.
		$ids = array_filter( array_column( array_slice( $pending_by_cat, 0, $max ), 'best_id' ) );
		if ( empty( $ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT rc.*, s.name as source_name FROM {$raw_table} rc
				LEFT JOIN {$wpdb->prefix}cep_sources s ON rc.source_id = s.id
				WHERE rc.id IN ({$placeholders})",
				...$ids
			),
			ARRAY_A
		) ?: [];
	}

	/**
	 * Generate a full article from research data using AI.
	 */
	private static function generate_article( array $item, string $research_text ) {
		$ai    = AiClient::get_instance();
		$niche = NicheManager::get_active();
		$brand = Settings::get( 'brand_name', 'our publication' );
		$cats  = $niche['article_categories'];
		$kws   = $niche['keywords'];

		// Category-specific tone and headline formulas.
		$tone_map = [
			'celebrity gossip'    => 'Page Six / TMZ — punchy, declarative, insider-feeling. Every sentence earns its place.',
			'celebrity news'      => 'Entertainment Tonight — authoritative but warm, fast-paced, feels like you have a source.',
			'love & dating'       => 'Cosmopolitan — candid, relatable, warm, slightly irreverent. Talks to the reader like a smart friend.',
			'relationship advice' => 'The Gottman Institute meets Cosmo — empathetic, expert-backed, actionable. Uses research without being dry.',
			'self-care'           => 'Well+Good meets Allure — aspirational, science-forward, empowering. Specific over generic.',
			'lifestyle & trends'  => 'Refinery29 — culturally sharp, slightly irreverent, trend-first. Has opinions.',
			'gift ideas'          => 'BuzzFeed Shopping meets Wirecutter — specific, enthusiastic, trustworthy. Makes readers want to buy.',
		];
		$category_key = strtolower( trim( $item['category'] ?? '' ) );
		$tone         = 'vibrant, culturally-aware entertainment media that rivals Cosmopolitan, BuzzFeed, and Page Six';
		foreach ( $tone_map as $key => $t ) {
			if ( str_contains( $category_key, $key ) || str_contains( $key, $category_key ) ) {
				$tone = $t;
				break;
			}
		}

		$system_prompt = "You are a senior entertainment editor at \"{$brand}\" — a culturally sharp publication that rivals Page Six, BuzzFeed, and Cosmopolitan. Your job is to make people STOP scrolling. You write stories that feel urgent, specific, and alive. You NEVER write generic filler. You NEVER sound like an AI. Every article you write has a clear story angle, a specific trigger event, and a hook that creates genuine curiosity. You are not a summariser — you are a storyteller.";

		// Pull affiliate partners relevant to this category so the AI can weave them in naturally.
		$affiliate_context = self::get_affiliate_prompt_context( $item['category'] ?? '' );

		// ── Category-aware article structure ──────────────────────────────────
		$is_celebrity   = str_contains( $category_key, 'celebrity' );
		$is_gift        = str_contains( $category_key, 'gift' );

		if ( $is_celebrity ) {
			$structure_block = <<<'STRUCTURE'
📰 MANDATORY ARTICLE STRUCTURE — NEWS STORY (7 sections)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

**SECTION 1 — THE HOOK (no H2, opening paragraph)**
One to two short, punchy paragraphs. Drop the reader into the story with tension or curiosity already present. Include the focus keyword naturally. DO NOT start with "In recent months" or "Recently" or "[Name] is a [job title]." Start with the action, the feeling, or the question fans are already asking.

**SECTION 2 — THE SPARK (H2: "[Specific thing] Kicked Off the Conversation")**
MANDATORY: Identify the SPECIFIC trigger event that started this story — a deleted post, a missed event, a lyric, a sighting, a statement, a conspicuous absence, a comment. This is non-negotiable. Without a trigger, there is no story. Be specific: name dates, events, platform names where possible based on the research.

**SECTION 3 — THE EVIDENCE (H2: "Here's What People Keep Pointing To")**
A bullet-point list (<ul><li>) of 4–6 specific, concrete things observers have noticed. Each bullet must be a specific fact or behaviour — never vague. Example: NOT "she seemed distant" → YES "she skipped his [specific event], her first absence since [year]."

**SECTION 4 — CONTEXT (H2: Brief, punchy heading about backstory)**
Background that helps the reader understand why this matters. Max 150 words. Keep it tight and keep the momentum. Do not over-explain.

**SECTION 5 — THE CHATTER (H2: "What People Are Actually Saying")**
2–3 illustrative social media-style quotes from fans or observers. These should feel real and specific, not generic. Format each as: <p><em>"[quote]"</em> — one fan wrote on [platform].</p>
Then 1–2 sentences reacting to the chatter: what does the volume of reaction say about why this story matters?

**SECTION 6 — REALITY CHECK (H2: phrased as a direct question, e.g. "So Did They Actually Break Up?")**
Answer clearly using EEAT-signalling language:
  - "According to reports..." / "Multiple outlets have noted..." / "Sources suggest..."
  - State what IS confirmed. State what is NOT confirmed — but say it actively, not passively.
  - NEVER say "there is no official confirmation." Instead: "Neither [X] nor [Y] has addressed the speculation directly — which is itself notable given how quickly [X] usually shuts down false stories."

**SECTION 7 — THE CLOSE (no H2, final paragraph)**
ONE paragraph maximum. Do NOT summarise. Do NOT say what "time will tell." Create forward momentum — leave the reader wanting to know what happens next, or give them one final thought that reframes everything they just read. End with a statement or question that creates anticipation or resonance.
STRUCTURE;

		} elseif ( $is_gift ) {
			$structure_block = <<<'STRUCTURE'
📰 MANDATORY ARTICLE STRUCTURE — GIFT ROUNDUP (5 sections)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

**SECTION 1 — THE HOOK (no H2, problem-first opening)**
Who is this gift for? What occasion or problem does it solve? Make the reader feel seen — like you wrote this specifically for their situation. Include the focus keyword in the first sentence.

**SECTION 2 — WHY THESE PICKS (H2: "How We Found the Best [Items]")**
2–3 sentences on your selection criteria — what separates a great pick from a mediocre one. Creates editorial credibility. Shows this isn't a random list.

**SECTION 3 — THE PICKS (H2: "The [N] Best [Items] Worth Gifting Right Now")**
3–5 individual picks. For each one, use an H3 heading with the product/gift name, then 1 paragraph on what makes it stand out and who it's perfect for, plus a price signal (e.g. "from $35", "under $50"). Be specific — name the feature, the feel, the reaction it gets. Use <ul><li> for any sub-features.

**SECTION 4 — WHAT TO LOOK FOR (H2: "Before You Buy: What Actually Matters")**
A <ul> list of 3–4 buying criteria the reader should consider when choosing. Practical, specific, confidence-building. No padding.

**SECTION 5 — THE VERDICT (no H2, final paragraph)**
A confident, direct closing recommendation. "Our top pick for most people is [X] because..." Leave the reader with a clear decision path and a reason to act now.
STRUCTURE;

		} else {
			// Guide structure: Self-Care, Love & Dating, Relationship Advice, Lifestyle & Trends
			$structure_block = <<<'STRUCTURE'
📰 MANDATORY ARTICLE STRUCTURE — GUIDE / TIPS (7 sections)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

**SECTION 1 — THE HOOK (no H2, emotional opening — 2 short paragraphs)**
Pull the reader in with a relatable feeling, a surprising truth, or a situation they recognise immediately. The first sentence is the most important sentence in the article — make it impossible to ignore. DO NOT start with "In recent months", "In today's world", or any celebrity name. Start with the reader's lived experience, a sharp observation, or a question they're already asking themselves. Include the focus keyword naturally.

**SECTION 2 — WHY THIS MATTERS NOW (H2: "Why [Topic] Is Getting So Much Attention Right Now")**
What specifically brought this topic to the forefront — new research, a cultural shift, a viral moment, a study? Reference the research data directly. Use EEAT-signalling language: "According to [source]...", "A [year] study found...", "Experts at [institution] point to...". This section builds credibility and explains why the reader should care right now, not in general.

**SECTION 3 — SECRET #1: THE SURPRISING BENEFIT (H2: "[Compelling angle] — What Most People Miss")**
The most counterintuitive or eye-opening insight from the research. Something the reader didn't know, or knew but never thought about this way. Be specific, never generic. End with one concrete, immediately actionable tip they can use today. Write like a knowledgeable friend — warm, direct, no jargon.

**SECTION 4 — SECRET #2: THE ACTION PLAN (H2: "How to Actually [Do / Get / Achieve the Thing]")**
Practical, specific steps. Use a <ul> list of 3–5 concrete actions — each one something real the reader can do this week. Not vague advice like "be more mindful" — actual behaviours: "Block 10 minutes on your calendar for...", "The next time [X] happens, try...". This is the section that makes the article bookmarkable.

**SECTION 5 — SECRET #3: THE COMMON TRAP (H2: "The Mistake That Holds Most People Back")**
Frame this as a warning or revelation. Identify the single most common mistake people make with this topic — and explain exactly why it backfires. This section adds value by protecting the reader from failure, not just telling them what to do. Specific > general. "Most people do X, which feels like progress but actually causes Y because Z."

**SECTION 6 — WHAT THE RESEARCH SAYS (H2: "Here's What [Experts / Research / Data] Actually Shows")**
EEAT section. Dense, credible, specific. Reference studies, expert names, or statistics from the research data. Use: "Research from [institution] suggests...", "A meta-analysis of [N] studies found...", "[Expert name], [credential], explains...". Max 150 words. No fluff — every sentence adds a fact.

**SECTION 7 — THE CLOSE (no H2, motivational final paragraph)**
DO NOT summarise. DO NOT say "in conclusion" or "ultimately" or "only time will tell." Leave the reader feeling empowered, slightly changed, or hungry for more. End with a forward-looking statement or a resonant question — something that makes them want to act on what they just read, or makes them think about it long after they close the tab.
STRUCTURE;
		}

		$prompt = apply_filters( 'cep_article_autopilot_prompt', <<<PROMPT
TASK: Write a viral-quality article for "{$brand}" based on the research data below.

TOPIC SIGNAL: {$item['title']}
CATEGORY: {$item['category']}
TONE GUIDE: {$tone}
NICHE: {$niche['label']}
TARGET KEYWORDS: {$kws}
AVAILABLE CATEGORIES: {$cats}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
❌ FORBIDDEN PHRASES — NEVER USE THESE
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Any of the following phrases will cause immediate rejection. Do NOT use them:
- "rumors are circulating"
- "fans are speculating"
- "it's important to note"
- "it remains to be seen"
- "there is no official confirmation"
- "in conclusion"
- "in today's world"
- "has taken the internet by storm"
- "this has led many to wonder"
- "needless to say"
- "only time will tell"
- "at the end of the day"
- "it is worth noting"
- "many people believe"
- any phrase that hedges without adding information

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔥 HEADLINE FORMULA — CHOOSE THE BEST FIT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
For Celebrity/Gossip content:
  • "Why Everyone Suddenly Thinks [X and Y] Have [Z]"
  • "[Celebrity] Just [Did Y] — and Fans Think They Know Why"
  • "Did [X] Really [Y]? Here's What We Actually Know"
  • "The Real Reason [Celebrity] [action] — And It's Not What You Think"
  • "[Celebrity]'s [Specific Thing] Has Fans Convinced [Conclusion]"

For Relationship/Dating/Self-Care content:
  • "[Number] Signs You're [Relatable Situation] (And What to Do About It)"
  • "Why You Keep [Pattern] — And the Real Reason It Happens"
  • "The [Number] Things About [Topic] Nobody Actually Talks About"
  • "What Happens to Your [Relationship/Body/Mind] When You [Action]"

Rules: Title must be ≤70 chars. Must create curiosity or answer a burning question.

{$structure_block}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🔎 SEO REQUIREMENTS (NON-NEGOTIABLE)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
1. FOCUS KEYWORD: a specific 2–4 word phrase that matches a real Google search query. Think: what would someone type into Google to find this article right now?
2. The focus keyword MUST appear verbatim:
   - In the article title (near the start)
   - In the FIRST paragraph's first sentence
   - In at least 2 H2 headings (exact match or close variant)
   - In the seo_description (exact match)
   - Naturally throughout the content (~1 per 100 words)
3. Minimum 900 words of body content. Target 1,100–1,300 words.
4. HTML ONLY: <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>. ZERO markdown.
5. seo_title: focus keyword first, ≤60 chars, ends with "| {$brand}"
6. seo_description: ≤155 chars, starts with focus keyword, creates curiosity, active voice
7. url_slug: 4–5 hyphenated lowercase words, keyword-first, no stop words

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📤 OUTPUT FORMAT
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Return ONLY a valid JSON object — no preamble, no commentary, no markdown fences:
{
  "title": "Click-worthy headline using formula above, ≤70 chars",
  "focus_keyword": "2-4 word search phrase",
  "seo_title": "Keyword-First Power Title | {$brand}",
  "seo_description": "≤155 chars, keyword-first, curiosity-driven, active voice",
  "url_slug": "keyword-first-4-5-word-slug",
  "content": "<p>Full HTML article, 900–1300 words, all sections per structure above...</p>",
  "excerpt": "2 punchy sentences that tease the story without spoiling it",
  "category": "Best matching category from: {$cats}",
  "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
  "key_takeaway": "One sentence — the most interesting thing about this story"
}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
🤝 PARTNER INTEGRATION (affiliate context)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$affiliate_context}

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 RESEARCH DATA
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
{$research_text}
PROMPT
		, $item, $research_text, $niche );

		$result = $ai->complete( $prompt, $system_prompt, [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => 0.75,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['content'], true );

		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_json', 'AI returned invalid JSON' );
		}

		// Post-generation validation: ensure focus keyword appears in seo_description
		$kw = strtolower( $data['focus_keyword'] ?? '' );
		if ( $kw && ! empty( $data['seo_description'] ) && ! str_contains( strtolower( $data['seo_description'] ), $kw ) ) {
			// Prepend keyword to description to satisfy Rank Math
			$data['seo_description'] = ucfirst( $kw ) . ': ' . ltrim( $data['seo_description'] );
			if ( strlen( $data['seo_description'] ) > 155 ) {
				$data['seo_description'] = substr( $data['seo_description'], 0, 152 ) . '...';
			}
		}

		// Log a warning if content is short
		$word_count = str_word_count( wp_strip_all_tags( $data['content'] ?? '' ) );
		if ( $word_count < 600 ) {
			Logger::log( "Article content too short ({$word_count} words) for: " . ( $data['title'] ?? 'unknown' ), 'warning', 'article_autopilot' );
		}

		return $data;
	}

	/**
	 * Build an affiliate context block for the AI prompt.
	 *
	 * Returns a short instruction string listing the 2–3 affiliate partners
	 * most relevant to the given content category. If no partners match,
	 * returns a generic instruction so the placeholder doesn't appear blank.
	 *
	 * @param string $category  Source category (e.g. "Love & Dating").
	 * @return string
	 */
	private static function get_affiliate_prompt_context( string $category ): string {
		$providers = \ContentEnginePro\Affiliate\AffiliateManager::get_providers();

		if ( empty( $providers ) ) {
			return 'No partner brands configured — skip this section.';
		}

		$category_lower = strtolower( trim( $category ) );
		$relevant       = [];

		foreach ( $providers as $provider ) {
			$audience = strtolower( $provider['target_audience'] ?? '' );
			// Match if this provider targets the current category.
			if ( empty( $audience ) || str_contains( $audience, $category_lower ) ) {
				$relevant[] = $provider;
			}
		}

		// Fallback: if no category match, pick any 2 providers.
		if ( empty( $relevant ) ) {
			$relevant = array_slice( $providers, 0, 2 );
		}

		// Limit to 3 partners max to avoid stuffing the prompt.
		$relevant = array_slice( $relevant, 0, 3 );

		$lines = [];
		foreach ( $relevant as $p ) {
			$terms  = implode( ', ', array_slice( $p['match_terms'], 0, 3 ) );
			$lines[] = "- {$p['name']} — mention naturally using one of: {$terms}";
		}

		$partner_list = implode( "\n", $lines );

		return <<<AFFILIATE
PARTNER INTEGRATION (mandatory editorial practice — every article includes one):
Our editorial standard is to include exactly ONE natural, helpful partner reference per article.
Find the right moment and weave it in as a genuine reader recommendation — not an ad.

Rules:
  • You MUST include exactly one partner mention somewhere in the article body
  • Find the most natural fit — it should feel like advice from a knowledgeable friend
  • Write it as a helpful tip, not a sponsored mention (e.g. "apps like BetterHelp make it easier to...", "Headspace is worth bookmarking if...")
  • Use the brand name EXACTLY as shown below — spelling and capitalisation matter for link injection
  • Place it mid-article, never in the opening paragraph or the very last sentence

Partner brands available for this category ({$category}):
{$partner_list}
AFFILIATE;
	}

	/**
	 * Assign a featured image to the post.
	 * Order: OG image from source → Pexels search.
	 */
	private static function assign_featured_image( int $post_id, string $source_url, array $article ): void {
		$mode = Settings::get( 'featured_image_source', 'source_first' );

		if ( $mode === 'disabled' ) {
			return;
		}

		$image_url  = null;
		$pexels_data = null;

		if ( $mode === 'pexels_only' ) {
			$pexels_data = self::fetch_pexels_image( $article['focus_keyword'] ?? $article['title'] );
			$image_url   = $pexels_data['src'] ?? null;
		} elseif ( $mode === 'source_only' ) {
			$image_url = self::extract_source_og_image( $source_url );
		} elseif ( $mode === 'pexels_first' ) {
			$pexels_data = self::fetch_pexels_image( $article['focus_keyword'] ?? $article['title'] );
			$image_url   = $pexels_data['src'] ?? self::extract_source_og_image( $source_url );
		} else {
			// source_first (default)
			$image_url = self::extract_source_og_image( $source_url );
			if ( ! $image_url ) {
				$pexels_data = self::fetch_pexels_image( $article['focus_keyword'] ?? $article['title'] );
				$image_url   = $pexels_data['src'] ?? null;
			}
		}

		if ( ! $image_url ) {
			// Final fallback: deterministic Picsum image keyed to the article title.
			$seed      = preg_replace( '/[^a-z0-9]+/', '-', strtolower( substr( $article['title'], 0, 60 ) ) );
			$image_url = "https://picsum.photos/seed/{$seed}/1200/628";
			Logger::log( "Using Picsum fallback image for #{$post_id}: {$image_url}", 'info', 'article_autopilot' );
		}

		// Sideload the image into the WordPress media library
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$alt_text   = sanitize_text_field( $article['focus_keyword'] ?? $article['title'] );
		$attachment_id = media_sideload_image( $image_url, $post_id, $alt_text, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			Logger::log( "Featured image sideload failed for #{$post_id}: " . $attachment_id->get_error_message(), 'warning', 'article_autopilot' );
			return;
		}

		// Set alt text
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		// Set as featured image
		set_post_thumbnail( $post_id, $attachment_id );

		// Store Pexels credit meta if applicable
		if ( $pexels_data ) {
			update_post_meta( $attachment_id, '_pexels_photographer',     sanitize_text_field( $pexels_data['photographer'] ?? '' ) );
			update_post_meta( $attachment_id, '_pexels_photographer_url', esc_url_raw( $pexels_data['photographer_url'] ?? '' ) );
			update_post_meta( $attachment_id, '_pexels_photo_url',        esc_url_raw( $pexels_data['photo_url'] ?? '' ) );
		}

		Logger::log( "Featured image set for #{$post_id}: {$image_url}", 'info', 'article_autopilot' );
	}

	/**
	 * Extract the OG image URL from a source article page.
	 */
	private static function extract_source_og_image( string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		$response = wp_remote_get( $url, [
			'timeout'    => 10,
			'user-agent' => Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return null;
		}

		// Try OG image first
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			return filter_var( $m[1], FILTER_VALIDATE_URL ) ? $m[1] : null;
		}
		// Alternate attribute order
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $m ) ) {
			return filter_var( $m[1], FILTER_VALIDATE_URL ) ? $m[1] : null;
		}

		return null;
	}

	/**
	 * Fetch a relevant image from Pexels API.
	 * Returns array with src, photographer, photographer_url, photo_url — or null.
	 */
	private static function fetch_pexels_image( string $query ): ?array {
		$key = Settings::get( 'pexels_key', '' );
		if ( empty( $key ) ) {
			return null;
		}

		$query    = urlencode( sanitize_text_field( $query ) );
		$response = wp_remote_get( "https://api.pexels.com/v1/search?query={$query}&per_page=5&orientation=landscape", [
			'timeout' => 10,
			'headers' => [ 'Authorization' => $key ],
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status !== 200 ) {
			Logger::log( "Pexels API returned HTTP {$status} for query: {$query}", 'warning', 'article_autopilot' );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$photo = $body['photos'][0] ?? null;

		if ( ! $photo ) {
			return null;
		}

		return [
			'src'              => $photo['src']['large2x'] ?? $photo['src']['large'] ?? null,
			'photographer'     => $photo['photographer'] ?? '',
			'photographer_url' => $photo['photographer_url'] ?? '',
			'photo_url'        => $photo['url'] ?? '',
		];
	}

	/**
	 * Inject 1–2 internal hyperlinks into the stored post content.
	 * Links to other published posts whose titles share keywords with the new content.
	 */
	private static function inject_internal_links( int $post_id ): void {
		if ( ! Settings::is_enabled( 'enable_internal_linking' ) ) {
			return;
		}

		$post_type = Settings::get( 'primary_cpt_slug', 'post' );
		$content   = get_post_field( 'post_content', $post_id );

		if ( empty( $content ) ) {
			return;
		}

		// Get recent published posts (excluding current)
		$recent = get_posts( [
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'post__not_in'   => [ $post_id ],
			'posts_per_page' => 8,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );

		if ( empty( $recent ) ) {
			return;
		}

		$injected   = 0;
		$max_links  = 2;
		$linked_ids = [];

		foreach ( $recent as $related_post ) {
			if ( $injected >= $max_links ) {
				break;
			}
			if ( in_array( $related_post->ID, $linked_ids, true ) ) {
				continue;
			}

			// Find significant words in the related post title (4+ chars, not already linked)
			$words = preg_split( '/\s+/', $related_post->post_title );
			$significant = array_filter( $words, fn( $w ) => strlen( $w ) >= 5 && ctype_alpha( $w ) );

			foreach ( $significant as $word ) {
				$pattern     = '/(?<!\w)(' . preg_quote( $word, '/' ) . ')(?!\w)(?![^<]*>)(?![^<]*<\/a>)/i';
				$replacement = '<a href="' . esc_url( get_permalink( $related_post->ID ) ) . '">' . $word . '</a>';
				$new_content = preg_replace( $pattern, $replacement, $content, 1 );

				if ( $new_content !== $content ) {
					$content     = $new_content;
					$injected++;
					$linked_ids[] = $related_post->ID;
					break; // One link per related post
				}
			}
		}

		if ( $injected > 0 ) {
			wp_update_post( [
				'ID'           => $post_id,
				'post_content' => $content,
			] );
		}
	}

	/**
	 * Find related URLs to research for a given topic.
	 */
	private static function find_related_urls( string $source_url, string $title, int $max ): array {
		$data = WebResearcher::fetch( $source_url );
		if ( ! $data ) {
			return [];
		}

		$response = wp_remote_get( $source_url, [
			'timeout'    => 15,
			'user-agent' => Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' ),
		] );

		if ( is_wp_error( $response ) ) {
			return [];
		}

		$html  = wp_remote_retrieve_body( $response );
		$links = [];

		preg_match_all( '/<a[^>]+href=["\']([^"\'#]+)["\'][^>]*>/i', $html, $matches );

		$source_host = parse_url( $source_url, PHP_URL_HOST );

		foreach ( $matches[1] as $link ) {
			if ( ! filter_var( $link, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			$link_host = parse_url( $link, PHP_URL_HOST );
			if ( $link_host === $source_host && $link !== $source_url ) {
				$links[] = $link;
			}
			if ( count( $links ) >= $max ) {
				break;
			}
		}

		return array_slice( array_unique( $links ), 0, $max );
	}

	/**
	 * Resolve a taxonomy term by name or slug, with fuzzy fallback.
	 * Creates the term if no match found. Returns term ID or 0.
	 */
	private static function resolve_term( string $value, string $taxonomy ): int {
		if ( empty( $value ) || empty( $taxonomy ) ) {
			return 0;
		}

		$slug = sanitize_title( $value );

		// 1. Exact match by name
		$term = get_term_by( 'name', $value, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->term_id;
		}

		// 2. Exact match by slug
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return $term->term_id;
		}

		// 3. Fuzzy match: compare normalised slugs against all existing terms
		$all_terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		if ( ! is_wp_error( $all_terms ) && ! empty( $all_terms ) ) {
			$normalised_input = str_replace( [ '-', '_', ' ' ], '', strtolower( $slug ) );
			$best_term        = null;
			$best_score       = 0;

			foreach ( $all_terms as $t ) {
				$normalised_term = str_replace( [ '-', '_', ' ' ], '', strtolower( $t->slug ) );
				// Check if one is a substring of the other
				if ( $normalised_input === $normalised_term ) {
					return $t->term_id; // perfect normalised match
				}
				similar_text( $normalised_input, $normalised_term, $pct );
				if ( $pct > $best_score ) {
					$best_score = $pct;
					$best_term  = $t;
				}
			}

			// Accept if similarity ≥ 70%
			if ( $best_score >= 70 && $best_term ) {
				Logger::log( "Fuzzy term match: '{$value}' → '{$best_term->name}' ({$best_score}%)", 'info', 'article_autopilot' );
				return $best_term->term_id;
			}
		}

		// 4. Create the term if no match found
		$inserted = wp_insert_term( sanitize_text_field( $value ), $taxonomy );
		if ( ! is_wp_error( $inserted ) ) {
			return (int) $inserted['term_id'];
		}

		return 0;
	}

	/**
	 * Build a short slug (max 5 words) from a post title.
	 */
	private static function build_short_slug( string $title ): string {
		$stop_words = [ 'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'is', 'are', 'was', 'were', 'be', 'been', 'by', 'as', 'it', 'its' ];
		$words      = preg_split( '/\s+/', strtolower( preg_replace( '/[^a-zA-Z0-9\s]/', '', $title ) ) );
		$filtered   = array_filter( $words, fn( $w ) => ! in_array( $w, $stop_words, true ) && strlen( $w ) > 1 );
		$slug_words = array_slice( array_values( $filtered ), 0, 5 );
		return implode( '-', $slug_words ) ?: sanitize_title( $title );
	}
}
