<?php
/**
 * Content Engine Pro — Reviews Archive Template
 *
 * Used for the /reviews/ archive page and review-type taxonomy archives.
 * Overrides the theme template via class-frontend.php `template_include` filter.
 *
 * @package ContentEnginePro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ContentEnginePro\Settings;

$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
$reviews_tax = Settings::get( 'reviews_tax_slug', 'review-type' );
$paged       = max( 1, (int) get_query_var( 'paged' ) );

// Sanitize type filter from query string
$filter_type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

$args = [
	'post_type'      => $reviews_cpt,
	'post_status'    => 'publish',
	'posts_per_page' => (int) Settings::get( 'reviews_per_page', 12 ),
	'paged'          => $paged,
	'orderby'        => 'date',
	'order'          => 'DESC',
	'no_found_rows'  => false,
];

if ( $filter_type ) {
	$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		[
			'taxonomy' => $reviews_tax,
			'field'    => 'slug',
			'terms'    => $filter_type,
		],
	];
}

$reviews_query = new WP_Query( $args );

// Taxonomy filter tabs
$review_types = get_terms(
	[
		'taxonomy'   => $reviews_tax,
		'hide_empty' => true,
		'orderby'    => 'name',
	]
);

$archive_url = get_post_type_archive_link( $reviews_cpt );

get_header();
?>
<div class="cep-reviews-page">

	<!-- ── Archive Hero ── -->
	<div class="cep-reviews-archive__hero">
		<div class="cep-reviews-archive__hero-inner">
			<p class="cep-reviews-archive__hero-eyebrow">Trusted &amp; In-Depth</p>
			<h1 class="cep-reviews-archive__hero-title">Product Reviews</h1>
			<p class="cep-reviews-archive__hero-sub">Real-world tests, honest ratings, and zero fluff.</p>
		</div>
	</div>

	<div class="cep-reviews-archive__wrap">

		<!-- ── Filter Tabs ── -->
		<?php if ( ! empty( $review_types ) && ! is_wp_error( $review_types ) ) : ?>
		<nav class="cep-reviews-filter" aria-label="<?php esc_attr_e( 'Filter reviews by type', 'content-engine-pro' ); ?>">
			<a href="<?php echo esc_url( $archive_url ); ?>"
			   class="cep-reviews-filter__tab<?php echo ! $filter_type ? ' is-active' : ''; ?>">
				<?php esc_html_e( 'All', 'content-engine-pro' ); ?>
			</a>
			<?php foreach ( $review_types as $type ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'type', $type->slug, $archive_url ) ); ?>"
				   class="cep-reviews-filter__tab<?php echo $filter_type === $type->slug ? ' is-active' : ''; ?>">
					<?php echo esc_html( ucfirst( $type->name ) ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php endif; ?>

		<!-- ── Reviews Grid ── -->
		<?php if ( $reviews_query->have_posts() ) : ?>
		<div class="cep-reviews-grid">
			<?php
			while ( $reviews_query->have_posts() ) :
				$reviews_query->the_post();
				$meta = cep_get_review_meta( get_the_ID() );
			?>
			<a href="<?php the_permalink(); ?>" class="cep-reviews-grid__card">

				<?php if ( has_post_thumbnail() ) : ?>
				<div class="cep-reviews-grid__thumb">
					<?php the_post_thumbnail( 'cep-card', [ 'loading' => 'lazy', 'alt' => get_the_title() ] ); ?>
					<?php if ( $meta['rating'] >= 4.5 ) : ?>
					<span class="cep-reviews-grid__badge cep-reviews-grid__badge--top">Top Pick</span>
					<?php elseif ( $meta['rating'] >= 4.0 ) : ?>
					<span class="cep-reviews-grid__badge cep-reviews-grid__badge--rec">Recommended</span>
					<?php endif; ?>
				</div>
				<?php endif; ?>

				<div class="cep-reviews-grid__body">
					<?php if ( $meta['product_type'] ) : ?>
					<span class="cep-type-badge"><?php echo esc_html( ucfirst( $meta['product_type'] ) ); ?></span>
					<?php endif; ?>

					<h2 class="cep-reviews-grid__title"><?php the_title(); ?></h2>

					<div class="cep-reviews-grid__rating">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo cep_render_stars( $meta['rating'], 'sm' );
						?>
						<span class="cep-reviews-grid__score"><?php echo esc_html( number_format( $meta['rating'], 1 ) ); ?></span>
						<?php if ( $meta['price'] ) : ?>
						<span class="cep-reviews-grid__price"><?php echo esc_html( $meta['price'] ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( has_excerpt() ) : ?>
					<p class="cep-reviews-grid__excerpt">
						<?php echo esc_html( wp_trim_words( get_the_excerpt(), 18 ) ); ?>
					</p>
					<?php endif; ?>

					<span class="cep-reviews-grid__cta" aria-hidden="true">Read Review &rarr;</span>
				</div>

			</a>
			<?php endwhile; wp_reset_postdata(); ?>
		</div>

		<!-- ── Pagination ── -->
		<?php if ( $reviews_query->max_num_pages > 1 ) : ?>
		<nav class="cep-reviews-pagination" aria-label="<?php esc_attr_e( 'Reviews pages', 'content-engine-pro' ); ?>">
			<?php
			echo wp_kses_post(
				paginate_links(
					[
						'total'   => $reviews_query->max_num_pages,
						'current' => $paged,
						'prev_text' => '&laquo; ' . esc_html__( 'Previous', 'content-engine-pro' ),
						'next_text' => esc_html__( 'Next', 'content-engine-pro' ) . ' &raquo;',
					]
				)
			);
			?>
		</nav>
		<?php endif; ?>

		<?php else : ?>
		<div class="cep-reviews-empty">
			<p><?php esc_html_e( 'No reviews found. Check back soon!', 'content-engine-pro' ); ?></p>
		</div>
		<?php endif; ?>

	</div><!-- /.cep-reviews-archive__wrap -->
</div><!-- /.cep-reviews-page -->

<?php get_footer(); ?>
