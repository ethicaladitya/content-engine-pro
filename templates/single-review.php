<?php
/**
 * Content Engine Pro — Single Review Template
 *
 * Renders the full single-review page for the review CPT.
 * Overrides the theme template via class-frontend.php `template_include` filter.
 *
 * @package ContentEnginePro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ContentEnginePro\Settings;

$reviews_cpt = Settings::get( 'reviews_cpt_slug', 'review' );
$archive_url = get_post_type_archive_link( $reviews_cpt );

get_header();

while ( have_posts() ) :
	the_post();

	$post_id = get_the_ID();
	$meta    = cep_get_review_meta( $post_id );

	// Clean product name: strip " Review", " Review: subtitle", "- Review", etc.
	// The `u` flag enables proper Unicode matching (handles em-dash, curly quotes, etc.)
	$product_name = preg_replace( '/[\s\x{2013}\x{2014}\-:]+review.*$/iu', '', get_the_title() );
	$product_name = trim( $product_name ) ?: get_the_title();

	// Clean price: only show if it looks like a genuine price value.
	// Accepts: $9.99, €4/mo, £29/month, USD 10, "Free" (exactly), "Free trial"
	// Rejects:  "Free – /month", "Free –", "", gibberish strings
	$raw_price  = $meta['price'];
	$show_price = false;
	if ( $raw_price ) {
		$raw_trimmed = trim( $raw_price );
		$show_price  = (bool) preg_match( '/(\$|€|£|USD|EUR|GBP)\s*\d/', $raw_trimmed )   // currency + digit
		            || (bool) preg_match( '/\d+[\.,]?\d*\s*(\/mo|\/yr|per month|per year)/i', $raw_trimmed ) // digit + period
		            || (bool) preg_match( '/^free(\s+trial|\s+tier|\s+plan)?$/i', $raw_trimmed );            // "Free", "Free trial" etc.
	}

	// Build the primary CTA URL.
	// Affiliate redirect takes priority. For a raw product URL we only use it if it
	// looks like an actual product/brand domain — NOT an editorial article URL — to
	// avoid pointing readers back to a news source.
	$editorial_domains = [
		'allure.com', 'byrdie.com', 'glamour.com', 'cosmopolitan.com',
		'refinery29.com', 'wellandgood.com', 'greatist.com', 'intothegloss.com',
		'techcrunch.com', 'theverge.com', 'engadget.com', 'wired.com',
		'ign.com', 'polygon.com', 'kotaku.com', 'forbes.com', 'businessinsider.com',
		'nerdwallet.com', 'investopedia.com', 'wordpress.org', 'wptavern.com',
		'shopify.com', 'seriouseats.com', 'bonappetit.com', 'eater.com',
	];
	$cta_url = '';
	if ( $meta['aff_slug'] ) {
		$cta_url = cep_get_affiliate_redirect_url( $meta['aff_slug'] );
	} elseif ( $meta['product_url'] ) {
		$parsed_host = wp_parse_url( $meta['product_url'], PHP_URL_HOST ) ?? '';
		$parsed_host = ltrim( strtolower( $parsed_host ), 'www.' );
		$is_editorial = false;
		foreach ( $editorial_domains as $domain ) {
			if ( false !== strpos( $parsed_host, $domain ) ) {
				$is_editorial = true;
				break;
			}
		}
		if ( ! $is_editorial ) {
			$cta_url = $meta['product_url'];
		}
	}

	// Related reviews (same post type, exclude current, latest 3)
	$related = get_posts(
		[
			'post_type'      => $reviews_cpt,
			'post_status'    => 'publish',
			'posts_per_page' => 3,
			'post__not_in'   => [ $post_id ],
			'orderby'        => 'date',
			'order'          => 'DESC',
		]
	);
?>

<div class="cep-reviews-page">
<article class="cep-single-review" itemscope itemtype="https://schema.org/Review">

	<!-- ── Breadcrumb ── -->
	<nav class="cep-single-review__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'content-engine-pro' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'content-engine-pro' ); ?></a>
		<span aria-hidden="true">/</span>
		<a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'Reviews', 'content-engine-pro' ); ?></a>
		<span aria-hidden="true">/</span>
		<span aria-current="page"><?php the_title(); ?></span>
	</nav>

	<!-- ── Hero / Product Header ── -->
	<div class="cep-single-review__hero">

		<div class="cep-single-review__hero-info">
			<?php if ( $meta['product_type'] ) : ?>
			<span class="cep-type-badge"><?php echo esc_html( ucfirst( $meta['product_type'] ) ); ?></span>
			<?php endif; ?>

			<h1 class="cep-single-review__title" itemprop="name"><?php the_title(); ?></h1>

			<div class="cep-single-review__rating">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo cep_render_stars( $meta['rating'] );
				?>
				<strong class="cep-single-review__score">
					<?php echo esc_html( number_format( $meta['rating'], 1 ) ); ?>/5
				</strong>
				<?php if ( $show_price ) : ?>
				<span class="cep-single-review__price">
					<?php echo esc_html__( 'From', 'content-engine-pro' ) . ' ' . esc_html( $raw_price ); ?>
				</span>
				<?php endif; ?>
			</div>

			<?php if ( $cta_url ) : ?>
			<a href="<?php echo esc_url( $cta_url ); ?>"
			   class="cep-btn cep-btn--primary cep-btn--lg"
			   target="_blank"
			   rel="noopener sponsored">
				<?php
				/* translators: %s: product name */
				echo esc_html( sprintf( __( 'Visit %s →', 'content-engine-pro' ), $product_name ) );
				?>
			</a>
			<p class="cep-single-review__disclosure">
				<?php esc_html_e( 'This link may earn us a small commission — at no extra cost to you.', 'content-engine-pro' ); ?>
			</p>
			<?php endif; ?>
		</div>

	</div><!-- /.cep-single-review__hero -->

	<!-- ── Quick Verdict Score Bar ── -->
	<?php if ( $meta['rating'] ) : ?>
	<div class="cep-score-bar">
		<span class="cep-score-bar__label"><?php esc_html_e( 'Overall Score', 'content-engine-pro' ); ?></span>
		<div class="cep-score-bar__track" role="progressbar" aria-valuenow="<?php echo esc_attr( $meta['rating'] ); ?>" aria-valuemin="0" aria-valuemax="5">
			<div class="cep-score-bar__fill" style="width:<?php echo esc_attr( ( $meta['rating'] / 5 ) * 100 ); ?>%"></div>
		</div>
		<span class="cep-score-bar__num"><?php echo esc_html( number_format( $meta['rating'], 1 ) ); ?></span>
	</div>
	<?php endif; ?>

	<!-- ── Pros / Cons ── -->
	<?php if ( ! empty( $meta['pros'] ) || ! empty( $meta['cons'] ) ) : ?>
	<div class="cep-single-review__pros-cons">

		<?php if ( ! empty( $meta['pros'] ) ) : ?>
		<div class="cep-pros-cons-box cep-pros-cons-box--pros">
			<h3 class="cep-pros-cons-box__heading">
				<span class="cep-pros-cons-box__icon" aria-hidden="true">✓</span>
				<?php esc_html_e( 'What We Like', 'content-engine-pro' ); ?>
			</h3>
			<ul>
				<?php foreach ( $meta['pros'] as $pro ) : ?>
				<li><?php echo esc_html( $pro ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $meta['cons'] ) ) : ?>
		<div class="cep-pros-cons-box cep-pros-cons-box--cons">
			<h3 class="cep-pros-cons-box__heading">
				<span class="cep-pros-cons-box__icon" aria-hidden="true">✗</span>
				<?php esc_html_e( 'What Could Be Better', 'content-engine-pro' ); ?>
			</h3>
			<ul>
				<?php foreach ( $meta['cons'] as $con ) : ?>
				<li><?php echo esc_html( $con ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

	</div>
	<?php endif; ?>

	<!-- ── Full Review Content ── -->
	<div class="cep-single-review__content entry-content" itemprop="reviewBody">
		<?php the_content(); ?>
	</div>

	<!-- ── Verdict Box ── -->
	<?php if ( $meta['verdict'] ) : ?>
	<div class="cep-verdict-box" itemprop="reviewRating" itemscope itemtype="https://schema.org/Rating">
		<meta itemprop="ratingValue" content="<?php echo esc_attr( $meta['rating'] ); ?>">
		<meta itemprop="bestRating" content="5">
		<div class="cep-verdict-box__header">
			<div class="cep-verdict-box__score-wrap">
				<span class="cep-verdict-box__score"><?php echo esc_html( number_format( $meta['rating'], 1 ) ); ?></span>
				<span class="cep-verdict-box__max">/5</span>
			</div>
			<div>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo cep_render_stars( $meta['rating'] );
				?>
				<strong class="cep-verdict-box__label"><?php esc_html_e( 'Our Verdict', 'content-engine-pro' ); ?></strong>
			</div>
		</div>
		<p class="cep-verdict-box__text"><?php echo esc_html( $meta['verdict'] ); ?></p>
	</div>
	<?php endif; ?>

	<!-- ── Bottom CTA ── -->
	<?php if ( $cta_url ) : ?>
	<div class="cep-single-review__bottom-cta">
		<a href="<?php echo esc_url( $cta_url ); ?>"
		   class="cep-btn cep-btn--primary cep-btn--lg"
		   target="_blank"
		   rel="noopener sponsored">
			<?php
			/* translators: %s: product name */
			echo esc_html( sprintf( __( 'Visit %s →', 'content-engine-pro' ), $product_name ) );
			?>
		</a>
		<?php if ( $show_price ) : ?>
		<span class="cep-single-review__bottom-price">
			<?php
			/* translators: %s: price string e.g. $4.99/month */
			echo esc_html( sprintf( __( 'Starting from %s', 'content-engine-pro' ), $raw_price ) );
			?>
		</span>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ── Meta Footer ── -->
	<footer class="cep-single-review__footer">
		<time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>">
			<?php
			/* translators: %s: date string */
			echo esc_html( sprintf( __( 'Last updated: %s', 'content-engine-pro' ), get_the_modified_date() ) );
			?>
		</time>
	</footer>

</article>

<!-- ── Related Reviews ── -->
<?php if ( ! empty( $related ) ) : ?>
<section class="cep-related-reviews">
	<div class="cep-related-reviews__inner">
		<h2 class="cep-related-reviews__title"><?php esc_html_e( 'More Reviews', 'content-engine-pro' ); ?></h2>
		<div class="cep-related-reviews__grid">
			<?php foreach ( $related as $rel ) :
				$rel_meta = cep_get_review_meta( $rel->ID );
			?>
			<a href="<?php echo esc_url( get_permalink( $rel->ID ) ); ?>" class="cep-related-reviews__card">
				<?php if ( has_post_thumbnail( $rel->ID ) ) : ?>
				<div class="cep-related-reviews__thumb">
					<?php echo get_the_post_thumbnail( $rel->ID, 'cep-card', [ 'loading' => 'lazy', 'alt' => esc_attr( get_the_title( $rel->ID ) ) ] ); ?>
				</div>
				<?php endif; ?>
				<div class="cep-related-reviews__body">
					<?php if ( $rel_meta['product_type'] ) : ?>
					<span class="cep-type-badge cep-type-badge--sm"><?php echo esc_html( ucfirst( $rel_meta['product_type'] ) ); ?></span>
					<?php endif; ?>
					<h3><?php echo esc_html( get_the_title( $rel->ID ) ); ?></h3>
					<div class="cep-stars cep-stars--sm">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo cep_render_stars( $rel_meta['rating'], 'sm' );
						?>
						<span class="cep-related-reviews__score"><?php echo esc_html( number_format( $rel_meta['rating'], 1 ) ); ?></span>
					</div>
				</div>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

</div><!-- /.cep-reviews-page -->

<?php endwhile; ?>

<?php get_footer(); ?>
