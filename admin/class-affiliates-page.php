<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AffiliatesPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$clicks_table = $wpdb->prefix . 'cep_clicks';

		$stats = $wpdb->get_results(
			"SELECT provider_slug, COUNT(*) as clicks, MAX(clicked_at) as last_click
			FROM {$clicks_table}
			GROUP BY provider_slug
			ORDER BY clicks DESC
			LIMIT 50",
			ARRAY_A
		);

		$total_clicks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clicks_table}" );
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title"><span class="dashicons dashicons-store"></span> Affiliate Tracking</h1>
				<p class="cep-page-subtitle">Redirect base: <code><?php echo esc_html( '/' . Settings::get( 'affiliate_redirect_base', 'go' ) . '/{slug}/' ); ?></code></p>
			</div>

			<div class="cep-stats-grid cep-stats-grid--small">
				<div class="cep-stat-card cep-stat-card--blue">
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( number_format_i18n( $total_clicks ) ); ?></div>
						<div class="cep-stat-label">Total Clicks</div>
					</div>
				</div>
				<div class="cep-stat-card cep-stat-card--green">
					<div class="cep-stat-body">
						<div class="cep-stat-value"><?php echo esc_html( count( $stats ) ); ?></div>
						<div class="cep-stat-label">Active Providers</div>
					</div>
				</div>
			</div>

			<div class="cep-card" style="margin-top:20px">
				<div class="cep-card-header"><h3>Clicks by Provider</h3></div>
				<div class="cep-card-body cep-table-wrap">
					<table class="wp-list-table widefat fixed striped cep-table">
						<thead>
							<tr>
								<th>Provider Slug</th>
								<th>Total Clicks</th>
								<th>Last Click</th>
								<th>Redirect URL</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $stats ) ) : ?>
								<tr><td colspan="4"><em>No click data yet.</em></td></tr>
							<?php else : foreach ( $stats as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row['provider_slug'] ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['clicks'] ) ); ?></td>
								<td><?php echo $row['last_click'] ? esc_html( human_time_diff( strtotime( $row['last_click'] ), time() ) . ' ago' ) : '—'; ?></td>
								<td><code><?php echo esc_html( cep_get_affiliate_redirect_url( $row['provider_slug'] ) ); ?></code></td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<div class="cep-card" style="margin-top:20px">
				<div class="cep-card-header"><h3>Manage Providers</h3></div>
				<div class="cep-card-body">
					<p>Providers are managed as a private post type in WordPress admin.</p>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Settings::get( 'providers_cpt_slug', 'provider' ) ) ); ?>" class="button button-primary">Manage Providers</a>
				</div>
			</div>
		</div>
		<?php
	}
}
