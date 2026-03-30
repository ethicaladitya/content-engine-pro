<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SourcesPage {

	public static function handle_post(): void {
		if ( ! isset( $_GET['page'] ) || 'cep-sources' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cep_sources';

		// Handle POST add/edit
		if ( isset( $_POST['cep_source_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cep_source_nonce'] ) ), 'cep_source_action' ) ) {
			self::handle_source_action();
		}

		// Handle GET toggle/delete
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) ) {
			if ( 'toggle' === $_GET['action'] && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cep_toggle_source' ) ) {
				$id      = absint( $_GET['id'] );
				$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM $table WHERE id = %d", $id ) );
				$wpdb->update( $table, [ 'is_active' => $current ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
				wp_redirect( admin_url( 'admin.php?page=cep-sources&updated=1' ) );
				exit;
			}
			if ( 'delete' === $_GET['action'] && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cep_delete_source' ) ) {
				$wpdb->delete( $table, [ 'id' => absint( $_GET['id'] ) ], [ '%d' ] );
				wp_redirect( admin_url( 'admin.php?page=cep-sources&updated=1' ) );
				exit;
			}
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cep_sources';

		$sources = $wpdb->get_results( "SELECT * FROM $table ORDER BY tier ASC, name ASC", ARRAY_A );
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title"><span class="dashicons dashicons-rss"></span> Content Sources</h1>
				<p class="cep-page-subtitle">Manage RSS feeds and content sources for crawling.</p>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Source saved successfully.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['cep_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>Failed to save source. <?php echo isset( $_GET['dberr'] ) ? esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['dberr'] ) ) ) ) : 'The feed URL may already exist, or a database error occurred.'; ?></p></div>
			<?php endif; ?>

			<div class="cep-card">
				<div class="cep-card-header">
					<h3>Add New Source</h3>
				</div>
				<div class="cep-card-body">
					<form method="post">
						<?php wp_nonce_field( 'cep_source_action', 'cep_source_nonce' ); ?>
						<input type="hidden" name="cep_source_action_type" value="add" />
						<div class="cep-form-grid">
							<div class="cep-form-field">
								<label>Source Name <input type="text" name="source_name" class="regular-text" required /></label>
							</div>
							<div class="cep-form-field">
								<label>Feed URL <input type="url" name="feed_url" class="regular-text" required /></label>
							</div>
							<div class="cep-form-field">
								<label>Homepage URL <input type="url" name="homepage_url" class="regular-text" /></label>
							</div>
							<div class="cep-form-field">
								<label>Type
									<select name="source_type">
										<option value="rss">RSS</option>
										<option value="api">API</option>
										<option value="html">HTML</option>
									</select>
								</label>
							</div>
							<div class="cep-form-field">
								<label>Category
									<select name="category">
										<option value="general">General</option>
										<option value="tech">Tech</option>
										<option value="security">Security</option>
										<option value="business">Business</option>
										<option value="community">Community</option>
										<option value="jobs">Jobs</option>
									</select>
								</label>
							</div>
							<div class="cep-form-field">
								<label>Crawl Window
									<select name="crawl_window">
										<option value="morning">Morning</option>
										<option value="midday">Midday</option>
										<option value="evening">Evening</option>
										<option value="weekly">Weekly</option>
									</select>
								</label>
							</div>
							<div class="cep-form-field">
								<label>Tier
									<select name="tier">
										<option value="1">Tier 1 (Primary)</option>
										<option value="2">Tier 2 (Secondary)</option>
									</select>
								</label>
							</div>
						</div>
						<?php submit_button( 'Add Source', 'primary', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<div class="cep-card" style="margin-top:20px">
				<div class="cep-card-header">
					<h3>All Sources (<?php echo count( $sources ); ?>)</h3>
				</div>
				<div class="cep-card-body cep-table-wrap">
					<table class="wp-list-table widefat fixed striped cep-table">
						<thead>
							<tr>
								<th>Name</th>
								<th>Feed URL</th>
								<th>Type</th>
								<th>Category</th>
								<th>Window</th>
								<th>Tier</th>
								<th>Reliability</th>
								<th>Last Crawled</th>
								<th>Status</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $sources ) ) : ?>
								<tr><td colspan="10"><em>No sources yet. Add your first source above.</em></td></tr>
							<?php else : foreach ( $sources as $s ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $s['name'] ); ?></strong></td>
								<td><a href="<?php echo esc_url( $s['feed_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s['feed_url'] ); ?></a></td>
								<td><?php echo esc_html( strtoupper( $s['source_type'] ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $s['category'] ) ); ?></td>
								<td><?php echo esc_html( ucfirst( $s['crawl_window'] ) ); ?></td>
								<td><?php echo esc_html( $s['tier'] ); ?></td>
								<td><?php echo esc_html( $s['reliability_score'] . '%' ); ?></td>
								<td><?php echo $s['last_crawled_at'] ? esc_html( human_time_diff( strtotime( $s['last_crawled_at'] ), time() ) . ' ago' ) : '—'; ?></td>
								<td>
									<span class="cep-badge cep-badge--<?php echo $s['is_active'] ? 'green' : 'red'; ?>">
										<?php echo $s['is_active'] ? 'Active' : 'Paused'; ?>
									</span>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-sources&action=toggle&id=' . $s['id'] . '&_wpnonce=' . wp_create_nonce( 'cep_toggle_source' ) ) ); ?>" class="button button-small">
										<?php echo $s['is_active'] ? 'Pause' : 'Activate'; ?>
									</a>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-sources&action=delete&id=' . $s['id'] . '&_wpnonce=' . wp_create_nonce( 'cep_delete_source' ) ) ); ?>" class="button button-small button-link-delete" onclick="return confirm('Delete this source?')">Delete</a>
								</td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	private static function handle_source_action(): void {
		global $wpdb;
		$table  = $wpdb->prefix . 'cep_sources';
		$action = sanitize_key( $_POST['cep_source_action_type'] ?? '' );

		if ( 'add' === $action ) {
			$result = $wpdb->insert(
				$table,
				[
					'name'        => sanitize_text_field( $_POST['source_name'] ?? '' ),
					'feed_url'    => esc_url_raw( $_POST['feed_url'] ?? '' ),
					'homepage_url'=> esc_url_raw( $_POST['homepage_url'] ?? '' ),
					'source_type' => sanitize_key( $_POST['source_type'] ?? 'rss' ),
					'category'    => sanitize_key( $_POST['category'] ?? 'general' ),
					'crawl_window'=> sanitize_key( $_POST['crawl_window'] ?? 'morning' ),
					'tier'        => (int) ( $_POST['tier'] ?? 1 ),
					'is_active'   => 1,
					'created_at'  => current_time( 'mysql', true ),
				],
				[ '%s','%s','%s','%s','%s','%s','%d','%d','%s' ]
			);

			if ( $result ) {
				wp_redirect( admin_url( 'admin.php?page=cep-sources&updated=1' ) );
				exit;
			} else {
				error_log( '[CEP] Source insert failed. DB error: ' . $wpdb->last_error );
				$err = urlencode( $wpdb->last_error );
				wp_redirect( admin_url( 'admin.php?page=cep-sources&cep_error=1&dberr=' . $err ) );
				exit;
			}
		}
	}
}
