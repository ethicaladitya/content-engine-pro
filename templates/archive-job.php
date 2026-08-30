<?php
/**
 * Content Engine Pro — Jobs Archive Template.
 *
 * Renders the remote-jobs board with a filter bar (company / location / type /
 * salary / search) and the job listing cards. Reuses the existing card markup
 * (`.as-card`) so it matches the current design.
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
?>

<div class="cep-jobs-archive">
	<header class="cep-jobs-archive__header">
		<h1 class="cep-jobs-archive__title"><?php esc_html_e( 'Remote jobs worth a look', 'content-engine-pro' ); ?></h1>
		<p class="cep-jobs-archive__intro"><?php esc_html_e( 'Hand-picked remote roles, curated not offered. Apply with the employer directly.', 'content-engine-pro' ); ?></p>
	</header>

	<?php
	/**
	 * Fires before the jobs loop. The plugin renders the filter bar here.
	 */
	do_action( 'cep_jobs_archive_before_loop' );
	?>

	<?php if ( have_posts() ) : ?>
		<ul class="cep-jobs-archive__list">
			<?php while ( have_posts() ) : the_post();
				$post_id = get_the_ID();
				$meta    = cep_get_job_meta( $post_id );
				$company = $meta['company'] ?: '';
				$initial = $company ? strtoupper( $company[0] ) : '•';
			?>
				<li class="wp-block-post post-<?php echo (int) $post_id; ?> job type-job status-publish hentry">
					<div class="wp-block-group as-card" style="padding:1.15rem 1.35rem;display:flex;gap:1rem;align-items:flex-start;">
						<p>
							<span class="as-co">
								<span class="as-co-badge" aria-hidden="true"><?php echo esc_html( $initial ); ?></span>
								<?php if ( $company ) : ?>
								<span class="as-co-name"><?php echo esc_html( $company ); ?></span>
								<?php endif; ?>
							</span>
						</p>
						<div style="flex:1 1 auto;min-width:0">
							<h2 class="wp-block-post-title has-large-font-size" style="line-height:1.25">
								<a href="<?php echo esc_url( get_permalink( $post_id ) ); ?>"><?php the_title(); ?></a>
							</h2>
							<p class="as-co-meta">
								<?php if ( $meta['location'] ) : ?>
									<?php echo esc_html( $meta['location'] ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Remote', 'content-engine-pro' ); ?>
								<?php endif; ?>
								<?php if ( $meta['type'] ) : ?>
									<span aria-hidden="true"> · </span><?php echo esc_html( $meta['type'] ); ?>
								<?php endif; ?>
								<?php if ( $meta['salary'] ) : ?>
									<span aria-hidden="true"> · </span><?php echo esc_html( $meta['salary'] ); ?>
								<?php endif; ?>
							</p>
							<div class="wp-block-post-date has-small-font-size">
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
							</div>
						</div>
					</div>
				</li>
			<?php endwhile; ?>
		</ul>

		<?php
		the_posts_pagination(
			[
				'mid_size'  => 2,
				'prev_text' => __( '← Older', 'content-engine-pro' ),
				'next_text' => __( 'Newer →', 'content-engine-pro' ),
			]
		);
		?>
	<?php else : ?>
		<p class="cep-jobs-archive__empty"><?php esc_html_e( 'No jobs match your filters.', 'content-engine-pro' ); ?></p>
	<?php endif; ?>
</div>

<?php
get_footer();
