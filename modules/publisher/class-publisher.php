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

		Logger::log( "Published post #{$post_id}: {$data['title']}", 'info', 'publisher' );
		do_action( 'cep_after_publish', $post_id, $raw_content );

		return $post_id;
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
