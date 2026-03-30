<?php
namespace ContentEnginePro\Seo;

use ContentEnginePro\Logger;
use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Autopilot Orchestrator.
 *
 * Scans posts published in the last N days, audits them for SEO issues,
 * applies deterministic auto-fixes, and persists unresolved issues to the
 * cep_seo_issues table for manual (or AI-assisted) resolution.
 */
class SeoAutopilot {

	/**
	 * Run the full SEO scan + auto-fix cycle.
	 *
	 * Called by:
	 *  - WP Cron (daily, if seo_autopilot_enabled)
	 *  - Manual "Scan Now" button in admin
	 *
	 * @return array Run summary.
	 */
	public static function run(): array {
		$summary = [
			'posts_scanned' => 0,
			'issues_found'  => 0,
			'auto_fixed'    => 0,
			'pending'       => 0,
			'errors'        => 0,
		];

		Logger::log( 'SEO Autopilot: starting scan', 'info', 'seo' );

		$posts = self::get_recent_posts();

		if ( empty( $posts ) ) {
			Logger::log( 'SEO Autopilot: no posts found in scan window', 'info', 'seo' );
			update_option( 'cep_seo_last_run', [ 'time' => time(), 'summary' => $summary ] );
			return $summary;
		}

		$score_threshold = (int) Settings::get( 'seo_score_threshold', 70 );
		$autofix_enabled = Settings::is_enabled( 'seo_autofix_enabled' );

		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;
			$summary['posts_scanned']++;

			try {
				// 1. Clear old pending issues for this post
				self::clear_pending_issues( $post_id );

				// 2. Get SEO plugin data
				$plugin_data = SeoPluginDetector::get_post_data( $post_id );

				// 3. Audit
				$issues = SeoAuditor::audit( $post_id, $plugin_data, $score_threshold );

				if ( empty( $issues ) ) {
					continue;
				}

				$summary['issues_found'] += count( $issues );

				// 4. Apply auto-fixes + persist remaining issues
				foreach ( $issues as $issue ) {
					$fixed = false;

					if ( $autofix_enabled && $issue->auto_fixable ) {
						$fixed = SeoFixer::auto_fix( $post_id, $issue, $plugin_data );
					}

					if ( $fixed ) {
						$summary['auto_fixed']++;
						self::persist_issue( $post_id, $issue, 'auto_fixed' );
					} else {
						$summary['pending']++;
						self::persist_issue( $post_id, $issue, 'pending' );
					}
				}
			} catch ( \Throwable $e ) {
				$summary['errors']++;
				Logger::log(
					'SEO Autopilot: error scanning post ' . $post_id . ' — ' . $e->getMessage(),
					'error',
					'seo'
				);
			}
		}

		update_option( 'cep_seo_last_run', [ 'time' => time(), 'summary' => $summary ] );

		Logger::log(
			sprintf(
				'SEO Autopilot: scan complete — %d posts, %d issues, %d auto-fixed, %d pending',
				$summary['posts_scanned'],
				$summary['issues_found'],
				$summary['auto_fixed'],
				$summary['pending']
			),
			'info',
			'seo'
		);

		return $summary;
	}

	/**
	 * Apply an AI fix for a single stored issue.
	 * Called via AJAX when user clicks "AI Fix".
	 *
	 * @param int $issue_id  Row ID in cep_seo_issues.
	 * @return bool
	 */
	public static function apply_ai_fix( int $issue_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cep_seo_issues WHERE id = %d",
			$issue_id
		), ARRAY_A );

		if ( ! $row ) {
			return false;
		}

		$post_id     = (int) $row['post_id'];
		$plugin_data = SeoPluginDetector::get_post_data( $post_id );

		// Reconstruct the SeoIssue from stored data
		$meta  = ! empty( $row['suggestion_meta'] ) ? json_decode( $row['suggestion_meta'], true ) : [];
		$issue = new SeoIssue(
			$row['issue_type'],
			$row['severity'],
			$row['description'],
			$row['suggestion'],
			true,
			(array) $meta
		);

		$fixed = SeoFixer::ai_fix( $post_id, $issue, $plugin_data );

		if ( $fixed ) {
			$wpdb->update(
				$wpdb->prefix . 'cep_seo_issues',
				[
					'status'   => 'manually_fixed',
					'fixed_at' => current_time( 'mysql' ),
				],
				[ 'id' => $issue_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		return $fixed;
	}

	/**
	 * Apply a deterministic fix for a single stored issue.
	 * Called via AJAX when user clicks "Apply Fix".
	 *
	 * @param int $issue_id
	 * @return bool
	 */
	public static function apply_manual_fix( int $issue_id ): bool {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cep_seo_issues WHERE id = %d",
			$issue_id
		), ARRAY_A );

		if ( ! $row ) {
			return false;
		}

		$post_id     = (int) $row['post_id'];
		$plugin_data = SeoPluginDetector::get_post_data( $post_id );
		$meta        = ! empty( $row['suggestion_meta'] ) ? json_decode( $row['suggestion_meta'], true ) : [];

		$issue = new SeoIssue(
			$row['issue_type'],
			$row['severity'],
			$row['description'],
			$row['suggestion'],
			(bool) $row['auto_fixable'],
			(array) $meta
		);

		$fixed = SeoFixer::auto_fix( $post_id, $issue, $plugin_data );

		if ( $fixed ) {
			$wpdb->update(
				$wpdb->prefix . 'cep_seo_issues',
				[
					'status'   => 'manually_fixed',
					'fixed_at' => current_time( 'mysql' ),
				],
				[ 'id' => $issue_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);
		}

		return $fixed;
	}

	/**
	 * Mark an issue as ignored.
	 *
	 * @param int $issue_id
	 * @return bool
	 */
	public static function ignore_issue( int $issue_id ): bool {
		global $wpdb;
		$result = $wpdb->update(
			$wpdb->prefix . 'cep_seo_issues',
			[
				'status'   => 'ignored',
				'fixed_at' => current_time( 'mysql' ),
			],
			[ 'id' => $issue_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
		return false !== $result;
	}

	/**
	 * Aggregate stats for display on the admin pages.
	 *
	 * @return array
	 */
	public static function get_stats(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_seo_issues';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}`" ) // no user input; table name is trusted
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", 'pending' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$auto_fixed = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", 'auto_fixed' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$manual = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", 'manually_fixed' )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ignored = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE status = %s", 'ignored' )
		);

		$last_run   = get_option( 'cep_seo_last_run', [] );
		$last_scanned = isset( $last_run['summary']['posts_scanned'] )
			? (int) $last_run['summary']['posts_scanned']
			: 0;

		return [
			'total'           => $total,
			'pending'         => $pending,
			'auto_fixed'      => $auto_fixed,
			'manually_fixed'  => $manual,
			'ignored'         => $ignored,
			'posts_scanned'   => $last_scanned,
			'last_run'        => isset( $last_run['time'] ) ? $last_run['time'] : null,
		];
	}

	// ── Private Helpers ───────────────────────────────────────────────────────

	/**
	 * Get posts published in the last N days for configured post types.
	 *
	 * @return \WP_Post[]
	 */
	private static function get_recent_posts(): array {
		$days       = (int) Settings::get( 'seo_scan_days', 3 );
		$post_types = array_map(
			'trim',
			explode( ',', Settings::get( 'seo_post_types', 'post' ) )
		);

		$args = [
			'post_type'      => array_filter( $post_types ),
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'date_query'     => [
				[
					'after'     => gmdate( 'Y-m-d', strtotime( "-{$days} days" ) ),
					'inclusive' => true,
				],
			],
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		];

		return get_posts( $args );
	}

	/**
	 * Delete any existing 'pending' issues for a post before re-scanning.
	 * Preserves auto_fixed, manually_fixed, and ignored records.
	 */
	private static function clear_pending_issues( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$wpdb->prefix . 'cep_seo_issues',
			[
				'post_id' => $post_id,
				'status'  => 'pending',
			],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Persist a single issue to the cep_seo_issues table.
	 */
	private static function persist_issue( int $post_id, SeoIssue $issue, string $status ): void {
		global $wpdb;

		$fixed_at = in_array( $status, [ 'auto_fixed', 'manually_fixed' ], true )
			? current_time( 'mysql' )
			: null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$wpdb->prefix . 'cep_seo_issues',
			[
				'post_id'         => $post_id,
				'issue_type'      => $issue->type,
				'severity'        => $issue->severity,
				'description'     => $issue->description,
				'suggestion'      => $issue->suggestion,
				'auto_fixable'    => $issue->auto_fixable ? 1 : 0,
				'suggestion_meta' => ! empty( $issue->meta ) ? wp_json_encode( $issue->meta ) : null,
				'status'          => $status,
				'fixed_at'        => $fixed_at,
				'created_at'      => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
	}
}
