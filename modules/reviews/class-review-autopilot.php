<?php
namespace ContentEnginePro\Reviews;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;
use ContentEnginePro\AiClient;
use ContentEnginePro\NicheManager;
use ContentEnginePro\Research\WebResearcher;

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

		// 3. Extract source images (before publish so we can pass count to content)
		$source_images = self::extract_source_images( $product['product_url'] );

		// 4. Publish review post
		$post_id = self::publish_review( $product, $review_data );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// 5. Sideload images: first → featured image, rest → injected into content
		if ( ! empty( $source_images ) ) {
			self::assign_review_images( $post_id, $source_images, $product['product_name'] );
		}

		// 6. Save to reviews DB table
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
- 800–1400 words of review content
- Use proper HTML: h2/h3 headings, <p> paragraphs, <ul>/<ol> lists
- Be specific — mention real features, real limitations, real use cases
- If it's free, mention the free tier vs any paid plans
- Do NOT fabricate version numbers or pricing you're not sure about
- End with a clear verdict

IMAGES — Insert 2–3 image placeholder comments at natural visual break points in the content (after the intro, after the features section, before the verdict). Use exactly this format:
  <!-- IMAGE_PLACEHOLDER -->
These will be replaced with real product images automatically after generation.

PURCHASE LINKS — Every time you mention the product name or any specific product variant/version, hyperlink it using an anchor tag pointing to the most appropriate purchase or official page. Use the product URL from above as the href. For well-known products also sold on Amazon, use an Amazon search link as the href instead: https://www.amazon.com/s?k=PRODUCT+NAME+URL+ENCODED. Always add rel="nofollow noopener" and target="_blank" to purchase links.

Example of a purchase link:
<a href="https://www.amazon.com/s?k=Product+Name" rel="nofollow noopener" target="_blank">Product Name</a>

Return a JSON object with this exact structure:
{
  "title": "SEO-optimized review headline (include the product name and 'Review')",
  "content": "full HTML review (800-1400 words) with <!-- IMAGE_PLACEHOLDER --> comments and purchase hyperlinks",
  "excerpt": "2-sentence meta description summary",
  "star_rating": 4.2,
  "pros": ["pro 1", "pro 2", "pro 3", "pro 4"],
  "cons": ["con 1", "con 2", "con 3"],
  "verdict": "2-3 sentence final verdict",
  "price_from": "Free / $X/month / Free – $XX/month",
  "version": "x.x.x or empty string if unknown",
  "category": "most fitting review category for this product type",
  "tags": ["tag1", "tag2", "tag3"]
}

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

		// Save review meta
		$rating = (float) ( $review_data['star_rating'] ?? 0 );
		update_post_meta( $post_id, '_cep_review_rating', $rating );
		update_post_meta( $post_id, '_cep_review_pros', wp_json_encode( $review_data['pros'] ?? [] ) );
		update_post_meta( $post_id, '_cep_review_cons', wp_json_encode( $review_data['cons'] ?? [] ) );
		update_post_meta( $post_id, '_cep_review_verdict', sanitize_textarea_field( $review_data['verdict'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_price', sanitize_text_field( $review_data['price_from'] ?? '' ) );
		update_post_meta( $post_id, '_cep_review_version', sanitize_text_field( $review_data['version'] ?? '' ) );
		update_post_meta( $post_id, '_cep_product_url', esc_url_raw( $product['product_url'] ) );
		update_post_meta( $post_id, '_cep_product_type', sanitize_key( $product['product_type'] ?? '' ) );
		update_post_meta( $post_id, '_cep_ai_generated', '1' );
		update_post_meta( $post_id, '_cep_ai_model', sanitize_text_field( Settings::get( 'ai_model' ) ) );
		update_post_meta( $post_id, '_cep_active_installs', (int) ( $product['active_installs'] ?? 0 ) );
		if ( ! empty( $product['wporg_slug'] ) ) {
			update_post_meta( $post_id, '_cep_wporg_slug', sanitize_title( $product['wporg_slug'] ) );
		}

		return $post_id;
	}

	// ─── Image helpers ──────────────────────────────────────────────────────

	/**
	 * Extract up to 4 usable image URLs from a source page.
	 * Returns OG image first, then inline article images.
	 */
	private static function extract_source_images( string $url ): array {
		if ( empty( $url ) ) {
			return [];
		}

		$response = wp_remote_get( $url, [
			'timeout'    => 12,
			'user-agent' => Settings::get( 'crawl_user_agent', 'ContentEnginePro/1.0' ),
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$html   = wp_remote_retrieve_body( $response );
		$images = [];

		// 1. OG image — highest quality, most reliable
		foreach ( [
			'/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
			'/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i',
		] as $pattern ) {
			if ( preg_match( $pattern, $html, $m ) && filter_var( $m[1], FILTER_VALIDATE_URL ) ) {
				$images[] = $m[1];
				break;
			}
		}

		// 2. Inline <img> tags — skip logos, icons, tracking pixels, data URIs
		preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches );
		$skip_keywords = [ 'logo', 'icon', 'avatar', 'emoji', 'pixel', 'track', 'badge', 'button', 'sprite', 'blank' ];

		foreach ( $matches[1] as $src ) {
			if ( count( $images ) >= 4 ) {
				break;
			}
			// Skip data URIs and already-added
			if ( strpos( $src, 'data:' ) === 0 || in_array( $src, $images, true ) ) {
				continue;
			}
			// Must be a real URL
			if ( ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
				continue;
			}
			// Skip tiny tracking/icon images
			$src_lower = strtolower( $src );
			$skip = false;
			foreach ( $skip_keywords as $kw ) {
				if ( strpos( $src_lower, $kw ) !== false ) {
					$skip = true;
					break;
				}
			}
			if ( ! $skip ) {
				$images[] = $src;
			}
		}

		return array_values( array_unique( $images ) );
	}

	/**
	 * Sideload source images into WordPress, set the first as the featured image,
	 * and inject the rest into the post content at natural paragraph breaks.
	 */
	private static function assign_review_images( int $post_id, array $image_urls, string $product_name ): void {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$sideloaded  = []; // wp_url => attachment_id
		$alt_base    = sanitize_text_field( $product_name );

		foreach ( $image_urls as $i => $url ) {
			$alt    = $alt_base . ( $i > 0 ? ' — image ' . $i : '' );
			$att_id = media_sideload_image( $url, $post_id, $alt, 'id' );

			if ( is_wp_error( $att_id ) ) {
				Logger::log( "Review image sideload failed for #{$post_id} (url: {$url}): " . $att_id->get_error_message(), 'warning', 'review_autopilot' );
				continue;
			}

			update_post_meta( $att_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			$wp_url = wp_get_attachment_url( $att_id );
			if ( $wp_url ) {
				$sideloaded[ $wp_url ] = $att_id;
			}
		}

		if ( empty( $sideloaded ) ) {
			return;
		}

		// First image → featured image
		$urls_list  = array_keys( $sideloaded );
		$ids_list   = array_values( $sideloaded );
		set_post_thumbnail( $post_id, $ids_list[0] );
		Logger::log( "Review featured image set for #{$post_id}", 'info', 'review_autopilot' );

		// Remaining images → replace <!-- IMAGE_PLACEHOLDER --> markers in content,
		// then inject any leftovers at paragraph boundaries.
		$content_images = array_slice( $urls_list, 1 ); // skip the featured image URL
		if ( empty( $content_images ) ) {
			return;
		}

		self::inject_content_images( $post_id, $content_images, $alt_base );
	}

	/**
	 * Replace <!-- IMAGE_PLACEHOLDER --> comments in post content with real <figure> blocks.
	 * Any extra images beyond the placeholder count are appended after every 4th paragraph.
	 */
	private static function inject_content_images( int $post_id, array $wp_image_urls, string $alt_base ): void {
		$content = get_post_field( 'post_content', $post_id );
		if ( empty( $content ) || empty( $wp_image_urls ) ) {
			return;
		}

		$img_index = 0;

		// Float directions alternate: left, right, left, right…
		$float_dirs = [ 'alignleft', 'alignright' ];

		// Replace explicit placeholders first
		$content = preg_replace_callback(
			'/<!--\s*IMAGE_PLACEHOLDER\s*-->/i',
			function () use ( &$img_index, $wp_image_urls, $alt_base, $float_dirs ) {
				if ( $img_index >= count( $wp_image_urls ) ) {
					return ''; // No more images — remove placeholder
				}
				$url   = esc_url( $wp_image_urls[ $img_index ] );
				$alt   = esc_attr( $alt_base );
				$float = $float_dirs[ $img_index % 2 ];
				$img_index++;
				return "\n<figure class=\"wp-block-image size-medium {$float}\"><img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" /></figure>\n";
			},
			$content
		);

		// If we still have unused images, inject after every 4th </p>
		if ( $img_index < count( $wp_image_urls ) ) {
			$para_count = 0;
			$content    = preg_replace_callback(
				'/<\/p>/i',
				function ( $match ) use ( &$img_index, &$para_count, $wp_image_urls, $alt_base, $float_dirs ) {
					$para_count++;
					if ( $para_count % 4 === 0 && $img_index < count( $wp_image_urls ) ) {
						$url   = esc_url( $wp_image_urls[ $img_index ] );
						$alt   = esc_attr( $alt_base );
						$float = $float_dirs[ $img_index % 2 ];
						$img_index++;
						return "</p>\n<figure class=\"wp-block-image size-medium {$float}\"><img src=\"{$url}\" alt=\"{$alt}\" loading=\"lazy\" /></figure>\n";
					}
					return $match[0];
				},
				$content
			);
		}

		wp_update_post( [
			'ID'           => $post_id,
			'post_content' => $content,
		] );

		Logger::log( "Review content images injected for #{$post_id} ({$img_index} images)", 'info', 'review_autopilot' );
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

	/**
	 * Backfill featured images + content images for published reviews that have none.
	 * Trigger via: wp eval 'ContentEnginePro\Reviews\ReviewAutopilot::backfill_images();'
	 */
	public static function backfill_images(): void {
		$posts = get_posts( [
			'post_type'      => Settings::get( 'reviews_cpt_slug', 'review' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => [ [
				'key'     => '_thumbnail_id',
				'compare' => 'NOT EXISTS',
			] ],
			'fields'         => 'ids',
		] );

		if ( empty( $posts ) ) {
			echo "No reviews missing a featured image.\n";
			return;
		}

		echo "Backfilling images for " . count( $posts ) . " reviews...\n";

		foreach ( $posts as $post_id ) {
			$product_url  = get_post_meta( $post_id, '_cep_product_url', true );
			$product_name = get_the_title( $post_id );

			if ( empty( $product_url ) ) {
				echo "  #{$post_id}: no product URL saved — skipping\n";
				continue;
			}

			echo "  #{$post_id}: fetching images from {$product_url}...\n";
			$images = self::extract_source_images( $product_url );

			if ( empty( $images ) ) {
				echo "  #{$post_id}: no images found on source page\n";
				continue;
			}

			self::assign_review_images( $post_id, $images, $product_name );
			echo "  #{$post_id}: done (" . count( $images ) . " images processed)\n";

			sleep( 1 ); // be polite to the remote server
		}

		echo "Backfill complete.\n";
	}
}
