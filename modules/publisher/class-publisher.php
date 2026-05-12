<?php
namespace ContentEnginePro\Publisher;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes AI-generated content as WordPress posts.
 */
class Publisher {

	/**
	 * Generate and publish a post from a raw content item.
	 *
	 * @param array $raw_content  Row from cep_raw_content table.
	 * @return int|WP_Error  New post ID or error.
	 */
	public static function publish_from_raw( array $raw_content ) {
		if ( ! Settings::is_enabled( 'enable_ai_publishing' ) ) {
			return new \WP_Error( 'cep_ai_disabled', 'AI publishing is disabled.' );
		}

		$ai = AiClient::get_instance();

		do_action( 'cep_before_publish', $raw_content );

		// Build prompt
		$prompt = self::build_prompt( $raw_content );

		$result = $ai->complete( $prompt, '', [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => 0.6,
		] );

		if ( is_wp_error( $result ) ) {
			Logger::log( 'AI generation failed: ' . $result->get_error_message(), 'error', 'publisher' );
			return $result;
		}

		$data = json_decode( $result['content'], true );

		if ( ! isset( $data['title'], $data['content'] ) ) {
			return new \WP_Error( 'cep_bad_response', 'AI response did not include title or content.' );
		}

		$post_type  = Settings::get( 'primary_cpt_slug', 'post' );
		$author_id  = (int) Settings::get( 'default_author_id', 1 );
		$status     = Settings::is_enabled( 'auto_publish' ) ? 'publish' : 'draft';

		$post_id = wp_insert_post( apply_filters( 'cep_post_insert_args', [
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_excerpt' => sanitize_textarea_field( $data['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => $post_type,
			'post_author'  => $author_id,
		] ) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta
		update_post_meta( $post_id, '_cep_source_url', esc_url_raw( $raw_content['canonical_url'] ?? '' ) );
		update_post_meta( $post_id, '_cep_source_name', sanitize_text_field( $raw_content['source_name'] ?? '' ) );
		update_post_meta( $post_id, '_cep_signal_score', (float) $raw_content['score'] );
		update_post_meta( $post_id, '_cep_ai_model', sanitize_text_field( $result['model'] ?? Settings::get( 'ai_model' ) ) );
		update_post_meta( $post_id, '_cep_word_count', str_word_count( wp_strip_all_tags( $data['content'] ) ) );

		// Assign category
		if ( ! empty( $data['category'] ) ) {
			$tax   = Settings::get( 'primary_tax_slug', 'article-category' );
			$term  = get_term_by( 'name', $data['category'], $tax );
			if ( ! $term ) {
				$term_result = wp_insert_term( sanitize_text_field( $data['category'] ), $tax );
				$term_id = ! is_wp_error( $term_result ) ? $term_result['term_id'] : 0;
			} else {
				$term_id = $term->term_id;
			}
			if ( $term_id ) {
				wp_set_post_terms( $post_id, [ $term_id ], $tax );
			}
		}

		// Mark raw content as published
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'cep_raw_content',
			[ 'status' => 'published' ],
			[ 'id' => $raw_content['id'] ],
			[ '%s' ],
			[ '%d' ]
		);

		// Write SEO meta into any active SEO plugin so it controls title/desc output.
		self::write_seo_meta( $post_id, $data, $raw_content );

		Logger::log( "Published post #{$post_id}: {$data['title']}", 'info', 'publisher' );
		do_action( 'cep_after_publish', $post_id, $raw_content );

		return $post_id;
	}


	/**
	 * Write SEO title, description, and focus keyword into whichever SEO plugin is active.
	 * Called immediately after a post is published so the SEO plugin controls all output.
	 *
	 * @param int   $post_id
	 * @param array $data        AI response data (title, excerpt, category).
	 * @param array $raw_content Source item (title used as initial keyword).
	 */
	private static function write_seo_meta( int $post_id, array $data, array $raw_content ): void {
		$title   = sanitize_text_field( $data['title']    ?? '' );
		$desc    = sanitize_textarea_field( $data['excerpt'] ?? '' );
		$keyword = sanitize_text_field( $raw_content['title'] ?? $data['category'] ?? '' );

		// SmartCrawl (WPMU DEV)
		if ( defined( 'SMARTCRAWL_VERSION' )
			|| class_exists( 'SmartCrawl_Settings', false )
			|| class_exists( 'Smartcrawl\Smartcrawl', false ) ) {
			update_post_meta( $post_id, '_wds_title',          $title );
			update_post_meta( $post_id, '_wds_metadesc',       $desc );
			update_post_meta( $post_id, '_wds_focus-keywords', $keyword );
		}

		// Yoast SEO
		if ( defined( 'WPSEO_VERSION' ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_title',    $title );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $desc );
			update_post_meta( $post_id, '_yoast_wpseo_focuskw',  $keyword );
		}

		// RankMath
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			update_post_meta( $post_id, 'rank_math_title',         $title );
			update_post_meta( $post_id, 'rank_math_description',   $desc );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );
		}

		// All in One SEO
		if ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			update_post_meta( $post_id, '_aioseo_title',       $title );
			update_post_meta( $post_id, '_aioseo_description', $desc );
			update_post_meta( $post_id, '_aioseo_keywords',    $keyword );
		}

		// SEOPress
		if ( defined( 'SEOPRESS_VERSION' ) || class_exists( 'SeoPress_Admin_Pages', false ) ) {
			update_post_meta( $post_id, '_seopress_titles_title',        $title );
			update_post_meta( $post_id, '_seopress_titles_desc',         $desc );
			update_post_meta( $post_id, '_seopress_analysis_target_kw', $keyword );
		}

		// The SEO Framework
		if ( function_exists( 'the_seo_framework' ) || class_exists( 'The_SEO_Framework\\Load', false ) ) {
			update_post_meta( $post_id, '_genesis_title',       $title );
			update_post_meta( $post_id, '_genesis_description', $desc );
		}
	}

	private static function build_prompt( array $raw_content ): string {
		$category  = $raw_content['category'] ?? 'general';
		$title     = $raw_content['title'] ?? '';
		$text      = $raw_content['clean_text'] ?? $raw_content['excerpt'] ?? '';
		$source    = $raw_content['canonical_url'] ?? '';
		$brand     = Settings::get( 'brand_name', 'our publication' );

		return apply_filters( 'cep_publish_prompt', <<<PROMPT
You are a professional content writer for {$brand}.

Based on the following source material, write a comprehensive, well-structured article.

Source Title: {$title}
Source URL: {$source}
Category: {$category}
Source Text:
{$text}

Return a JSON object with:
{
  "title": "compelling article title",
  "content": "full HTML article content with proper paragraphs, headings (h2/h3), and structure",
  "excerpt": "2-3 sentence summary",
  "category": "most relevant category from the source"
}

Requirements:
- Write in an authoritative, journalistic tone
- Use proper HTML formatting
- Include a Sources section at the end with the original URL
- Do NOT fabricate facts
- 600-1200 words
PROMPT
		, $raw_content );
	}
}
