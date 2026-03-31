<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Seo\SeoAgent;
use ContentEnginePro\Seo\SeoFixer;
use ContentEnginePro\Seo\SeoAiFixer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO Agent admin page.
 */
class SeoAgentPage {

	private const PER_PAGE = 30;

	private static array $issue_labels = [
		'title_missing'          => 'Title Missing',
		'title_too_short'        => 'Title Too Short',
		'title_too_long'         => 'Title Too Long',
		'meta_desc_missing'      => 'No Meta Description',
		'meta_desc_too_short'    => 'Meta Desc Too Short',
		'meta_desc_too_long'     => 'Meta Desc Too Long',
		'thin_content'           => 'Thin Content',
		'no_h2'                  => 'No H2 Headings',
		'multiple_h1'            => 'Multiple H1 Tags',
		'image_missing_alt'      => 'Images Missing Alt',
		'missing_featured_image' => 'No Featured Image',
		'slug_too_long'          => 'Slug Too Long',
		'no_internal_links'      => 'No Internal Links',
		'no_outbound_links'      => 'No Outbound Links',
	];

	private static array $issue_icons = [
		'title_missing'          => '📝',
		'title_too_short'        => '📝',
		'title_too_long'         => '📝',
		'meta_desc_missing'      => '🔍',
		'meta_desc_too_short'    => '🔍',
		'meta_desc_too_long'     => '🔍',
		'thin_content'           => '📄',
		'no_h2'                  => '🏷️',
		'multiple_h1'            => '🏷️',
		'image_missing_alt'      => '🖼️',
		'missing_featured_image' => '🖼️',
		'slug_too_long'          => '🔗',
		'no_internal_links'      => '🔗',
		'no_outbound_links'      => '🔗',
	];

	private static array $ai_fix_map = [
		'title_missing'       => 'title',
		'title_too_short'     => 'title',
		'title_too_long'      => 'title',
		'meta_desc_missing'   => 'meta_description',
		'meta_desc_too_short' => 'meta_description',
		'meta_desc_too_long'  => 'meta_description',
		'thin_content'        => 'content',
	];

	// ── Page renderer ───────────────────────────────────────────────────────

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-engine-pro' ) );
		}

		$stats    = self::get_overview_stats();
		$last_run = self::get_last_run();

		$filter_severity   = isset( $_GET['severity'] )   ? sanitize_key( $_GET['severity'] )   : '';
		$filter_status     = isset( $_GET['status'] )     ? sanitize_key( $_GET['status'] )     : 'open';
		$filter_issue_type = isset( $_GET['issue_type'] ) ? sanitize_key( $_GET['issue_type'] ) : '';
		$paged             = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$offset            = ( $paged - 1 ) * self::PER_PAGE;

		$issues       = self::get_issues( compact( 'filter_severity', 'filter_status', 'filter_issue_type' ), self::PER_PAGE, $offset );
		$total_issues = self::count_issues( compact( 'filter_severity', 'filter_status', 'filter_issue_type' ) );
		$total_pages  = (int) ceil( $total_issues / self::PER_PAGE );

		self::render_styles();
		?>
		<div class="wrap cep-wrap cep-seo-wrap">

			<!-- Banner header -->
			<div class="cep-seo-banner">
				<div class="cep-seo-banner__left">
					<div class="cep-seo-banner__icon">
						<span class="dashicons dashicons-search"></span>
					</div>
					<div>
						<h1 class="cep-seo-banner__title">SEO Agent</h1>
						<p class="cep-seo-banner__sub">
							Scans all published posts for SEO issues every 3 days &amp; auto-fixes what it can.
							<?php
							$next = wp_next_scheduled( 'cep_seo_analysis' );
							if ( $next ) {
								echo '<span class="cep-seo-next-run">Next scan in ' . esc_html( human_time_diff( time(), $next ) ) . '</span>';
							}
							?>
						</p>
					</div>
				</div>
				<div class="cep-seo-banner__right">
					<button type="button" id="cep-seo-run-btn" class="cep-seo-run-btn">
						<span class="cep-seo-run-btn__icon dashicons dashicons-update"></span>
						<span class="cep-seo-run-btn__text">Run Analysis Now</span>
					</button>
				</div>
			</div>

			<!-- AJAX notice area -->
			<div id="cep-seo-notice-area"></div>

			<!-- Progress bar (hidden by default) -->
			<div id="cep-seo-progress" class="cep-seo-progress" style="display:none;">
				<div class="cep-seo-progress__bar">
					<div class="cep-seo-progress__fill"></div>
				</div>
				<p class="cep-seo-progress__label">Scanning posts for SEO issues&hellip;</p>
			</div>

			<!-- Stats grid -->
			<div class="cep-seo-stats">
				<div class="cep-seo-stat">
					<div class="cep-seo-stat__icon cep-seo-stat__icon--clock">
						<span class="dashicons dashicons-clock"></span>
					</div>
					<div class="cep-seo-stat__body">
						<div class="cep-seo-stat__label">Last Run</div>
						<div class="cep-seo-stat__value" id="cep-seo-last-run">
							<?php
							if ( $last_run && ! empty( $last_run['completed_at'] ) ) {
								echo esc_html( human_time_diff( strtotime( $last_run['completed_at'] ), time() ) . ' ago' );
							} else {
								echo '<span style="color:#94a3b8">Never</span>';
							}
							?>
						</div>
						<?php if ( $last_run ) : ?>
						<div class="cep-seo-stat__sub"><?php echo (int) $last_run['posts_scanned']; ?> posts scanned</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="cep-seo-stat cep-seo-stat--critical">
					<div class="cep-seo-stat__icon cep-seo-stat__icon--critical">
						<span class="dashicons dashicons-warning"></span>
					</div>
					<div class="cep-seo-stat__body">
						<div class="cep-seo-stat__label">Critical</div>
						<div class="cep-seo-stat__value" id="cep-stat-critical"><?php echo (int) $stats['critical_open']; ?></div>
						<div class="cep-seo-stat__sub">open issues</div>
					</div>
				</div>

				<div class="cep-seo-stat cep-seo-stat--warning">
					<div class="cep-seo-stat__icon cep-seo-stat__icon--warning">
						<span class="dashicons dashicons-flag"></span>
					</div>
					<div class="cep-seo-stat__body">
						<div class="cep-seo-stat__label">Warnings</div>
						<div class="cep-seo-stat__value" id="cep-stat-warning"><?php echo (int) $stats['warning_open']; ?></div>
						<div class="cep-seo-stat__sub">open issues</div>
					</div>
				</div>

				<div class="cep-seo-stat cep-seo-stat--fixed">
					<div class="cep-seo-stat__icon cep-seo-stat__icon--fixed">
						<span class="dashicons dashicons-yes-alt"></span>
					</div>
					<div class="cep-seo-stat__body">
						<div class="cep-seo-stat__label">Fixed</div>
						<div class="cep-seo-stat__value" id="cep-stat-fixed"><?php echo (int) $stats['total_fixed']; ?></div>
						<div class="cep-seo-stat__sub">all time</div>
					</div>
				</div>

				<div class="cep-seo-stat cep-seo-stat--info">
					<div class="cep-seo-stat__icon cep-seo-stat__icon--auto">
						<span class="dashicons dashicons-hammer"></span>
					</div>
					<div class="cep-seo-stat__body">
						<div class="cep-seo-stat__label">Auto-fixable</div>
						<div class="cep-seo-stat__value" id="cep-stat-autofixable"><?php echo (int) $stats['auto_fixable_open']; ?></div>
						<div class="cep-seo-stat__sub">ready to fix</div>
					</div>
				</div>
			</div>

			<!-- Filter + bulk actions bar -->
			<div class="cep-seo-toolbar">
				<form method="get" class="cep-seo-filters">
					<input type="hidden" name="page" value="cep-seo-agent" />

					<div class="cep-seo-filter-group">
						<label>Status</label>
						<select name="status" class="cep-seo-select">
							<option value="">All</option>
							<option value="open"    <?php selected( $filter_status, 'open' ); ?>>Open</option>
							<option value="fixed"   <?php selected( $filter_status, 'fixed' ); ?>>Fixed</option>
							<option value="ignored" <?php selected( $filter_status, 'ignored' ); ?>>Ignored</option>
						</select>
					</div>

					<div class="cep-seo-filter-group">
						<label>Severity</label>
						<select name="severity" class="cep-seo-select">
							<option value="">All</option>
							<option value="critical" <?php selected( $filter_severity, 'critical' ); ?>>🔴 Critical</option>
							<option value="warning"  <?php selected( $filter_severity, 'warning' ); ?>>🟡 Warning</option>
							<option value="info"     <?php selected( $filter_severity, 'info' ); ?>>🔵 Info</option>
						</select>
					</div>

					<div class="cep-seo-filter-group">
						<label>Issue Type</label>
						<select name="issue_type" class="cep-seo-select">
							<option value="">All Types</option>
							<?php foreach ( self::$issue_labels as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $filter_issue_type, $slug ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<button type="submit" class="cep-seo-btn cep-seo-btn--ghost">
						<span class="dashicons dashicons-filter" style="font-size:14px;vertical-align:middle;"></span> Apply
					</button>

					<?php if ( $filter_status || $filter_severity || $filter_issue_type ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-seo-agent' ) ); ?>" class="cep-seo-clear-link">Clear filters</a>
					<?php endif; ?>
				</form>

				<div class="cep-seo-toolbar__actions">
					<?php if ( $stats['auto_fixable_open'] > 0 ) : ?>
					<button type="button" class="cep-seo-btn cep-seo-btn--primary cep-seo-bulk-fix-btn">
						<span class="dashicons dashicons-hammer" style="font-size:14px;vertical-align:middle;margin-right:2px;"></span>
						Fix All Auto-fixable
						<span class="cep-seo-badge"><?php echo (int) $stats['auto_fixable_open']; ?></span>
					</button>
					<?php endif; ?>
					<?php if ( $total_issues > 0 ) : ?>
					<span class="cep-seo-total-label"><?php echo (int) $total_issues; ?> issue<?php echo $total_issues !== 1 ? 's' : ''; ?> found</span>
					<?php endif; ?>
				</div>
			</div>

			<!-- Issues table / empty state -->
			<?php if ( empty( $issues ) ) : ?>
				<div class="cep-seo-empty">
					<?php if ( ! $last_run ) : ?>
						<div class="cep-seo-empty__icon">🔍</div>
						<h3>No analysis has been run yet</h3>
						<p>Click <strong>Run Analysis Now</strong> above to scan your posts for SEO issues.</p>
						<button type="button" class="cep-seo-btn cep-seo-btn--primary cep-seo-run-btn" style="margin-top:12px;">
							Run Analysis Now
						</button>
					<?php else : ?>
						<div class="cep-seo-empty__icon">✅</div>
						<h3>All clear!</h3>
						<p>No issues match the current filters.</p>
					<?php endif; ?>
				</div>
			<?php else : ?>

			<div class="cep-seo-table-wrap">
				<table class="cep-seo-table">
					<thead>
						<tr>
							<th class="col-post">Post</th>
							<th class="col-issue">Issue</th>
							<th class="col-severity">Severity</th>
							<th class="col-description">Description</th>
							<th class="col-status">Status</th>
							<th class="col-actions">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $issues as $issue ) :
						$edit_url   = get_edit_post_link( $issue['post_id'] );
						$view_url   = get_permalink( $issue['post_id'] );
						$post_title = get_the_title( $issue['post_id'] ) ?: '(no title)';
						$ai_type    = self::$ai_fix_map[ $issue['issue_type'] ] ?? '';
						$icon       = self::$issue_icons[ $issue['issue_type'] ] ?? '⚠️';
					?>
						<tr id="cep-seo-row-<?php echo (int) $issue['id']; ?>" class="cep-seo-row cep-seo-row--<?php echo esc_attr( $issue['severity'] ); ?>">
							<td class="col-post">
								<div class="cep-seo-post-cell">
									<a href="<?php echo esc_url( (string) $edit_url ); ?>" class="cep-seo-post-title" target="_blank">
										<?php echo esc_html( $post_title ); ?>
									</a>
									<div class="cep-seo-post-meta">
										<span class="cep-seo-post-type"><?php echo esc_html( $issue['post_type'] ); ?></span>
										<?php if ( $view_url ) : ?>
											<a href="<?php echo esc_url( (string) $view_url ); ?>" target="_blank" class="cep-seo-post-view">↗</a>
										<?php endif; ?>
									</div>
								</div>
							</td>
							<td class="col-issue">
								<span class="cep-seo-issue-label">
									<?php echo esc_html( $icon ); ?>
									<?php echo esc_html( self::$issue_labels[ $issue['issue_type'] ] ?? $issue['issue_type'] ); ?>
								</span>
							</td>
							<td class="col-severity">
								<?php echo wp_kses_post( self::severity_badge( $issue['severity'] ) ); ?>
							</td>
							<td class="col-description">
								<span class="cep-seo-desc-text"><?php echo esc_html( $issue['description'] ); ?></span>
								<?php if ( ! empty( $issue['fix_applied'] ) ) : ?>
									<div class="cep-seo-fix-note">
										<span class="dashicons dashicons-yes" style="font-size:12px;vertical-align:middle;color:#10b981;"></span>
										<?php echo esc_html( $issue['fix_applied'] ); ?>
									</div>
								<?php endif; ?>
							</td>
							<td class="col-status">
								<span class="cep-seo-status cep-seo-status--<?php echo esc_attr( $issue['status'] ); ?>">
									<?php echo esc_html( ucfirst( $issue['status'] ) ); ?>
								</span>
							</td>
							<td class="col-actions">
								<div class="cep-seo-action-group">
									<?php if ( 'open' === $issue['status'] && $issue['auto_fixable'] ) : ?>
										<button type="button"
											class="cep-seo-btn cep-seo-btn--fix cep-seo-fix-btn"
											data-issue-id="<?php echo (int) $issue['id']; ?>"
											title="Apply automatic fix">
											<span class="dashicons dashicons-hammer"></span> Fix
										</button>
									<?php endif; ?>
									<?php if ( $ai_type ) : ?>
										<button type="button"
											class="cep-seo-btn cep-seo-btn--ai cep-seo-ai-fix-btn"
											data-post-id="<?php echo (int) $issue['post_id']; ?>"
											data-fix-type="<?php echo esc_attr( $ai_type ); ?>"
											title="Fix using AI">
											✨ AI Fix
										</button>
									<?php endif; ?>
									<?php if ( 'open' === $issue['status'] ) : ?>
										<button type="button"
											class="cep-seo-btn cep-seo-btn--ignore cep-seo-ignore-btn"
											data-issue-id="<?php echo (int) $issue['id']; ?>"
											title="Ignore this issue">
											Ignore
										</button>
									<?php endif; ?>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( $total_pages > 1 ) : ?>
			<div class="cep-seo-pagination">
				<?php
				$base_url = add_query_arg( [
					'page'       => 'cep-seo-agent',
					'status'     => $filter_status,
					'severity'   => $filter_severity,
					'issue_type' => $filter_issue_type,
				], admin_url( 'admin.php' ) );
				echo paginate_links( [
					'base'    => $base_url . '&paged=%#%',
					'format'  => '',
					'current' => $paged,
					'total'   => $total_pages,
					'type'    => 'list',
				] );
				?>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div><!-- .wrap -->
		<?php
	}

	// ── AJAX handlers ───────────────────────────────────────────────────────

	public static function ajax_run_analysis(): void {
		// Buffer any stray output so JSON isn't corrupted
		ob_start();

		if ( ! check_ajax_referer( 'cep_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		// Give the scan more time to complete
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		try {
			SeoAgent::run();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Error during scan: ' . $e->getMessage() ] );
		}

		ob_end_clean();

		global $wpdb;
		$last = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}cep_seo_runs ORDER BY id DESC LIMIT 1",
			ARRAY_A
		);

		$msg = 'Analysis complete.';
		if ( $last ) {
			$msg = sprintf(
				'Scan complete — <strong>%d</strong> posts scanned, <strong>%d</strong> issues found, <strong>%d</strong> auto-fixed.',
				(int) $last['posts_scanned'],
				(int) $last['issues_found'],
				(int) $last['issues_fixed']
			);
		}

		// Return fresh stats for the UI to update without a page reload
		$stats = self::get_overview_stats();

		wp_send_json_success( [
			'message'         => $msg,
			'critical_open'   => $stats['critical_open'],
			'warning_open'    => $stats['warning_open'],
			'total_fixed'     => $stats['total_fixed'],
			'auto_fixable'    => $stats['auto_fixable_open'],
			'last_run_label'  => $last ? human_time_diff( strtotime( $last['completed_at'] ?? $last['started_at'] ), time() ) . ' ago' : 'just now',
			'posts_scanned'   => $last ? (int) $last['posts_scanned'] : 0,
			'reload'          => true,
		] );
	}

	public static function ajax_apply_fix(): void {
		ob_start();
		if ( ! check_ajax_referer( 'cep_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$issue_id = absint( $_POST['issue_id'] ?? 0 );
		if ( ! $issue_id ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Invalid issue ID.' ] );
		}

		global $wpdb;
		$issue = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cep_seo_issues WHERE id = %d", $issue_id ),
			ARRAY_A
		);

		if ( ! $issue ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Issue not found.' ] );
		}

		try {
			$result = SeoFixer::apply_fix( $issue );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Fix error: ' . $e->getMessage() ] );
		}

		if ( false === $result ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Could not apply fix automatically for this issue.' ] );
		}

		$wpdb->update(
			$wpdb->prefix . 'cep_seo_issues',
			[
				'status'      => 'fixed',
				'fix_applied' => $result,
				'fixed_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $issue_id ],
			[ '%s', '%s', '%s' ],
			[ '%d' ]
		);

		ob_end_clean();
		wp_send_json_success( [ 'message' => $result ] );
	}

	public static function ajax_apply_ai_fix(): void {
		ob_start();
		if ( ! check_ajax_referer( 'cep_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$fix_type = sanitize_key( $_POST['fix_type'] ?? '' );

		if ( ! $post_id || ! $fix_type ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Missing parameters.' ] );
		}

		try {
			$result = SeoAiFixer::apply_ai_fix( $post_id, $fix_type );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'AI error: ' . $e->getMessage() ] );
		}

		if ( is_wp_error( $result ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Mark related open issues as fixed
		global $wpdb;
		$type_map = [
			'title'            => [ 'title_missing', 'title_too_short', 'title_too_long' ],
			'meta_description' => [ 'meta_desc_missing', 'meta_desc_too_short', 'meta_desc_too_long' ],
			'content'          => [ 'thin_content' ],
		];

		if ( isset( $type_map[ $fix_type ] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $type_map[ $fix_type ] ), '%s' ) );
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}cep_seo_issues
					 SET status = 'fixed', fix_applied = %s, fixed_at = %s
					 WHERE post_id = %d AND status = 'open' AND issue_type IN ({$placeholders})",
					array_merge( [ $result, current_time( 'mysql' ), $post_id ], $type_map[ $fix_type ] )
				)
			);
		}

		ob_end_clean();
		wp_send_json_success( [ 'message' => $result ] );
	}

	public static function ajax_ignore_issue(): void {
		ob_start();
		if ( ! check_ajax_referer( 'cep_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		$issue_id = absint( $_POST['issue_id'] ?? 0 );
		if ( ! $issue_id ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Invalid issue ID.' ] );
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'cep_seo_issues',
			[ 'status' => 'ignored' ],
			[ 'id' => $issue_id ],
			[ '%s' ],
			[ '%d' ]
		);

		ob_end_clean();
		wp_send_json_success( [ 'message' => 'Issue ignored.' ] );
	}

	public static function ajax_bulk_fix(): void {
		ob_start();
		if ( ! check_ajax_referer( 'cep_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		global $wpdb;
		$issues = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}cep_seo_issues WHERE status = 'open' AND auto_fixable = 1 LIMIT 200",
			ARRAY_A
		);

		$fixed = 0;
		foreach ( $issues as $issue ) {
			try {
				$result = SeoFixer::apply_fix( $issue );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( false !== $result ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
					$wpdb->prefix . 'cep_seo_issues',
					[
						'status'      => 'fixed',
						'fix_applied' => $result,
						'fixed_at'    => current_time( 'mysql' ),
					],
					[ 'id' => (int) $issue['id'] ],
					[ '%s', '%s', '%s' ],
					[ '%d' ]
				);
				$fixed++;
			}
		}

		ob_end_clean();
		wp_send_json_success( [ 'message' => "Bulk fix complete — {$fixed} issue(s) fixed.", 'reload' => true ] );
	}

	// ── Private helpers ─────────────────────────────────────────────────────

	private static function get_overview_stats(): array {
		global $wpdb;
		$t = $wpdb->prefix . 'cep_seo_issues';
		return [
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'critical_open'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='open' AND severity='critical'" ),
			'warning_open'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='open' AND severity='warning'" ),
			'total_fixed'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='fixed'" ),
			'auto_fixable_open' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status='open' AND auto_fixable=1" ),
			// phpcs:enable
		];
	}

	private static function get_last_run(): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}cep_seo_runs ORDER BY id DESC LIMIT 1", ARRAY_A ) ?: null;
	}

	private static function get_issues( array $f, int $per_page, int $offset ): array {
		global $wpdb;
		$where = '1=1'; $params = [];
		if ( ! empty( $f['filter_status'] ) )     { $where .= ' AND status = %s';     $params[] = $f['filter_status']; }
		if ( ! empty( $f['filter_severity'] ) )   { $where .= ' AND severity = %s';   $params[] = $f['filter_severity']; }
		if ( ! empty( $f['filter_issue_type'] ) ) { $where .= ' AND issue_type = %s'; $params[] = $f['filter_issue_type']; }
		$params = array_merge( $params, [ $per_page, $offset ] );
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cep_seo_issues WHERE {$where} ORDER BY FIELD(severity,'critical','warning','info'), detected_at DESC LIMIT %d OFFSET %d",
			$params
		);
		return $wpdb->get_results( $sql, ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private static function count_issues( array $f ): int {
		global $wpdb;
		$where = '1=1'; $params = [];
		if ( ! empty( $f['filter_status'] ) )     { $where .= ' AND status = %s';     $params[] = $f['filter_status']; }
		if ( ! empty( $f['filter_severity'] ) )   { $where .= ' AND severity = %s';   $params[] = $f['filter_severity']; }
		if ( ! empty( $f['filter_issue_type'] ) ) { $where .= ' AND issue_type = %s'; $params[] = $f['filter_issue_type']; }
		$sql = empty( $params )
			? "SELECT COUNT(*) FROM {$wpdb->prefix}cep_seo_issues WHERE {$where}"
			: $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_seo_issues WHERE {$where}", $params );
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private static function severity_badge( string $severity ): string {
		$icons = [ 'critical' => '🔴', 'warning' => '🟡', 'info' => '🔵' ];
		$icon  = $icons[ $severity ] ?? '⚪';
		return '<span class="cep-seo-severity cep-seo-severity--' . esc_attr( $severity ) . '">'
			. $icon . ' ' . esc_html( ucfirst( $severity ) )
			. '</span>';
	}

	// ── Styles ───────────────────────────────────────────────────────────────

	private static function render_styles(): void {
		?>
		<style>
		/* ── Banner ── */
		.cep-seo-banner{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 28px;background:linear-gradient(135deg,#1e293b 0%,#334155 50%,#1e3a5f 100%);border-radius:12px;margin-top:16px;flex-wrap:wrap;box-shadow:0 4px 24px rgba(0,0,0,.18)}
		.cep-seo-banner__left{display:flex;align-items:center;gap:16px}
		.cep-seo-banner__icon{width:48px;height:48px;background:rgba(255,255,255,.12);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
		.cep-seo-banner__icon .dashicons{font-size:24px!important;color:#fff;width:24px;height:24px}
		.cep-seo-banner__title{margin:0;font-size:22px;font-weight:700;color:#fff;line-height:1.2}
		.cep-seo-banner__sub{margin:4px 0 0;font-size:13px;color:rgba(255,255,255,.65);display:flex;align-items:center;gap:10px}
		.cep-seo-next-run{background:rgba(255,255,255,.12);color:rgba(255,255,255,.85);font-size:11px;font-weight:600;padding:2px 10px;border-radius:20px;white-space:nowrap}

		/* ── Run button ── */
		.cep-seo-run-btn{display:flex;align-items:center;gap:8px;background:#fff;color:#1e293b;border:none;border-radius:8px;padding:10px 20px;font-size:14px;font-weight:700;cursor:pointer;transition:all .2s;white-space:nowrap;box-shadow:0 2px 8px rgba(0,0,0,.15)}
		.cep-seo-run-btn:hover:not(:disabled){background:#f1f5f9;box-shadow:0 4px 16px rgba(0,0,0,.2);transform:translateY(-1px)}
		.cep-seo-run-btn:disabled{opacity:.65;cursor:not-allowed;transform:none}
		.cep-seo-run-btn__icon{font-size:16px!important;width:16px!important;height:16px!important}
		.cep-seo-run-btn--loading .cep-seo-run-btn__icon{animation:cep-spin .8s linear infinite}

		/* ── Progress bar ── */
		.cep-seo-progress{margin:16px 0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;display:flex;flex-direction:column;gap:8px}
		.cep-seo-progress__bar{height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden}
		.cep-seo-progress__fill{height:100%;width:0%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:3px;animation:cep-seo-progress 3s ease-in-out forwards}
		.cep-seo-progress__label{margin:0;font-size:13px;color:#64748b;font-weight:500}
		@keyframes cep-seo-progress{0%{width:5%}70%{width:85%}100%{width:95%}}

		/* ── Stats grid ── */
		.cep-seo-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin:20px 0}
		.cep-seo-stat{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px;display:flex;align-items:center;gap:14px;transition:box-shadow .15s}
		.cep-seo-stat:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
		.cep-seo-stat__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
		.cep-seo-stat__icon .dashicons{font-size:20px!important;width:20px!important;height:20px!important}
		.cep-seo-stat__icon--clock{background:#f1f5f9}.cep-seo-stat__icon--clock .dashicons{color:#64748b}
		.cep-seo-stat__icon--critical{background:#fef2f2}.cep-seo-stat__icon--critical .dashicons{color:#ef4444}
		.cep-seo-stat__icon--warning{background:#fffbeb}.cep-seo-stat__icon--warning .dashicons{color:#f59e0b}
		.cep-seo-stat__icon--fixed{background:#f0fdf4}.cep-seo-stat__icon--fixed .dashicons{color:#22c55e}
		.cep-seo-stat__icon--auto{background:#eff6ff}.cep-seo-stat__icon--auto .dashicons{color:#3b82f6}
		.cep-seo-stat__label{font-size:11px;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.6px}
		.cep-seo-stat__value{font-size:26px;font-weight:800;line-height:1.1;color:#0f172a;margin:2px 0}
		.cep-seo-stat__sub{font-size:11px;color:#94a3b8}
		.cep-seo-stat--critical{border-top:3px solid #ef4444}
		.cep-seo-stat--warning{border-top:3px solid #f59e0b}
		.cep-seo-stat--fixed{border-top:3px solid #22c55e}
		.cep-seo-stat--info{border-top:3px solid #3b82f6}

		/* ── Toolbar ── */
		.cep-seo-toolbar{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
		.cep-seo-filters{display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap}
		.cep-seo-filter-group{display:flex;flex-direction:column;gap:4px}
		.cep-seo-filter-group label{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.4px}
		.cep-seo-select{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;color:#374151;background:#fff;min-width:130px;height:36px}
		.cep-seo-select:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.12)}
		.cep-seo-toolbar__actions{display:flex;align-items:center;gap:10px}
		.cep-seo-total-label{font-size:12px;color:#64748b;font-weight:500}
		.cep-seo-clear-link{font-size:12px;color:#6366f1;text-decoration:none;padding:7px 4px}
		.cep-seo-clear-link:hover{text-decoration:underline}

		/* ── Buttons ── */
		.cep-seo-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;transition:all .15s;white-space:nowrap;line-height:1.4;text-decoration:none}
		.cep-seo-btn:disabled{opacity:.5;cursor:not-allowed}
		.cep-seo-btn--ghost{background:#fff;color:#374151;border-color:#d1d5db;height:36px}
		.cep-seo-btn--ghost:hover:not(:disabled){background:#f9fafb;border-color:#9ca3af}
		.cep-seo-btn--primary{background:#6366f1;color:#fff;border-color:#6366f1}
		.cep-seo-btn--primary:hover:not(:disabled){background:#4f46e5}
		.cep-seo-btn--fix{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
		.cep-seo-btn--fix:hover:not(:disabled){background:#dbeafe}
		.cep-seo-btn--ai{background:linear-gradient(135deg,#7c3aed,#6366f1);color:#fff;border-color:transparent}
		.cep-seo-btn--ai:hover:not(:disabled){opacity:.88}
		.cep-seo-btn--ignore{background:#f8fafc;color:#94a3b8;border-color:#e2e8f0}
		.cep-seo-btn--ignore:hover:not(:disabled){background:#f1f5f9;color:#64748b}
		.cep-seo-badge{background:rgba(255,255,255,.25);color:#fff;font-size:10px;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:2px}
		.cep-seo-btn--primary .cep-seo-badge{background:rgba(255,255,255,.3)}

		/* ── Table ── */
		.cep-seo-table-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06)}
		.cep-seo-table{width:100%;border-collapse:collapse;font-size:13px}
		.cep-seo-table thead tr{background:#f8fafc;border-bottom:2px solid #e2e8f0}
		.cep-seo-table th{padding:11px 14px;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;text-align:left;white-space:nowrap}
		.cep-seo-table td{padding:12px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
		.cep-seo-row:last-child td{border-bottom:none}
		.cep-seo-row:hover td{background:#fafbff}
		.cep-seo-row--critical td:first-child{border-left:3px solid #ef4444}
		.cep-seo-row--warning td:first-child{border-left:3px solid #f59e0b}
		.cep-seo-row--info td:first-child{border-left:3px solid #3b82f6}
		.col-post{width:22%}.col-issue{width:16%}.col-severity{width:10%}.col-description{width:28%}.col-status{width:8%}.col-actions{width:16%}

		/* Table cells */
		.cep-seo-post-title{font-weight:600;color:#1e293b;text-decoration:none;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:220px}
		.cep-seo-post-title:hover{color:#6366f1}
		.cep-seo-post-meta{display:flex;align-items:center;gap:6px;margin-top:3px}
		.cep-seo-post-type{background:#f1f5f9;color:#64748b;font-size:10px;font-weight:600;padding:1px 6px;border-radius:4px;text-transform:uppercase}
		.cep-seo-post-view{color:#94a3b8;text-decoration:none;font-size:12px}.cep-seo-post-view:hover{color:#6366f1}
		.cep-seo-issue-label{display:flex;align-items:center;gap:5px;font-weight:500;color:#374151}
		.cep-seo-desc-text{color:#4b5563;font-size:12px;line-height:1.5}
		.cep-seo-fix-note{margin-top:4px;font-size:11px;color:#059669;font-style:italic}
		.cep-seo-action-group{display:flex;gap:5px;flex-wrap:wrap}

		/* Severity badges */
		.cep-seo-severity{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600;white-space:nowrap}

		/* Status pills */
		.cep-seo-status{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:600}
		.cep-seo-status--open{background:#fef3c7;color:#92400e}
		.cep-seo-status--fixed{background:#d1fae5;color:#065f46}
		.cep-seo-status--ignored{background:#f1f5f9;color:#475569}

		/* Empty state */
		.cep-seo-empty{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:60px 40px;text-align:center;margin-top:4px}
		.cep-seo-empty__icon{font-size:48px;display:block;margin-bottom:16px}
		.cep-seo-empty h3{margin:0 0 8px;font-size:18px;font-weight:700;color:#1e293b}
		.cep-seo-empty p{color:#64748b;margin:0;font-size:14px}

		/* Pagination */
		.cep-seo-pagination{margin-top:16px;display:flex;justify-content:center}
		.cep-seo-pagination .pagination-links{display:flex;gap:4px}

		/* Floating notices */
		.cep-seo-toast{position:fixed;bottom:28px;right:28px;max-width:440px;padding:14px 18px;border-radius:10px;font-size:13px;font-weight:500;box-shadow:0 8px 30px rgba(0,0,0,.15);z-index:99999;line-height:1.55;display:flex;align-items:flex-start;gap:10px}
		.cep-seo-toast--success{background:#ecfdf5;color:#065f46;border-left:4px solid #10b981}
		.cep-seo-toast--error{background:#fef2f2;color:#7f1d1d;border-left:4px solid #ef4444}
		.cep-seo-toast__icon{font-size:18px;flex-shrink:0;line-height:1}

		/* Notice area */
		#cep-seo-notice-area .notice{margin:12px 0 0}

		/* Animations */
		@keyframes cep-spin{to{transform:rotate(360deg)}}
		@keyframes cep-fadein{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
		.cep-seo-stat,.cep-seo-table-wrap,.cep-seo-empty{animation:cep-fadein .25s ease}

		/* Responsive */
		@media(max-width:1200px){.cep-seo-stats{grid-template-columns:repeat(3,1fr)}}
		@media(max-width:782px){.cep-seo-stats{grid-template-columns:repeat(2,1fr)}.cep-seo-banner{flex-direction:column;align-items:flex-start}}
		</style>
		<?php
	}
}
