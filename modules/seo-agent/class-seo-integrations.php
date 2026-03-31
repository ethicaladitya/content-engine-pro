<?php
namespace ContentEnginePro\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge for third-party SEO plugins (Yoast, Rank Math, SmartCrawl).
 *
 * Centralises all detection and meta-key logic so SeoAnalyzer and SeoFixer
 * never need to repeat plugin detection.
 */
class SeoIntegrations {

	/** Cached detection result. */
	private static string $detected = '';

	/**
	 * Detect the active SEO plugin.
	 *
	 * @return string 'yoast' | 'rankmath' | 'smartcrawl' | 'none'
	 */
	public static function detect(): string {
		if ( '' !== self::$detected ) {
			return self::$detected;
		}

		if ( self::is_yoast_active() ) {
			self::$detected = 'yoast';
		} elseif ( self::is_rankmath_active() ) {
			self::$detected = 'rankmath';
		} elseif ( self::is_smartcrawl_active() ) {
			self::$detected = 'smartcrawl';
		} else {
			self::$detected = 'none';
		}

		return self::$detected;
	}

	/**
	 * Return the meta key map for the given (or auto-detected) plugin.
	 *
	 * @param string $plugin Optional override ('yoast'|'rankmath'|'smartcrawl').
	 * @return array{title:string, desc:string, focus_kw:string}
	 */
	public static function get_meta_keys( string $plugin = '' ): array {
		$plugin = $plugin ?: self::detect();

		$map = [
			'yoast'      => [
				'title'    => '_yoast_wpseo_title',
				'desc'     => '_yoast_wpseo_metadesc',
				'focus_kw' => '_yoast_wpseo_focuskw',
			],
			'rankmath'   => [
				'title'    => 'rank_math_title',
				'desc'     => 'rank_math_description',
				'focus_kw' => 'rank_math_focus_keyword',
			],
			'smartcrawl' => [
				'title'    => '_wds_title',
				'desc'     => '_wds_metadesc',
				'focus_kw' => '_wds_focus-keywords',
			],
		];

		return $map[ $plugin ] ?? [];
	}

	/**
	 * Get the effective SEO title for a post.
	 * Reads from active plugin meta first, falls back to $fallback.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $fallback Fallback value (usually post_title).
	 * @return string
	 */
	public static function get_title( int $post_id, string $fallback = '' ): string {
		$keys = self::get_meta_keys();
		if ( ! empty( $keys['title'] ) ) {
			$value = (string) get_post_meta( $post_id, $keys['title'], true );
			if ( '' !== $value ) {
				return $value;
			}
		}
		// Always also check our own fallback key
		$cep = (string) get_post_meta( $post_id, '_cep_seo_title', true );
		return '' !== $cep ? $cep : $fallback;
	}

	/**
	 * Get the effective meta description for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_description( int $post_id ): string {
		$keys = self::get_meta_keys();
		if ( ! empty( $keys['desc'] ) ) {
			$value = (string) get_post_meta( $post_id, $keys['desc'], true );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return (string) get_post_meta( $post_id, '_cep_seo_desc', true );
	}

	/**
	 * Get the focus keyword for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_focus_keyword( int $post_id ): string {
		$keys = self::get_meta_keys();
		if ( ! empty( $keys['focus_kw'] ) ) {
			$value = (string) get_post_meta( $post_id, $keys['focus_kw'], true );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Write an SEO title to the active plugin's meta key + our own fallback.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   New title value.
	 */
	public static function write_title( int $post_id, string $value ): void {
		$keys = self::get_meta_keys();
		if ( ! empty( $keys['title'] ) ) {
			update_post_meta( $post_id, $keys['title'], $value );
		}
		update_post_meta( $post_id, '_cep_seo_title', $value );
	}

	/**
	 * Write a meta description to the active plugin's meta key + our own fallback.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $value   New description value.
	 */
	public static function write_description( int $post_id, string $value ): void {
		$keys = self::get_meta_keys();
		if ( ! empty( $keys['desc'] ) ) {
			update_post_meta( $post_id, $keys['desc'], $value );
		}
		update_post_meta( $post_id, '_cep_seo_desc', $value );
	}

	// ── Detection helpers ───────────────────────────────────────────────────

	private static function is_yoast_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
	}

	private static function is_rankmath_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	private static function is_smartcrawl_active(): bool {
		return defined( 'SMARTCRAWL_VERSION' ) || class_exists( 'SmartCrawl\SmartCrawl' );
	}
}
