<?php
namespace ContentEnginePro\Seo;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rule-based SEO auto-fixer. No AI.
 *
 * Each public fix method returns a human-readable description of what was
 * applied (string), or false if the fix could not be applied.
 */
class SeoFixer {

	/** Stop-words stripped when truncating slugs. */
	private static array $stop_words = [
		'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to',
		'for', 'of', 'with', 'by', 'from', 'is', 'was', 'are', 'be',
	];

	/**
	 * Dispatcher: apply the correct fix for an issue row from the DB.
	 *
	 * @param array $issue  Row from cep_seo_issues (ARRAY_A).
	 * @return string|false  Description of what was done, or false on failure.
	 */
	public static function apply_fix( array $issue ): string|false {
		$post_id    = (int) $issue['post_id'];
		$issue_type = (string) $issue['issue_type'];

		switch ( $issue_type ) {
			case 'title_too_long':
				return self::fix_title_too_long( $post_id );

			case 'meta_desc_missing':
				return self::fix_meta_desc_missing( $post_id );

			case 'meta_desc_too_long':
				return self::fix_meta_desc_too_long( $post_id );

			case 'image_missing_alt':
				return self::fix_image_alt_text( $post_id );

			case 'multiple_h1':
				return self::fix_multiple_h1( $post_id );

			case 'slug_too_long':
				return self::fix_slug_too_long( $post_id );
		}

		return false;
	}

	// ── Individual fixers ───────────────────────────────────────────────────

	/**
	 * Truncate an overly long SEO title at a word boundary.
	 */
	public static function fix_title_too_long( int $post_id ): string|false {
		$post  = get_post( $post_id );
		$max   = (int) Settings::get( 'seo_title_max_length', 60 );
		$title = SeoIntegrations::get_title( $post_id, $post ? $post->post_title : '' );

		if ( mb_strlen( $title ) <= $max ) {
			return false; // Already within limit
		}

		$truncated = self::truncate_at_word( $title, $max );
		SeoIntegrations::write_title( $post_id, $truncated );

		return "Truncated SEO title to {$max} chars: \"{$truncated}\"";
	}

	/**
	 * Generate a meta description from excerpt or content and write it.
	 */
	public static function fix_meta_desc_missing( int $post_id ): string|false {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$max = (int) Settings::get( 'seo_meta_desc_max_length', 160 );

		// Prefer post_excerpt if set
		if ( ! empty( $post->post_excerpt ) ) {
			$desc = wp_strip_all_tags( $post->post_excerpt );
		} else {
			$desc = wp_strip_all_tags( $post->post_content );
		}

		$desc = self::truncate_at_word( $desc, $max );
		if ( '' === $desc ) {
			return false;
		}

		SeoIntegrations::write_description( $post_id, $desc );
		return "Generated meta description ({$max} chars) from post content.";
	}

	/**
	 * Truncate an overly long meta description.
	 */
	public static function fix_meta_desc_too_long( int $post_id ): string|false {
		$max  = (int) Settings::get( 'seo_meta_desc_max_length', 160 );
		$desc = SeoIntegrations::get_description( $post_id );

		if ( mb_strlen( $desc ) <= $max ) {
			return false;
		}

		$truncated = self::truncate_at_word( $desc, $max );
		SeoIntegrations::write_description( $post_id, $truncated );

		return "Truncated meta description to {$max} chars.";
	}

	/**
	 * Set alt text on all <img> tags that are missing it.
	 */
	public static function fix_image_alt_text( int $post_id ): string|false {
		$post = get_post( $post_id );
		if ( ! $post || '' === trim( $post->post_content ) ) {
			return false;
		}

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $post->post_content . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$imgs  = $doc->getElementsByTagName( 'img' );
		$count = 0;

		foreach ( $imgs as $img ) {
			$alt = trim( (string) $img->getAttribute( 'alt' ) );
			if ( '' !== $alt ) {
				continue;
			}

			$src      = (string) $img->getAttribute( 'src' );
			$filename = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			$new_alt  = self::clean_filename_for_alt( $filename );

			if ( '' === $new_alt ) {
				$new_alt = $post->post_title;
			}

			$img->setAttribute( 'alt', $new_alt );
			$count++;
		}

		if ( 0 === $count ) {
			return false;
		}

		// Extract only the <body> inner HTML
		$body    = $doc->getElementsByTagName( 'body' )->item( 0 );
		$content = '';
		foreach ( $body->childNodes as $child ) {
			$content .= $doc->saveHTML( $child );
		}

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $content,
		] );

		return "Added alt text to {$count} image(s).";
	}

	/**
	 * Convert extra H1 tags (after the first) to H2.
	 */
	public static function fix_multiple_h1( int $post_id ): string|false {
		$post = get_post( $post_id );
		if ( ! $post || '' === trim( $post->post_content ) ) {
			return false;
		}

		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML(
			'<html><head><meta charset="UTF-8"></head><body>' . $post->post_content . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();

		$h1s   = $doc->getElementsByTagName( 'h1' );
		$count = $h1s->length;

		if ( $count <= 1 ) {
			return false;
		}

		// Collect nodes to change (can't modify live NodeList while iterating)
		$to_change = [];
		for ( $i = 1; $i < $count; $i++ ) {
			$to_change[] = $h1s->item( $i );
		}

		foreach ( $to_change as $h1 ) {
			$h2 = $doc->createElement( 'h2' );
			// Copy child nodes
			while ( $h1->firstChild ) {
				$h2->appendChild( $h1->firstChild );
			}
			// Copy attributes
			foreach ( $h1->attributes as $attr ) {
				$h2->setAttribute( $attr->nodeName, $attr->nodeValue );
			}
			$h1->parentNode->replaceChild( $h2, $h1 );
		}

		$body    = $doc->getElementsByTagName( 'body' )->item( 0 );
		$content = '';
		foreach ( $body->childNodes as $child ) {
			$content .= $doc->saveHTML( $child );
		}

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $content,
		] );

		$changed = count( $to_change );
		return "Converted {$changed} extra H1 tag(s) to H2.";
	}

	/**
	 * Shorten the post slug by removing stop-words and keeping the first 6 meaningful words.
	 */
	public static function fix_slug_too_long( int $post_id ): string|false {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$max  = (int) Settings::get( 'seo_slug_max_length', 75 );
		$slug = $post->post_name;

		if ( mb_strlen( $slug ) <= $max ) {
			return false;
		}

		$new_slug = self::truncate_slug( $slug );
		if ( $new_slug === $slug ) {
			return false;
		}

		wp_update_post( [
			'ID'        => $post_id,
			'post_name' => $new_slug,
		] );

		return "Shortened slug from \"{$slug}\" to \"{$new_slug}\".";
	}

	// ── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Truncate a string at a word boundary, not exceeding $max chars.
	 */
	private static function truncate_at_word( string $text, int $max ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$truncated = mb_substr( $text, 0, $max );
		$last_space = mb_strrpos( $truncated, ' ' );

		if ( false !== $last_space ) {
			$truncated = mb_substr( $truncated, 0, $last_space );
		}

		return rtrim( $truncated, '.,;: ' );
	}

	/**
	 * Clean a filename for use as image alt text.
	 * e.g. "my-awesome_image.jpg" → "My Awesome Image"
	 */
	private static function clean_filename_for_alt( string $filename ): string {
		// Strip query string and extension
		$filename = (string) strtok( $filename, '?' );
		$filename = (string) preg_replace( '/\.[^.]+$/', '', $filename );
		// Replace hyphens and underscores with spaces
		$filename = (string) str_replace( [ '-', '_' ], ' ', $filename );
		// Strip numbers-only segments (e.g. WordPress image IDs)
		$filename = (string) preg_replace( '/\b\d+\b/', '', $filename );
		$filename = (string) preg_replace( '/\s+/', ' ', $filename );
		return ucwords( trim( $filename ) );
	}

	/**
	 * Strip stop-words from a slug and keep the first 6 meaningful words.
	 */
	private static function truncate_slug( string $slug, int $max_words = 6 ): string {
		$words = explode( '-', $slug );
		$meaningful = array_filter( $words, fn( $w ) => ! in_array( strtolower( $w ), self::$stop_words, true ) );
		$meaningful = array_values( $meaningful );
		$kept       = array_slice( $meaningful, 0, $max_words );
		return sanitize_title( implode( '-', $kept ) );
	}
}
