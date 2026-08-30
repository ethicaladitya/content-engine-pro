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
		add_action( 'wp_enqueue_scripts',  [ $this, 'job_assets' ] );
		add_action( 'after_setup_theme',   [ $this, 'register_image_sizes' ] );
		add_action( 'pre_get_posts',       [ $this, 'modify_main_query' ] );

		// Reviews CPT frontend features (guard on the enabled toggle)
		if ( Settings::is_enabled( 'reviews_cpt_enabled' ) ) {
			add_filter( 'template_include',  [ $this, 'review_templates' ] );
			add_filter( 'the_content',       [ $this, 'inject_homepage_reviews' ] );
			add_filter( 'wp_nav_menu_items', [ $this, 'add_reviews_nav_item' ], 20, 2 );
		}

		// Jobs CPT frontend features (guard on the enabled toggle)
		if ( Settings::is_enabled( 'jobs_cpt_enabled' ) ) {
			// The active theme is a block/FSE theme with its own
			// templates/single-job.html + templates/archive-job.html that render
			// the correct header/footer parts and the [as_job_meta] shortcode.
			// We deliberately do NOT override template_include for jobs:
			// get_header()/get_footer() are no-ops in block themes, which would
			// strip the site header/footer. Instead we filter the block query
			// (pre_get_posts + query_loop_block_query_vars for wp:query) and
			// inject the filter bar into the block archive via render_block.
			add_action( 'pre_get_posts',          [ $this, 'job_archive_filter' ] );
			add_filter( 'query_loop_block_query_vars', [ $this, 'job_block_query_vars' ], 10, 2 );
			add_filter( 'render_block',           [ $this, 'inject_job_filter_bar' ], 10, 2 );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Jobs assets (filter bar + archive styling)
	// ─────────────────────────────────────────────────────────────────────────

	public function job_assets(): void {
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );

		$load = is_post_type_archive( $jobs_cpt )
			|| is_singular( $jobs_cpt )
			|| is_post_type_archive( Settings::get( 'primary_cpt_slug', 'post' ) )
			|| is_home()
			|| is_front_page();

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style(
			'cep-jobs',
			\CEP_URL . 'assets/css/jobs.css',
			[],
			\CEP_VERSION
		);

		if ( is_post_type_archive( $jobs_cpt ) ) {
			wp_enqueue_script(
				'cep-jobs-filter',
				\CEP_URL . 'assets/js/jobs-filter.js',
				[ 'jquery' ],
				\CEP_VERSION,
				true
			);
			wp_localize_script(
				'cep-jobs-filter',
				'cepJobsL10n',
				[
					'clear' => __( 'Clear', 'content-engine-pro' ),
					'none'  => __( 'No jobs match your filters.', 'content-engine-pro' ),
				]
			);
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Assets (reviews + primary CPT)
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
	 * Apply ?company= / ?location= / ?type= / ?salary= filters on the jobs
	 * archive via meta queries. Falls back to a search-style contains match
	 * when the exact value isn't present (robust to free-text meta from feeds).
	 *
	 * @param \WP_Query $query
	 */
	public function job_archive_filter( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		if ( ! is_post_type_archive( $jobs_cpt ) ) {
			return;
		}

		$meta_query = [];

		$company = isset( $_GET['company'] ) ? sanitize_text_field( wp_unslash( $_GET['company'] ) ) : '';
		if ( $company ) {
			$meta_query[] = [
				'key'     => '_cep_job_company',
				'value'   => $company,
				'compare' => 'LIKE',
			];
		}

		$location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		if ( $location ) {
			$meta_query[] = [
				'key'     => '_cep_job_location',
				'value'   => $location,
				'compare' => 'LIKE',
			];
		}

		$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		if ( $type ) {
			$meta_query[] = [
				'key'     => '_cep_job_type',
				'value'   => $type,
				'compare' => 'LIKE',
			];
		}

		$salary = isset( $_GET['salary'] ) ? sanitize_text_field( wp_unslash( $_GET['salary'] ) ) : '';
		if ( $salary ) {
			// 'yes' => only jobs that advertise a salary; 'no' => only unpaid/undisclosed.
			if ( 'yes' === $salary ) {
				$meta_query[] = [
					'key'     => '_cep_job_salary',
					'value'   => '',
					'compare' => '!=',
				];
			} elseif ( 'no' === $salary ) {
				$meta_query[] = [
					'key'     => '_cep_job_salary',
					'value'   => '',
					'compare' => '=',
				];
			}
		}

		// Free-text search on the job title/company (NOT core WP `s`, which would
		// route to the search template and mix in blog posts with image cards).
		$q = isset( $_GET['cep_q'] ) ? sanitize_text_field( wp_unslash( $_GET['cep_q'] ) ) : '';
		if ( $q ) {
			$query->set( 's', '' ); // disable core search behaviour
			add_filter( 'posts_search', static function ( $search, $wp_query ) use ( $q ) {
				global $wpdb;
				if ( empty( $search ) ) {
					return $search;
				}
				$like = '%' . $wpdb->esc_like( $q ) . '%';
				// Match title OR company meta.
				$search = $wpdb->prepare(
					" AND ( {$wpdb->posts}.post_title LIKE %s OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = '_cep_job_company' AND pm.meta_value LIKE %s ) ) ",
					$like,
					$like
				);
				return $search;
			}, 10, 2 );
		}

		if ( ! empty( $meta_query ) ) {
			$query->set( 'meta_query', $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
	}

	/**
	 * Filter the block-theme `wp:query` loop on the jobs archive.
	 *
	 * Block themes (FSE) ignore `template_include` and use `wp:query`, which is
	 * driven by `query_loop_block_query_vars` rather than `pre_get_posts` for
	 * the main query. This applies the same company/location/type meta filters.
	 *
	 * @param array    $query_vars Vars passed to WP_Query by the block.
	 * @param \WP_Block $block      The query block instance.
	 * @return array
	 */
	public function job_block_query_vars( array $query_vars, \WP_Block $block ): array {
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );

		$post_type = $query_vars['post_type'] ?? '';
		if ( ( is_array( $post_type ) ? in_array( $jobs_cpt, $post_type, true ) : $post_type === $jobs_cpt ) === false ) {
			return $query_vars;
		}

		// Only act when on the actual jobs archive (not an arbitrary query block).
		if ( ! is_post_type_archive( $jobs_cpt ) ) {
			return $query_vars;
		}

		$meta_query = $query_vars['meta_query'] ?? [];

		$company = isset( $_GET['company'] ) ? sanitize_text_field( wp_unslash( $_GET['company'] ) ) : '';
		if ( $company ) {
			$meta_query[] = [ 'key' => '_cep_job_company', 'value' => $company, 'compare' => 'LIKE' ];
		}

		$location = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		if ( $location ) {
			$meta_query[] = [ 'key' => '_cep_job_location', 'value' => $location, 'compare' => 'LIKE' ];
		}

		$type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';
		if ( $type ) {
			$meta_query[] = [ 'key' => '_cep_job_type', 'value' => $type, 'compare' => 'LIKE' ];
		}

		if ( ! empty( $meta_query ) ) {
			$query_vars['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		return $query_vars;
	}

	/**
	 * Inject the jobs filter bar into the block-theme archive.
	 *
	 * Block themes (FSE) don't run our PHP `archive-job.php`, so we prepend the bar to
	 * the `wp:query` (and `wp:query-pagination`) block output on the jobs
	 * archive. Runs once (on the first matching block) to avoid repetition.
	 *
	 * NOTE: the `render_block` filter passes the parsed block as an **array**
	 * (not a `WP_Block` instance) in this WordPress version, so we type-hint
	 * `array` and read `blockName` from it.
	 *
	 * @param string $html  Block output.
	 * @param array  $block Parsed block (associative array).
	 * @return string
	 */
	public function inject_job_filter_bar( string $html, array $block ): string {
		static $done = false;

		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		if ( ! is_post_type_archive( $jobs_cpt ) || $done ) {
			return $html;
		}

		$block_name = (string) ( $block['blockName'] ?? '' );
		if ( 'core/query' !== $block_name && 'core/query-pagination' !== $block_name ) {
			return $html;
		}

		ob_start();
		$this->render_job_filter_bar();
		$bar = ob_get_clean();
		$done = true;

		return $bar . $html;
	}

	/**
	 * Render the jobs filter bar (search + company + location + type).
	 *
	 * Injected into the block-theme archive via `render_block`, or callable
	 * directly. Note: a "salary" filter is intentionally omitted — the jobs
	 * feed does not capture salary for any listing, so it would always be empty.
	 */
	public function render_job_filter_bar(): void {
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );
		if ( ! is_post_type_archive( $jobs_cpt ) ) {
			return;
		}

		$companies = $this->get_job_meta_values( '_cep_job_company' );
		$locations = $this->get_job_meta_values( '_cep_job_location' );
		$types     = $this->get_job_meta_values( '_cep_job_type' );

		$current = [
			'company'  => isset( $_GET['company'] ) ? sanitize_text_field( wp_unslash( $_GET['company'] ) ) : '',
			'location' => isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '',
			'type'     => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '',
			'q'        => isset( $_GET['cep_q'] ) ? sanitize_text_field( wp_unslash( $_GET['cep_q'] ) ) : '',
		];

		$base = esc_url( get_post_type_archive_link( $jobs_cpt ) );
		?>
		<form class="cep-job-filter" method="get" action="<?php echo $base; ?>" role="search" aria-label="<?php esc_attr_e( 'Filter remote jobs', 'content-engine-pro' ); ?>">
			<div class="cep-job-filter__row">
				<input type="search" class="cep-job-filter__search" name="cep_q"
				       value="<?php echo esc_attr( $current['q'] ); ?>"
				       placeholder="<?php esc_attr_e( 'Search title or company…', 'content-engine-pro' ); ?>" aria-label="<?php esc_attr_e( 'Search', 'content-engine-pro' ); ?>">

				<select class="cep-job-filter__select" name="company" aria-label="<?php esc_attr_e( 'Filter by company', 'content-engine-pro' ); ?>">
					<option value=""><?php esc_html_e( 'All companies', 'content-engine-pro' ); ?></option>
					<?php foreach ( $companies as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $current['company'], $c ); ?>><?php echo esc_html( $c ); ?></option>
					<?php endforeach; ?>
				</select>

				<select class="cep-job-filter__select" name="location" aria-label="<?php esc_attr_e( 'Filter by location', 'content-engine-pro' ); ?>">
					<option value=""><?php esc_html_e( 'All locations', 'content-engine-pro' ); ?></option>
					<option value="Remote" <?php selected( $current['location'], 'Remote' ); ?>><?php esc_html_e( 'Remote', 'content-engine-pro' ); ?></option>
					<?php foreach ( $locations as $l ) : ?>
						<?php if ( 'Remote' === $l ) { continue; } ?>
						<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $current['location'], $l ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>

				<select class="cep-job-filter__select" name="type" aria-label="<?php esc_attr_e( 'Filter by job type', 'content-engine-pro' ); ?>">
					<option value=""><?php esc_html_e( 'All types', 'content-engine-pro' ); ?></option>
					<?php foreach ( $types as $t ) : ?>
						<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $current['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
					<?php endforeach; ?>
				</select>

				<button type="submit" class="cep-btn cep-btn--primary cep-job-filter__submit"><?php esc_html_e( 'Filter', 'content-engine-pro' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Distinct, non-empty values for a job meta key (cached per request).
	 *
	 * @param string $meta_key
	 * @return string[]
	 */
	private function get_job_meta_values( string $meta_key ): array {
		static $cache = [];
		if ( isset( $cache[ $meta_key ] ) ) {
			return $cache[ $meta_key ];
		}

		global $wpdb;
		$values = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = %s
			   AND pm.meta_value != ''
			   AND p.post_type = %s
			   AND p.post_status = 'publish'",
			$meta_key,
			Settings::get( 'jobs_cpt_slug', 'job' )
		) );

		$values = array_map( 'trim', $values );
		$values = array_filter( $values );
		sort( $values );
		$cache[ $meta_key ] = $values;

		return $values;
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
