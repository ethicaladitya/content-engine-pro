<?php
namespace ContentEnginePro\Affiliate;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks affiliate link clicks and handles the redirect.
 */
class ClickTracker {

	public function register(): void {
		// Registration happens via RewriteManager → handle_redirects()
		// The cep_affiliate_go query var triggers handle_redirect()
	}

	public function handle_redirect( string $slug ): void {
		global $wpdb;

		$provider = AffiliateManager::get_provider( sanitize_title( $slug ) );

		if ( ! $provider ) {
			wp_redirect( home_url( '/' ), 302 );
			exit;
		}

		// Record click
		if ( Settings::is_enabled( 'enable_click_tracking' ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'cep_clicks',
				[
					'provider_slug' => $provider['slug'],
					'post_id'       => (int) get_the_ID(),
					'click_type'    => 'affiliate',
					'user_agent'    => substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ),
					'referer'       => esc_url_raw( substr( $_SERVER['HTTP_REFERER'] ?? '', 0, 500 ) ),
					'clicked_at'    => current_time( 'mysql', true ),
				],
				[ '%s','%d','%s','%s','%s','%s' ]
			);
		}

		// Decrypt and redirect
		$url = cep_decrypt_affiliate_url( $provider['url_enc'] );

		if ( ! $url ) {
			// Fall back to homepage URL
			$url = $provider['homepage_url'] ?: home_url( '/' );
		}

		do_action( 'cep_affiliate_redirect', $provider['slug'], $url );

		wp_redirect( esc_url_raw( $url ), 302 );
		exit;
	}
}
