<?php
namespace ContentEnginePro\Publisher;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects JSON-LD schema markup for articles, reviews, and other content types.
 */
class SchemaInjector {

	public function register(): void {
		add_action( 'wp_head', [ $this, 'inject_schema' ], 5 );

		if ( Settings::is_enabled( 'enable_seo_meta' ) ) {
			add_action( 'wp_head', [ $this, 'inject_seo_meta' ], 2 );
		}
	}

	public function inject_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$post        = get_post( $post_id );
		$post_type   = get_post_type( $post_id );
		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );

		if ( $post_type === $reviews_cpt ) {
			$schema = $this->build_review_schema( $post );
		} else {
			$schema = $this->build_article_schema( $post );
		}

		$schema = apply_filters( 'cep_schema_data', $schema, $post_id, $post_type );

		if ( empty( $schema ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	private function build_article_schema( \WP_Post $post ): array {
		$article_type = get_post_meta( $post->ID, '_cep_article_type', true ) ?: 'Article';
		$brand        = Settings::get( 'brand_name', get_bloginfo( 'name' ) );

		$schema = [
			'@context'         => 'https://schema.org',
			'@type'            => $article_type,
			'headline'         => get_the_title( $post->ID ),
			'description'      => get_the_excerpt( $post ),
			'url'              => get_permalink( $post->ID ),
			'datePublished'    => get_the_date( 'c', $post->ID ),
			'dateModified'     => get_the_modified_date( 'c', $post->ID ),
			'author'           => [
				'@type' => 'Organization',
				'name'  => esc_html( $brand ),
			],
			'publisher'        => [
				'@type' => 'Organization',
				'name'  => esc_html( $brand ),
				'url'   => home_url( '/' ),
			],
		];

		if ( has_post_thumbnail( $post->ID ) ) {
			$img_id = get_post_thumbnail_id( $post->ID );
			$img    = wp_get_attachment_image_src( $img_id, 'full' );
			if ( $img ) {
				$schema['image'] = [
					'@type'  => 'ImageObject',
					'url'    => $img[0],
					'width'  => $img[1],
					'height' => $img[2],
				];
			}
		}

		return $schema;
	}

	private function build_review_schema( \WP_Post $post ): array {
		$rating      = (float) get_post_meta( $post->ID, '_cep_review_star_rating', true );
		$product_url = get_post_meta( $post->ID, '_cep_review_product_url', true );
		$brand       = Settings::get( 'brand_name', get_bloginfo( 'name' ) );

		return [
			'@context'     => 'https://schema.org',
			'@type'        => 'Review',
			'name'         => get_the_title( $post->ID ),
			'url'          => get_permalink( $post->ID ),
			'datePublished'=> get_the_date( 'c', $post->ID ),
			'author'       => [
				'@type' => 'Organization',
				'name'  => esc_html( $brand ),
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

	public function inject_seo_meta(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post_id     = get_the_ID();
		$description = get_the_excerpt( $post_id );
		$title       = get_the_title( $post_id );
		$url         = get_permalink( $post_id );
		$image       = '';

		if ( has_post_thumbnail( $post_id ) ) {
			$img   = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			$image = $img ? $img[0] : '';
		}

		if ( $description ) :
			echo '<meta name="description" content="' . esc_attr( wp_trim_words( $description, 30 ) ) . '" />' . "\n";
		endif;

		// OpenGraph
		echo '<meta property="og:type" content="article" />' . "\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
		if ( $description ) {
			echo '<meta property="og:description" content="' . esc_attr( wp_trim_words( $description, 30 ) ) . '" />' . "\n";
		}
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
		}

		// Twitter Card
		echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
		echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		if ( $image ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
		}
	}
}
