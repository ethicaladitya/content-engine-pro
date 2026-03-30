<?php
namespace ContentEnginePro\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits a single post for SEO issues.
 *
 * Combines:
 *  1. Its own independent checks (title length, description, word count, images, headings, etc.)
 *  2. Issues surfaced by the active SEO plugin (Rank Math, Yoast, SmartCrawl, AIOSEO)
 *
 * Returns an array of SeoIssue objects, each annotated with whether they can be auto-fixed.
 */
class SeoAuditor {

	// Issue type constants
	const ISSUE_META_TITLE_MISSING   = 'meta_title_missing';
	const ISSUE_META_TITLE_SHORT     = 'meta_title_too_short';
	const ISSUE_META_TITLE_LONG      = 'meta_title_too_long';
	const ISSUE_META_DESC_MISSING    = 'meta_desc_missing';
	const ISSUE_META_DESC_SHORT      = 'meta_desc_too_short';
	const ISSUE_META_DESC_LONG       = 'meta_desc_too_long';
	const ISSUE_KW_NOT_IN_TITLE      = 'keyword_not_in_title';
	const ISSUE_KW_NOT_IN_INTRO      = 'keyword_not_in_intro';
	const ISSUE_THIN_CONTENT         = 'thin_content';
	const ISSUE_NO_INTERNAL_LINKS    = 'no_internal_links';
	const ISSUE_IMAGE_ALT_MISSING    = 'image_alt_missing';
	const ISSUE_HEADING_NO_H2        = 'heading_no_h2';
	const ISSUE_HEADING_MULTI_H1     = 'heading_multiple_h1';
	const ISSUE_SLUG_TOO_LONG        = 'slug_too_long';
	const ISSUE_SLUG_NO_KEYWORD      = 'slug_missing_keyword';
	const ISSUE_PLUGIN_SCORE_LOW     = 'plugin_score_low';
	const ISSUE_PLUGIN_SURFACED      = 'plugin_surfaced_issue';

	// Thresholds
	const TITLE_MIN   = 30;
	const TITLE_MAX   = 60;
	const DESC_MIN    = 100;
	const DESC_MAX    = 160;
	const MIN_WORDS   = 300;
	const SLUG_MAX    = 75;

	/**
	 * Audit a post and return all found issues.
	 *
	 * @param int           $post_id
	 * @param SeoPluginData $plugin_data  Normalized data from the active SEO plugin.
	 * @param int           $score_threshold  Below this score, flag a low-score issue.
	 * @return SeoIssue[]
	 */
	public static function audit( int $post_id, SeoPluginData $plugin_data, int $score_threshold = 70 ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return [];
		}

		$issues  = [];
		$content = $post->post_content;
		$title   = $post->post_title;
		$slug    = $post->post_name;

		// ── 1. Meta Title ─────────────────────────────────────────────────────
		$meta_title     = $plugin_data->meta_title;
		$effective_title = $meta_title ?: $title;

		if ( empty( $meta_title ) ) {
			$issues[] = new SeoIssue(
				self::ISSUE_META_TITLE_MISSING,
				'error',
				'Meta title is missing. Search engines will fall back to the post title.',
				'Generate an optimised meta title (30–60 characters) using the post title and focus keyword.',
				true
			);
		} elseif ( mb_strlen( $meta_title ) < self::TITLE_MIN ) {
			$issues[] = new SeoIssue(
				self::ISSUE_META_TITLE_SHORT,
				'warning',
				'Meta title is too short (' . mb_strlen( $meta_title ) . ' chars). Recommended: 30–60 characters.',
				'Expand the meta title to include the focus keyword and a benefit/modifier.',
				true
			);
		} elseif ( mb_strlen( $meta_title ) > self::TITLE_MAX ) {
			$issues[] = new SeoIssue(
				self::ISSUE_META_TITLE_LONG,
				'warning',
				'Meta title is too long (' . mb_strlen( $meta_title ) . ' chars). Google truncates titles over 60 characters.',
				'Shorten the meta title to 30–60 characters while keeping the focus keyword near the front.',
				true
			);
		}

		// ── 2. Meta Description ───────────────────────────────────────────────
		$meta_desc = $plugin_data->meta_description;

		if ( empty( $meta_desc ) ) {
			$issues[] = new SeoIssue(
				self::ISSUE_META_DESC_MISSING,
				'error',
				'Meta description is missing. Google may auto-generate a snippet that hurts CTR.',
				'Generate a compelling meta description (100–160 characters) summarising the post and including the focus keyword.',
				true
			);
		} elseif ( mb_strlen( $meta_desc ) < self::DESC_MIN ) {
			$issues[] = new SeoIssue(
				self::ISSUE_META_DESC_SHORT,
				'warning',
				'Meta description is too short (' . mb_strlen( $meta_desc ) . ' chars). Recommended: 100–160 characters.',
				'Expand the meta description to 100–160 characters with a clear value proposition.',
				true
			);
		} elseif ( mb_strlen( $meta_desc ) > self::DESC_MAX ) {
			$issues[] = new SeoIssue(
				self::ISSUE_META_DESC_LONG,
				'warning',
				'Meta description is too long (' . mb_strlen( $meta_desc ) . ' chars). Google will truncate it.',
				'Trim the meta description to 100–160 characters.',
				true
			);
		}

		// ── 3. Focus Keyword ──────────────────────────────────────────────────
		$keyword = $plugin_data->focus_keyword;

		if ( ! empty( $keyword ) ) {
			// Keyword in title
			if ( false === mb_stripos( $effective_title, $keyword ) ) {
				$issues[] = new SeoIssue(
					self::ISSUE_KW_NOT_IN_TITLE,
					'warning',
					"Focus keyword \"{$keyword}\" is not present in the meta title.",
					"Rewrite the meta title to naturally include \"{$keyword}\" near the beginning.",
					true
				);
			}

			// Keyword in intro paragraph (first ~200 words)
			$intro = self::get_intro_text( $content, 200 );
			if ( ! empty( $intro ) && false === mb_stripos( $intro, $keyword ) ) {
				$issues[] = new SeoIssue(
					self::ISSUE_KW_NOT_IN_INTRO,
					'warning',
					"Focus keyword \"{$keyword}\" does not appear in the introductory paragraph.",
					"Consider adding \"{$keyword}\" naturally within the first paragraph to signal relevance.",
					false // Requires manual edit — too risky to auto-patch content
				);
			}
		}

		// ── 4. Word Count ─────────────────────────────────────────────────────
		$word_count = self::count_words( $content );
		if ( $word_count < self::MIN_WORDS ) {
			$issues[] = new SeoIssue(
				self::ISSUE_THIN_CONTENT,
				'error',
				"Post content is thin ({$word_count} words). Minimum recommended: " . self::MIN_WORDS . ' words.',
				'Expand the post with additional context, examples, FAQs, or a summary section.',
				false
			);
		}

		// ── 5. Internal Links ─────────────────────────────────────────────────
		$site_host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal_links = self::count_internal_links( $content, (string) $site_host );
		if ( 0 === $internal_links ) {
			$issues[] = new SeoIssue(
				self::ISSUE_NO_INTERNAL_LINKS,
				'warning',
				'No internal links found in the post. Internal linking improves crawlability and PageRank flow.',
				'Auto-insert relevant internal links to related posts on this site.',
				true
			);
		}

		// ── 6. Image Alt Text ─────────────────────────────────────────────────
		$images_missing_alt = self::find_images_without_alt( $content );
		if ( ! empty( $images_missing_alt ) ) {
			$count    = count( $images_missing_alt );
			$issues[] = new SeoIssue(
				self::ISSUE_IMAGE_ALT_MISSING,
				'warning',
				"{$count} image(s) are missing alt text. Alt text is critical for accessibility and image SEO.",
				"Auto-generate descriptive alt text for each image based on surrounding context and focus keyword.",
				true,
				[ 'count' => $count, 'srcs' => array_slice( $images_missing_alt, 0, 5 ) ]
			);
		}

		// ── 7. Heading Structure ──────────────────────────────────────────────
		$h1_count = preg_match_all( '/<h1[\s>]/i', $content, $m );
		$h2_count = preg_match_all( '/<h2[\s>]/i', $content, $m );

		if ( $h1_count > 1 ) {
			$issues[] = new SeoIssue(
				self::ISSUE_HEADING_MULTI_H1,
				'error',
				"Post content contains {$h1_count} H1 tags. There should only be one H1 per page.",
				'Demote extra H1 headings to H2 or H3.',
				true
			);
		}

		if ( 0 === $h2_count && $word_count > 300 ) {
			$issues[] = new SeoIssue(
				self::ISSUE_HEADING_NO_H2,
				'warning',
				'Post content has no H2 headings. Proper heading structure aids readability and SEO.',
				'Add H2 headings to break the content into logical sections.',
				true
			);
		}

		// ── 8. Slug ───────────────────────────────────────────────────────────
		if ( strlen( $slug ) > self::SLUG_MAX ) {
			$issues[] = new SeoIssue(
				self::ISSUE_SLUG_TOO_LONG,
				'warning',
				'URL slug is very long (' . strlen( $slug ) . ' characters). Shorter slugs rank better.',
				'Manually shorten the slug to include only the focus keyword and 2–3 essential words.',
				false // Slug changes permanently break old URLs — manual only
			);
		}

		if ( ! empty( $keyword ) && false === mb_stripos( $slug, $keyword ) ) {
			// Only flag if keyword is a single word (avoid false positives for long-tail)
			$kw_words = explode( ' ', trim( $keyword ) );
			if ( count( $kw_words ) <= 2 ) {
				$simplified_kw = str_replace( ' ', '-', strtolower( trim( $keyword ) ) );
				if ( false === mb_stripos( $slug, $simplified_kw ) ) {
					$issues[] = new SeoIssue(
						self::ISSUE_SLUG_NO_KEYWORD,
						'warning',
						"The URL slug does not contain the focus keyword \"{$keyword}\".",
						'Manually update the slug to include the focus keyword (e.g., "my-focus-keyword-topic").',
						false // Slug changes break URLs — do not auto-fix
					);
				}
			}
		}

		// ── 9. Plugin Score ───────────────────────────────────────────────────
		if ( null !== $plugin_data->plugin_score && $plugin_data->plugin_score < $score_threshold ) {
			$plugin_label = SeoPluginDetector::detect_label();
			$issues[]     = new SeoIssue(
				self::ISSUE_PLUGIN_SCORE_LOW,
				$plugin_data->plugin_score < 50 ? 'error' : 'warning',
				"{$plugin_label} SEO score is {$plugin_data->plugin_score}/100 (threshold: {$score_threshold}).",
				'Review and address the specific issues flagged by ' . $plugin_label . ' in the post editor.',
				false
			);
		}

		// ── 10. Plugin-Surfaced Issues ────────────────────────────────────────
		foreach ( $plugin_data->plugin_issues as $plugin_issue_text ) {
			$issues[] = new SeoIssue(
				self::ISSUE_PLUGIN_SURFACED,
				'warning',
				$plugin_issue_text,
				'Resolve the issue flagged by ' . SeoPluginDetector::detect_label() . '.',
				false
			);
		}

		return $issues;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function get_intro_text( string $html, int $word_limit ): string {
		$text  = wp_strip_all_tags( $html );
		$words = preg_split( '/\s+/', trim( $text ) );
		return implode( ' ', array_slice( (array) $words, 0, $word_limit ) );
	}

	private static function count_words( string $html ): int {
		$text  = wp_strip_all_tags( $html );
		$words = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		return count( (array) $words );
	}

	private static function count_internal_links( string $html, string $host ): int {
		preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches );
		$count = 0;
		foreach ( $matches[1] as $href ) {
			$parsed = wp_parse_url( $href );
			if ( empty( $parsed['host'] ) ) {
				$count++; // relative link = internal
			} elseif ( false !== strpos( $parsed['host'], $host ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Find img tags that have no alt attribute or an empty one.
	 *
	 * @return string[] Array of src values for affected images.
	 */
	private static function find_images_without_alt( string $html ): array {
		preg_match_all( '/<img\s[^>]*>/i', $html, $matches );
		$missing = [];
		foreach ( $matches[0] as $tag ) {
			if ( ! preg_match( '/\salt=["\']([^"\']*)["\']/', $tag, $alt_match ) || '' === trim( $alt_match[1] ) ) {
				preg_match( '/\ssrc=["\']([^"\']+)["\']/', $tag, $src_match );
				$missing[] = $src_match[1] ?? '(unknown src)';
			}
		}
		return $missing;
	}
}

/**
 * Value object representing a single SEO issue found on a post.
 */
class SeoIssue {

	public string  $type;
	public string  $severity;     // 'warning' | 'error'
	public string  $description;
	public string  $suggestion;
	public bool    $auto_fixable;
	public array   $meta;

	public function __construct(
		string $type,
		string $severity,
		string $description,
		string $suggestion,
		bool $auto_fixable,
		array $meta = []
	) {
		$this->type         = $type;
		$this->severity     = $severity;
		$this->description  = $description;
		$this->suggestion   = $suggestion;
		$this->auto_fixable = $auto_fixable;
		$this->meta         = $meta;
	}
}
