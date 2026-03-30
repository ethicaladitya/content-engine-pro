<?php
namespace ContentEnginePro\Affiliate;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages affiliate provider data and lookups.
 */
class AffiliateManager {

	private static ?array $providers_cache = null;

	/**
	 * Get all active providers.
	 * Returns array of [ 'slug', 'match_terms', 'url_enc', 'post_id', 'star_rating', 'display_price' ]
	 */
	public static function get_providers(): array {
		if ( null !== self::$providers_cache ) {
			return self::$providers_cache;
		}

		$cpt = Settings::get( 'providers_cpt_slug', 'provider' );

		$posts = get_posts( [
			'post_type'      => $cpt,
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'meta_query'     => [
				[
					'key'     => '_cep_is_active',
					'value'   => '1',
					'compare' => '=',
				],
			],
		] );

		$providers = [];
		foreach ( $posts as $post ) {
			$match_terms = get_post_meta( $post->ID, '_cep_match_terms', true );
			if ( is_string( $match_terms ) ) {
				$match_terms = array_map( 'trim', explode( ',', $match_terms ) );
			}
			$match_terms = array_filter( (array) $match_terms );

			$providers[] = [
				'post_id'       => $post->ID,
				'slug'          => $post->post_name,
				'name'          => $post->post_title,
				'match_terms'   => $match_terms,
				'url_enc'       => get_post_meta( $post->ID, '_cep_affiliate_url_enc', true ),
				'homepage_url'  => get_post_meta( $post->ID, '_cep_homepage_url', true ),
				'display_price' => get_post_meta( $post->ID, '_cep_display_price', true ),
				'star_rating'   => (float) get_post_meta( $post->ID, '_cep_star_rating', true ),
				'infra_type'    => get_post_meta( $post->ID, '_cep_infra_type', true ),
				'target_audience' => get_post_meta( $post->ID, '_cep_target_audience', true ),
			];
		}

		self::$providers_cache = apply_filters( 'cep_affiliate_providers', $providers );

		return self::$providers_cache;
	}

	/**
	 * Get a single provider by slug.
	 */
	public static function get_provider( string $slug ): ?array {
		foreach ( self::get_providers() as $provider ) {
			if ( $provider['slug'] === $slug ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Clear the providers cache (call after saving a provider post).
	 */
	public static function clear_cache(): void {
		self::$providers_cache = null;
	}
}
