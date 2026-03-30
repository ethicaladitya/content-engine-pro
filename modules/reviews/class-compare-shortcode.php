<?php
namespace ContentEnginePro\Reviews;

use ContentEnginePro\Settings;
use ContentEnginePro\Affiliate\AffiliateManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * [cep_compare providers="slug1,slug2,slug3" title="..." highlight="slug1"]
 */
class CompareShortcode {

	public function render( array $atts ): string {
		$atts = shortcode_atts( [
			'providers' => '',
			'title'     => Settings::get( 'compare_default_title', 'Top Picks Compared' ),
			'highlight' => '',
		], $atts, 'cep_compare' );

		if ( empty( $atts['providers'] ) ) {
			return '';
		}

		$slugs     = array_map( 'sanitize_title', array_map( 'trim', explode( ',', $atts['providers'] ) ) );
		$providers = [];

		foreach ( $slugs as $slug ) {
			$p = AffiliateManager::get_provider( $slug );
			if ( $p ) {
				$providers[] = $p;
			}
		}

		if ( empty( $providers ) ) {
			return '';
		}

		$disclosure = Settings::get( 'affiliate_disclosure_text' );
		$highlight  = sanitize_title( $atts['highlight'] );

		ob_start();
		?>
		<div class="cep-compare-table">
			<?php if ( $atts['title'] ) : ?>
				<h3 class="cep-compare-table__title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>

			<?php if ( $disclosure ) : ?>
				<p class="cep-compare-table__disclosure"><?php echo wp_kses_post( $disclosure ); ?></p>
			<?php endif; ?>

			<div class="cep-compare-table__wrap">
				<table class="cep-compare-table__table">
					<thead>
						<tr>
							<th>Provider</th>
							<th>Rating</th>
							<th>Starting Price</th>
							<th>Infrastructure</th>
							<th>Best For</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $providers as $p ) :
							$is_best = $highlight && $p['slug'] === $highlight;
						?>
						<tr class="<?php echo $is_best ? 'cep-compare-table__row--best' : ''; ?>">
							<td class="cep-compare-table__name">
								<?php if ( $is_best ) : ?>
									<span class="cep-compare-table__best-badge">Best Pick</span>
								<?php endif; ?>
								<strong><?php echo esc_html( $p['name'] ); ?></strong>
							</td>
							<td>
								<div class="cep-stars cep-stars--sm">
									<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
										<span class="cep-star <?php echo $i <= round( $p['star_rating'] ) ? 'cep-star--filled' : ''; ?>">&#9733;</span>
									<?php endfor; ?>
								</div>
								<span class="cep-compare-table__rating"><?php echo esc_html( number_format( $p['star_rating'], 1 ) ); ?></span>
							</td>
							<td><?php echo esc_html( $p['display_price'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $p['infra_type'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $p['target_audience'] ?: '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( cep_get_affiliate_redirect_url( $p['slug'] ) ); ?>" class="cep-btn cep-btn--sm cep-btn--primary" target="_blank" rel="noopener sponsored">
									Visit &rarr;
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
