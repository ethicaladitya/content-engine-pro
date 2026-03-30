<?php
/**
 * One-time backfill: assign featured images to published posts that have none.
 *
 * Run via WP-CLI:
 *   wp eval-file wp-content/plugins/content-engine-pro/tools/backfill-featured-images.php
 *
 * Or via SSH + php:
 *   php -r "
 *     define('ABSPATH', '/home/u688975212/domains/news.baetalk.com/public_html/');
 *     define('WPINC', 'wp-includes');
 *     \$_SERVER['HTTP_HOST'] = 'news.baetalk.com';
 *     \$_SERVER['REQUEST_URI'] = '/';
 *     require_once ABSPATH . 'wp-load.php';
 *     require_once ABSPATH . 'wp-content/plugins/content-engine-pro/tools/backfill-featured-images.php';
 *   "
 *
 * DELETE THIS FILE after running.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Run via wp eval-file or wp-load.php.' );
}

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$pexels_key = \ContentEnginePro\Settings::get( 'pexels_key', '' );

// Find all published posts without a featured image.
$post_ids = get_posts( [
	'post_type'      => 'post',
	'post_status'    => 'publish',
	'numberposts'    => -1,
	'fields'         => 'ids',
	'meta_query'     => [
		[
			'key'     => '_thumbnail_id',
			'compare' => 'NOT EXISTS',
		],
	],
] );

echo sprintf( "Found %d posts without featured images.\n", count( $post_ids ) );

$done  = 0;
$failed = 0;

foreach ( $post_ids as $post_id ) {
	$title         = get_the_title( $post_id );
	$focus_keyword = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
	$query         = $focus_keyword ?: $title;

	$image_url  = null;
	$pexels_data = null;

	// 1. Try Pexels.
	if ( $pexels_key ) {
		$encoded  = urlencode( sanitize_text_field( $query ) );
		$response = wp_remote_get(
			"https://api.pexels.com/v1/search?query={$encoded}&per_page=3&orientation=landscape",
			[ 'timeout' => 10, 'headers' => [ 'Authorization' => $pexels_key ] ]
		);

		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$body  = json_decode( wp_remote_retrieve_body( $response ), true );
			$photo = $body['photos'][0] ?? null;
			if ( $photo ) {
				$image_url   = $photo['src']['large2x'] ?? $photo['src']['large'] ?? null;
				$pexels_data = $photo;
			}
		}
	}

	// 2. Picsum fallback.
	if ( ! $image_url ) {
		$seed      = preg_replace( '/[^a-z0-9]+/', '-', strtolower( substr( $title, 0, 60 ) ) );
		$image_url = "https://picsum.photos/seed/{$seed}/1200/628";
	}

	$alt_text      = sanitize_text_field( $focus_keyword ?: $title );
	$attachment_id = media_sideload_image( $image_url, $post_id, $alt_text, 'id' );

	if ( is_wp_error( $attachment_id ) ) {
		echo sprintf( "  FAILED post #%d (%s): %s\n", $post_id, $title, $attachment_id->get_error_message() );
		$failed++;
		continue;
	}

	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
	set_post_thumbnail( $post_id, $attachment_id );

	if ( $pexels_data ) {
		update_post_meta( $attachment_id, '_pexels_photographer',     sanitize_text_field( $pexels_data['photographer'] ?? '' ) );
		update_post_meta( $attachment_id, '_pexels_photographer_url', esc_url_raw( $pexels_data['photographer_url'] ?? '' ) );
		update_post_meta( $attachment_id, '_pexels_photo_url',        esc_url_raw( $pexels_data['url'] ?? '' ) );
		echo sprintf( "  OK post #%d — Pexels image: %s\n", $post_id, $image_url );
	} else {
		echo sprintf( "  OK post #%d — Picsum fallback: %s\n", $post_id, $image_url );
	}

	$done++;

	// Avoid hammering Pexels rate limits.
	if ( $pexels_key ) {
		sleep( 1 );
	}
}

echo sprintf( "\nDone. %d images set, %d failed.\n", $done, $failed );
echo "Remember to DELETE this file after running.\n";
