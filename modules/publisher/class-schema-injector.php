<?php
namespace ContentEnginePro\Publisher;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects JSON-LD schema markup and, as a fallback, meta/OG tags.
 *
 * Responsibility split:
 *  - inject_schema()   : Review CPT only. All other post types are handled by the
 *                        active SEO plugin (Yoast, RankMath, SmartCrawl, etc.) or
 *                        by seo-agent-ai for BlogPosting / FAQPage.
 *  - inject_seo_meta() : Fallback meta/OG/Twitter output only when no SEO plugin
 *                        is active. When any SEO plugin is present this method
 *                        returns immediately to avoid duplicate tags.
 */
class SchemaInjector {

	public function register(): void {
		add_action( 'wp_head', [ $this, 'inject_schema' ], 5 );

		if ( Settings::is_enabled( 'enable_seo_meta' ) ) {
			add_action( 'wp_head', [ $this, 'inject_seo_meta' ], 2 );
		}
	}

	// -------------------------------------------------------------------
	// SEO plugin detection
	// -------------------------------------------------------------------

	/**
	 * Returns the slug of the first active SEO plugin found, or empty string.
	 *
	 * Covers: Yoast SEO, RankMath, SmartCrawl (WPMU DEV), All in One SEO,
	 * SEOPress, and The SEO Framework.
	 */
	private function active_seo_plugin(): string {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend', false ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false ) ) {
			return 'rankmath';
		}
		if (
			defined( 'SMARTCRAWL_VERSION' )
			|| class_exists( 'SmartCrawl_Settings', false )
			|| class_exists( 'Smartcrawl\\Smartcrawl', false )
		) {
			return 'smartcrawl';
		}
		if (
			defined( 'AIOSEO_VERSION' )
			|| class_exists( 'AIOSEO\\Plugin\\AIOSEO', false )
			|| function_exists( 'aioseo' )
		) {
			return 'aioseo';
		}
		if ( defined( 'SEOPRESS_VERSION' ) || class_exists( 'SeoPress_Admin_Pages', false ) ) {
			return 'seopress';
		}
		if ( function_exists( 'the_seo_framework' ) || class_exists( 'The_SEO_Framework\\Load', false ) ) {
			return 'seoframework';
		}
		return '';
	}

	private function has_active_seo_plugin(): bool {
		return $this->active_seo_plugin() !== '';
	}

	// -------------------------------------------------------------------
	// Schema injection
	// -------------------------------------------------------------------

	public function inject_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$post        = get_post( $post_id );
		$post_type   = get_post_type( $post_id );
		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );

		// Only inject schema for the reviews CPT — no SEO plugin auto-generates
		// Review + ReviewRating schema. Everything else is handled upstream.
		if ( $post_type !== $reviews_cpt ) {
			return;
		}

		$schema = $this->build_review_schema( $post );
		$schema = apply_filters( 'cep_schema_data', $schema, $post_id, $post_type );

		if ( empty( $schema ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	// -------------------------------------------------------------------
	// Schema builders
	// -------------------------------------------------------------------

	private function build_review_schema( \WP_Post $post ): array {
		$author_id   = (int) $post->post_author;
		$author_name = get_the_author_meta( 'display_name', $author_id )
			?: Settings::get( 'brand_name', get_bloginfo( 'name' ) );
		$rating      = (float) get_post_meta( $post->ID, '_cep_review_star_rating', true );
		$product_url = get_post_meta( $post->ID, '_cep_review_product_url', true );

		return [
			'@context'     => 'https://schema.org',
			'@type'        => 'Review',
			'name'         => get_the_title( $post->ID ),
			'url'          => get_permalink( $post->ID ),
			'datePublished'=> get_the_date( 'c', $post->ID ),
			'author'       => [
				'@type' => 'Person',
				'name'  => esc_html( $author_name ),
				'url'   => get_author_posts_url( $author_id ),
			],
			'reviewRating' => [
				'@type'       => 'Rating',
				'ratingValue' => $rating ?: 3.0,
				'bestRating'  => 5,
				'worstRating' => 1,
			],
			'itemReviewed' => [
				'@type' => 'SoftwareApplication',
				'name'  => get_the_title( $post->ID ),
				'url'   => $product_url ?: '',
			],
		];
	}

	// -------------------------------------------------------------------
	// Fallback meta / OG / Twitter (only when no SEO plugin is active)
	// -------------------------------------------------------------------

	public function inject_seo_meta(): void {
		// All major SEO plugins handle meta description, OG, and Twitter Card tags.
		// When any of them is active we return early to avoid duplicate output.
		if ( $this->has_active_seo_plugin() ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$description = wp_trim_words( get_the_excerpt( $post_id ), 30 );
		$title       = get_the_title( $post_id );
		$url         = get_permalink( $post_id );
		$image       = '';

		if ( has_post_thumbnail( $post_id ) ) {
			$img   = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			$image = $img ? $img[0] : '';
		}

		if ( $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
		}

		echo '<meta property="og:type"  content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta property="og:url"   content="' . esc_url( $url ) . '" />' . "\n";
		if ( $description ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		echo '<meta name="twitter:card"  content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		}
	}
}
