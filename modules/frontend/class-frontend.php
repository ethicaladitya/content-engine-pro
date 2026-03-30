<?php
namespace ContentEnginePro\Frontend;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend hooks: enqueue assets, modify queries, register image sizes.
 */
class Frontend {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'after_setup_theme', [ $this, 'register_image_sizes' ] );
		add_action( 'pre_get_posts', [ $this, 'modify_main_query' ] );
	}

	public function enqueue_assets(): void {
		$primary  = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews  = Settings::get( 'reviews_cpt_slug', 'review' );

		$load_css = is_singular( [ $primary, $reviews ] )
			|| is_post_type_archive( [ $primary, $reviews ] )
			|| is_tax( [ Settings::get( 'primary_tax_slug' ), Settings::get( 'secondary_tax_slug' ), Settings::get( 'reviews_tax_slug' ) ] )
			|| is_home()
			|| is_front_page();

		if ( $load_css ) {
			wp_enqueue_style( 'cep-frontend', CEP_URL . 'assets/css/frontend.css', [], CEP_VERSION );
			wp_enqueue_script( 'cep-frontend', CEP_URL . 'assets/js/frontend.js', [], CEP_VERSION, true );
		}
	}

	public function register_image_sizes(): void {
		$w = (int) Settings::get( 'hero_image_width', 1200 );
		$h = (int) Settings::get( 'hero_image_height', 675 );
		add_image_size( 'cep-hero', $w, $h, true );

		$w = (int) Settings::get( 'card_image_width', 600 );
		$h = (int) Settings::get( 'card_image_height', 338 );
		add_image_size( 'cep-card', $w, $h, true );

		$w = (int) Settings::get( 'thumb_image_width', 160 );
		$h = (int) Settings::get( 'thumb_image_height', 120 );
		add_image_size( 'cep-thumb', $w, $h, true );
	}

	public function modify_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$primary = Settings::get( 'primary_cpt_slug', 'post' );
		$ppp     = (int) Settings::get( 'posts_per_page', 12 );

		if ( is_home() || is_post_type_archive( $primary ) ) {
			$query->set( 'posts_per_page', $ppp );
		}

		// Include primary CPT in search if not default 'post'
		if ( is_search() && 'post' !== $primary ) {
			$types   = (array) $query->get( 'post_type' );
			$types[] = $primary;
			$query->set( 'post_type', array_unique( $types ) );
		}
	}
}
