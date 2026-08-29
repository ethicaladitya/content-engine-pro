<?php
/**
 * Content Engine Pro — Single Job Template.
 *
 * Renders a structured job detail page for the jobs CPT, using the
 * `_cep_job_*` meta saved by the jobs autopilot. Falls back gracefully when
 * meta is missing (older / manually imported posts).
 *
 * Overrides the theme template via class-frontend.php `template_include` filter.
 *
 * @package ContentEnginePro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ContentEnginePro\Settings;

$jobs_cpt   = Settings::get( 'jobs_cpt_slug', 'job' );
$archive_url = get_post_type_archive_link( $jobs_cpt );

get_header();

while ( have_posts() ) :
	the_post();

	$post_id = get_the_ID();
	$meta    = cep_get_job_meta( $post_id );

	$company  = $meta['company'] ?: $meta['source'] ?: __( 'Curated role', 'content-engine-pro' );
	$location = $meta['location'] ?: '';
	$type     = $meta['type'] ?: '';
	$salary   = $meta['salary'] ?: '';

	// Apply link: prefer the original listing URL.
	$apply_url = $meta['url'] ?: '';
	// Detect an "apply/remote" paragraph the autopilot may have injected into content.
	if ( ! $apply_url ) {
		if ( preg_match( '/<a\s+[^>]*href="([^"]+)"[^>]*>\s*(?:Apply|View|Read more)/i', get_the_content(), $m ) ) {
			$apply_url = $m[1];
		}
	}

	// Company initials for the avatar-style badge.
	$initials = '';
	foreach ( explode( ' ', preg_replace( '/[^A-Za-z0-9 ]/', ' ', $company ) ) as $w ) {
		if ( $w !== '' ) {
			$initials .= strtoupper( $w[0] );
		}
		$initials = substr( $initials, 0, 2 );
		break;
	}
	if ( strlen( $initials ) < 2 ) {
		$initials = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]/', '', $company ), 0, 2 ) );
	}

	$related = get_posts(
		[
			'post_type'      => $jobs_cpt,
			'post_status'    => 'publish',
			'posts_per_page' => 3,
			'post__not_in'   => [ $post_id ],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		]
	);
?>

<div class="cep-jobs-page">
<article class="cep-single-job" itemscope itemtype="https://schema.org/JobPosting">

	<!-- ── Breadcrumb ── -->
	<nav class="cep-single-job__breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'content-engine-pro' ); ?>">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Home', 'content-engine-pro' ); ?></a>
		<span aria-hidden="true">/</span>
		<a href="<?php echo esc_url( $archive_url ); ?>"><?php esc_html_e( 'Remote jobs', 'content-engine-pro' ); ?></a>
		<span aria-hidden="true">/</span>
		<span aria-current="page"><?php the_title(); ?></span>
	</nav>

	<!-- ── Company header ── -->
	<header class="cep-single-job__header">
		<div class="cep-single-job__logo" aria-hidden="true"><?php echo esc_html( $initials ); ?></div>
		<div class="cep-single-job__heading">
			<h1 class="cep-single-job__title" itemprop="title"><?php the_title(); ?></h1>
			<p class="cep-single-job__company" itemprop="hiringOrganization" itemscope itemtype="https://schema.org/Organization">
				<span itemprop="name"><?php echo esc_html( $company ); ?></span>
			</p>
		</div>
	</header>

	<!-- ── Fact bar: location / type / salary ── -->
	<?php if ( $location || $type || $salary ) : ?>
	<div class="cep-single-job__facts">
		<?php if ( $location ) : ?>
			<span class="cep-job-fact cep-job-fact--location">
				<span class="cep-job-fact__icon" aria-hidden="true">📍</span>
				<?php echo esc_html( $location ); ?>
			</span>
		<?php endif; ?>
		<?php if ( $type ) : ?>
			<span class="cep-job-fact cep-job-fact--type">
				<span class="cep-job-fact__icon" aria-hidden="true">💼</span>
				<?php echo esc_html( $type ); ?>
			</span>
		<?php endif; ?>
		<?php if ( $salary ) : ?>
			<span class="cep-job-fact cep-job-fact--salary">
				<span class="cep-job-fact__icon" aria-hidden="true">💰</span>
				<?php echo esc_html( $salary ); ?>
			</span>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- ── Primary CTA ── -->
	<?php if ( $apply_url ) : ?>
	<div class="cep-single-job__cta">
		<a href="<?php echo esc_url( $apply_url ); ?>"
		   class="cep-btn cep-btn--primary cep-btn--lg"
		   target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Apply for this role →', 'content-engine-pro' ); ?>
		</a>
		<p class="cep-single-job__disclosure">
			<?php esc_html_e( 'Curated, not offered — apply with the employer directly.', 'content-engine-pro' ); ?>
		</p>
	</div>
	<?php endif; ?>

	<!-- ── Job description ── -->
	<div class="cep-single-job__content entry-content" itemprop="description">
		<?php the_content(); ?>
	</div>

	<!-- ── Bottom CTA ── -->
	<?php if ( $apply_url ) : ?>
	<div class="cep-single-job__bottom-cta">
		<a href="<?php echo esc_url( $apply_url ); ?>"
		   class="cep-btn cep-btn--primary cep-btn--lg"
		   target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Apply for this role →', 'content-engine-pro' ); ?>
		</a>
	</div>
	<?php endif; ?>

	<!-- ── Meta footer ── -->
	<footer class="cep-single-job__footer">
		<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>">
			<?php
			/* translators: %s: date string */
			echo esc_html( sprintf( __( 'Posted: %s', 'content-engine-pro' ), get_the_date() ) );
			?>
		</time>
		<?php if ( $meta['source'] ) : ?>
			<span class="cep-single-job__source">· <?php esc_html_e( 'Source:', 'content-engine-pro' ); ?> <?php echo esc_html( wp_parse_url( $meta['source'], PHP_URL_HOST ) ?: $meta['source'] ); ?></span>
		<?php endif; ?>
	</footer>

</article>

<!-- ── Related jobs ── -->
<?php if ( ! empty( $related ) ) : ?>
<section class="cep-related-jobs">
	<div class="cep-related-jobs__inner">
		<h2 class="cep-related-jobs__title"><?php esc_html_e( 'More remote roles', 'content-engine-pro' ); ?></h2>
		<div class="cep-related-jobs__grid">
			<?php foreach ( $related as $rel ) :
				$rel_meta = cep_get_job_meta( $rel->ID );
			?>
			<a href="<?php echo esc_url( get_permalink( $rel->ID ) ); ?>" class="cep-related-jobs__card">
				<div class="cep-related-jobs__body">
					<h3 class="cep-related-jobs__role"><?php echo esc_html( get_the_title( $rel->ID ) ); ?></h3>
					<?php if ( $rel_meta['company'] || $rel_meta['location'] ) : ?>
					<p class="cep-related-jobs__meta">
						<?php if ( $rel_meta['company'] ) : ?><span><?php echo esc_html( $rel_meta['company'] ); ?></span><?php endif; ?>
						<?php if ( $rel_meta['location'] ) : ?><span><?php echo esc_html( $rel_meta['location'] ); ?></span><?php endif; ?>
					</p>
					<?php endif; ?>
				</div>
			</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

</div><!-- /.cep-jobs-page -->

<?php endwhile; ?>

<?php get_footer(); ?>
