<?php
namespace ContentEnginePro\Seo;

use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;
use ContentEnginePro\Publisher\InternalLinker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies SEO fixes to a post.
 *
 * FIX MODES:
 *  - auto_fix()   → Deterministic rule-based fixes only.  No AI calls.  Safe to run on cron.
 *  - ai_fix()     → AI-powered fix for a single issue.    Only called when the user explicitly
 *                   clicks "AI Fix" in the admin UI.
 */
class SeoFixer {

	// ── Auto-Fix (deterministic, no AI) ──────────────────────────────────────

	/**
	 * Apply all deterministic fixes for a single issue.
	 *
	 * Returns true if the fix was applied, false if it could not be handled
	 * without AI or manual intervention.
	 *
	 * @param int      $post_id
	 * @param SeoIssue $issue
	 * @param SeoPluginData $plugin_data
	 * @return bool
	 */
	public static function auto_fix( int $post_id, SeoIssue $issue, SeoPluginData $plugin_data ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		switch ( $issue->type ) {

			// ── Meta Title ─────────────────────────────────────────────────
			case SeoAuditor::ISSUE_META_TITLE_MISSING:
				// Fallback: use post title trimmed to 60 chars at nearest word boundary
				$fallback = self::truncate_at_word( $post->post_title, SeoAuditor::TITLE_MAX );
				SeoPluginDetector::write_meta( $post_id, $fallback, '' );
				Logger::log( "SEO auto-fix: set meta title from post title for post {$post_id}", 'info', 'seo' );
				return true;

			case SeoAuditor::ISSUE_META_TITLE_SHORT:
				// Append site name to pad it — deterministic, no AI
				$current  = $plugin_data->meta_title;
				$site     = get_bloginfo( 'name' );
				$combined = $current . ' | ' . $site;
				if ( mb_strlen( $combined ) <= SeoAuditor::TITLE_MAX ) {
					SeoPluginDetector::write_meta( $post_id, $combined, '' );
					Logger::log( "SEO auto-fix: padded short meta title for post {$post_id}", 'info', 'seo' );
					return true;
				}
				return false; // Still couldn't reach min — needs AI

			case SeoAuditor::ISSUE_META_TITLE_LONG:
				// Truncate to nearest word boundary at 60 chars
				$trimmed = self::truncate_at_word( $plugin_data->meta_title, SeoAuditor::TITLE_MAX );
				SeoPluginDetector::write_meta( $post_id, $trimmed, '' );
				Logger::log( "SEO auto-fix: trimmed long meta title for post {$post_id}", 'info', 'seo' );
				return true;

			// ── Meta Description ───────────────────────────────────────────
			case SeoAuditor::ISSUE_META_DESC_MISSING:
				// Use post excerpt OR first 160 chars of stripped content
				$text = $post->post_excerpt
					? wp_strip_all_tags( $post->post_excerpt )
					: wp_strip_all_tags( $post->post_content );
				$desc = self::truncate_at_sentence( $text, SeoAuditor::DESC_MAX );
				if ( mb_strlen( $desc ) >= SeoAuditor::DESC_MIN ) {
					SeoPluginDetector::write_meta( $post_id, '', $desc );
					Logger::log( "SEO auto-fix: set meta description from content for post {$post_id}", 'info', 'seo' );
					return true;
				}
				// Content too short to generate a useful description — needs AI
				return false;

			case SeoAuditor::ISSUE_META_DESC_LONG:
				// Truncate at nearest sentence boundary at 160 chars
				$trimmed = self::truncate_at_sentence( $plugin_data->meta_description, SeoAuditor::DESC_MAX );
				SeoPluginDetector::write_meta( $post_id, '', $trimmed );
				Logger::log( "SEO auto-fix: trimmed long meta description for post {$post_id}", 'info', 'seo' );
				return true;

			case SeoAuditor::ISSUE_META_DESC_SHORT:
				// Try to extend from post content
				$existing = $plugin_data->meta_description;
				$extra    = wp_strip_all_tags( $post->post_content );
				$combined = rtrim( $existing, '.' ) . '. ' . $extra;
				$extended = self::truncate_at_sentence( $combined, SeoAuditor::DESC_MAX );
				if ( mb_strlen( $extended ) >= SeoAuditor::DESC_MIN ) {
					SeoPluginDetector::write_meta( $post_id, '', $extended );
					Logger::log( "SEO auto-fix: extended short meta description for post {$post_id}", 'info', 'seo' );
					return true;
				}
				return false;

			// ── Images Alt Text ────────────────────────────────────────────
			case SeoAuditor::ISSUE_IMAGE_ALT_MISSING:
				$updated = self::patch_image_alts( $post_id, $post->post_content, $plugin_data->focus_keyword );
				if ( $updated ) {
					Logger::log( "SEO auto-fix: patched image alt text for post {$post_id}", 'info', 'seo' );
				}
				return $updated;

			// ── Multiple H1s ───────────────────────────────────────────────
			case SeoAuditor::ISSUE_HEADING_MULTI_H1:
				$fixed   = self::fix_multiple_h1s( $post->post_content );
				if ( $fixed !== $post->post_content ) {
					wp_update_post( [
						'ID'           => $post_id,
						'post_content' => $fixed,
					] );
					Logger::log( "SEO auto-fix: demoted extra H1 headings for post {$post_id}", 'info', 'seo' );
					return true;
				}
				return false;

			// ── Internal Links ─────────────────────────────────────────────
			case SeoAuditor::ISSUE_NO_INTERNAL_LINKS:
				// Delegate to the existing InternalLinker module (no AI)
				if ( class_exists( InternalLinker::class ) ) {
					// InternalLinker works as a content filter; apply it directly to the post
					$new_content = ( new InternalLinker() )->process( $post->post_content, $post_id );
					if ( $new_content && $new_content !== $post->post_content ) {
						wp_update_post( [
							'ID'           => $post_id,
							'post_content' => $new_content,
						] );
						Logger::log( "SEO auto-fix: added internal links for post {$post_id}", 'info', 'seo' );
						return true;
					}
				}
				return false;

			// ── Everything else needs AI or manual action ──────────────────
			default:
				return false;
		}
	}

	// ── AI Fix (explicit, user-triggered only) ────────────────────────────────

	/**
	 * Apply an AI-powered fix for a single issue.
	 *
	 * Only called when the admin explicitly clicks "AI Fix" for a specific issue.
	 *
	 * @param int      $post_id
	 * @param SeoIssue $issue
	 * @param SeoPluginData $plugin_data
	 * @return bool
	 */
	public static function ai_fix( int $post_id, SeoIssue $issue, SeoPluginData $plugin_data ): bool {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$ai      = AiClient::get_instance();
		$keyword = $plugin_data->focus_keyword;
		$excerpt = self::get_content_excerpt( $post->post_content, 500 );

		switch ( $issue->type ) {

			// ── Meta Title (AI) ────────────────────────────────────────────
			case SeoAuditor::ISSUE_META_TITLE_MISSING:
			case SeoAuditor::ISSUE_META_TITLE_SHORT:
			case SeoAuditor::ISSUE_META_TITLE_LONG:
			case SeoAuditor::ISSUE_KW_NOT_IN_TITLE:
				$kw_hint = $keyword ? " The focus keyword is \"{$keyword}\" and should appear near the start." : '';
				$prompt  = "Write a compelling SEO meta title for a blog post. Requirements:\n"
					. "- Between 30 and 60 characters (strictly)\n"
					. "- No quotes, no special formatting, plain text only\n"
					. "- Accurately represents the post\n"
					. "{$kw_hint}\n\n"
					. "Post title: {$post->post_title}\n"
					. "Content excerpt: {$excerpt}\n\n"
					. "Return ONLY the meta title, nothing else.";

				$result = $ai->complete( $prompt, '', [ 'temperature' => 0.4, 'max_tokens' => 80 ] );
				if ( ! is_wp_error( $result ) ) {
					$generated = self::truncate_at_word( trim( $result['content'] ), SeoAuditor::TITLE_MAX );
					SeoPluginDetector::write_meta( $post_id, $generated, '' );
					Logger::log( "SEO AI fix: generated meta title for post {$post_id}", 'info', 'seo' );
					return true;
				}
				Logger::log( 'SEO AI fix: meta title generation failed — ' . $result->get_error_message(), 'error', 'seo' );
				return false;

			// ── Meta Description (AI) ──────────────────────────────────────
			case SeoAuditor::ISSUE_META_DESC_MISSING:
			case SeoAuditor::ISSUE_META_DESC_SHORT:
			case SeoAuditor::ISSUE_META_DESC_LONG:
				$kw_hint = $keyword ? " Include the phrase \"{$keyword}\" naturally." : '';
				$prompt  = "Write an SEO meta description for a blog post. Requirements:\n"
					. "- Between 100 and 160 characters (strictly)\n"
					. "- Compelling, action-oriented, no clickbait\n"
					. "- No quotes, no special formatting, plain text only\n"
					. "{$kw_hint}\n\n"
					. "Post title: {$post->post_title}\n"
					. "Content excerpt: {$excerpt}\n\n"
					. "Return ONLY the meta description, nothing else.";

				$result = $ai->complete( $prompt, '', [ 'temperature' => 0.4, 'max_tokens' => 120 ] );
				if ( ! is_wp_error( $result ) ) {
					$generated = self::truncate_at_sentence( trim( $result['content'] ), SeoAuditor::DESC_MAX );
					SeoPluginDetector::write_meta( $post_id, '', $generated );
					Logger::log( "SEO AI fix: generated meta description for post {$post_id}", 'info', 'seo' );
					return true;
				}
				Logger::log( 'SEO AI fix: meta description generation failed — ' . $result->get_error_message(), 'error', 'seo' );
				return false;

			// ── Image Alt Text (AI) ────────────────────────────────────────
			case SeoAuditor::ISSUE_IMAGE_ALT_MISSING:
				return self::ai_patch_image_alts( $post_id, $post->post_content, $keyword, $ai );

			// ── Heading Hierarchy (AI) ─────────────────────────────────────
			case SeoAuditor::ISSUE_HEADING_NO_H2:
			case SeoAuditor::ISSUE_HEADING_MULTI_H1:
				$prompt = "The following blog post content has heading structure issues. "
					. "Fix the headings so there is exactly one H1 (the main title), "
					. "multiple H2s for main sections, and H3s for sub-sections. "
					. "Return ONLY the fixed HTML content with no explanation.\n\n"
					. "Content:\n" . $post->post_content;

				$result = $ai->complete( $prompt, '', [ 'temperature' => 0.2, 'max_tokens' => 4000 ] );
				if ( ! is_wp_error( $result ) && ! empty( $result['content'] ) ) {
					wp_update_post( [
						'ID'           => $post_id,
						'post_content' => wp_kses_post( $result['content'] ),
					] );
					Logger::log( "SEO AI fix: rewrote heading structure for post {$post_id}", 'info', 'seo' );
					return true;
				}
				return false;

			// ── Focus Keyword in Intro (AI) ────────────────────────────────
			case SeoAuditor::ISSUE_KW_NOT_IN_INTRO:
				if ( empty( $keyword ) ) {
					return false;
				}
				$prompt = "Rewrite only the first paragraph of the following blog post to naturally include "
					. "the phrase \"{$keyword}\" without changing the overall meaning. "
					. "Return ONLY the rewritten first paragraph HTML, nothing else.\n\n"
					. "Content:\n" . $post->post_content;

				$result = $ai->complete( $prompt, '', [ 'temperature' => 0.3, 'max_tokens' => 600 ] );
				if ( ! is_wp_error( $result ) && ! empty( $result['content'] ) ) {
					// Replace only the first <p>...</p> in the content
					$new_content = preg_replace(
						'/<p[\s>].*?<\/p>/is',
						wp_kses_post( $result['content'] ),
						$post->post_content,
						1
					);
					if ( $new_content ) {
						wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
						Logger::log( "SEO AI fix: added keyword to intro paragraph for post {$post_id}", 'info', 'seo' );
						return true;
					}
				}
				return false;

			default:
				return false;
		}
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Patch images missing alt text using deterministic fallbacks.
	 *
	 * Fallback priority:
	 *  1. wp_get_attachment_caption() for WordPress media attachments
	 *  2. Focus keyword + image position ("focus keyword image 1")
	 *  3. Filename slugified ("my-photo-jpg" → "my photo")
	 */
	private static function patch_image_alts( int $post_id, string $content, string $keyword ): bool {
		$position = 0;
		$changed  = false;

		$new_content = preg_replace_callback(
			'/<img(\s[^>]*)>/i',
			function ( $matches ) use ( &$position, $keyword, $post_id, &$changed ) {
				$attrs = $matches[1];

				// Already has non-empty alt — skip
				if ( preg_match( '/\salt=["\']([^"\']+)["\']/', $attrs, $alt_m ) && '' !== trim( $alt_m[1] ) ) {
					return $matches[0];
				}

				$position++;
				$alt = self::derive_alt_for_image( $attrs, $post_id, $keyword, $position );

				$changed = true;

				// Replace or add alt attribute
				if ( preg_match( '/\salt=["\']["\']/', $attrs ) ) {
					$new_attrs = preg_replace( '/\salt=["\']["\']/', ' alt="' . esc_attr( $alt ) . '"', $attrs );
				} else {
					$new_attrs = $attrs . ' alt="' . esc_attr( $alt ) . '"';
				}

				return '<img' . $new_attrs . '>';
			},
			$content
		);

		if ( $changed && $new_content ) {
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
		}

		return $changed;
	}

	/**
	 * Derive a deterministic alt text for an image.
	 */
	private static function derive_alt_for_image( string $attrs, int $post_id, string $keyword, int $position ): string {
		// Try to get WP attachment ID from class="wp-image-{id}"
		if ( preg_match( '/class=["\'][^"\']*wp-image-(\d+)[^"\']*["\']/', $attrs, $cls ) ) {
			$attachment_id = (int) $cls[1];
			$caption       = wp_get_attachment_caption( $attachment_id );
			if ( $caption ) {
				return wp_strip_all_tags( $caption );
			}
			$desc = get_post_field( 'post_excerpt', $attachment_id );
			if ( $desc ) {
				return wp_strip_all_tags( $desc );
			}
		}

		// Try to build from filename
		if ( preg_match( '/\ssrc=["\']([^"\']+)["\']/', $attrs, $src_m ) ) {
			$basename = pathinfo( wp_parse_url( $src_m[1], PHP_URL_PATH ) ?? '', PATHINFO_FILENAME );
			if ( $basename ) {
				// Slugified filename → readable words
				$readable = ucfirst( str_replace( [ '-', '_' ], ' ', $basename ) );
				// Remove common image size suffixes like "-300x200"
				$readable = preg_replace( '/ \d+x\d+$/', '', $readable );
				if ( $readable && strlen( $readable ) > 3 ) {
					return $readable;
				}
			}
		}

		// Final fallback: keyword-based
		$post_title = get_the_title( $post_id );
		if ( $keyword ) {
			return $position > 1
				? ucfirst( $keyword ) . ' - ' . $post_title
				: ucfirst( $keyword );
		}

		return $post_title;
	}

	/**
	 * AI-powered image alt text patching.
	 */
	private static function ai_patch_image_alts( int $post_id, string $content, string $keyword, AiClient $ai ): bool {
		$position = 0;
		$changed  = false;
		$post     = get_post( $post_id );

		$new_content = preg_replace_callback(
			'/<img(\s[^>]*)>/i',
			function ( $matches ) use ( &$position, $keyword, $post, $ai, &$changed ) {
				$attrs = $matches[1];

				if ( preg_match( '/\salt=["\']([^"\']+)["\']/', $attrs, $alt_m ) && '' !== trim( $alt_m[1] ) ) {
					return $matches[0];
				}

				$position++;
				$kw_hint = $keyword ? " The post is about \"{$keyword}\"." : '';
				$prompt  = "Write a concise, descriptive alt text for an image (max 125 characters). "
					. "The image is the image #{$position} in a blog post titled \"{$post->post_title}\".{$kw_hint} "
					. "Return ONLY the alt text, no quotes.";

				$result = $ai->complete( $prompt, '', [ 'temperature' => 0.3, 'max_tokens' => 50 ] );
				$alt    = ! is_wp_error( $result ) && ! empty( $result['content'] )
					? substr( trim( $result['content'] ), 0, 125 )
					: self::derive_alt_for_image( $attrs, $post->ID, $keyword, $position );

				$changed = true;

				if ( preg_match( '/\salt=["\']["\']/', $attrs ) ) {
					$new_attrs = preg_replace( '/\salt=["\']["\']/', ' alt="' . esc_attr( $alt ) . '"', $attrs );
				} else {
					$new_attrs = $attrs . ' alt="' . esc_attr( $alt ) . '"';
				}

				return '<img' . $new_attrs . '>';
			},
			$content
		);

		if ( $changed && $new_content ) {
			wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ] );
		}

		return $changed;
	}

	/**
	 * Demote all H1 tags after the first one to H2.
	 */
	private static function fix_multiple_h1s( string $content ): string {
		$first_found = false;
		return preg_replace_callback(
			'/<(h1)(\s[^>]*)?>.*?<\/h1>/is',
			function ( $m ) use ( &$first_found ) {
				if ( ! $first_found ) {
					$first_found = true;
					return $m[0]; // Keep the first H1 intact
				}
				// Demote subsequent H1s to H2
				return preg_replace( '/<h1([\s>])/i', '<h2$1',
					preg_replace( '/<\/h1>/i', '</h2>', $m[0] )
				);
			},
			$content
		);
	}

	/**
	 * Truncate at the nearest word boundary without exceeding $max characters.
	 */
	private static function truncate_at_word( string $text, int $max ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$truncated = mb_substr( $text, 0, $max );
		$last_space = mb_strrpos( $truncated, ' ' );
		return $last_space ? mb_substr( $truncated, 0, $last_space ) : $truncated;
	}

	/**
	 * Truncate at the nearest sentence boundary without exceeding $max characters.
	 * Falls back to word boundary if no sentence break is found.
	 */
	private static function truncate_at_sentence( string $text, int $max ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$fragment = mb_substr( $text, 0, $max );
		// Find last sentence-ending punctuation
		$last_end = max(
			(int) mb_strrpos( $fragment, '. ' ),
			(int) mb_strrpos( $fragment, '! ' ),
			(int) mb_strrpos( $fragment, '? ' )
		);
		if ( $last_end > $max / 2 ) {
			return rtrim( mb_substr( $fragment, 0, $last_end + 1 ) );
		}
		return self::truncate_at_word( $text, $max );
	}

	/**
	 * Get a plain-text excerpt from post content for AI prompts.
	 */
	private static function get_content_excerpt( string $html, int $chars ): string {
		$text = wp_strip_all_tags( $html );
		return mb_strlen( $text ) > $chars ? mb_substr( $text, 0, $chars ) . '…' : $text;
	}
}
