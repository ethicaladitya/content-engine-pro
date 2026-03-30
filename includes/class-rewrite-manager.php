<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dynamically registers rewrite rules and query vars from settings.
 */
class RewriteManager {

	public static function add_rules(): void {
		$go_base = Settings::get( 'affiliate_redirect_base', 'go' );

		// Affiliate redirect: /go/{slug}/
		add_rewrite_rule(
			'^' . preg_quote( $go_base, '/' ) . '/([^/]+)/?$',
			'index.php?cep_affiliate_go=$matches[1]',
			'top'
		);

		// NOTE: Primary taxonomy archive URLs are handled by WordPress's own
		// taxonomy rewrite rules (registered via register_taxonomy 'rewrite' arg).
		// Adding custom rules here for 'post' CPT caused an infinite redirect
		// loop because the custom rule matched the URL, then redirected to
		// get_term_link() which returned the exact same URL.

		// Secondary taxonomy (tags/topics)
		$tag_archive = Settings::get( 'secondary_tax_archive_slug', 'topic' );
		add_rewrite_rule(
			'^' . preg_quote( $tag_archive, '/' ) . '/([^/]+)/page/([0-9]+)/?$',
			'index.php?cep_secondary_tax=$matches[1]&paged=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $tag_archive, '/' ) . '/([^/]+)/?$',
			'index.php?cep_secondary_tax=$matches[1]',
			'top'
		);

		do_action( 'cep_add_rewrite_rules' );
	}

	public static function add_query_vars( array $vars ): array {
		$vars[] = 'cep_affiliate_go';
		$vars[] = 'cep_primary_tax';
		$vars[] = 'cep_secondary_tax';
		return $vars;
	}

	public static function handle_redirects(): void {
		// Affiliate redirect
		$slug = get_query_var( 'cep_affiliate_go' );
		if ( $slug ) {
			self::do_affiliate_redirect( $slug );
			return;
		}

		// Primary taxonomy redirect
		$primary_term = get_query_var( 'cep_primary_tax' );
		if ( $primary_term ) {
			$tax     = Settings::get( 'primary_tax_slug', 'article-category' );
			$term    = get_term_by( 'slug', $primary_term, $tax );
			if ( $term ) {
				$paged = max( 1, (int) get_query_var( 'paged', 1 ) );
				$link  = get_term_link( $term, $tax );
				if ( $paged > 1 ) {
					$link = trailingslashit( $link ) . 'page/' . $paged . '/';
				}
				if ( ! is_wp_error( $link ) ) {
					wp_redirect( $link, 301 );
					exit;
				}
			}
		}

		// Secondary taxonomy redirect
		$secondary_term = get_query_var( 'cep_secondary_tax' );
		if ( $secondary_term ) {
			$tax  = Settings::get( 'secondary_tax_slug', 'article-tag' );
			$term = get_term_by( 'slug', $secondary_term, $tax );
			if ( $term ) {
				$paged = max( 1, (int) get_query_var( 'paged', 1 ) );
				$link  = get_term_link( $term, $tax );
				if ( $paged > 1 ) {
					$link = trailingslashit( $link ) . 'page/' . $paged . '/';
				}
				if ( ! is_wp_error( $link ) ) {
					wp_redirect( $link, 301 );
					exit;
				}
			}
		}
	}

	private static function do_affiliate_redirect( string $slug ): void {
		if ( ! Settings::is_enabled( 'enable_click_tracking' ) ) {
			// Just do a basic lookup
			$provider = self::get_provider_by_slug( $slug );
			if ( $provider ) {
				$url = cep_decrypt_affiliate_url( $provider['url_enc'] );
				if ( $url ) {
					wp_redirect( $url, 302 );
					exit;
				}
			}
			wp_redirect( home_url( '/' ), 302 );
			exit;
		}

		( new Affiliate\ClickTracker() )->handle_redirect( $slug );
	}

	private static function get_provider_by_slug( string $slug ): ?array {
		$providers = get_posts( [
			'post_type'      => Settings::get( 'providers_cpt_slug', 'provider' ),
			'name'           => $slug,
			'posts_per_page' => 1,
			'post_status'    => 'publish',
		] );

		if ( empty( $providers ) ) {
			return null;
		}

		$post    = $providers[0];
		$url_enc = get_post_meta( $post->ID, '_cep_affiliate_url_enc', true );

		return [ 'url_enc' => $url_enc, 'post_id' => $post->ID ];
	}
}
