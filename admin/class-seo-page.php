<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;
use ContentEnginePro\Seo\SeoAutopilot;
use ContentEnginePro\Seo\SeoPluginDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page: SEO Autopilot
 *
 * Displays the issue table, stats, and manual / AI fix controls.
 */
class SeoPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-engine-pro' ) );
		}

		$notice = self::handle_trigger();
		$stats  = SeoAutopilot::get_stats();
		$plugin = SeoPluginDetector::detect_label();
		$active_filter = isset( $_GET['seo_filter'] ) ? sanitize_key( $_GET['seo_filter'] ) : 'pending';
		?>
		<div class="wrap cep-wrap cep-seo-wrap">

			<div class="cep-page-header">
				<h1 class="cep-page-title">
					<span class="dashicons dashicons-chart-area"></span>
					SEO Autopilot
				</h1>
				<p class="cep-page-subtitle">
					Scans recently published posts for SEO issues, auto-fixes what's safe, and flags the rest for your review.
				</p>
			</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
			<?php endif; ?>

			<!-- ── Top bar ─────────────────────────────────────────────────── -->
			<div class="cep-seo-topbar">
				<div class="cep-seo-topbar__stats">
					<div class="cep-seo-stat">
						<span class="cep-seo-stat__num"><?php echo (int) $stats['posts_scanned']; ?></span>
						<span class="cep-seo-stat__label">Posts Scanned</span>
					</div>
					<div class="cep-seo-stat">
						<span class="cep-seo-stat__num"><?php echo (int) $stats['pending']; ?></span>
						<span class="cep-seo-stat__label">Pending Review</span>
					</div>
					<div class="cep-seo-stat cep-seo-stat--green">
						<span class="cep-seo-stat__num"><?php echo (int) $stats['auto_fixed']; ?></span>
						<span class="cep-seo-stat__label">Auto-Fixed</span>
					</div>
					<div class="cep-seo-stat cep-seo-stat--blue">
						<span class="cep-seo-stat__num"><?php echo (int) $stats['manually_fixed']; ?></span>
						<span class="cep-seo-stat__label">Manually Fixed</span>
					</div>
					<div class="cep-seo-stat cep-seo-stat--grey">
						<span class="cep-seo-stat__num"><?php echo (int) $stats['ignored']; ?></span>
						<span class="cep-seo-stat__label">Ignored</span>
					</div>
				</div>

				<div class="cep-seo-topbar__meta">
					<span class="cep-seo-plugin-badge">
						<span class="dashicons dashicons-admin-plugins"></span>
						SEO Plugin: <strong><?php echo esc_html( $plugin ); ?></strong>
					</span>
					<?php if ( $stats['last_run'] ) : ?>
						<span class="cep-seo-last-run">
							Last scan: <strong><?php echo esc_html( human_time_diff( $stats['last_run'], time() ) . ' ago' ); ?></strong>
						</span>
					<?php endif; ?>
					<form method="post" style="display:inline">
						<?php wp_nonce_field( 'cep_seo_trigger', 'cep_seo_nonce' ); ?>
						<input type="hidden" name="cep_seo_action" value="scan_now">
						<button type="submit" class="button button-primary cep-seo-scan-btn">
							<span class="dashicons dashicons-update"></span> Scan Now
						</button>
					</form>
				</div>
			</div>
			<!-- ───────────────────────────────────────────────────────────── -->

			<!-- ── Filter Tabs ─────────────────────────────────────────────── -->
			<div class="cep-seo-tabs">
				<?php
				$tab_counts = [
					'pending'        => $stats['pending'],
					'auto_fixed'     => $stats['auto_fixed'],
					'manually_fixed' => $stats['manually_fixed'],
					'ignored'        => $stats['ignored'],
					'all'            => $stats['total'],
				];
				$tab_labels = [
					'pending'        => 'Pending',
					'auto_fixed'     => 'Auto-Fixed',
					'manually_fixed' => 'Manually Fixed',
					'ignored'        => 'Ignored',
					'all'            => 'All Issues',
				];
				foreach ( $tab_labels as $key => $label ) :
					$active = $active_filter === $key ? 'cep-seo-tab--active' : '';
					$url    = add_query_arg( [ 'page' => 'cep-seo', 'seo_filter' => $key ], admin_url( 'admin.php' ) );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="cep-seo-tab <?php echo esc_attr( $active ); ?>">
						<?php echo esc_html( $label ); ?>
						<span class="cep-seo-tab__count"><?php echo (int) $tab_counts[ $key ]; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<!-- ───────────────────────────────────────────────────────────── -->

			<!-- ── Issues Table ────────────────────────────────────────────── -->
			<?php
			$rows = self::get_issues( $active_filter );
			if ( empty( $rows ) ) :
			?>
				<div class="cep-seo-empty">
					<span class="dashicons dashicons-yes-alt"></span>
					<p>
						<?php
						if ( 'all' === $active_filter ) {
							echo esc_html__( 'No SEO issues found. Run a scan to check your recent posts.', 'content-engine-pro' );
						} else {
							echo esc_html__( 'No issues in this category.', 'content-engine-pro' );
						}
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped cep-seo-table" id="cep-seo-issues-table">
					<thead>
						<tr>
							<th class="col-post">Post</th>
							<th class="col-issue">Issue</th>
							<th class="col-suggestion">Suggestion</th>
							<th class="col-status">Status</th>
							<th class="col-actions">Actions</th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr id="cep-issue-row-<?php echo (int) $row['id']; ?>" class="cep-issue-row cep-issue-row--<?php echo esc_attr( $row['status'] ); ?>">
							<td class="col-post">
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( get_the_title( (int) $row['post_id'] ) ?: '(Post #' . $row['post_id'] . ')' ); ?>
								</a>
								<span class="cep-issue-age"><?php echo esc_html( human_time_diff( strtotime( $row['created_at'] ), time() ) . ' ago' ); ?></span>
							</td>
							<td class="col-issue">
								<span class="cep-severity-badge cep-severity-badge--<?php echo esc_attr( $row['severity'] ); ?>">
									<?php echo esc_html( ucfirst( $row['severity'] ) ); ?>
								</span>
								<span class="cep-issue-type"><?php echo esc_html( self::issue_label( $row['issue_type'] ) ); ?></span>
								<p class="cep-issue-desc"><?php echo esc_html( $row['description'] ); ?></p>
							</td>
							<td class="col-suggestion">
								<em class="cep-issue-suggestion"><?php echo esc_html( $row['suggestion'] ); ?></em>
							</td>
							<td class="col-status">
								<span class="cep-status-pill cep-status-pill--<?php echo esc_attr( $row['status'] ); ?>">
									<?php echo esc_html( self::status_label( $row['status'] ) ); ?>
								</span>
								<?php if ( $row['fixed_at'] ) : ?>
									<span class="cep-fixed-at"><?php echo esc_html( human_time_diff( strtotime( $row['fixed_at'] ), time() ) . ' ago' ); ?></span>
								<?php endif; ?>
							</td>
							<td class="col-actions">
								<?php if ( 'pending' === $row['status'] ) : ?>
									<?php if ( $row['auto_fixable'] ) : ?>
										<button type="button"
											class="button button-small cep-seo-action-btn cep-fix-btn"
											data-issue-id="<?php echo (int) $row['id']; ?>"
											data-action="fix">
											⚡ Apply Fix
										</button>
									<?php endif; ?>
									<button type="button"
										class="button button-small cep-seo-action-btn cep-ai-fix-btn"
										data-issue-id="<?php echo (int) $row['id']; ?>"
										data-action="ai_fix"
										title="Use AI to generate an optimised fix for this issue">
										🤖 AI Fix
									</button>
									<button type="button"
										class="button button-small cep-seo-action-btn cep-ignore-btn"
										data-issue-id="<?php echo (int) $row['id']; ?>"
										data-action="ignore">
										✕ Ignore
									</button>
								<?php endif; ?>
								<a href="<?php echo esc_url( get_edit_post_link( (int) $row['post_id'] ) ); ?>"
									class="button button-small"
									target="_blank" rel="noopener">
									✏️ Edit Post
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		</div><!-- .cep-seo-wrap -->

		<?php self::render_styles(); ?>
		<?php self::render_scripts(); ?>
		<?php
	}

	// ── Handle Form Trigger ───────────────────────────────────────────────────

	private static function handle_trigger(): string {
		if ( empty( $_POST['cep_seo_action'] ) || empty( $_POST['cep_seo_nonce'] ) ) {
			return '';
		}
		// Capability check BEFORE nonce consumption (WP convention)
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_seo_nonce'] ) ), 'cep_seo_trigger' ) ) {
			return '';
		}

		$action = sanitize_key( $_POST['cep_seo_action'] );

		if ( 'scan_now' === $action ) {
			$summary = SeoAutopilot::run();
			return sprintf(
				'✅ Scan complete — <strong>%d</strong> post(s) scanned, <strong>%d</strong> issue(s) found, <strong>%d</strong> auto-fixed, <strong>%d</strong> pending review.',
				$summary['posts_scanned'],
				$summary['issues_found'],
				$summary['auto_fixed'],
				$summary['pending']
			);
		}

		return '';
	}

	// ── Data Helpers ──────────────────────────────────────────────────────────

	private static function get_issues( string $filter ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_seo_issues';

		if ( 'all' === $filter ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_results(
				"SELECT * FROM {$table} ORDER BY severity DESC, created_at DESC LIMIT 200",
				ARRAY_A
			) ?: [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY severity DESC, created_at DESC LIMIT 200",
				$filter
			),
			ARRAY_A
		) ?: [];
	}

	private static function issue_label( string $type ): string {
		$labels = [
			'meta_title_missing'       => 'Meta Title Missing',
			'meta_title_too_short'     => 'Meta Title Too Short',
			'meta_title_too_long'      => 'Meta Title Too Long',
			'meta_desc_missing'        => 'Meta Description Missing',
			'meta_desc_too_short'      => 'Meta Description Too Short',
			'meta_desc_too_long'       => 'Meta Description Too Long',
			'keyword_not_in_title'     => 'Keyword Not in Title',
			'keyword_not_in_intro'     => 'Keyword Not in Intro',
			'thin_content'             => 'Thin Content',
			'no_internal_links'        => 'No Internal Links',
			'image_alt_missing'        => 'Missing Image Alt Text',
			'heading_no_h2'            => 'No H2 Headings',
			'heading_multiple_h1'      => 'Multiple H1 Tags',
			'slug_too_long'            => 'URL Slug Too Long',
			'slug_missing_keyword'     => 'Keyword Missing from Slug',
			'plugin_score_low'         => 'Low SEO Plugin Score',
			'plugin_surfaced_issue'    => 'SEO Plugin Issue',
		];
		return $labels[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
	}

	private static function status_label( string $status ): string {
		$labels = [
			'pending'        => 'Pending',
			'auto_fixed'     => 'Auto-Fixed',
			'manually_fixed' => 'Fixed',
			'ignored'        => 'Ignored',
		];
		return $labels[ $status ] ?? ucfirst( $status );
	}

	// ── Styles ────────────────────────────────────────────────────────────────

	private static function render_styles(): void {
		?>
		<style>
		/* ── Topbar ─────────────────────────────────────────────────────── */
		.cep-seo-topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;margin-top:20px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px}
		.cep-seo-topbar__stats{display:flex;gap:24px;flex-wrap:wrap}
		.cep-seo-stat{display:flex;flex-direction:column;align-items:center;gap:2px;min-width:72px}
		.cep-seo-stat__num{font-size:26px;font-weight:700;color:#1d2327;line-height:1}
		.cep-seo-stat__label{font-size:11px;color:#666;text-transform:uppercase;letter-spacing:.4px;text-align:center}
		.cep-seo-stat--green .cep-seo-stat__num{color:#1a7a3c}
		.cep-seo-stat--blue .cep-seo-stat__num{color:#0073aa}
		.cep-seo-stat--grey .cep-seo-stat__num{color:#999}
		.cep-seo-topbar__meta{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
		.cep-seo-plugin-badge{display:inline-flex;align-items:center;gap:5px;background:#f0f6fc;border:1px solid #c5d8f0;border-radius:20px;padding:4px 12px;font-size:12px;color:#333}
		.cep-seo-plugin-badge .dashicons{font-size:14px;width:14px;height:14px;color:#0073aa}
		.cep-seo-last-run{font-size:12px;color:#666}
		.cep-seo-scan-btn{display:inline-flex!important;align-items:center;gap:5px}
		.cep-seo-scan-btn .dashicons{font-size:14px;width:14px;height:14px}

		/* ── Filter Tabs ─────────────────────────────────────────────────── */
		.cep-seo-tabs{display:flex;gap:0;margin:20px 0 0;border-bottom:2px solid #ddd}
		.cep-seo-tab{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;text-decoration:none;color:#555;font-size:13px;font-weight:500;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s ease}
		.cep-seo-tab:hover{color:#0073aa;border-bottom-color:#0073aa}
		.cep-seo-tab--active{color:#0073aa;border-bottom-color:#0073aa;font-weight:600}
		.cep-seo-tab__count{display:inline-block;background:#f0f0f0;color:#555;border-radius:10px;padding:1px 7px;font-size:11px;font-weight:600}
		.cep-seo-tab--active .cep-seo-tab__count{background:#0073aa;color:#fff}

		/* ── Issues Table ────────────────────────────────────────────────── */
		.cep-seo-table{margin-top:0;border-top:none!important}
		.cep-seo-table .col-post{width:18%}
		.cep-seo-table .col-issue{width:26%}
		.cep-seo-table .col-suggestion{width:26%}
		.cep-seo-table .col-status{width:10%}
		.cep-seo-table .col-actions{width:20%}
		.cep-issue-age{display:block;font-size:11px;color:#999;margin-top:2px}
		.cep-issue-desc{margin:4px 0 0;font-size:12px;color:#444;line-height:1.4}
		.cep-issue-suggestion{font-size:12px;color:#555;line-height:1.4}
		.cep-fixed-at{display:block;font-size:11px;color:#999;margin-top:3px}

		/* Severity badges */
		.cep-severity-badge{display:inline-block;padding:1px 7px;border-radius:10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-right:4px}
		.cep-severity-badge--error{background:#fde8e8;color:#c0392b}
		.cep-severity-badge--warning{background:#fff3cd;color:#856404}

		/* Status pills */
		.cep-status-pill{display:inline-block;padding:2px 9px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase}
		.cep-status-pill--pending{background:#fff3cd;color:#856404}
		.cep-status-pill--auto_fixed{background:#d1f5e0;color:#1a7a3c}
		.cep-status-pill--manually_fixed{background:#d1ecf1;color:#0c5460}
		.cep-status-pill--ignored{background:#f0f0f0;color:#888}

		/* Action buttons */
		.cep-seo-action-btn{margin-right:4px!important;margin-bottom:4px!important}
		.cep-fix-btn{background:#0073aa!important;color:#fff!important;border-color:#0073aa!important}
		.cep-fix-btn:hover{background:#00517a!important;border-color:#00517a!important}
		.cep-ai-fix-btn{background:#7c3aed!important;color:#fff!important;border-color:#7c3aed!important}
		.cep-ai-fix-btn:hover{background:#5b21b6!important;border-color:#5b21b6!important}
		.cep-ai-fix-btn.cep-loading{opacity:.65;cursor:wait}
		.cep-ignore-btn{color:#c0392b!important}
		.cep-issue-row--auto_fixed td{opacity:.65}
		.cep-issue-row--ignored td{opacity:.5}

		/* Empty state */
		.cep-seo-empty{text-align:center;padding:60px 20px;color:#777}
		.cep-seo-empty .dashicons{font-size:48px;width:48px;height:48px;color:#1a7a3c;display:block;margin:0 auto 12px}
		.cep-seo-empty p{font-size:14px;margin:0}
		</style>
		<?php
	}

	// ── Inline JS ─────────────────────────────────────────────────────────────

	private static function render_scripts(): void {
		?>
		<script>
		(function($){
			$(document).on('click', '.cep-seo-action-btn', function(e){
				e.preventDefault();

				var $btn    = $(this);
				var issueId = $btn.data('issue-id');
				var action  = $btn.data('action');
				var $row    = $('#cep-issue-row-' + issueId);

				if ( $btn.hasClass('cep-loading') ) return;

				$btn.addClass('cep-loading').prop('disabled', true);

				var ajaxAction = '';
				if ( 'fix'    === action ) ajaxAction = 'cep_seo_fix_issue';
				if ( 'ai_fix' === action ) ajaxAction = 'cep_seo_ai_fix_issue';
				if ( 'ignore' === action ) ajaxAction = 'cep_seo_ignore_issue';

				$.ajax({
					url:  cepAdmin.ajaxUrl,
					type: 'POST',
					data: {
						action:   ajaxAction,
						issue_id: issueId,
						nonce:    cepAdmin.nonce,
					},
					success: function(res){
						if ( res.success ) {
							if ( 'ignore' === action ) {
								$row.addClass('cep-issue-row--ignored');
							} else {
								$row.addClass('cep-issue-row--auto_fixed');
							}
							$row.find('.cep-seo-action-btn').remove();
							$row.find('.cep-status-pill')
								.removeClass()
								.addClass('cep-status-pill cep-status-pill--' + ( 'ignore' === action ? 'ignored' : 'manually_fixed' ))
								.text( 'ignore' === action ? 'Ignored' : 'Fixed' );
						} else {
							var msg = res.data && res.data.message ? res.data.message : 'Unable to apply fix. Please try manually.';
							alert( msg );
							$btn.removeClass('cep-loading').prop('disabled', false);
						}
					},
					error: function(){
						alert('Request failed. Please try again.');
						$btn.removeClass('cep-loading').prop('disabled', false);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}
