<?php
namespace ContentEnginePro\Reviews;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;
use ContentEnginePro\NicheManager;
use ContentEnginePro\Research\WebResearcher;
use ContentEnginePro\ImageHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review Autopilot — Stage 2 of the review autopilot.
 *
 * Picks discovered products from cep_product_discovery (status = 'pending'),
 * researches their product page + any pricing/changelog pages,
 * uses AI to generate a comprehensive review, and publishes it as a review CPT post.
 */
class ReviewAutopilot {

	public static function run(): void {
		if ( ! Settings::is_enabled( 'review_autopilot_enabled' ) ) {
			return;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'cep_product_discovery';
		$max     = (int) Settings::get( 'review_max_per_run', 3 );

		$products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = 'pending' ORDER BY active_installs DESC, discovered_at ASC LIMIT %d",
				$max
			),
			ARRAY_A
		);

		if ( empty( $products ) ) {
			Logger::log( 'Review autopilot: no products pending generation', 'info', 'review_autopilot' );
			return;
		}

		do_action( 'cep_before_review_autopilot' );

		$generated = 0;
		foreach ( $products as $product ) {
			// Mark as processing to prevent concurrent runs from picking it up
			$wpdb->update( $table, [ 'status' => 'processing' ], [ 'id' => $product['id'] ], [ '%s' ], [ '%d' ] );

			$result = self::process_product( $product );

			if ( $result && ! is_wp_error( $result ) ) {
				$wpdb->update( $table, [ 'status' => 'published' ], [ 'id' => $product['id'] ], [ '%s' ], [ '%d' ] );
				$generated++;
			} else {
				$error_msg = is_wp_error( $result ) ? $result->get_error_message() : 'unknown error';
				Logger::log( "Review autopilot: failed for {$product['product_name']} — {$error_msg}", 'warning', 'review_autopilot' );
				$wpdb->update( $table, [ 'status' => 'failed' ], [ 'id' => $product['id'] ], [ '%s' ], [ '%d' ] );
			}

			if ( $generated < count( $products ) ) {
				sleep( 3 );
			}
		}

		Logger::log( "Review autopilot: generated {$generated} reviews", 'info', 'review_autopilot' );
		do_action( 'cep_after_review_autopilot', $generated );
	}

	/**
	 * Process a single discovered product into a published review.
	 */
	public static function process_product( array $product ) {
		Logger::log( "Review autopilot: researching {$product['product_name']}", 'info', 'review_autopilot' );

		// 1. Gather research
		$research_urls = array_filter( [ $product['product_url'] ] );

		// For WordPress.org plugins, also fetch the changelog and reviews tabs
		if ( ! empty( $product['wporg_slug'] ) ) {
			$slug = sanitize_title( $product['wporg_slug'] );
			$research_urls[] = "https://wordpress.org/plugins/{$slug}/#description";
			$research_urls[] = "https://wordpress.org/plugins/{$slug}/#reviews";

			// Try to get version info from WP.org API
			$api_info = self::fetch_wporg_plugin_info( $slug );
		}

		$depth          = (int) Settings::get( 'review_research_depth', 2 );
		$research_text  = WebResearcher::research_urls( array_unique( $research_urls ), 10000 );

		// Supplement with WP.org API data if available
		if ( ! empty( $api_info ) ) {
			$research_text .= "\n\n### WordPress.org Data\n" . wp_json_encode( $api_info, JSON_PRETTY_PRINT );
		}

		// Fallback to description stored at discovery time
		if ( strlen( $research_text ) < 300 && ! empty( $product['description'] ) ) {
			$research_text = $product['description'];
		}

		if ( empty( $research_text ) ) {
			return new \WP_Error( 'no_research', "No research data for {$product['product_name']}" );
		}

		// 2. Generate review via AI
		$review_data = self::generate_review( $product, $research_text );

		if ( is_wp_error( $review_data ) ) {
			return $review_data;
		}

		if ( empty( $review_data['title'] ) || empty( $review_data['content'] ) ) {
			return new \WP_Error( 'bad_response', 'AI returned incomplete review data' );
		}

		// 3. Publish review post
		$post_id = self::publish_review( $product, $review_data );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// 4. Featured image — try OG from product page, then Pexels, then Picsum.
		ImageHelper::assign_featured_image(
			$post_id,
			$product['product_url'],
			$product['product_name']
		);

		// 5. Save to reviews DB table
		self::save_review_record( $post_id, $product, $review_data );

		Logger::log( "Review autopilot: published review #{$post_id} for {$product['product_name']}", 'info', 'review_autopilot' );
		do_action( 'cep_review_autopilot_published', $post_id, $product, $review_data );

		return $post_id;
	}

	/**
	 * Fetch plugin info from WordPress.org API.
	 */
	private static function fetch_wporg_plugin_info( string $slug ): array {
		$url      = "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]={$slug}&request[fields][versions]=false&request[fields][screenshots]=false";
		$response = wp_remote_get( $url, [ 'timeout' => 15 ] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return [];
		}

		return [
			'name'             => $data['name'] ?? '',
			'version'          => $data['version'] ?? '',
			'active_installs'  => $data['active_installs'] ?? 0,
			'rating'           => isset( $data['rating'] ) ? round( $data['rating'] / 20, 1 ) : 0,
			'num_ratings'      => $data['num_ratings'] ?? 0,
			'last_updated'     => $data['last_updated'] ?? '',
			'requires'         => $data['requires'] ?? '',
			'requires_php'     => $data['requires_php'] ?? '',
			'author'           => is_array( $data['author'] ) ? '' : wp_strip_all_tags( $data['author'] ?? '' ),
			'short_description'=> $data['short_description'] ?? '',
			'tags'             => is_array( $data['tags'] ) ? implode( ', ', array_keys( $data['tags'] ) ) : '',
			'homepage'         => $data['homepage'] ?? '',
			'price'            => 'Free',
		];
	}

	/**
	 * Use AI to generate a comprehensive review.
	 */
	private static function generate_review( array $product, string $research_text ) {
		$ai    = AiClient::get_instance();
		$niche = NicheManager::get_active();
		$brand = Settings::get( 'brand_name', 'our publication' );
		$type  = $product['product_type'] ?? 'plugin';

		$prompt = apply_filters( 'cep_review_autopilot_prompt', <<<PROMPT
You are a senior reviewer at "{$brand}", an authoritative {$niche['label']} publication.

Write a comprehensive, honest, expert review of the following {$type}:
Product: {$product['product_name']}
URL: {$product['product_url']}

Requirements:
- Write in an authoritative yet accessible tone
- 600–1200 words of review content
- Use proper HTML: h2/h3 headings, <p> paragraphs, <ul> lists for pros/cons
- Be specific — mention real features, real limitations, real use cases
- If it's free, mention the free tier vs any paid plans
- Do NOT fabricate version numbers or pricing you're not sure about
- End with a clear verdict

SCORING INSTRUCTIONS — read carefully before writing:
Assign star_rating as a decimal from 1.0 to 5.0 based solely on the research data below.
Scoring guide: features & depth (up to 2pts) + user sentiment/ratings (up to 1pt) + value for money (up to 1pt) + support & docs (up to 1pt).
Most solid products score 3.2–4.4. Reserve 4.5+ for genuinely outstanding products. Do not default to any fixed number.

Return ONLY a valid JSON object:
{
  "title": "SEO-optimised review headline including the product name and the word Review",
  "content": "full HTML review body (600-1200 words)",
  "excerpt": "2-sentence meta description summary",
  "star_rating": 0.0,
  "pros": ["specific strength observed in research", "another real pro"],
  "cons": ["specific limitation observed in research", "another real con"],
  "verdict": "2-3 sentence final verdict",
  "price_from": "Free or actual starting price e.g. $9/month",
  "version": "actual version number or empty string if unknown",
  "category": "most fitting review category for this product type",
  "tags": ["relevant", "tag", "here"]
}

Replace star_rating 0.0 with your calculated score. A value of 0.0 in the output is invalid.

Research Data:
{$research_text}
PROMPT
		, $product, $research_text, $niche );

		$result = $ai->complete( $prompt, '', [
			'response_format' => [ 'type' => 'json_object' ],
			'temperature'     => (float) Settings::get( 'reviews_ai_temperature', 0.6 ),
		] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = json_decode( $result['content'], true );
		if ( ! is_array( $data ) ) {
			return new \WP_Error( 'bad_json', 'AI returned invalid JSON for review' );
		}

		return $data;
	}

	/**
	 * Publish the review as a WordPress post.
	 */
	private static function publish_review( array $product, array $review_data ): int|\WP_Error {
		$cpt       = Settings::get( 'reviews_cpt_slug', 'review' );
		$author_id = (int) Settings::get( 'default_author_id', 1 );
		$status    = Settings::is_enabled( 'auto_publish' ) ? 'publish' : 'draft';

		$post_id = wp_insert_post( apply_filters( 'cep_review_autopilot_post_args', [
			'post_title'   => sanitize_text_field( $review_data['title'] ),
			'post_content' => wp_kses_post( $review_data['content'] ),
			'post_excerpt' => sanitize_textarea_field( $review_data['excerpt'] ?? '' ),
			'post_status'  => $status,
			'post_type'    => $cpt,
			'post_author'  => $author_id,
			'post_name'    => sanitize_title( $product['product_name'] ),
		], $product, $review_data ) );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Assign review-type taxonomy
		if ( ! empty( $review_data['category'] ) ) {
			$tax     = Settings::get( 'reviews_tax_slug', 'review-type' );
			$term    = get_term_by( 'name', $review_data['category'], $tax ) ?: get_term_by( 'slug', sanitize_title( $review_data['category'] ), $tax );
			if ( ! $term ) {
				$inserted = wp_insert_term( sanitize_text_field( $review_data['category'] ), $tax );
				$term_id  = ! is_wp_error( $inserted ) ? $inserted['term_id'] : 0;
			} else {
				$term_id = $term->term_id;
			}
			if ( ! empty( $term_id ) ) {
				wp_set_post_terms( $post_id, [ $term_id ], $tax );
			}
		}

		// Save review meta — keys must match what ReviewShortcode and the admin UI read.
		// Clamp to 1.0–5.0; treat 0 as absent (AI returned sentinel without replacing it).
		$raw_rating = (float) ( $review_data['star_rating'] ?? 0 );
		$rating     = ( $raw_rating < 0.5 ) ? 0.0 : min( 5.0, max( 1.0, $raw_rating ) );
		$pros   = array_filter( array_map( 'sanitize_text_field', (array) ( $review_data['pros'] ?? [] ) ) );
		$cons   = array_filter( array_map( 'sanitize_text_field', (array) ( $review_data['cons'] ?? [] ) ) );

		update_post_meta( $post_id, '_cep_review_star_rating',  $rating );
		update_post_meta( $post_id, '_cep_review_pros',         implode( "\n", $pros ) );
		update_post_meta( $post_id, '_cep_review_cons',         implode( "\n", $cons ) );
		update_post_meta( $post_id, '_cep_review_verdict',      sanitize_textarea_field( $review_data['verdict'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_price_from',   sanitize_text_field( $review_data['price_from'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_version',      sanitize_text_field( $review_data['version'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_product_name', sanitize_text_field( $product['product_name'] ) );
		update_post_meta( $post_id, '_cep_review_product_url',  esc_url_raw( $product['product_url'] ) );
		update_post_meta( $post_id, '_cep_review_product_type', sanitize_key( $product['product_type'] ?? '' ) );
		update_post_meta( $post_id, '_cep_ai_generated',        '1' );
		update_post_meta( $post_id, '_cep_ai_model',            sanitize_text_field( Settings::get( 'ai_model' ) ) );
		update_post_meta( $post_id, '_cep_active_installs',     (int) ( $product['active_installs'] ?? 0 ) );
		if ( ! empty( $product['wporg_slug'] ) ) {
			update_post_meta( $post_id, '_cep_wporg_slug', sanitize_title( $product['wporg_slug'] ) );
		}

		return $post_id;
	}

	/**
	 * Insert/update the reviews DB table record.
	 */
	private static function save_review_record( int $post_id, array $product, array $review_data ): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'cep_reviews';
		$slug   = sanitize_title( $product['product_name'] );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE product_slug = %s", $slug ) );

		$data = [
			'wp_post_id'    => $post_id,
			'product_name'  => sanitize_text_field( $product['product_name'] ),
			'product_slug'  => $slug,
			'product_url'   => esc_url_raw( $product['product_url'] ),
			'product_version'=> sanitize_text_field( $review_data['version'] ?? '' ),
			'review_type'   => sanitize_key( $product['product_type'] ?? 'plugin' ),
			'star_rating'   => (float) ( $review_data['star_rating'] ?? 0 ),
			'price_from'    => sanitize_text_field( $review_data['price_from'] ?? '' ),
			'pros'          => wp_json_encode( $review_data['pros'] ?? [] ),
			'cons'          => wp_json_encode( $review_data['cons'] ?? [] ),
			'verdict'       => sanitize_textarea_field( $review_data['verdict'] ?? '' ),
			'ai_generated'  => 1,
		];

		if ( $exists ) {
			$wpdb->update( $table, $data, [ 'product_slug' => $slug ], null, [ '%s' ] );
		} else {
			$data['created_at'] = current_time( 'mysql', true );
			$wpdb->insert( $table, $data );
		}
	}
}
