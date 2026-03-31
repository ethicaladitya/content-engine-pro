<?php
namespace ContentEnginePro\Seo;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure SEO analysis engine.
 *
 * Runs a series of rule-based checks on a post and returns an array of
 * issue descriptors ready for DB insertion. No side-effects.
 */
class SeoAnalyzer {

	/**
	 * Analyse a post for SEO issues.
	 *
	 * @param int $post_id
	 * @return array  Array of issue arrays (each matching cep_seo_issues columns).
	 */
	public static function analyze( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return [];
		}

		$s = [
			'title_min'    => (int) Settings::get( 'seo_title_min_length', 30 ),
			'title_max'    => (int) Settings::get( 'seo_title_max_length', 60 ),
			'desc_min'     => (int) Settings::get( 'seo_meta_desc_min_length', 100 ),
			'desc_max'     => (int) Settings::get( 'seo_meta_desc_max_length', 160 ),
			'min_words'    => (int) Settings::get( 'seo_min_word_count', 300 ),
			'slug_max'     => (int) Settings::get( 'seo_slug_max_length', 75 ),
		];

		$issues = [];

		$checks = [
			self::check_title( $post, $post_id, $s ),
			self::check_meta_description( $post_id, $post, $s ),
			self::check_thin_content( $post, $s ),
			self::check_h2_headings( $post ),
			self::check_multiple_h1( $post ),
			self::check_featured_image( $post_id ),
			self::check_slug_length( $post, $s ),
			self::check_internal_links( $post ),
			self::check_outbound_links( $post ),
		];

		// Image alt checks return an array of issues (one per offending img)
		$alt_issues = self::check_image_alt_text( $post );

		foreach ( $checks as $issue ) {
			if ( null !== $issue ) {
				$issues[] = $issue;
			}
		}

		foreach ( $alt_issues as $issue ) {
			$issues[] = $issue;
		}

		return $issues;
	}

	// ── Individual checks ───────────────────────────────────────────────────

	private static function check_title( \WP_Post $post, int $post_id, array $s ): ?array {
		$title  = SeoIntegrations::get_title( $post_id, $post->post_title );
		$length = mb_strlen( $title );

		if ( '' === $title ) {
			return self::issue( $post, 'title_missing', 'critical', 'Post has no SEO title.', 0 );
		}
		if ( $length < $s['title_min'] ) {
			return self::issue( $post, 'title_too_short', 'warning',
				"SEO title is too short ({$length} chars; min {$s['title_min']}).", 0 );
		}
		if ( $length > $s['title_max'] ) {
			return self::issue( $post, 'title_too_long', 'warning',
				"SEO title is too long ({$length} chars; max {$s['title_max']}).", 1 );
		}
		return null;
	}

	private static function check_meta_description( int $post_id, \WP_Post $post, array $s ): ?array {
		$desc   = SeoIntegrations::get_description( $post_id );
		$length = mb_strlen( $desc );

		if ( '' === $desc ) {
			return self::issue( $post, 'meta_desc_missing', 'critical', 'Post has no meta description.', 1 );
		}
		if ( $length < $s['desc_min'] ) {
			return self::issue( $post, 'meta_desc_too_short', 'warning',
				"Meta description is too short ({$length} chars; min {$s['desc_min']}).", 0 );
		}
		if ( $length > $s['desc_max'] ) {
			return self::issue( $post, 'meta_desc_too_long', 'warning',
				"Meta description is too long ({$length} chars; max {$s['desc_max']}).", 1 );
		}
		return null;
	}

	private static function check_thin_content( \WP_Post $post, array $s ): ?array {
		$words = self::word_count( $post->post_content );
		if ( $words < $s['min_words'] ) {
			return self::issue( $post, 'thin_content', 'critical',
				"Content is too thin ({$words} words; min {$s['min_words']}).", 0 );
		}
		return null;
	}

	private static function check_h2_headings( \WP_Post $post ): ?array {
		if ( '' === trim( $post->post_content ) ) {
			return null;
		}
		if ( ! preg_match( '/<h2[\s>]/i', $post->post_content ) ) {
			return self::issue( $post, 'no_h2', 'warning', 'Content has no H2 headings.', 0 );
		}
		return null;
	}

	private static function check_multiple_h1( \WP_Post $post ): ?array {
		$count = preg_match_all( '/<h1[\s>]/i', $post->post_content );
		if ( $count > 1 ) {
			return self::issue( $post, 'multiple_h1', 'warning',
				"Content has {$count} H1 tags; only one is recommended.", 1 );
		}
		return null;
	}

	/**
	 * Find <img> tags without a meaningful alt attribute.
	 *
	 * @return array  Array of issue arrays (may be empty).
	 */
	private static function check_image_alt_text( \WP_Post $post ): array {
		$issues = [];

		if ( '' === trim( $post->post_content ) ) {
			return $issues;
		}

		preg_match_all( '/<img[^>]+>/i', $post->post_content, $matches );
		foreach ( $matches[0] as $img_tag ) {
			if ( preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/', $img_tag, $alt_match ) ) {
				if ( '' !== trim( $alt_match[1] ) ) {
					continue; // has meaningful alt — OK
				}
			}
			// Missing alt attribute or empty alt
			$issues[] = self::issue( $post, 'image_missing_alt', 'warning',
				'One or more images are missing alt text.', 1 );
			break; // Report once per post (fixer handles all at once)
		}

		return $issues;
	}

	private static function check_featured_image( int $post_id ): ?array {
		if ( ! has_post_thumbnail( $post_id ) ) {
			return self::issue_by_id( $post_id, get_post_type( $post_id ), 'missing_featured_image', 'info',
				'Post has no featured image.', 0 );
		}
		return null;
	}

	private static function check_slug_length( \WP_Post $post, array $s ): ?array {
		$slug   = $post->post_name;
		$length = mb_strlen( $slug );
		if ( $length > $s['slug_max'] ) {
			return self::issue( $post, 'slug_too_long', 'warning',
				"URL slug is too long ({$length} chars; max {$s['slug_max']}).", 1 );
		}
		return null;
	}

	private static function check_internal_links( \WP_Post $post ): ?array {
		$site_url = home_url();
		$domain   = (string) wp_parse_url( $site_url, PHP_URL_HOST );

		if ( '' === trim( $post->post_content ) ) {
			return null;
		}

		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches );
		foreach ( $matches[1] as $href ) {
			if ( false !== strpos( $href, $domain ) || '/' === substr( $href, 0, 1 ) ) {
				return null; // Found at least one internal link
			}
		}

		return self::issue( $post, 'no_internal_links', 'warning', 'Content has no internal links.', 0 );
	}

	private static function check_outbound_links( \WP_Post $post ): ?array {
		$site_url = home_url();
		$domain   = (string) wp_parse_url( $site_url, PHP_URL_HOST );

		if ( '' === trim( $post->post_content ) ) {
			return null;
		}

		preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches );
		foreach ( $matches[1] as $href ) {
			if ( 0 === strpos( $href, 'http' ) && false === strpos( $href, $domain ) ) {
				return null; // Found at least one outbound link
			}
		}

		return self::issue( $post, 'no_outbound_links', 'info', 'Content has no outbound links.', 0 );
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	private static function word_count( string $content ): int {
		$text = wp_strip_all_tags( $content );
		return str_word_count( $text );
	}

	/**
	 * Build an issue array from a WP_Post object.
	 */
	private static function issue(
		\WP_Post $post,
		string $issue_type,
		string $severity,
		string $description,
		int $auto_fixable
	): array {
		return [
			'post_id'      => $post->ID,
			'post_type'    => $post->post_type,
			'issue_type'   => $issue_type,
			'severity'     => $severity,
			'description'  => $description,
			'auto_fixable' => $auto_fixable,
			'status'       => 'open',
		];
	}

	/**
	 * Build an issue array from individual values (when WP_Post is not available).
	 */
	private static function issue_by_id(
		int $post_id,
		string $post_type,
		string $issue_type,
		string $severity,
		string $description,
		int $auto_fixable
	): array {
		return [
			'post_id'      => $post_id,
			'post_type'    => $post_type,
			'issue_type'   => $issue_type,
			'severity'     => $severity,
			'description'  => $description,
			'auto_fixable' => $auto_fixable,
			'status'       => 'open',
		];
	}
}
