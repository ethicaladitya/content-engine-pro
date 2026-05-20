<?php
namespace ContentEnginePro\Deals;

use ContentEnginePro\Settings;
use ContentEnginePro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deal Monitor — handles post-publish deal lifecycle.
 *
 * - Marks deals as expired when expires_at passes
 * - Updates deal posts with "expired" notice
 * - Tracks price changes
 *
 * @since 1.3.0
 */
class DealMonitor {

	public static function run(): void {
		if ( ! Settings::is_enabled( 'deals_enabled' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cep_deals';

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $table_exists ) {
			return;
		}

		self::expire_old_deals( $table );

		Logger::log( 'Deal monitor: run complete', 'info', 'deals' );
	}

	private static function expire_old_deals( string $table ): void {
		global $wpdb;

		$action = Settings::get( 'deals_expiry_action', 'badge' );

		// Find published deals that have passed expiry
		$expired = $wpdb->get_results(
			"SELECT id, wp_post_id, product_name FROM {$table}
			 WHERE status = 'published'
			   AND expires_at IS NOT NULL
			   AND expires_at < NOW()",
			ARRAY_A
		);

		foreach ( $expired as $deal ) {
			$wpdb->update( $table, [ 'status' => 'expired', 'is_active' => 0 ], [ 'id' => $deal['id'] ], [ '%s', '%d' ], [ '%d' ] );

			if ( empty( $deal['wp_post_id'] ) ) {
				continue;
			}

			$post_id = (int) $deal['wp_post_id'];

			if ( 'unpublish' === $action ) {
				wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
			} elseif ( 'badge' === $action ) {
				// Add expired flag meta — theme/shortcode can render a notice
				update_post_meta( $post_id, '_cep_deal_expired', '1' );
			}

			Logger::log( "Deal monitor: expired deal #{$deal['id']} post #{$post_id}", 'info', 'deals' );
		}
	}
}
