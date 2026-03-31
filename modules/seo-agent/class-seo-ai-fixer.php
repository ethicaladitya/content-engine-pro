<?php
namespace ContentEnginePro\Seo;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI-powered SEO fixer.
 *
 * Handles the three AI fix types triggered from the admin UI:
 *   - 'title'            → rewrite SEO title
 *   - 'meta_description' → generate meta description
 *   - 'content'          → expand thin content
 */
class SeoAiFixer {

	/**
	 * Master dispatcher for AI fix AJAX handler.
	 *
	 * @param int    $post_id  Post to fix.
	 * @param string $fix_type 'title' | 'meta_description' | 'content'
	 * @return string|\WP_Error  Result message or error.
	 */
	public static function apply_ai_fix( int $post_id, string $fix_type ): string|\WP_Error {
		switch ( $fix_type ) {
			case 'title':
				return self::rewrite_title( $post_id );

			case 'meta_description':
				return self::generate_meta_description( $post_id );

			case 'content':
				return self::expand_content( $post_id );

			default:
				return new \WP_Error( 'invalid_fix_type', "Unknown AI fix type: {$fix_type}" );
		}
	}

	/**
	 * Rewrite the SEO title using AI.
	 *
	 * @param int $post_id
	 * @return string|\WP_Error  New title or error.
	 */
	public static function rewrite_title( int $post_id ): string|\WP_Error {
		$ctx    = self::get_post_context( $post_id );
		$max    = (int) Settings::get( 'seo_title_max_length', 60 );
		$prompt = "Write an SEO-optimised title for the following article.\n"
				. "Requirements:\n"
				. "- Under {$max} characters\n"
				. "- Compelling and click-worthy\n"
				. "- Include the main keyword naturally\n"
				. "- Return ONLY the title text, no quotes, no explanation\n\n"
				. "Article title: {$ctx['title']}\n"
				. "Summary: {$ctx['excerpt']}\n"
				. ( $ctx['focus_kw'] ? "Primary keyword: {$ctx['focus_kw']}\n" : '' );

		$result = cep_get_ai_client()->complete( $prompt, 'You are an expert SEO copywriter. Output only the requested text.', [
			'max_tokens'  => 80,
			'temperature' => 0.5,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$title = trim( (string) ( $result['content'] ?? '' ) );
		if ( '' === $title ) {
			return new \WP_Error( 'empty_response', 'AI returned an empty title.' );
		}

		// Strip surrounding quotes if AI added them
		$title = trim( $title, '"\'""' );

		SeoIntegrations::write_title( $post_id, $title );
		cep_log( "SEO AI: rewrote title for post {$post_id}: \"{$title}\"", 'info', 'seo_agent' );

		return "AI rewrote SEO title: \"{$title}\"";
	}

	/**
	 * Generate a meta description using AI.
	 *
	 * @param int $post_id
	 * @return string|\WP_Error  Result message or error.
	 */
	public static function generate_meta_description( int $post_id ): string|\WP_Error {
		$ctx    = self::get_post_context( $post_id );
		$min    = (int) Settings::get( 'seo_meta_desc_min_length', 100 );
		$max    = (int) Settings::get( 'seo_meta_desc_max_length', 160 );
		$prompt = "Write an SEO meta description for the following article.\n"
				. "Requirements:\n"
				. "- Between {$min} and {$max} characters\n"
				. "- Compelling, accurate, and informative\n"
				. "- Include the main keyword naturally\n"
				. "- Return ONLY the meta description text, no quotes, no explanation\n\n"
				. "Article title: {$ctx['title']}\n"
				. "Summary: {$ctx['excerpt']}\n"
				. ( $ctx['focus_kw'] ? "Primary keyword: {$ctx['focus_kw']}\n" : '' );

		$result = cep_get_ai_client()->complete( $prompt, 'You are an expert SEO copywriter. Output only the requested text.', [
			'max_tokens'  => 200,
			'temperature' => 0.5,
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$desc = trim( (string) ( $result['content'] ?? '' ) );
		if ( '' === $desc ) {
			return new \WP_Error( 'empty_response', 'AI returned an empty meta description.' );
		}

		$desc = trim( $desc, '"\'""' );

		SeoIntegrations::write_description( $post_id, $desc );
		cep_log( "SEO AI: generated meta description for post {$post_id}", 'info', 'seo_agent' );

		return 'AI generated meta description (' . mb_strlen( $desc ) . ' chars).';
	}

	/**
	 * Expand thin content using AI (adds new H2 sections).
	 *
	 * @param int $post_id
	 * @return string|\WP_Error  Result message or error.
	 */
	public static function expand_content( int $post_id ): string|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', 'Post not found.' );
		}

		$ctx             = self::get_post_context( $post_id );
		$existing_plain  = mb_substr( wp_strip_all_tags( $post->post_content ), 0, 3000 );

		$prompt = "The following article is too short and needs more depth.\n"
				. "Add 2–3 new sections with H2 headings that expand on the existing topics.\n"
				. "Each section should be 150–250 words.\n"
				. "Return ONLY the new HTML sections (h2 + paragraphs) to append, nothing else.\n"
				. "Do not repeat the existing content. Do not include doctype, body tags, or preamble.\n\n"
				. "Article title: {$ctx['title']}\n"
				. ( $ctx['focus_kw'] ? "Primary keyword: {$ctx['focus_kw']}\n" : '' )
				. "\nExisting content (plain text):\n{$existing_plain}";

		$result = cep_get_ai_client()->complete(
			$prompt,
			'You are an expert content writer. Output only clean HTML (h2 and p tags).',
			[
				'max_tokens'  => 1200,
				'temperature' => 0.65,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$new_sections = trim( (string) ( $result['content'] ?? '' ) );
		if ( '' === $new_sections ) {
			return new \WP_Error( 'empty_response', 'AI returned empty content.' );
		}

		// Strip any markdown code fences AI might include
		$new_sections = (string) preg_replace( '/^```(?:html)?\s*/i', '', $new_sections );
		$new_sections = (string) preg_replace( '/\s*```$/', '', trim( $new_sections ) );

		$updated_content = $post->post_content . "\n\n" . $new_sections;

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $updated_content,
		] );

		$words_added = str_word_count( wp_strip_all_tags( $new_sections ) );
		cep_log( "SEO AI: expanded content for post {$post_id} (+{$words_added} words)", 'info', 'seo_agent' );

		return "AI expanded content by ~{$words_added} words.";
	}

	// ── Private helpers ─────────────────────────────────────────────────────

	/**
	 * Build the post context array used in all prompts.
	 *
	 * @param int $post_id
	 * @return array{title:string, excerpt:string, focus_kw:string, url:string}
	 */
	private static function get_post_context( int $post_id ): array {
		$post     = get_post( $post_id );
		$focus_kw = SeoIntegrations::get_focus_keyword( $post_id );

		return [
			'title'    => $post ? $post->post_title : '',
			'excerpt'  => $post ? wp_trim_words( wp_strip_all_tags( $post->post_content ), 60 ) : '',
			'focus_kw' => $focus_kw,
			'url'      => (string) get_permalink( $post_id ),
		];
	}
}
