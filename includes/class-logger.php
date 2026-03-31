<?php
namespace ContentEnginePro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple DB-backed logger.
 * Logs are pruned automatically based on log_retention_days setting.
 */
class Logger {

	public static function log( string $message, string $level = 'info', string $context = 'general', array $data = [] ): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cep_logs';

		$wpdb->insert(
			$table,
			[
				'level'      => sanitize_key( $level ),
				'context'    => sanitize_key( $context ),
				'message'    => sanitize_textarea_field( $message ),
				'data'       => $data ? wp_json_encode( $data ) : null,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public static function get_logs( array $args = [] ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'cep_logs';
		$limit   = (int) ( $args['per_page'] ?? 50 );
		$offset  = (int) ( $args['offset'] ?? 0 );
		$level   = $args['level'] ?? '';
		$context = $args['context'] ?? '';

		$where = '1=1';
		$params = [];

		if ( $level ) {
			$where    .= ' AND level = %s';
			$params[]  = $level;
		}
		if ( $context ) {
			$where    .= ' AND context = %s';
			$params[]  = $context;
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}

	public static function prune(): void {
		global $wpdb;
		$table      = $wpdb->prefix . 'cep_logs';
		$retention  = (int) Settings::get( 'log_retention_days', 90 );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$retention
			)
		);
	}
}
