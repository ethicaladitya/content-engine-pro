<?php
namespace ContentEnginePro\Frontend;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend hooks: enqueue assets, template overrides, homepage injection,
 * nav menu item, query modifications, and image sizes.
 */
class Frontend {

	public function register(): void {
		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'after_setup_theme',   [ $this, 'register_image_sizes' ] );
		add_action( 'pre_get_posts',       [ $this, 'modify_main_query' ] );

		// Reviews CPT frontend features (guard on the enabled toggle)
		if ( Settings::is_enabled( 'reviews_cpt_enabled' ) ) {
			add_filter( 'template_include',  [ $this, 'review_templates' ] );
			add_filter( 'the_content',       [ $this, 'inject_homepage_reviews' ] );
			add_filter( 'wp_nav_menu_items', [ $this, 'add_reviews_nav_item' ], 20, 2 );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Assets
	// ─────────────────────────────────────────────────────────────────────────

	public function enqueue_assets(): void {
		$primary = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews = Settings::get( 'reviews_cpt_slug', 'review' );

		$load_css = is_singular( [ $primary, $reviews ] )
			|| is_post_type_archive( [ $primary, $reviews ] )
			|| is_tax( [ Settings::get( 'primary_tax_slug' ), Settings::get( 'secondary_tax_slug' ), Settings::get( 'reviews_tax_slug' ) ] )
			|| is_home()
			|| is_front_page();

		if ( $load_css ) {
			wp_enqueue_style(
				'cep-frontend',
				\CEP_URL . 'assets/css/frontend.css',
				[],
				\CEP_VERSION
			);
			wp_enqueue_script(
				'cep-frontend',
				\CEP_URL . 'assets/js/frontend.js',
				[],
				\CEP_VERSION,
				true
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Template overrides
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Serve plugin-bundled templates for review CPT single and archive pages.
	 * Theme authors can override by placing the file in their theme directory.
	 *
	 * @param string $template Original template path chosen by WordPress.
	 * @return string
	 */
	public function review_templates( string $template ): string {
		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
		$reviews_tax = Settings::get( 'reviews_tax_slug', 'review-type' );

		if ( is_singular( $reviews_cpt ) ) {
			$located = $this->locate_template( 'single-review.php' );
			if ( $located ) {
				return $located;
			}
		}

		if ( is_post_type_archive( $reviews_cpt ) || is_tax( $reviews_tax ) ) {
			$located = $this->locate_template( 'archive-review.php' );
			if ( $located ) {
				return $located;
			}
		}

		return $template;
	}

	/**
	 * Locate a template file — theme overrides take priority over plugin templates.
	 *
	 * @param string $filename Template filename (e.g. 'single-review.php').
	 * @return string|false Absolute path, or false if not found.
	 */
	private function locate_template( string $filename ) {
		// 1. Active theme (allows theme overrides)
		$theme_file = locate_template( 'content-engine-pro/' . $filename );
		if ( $theme_file ) {
			return $theme_file;
		}

		// 2. Plugin-bundled template
		$plugin_file = \CEP_DIR . 'templates/' . $filename;
		if ( file_exists( $plugin_file ) ) {
			return $plugin_file;
		}

		return false;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Homepage Reviews section
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Append a "Latest Reviews" grid to the front-page content.
	 *
	 * Fires only on the front page inside the main loop, so it won't affect
	 * widgets, shortcodes on other pages, or secondary queries.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function inject_homepage_reviews( string $content ): string {
		if ( ! is_front_page() || ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
		$count       = max( 1, (int) Settings::get( 'homepage_reviews_count', 3 ) );

		$reviews = get_posts(
			[
				'post_type'      => $reviews_cpt,
				'post_status'    => 'publish',
				'posts_per_page' => $count,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		if ( empty( $reviews ) ) {
			return $content;
		}

		$archive_url = get_post_type_archive_link( $reviews_cpt );

		ob_start();
		?>
		<section class="cep-homepage-reviews" aria-label="<?php esc_attr_e( 'Latest Reviews', 'content-engine-pro' ); ?>">
			<div class="cep-homepage-reviews__header">
				<h2 class="cep-homepage-reviews__title">
					<?php esc_html_e( 'Latest Reviews', 'content-engine-pro' ); ?>
				</h2>
				<?php if ( $archive_url ) : ?>
				<a href="<?php echo esc_url( $archive_url ); ?>" class="cep-homepage-reviews__see-all">
					<?php esc_html_e( 'See all reviews', 'content-engine-pro' ); ?> &rarr;
				</a>
				<?php endif; ?>
			</div>

			<div class="cep-homepage-reviews__grid">
				<?php foreach ( $reviews as $review ) :
					$meta = cep_get_review_meta( $review->ID );
				?>
				<a href="<?php echo esc_url( get_permalink( $review->ID ) ); ?>" class="cep-hp-review-card">

					<?php if ( has_post_thumbnail( $review->ID ) ) : ?>
					<div class="cep-hp-review-card__thumb">
						<?php echo get_the_post_thumbnail( $review->ID, 'cep-card', [ 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $review->ID ) ) ] ); ?>
					</div>
					<?php endif; ?>

					<div class="cep-hp-review-card__body">
						<?php if ( $meta['product_type'] ) : ?>
						<span class="cep-type-badge cep-type-badge--sm">
							<?php echo esc_html( ucfirst( $meta['product_type'] ) ); ?>
						</span>
						<?php endif; ?>

						<h3 class="cep-hp-review-card__title">
							<?php echo esc_html( get_the_title( $review->ID ) ); ?>
						</h3>

						<div class="cep-hp-review-card__rating">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							echo cep_render_stars( $meta['rating'], 'sm' );
							?>
							<span class="cep-hp-review-card__score">
								<?php echo esc_html( number_format( $meta['rating'], 1 ) ); ?>
							</span>
						</div>

						<?php if ( $meta['price'] ) : ?>
						<span class="cep-hp-review-card__price">
							<?php echo esc_html( $meta['price'] ); ?>
						</span>
						<?php endif; ?>
					</div>

				</a>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
		return $content . ob_get_clean();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Nav menu item
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Append a "Reviews" menu item to the primary navigation menu.
	 *
	 * Only targets menus registered at common primary-navigation theme locations
	 * ('primary', 'main', 'header', 'header-menu', 'navigation', 'top').
	 * Theme authors or users can disable this via the
	 * `cep_add_reviews_nav_item` filter.
	 *
	 * @param string    $items HTML of existing menu items.
	 * @param \stdClass $args  wp_nav_menu() arguments.
	 * @return string
	 */
	public function add_reviews_nav_item( string $items, \stdClass $args ): string {
		/**
		 * Filters whether the Reviews nav item should be injected automatically.
		 *
		 * @param bool      $inject Whether to inject.
		 * @param \stdClass $args   wp_nav_menu() arguments.
		 */
		if ( ! apply_filters( 'cep_add_reviews_nav_item', true, $args ) ) {
			return $items;
		}

		// Only inject into well-known "primary" nav locations
		$primary_locations = [ 'primary', 'main', 'header', 'header-menu', 'navigation', 'top', 'top-menu' ];
		$location          = isset( $args->theme_location ) ? (string) $args->theme_location : '';

		if ( $location && ! in_array( $location, $primary_locations, true ) ) {
			return $items;
		}

		// Don't inject if this location isn't assigned a menu (prevents empty menus being polluted)
		if ( $location ) {
			$locations = get_nav_menu_locations();
			if ( empty( $locations[ $location ] ) ) {
				return $items;
			}
		}

		$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
		$archive_url = get_post_type_archive_link( $reviews_cpt );

		if ( ! $archive_url ) {
			return $items;
		}

		// Avoid duplicate — bail if the reviews archive URL is already in the menu
		if ( false !== strpos( $items, esc_url( $archive_url ) ) ) {
			return $items;
		}

		$is_active = is_post_type_archive( $reviews_cpt ) || is_singular( $reviews_cpt );
		$class     = 'menu-item menu-item-type-post_type_archive menu-item-object-' . sanitize_html_class( $reviews_cpt );
		if ( $is_active ) {
			$class .= ' current-menu-item';
		}

		$label  = apply_filters( 'cep_reviews_nav_label', __( 'Reviews', 'content-engine-pro' ) );
		$items .= '<li class="' . esc_attr( $class ) . '">'
		        . '<a href="' . esc_url( $archive_url ) . '">' . esc_html( $label ) . '</a>'
		        . '</li>';

		return $items;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Image sizes
	// ─────────────────────────────────────────────────────────────────────────

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

	// ─────────────────────────────────────────────────────────────────────────
	// Query modifications
	// ─────────────────────────────────────────────────────────────────────────

	public function modify_main_query( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$primary = Settings::get( 'primary_cpt_slug', 'post' );
		$ppp     = (int) Settings::get( 'posts_per_page', 12 );

		if ( is_home() || is_post_type_archive( $primary ) ) {
			$query->set( 'posts_per_page', $ppp );
		}

		// Include primary CPT in search results if it's not the default 'post'
		if ( is_search() && 'post' !== $primary ) {
			$types   = (array) $query->get( 'post_type' );
			$types[] = $primary;
			$query->set( 'post_type', array_unique( $types ) );
		}
	}
}
