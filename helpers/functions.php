<?php
/**
 * Global helper functions for Content Engine Pro.
 *
 * These are intentionally global for easy use in templates and third-party code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cep_get_setting' ) ) {
	/**
	 * Retrieve a plugin setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Optional default override.
	 * @return mixed
	 */
	function cep_get_setting( string $key, $default = null ) {
		return \ContentEnginePro\Settings::get( $key, $default );
	}
}

if ( ! function_exists( 'cep_is_enabled' ) ) {
	/**
	 * Check whether a feature toggle is enabled.
	 *
	 * @param string $key Feature key (e.g. 'enable_crawling').
	 * @return bool
	 */
	function cep_is_enabled( string $key ): bool {
		return \ContentEnginePro\Settings::is_enabled( $key );
	}
}

if ( ! function_exists( 'cep_get_ai_client' ) ) {
	/**
	 * Get the AI client instance (OpenAI or Azure).
	 *
	 * @return \ContentEnginePro\AiClient
	 */
	function cep_get_ai_client(): \ContentEnginePro\AiClient {
		return \ContentEnginePro\AiClient::get_instance();
	}
}

if ( ! function_exists( 'cep_log' ) ) {
	/**
	 * Log a message to the Content Engine Pro logger.
	 *
	 * @param string $message  Log message.
	 * @param string $level    Log level: info, warning, error, debug.
	 * @param string $context  Module context for filtering logs.
	 * @param array  $data     Extra data to log.
	 */
	function cep_log( string $message, string $level = 'info', string $context = 'general', array $data = [] ): void {
		\ContentEnginePro\Logger::log( $message, $level, $context, $data );
	}
}

if ( ! function_exists( 'cep_get_primary_cpt_slug' ) ) {
	/**
	 * Get the registered post type slug for primary articles.
	 */
	function cep_get_primary_cpt_slug(): string {
		return (string) cep_get_setting( 'primary_cpt_slug', 'article' );
	}
}

if ( ! function_exists( 'cep_get_reviews_cpt_slug' ) ) {
	/**
	 * Get the registered post type slug for reviews.
	 */
	function cep_get_reviews_cpt_slug(): string {
		return (string) cep_get_setting( 'reviews_cpt_slug', 'review' );
	}
}

if ( ! function_exists( 'cep_is_primary_cpt' ) ) {
	/**
	 * Check if the current post or given post is the primary CPT.
	 *
	 * @param int|null $post_id
	 */
	function cep_is_primary_cpt( ?int $post_id = null ): bool {
		$post_id = $post_id ?? get_the_ID();
		return get_post_type( $post_id ) === cep_get_primary_cpt_slug();
	}
}

if ( ! function_exists( 'cep_get_affiliate_redirect_url' ) ) {
	/**
	 * Build the affiliate redirect URL for a given provider slug.
	 *
	 * @param string $provider_slug
	 * @return string
	 */
	function cep_get_affiliate_redirect_url( string $provider_slug ): string {
		$base = cep_get_setting( 'affiliate_redirect_base', 'go' );
		return home_url( trailingslashit( $base ) . sanitize_title( $provider_slug ) . '/' );
	}
}

if ( ! function_exists( 'cep_get_brand_name' ) ) {
	/**
	 * Get the configured brand name.
	 */
	function cep_get_brand_name(): string {
		return (string) cep_get_setting( 'brand_name', get_bloginfo( 'name' ) );
	}
}

if ( ! function_exists( 'cep_read_time' ) ) {
	/**
	 * Estimate reading time for content.
	 *
	 * @param string $content
	 * @return string e.g. "4 min read"
	 */
	function cep_read_time( string $content ): string {
		$words   = str_word_count( wp_strip_all_tags( $content ) );
		$minutes = max( 1, (int) ceil( $words / 200 ) );
		return $minutes . ' min read';
	}
}

if ( ! function_exists( 'cep_decrypt_affiliate_url' ) ) {
	/**
	 * Decrypt an AES-256-CBC encrypted affiliate URL.
	 *
	 * @param string $encrypted Base64-encoded ciphertext.
	 * @return string|false
	 */
	function cep_decrypt_affiliate_url( string $encrypted ) {
		$key = cep_get_setting( 'affiliate_encrypt_key' );
		if ( empty( $key ) ) {
			// Fallback to constant if defined
			$key = defined( 'CEP_AFFILIATE_ENCRYPT_KEY' ) ? CEP_AFFILIATE_ENCRYPT_KEY : '';
		}
		if ( empty( $key ) ) {
			return false;
		}
		$data   = base64_decode( $encrypted, true );
		if ( false === $data || strlen( $data ) < 17 ) {
			return false;
		}
		$iv         = substr( $data, 0, 16 );
		$ciphertext = substr( $data, 16 );
		$decrypted  = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return $decrypted;
	}
}

if ( ! function_exists( 'cep_encrypt_affiliate_url' ) ) {
	/**
	 * Encrypt an affiliate URL with AES-256-CBC.
	 *
	 * @param string $url Plain URL.
	 * @return string|false Base64 ciphertext or false on failure.
	 */
	function cep_encrypt_affiliate_url( string $url ) {
		$key = cep_get_setting( 'affiliate_encrypt_key' );
		if ( empty( $key ) ) {
			$key = defined( 'CEP_AFFILIATE_ENCRYPT_KEY' ) ? CEP_AFFILIATE_ENCRYPT_KEY : '';
		}
		if ( empty( $key ) ) {
			return false;
		}
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $url, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return false;
		}
		return base64_encode( $iv . $ciphertext );
	}
}
