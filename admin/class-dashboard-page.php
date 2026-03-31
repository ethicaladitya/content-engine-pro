<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$primary_cpt    = Settings::get( 'primary_cpt_slug', 'post' );
		$reviews_cpt    = Settings::get( 'reviews_cpt_slug', 'review' );
		$jobs_cpt       = Settings::get( 'jobs_cpt_slug', 'job' );
		$brand          = Settings::get( 'brand_name', 'Content Engine' );

		$total_posts     = wp_count_posts( $primary_cpt );
		$published_posts = isset( $total_posts->publish ) ? (int) $total_posts->publish : 0;

		$total_reviews     = wp_count_posts( $reviews_cpt );
		$published_reviews = isset( $total_reviews->publish ) ? (int) $total_reviews->publish : 0;

		$sources_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_sources WHERE is_active = 1" );
		$queue_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cep_raw_content WHERE status = 'pending'" );

		$recent_logs = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}cep_logs ORDER BY id DESC LIMIT 10",
			ARRAY_A
		);

		$activated_at = get_option( 'cep_activated_at' );
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title">
					<span class="dashicons dashicons-database"></span>
					<?php echo esc_html( $brand ); ?> — Dashboard
				</h1>
				<p class="cep-page-subtitle">
					Content Engine Pro is active and running.
					<?php if ( $activated_at ) : ?>
						Active since <?php echo esc_html( date_i18n( 'M j, Y', $activated_at ) ); ?>.
					<?php endif; ?>
				</p>
			</div>

			<div class="cep-stats-grid">
				<div class="cep-stat-card cep-stat-card--blue">
					<div class="cep-stat-icon"><span class="dashicons dashicons-media-document"></span></div>
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( number_format_i18n( $published_posts ) ); ?></div>
						<div class="cep-stat-label">Published <?php echo esc_html( Settings::get( 'primary_cpt_plural', 'Posts' ) ); ?></div>
					</div>
				</div>
				<div class="cep-stat-card cep-stat-card--green">
					<div class="cep-stat-icon"><span class="dashicons dashicons-star-filled"></span></div>
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( number_format_i18n( $published_reviews ) ); ?></div>
						<div class="cep-stat-label">Published <?php echo esc_html( Settings::get( 'reviews_cpt_plural', 'Reviews' ) ); ?></div>
					</div>
				</div>
				<div class="cep-stat-card cep-stat-card--purple">
					<div class="cep-stat-icon"><span class="dashicons dashicons-rss"></span></div>
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( number_format_i18n( $sources_count ) ); ?></div>
						<div class="cep-stat-label">Active Sources</div>
					</div>
				</div>
				<div class="cep-stat-card cep-stat-card--orange">
					<div class="cep-stat-icon"><span class="dashicons dashicons-list-view"></span></div>
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( number_format_i18n( $queue_count ) ); ?></div>
						<div class="cep-stat-label">Pending Queue</div>
					</div>
				</div>
			</div>

			<div class="cep-dashboard-grid">
				<div class="cep-card">
					<div class="cep-card-header"><h3>Feature Status</h3></div>
					<div class="cep-card-body">
						<ul class="cep-feature-list">
							<?php
							$features = [
								'enable_crawling'         => 'Content Crawling',
								'enable_ai_publishing'    => 'AI Publishing',
								'enable_reviews'          => 'Reviews Module',
								'enable_reviews_autopilot'=> 'Reviews Autopilot',
								'enable_affiliates'       => 'Affiliates',
								'enable_click_tracking'   => 'Click Tracking',
								'enable_schema_markup'    => 'Schema Markup',
								'enable_internal_linking' => 'Internal Linking',
								'enable_rest_api'         => 'REST API',
								'enable_jobs'             => 'Jobs Module',
							];
							foreach ( $features as $key => $label ) :
								$enabled = Settings::is_enabled( $key );
							?>
								<li class="cep-feature-item">
									<span class="cep-feature-dot cep-feature-dot--<?php echo $enabled ? 'on' : 'off'; ?>"></span>
									<?php echo esc_html( $label ); ?>
									<span class="cep-feature-status"><?php echo $enabled ? 'ON' : 'OFF'; ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
					<div class="cep-card-footer">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings&tab=features' ) ); ?>" class="button button-secondary">Configure Features</a>
					</div>
				</div>

				<div class="cep-card">
					<div class="cep-card-header"><h3>Recent Activity</h3></div>
					<div class="cep-card-body">
						<?php if ( empty( $recent_logs ) ) : ?>
							<p class="cep-empty">No recent activity. Logs will appear here once crawling starts.</p>
						<?php else : ?>
							<ul class="cep-log-list">
								<?php foreach ( $recent_logs as $log ) : ?>
									<li class="cep-log-item cep-log-item--<?php echo esc_attr( $log['level'] ); ?>">
										<span class="cep-log-badge cep-log-badge--<?php echo esc_attr( $log['level'] ); ?>"><?php echo esc_html( strtoupper( $log['level'] ) ); ?></span>
										<span class="cep-log-context">[<?php echo esc_html( $log['context'] ); ?>]</span>
										<span class="cep-log-msg"><?php echo esc_html( $log['message'] ); ?></span>
										<span class="cep-log-time"><?php echo esc_html( human_time_diff( strtotime( $log['created_at'] ), time() ) . ' ago' ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</div>
					<div class="cep-card-footer">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-logs' ) ); ?>" class="button button-secondary">View All Logs</a>
					</div>
				</div>
			</div>

			<div class="cep-card cep-card--quick-actions">
				<div class="cep-card-header"><h3>Quick Actions</h3></div>
				<div class="cep-card-body cep-quick-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-sources' ) ); ?>" class="cep-action-btn">
						<span class="dashicons dashicons-rss"></span> Manage Sources
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-queue' ) ); ?>" class="cep-action-btn">
						<span class="dashicons dashicons-list-view"></span> Content Queue
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings' ) ); ?>" class="cep-action-btn">
						<span class="dashicons dashicons-admin-settings"></span> Settings
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings&tab=advanced&cep_flush=1&_wpnonce=' . wp_create_nonce( 'cep_flush' ) ) ); ?>" class="cep-action-btn cep-action-btn--secondary">
						<span class="dashicons dashicons-update"></span> Flush Rewrites
					</a>
				</div>
			</div>
		</div>
		<?php
	}
}
