<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;
use ContentEnginePro\Articles\ArticleAutopilot;
use ContentEnginePro\Reviews\ReviewDiscoverer;
use ContentEnginePro\Reviews\ReviewAutopilot;
use ContentEnginePro\Jobs\JobAggregator;
use ContentEnginePro\Crawl\Crawler;
use ContentEnginePro\Trending\TrendingAutopilot;
use ContentEnginePro\Seo\SeoAutopilot;
use ContentEnginePro\Seo\SeoPluginDetector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autopilot admin page — shows pipeline status and allows manual triggering.
 */
class AutopilotPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'content-engine-pro' ) );
		}

		// Handle manual trigger actions
		$notice = self::handle_trigger();

		global $wpdb;

		// Gather stats
		$stats = self::gather_stats();
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title">
					<span class="dashicons dashicons-controls-repeat"></span>
					Autopilot Control Center
				</h1>
				<p class="cep-page-subtitle">Monitor and manually trigger your content autopilot pipelines.</p>
			</div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
			<?php endif; ?>

			<!-- Run Full Pipeline -->
			<div class="cep-pipeline-banner">
				<div class="cep-pipeline-banner__text">
					<strong>🚀 Run Full Pipeline</strong>
					<span>Crawl all sources → Publish articles → Aggregate jobs in one click.</span>
				</div>
				<form method="post" style="display:inline">
					<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
					<input type="hidden" name="cep_trigger" value="full_pipeline">
					<button type="submit" class="button button-primary button-hero cep-pipeline-run-btn">
						▶ Run Full Pipeline
					</button>
				</form>
			</div>

			<div class="cep-autopilot-grid">

				<!-- Article Autopilot -->
				<div class="cep-autopilot-card <?php echo Settings::is_enabled( 'article_autopilot_enabled' ) ? 'cep-active' : 'cep-inactive'; ?>">
					<div class="cep-autopilot-card__header">
						<span class="dashicons dashicons-edit-large cep-pipeline-icon"></span>
						<div>
							<h2>Article Autopilot</h2>
							<span class="cep-status-badge <?php echo Settings::is_enabled( 'article_autopilot_enabled' ) ? 'cep-status-badge--active' : 'cep-status-badge--inactive'; ?>">
								<?php echo Settings::is_enabled( 'article_autopilot_enabled' ) ? 'Enabled' : 'Disabled'; ?>
							</span>
						</div>
					</div>
					<div class="cep-autopilot-card__body">
						<div class="cep-stat-row">
							<span>Pending in queue</span>
							<strong><?php echo (int) $stats['articles_pending']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Published (all time)</span>
							<strong><?php echo (int) $stats['articles_published']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Max per run</span>
							<strong><?php echo (int) Settings::get( 'article_max_per_run', 3 ); ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Research depth</span>
							<strong><?php echo (int) Settings::get( 'article_research_depth', 3 ); ?> URLs</strong>
						</div>
						<div class="cep-stat-row">
							<span>Next scheduled</span>
							<strong><?php echo esc_html( self::next_run( 'cep_article_autopilot' ) ); ?></strong>
						</div>
					</div>
					<div class="cep-autopilot-card__footer">
						<form method="post">
							<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
							<input type="hidden" name="cep_trigger" value="article_autopilot">
							<button type="submit" class="button button-primary" <?php echo ! Settings::is_enabled( 'article_autopilot_enabled' ) ? 'disabled' : ''; ?>>
								Run Now
							</button>
						</form>
					</div>
				</div>

				<!-- Review Discoverer -->
				<div class="cep-autopilot-card <?php echo Settings::is_enabled( 'review_autopilot_enabled' ) ? 'cep-active' : 'cep-inactive'; ?>">
					<div class="cep-autopilot-card__header">
						<span class="dashicons dashicons-search cep-pipeline-icon"></span>
						<div>
							<h2>Review Discoverer</h2>
							<span class="cep-status-badge <?php echo Settings::is_enabled( 'review_autopilot_enabled' ) ? 'cep-status-badge--active' : 'cep-status-badge--inactive'; ?>">
								<?php echo Settings::is_enabled( 'review_autopilot_enabled' ) ? 'Enabled' : 'Disabled'; ?>
							</span>
						</div>
					</div>
					<div class="cep-autopilot-card__body">
						<div class="cep-stat-row">
							<span>Products discovered</span>
							<strong><?php echo (int) $stats['discovery_total']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Pending generation</span>
							<strong><?php echo (int) $stats['discovery_pending']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Max per run</span>
							<strong><?php echo (int) Settings::get( 'review_discovery_max_per_run', 20 ); ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Active niche</span>
							<strong><?php echo esc_html( ucfirst( Settings::get( 'niche_vertical', 'wordpress' ) ) ); ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Next scheduled</span>
							<strong><?php echo esc_html( self::next_run( 'cep_reviews_discover' ) ); ?></strong>
						</div>
					</div>
					<div class="cep-autopilot-card__footer">
						<form method="post">
							<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
							<input type="hidden" name="cep_trigger" value="review_discover">
							<button type="submit" class="button button-primary" <?php echo ! Settings::is_enabled( 'review_autopilot_enabled' ) ? 'disabled' : ''; ?>>
								Discover Now
							</button>
						</form>
					</div>
				</div>

				<!-- Review Generator -->
				<div class="cep-autopilot-card <?php echo Settings::is_enabled( 'review_autopilot_enabled' ) ? 'cep-active' : 'cep-inactive'; ?>">
					<div class="cep-autopilot-card__header">
						<span class="dashicons dashicons-star-filled cep-pipeline-icon"></span>
						<div>
							<h2>Review Generator</h2>
							<span class="cep-status-badge <?php echo Settings::is_enabled( 'review_autopilot_enabled' ) ? 'cep-status-badge--active' : 'cep-status-badge--inactive'; ?>">
								<?php echo Settings::is_enabled( 'review_autopilot_enabled' ) ? 'Enabled' : 'Disabled'; ?>
							</span>
						</div>
					</div>
					<div class="cep-autopilot-card__body">
						<div class="cep-stat-row">
							<span>Reviews published</span>
							<strong><?php echo (int) $stats['reviews_published']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>AI-generated</span>
							<strong><?php echo (int) $stats['reviews_ai']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Max per run</span>
							<strong><?php echo (int) Settings::get( 'review_max_per_run', 3 ); ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Research depth</span>
							<strong><?php echo (int) Settings::get( 'review_research_depth', 2 ); ?> URLs</strong>
						</div>
						<div class="cep-stat-row">
							<span>Next scheduled</span>
							<strong><?php echo esc_html( self::next_run( 'cep_reviews_generate' ) ); ?></strong>
						</div>
					</div>
					<div class="cep-autopilot-card__footer">
						<form method="post">
							<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
							<input type="hidden" name="cep_trigger" value="review_generate">
							<button type="submit" class="button button-primary" <?php echo ( ! Settings::is_enabled( 'review_autopilot_enabled' ) || $stats['discovery_pending'] === 0 ) ? 'disabled' : ''; ?>>
								Generate Now
							</button>
						</form>
					</div>
				</div>

				<!-- Jobs Autopilot -->
				<div class="cep-autopilot-card <?php echo Settings::is_enabled( 'jobs_autopilot_enabled' ) ? 'cep-active' : 'cep-inactive'; ?>">
					<div class="cep-autopilot-card__header">
						<span class="dashicons dashicons-businessman cep-pipeline-icon"></span>
						<div>
							<h2>Jobs Autopilot</h2>
							<span class="cep-status-badge <?php echo Settings::is_enabled( 'jobs_autopilot_enabled' ) ? 'cep-status-badge--active' : 'cep-status-badge--inactive'; ?>">
								<?php echo Settings::is_enabled( 'jobs_autopilot_enabled' ) ? 'Enabled' : 'Disabled'; ?>
							</span>
						</div>
					</div>
					<div class="cep-autopilot-card__body">
						<div class="cep-stat-row">
							<span>Jobs published (total)</span>
							<strong><?php echo (int) $stats['jobs_published']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>This month</span>
							<strong><?php echo (int) $stats['jobs_this_month']; ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Max per run</span>
							<strong><?php echo (int) Settings::get( 'jobs_max_per_run', 10 ); ?></strong>
						</div>
						<div class="cep-stat-row">
							<span>Dedup window</span>
							<strong><?php echo (int) Settings::get( 'jobs_dedup_days', 30 ); ?> days</strong>
						</div>
						<div class="cep-stat-row">
							<span>Next scheduled</span>
							<strong><?php echo esc_html( self::next_run( 'cep_jobs_aggregate' ) ); ?></strong>
						</div>
					</div>
					<div class="cep-autopilot-card__footer">
						<form method="post">
							<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
							<input type="hidden" name="cep_trigger" value="jobs_aggregate">
							<button type="submit" class="button button-primary" <?php echo ! Settings::is_enabled( 'jobs_autopilot_enabled' ) ? 'disabled' : ''; ?>>
								Aggregate Now
							</button>
						</form>
					</div>
				</div>

			<!-- Trending Topics Autopilot -->
			<?php $trending_enabled = Settings::is_enabled( 'trending_autopilot_enabled' ); ?>
			<div class="cep-autopilot-card <?php echo $trending_enabled ? 'cep-active cep-active--trending' : 'cep-inactive'; ?>">
				<div class="cep-autopilot-card__header">
					<span class="dashicons dashicons-chart-line cep-pipeline-icon cep-pipeline-icon--trending"></span>
					<div>
						<h2><?php esc_html_e( 'Trending Topics Autopilot', 'content-engine-pro' ); ?></h2>
						<span class="cep-status-badge <?php echo $trending_enabled ? 'cep-status-badge--active' : 'cep-status-badge--inactive'; ?>">
							<?php echo $trending_enabled ? esc_html__( 'Enabled', 'content-engine-pro' ) : esc_html__( 'Disabled', 'content-engine-pro' ); ?>
						</span>
					</div>
				</div>
				<div class="cep-autopilot-card__body">
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Topics discovered (all time)', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) $stats['trending_total']; ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Successfully queued', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) $stats['trending_queued']; ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Min demand score', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) Settings::get( 'trending_min_demand_score', 50 ); ?>/100</strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Max topics per run', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) Settings::get( 'trending_max_per_run', 5 ); ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Sources', 'content-engine-pro' ); ?></span>
						<strong><?php echo esc_html( Settings::get( 'trending_sources', 'google_trends' ) ); ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Next scheduled', 'content-engine-pro' ); ?></span>
						<strong><?php echo esc_html( self::next_run( 'cep_trending_autopilot' ) ); ?></strong>
					</div>
				</div>
				<div class="cep-autopilot-card__footer">
					<form method="post">
						<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
						<input type="hidden" name="cep_trigger" value="trending_discover">
						<button type="submit" class="button button-primary" <?php disabled( ! $trending_enabled ); ?>>
							<?php esc_html_e( 'Discover & Queue Now', 'content-engine-pro' ); ?>
						</button>
					</form>
					<?php if ( ! $trending_enabled ) : ?>
					<p class="cep-card-hint"><?php esc_html_e( 'Enable in Settings → Niche &amp; Autopilot → Trending Topics.', 'content-engine-pro' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			</div><!-- .cep-autopilot-grid -->

			<!-- SEO Autopilot Card (summary; full page at cep-seo) -->
			<?php
			$seo_enabled = Settings::is_enabled( 'seo_autopilot_enabled' );
			$seo_stats   = SeoAutopilot::get_stats();
			$seo_plugin  = SeoPluginDetector::detect_label();
			?>
			<div class="cep-autopilot-card <?php echo $seo_enabled ? 'cep-active cep-active--seo' : 'cep-inactive'; ?>">
				<div class="cep-autopilot-card__header">
					<span class="dashicons dashicons-chart-area cep-pipeline-icon cep-pipeline-icon--seo"></span>
					<div>
						<h2><?php esc_html_e( 'SEO Autopilot', 'content-engine-pro' ); ?></h2>
						<span class="cep-status-badge <?php echo $seo_enabled ? 'cep-status-badge--active' : 'cep-status-badge--inactive'; ?>">
							<?php echo $seo_enabled ? esc_html__( 'Enabled', 'content-engine-pro' ) : esc_html__( 'Disabled', 'content-engine-pro' ); ?>
						</span>
					</div>
				</div>
				<div class="cep-autopilot-card__body">
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'SEO plugin', 'content-engine-pro' ); ?></span>
						<strong><?php echo esc_html( $seo_plugin ); ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Issues pending', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) $seo_stats['pending']; ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Auto-fixed', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) $seo_stats['auto_fixed']; ?></strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Scan window', 'content-engine-pro' ); ?></span>
						<strong><?php echo (int) Settings::get( 'seo_scan_days', 3 ); ?> days</strong>
					</div>
					<div class="cep-stat-row">
						<span><?php esc_html_e( 'Next scheduled', 'content-engine-pro' ); ?></span>
						<strong><?php echo esc_html( self::next_run( 'cep_seo_autopilot' ) ); ?></strong>
					</div>
				</div>
				<div class="cep-autopilot-card__footer">
					<form method="post">
						<?php wp_nonce_field( 'cep_trigger_autopilot', 'cep_trigger_nonce' ); ?>
						<input type="hidden" name="cep_trigger" value="seo_autopilot">
						<button type="submit" class="button button-primary" <?php disabled( ! $seo_enabled ); ?>>
							<?php esc_html_e( 'Scan Now', 'content-engine-pro' ); ?>
						</button>
					</form>
					<p class="cep-card-hint">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-seo' ) ); ?>">
							<?php esc_html_e( 'View all issues →', 'content-engine-pro' ); ?>
						</a>
					</p>
				</div>
			</div>

			<!-- Product Discovery Queue -->
			<?php if ( $stats['discovery_total'] > 0 ) : ?>
			<div class="cep-section">
				<h2>Product Discovery Queue</h2>
				<table class="wp-list-table widefat fixed striped cep-table">
					<thead>
						<tr>
							<th>Product</th>
							<th>Type</th>
							<th>Active Installs</th>
							<th>Rating</th>
							<th>Status</th>
							<th>Discovered</th>
						</tr>
					</thead>
					<tbody>
						<?php
						$queue = $wpdb->get_results(
							"SELECT * FROM {$wpdb->prefix}cep_product_discovery ORDER BY status ASC, active_installs DESC LIMIT 50",
							ARRAY_A
						);
						foreach ( $queue as $row ) :
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $row['product_url'] ); ?>" target="_blank" rel="noopener">
									<?php echo esc_html( $row['product_name'] ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $row['product_type'] ); ?></td>
							<td><?php echo number_format( (int) $row['active_installs'] ); ?></td>
							<td><?php echo esc_html( $row['initial_rating'] > 0 ? $row['initial_rating'] . ' / 5' : '—' ); ?></td>
							<td>
								<span class="cep-status-pill cep-status-pill--<?php echo esc_attr( $row['status'] ); ?>">
									<?php echo esc_html( ucfirst( $row['status'] ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( human_time_diff( strtotime( $row['discovered_at'] ), time() ) . ' ago' ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

		</div><!-- .wrap -->
		<style>
		.cep-pipeline-banner{display:flex;align-items:center;justify-content:space-between;gap:16px;background:linear-gradient(135deg,#6B6EF9,#a78bfa);color:#fff;border-radius:10px;padding:18px 24px;margin-top:20px;flex-wrap:wrap}
		.cep-pipeline-banner__text{display:flex;flex-direction:column;gap:3px}
		.cep-pipeline-banner__text strong{font-size:15px}
		.cep-pipeline-banner__text span{font-size:13px;opacity:.88}
		.cep-pipeline-run-btn{background:#fff!important;color:#6B6EF9!important;border-color:#fff!important;font-weight:700!important;padding:8px 20px!important;height:auto!important;font-size:14px!important}
		.cep-pipeline-run-btn:hover{background:#f0f0ff!important}
		.cep-autopilot-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:20px;margin-top:20px}
		.cep-autopilot-card{background:#fff;border:1px solid #ddd;border-radius:8px;overflow:hidden;display:flex;flex-direction:column}
		.cep-autopilot-card.cep-active{border-top:3px solid #0073aa}
		.cep-autopilot-card.cep-inactive{border-top:3px solid #ccc;opacity:.85}
		.cep-autopilot-card__header{display:flex;align-items:center;gap:12px;padding:16px;border-bottom:1px solid #f0f0f0;background:#fafafa}
		.cep-autopilot-card__header h2{margin:0;font-size:14px;font-weight:600}
		.cep-pipeline-icon{font-size:24px!important;color:#0073aa}
		.cep-autopilot-card__body{padding:16px;flex:1}
		.cep-stat-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f5f5f5;font-size:13px}
		.cep-stat-row:last-child{border-bottom:none}
		.cep-autopilot-card__footer{padding:12px 16px;background:#f9f9f9;border-top:1px solid #f0f0f0}
		.cep-status-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;text-transform:uppercase}
		.cep-status-badge--active{background:#d7f5e3;color:#1a7a3c}
		.cep-status-badge--inactive{background:#f0f0f0;color:#666}
		.cep-status-pill{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
		.cep-status-pill--pending{background:#fff3cd;color:#856404}
		.cep-status-pill--published{background:#d1ecf1;color:#0c5460}
		.cep-status-pill--failed{background:#f8d7da;color:#721c24}
		.cep-status-pill--processing{background:#cfe2ff;color:#084298}
		.cep-section{margin-top:30px}
		.cep-section h2{font-size:16px;font-weight:600;margin-bottom:10px}
		.cep-active--trending{border-top-color:#f97316!important}
		.cep-pipeline-icon--trending{color:#f97316!important}
		.cep-active--seo{border-top-color:#10b981!important}
		.cep-pipeline-icon--seo{color:#10b981!important}
		.cep-card-hint{margin:8px 0 0;font-size:12px;color:#666}
		.cep-btn-loading{opacity:.7;cursor:not-allowed;pointer-events:none}
		.cep-btn-spinner{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:cep-spin .7s linear infinite;margin-right:5px;vertical-align:middle}
		@keyframes cep-spin{to{transform:rotate(360deg)}}
		.cep-trigger-notice{padding:10px 14px;border-left:4px solid #0073aa;background:#f0f6fc;margin-top:10px;border-radius:0 4px 4px 0;font-size:13px;display:none}
		.cep-trigger-notice.cep-notice-success{border-left-color:#1a7a3c;background:#edfaf1}
		.cep-trigger-notice.cep-notice-error{border-left-color:#c0392b;background:#fdf0ef}
		</style>
		<script>
		( function () {
			'use strict';

			// Intercept every trigger form on this page and run it via AJAX so only
			// the clicked button shows a loading state — no full-page reload.
			document.querySelectorAll( '.cep-autopilot-card__footer form, .cep-pipeline-banner form' ).forEach( function ( form ) {
				form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();

					var btn     = form.querySelector( 'button[type="submit"]' );
					var trigger = ( form.querySelector( 'input[name="cep_trigger"]' ) || {} ).value;

					if ( ! btn || ! trigger ) {
						return;
					}

					// Find or create the result notice element for this card/banner.
					var card   = form.closest( '.cep-autopilot-card, .cep-pipeline-banner' );
					var notice = card ? card.querySelector( '.cep-trigger-notice' ) : null;
					if ( ! notice ) {
						notice = document.createElement( 'p' );
						notice.className = 'cep-trigger-notice';
						form.parentNode.insertBefore( notice, form.nextSibling );
					}

					// Loading state.
					var originalHTML = btn.innerHTML;
					btn.disabled    = true;
					btn.classList.add( 'cep-btn-loading' );
					btn.innerHTML   = '<span class="cep-btn-spinner"></span>Running&hellip;';
					notice.style.display = 'none';
					notice.className     = 'cep-trigger-notice';

					// Build form data.
					var body = new URLSearchParams( {
						action : 'cep_manual_trigger',
						nonce  : ( window.cepAdmin || {} ).nonce || '',
						trigger: trigger,
					} );

					fetch( ( window.cepAdmin || {} ).ajaxUrl || ajaxurl, {
						method     : 'POST',
						credentials: 'same-origin',
						headers    : { 'Content-Type': 'application/x-www-form-urlencoded' },
						body       : body.toString(),
					} )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						notice.innerHTML     = data.data && data.data.message ? data.data.message : ( data.success ? '✅ Done.' : '❌ Something went wrong.' );
						notice.classList.add( data.success ? 'cep-notice-success' : 'cep-notice-error' );
						notice.style.display = 'block';
					} )
					.catch( function () {
						notice.innerHTML     = '❌ Request failed — please try again.';
						notice.classList.add( 'cep-notice-error' );
						notice.style.display = 'block';
					} )
					.finally( function () {
						btn.disabled   = false;
						btn.classList.remove( 'cep-btn-loading' );
						btn.innerHTML  = originalHTML;
					} );
				} );
			} );
		} () );
		</script>
		<?php
	}

	/**
	 * Handle manual pipeline trigger via POST (legacy full-page fallback).
	 */
	private static function handle_trigger(): string {
		if ( empty( $_POST['cep_trigger'] ) || empty( $_POST['cep_trigger_nonce'] ) ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_trigger_nonce'] ) ), 'cep_trigger_autopilot' ) ) {
			return '';
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		return self::run_trigger( sanitize_key( $_POST['cep_trigger'] ) );
	}

	/**
	 * Execute a named pipeline trigger and return a human-readable result string.
	 * Called both by handle_trigger() (POST fallback) and the AJAX handler.
	 */
	public static function run_trigger( string $trigger ): string {
		switch ( $trigger ) {
			case 'full_pipeline':
				global $wpdb;
				// Crawl all windows
				foreach ( [ 'morning', 'midday', 'evening', 'weekly' ] as $window ) {
					Crawler::run_window( $window );
				}
				$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );

				// Publish articles
				$before = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Settings::get( 'primary_cpt_slug', 'post' ) )
				);
				ArticleAutopilot::run();
				$after     = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Settings::get( 'primary_cpt_slug', 'post' ) )
				);
				$published = $after - $before;

				// Jobs
				JobAggregator::run();

				return "✅ Pipeline complete — <strong>{$queued}</strong> items crawled into queue, <strong>{$published}</strong> new article(s) published.";

			case 'article_autopilot':
				ArticleAutopilot::run();
				return 'Article autopilot run triggered successfully.';

			case 'review_discover':
				ReviewDiscoverer::run();
				return 'Review discovery run triggered successfully.';

			case 'review_generate':
				ReviewAutopilot::run();
				return 'Review generation run triggered successfully.';

			case 'jobs_aggregate':
				JobAggregator::run();
				return esc_html__( 'Jobs aggregation complete.', 'content-engine-pro' );

			case 'trending_discover':
				$queued = TrendingAutopilot::discover_and_queue();
				return sprintf(
					/* translators: %d: number of topics queued */
					esc_html( _n( 'Trending discovery complete — %d topic queued.', 'Trending discovery complete — %d topics queued.', $queued, 'content-engine-pro' ) ),
					$queued
				);

			case 'seo_autopilot':
				$summary = SeoAutopilot::run();
				return sprintf(
					/* translators: %1$d posts, %2$d issues, %3$d auto-fixed, %4$d pending */
					esc_html__( 'SEO scan complete — %1$d post(s) scanned, %2$d issue(s) found, %3$d auto-fixed, %4$d pending review.', 'content-engine-pro' ),
					$summary['posts_scanned'],
					$summary['issues_found'],
					$summary['auto_fixed'],
					$summary['pending']
				);
		}

		return '';
	}

	/**
	 * Gather stats from DB for display.
	 */
	private static function gather_stats(): array {
		global $wpdb;

		$raw_table       = $wpdb->prefix . 'cep_raw_content';
		$discovery_table = $wpdb->prefix . 'cep_product_discovery';
		$reviews_table   = $wpdb->prefix . 'cep_reviews';
		$jobs_cpt        = Settings::get( 'jobs_cpt_slug', 'job' );
		$reviews_cpt     = Settings::get( 'reviews_cpt_slug', 'review' );

		$trending_stats = TrendingAutopilot::get_stats();

		// wp_count_posts() returns stdClass with status-keyed properties, but if
		// the CPT is not registered the object may lack 'publish'. Using isset()
		// is the only reliable guard across all PHP 8.x minor versions.
		$reviews_counts = wp_count_posts( $reviews_cpt );
		$jobs_counts    = wp_count_posts( $jobs_cpt );

		return [
			'articles_pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw_table} WHERE status = 'pending'" ),
			'articles_published' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$raw_table} WHERE status = 'published'" ),
			'discovery_total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$discovery_table}" ),
			'discovery_pending'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$discovery_table} WHERE status = 'pending'" ),
			'reviews_published'  => isset( $reviews_counts->publish ) ? (int) $reviews_counts->publish : 0,
			'reviews_ai'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$reviews_table} WHERE ai_generated = 1" ),
			'jobs_published'     => isset( $jobs_counts->publish ) ? (int) $jobs_counts->publish : 0,
			'jobs_this_month'    => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date >= %s",
				$jobs_cpt,
				gmdate( 'Y-m-01 00:00:00' )
			) ),
			'trending_total'     => $trending_stats['total'],
			'trending_queued'    => $trending_stats['queued'],
		];
	}

	/**
	 * Format the next scheduled run time for a cron hook.
	 */
	private static function next_run( string $hook ): string {
		$next = wp_next_scheduled( $hook );
		if ( ! $next ) {
			return 'Not scheduled';
		}
		$diff = $next - time();
		if ( $diff < 0 ) {
			return 'Overdue';
		}
		return human_time_diff( time(), $next );
	}
}
