<?php
namespace ContentEnginePro\Reviews;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages automated review generation lifecycle:
 * discover → generate → publish → update
 */
class ReviewManager {

	public static function run_discovery(): void {
		if ( ! Settings::is_enabled( 'enable_reviews_autopilot' ) ) {
			return;
		}
		Logger::log( 'Running review discovery', 'info', 'reviews' );
		do_action( 'cep_reviews_run_discovery' );
	}

	public static function run_generation(): void {
		if ( ! Settings::is_enabled( 'enable_reviews_autopilot' ) ) {
			return;
		}

		global $wpdb;
		$max    = (int) Settings::get( 'reviews_max_per_run', 3 );
		$table  = $wpdb->prefix . 'cep_reviews';

		// Find reviews that need to be generated (have wp_post_id = 0)
		$pending = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE wp_post_id = 0 AND ai_generated = 0 LIMIT %d",
				$max
			),
			ARRAY_A
		);

		if ( empty( $pending ) ) {
			Logger::log( 'No reviews pending generation', 'info', 'reviews' );
			return;
		}

		foreach ( $pending as $review ) {
			self::generate_review( $review );
		}
	}

	public static function run_updates(): void {
		if ( ! Settings::is_enabled( 'enable_reviews_autopilot' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cep_reviews';

		// Reviews that haven't been verified in > 30 days
		$stale = $wpdb->get_results(
			"SELECT * FROM $table WHERE wp_post_id > 0 AND auto_update = 1
			AND (last_verified_at IS NULL OR last_verified_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY))
			LIMIT 5",
			ARRAY_A
		);

		foreach ( $stale as $review ) {
			self::update_review( $review );
		}
	}

	/**
	 * Add a product to the review queue.
	 */
	public static function queue_product( array $product ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_reviews';

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $table WHERE product_slug = %s",
			sanitize_title( $product['name'] )
		) );

		if ( $exists ) {
			return false;
		}

		$inserted = $wpdb->insert(
			$table,
			[
				'product_name' => sanitize_text_field( $product['name'] ),
				'product_slug' => sanitize_title( $product['name'] ),
				'product_url'  => esc_url_raw( $product['url'] ?? '' ),
				'review_type'  => sanitize_key( $product['type'] ?? 'plugin' ),
				'ai_generated' => 0,
				'auto_update'  => 1,
				'created_at'   => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			],
			[ '%s','%s','%s','%s','%d','%d','%s','%s' ]
		);

		return (bool) $inserted;
	}

	private static function generate_review( array $review ): void {
		global $wpdb;

		$ai = AiClient::get_instance();

		$prompt = self::build_review_prompt( $review );

		$result = $ai->complete( $prompt, '', [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => (float) Settings::get( 'reviews_ai_temperature', 0.7 ),
		] );

		if ( is_wp_error( $result ) ) {
			Logger::log( "Review generation failed for {$review['product_name']}: " . $result->get_error_message(), 'error', 'reviews' );
			return;
		}

		$data = json_decode( $result['content'], true );

		if ( ! isset( $data['title'], $data['content'], $data['rating'] ) ) {
			Logger::log( "Invalid AI response for {$review['product_name']}", 'error', 'reviews' );
			return;
		}

		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
		$author_id   = (int) Settings::get( 'default_author_id', 1 );
		$status      = Settings::is_enabled( 'auto_publish' ) ? 'publish' : 'draft';

		$post_id = wp_insert_post( [
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_excerpt' => sanitize_textarea_field( $data['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => $reviews_cpt,
			'post_author'  => $author_id,
		] );

		if ( is_wp_error( $post_id ) ) {
			Logger::log( "Failed to insert review post: " . $post_id->get_error_message(), 'error', 'reviews' );
			return;
		}

		// Save review meta
		$rating = min( 5, max( 0, (float) ( $data['rating'] ?? 3 ) ) );
		update_post_meta( $post_id, '_cep_review_star_rating', $rating );
		update_post_meta( $post_id, '_cep_review_pros', implode( "\n", (array) ( $data['pros'] ?? [] ) ) );
		update_post_meta( $post_id, '_cep_review_cons', implode( "\n", (array) ( $data['cons'] ?? [] ) ) );
		update_post_meta( $post_id, '_cep_review_verdict', sanitize_textarea_field( $data['verdict'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_price_from', sanitize_text_field( $data['price'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_product_url', esc_url_raw( $review['product_url'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_product_type', sanitize_text_field( $review['review_type'] ) );

		// Set taxonomy
		$tax     = Settings::get( 'reviews_tax_slug', 'review-type' );
		$term    = get_term_by( 'slug', $review['review_type'], $tax );
		if ( $term ) {
			wp_set_post_terms( $post_id, [ $term->term_id ], $tax );
		}

		// Update DB record
		$wpdb->update(
			$wpdb->prefix . 'cep_reviews',
			[
				'wp_post_id'      => $post_id,
				'star_rating'     => $rating,
				'pros'            => wp_json_encode( $data['pros'] ?? [] ),
				'cons'            => wp_json_encode( $data['cons'] ?? [] ),
				'verdict'         => sanitize_textarea_field( $data['verdict'] ?? '' ),
				'ai_generated'    => 1,
				'last_verified_at'=> current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $review['id'] ],
			[ '%d','%f','%s','%s','%s','%d','%s','%s' ],
			[ '%d' ]
		);

		Logger::log( "Generated review for {$review['product_name']}: post #{$post_id}", 'info', 'reviews' );
		do_action( 'cep_review_generated', $post_id, $review );
	}

	private static function update_review( array $review ): void {
		global $wpdb;

		$ai     = AiClient::get_instance();
		$prompt = "Provide an updated assessment for {$review['product_name']} ({$review['review_type']}). Return JSON with: rating (1-5), pros (array), cons (array), verdict (string).";

		$result = $ai->complete( $prompt, '', [
			'response_format' => [ 'type' => 'json_object' ],
			'mini'            => true,
		] );

		if ( is_wp_error( $result ) ) {
			return;
		}

		$data = json_decode( $result['content'], true );
		if ( ! isset( $data['rating'] ) ) {
			return;
		}

		$rating  = min( 5, max( 0, (float) $data['rating'] ) );
		$post_id = (int) $review['wp_post_id'];

		if ( $post_id ) {
			update_post_meta( $post_id, '_cep_review_star_rating', $rating );
			update_post_meta( $post_id, '_cep_review_verdict', sanitize_textarea_field( $data['verdict'] ?? '' ) );
		}

		$wpdb->update(
			$wpdb->prefix . 'cep_reviews',
			[
				'star_rating'     => $rating,
				'last_verified_at'=> current_time( 'mysql', true ),
				'updated_at'      => current_time( 'mysql', true ),
			],
			[ 'id' => $review['id'] ],
			[ '%f','%s','%s' ],
			[ '%d' ]
		);

		Logger::log( "Updated review for {$review['product_name']}", 'info', 'reviews' );
	}

	private static function build_review_prompt( array $review ): string {
		$type = $review['review_type'];
		$name = $review['product_name'];
		$url  = $review['product_url'];

		return apply_filters( 'cep_review_prompt', <<<PROMPT
You are an expert reviewer. Write a comprehensive, balanced review of the {$type} "{$name}".
Product URL: {$url}

Return a JSON object:
{
  "title": "SEO-optimized review title",
  "content": "full HTML review content (600-1000 words) with sections: Overview, Key Features. Do NOT include Pros & Cons or Verdict in this HTML.",
  "excerpt": "2-sentence summary",
  "rating": 4.2,
  "pros": ["Pro 1", "Pro 2", "Pro 3"],
  "cons": ["Con 1", "Con 2"],
  "verdict": "2-3 sentence conclusion",
  "price": "starting price if known"
}

Be factual, balanced, and helpful. Do not fabricate specific pricing or version numbers unless widely known.
PROMPT
		, $review );
	}
}
