<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;
use ContentEnginePro\Articles\ArticleAutopilot;
use ContentEnginePro\Reviews\ReviewDiscoverer;
use ContentEnginePro\Reviews\ReviewAutopilot;
use ContentEnginePro\Jobs\JobAggregator;
use ContentEnginePro\Crawl\Crawler;
use ContentEnginePro\Trending\TrendingAutopilot;

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
			<div id="cep-ajax-notice-area"></div>

			<!-- Run Full Pipeline -->
			<div class="cep-pipeline-banner">
				<div class="cep-pipeline-banner__text">
					<strong>🚀 Run Full Pipeline</strong>
					<span>Crawl all sources → Publish articles → Aggregate jobs in one click.</span>
				</div>
				<button type="button" class="button button-primary button-hero cep-pipeline-run-btn cep-trigger-btn" data-trigger="full_pipeline">
					▶ Run Full Pipeline
				</button>
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
						<button type="button" class="button button-primary cep-trigger-btn" data-trigger="article_autopilot" <?php echo ! Settings::is_enabled( 'article_autopilot_enabled' ) ? 'disabled' : ''; ?>>
						Run Now
					</button>
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
						<button type="button" class="button button-primary cep-trigger-btn" data-trigger="review_discover" <?php echo ! Settings::is_enabled( 'review_autopilot_enabled' ) ? 'disabled' : ''; ?>>
						Discover Now
					</button>
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
						<button type="button" class="button button-primary cep-trigger-btn" data-trigger="review_generate" <?php echo ( ! Settings::is_enabled( 'review_autopilot_enabled' ) || $stats['discovery_pending'] === 0 ) ? 'disabled' : ''; ?>>
						Generate Now
					</button>
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
						<button type="button" class="button button-primary cep-trigger-btn" data-trigger="jobs_aggregate" <?php echo ! Settings::is_enabled( 'jobs_autopilot_enabled' ) ? 'disabled' : ''; ?>>
						Aggregate Now
					</button>
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
					<button type="button" class="button button-primary cep-trigger-btn" data-trigger="trending_discover" <?php disabled( ! $trending_enabled ); ?>>
					<?php esc_html_e( 'Discover & Queue Now', 'content-engine-pro' ); ?>
				</button>
					<?php if ( ! $trending_enabled ) : ?>
					<p class="cep-card-hint"><?php esc_html_e( 'Enable in Settings → Niche &amp; Autopilot → Trending Topics.', 'content-engine-pro' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			</div><!-- .cep-autopilot-grid -->

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
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
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
		.cep-card-hint{margin:8px 0 0;font-size:12px;color:#666}

		/* ── Trigger button loading states ── */
		@keyframes cep-card-shimmer {
			0%   { background-position: -400px 0 }
			100% { background-position: 400px 0 }
		}
		@keyframes cep-spin {
			to { transform: rotate(360deg) }
		}
		@keyframes cep-flash-success {
			0%,100% { box-shadow: none }
			40%      { box-shadow: 0 0 0 3px #34d399 }
		}
		@keyframes cep-flash-error {
			0%,100% { box-shadow: none }
			40%      { box-shadow: 0 0 0 3px #f87171 }
		}
		.cep-autopilot-card--running {
			position: relative;
			overflow: hidden;
		}
		.cep-autopilot-card--running::after {
			content: '';
			position: absolute;
			inset: 0;
			background: linear-gradient(90deg, transparent 0%, rgba(107,110,249,.08) 50%, transparent 100%);
			background-size: 800px 100%;
			animation: cep-card-shimmer 1.4s infinite linear;
			pointer-events: none;
			z-index: 1;
		}
		.cep-autopilot-card--success { animation: cep-flash-success .7s ease }
		.cep-autopilot-card--error   { animation: cep-flash-error .7s ease }
		.cep-trigger-btn.cep-running  { opacity: .8; cursor: wait }
		.cep-trigger-btn .cep-btn-spinner {
			display: inline-block;
			width: 11px; height: 11px;
			border: 2px solid currentColor;
			border-top-color: transparent;
			border-radius: 50%;
			animation: cep-spin .7s linear infinite;
			vertical-align: middle;
			margin-right: 5px;
		}
		/* Floating result notice */
		.cep-ajax-notice {
			position: fixed;
			bottom: 28px; right: 28px;
			max-width: 420px;
			padding: 12px 18px;
			border-radius: 8px;
			font-size: 13px;
			font-weight: 500;
			box-shadow: 0 4px 20px rgba(0,0,0,.18);
			z-index: 99999;
			line-height: 1.5;
		}
		.cep-ajax-notice--success { background: #ecfdf5; color: #065f46; border-left: 4px solid #10b981 }
		.cep-ajax-notice--error   { background: #fef2f2; color: #7f1d1d; border-left: 4px solid #ef4444 }
		</style>
		<?php
	}

	/**
	 * AJAX handler for autopilot trigger buttons.
	 */
	public static function ajax_trigger(): void {
		if ( ! check_ajax_referer( 'cep_admin_nonce', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotUnslashed -- sanitize_key handles slashes
		$trigger = sanitize_key( wp_unslash( $_POST['trigger'] ?? '' ) );
		$message = self::run_trigger( $trigger );

		if ( $message ) {
			wp_send_json_success( [ 'message' => $message ] );
		} else {
			wp_send_json_error( [ 'message' => 'Unknown trigger.' ] );
		}
	}

	/**
	 * Handle manual pipeline trigger via POST (form fallback).
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

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotUnslashed
		return self::run_trigger( sanitize_key( wp_unslash( $_POST['cep_trigger'] ) ) );
	}

	/**
	 * Execute a named pipeline trigger and return a result message.
	 */
	private static function run_trigger( string $trigger ): string {
		switch ( $trigger ) {
			case 'full_pipeline':
				global $wpdb;
				foreach ( [ 'morning', 'midday', 'evening', 'weekly' ] as $window ) {
					Crawler::run_window( $window );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$queued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );

				$before = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Settings::get( 'primary_cpt_slug', 'post' ) )
				);
				ArticleAutopilot::run();
				$after = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", Settings::get( 'primary_cpt_slug', 'post' ) )
				);
				$published = $after - $before;

				JobAggregator::run();

				return '✅ Pipeline complete — <strong>' . (int) $queued . '</strong> items crawled into queue, <strong>' . (int) $published . '</strong> new article(s) published.';

			case 'article_autopilot':
				ArticleAutopilot::run();
				return esc_html__( 'Article autopilot run complete.', 'content-engine-pro' );

			case 'review_discover':
				ReviewDiscoverer::run();
				return esc_html__( 'Review discovery complete.', 'content-engine-pro' );

			case 'review_generate':
				ReviewAutopilot::run();
				return esc_html__( 'Review generation complete.', 'content-engine-pro' );

			case 'jobs_aggregate':
				JobAggregator::run();
				return esc_html__( 'Jobs aggregation complete.', 'content-engine-pro' );

			case 'trending_discover':
				$queued = TrendingAutopilot::discover_and_queue();
				return esc_html( sprintf(
					/* translators: %d: number of topics queued */
					_n( 'Trending discovery complete — %d topic queued.', 'Trending discovery complete — %d topics queued.', $queued, 'content-engine-pro' ),
					$queued
				) );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
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
