<?php
/**
 * Admin Email Logs Page for Certificate Generator
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Render email logs page
function certificate_generator_email_logs_page() {
	// Handle bulk actions
	$deleted = false;
	if ( isset( $_POST['action'] ) && $_POST['action'] === 'delete_logs' && isset( $_POST['log_ids'] ) ) {
		check_admin_referer( 'bulk_delete_logs' );
		certificate_generator_delete_email_logs( array_map( 'absint', (array) wp_unslash( $_POST['log_ids'] ) ) );
		$deleted = true;
	}

	// Get filter parameters
	$current_page     = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
	$per_page         = 20;
	$post_type_filter = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
	$status_filter    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
	$search           = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$date_from        = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
	$date_to          = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';

	// Get logs
	$logs_data = certificate_generator_get_email_logs(
		array(
			'page'      => $current_page,
			'per_page'  => $per_page,
			'post_type' => $post_type_filter,
			'status'    => $status_filter,
			'search'    => $search,
			'date_from' => $date_from,
			'date_to'   => $date_to,
		)
	);

	$logs        = $logs_data['logs'];
	$total_pages = $logs_data['pages'];
	$total_count = $logs_data['total'];

	// Get statistics
	$stats = certificate_generator_get_email_stats();

	?>
	<div class="wrap">
		<?php
		$has_filters  = $post_type_filter || $status_filter || $search || $date_from || $date_to;
		$failed_count = class_exists( '\CertificateGenerator\Email\EmailResender' ) ? count( \CertificateGenerator\Email\EmailResender::still_failed() ) : 0;
		$header_btns  = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=certificate-email-logs&action=export' ), 'export_logs' ) ) . '" class="button">' . esc_html__( 'Export CSV', 'certificate-generator' ) . '</a>';
		if ( $failed_count ) {
			$header_btns .= ' <button type="button" class="button button-primary" id="cg-resend-all-failed" data-cg-confirm-label="' . esc_attr__( 'Resend', 'certificate-generator' ) . '" data-cg-confirm="' . esc_attr(
				/* translators: %d: number of failed emails */
				sprintf( _n( 'Queue %d failed email to send again? Anyone who has since received it is skipped.', 'Queue %d failed emails to send again? Anyone who has since received theirs is skipped.', $failed_count, 'certificate-generator' ), $failed_count )
			) . '">' . esc_html(
				/* translators: %d: number of failed emails */
				sprintf( __( 'Resend all failed (%d)', 'certificate-generator' ), $failed_count )
			) . '</button>';
		}
		certificate_generator_ui_page_header(
			__( 'Certificate Email Logs', 'certificate-generator' ),
			__( 'Every certificate email the plugin has tried to send, with its delivery result. Failed sends can be retried from here.', 'certificate-generator' ),
			$header_btns
		);
		if ( $deleted ) {
			certificate_generator_ui_notice( 'success', esc_html__( 'Selected logs deleted successfully.', 'certificate-generator' ) );
		}
		?>

		<div class="cg-stats">
			<?php
			certificate_generator_ui_stat( __( 'Total Sent', 'certificate-generator' ), $stats['total_sent'], '', 'good' );
			certificate_generator_ui_stat( __( 'Failed', 'certificate-generator' ), $stats['total_failed'], '', $stats['total_failed'] ? 'bad' : '' );
			certificate_generator_ui_stat( __( 'Success Rate', 'certificate-generator' ), $stats['success_rate'] . '%' );
			certificate_generator_ui_stat( __( 'Today', 'certificate-generator' ), $stats['today'] );
			certificate_generator_ui_stat( __( 'This Week', 'certificate-generator' ), $stats['this_week'] );
			certificate_generator_ui_stat( __( 'This Month', 'certificate-generator' ), $stats['this_month'] );
			?>
		</div>

		<!-- Filters -->
		<form method="get" class="cg-filters">
				<input type="hidden" name="page" value="certificate-email-logs">

				<select name="post_type">
					<option value=""><?php esc_html_e( 'All Post Types', 'certificate-generator' ); ?></option>
					<option value="students" <?php selected( $post_type_filter, 'students' ); ?>><?php esc_html_e( 'Students', 'certificate-generator' ); ?></option>
					<option value="teachers" <?php selected( $post_type_filter, 'teachers' ); ?>><?php esc_html_e( 'Teachers', 'certificate-generator' ); ?></option>
					<option value="schools" <?php selected( $post_type_filter, 'schools' ); ?>><?php esc_html_e( 'Schools', 'certificate-generator' ); ?></option>
				</select>

				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'certificate-generator' ); ?></option>
					<option value="sent" <?php selected( $status_filter, 'sent' ); ?>><?php esc_html_e( 'Sent', 'certificate-generator' ); ?></option>
					<option value="failed" <?php selected( $status_filter, 'failed' ); ?>><?php esc_html_e( 'Failed', 'certificate-generator' ); ?></option>
				</select>

				<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" placeholder="<?php esc_attr_e( 'From Date', 'certificate-generator' ); ?>">
				<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" placeholder="<?php esc_attr_e( 'To Date', 'certificate-generator' ); ?>">

				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email or name...', 'certificate-generator' ); ?>">

				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'certificate-generator' ); ?>">

				<?php if ( $has_filters ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=certificate-email-logs' ) ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'certificate-generator' ); ?></a>
				<?php endif; ?>
		</form>

		<!-- Logs Table -->
		<form method="post" data-cg-danger data-cg-confirm-label="<?php esc_attr_e( 'Delete', 'certificate-generator' ); ?>" data-cg-confirm="<?php esc_attr_e( 'Delete the selected email logs? This only removes the log entries; emails already sent are not affected.', 'certificate-generator' ); ?>">
			<?php wp_nonce_field( 'bulk_delete_logs' ); ?>
			<input type="hidden" name="action" value="delete_logs">

			<div class="cg-table-wrap">
			<table class="wp-list-table widefat fixed striped cg-table">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="cb-select-all-1">
						</td>
						<th><?php esc_html_e( 'Date', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Recipient', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Post Type', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Status', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'certificate-generator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $logs ) ) : ?>
						<?php
						echo wp_kses_post(
							$has_filters
							? certificate_generator_ui_empty_row( 8, __( 'No email logs match these filters.', 'certificate-generator' ), admin_url( 'admin.php?page=certificate-email-logs' ), __( 'Clear Filters', 'certificate-generator' ) )
							: certificate_generator_ui_empty_row( 8, __( 'No certificate emails sent yet. Logs appear here after the first send.', 'certificate-generator' ), admin_url( 'admin.php?page=certificate-bulk-send' ), __( 'Go to Bulk Send', 'certificate-generator' ) )
						);
						?>
					<?php else : ?>
						<?php foreach ( $logs as $log ) : ?>
							<tr>
								<th class="check-column">
									<input type="checkbox" name="log_ids[]" value="<?php echo esc_attr( $log->id ); ?>">
								</th>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->sent_at ) ) ); ?></td>
								<td>
									<strong><?php echo esc_html( $log->recipient_name ?: $log->recipient_email ); ?></strong>
									<?php if ( $log->recipient_name ) : ?>
										<br><small><?php echo esc_html( $log->recipient_email ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $log->certificate_type ); ?></td>
								<td>
									<?php echo '' !== (string) $log->post_type ? certificate_generator_ui_badge( ucfirst( (string) $log->post_type ), 'info' ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</td>
								<td><?php echo esc_html( $log->email_subject ); ?></td>
								<td>
									<?php if ( $log->status === 'sent' ) : ?>
										<?php echo certificate_generator_ui_badge( __( 'Sent', 'certificate-generator' ), 'good' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php else : ?>
										<?php echo certificate_generator_ui_badge( __( 'Failed', 'certificate-generator' ), 'bad', (string) $log->error_message ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
										<?php if ( $log->error_message ) : ?>
											<div class="cg-hint" title="<?php echo esc_attr( $log->error_message ); ?>"><?php echo esc_html( wp_trim_words( $log->error_message, 5 ) ); ?></div>
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $log->certificate_id . '&action=edit' ) ); ?>" class="button button-small"><?php esc_html_e( 'View Certificate', 'certificate-generator' ); ?></a>
									<?php if ( $log->status === 'failed' && (int) $log->certificate_id > 0 ) : ?>
										<button type="button" class="button button-small resend-email" data-log-id="<?php echo esc_attr( $log->id ); ?>"><?php esc_html_e( 'Resend', 'certificate-generator' ); ?></button>
									<?php elseif ( $log->status === 'failed' ) : ?>
										<span class="cg-hint"><?php esc_html_e( 'Resend from the student\'s row', 'certificate-generator' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			</div>

			<?php if ( ! empty( $logs ) ) : ?>
				<div class="tablenav bottom">
					<div class="alignleft actions">
						<input type="submit" class="button action" value="<?php esc_attr_e( 'Delete Selected', 'certificate-generator' ); ?>">
					</div>

					<?php
					// Pagination
					if ( $total_pages > 1 ) {
						$pagination_args = array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $current_page,
						);
						echo '<div class="tablenav-pages">';
						echo wp_kses_post( (string) paginate_links( $pagination_args ) );
						echo '</div>';
					}
					?>
				</div>
			<?php endif; ?>
		</form>
	</div>

	<script>
	jQuery(document).ready(function($) {
		// Select all checkbox functionality
		$('#cb-select-all-1').on('change', function() {
			$('input[name="log_ids[]"]').prop('checked', this.checked);
		});

		// Resend email functionality
		var resendNonce = '<?php echo esc_js( wp_create_nonce( 'cg_resend_email' ) ); ?>';

		// Resend all failed: CGUI's data-cg-confirm dialog runs first; this only fires once confirmed.
		$('#cg-resend-all-failed').on('click', function() {
			var button = this;
			CGUI.busy(button, true);
			$.post(ajaxurl, { action: 'certificate_generator_resend_all_failed', nonce: resendNonce })
				.done(function(response) {
					alert((response.data && response.data.message) || '<?php echo esc_js( __( 'Done.', 'certificate-generator' ) ); ?>');
					location.reload();
				})
				.fail(function() {
					CGUI.busy(button, false);
					alert('<?php echo esc_js( __( 'An error occurred. Please try again.', 'certificate-generator' ) ); ?>');
				});
		});

		$('.resend-email').on('click', function() {
			var button = $(this);

			CGUI.busy(button[0], true);

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'certificate_generator_resend_email_log',
					log_id: button.data('log-id'),
					nonce: resendNonce
				},
				success: function(response) {
					if (response.success) {
						CGUI.busy(button[0], false);
						button.prop('disabled', true).text('<?php echo esc_js( __( 'Sent!', 'certificate-generator' ) ); ?>');
						setTimeout(function() {
							location.reload();
						}, 1000);
					} else {
						CGUI.busy(button[0], false);
						alert('<?php esc_html_e( 'Failed to send email: ', 'certificate-generator' ); ?>' + (response.data.message || '<?php esc_html_e( 'Unknown error', 'certificate-generator' ); ?>'));
					}
				},
				error: function() {
					CGUI.busy(button[0], false);
					alert('<?php esc_html_e( 'An error occurred. Please try again.', 'certificate-generator' ); ?>');
				}
			});
		});
	});
	</script>
	<?php
}

// Handle CSV export
add_action( 'admin_init', 'certificate_generator_handle_export_logs' );
function certificate_generator_handle_export_logs() {
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'export' && isset( $_GET['page'] ) && $_GET['page'] === 'certificate-email-logs' ) {
		check_admin_referer( 'export_logs' );

		// Get all logs for export
		$logs_data = certificate_generator_get_email_logs(
			array(
				'per_page'  => -1,
				'post_type' => isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '',
				'status'    => isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '',
				'search'    => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
				'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
				'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			)
		);

		$logs = $logs_data['logs'];

		// Set headers for CSV download
		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="certificate-email-logs-' . gmdate( 'd-m-Y' ) . '.csv"' );

		// Create CSV output
		$output = fopen( 'php://output', 'w' );

		// CSV headers
		fputcsv(
			$output,
			array(
				'Date',
				'Recipient Name',
				'Recipient Email',
				'Certificate Type',
				'Post Type',
				'Subject',
				'Status',
				'Error Message',
			)
		);

		// CSV data
		foreach ( $logs as $log ) {
			fputcsv(
				$output,
				array(
					$log->sent_at,
					$log->recipient_name,
					$log->recipient_email,
					$log->certificate_type,
					$log->post_type,
					$log->email_subject,
					$log->status,
					$log->error_message,
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes a php://output / php://temp stream
		exit;
	}
}

// Delete email logs
function certificate_generator_delete_email_logs( $log_ids ) {
	global $wpdb;

	if ( empty( $log_ids ) || ! is_array( $log_ids ) ) {
		return false;
	}

	$table_name   = $wpdb->prefix . 'cert_email_logs';
	$log_ids      = array_map( 'intval', $log_ids );
	$placeholders = implode( ',', array_fill( 0, count( $log_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	return $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM $table_name WHERE id IN ($placeholders)",
			$log_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Resend / Resend all failed: AJAX handlers live in src/Email/EmailResender.php.
?>