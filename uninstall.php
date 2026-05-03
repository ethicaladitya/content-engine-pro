<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove plugin options
delete_option( 'cep_settings' );
delete_option( 'cep_db_version' );
delete_option( 'cep_activated_at' );

// Drop custom tables
$tables = [
	$wpdb->prefix . 'cep_logs',
	$wpdb->prefix . 'cep_sources',
	$wpdb->prefix . 'cep_raw_content',
	$wpdb->prefix . 'cep_reviews',
	$wpdb->prefix . 'cep_product_discovery',
	$wpdb->prefix . 'cep_jobs_raw',
	$wpdb->prefix . 'cep_trending_topics',
	$wpdb->prefix . 'cep_clicks',
];

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

// Remove cron events
$hooks = [
	'cep_crawl_morning','cep_crawl_midday','cep_crawl_evening','cep_crawl_weekly',
	'cep_article_autopilot',
	'cep_reviews_discover','cep_reviews_generate','cep_reviews_update','cep_log_prune',
	'cep_jobs_aggregate','cep_trending_autopilot',
];
foreach ( $hooks as $hook ) {
	wp_clear_scheduled_hook( $hook );
}
