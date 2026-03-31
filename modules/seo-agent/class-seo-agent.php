<?php
namespace ContentEnginePro\Seo;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Agent — main orchestrator.
 *
 * Runs the full scan on a cron schedule (every 3 days), processes posts in
 * batches of 50, and optionally auto-fixes what it can without AI.
 */
class SeoAgent {

	/** Posts to process per WP_Query batch. */
	private const BATCH_SIZE = 50;

	/**
	 * Cron callback: run a full scan of all published posts.
	 */
	public static function run(): void {
		if ( ! Settings::is_enabled( 'enable_seo_agent' ) ) {
			return;
		}

		cep_log( 'SEO Agent: starting full scan.', 'info', 'seo_agent' );

		$started_at = current_time( 'mysql' );
		$run_id     = self::start_run( $started_at );

		$stats = self::scan_all_posts();

		self::complete_run( $run_id, $stats );

		cep_log(
			"SEO Agent: scan complete — {$stats['scanned']} posts, {$stats['found']} issues, {$stats['fixed']} auto-fixed.",
			'info',
			'seo_agent'
		);
	}

	/**
	 * Analyse (and optionally auto-fix) a single post.
	 * Used by the cron batch and by the admin AJAX single-post trigger.
	 *
	 * @param int  $post_id   Post to analyse.
	 * @param bool $auto_fix  Whether to apply rule-based auto-fixes immediately.
	 * @return array  Issue rows that were inserted.
	 */
	public static function run_analysis_for_post( int $post_id, bool $auto_fix = true ): array {
		global $wpdb;

		// Remove stale open issues for this post so we get a fresh picture
		self::clear_open_issues_for_post( $post_id );

		$issues = SeoAnalyzer::analyze( $post_id );

		if ( empty( $issues ) ) {
			return [];
		}

		$inserted = [];
		$now      = current_time( 'mysql' );

		foreach ( $issues as $issue ) {
			$row = array_merge( $issue, [ 'detected_at' => $now ] );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$wpdb->prefix . 'cep_seo_issues',
				[
					'post_id'      => (int) $row['post_id'],
					'post_type'    => sanitize_key( $row['post_type'] ),
					'issue_type'   => sanitize_key( $row['issue_type'] ),
					'severity'     => $row['severity'],
					'description'  => $row['description'],
					'auto_fixable' => (int) $row['auto_fixable'],
					'status'       => 'open',
					'detected_at'  => $row['detected_at'],
				],
				[ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);

			$row['id'] = $wpdb->insert_id;
			$inserted[] = $row;
		}

		// Auto-fix eligible issues immediately
		if ( $auto_fix && Settings::is_enabled( 'seo_auto_fix_enabled' ) ) {
			foreach ( $inserted as &$row ) {
				if ( ! $row['auto_fixable'] || 'open' !== $row['status'] ) {
					continue;
				}

				$result = SeoFixer::apply_fix( $row );
				if ( false !== $result ) {
					$wpdb->update(
						$wpdb->prefix . 'cep_seo_issues',
						[
							'status'      => 'fixed',
							'fix_applied' => $result,
							'fixed_at'    => current_time( 'mysql' ),
						],
						[ 'id' => (int) $row['id'] ],
						[ '%s', '%s', '%s' ],
						[ '%d' ]
					);
					$row['status'] = 'fixed';
				}
			}
			unset( $row );
		}

		return $inserted;
	}

	// ── Private methods ─────────────────────────────────────────────────────

	/**
	 * Batch-scan all published posts across all configured post types.
	 *
	 * @return array{scanned:int, found:int, fixed:int}
	 */
	private static function scan_all_posts(): array {
		$stats = [ 'scanned' => 0, 'found' => 0, 'fixed' => 0 ];

		foreach ( self::get_post_types() as $post_type ) {
			$offset = 0;

			do {
				$posts = get_posts( [
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => self::BATCH_SIZE,
					'offset'         => $offset,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				] );

				foreach ( $posts as $post_id ) {
					$issues = self::run_analysis_for_post( (int) $post_id, true );
					$stats['scanned']++;
					$stats['found'] += count( $issues );
					$stats['fixed'] += count( array_filter( $issues, fn( $i ) => 'fixed' === $i['status'] ) );
				}

				$offset += self::BATCH_SIZE;
			} while ( count( $posts ) === self::BATCH_SIZE );
		}

		return $stats;
	}

	/**
	 * Determine which post types to scan based on plugin settings.
	 *
	 * @return string[]
	 */
	private static function get_post_types(): array {
		$types = [ Settings::get( 'primary_cpt_slug', 'post' ) ];

		if ( Settings::is_enabled( 'reviews_cpt_enabled' ) ) {
			$types[] = Settings::get( 'reviews_cpt_slug', 'review' );
		}
		if ( Settings::is_enabled( 'jobs_cpt_enabled' ) ) {
			$types[] = Settings::get( 'jobs_cpt_slug', 'job' );
		}

		return array_unique( $types );
	}

	/**
	 * Delete all open (un-resolved) issues for a post before a fresh scan.
	 * Fixed and ignored issues are preserved for history.
	 *
	 * @param int $post_id
	 */
	private static function clear_open_issues_for_post( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'cep_seo_issues',
			[ 'post_id' => $post_id, 'status' => 'open' ],
			[ '%d', '%s' ]
		);
	}

	/**
	 * Insert a run record and return its ID.
	 *
	 * @param string $started_at MySQL datetime.
	 * @return int
	 */
	private static function start_run( string $started_at ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'cep_seo_runs',
			[ 'started_at' => $started_at ],
			[ '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update the run record with final stats.
	 *
	 * @param int   $run_id
	 * @param array $stats
	 */
	private static function complete_run( int $run_id, array $stats ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->prefix . 'cep_seo_runs',
			[
				'completed_at'  => current_time( 'mysql' ),
				'posts_scanned' => $stats['scanned'],
				'issues_found'  => $stats['found'],
				'issues_fixed'  => $stats['fixed'],
			],
			[ 'id' => $run_id ],
			[ '%s', '%d', '%d', '%d' ],
			[ '%d' ]
		);
	}
}
