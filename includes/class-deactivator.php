<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate(): void {
		// Remove scheduled cron events
		$hooks = [
			'cep_crawl_morning',
			'cep_crawl_midday',
			'cep_crawl_evening',
			'cep_crawl_weekly',
			'cep_reviews_discover',
			'cep_reviews_generate',
			'cep_reviews_update',
			'cep_log_prune',
		];
		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
		}
		flush_rewrite_rules();
	}
}
