<?php
namespace ContentEnginePro\Jobs;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfill / re-format existing job posts.
 *
 * Live job posts were published as the `minimal_format()` fallback (a single
 * stripped paragraph) because the AI formatting step failed at ingest time.
 * This rebuilds clean, structured `post_content` from the original listing
 * data we already stored in `_cep_job_*` meta + the raw description, so the
 * single-job template renders properly without re-fetching the feeds.
 */
class JobReformat {

	/**
	 * Re-format all (or a batch of) published job posts.
	 *
	 * @param int $limit  Max posts to process (0 = all).
	 * @return int        Number of posts actually updated.
	 */
	public static function run( int $limit = 0 ): int {
		$jobs_cpt = Settings::get( 'jobs_cpt_slug', 'job' );

		$args = [
			'post_type'      => $jobs_cpt,
			'post_status'    => 'publish',
			'posts_per_page' => $limit > 0 ? $limit : 500,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		$posts = get_posts( $args );
		if ( empty( $posts ) ) {
			Logger::log( 'Job reformat: no published jobs found', 'info', 'job_reformat' );
			return 0;
		}

		$updated = 0;
		foreach ( $posts as $post ) {
			$meta      = cep_get_job_meta( $post->ID );
			$raw_desc  = wp_strip_all_tags( $post->post_content );
			$raw_desc  = trim( preg_replace( '/Apply for this (position|role).*?$/is', '', $raw_desc ) );
			$raw_desc  = trim( preg_replace( '/^Headquarters:\s*.*$/im', '', $raw_desc ) );

			if ( '' === $raw_desc && ! $meta['url'] ) {
				continue; // nothing to work with
			}

			$content = self::build_content( $post, $meta, $raw_desc );
			$excerpt = self::build_excerpt( $raw_desc, $meta );

			wp_update_post( [
				'ID'           => $post->ID,
				'post_content' => wp_kses_post( $content ),
				'post_excerpt' => sanitize_textarea_field( $excerpt ),
			] );

			// Normalise meta that the aggregator may have left empty.
			if ( $meta['company'] ) {
				update_post_meta( $post->ID, '_cep_job_company', sanitize_text_field( $meta['company'] ) );
			}
			if ( $meta['location'] ) {
				update_post_meta( $post->ID, '_cep_job_location', sanitize_text_field( $meta['location'] ) );
			}
			// Derive a location from the stored "Headquarters:" line if missing.
			if ( empty( $meta['location'] ) ) {
				if ( preg_match( '/Headquarters:\s*([^\n<]+)/i', $post->post_content, $m ) ) {
					update_post_meta( $post->ID, '_cep_job_location', sanitize_text_field( trim( $m[1] ) ) );
				}
			}

			$updated++;
		}

		Logger::log( "Job reformat: updated {$updated} job posts", 'info', 'job_reformat', [ 'updated' => $updated ] );
		return $updated;
	}

	/**
	 * Build a clean, structured HTML body from the raw description + meta.
	 */
	private static function build_content( \WP_Post $post, array $meta, string $raw_desc ): string {
		$parts = [];

		// Intro line: status + disclosure.
		$company = $meta['company'] ?: '';
		if ( $company ) {
			$parts[] = '<p><strong>' . esc_html( $company ) . '</strong>'
				. ( $meta['location'] ? ' · ' . esc_html( $meta['location'] ) : '' )
				. '</p>';
		} elseif ( $meta['location'] ) {
			$parts[] = '<p>' . esc_html( $meta['location'] ) . '</p>';
		}

		// Body: split the raw description into paragraphs on blank lines /
		// sentence-group boundaries so it isn't one wall of text.
		$paras = preg_split( '/\n{2,}|\.\s+(?=[A-Z])/', $raw_desc );
		$paras = array_filter( array_map( 'trim', $paras ) );
		foreach ( $paras as $para ) {
			if ( '' === $para ) {
				continue;
			}
			$parts[] = '<p>' . esc_html( $para ) . '</p>';
		}

		// Apply CTA.
		if ( $meta['url'] ) {
			$parts[] = '<p><a href="' . esc_url( $meta['url'] ) . '" target="_blank" rel="noopener noreferrer">'
				. esc_html__( 'Apply for this position →', 'content-engine-pro' )
				. '</a></p>';
			$parts[] = '<p><em>' . esc_html__( 'Curated, not offered — apply with the employer directly.', 'content-engine-pro' ) . '</em></p>';
		}

		return implode( "\n", $parts );
	}

	/**
	 * Build a 1–2 sentence excerpt from the description.
	 */
	private static function build_excerpt( string $raw_desc, array $meta ): string {
		$sentences = preg_split( '/(?<=[.!?])\s+/', $raw_desc, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $sentences ) ) {
			return '';
		}
		$excerpt = $sentences[0];
		if ( isset( $sentences[1] ) && mb_strlen( $excerpt ) < 120 ) {
			$excerpt .= ' ' . $sentences[1];
		}
		return wp_trim_words( $excerpt, 30, '…' );
	}
}
