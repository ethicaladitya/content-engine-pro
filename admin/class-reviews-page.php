<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ReviewsPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'cep_reviews';

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$generated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE ai_generated = 1" );
		$recent    = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC LIMIT 10", ARRAY_A );
		$autopilot_enabled = Settings::is_enabled( 'review_autopilot_enabled' ) || Settings::is_enabled( 'enable_reviews_autopilot' );
		$max_per_run = (int) Settings::get( 'review_max_per_run', 0 );
		if ( $max_per_run <= 0 ) {
			$max_per_run = (int) Settings::get( 'reviews_max_per_run', 3 );
		}
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title"><span class="dashicons dashicons-star-filled"></span> Reviews Engine</h1>
				<p class="cep-page-subtitle">Monitor and manage the automated review generation system.</p>
			</div>

			<div class="cep-stats-grid cep-stats-grid--small">
				<div class="cep-stat-card cep-stat-card--blue">
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( $total ); ?></div>
						<div class="cep-stat-label">Total Reviews</div>
					</div>
				</div>
				<div class="cep-stat-card cep-stat-card--green">
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( $generated ); ?></div>
						<div class="cep-stat-label">AI Generated</div>
					</div>
				</div>
				<div class="cep-stat-card cep-stat-card--purple">
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo $autopilot_enabled ? 'ON' : 'OFF'; ?></div>
						<div class="cep-stat-label">Autopilot</div>
					</div>
				</div>
			</div>

			<div class="cep-card" style="margin-top:20px">
				<div class="cep-card-header"><h3>Settings</h3></div>
				<div class="cep-card-body cep-kv-list">
					<div class="cep-kv"><span>Max per cron run</span><strong><?php echo esc_html( $max_per_run ); ?></strong></div>
					<div class="cep-kv"><span>Autopilot</span><strong><?php echo $autopilot_enabled ? 'Enabled' : 'Disabled'; ?></strong></div>
					<div class="cep-kv"><span>AI Model</span><strong><?php echo esc_html( Settings::get( 'ai_model' ) ); ?></strong></div>
				</div>
				<div class="cep-card-footer">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-settings&tab=features' ) ); ?>" class="button button-secondary">Configure Reviews</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Settings::get( 'reviews_cpt_slug', 'review' ) ) ); ?>" class="button button-secondary">All Reviews</a>
				</div>
			</div>

			<div class="cep-card" style="margin-top:20px">
				<div class="cep-card-header"><h3>Recent Reviews</h3></div>
				<div class="cep-card-body cep-table-wrap">
					<table class="wp-list-table widefat fixed striped cep-table">
						<thead>
							<tr>
								<th>Product</th>
								<th>Type</th>
								<th>Rating</th>
								<th>AI Generated</th>
								<th>Date</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $recent ) ) : ?>
								<tr><td colspan="5"><em>No reviews yet.</em></td></tr>
							<?php else : foreach ( $recent as $r ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $r['product_name'] ); ?></strong></td>
								<td><?php echo esc_html( ucfirst( $r['review_type'] ) ); ?></td>
								<td>
									<span class="cep-stars">
										<?php for ( $i = 0; $i < 5; $i++ ) : ?>
											<span class="cep-star <?php echo $i < round( (float) $r['star_rating'] ) ? 'cep-star--filled' : ''; ?>">&#9733;</span>
										<?php endfor; ?>
										(<?php echo esc_html( $r['star_rating'] ); ?>)
									</span>
								</td>
								<td><?php echo $r['ai_generated'] ? '<span class="cep-badge cep-badge--green">AI</span>' : '—'; ?></td>
								<td><?php echo esc_html( substr( $r['created_at'], 0, 10 ) ); ?></td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
