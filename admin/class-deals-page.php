<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;
use ContentEnginePro\Deals\DealDiscoverer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page for the Deals Engine.
 * Shows queue stats, source management, and recent deal articles.
 *
 * @since 1.3.0
 */
class DealsPage {

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'content-engine-pro' ) );
		}

		// Handle manual trigger actions
		if ( isset( $_POST['cep_deals_action'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'cep_deals_action' ) ) {
			$this->handle_action( sanitize_key( $_POST['cep_deals_action'] ) );
		}

		// Handle add source
		if ( isset( $_POST['cep_add_source'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'cep_add_deal_source' ) ) {
			$this->handle_add_source();
		}

		$stats   = DealDiscoverer::get_stats();
		$sources = $this->get_sources();
		$recent  = $this->get_recent_deals();

		echo '<div class="wrap">';
		echo '<h1>🏷️ Deals Engine</h1>';

		// Status cards
		printf(
			'<div style="display:flex;gap:16px;margin:16px 0">%s</div>',
			$this->stat_card( 'Pending', (int) $stats['pending'], '#f0ad4e' ) .
			$this->stat_card( 'Published', (int) $stats['published'], '#5cb85c' ) .
			$this->stat_card( 'Expired', (int) $stats['expired'], '#d9534f' ) .
			$this->stat_card( 'Total Discovered', (int) $stats['total'], '#337ab7' )
		);

		// Manual run buttons
		echo '<p>';
		echo '<form method="post" style="display:inline">';
		wp_nonce_field( 'cep_deals_action' );
		echo '<input type="hidden" name="cep_deals_action" value="discover">';
		echo '<button type="submit" class="button button-primary">▶ Run Discovery Now</button>';
		echo '</form> &nbsp;';
		echo '<form method="post" style="display:inline">';
		wp_nonce_field( 'cep_deals_action' );
		echo '<input type="hidden" name="cep_deals_action" value="generate">';
		echo '<button type="submit" class="button">✍ Generate Articles Now</button>';
		echo '</form>';
		echo '</p>';

		if ( ! empty( $_GET['cep_msg'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sanitize_text_field( $_GET['cep_msg'] ) ) );
		}

		// Settings panel
		echo '<h2>Settings</h2>';
		$max        = (int) Settings::get( 'deals_max_per_run', 2 );
		$min_score  = (int) Settings::get( 'deals_min_quality_score', 40 );
		$min_disc   = (int) Settings::get( 'deals_min_discount_pct', 10 );
		$region     = Settings::get( 'deals_default_region', 'US' );
		$autopilot  = Settings::is_enabled( 'deals_autopilot_enabled' ) ? 'checked' : '';
		echo '<form method="post" action="options.php">';
		// We write settings inline for simplicity
		echo '<table class="form-table"><tbody>';
		printf( '<tr><th>Autopilot Enabled</th><td><input type="checkbox" name="cep_settings[deals_autopilot_enabled]" value="1" %s></td></tr>', $autopilot );
		printf( '<tr><th>Max Deals Per Day</th><td><input type="number" name="cep_settings[deals_max_per_run]" value="%d" min="1" max="20" style="width:80px"></td></tr>', $max );
		printf( '<tr><th>Min Quality Score (0-100)</th><td><input type="number" name="cep_settings[deals_min_quality_score]" value="%d" min="0" max="100" style="width:80px"></td></tr>', $min_score );
		printf( '<tr><th>Min Discount %%</th><td><input type="number" name="cep_settings[deals_min_discount_pct]" value="%d" min="0" max="99" style="width:80px"></td></tr>', $min_disc );
		printf( '<tr><th>Default Region</th><td><input type="text" name="cep_settings[deals_default_region]" value="%s" style="width:80px" maxlength="10"></td></tr>', esc_attr( $region ) );
		echo '</tbody></table>';
		// Save via direct option update for simplicity
		echo '<input type="hidden" name="action" value="cep_save_deals_settings">';
		echo get_submit_button( 'Save Settings' );
		echo '</form>';

		// Deal Sources
		echo '<h2>Deal Feed Sources</h2>';
		echo '<form method="post">';
		wp_nonce_field( 'cep_add_deal_source' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Source Name</th><td><input type="text" name="ds_name" class="regular-text" required></td></tr>';
		echo '<tr><th>RSS Feed URL</th><td><input type="url" name="ds_url" class="large-text" required></td></tr>';
		echo '<tr><th>Category</th><td><input type="text" name="ds_category" value="general" class="regular-text"></td></tr>';
		echo '<tr><th>Region</th><td><input type="text" name="ds_region" value="US" style="width:80px" maxlength="10"></td></tr>';
		echo '</tbody></table>';
		echo '<input type="hidden" name="cep_add_source" value="1">';
		echo get_submit_button( 'Add Feed Source', 'secondary' );
		echo '</form>';

		if ( ! empty( $sources ) ) {
			echo '<table class="wp-list-table widefat striped"><thead><tr><th>Name</th><th>Feed URL</th><th>Category</th><th>Region</th><th>Last Checked</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $sources as $src ) {
				$toggle_url = admin_url( 'admin.php?page=cep-deals&cep_toggle_source=' . (int) $src['id'] . '&_wpnonce=' . wp_create_nonce( 'cep_toggle_source_' . $src['id'] ) );
				$delete_url = admin_url( 'admin.php?page=cep-deals&cep_delete_source=' . (int) $src['id'] . '&_wpnonce=' . wp_create_nonce( 'cep_delete_source_' . $src['id'] ) );
				printf(
					'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'Delete?\')">Delete</a></td></tr>',
					esc_html( $src['name'] ),
					esc_html( $src['feed_url'] ),
					esc_html( $src['category'] ),
					esc_html( $src['region'] ),
					esc_html( $src['last_checked_at'] ?? 'Never' ),
					$src['is_active'] ? '✅' : '❌',
					esc_url( $toggle_url ),
					$src['is_active'] ? 'Disable' : 'Enable',
					esc_url( $delete_url )
				);
			}
			echo '</tbody></table>';
		}

		// Recent deal articles
		if ( ! empty( $recent ) ) {
			echo '<h2>Recent Deal Articles</h2>';
			echo '<table class="wp-list-table widefat striped"><thead><tr><th>Title</th><th>Price</th><th>Merchant</th><th>Quality</th><th>Published</th><th>Status</th></tr></thead><tbody>';
			foreach ( $recent as $deal ) {
				$edit_url = get_edit_post_link( $deal['wp_post_id'] );
				printf(
					'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_url( $edit_url ?: '#' ),
					esc_html( $deal['product_name'] ),
					$deal['deal_price'] ? '$' . number_format( (float) $deal['deal_price'], 2 ) : '—',
					esc_html( $deal['merchant_name'] ?: '—' ),
					number_format( (float) $deal['quality_score'], 1 ),
					esc_html( $deal['published_at'] ?? '—' ),
					esc_html( $deal['status'] )
				);
			}
			echo '</tbody></table>';
		}

		echo '</div>';
	}

	private function handle_action( string $action ): void {
		switch ( $action ) {
			case 'discover':
				DealDiscoverer::run();
				wp_safe_redirect( admin_url( 'admin.php?page=cep-deals&cep_msg=Discovery+run+complete' ) );
				exit;
			case 'generate':
				\ContentEnginePro\Deals\DealAutopilot::run();
				wp_safe_redirect( admin_url( 'admin.php?page=cep-deals&cep_msg=Generation+run+complete' ) );
				exit;
		}

		// Toggle/delete source by GET param
		if ( isset( $_GET['cep_toggle_source'] ) && isset( $_GET['_wpnonce'] ) ) {
			$id = (int) $_GET['cep_toggle_source'];
			if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'cep_toggle_source_' . $id ) ) {
				global $wpdb;
				$curr = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$wpdb->prefix}cep_deal_sources WHERE id = %d", $id ) );
				$wpdb->update( $wpdb->prefix . 'cep_deal_sources', [ 'is_active' => $curr ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
				wp_safe_redirect( admin_url( 'admin.php?page=cep-deals' ) );
				exit;
			}
		}
	}

	private function handle_add_source(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cep_deal_sources',
			[
				'name'        => sanitize_text_field( $_POST['ds_name'] ?? '' ),
				'feed_url'    => esc_url_raw( $_POST['ds_url'] ?? '' ),
				'source_type' => 'rss',
				'provider'    => 'generic_rss',
				'category'    => sanitize_key( $_POST['ds_category'] ?? 'general' ),
				'region'      => strtoupper( substr( sanitize_text_field( $_POST['ds_region'] ?? 'US' ), 0, 10 ) ),
				'is_active'   => 1,
				'created_at'  => current_time( 'mysql', true ),
			],
			[ '%s','%s','%s','%s','%s','%s','%d','%s' ]
		);
		wp_safe_redirect( admin_url( 'admin.php?page=cep-deals&cep_msg=Source+added' ) );
		exit;
	}

	private function get_sources(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_deal_sources';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY is_active DESC, name ASC", ARRAY_A );
	}

	private function get_recent_deals( int $limit = 20 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_deals';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return [];
		}
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status IN ('published','pending','failed') ORDER BY discovered_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
	}

	private function stat_card( string $label, int $value, string $color ): string {
		return sprintf(
			'<div style="background:%s;color:#fff;padding:16px 24px;border-radius:8px;min-width:120px;text-align:center"><div style="font-size:2em;font-weight:700">%d</div><div style="font-size:0.85em;margin-top:4px">%s</div></div>',
			esc_attr( $color ),
			$value,
			esc_html( $label )
		);
	}
}
