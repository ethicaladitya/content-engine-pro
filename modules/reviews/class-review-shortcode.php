<?php
namespace ContentEnginePro\Reviews;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [cep_review slug="product-slug" show_cta="yes"]
 * Renders an embeddable review card widget.
 */
class ReviewShortcode {

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'slug'     => '',
			'id'       => '',
			'show_cta' => 'yes',
		], $atts, 'cep_review' );

		$post = null;

		if ( $atts['id'] ) {
			$post = get_post( (int) $atts['id'] );
		} elseif ( $atts['slug'] ) {
			$posts = get_posts( [
				'post_type'   => Settings::get( 'reviews_cpt_slug', 'review' ),
				'name'        => sanitize_title( $atts['slug'] ),
				'numberposts' => 1,
				'post_status' => 'publish',
			] );
			$post = $posts[0] ?? null;
		}

		if ( ! $post ) {
			return '';
		}

		$rating      = (float) get_post_meta( $post->ID, '_cep_review_star_rating', true );
		$pros_raw    = get_post_meta( $post->ID, '_cep_review_pros', true );
		$cons_raw    = get_post_meta( $post->ID, '_cep_review_cons', true );
		$verdict     = get_post_meta( $post->ID, '_cep_review_verdict', true );
		$price       = get_post_meta( $post->ID, '_cep_review_price_from', true );
		$aff_slug    = get_post_meta( $post->ID, '_cep_review_affiliate_slug', true );
		$product_type = get_post_meta( $post->ID, '_cep_review_product_type', true );

		$pros = array_filter( array_slice( explode( "\n", $pros_raw ), 0, 3 ) );
		$cons = array_filter( array_slice( explode( "\n", $cons_raw ), 0, 2 ) );

		ob_start();
		?>
		<div class="cep-review-card">
			<div class="cep-review-card__header">
				<?php if ( has_post_thumbnail( $post->ID ) ) : ?>
					<div class="cep-review-card__logo">
						<?php echo get_the_post_thumbnail( $post->ID, 'thumbnail' ); ?>
					</div>
				<?php endif; ?>
				<div class="cep-review-card__title-wrap">
					<h3 class="cep-review-card__title">
						<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>"><?php echo esc_html( get_the_title( $post->ID ) ); ?></a>
					</h3>
					<?php if ( $product_type ) : ?>
						<span class="cep-review-card__type-badge"><?php echo esc_html( ucfirst( $product_type ) ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<div class="cep-review-card__rating">
				<div class="cep-stars" aria-label="Rating: <?php echo esc_attr( $rating ); ?> out of 5">
					<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
						<span class="cep-star <?php echo $i <= round( $rating ) ? 'cep-star--filled' : ''; ?>">&#9733;</span>
					<?php endfor; ?>
				</div>
				<span class="cep-review-card__rating-num"><?php echo esc_html( number_format( $rating, 1 ) ); ?>/5</span>
				<?php if ( $price ) : ?>
					<span class="cep-review-card__price">From <?php echo esc_html( $price ); ?></span>
				<?php endif; ?>
			</div>

			<div class="cep-review-card__body">
				<?php if ( $pros ) : ?>
					<div class="cep-review-card__pros">
						<strong class="cep-review-card__list-title">✓ Pros</strong>
						<ul>
							<?php foreach ( $pros as $pro ) : ?>
								<li><?php echo esc_html( $pro ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $cons ) : ?>
					<div class="cep-review-card__cons">
						<strong class="cep-review-card__list-title">✗ Cons</strong>
						<ul>
							<?php foreach ( $cons as $con ) : ?>
								<li><?php echo esc_html( $con ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $verdict ) : ?>
					<div class="cep-review-card__verdict">
						<strong>Verdict:</strong> <?php echo esc_html( $verdict ); ?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( 'yes' === $atts['show_cta'] && $aff_slug ) : ?>
				<div class="cep-review-card__cta">
					<a href="<?php echo esc_url( cep_get_affiliate_redirect_url( $aff_slug ) ); ?>" class="cep-btn cep-btn--primary" target="_blank" rel="noopener sponsored">
						Visit <?php echo esc_html( get_the_title( $post->ID ) ); ?> &rarr;
					</a>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
