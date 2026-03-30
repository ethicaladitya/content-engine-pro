<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared utility for assigning featured images to posts.
 *
 * Tries image sources in priority order and falls back to the next
 * candidate if a sideload fails (e.g. source site blocks hotlinking).
 * Final fallback is always a deterministic Picsum image so every post
 * gets something.
 *
 * Priority order is controlled by the `featured_image_source` setting:
 *   source_first (default) — OG image → Pexels → Picsum
 *   pexels_first           — Pexels → OG image → Picsum
 *   pexels_only            — Pexels → Picsum
 *   source_only            — OG image → Picsum
 *   disabled               — no image assigned
 */
class ImageHelper {

	/**
	 * Assign a featured image to $post_id.
	 *
	 * @param int    $post_id      The post to set the thumbnail on.
	 * @param string $source_url   Source article / product URL (used for OG extraction).
	 * @param string $search_query Keyword / title used for Pexels search and Picsum seed.
	 */
	public static function assign_featured_image( int $post_id, string $source_url, string $search_query ): void {
		if ( ! Settings::is_enabled( 'enable_image_fetching' ) ) {
			return;
		}

		$mode = Settings::get( 'featured_image_source', 'source_first' );

		if ( $mode === 'disabled' ) {
			return;
		}

		$candidates = self::build_candidates( $mode, $source_url, $search_query );

		if ( empty( $candidates ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$alt_text = sanitize_text_field( $search_query );

		foreach ( $candidates as $candidate ) {
			$attachment_id = media_sideload_image( $candidate['url'], $post_id, $alt_text, 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				Logger::log(
					"Image sideload failed for #{$post_id} ({$candidate['url']}): " . $attachment_id->get_error_message() . ' — trying next source',
					'warning',
					'image_helper'
				);
				continue;
			}

			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
			set_post_thumbnail( $post_id, $attachment_id );

			if ( ! empty( $candidate['pexels'] ) ) {
				update_post_meta( $attachment_id, '_pexels_photographer',     sanitize_text_field( $candidate['pexels']['photographer'] ?? '' ) );
				update_post_meta( $attachment_id, '_pexels_photographer_url', esc_url_raw( $candidate['pexels']['photographer_url'] ?? '' ) );
				update_post_meta( $attachment_id, '_pexels_photo_url',        esc_url_raw( $candidate['pexels']['photo_url'] ?? '' ) );
			}

			Logger::log( "Featured image set for #{$post_id}: {$candidate['url']}", 'info', 'image_helper' );
			return;
		}

		Logger::log( "All image sources exhausted for #{$post_id} — no featured image set", 'warning', 'image_helper' );
	}

	/**
	 * Build an ordered list of image candidates to try.
	 * Each entry: [ 'url' => string, 'pexels' => array|null ]
	 *
	 * @return array<int, array{url: string, pexels: array|null}>
	 */
	private static function build_candidates( string $mode, string $source_url, string $query ): array {
		$candidates = [];

		switch ( $mode ) {
			case 'pexels_only':
				$pexels = self::fetch_pexels_image( $query );
				if ( ! empty( $pexels['src'] ) ) {
					$candidates[] = [ 'url' => $pexels['src'], 'pexels' => $pexels ];
				}
				break;

			case 'source_only':
				$og = self::extract_og_image( $source_url );
				if ( $og ) {
					$candidates[] = [ 'url' => $og, 'pexels' => null ];
				}
				break;

			case 'pexels_first':
				$pexels = self::fetch_pexels_image( $query );
				if ( ! empty( $pexels['src'] ) ) {
					$candidates[] = [ 'url' => $pexels['src'], 'pexels' => $pexels ];
				}
				$og = self::extract_og_image( $source_url );
				if ( $og ) {
					$candidates[] = [ 'url' => $og, 'pexels' => null ];
				}
				break;

			default: // source_first
				$og = self::extract_og_image( $source_url );
				if ( $og ) {
					$candidates[] = [ 'url' => $og, 'pexels' => null ];
				}
				$pexels = self::fetch_pexels_image( $query );
				if ( ! empty( $pexels['src'] ) ) {
					$candidates[] = [ 'url' => $pexels['src'], 'pexels' => $pexels ];
				}
				break;
		}

		// Always append Picsum as the final no-fail fallback.
		$seed         = (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( substr( $query, 0, 60 ) ) );
		$candidates[] = [ 'url' => "https://picsum.photos/seed/{$seed}/1200/628", 'pexels' => null ];

		return $candidates;
	}

	/**
	 * Extract the og:image URL from a remote page.
	 */
	public static function extract_og_image( string $url ): ?string {
		if ( empty( $url ) ) {
			return null;
		}

		$response = wp_remote_get( $url, [
			'timeout'    => 10,
			'user-agent' => Settings::get( 'crawl_user_agent', 'Content Engine Pro/1.0' ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return null;
		}

		// property before content
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m ) ) {
			return filter_var( $m[1], FILTER_VALIDATE_URL ) ? $m[1] : null;
		}
		// content before property
		if ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\'][^>]*>/i', $html, $m ) ) {
			return filter_var( $m[1], FILTER_VALIDATE_URL ) ? $m[1] : null;
		}

		return null;
	}

	/**
	 * Fetch the best landscape photo from Pexels for $query.
	 * Returns [ src, photographer, photographer_url, photo_url ] or null.
	 *
	 * @return array{src: string, photographer: string, photographer_url: string, photo_url: string}|null
	 */
	public static function fetch_pexels_image( string $query ): ?array {
		$key = Settings::get( 'pexels_key', '' );
		if ( empty( $key ) ) {
			return null;
		}

		$encoded  = urlencode( sanitize_text_field( $query ) );
		$response = wp_remote_get(
			"https://api.pexels.com/v1/search?query={$encoded}&per_page=5&orientation=landscape",
			[
				'timeout' => 10,
				'headers' => [ 'Authorization' => $key ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $status ) {
			Logger::log( "Pexels API returned HTTP {$status} for query: {$encoded}", 'warning', 'image_helper' );
			return null;
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$photo = $body['photos'][0] ?? null;

		if ( ! $photo ) {
			return null;
		}

		return [
			'src'              => $photo['src']['large2x'] ?? $photo['src']['large'] ?? '',
			'photographer'     => $photo['photographer'] ?? '',
			'photographer_url' => $photo['photographer_url'] ?? '',
			'photo_url'        => $photo['url'] ?? '',
		];
	}
}
