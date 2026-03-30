<?php
namespace ContentEnginePro\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QueuePage {

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table    = $wpdb->prefix . 'cep_raw_content';
		$per_page = 20;
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = sanitize_key( $_GET['status'] ?? 'pending' );

		// Handle publish/reject actions
		if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cep_queue_action' ) ) {
			$id     = absint( $_GET['id'] );
			$action = sanitize_key( $_GET['action'] );
			if ( in_array( $action, [ 'reject', 'reset' ], true ) ) {
				$new_status = 'reject' === $action ? 'rejected' : 'pending';
				$wpdb->update( $table, [ 'status' => $new_status ], [ 'id' => $id ], [ '%s' ], [ '%d' ] );
				wp_redirect( admin_url( 'admin.php?page=cep-queue&status=' . $status . '&updated=1' ) );
				exit;
			}
		}

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT rc.*, s.name as source_name FROM {$table} rc LEFT JOIN {$wpdb->prefix}cep_sources s ON rc.source_id = s.id WHERE rc.status = %s ORDER BY rc.id DESC LIMIT %d OFFSET %d",
				$status, $per_page, $offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) );
		$pages = max( 1, (int) ceil( $total / $per_page ) );
		?>
		<div class="wrap cep-wrap">
			<div class="cep-page-header">
				<h1 class="cep-page-title"><span class="dashicons dashicons-list-view"></span> Content Queue</h1>
			</div>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Item updated.</p></div>
			<?php endif; ?>

			<div class="cep-queue-filters">
				<?php foreach ( ['pending','published','rejected','below_threshold'] as $s ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-queue&status=' . $s ) ); ?>" class="button <?php echo $status === $s ? 'button-primary' : 'button-secondary'; ?>"><?php echo esc_html( ucwords( str_replace( '_', ' ', $s ) ) ); ?></a>
				<?php endforeach; ?>
			</div>

			<div class="cep-card" style="margin-top:16px">
				<div class="cep-card-body cep-table-wrap">
					<table class="wp-list-table widefat fixed striped cep-table">
						<thead>
							<tr>
								<th>Title</th>
								<th>Source</th>
								<th>Category</th>
								<th>Score</th>
								<th>URL</th>
								<th>Date</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $items ) ) : ?>
								<tr><td colspan="7"><em>No items with status "<?php echo esc_html( $status ); ?>".</em></td></tr>
							<?php else : foreach ( $items as $item ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $item['title'] ); ?></strong></td>
								<td><?php echo esc_html( $item['source_name'] ?? '—' ); ?></td>
								<td><?php echo esc_html( $item['category'] ); ?></td>
								<td>
									<span class="cep-score cep-score--<?php echo esc_attr( (float) $item['score'] >= 50 ? 'high' : 'low' ); ?>">
										<?php echo esc_html( $item['score'] ); ?>
									</span>
								</td>
								<td><a href="<?php echo esc_url( $item['canonical_url'] ); ?>" target="_blank" rel="noopener">View</a></td>
								<td><?php echo esc_html( substr( $item['created_at'], 0, 10 ) ); ?></td>
								<td>
									<?php if ( 'pending' === $item['status'] ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-queue&action=reject&id=' . $item['id'] . '&status=pending&_wpnonce=' . wp_create_nonce( 'cep_queue_action' ) ) ); ?>" class="button button-small button-link-delete">Reject</a>
									<?php elseif ( 'rejected' === $item['status'] ) : ?>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-queue&action=reset&id=' . $item['id'] . '&status=rejected&_wpnonce=' . wp_create_nonce( 'cep_queue_action' ) ) ); ?>" class="button button-small">Restore</a>
									<?php endif; ?>
								</td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<?php if ( $pages > 1 ) : ?>
				<div class="cep-pagination">
					<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cep-queue&status=' . $status . '&paged=' . $i ) ); ?>" class="cep-page-link <?php echo $i === $page ? 'current' : ''; ?>"><?php echo esc_html( $i ); ?></a>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
