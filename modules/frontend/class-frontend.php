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

		// Review single-post enhancements.
		add_filter( 'the_content', [ $this, 'inject_review_header' ], 5 );
		add_filter( 'is_active_sidebar', [ $this, 'suppress_sidebar_on_reviews' ], 10, 2 );
		add_filter( 'body_class', [ $this, 'review_body_classes' ] );
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

	/**
	 * Prepend a review summary block (stars, score, pros/cons, verdict) to the
	 * content of single review posts. Runs at priority 5 so it appears before
	 * any other the_content filters (e.g. affiliate link injection at 20).
	 */
	public function inject_review_header( string $content ): string {
		if ( ! is_singular( Settings::get( 'reviews_cpt_slug', 'review' ) ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id  = get_the_ID();
		$rating   = (float) get_post_meta( $post_id, '_cep_review_star_rating', true );
		$pros_raw = (string) get_post_meta( $post_id, '_cep_review_pros', true );
		$cons_raw = (string) get_post_meta( $post_id, '_cep_review_cons', true );
		$verdict  = (string) get_post_meta( $post_id, '_cep_review_verdict', true );
		$price    = (string) get_post_meta( $post_id, '_cep_review_price_from', true );
		$type     = (string) get_post_meta( $post_id, '_cep_review_product_type', true );

		// Nothing stored yet — don't inject an empty box.
		if ( $rating < 0.5 && ! $pros_raw && ! $cons_raw && ! $verdict ) {
			return $content;
		}

		$pros = array_values( array_filter( array_map( 'esc_html', $this->parse_list_meta( $pros_raw ) ) ) );
		$cons = array_values( array_filter( array_map( 'esc_html', $this->parse_list_meta( $cons_raw ) ) ) );

		// Build stars HTML.
		$stars_html = '';
		for ( $i = 1; $i <= 5; $i++ ) {
			$filled      = $i <= round( $rating ) ? ' cep-star--filled' : '';
			$stars_html .= '<span class="cep-star' . $filled . '" aria-hidden="true">&#9733;</span>';
		}

		// Pros list.
		$pros_html = '';
		if ( $pros ) {
			$items = implode( '', array_map( static fn( $p ) => '<li>' . $p . '</li>', $pros ) );
			$pros_html = '<div class="cep-rh__col">
				<p class="cep-rh__list-label cep-rh__list-label--pros">&#10003; Pros</p>
				<ul class="cep-rh__list">' . $items . '</ul>
			</div>';
		}

		// Cons list.
		$cons_html = '';
		if ( $cons ) {
			$items = implode( '', array_map( static fn( $c ) => '<li>' . $c . '</li>', $cons ) );
			$cons_html = '<div class="cep-rh__col">
				<p class="cep-rh__list-label cep-rh__list-label--cons">&#10007; Cons</p>
				<ul class="cep-rh__list">' . $items . '</ul>
			</div>';
		}

		// Type badge.
		$badge_html = $type
			? '<span class="cep-rh__badge">' . esc_html( ucfirst( $type ) ) . '</span>'
			: '';

		// Verdict.
		$verdict_html = $verdict
			? '<div class="cep-rh__verdict"><strong>Verdict:</strong> ' . esc_html( $verdict ) . '</div>'
			: '';

		// Price.
		$price_html = $price
			? '<span class="cep-rh__price">From ' . esc_html( $price ) . '</span>'
			: '';

		$score_label = $rating >= 0.5
			? '<span class="cep-rh__score-num" aria-label="' . esc_attr( number_format( $rating, 1 ) ) . ' out of 5">'
				. esc_html( number_format( $rating, 1 ) ) . '<small>/5</small></span>'
			: '';

		$block = '<div class="cep-review-header" role="region" aria-label="Review summary">
			<div class="cep-rh__top">
				<div class="cep-rh__rating-wrap">
					' . $badge_html . '
					<div class="cep-stars cep-stars--lg" aria-label="Rating: ' . esc_attr( number_format( $rating, 1 ) ) . ' out of 5">'
						. $stars_html .
					'</div>
					' . $score_label . '
					' . $price_html . '
				</div>
			</div>
			' . ( ( $pros_html || $cons_html ) ? '<div class="cep-rh__cols">' . $pros_html . $cons_html . '</div>' : '' ) . '
			' . $verdict_html . '
		</div>';

		return $block . $content;
	}

	/**
	 * Parse a newline-separated or JSON-encoded list meta value into a string array.
	 * Mirrors ReviewShortcode::parse_list_meta() — kept local to avoid cross-module coupling.
	 *
	 * @return string[]
	 */
	private function parse_list_meta( string $raw ): array {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return [];
		}
		if ( str_starts_with( $raw, '[' ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'trim', $decoded ) ) );
			}
		}
		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
	}

	/**
	 * Return false for any sidebar when viewing a single review post so the theme
	 * renders full-width. Works for any theme that calls is_active_sidebar() before
	 * outputting the sidebar widget area (virtually all themes do).
	 *
	 * @param  bool       $is_active  Current value.
	 * @param  string|int $index      Sidebar ID or name.
	 * @return bool
	 */
	public function suppress_sidebar_on_reviews( bool $is_active, $index ): bool {
		if ( $is_active && is_singular( Settings::get( 'reviews_cpt_slug', 'review' ) ) ) {
			return false;
		}
		return $is_active;
	}

	/**
	 * Add body classes on single review posts so themes with CSS full-width
	 * overrides (e.g. .full-width, .no-sidebar) activate automatically.
	 *
	 * @param  string[] $classes
	 * @return string[]
	 */
	public function review_body_classes( array $classes ): array {
		if ( is_singular( Settings::get( 'reviews_cpt_slug', 'review' ) ) ) {
			$classes[] = 'cep-review-single';
			$classes[] = 'no-sidebar';
			$classes[] = 'full-width';
		}
		return $classes;
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
