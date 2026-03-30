<?php
namespace ContentEnginePro\Admin;

use ContentEnginePro\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogsPage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$per_page = 50;
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$level    = sanitize_key( $_GET['level'] ?? '' );
		$context  = sanitize_key( $_GET['context'] ?? '' );

		$logs  = Logger::get_logs( compact( 'per_page', 'offset', 'level', 'context' ) );
		$total = self::count_logs( $level, $context );
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		// Prune action
		if ( isset( $_GET['prune'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cep_prune_logs' ) ) {
			Logger::prune();
			wp_redirect( admin_url( 'admin.php?page=cep-logs&pruned=1' ) );
			exit;
		}
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title"><span class="dashicons dashicons-list-view"></span> Activity Logs</h1>
				<div class="cep-page-actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-logs&prune=1&_wpnonce=' . wp_create_nonce( 'cep_prune_logs' ) ) ); ?>" class="button button-secondary">Prune Old Logs</a>
				</div>
			</div>

			<?php if ( isset( $_GET['pruned'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Old logs pruned.</p></div>
			<?php endif; ?>

			<div class="cep-logs-filter">
				<form method="get">
					<input type="hidden" name="page" value="cep-logs" />
					<select name="level" onchange="this.form.submit()">
						<option value="">All Levels</option>
						<?php foreach ( ['info','warning','error','debug'] as $l ) : ?>
							<option value="<?php echo esc_attr( $l ); ?>" <?php selected( $level, $l ); ?>><?php echo esc_html( ucfirst( $l ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="context" onchange="this.form.submit()">
						<option value="">All Contexts</option>
						<?php foreach ( ['crawler','scorer','publisher','reviews','affiliate','api','general'] as $c ) : ?>
							<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $context, $c ); ?>><?php echo esc_html( ucfirst( $c ) ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>

			<div class="cep-card">
				<div class="cep-card-body cep-table-wrap">
					<table class="wp-list-table widefat fixed striped cep-table cep-logs-table">
						<thead>
							<tr>
								<th width="70">Level</th>
								<th width="100">Context</th>
								<th>Message</th>
								<th width="160">Time</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr><td colspan="4"><em>No logs found.</em></td></tr>
							<?php else : foreach ( $logs as $log ) : ?>
							<tr>
								<td><span class="cep-badge cep-badge--<?php echo esc_attr( $log['level'] ); ?>"><?php echo esc_html( strtoupper( $log['level'] ) ); ?></span></td>
								<td><?php echo esc_html( $log['context'] ); ?></td>
								<td>
									<?php echo esc_html( $log['message'] ); ?>
									<?php if ( $log['data'] ) : ?>
										<details><summary>Data</summary><pre><?php echo esc_html( $log['data'] ); ?></pre></details>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $log['created_at'] ); ?></td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<div class="cep-pagination">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-logs&paged=' . $i . ( $level ? '&level=' . $level : '' ) . ( $context ? '&context=' . $context : '' ) ) ); ?>" class="cep-page-link <?php echo $i === $page ? 'current' : ''; ?>"><?php echo esc_html( $i ); ?></a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function count_logs( string $level = '', string $context = '' ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cep_logs';
		$where = '1=1';
		$params = [];
		if ( $level ) {
			$where   .= ' AND level = %s';
			$params[] = $level;
		}
		if ( $context ) {
			$where   .= ' AND context = %s';
			$params[] = $context;
		}
		if ( $params ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", ...$params ) );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
	}
}
