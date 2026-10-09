<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch a flattened entity row from a SQL table by wp_post_id.
 * Returns merged core + extra_fields as a flat array, or null if:
 *   - CustomTables class unavailable, table missing, or no row found.
 */
function certificate_generator_get_sql_row_for_post( int $post_id, string $entity ): ?array {
	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return null;
	}
	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	$table  = $tables->get_table( $entity );
	if ( empty( $table ) || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE wp_post_id = %d LIMIT 1", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	if ( empty( $row ) ) {
		return null;
	}
	$extra = ! empty( $row['extra_fields'] ) ? ( json_decode( $row['extra_fields'], true ) ?: array() ) : array();
	unset( $row['extra_fields'] );
	return array_merge( $row, $extra );
}

/**
 * Cached version — prevents N×columns queries per list view.
 * Uses array_key_exists so null (no SQL row) is also cached.
 */
function certificate_generator_get_sql_row_for_post_cached( int $post_id, string $entity ): ?array {
	static $cache = array();
	$key          = $entity . ':' . $post_id;
	if ( ! array_key_exists( $key, $cache ) ) {
		$cache[ $key ] = certificate_generator_get_sql_row_for_post( $post_id, $entity );
	}
	return $cache[ $key ];
}


// ── AJAX: toggle send_email (bulk-send opt-in/opt-out) per entity row ────────
add_action( 'wp_ajax_certificate_generator_toggle_bulk_send', 'certificate_generator_ajax_toggle_bulk_send' );
function certificate_generator_ajax_toggle_bulk_send(): void {
	check_ajax_referer( 'certificate_generator_toggle_bulk_send', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'certificate-generator' ) ) );
	}

	$post_id   = absint( $_POST['post_id'] ?? 0 );
	$post_type = sanitize_key( $_POST['post_type'] ?? '' );
	$new_val   = absint( $_POST['new_value'] ?? 1 ); // 0 or 1

	if ( ! $post_id || ! in_array( $post_type, array( 'students', 'teachers', 'schools' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'certificate-generator' ) ) );
	}

	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		wp_send_json_error( array( 'message' => __( 'Custom tables not available.', 'certificate-generator' ) ) );
	}

	global $wpdb;
	$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $post_type );
	if ( empty( $table ) ) {
		wp_send_json_error( array( 'message' => __( 'Table not found.', 'certificate-generator' ) ) );
	}

	$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table,
		array( 'send_email' => $new_val ? 1 : 0 ),
		array( 'wp_post_id' => $post_id ),
		array( '%d' ),
		array( '%d' )
	);

	if ( $updated === false ) {
		wp_send_json_error( array( 'message' => __( 'Database update failed.', 'certificate-generator' ) ) );
	}

	wp_send_json_success(
		array(
			'new_value' => $new_val ? 1 : 0,
			'label'     => $new_val ? __( 'Included', 'certificate-generator' ) : __( 'Excluded', 'certificate-generator' ),
		)
	);
}

// certificate_generator_send_single_email_ajax removed — CPT-based, broken for deregistered CPTs.
// Single-send entry point: cg_student_send_email in StudentsPage.php (SQL-backed).
if ( false ) {
	function certificate_generator_removed_placeholder() {
		// Check nonce for security
		check_ajax_referer( 'certificate_generator_send_email', 'nonce' );

		// Check if user has permission
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'certificate-generator' ) ) );
		}

		// Get post ID from request
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'certificate-generator' ) ) );
			wp_die();
		}

		// Check if post has an email address — SQL-first, CPT fallback
		$ajax_post_type = get_post_type( $post_id );
		$ajax_sql_row   = in_array( $ajax_post_type, array( 'students', 'teachers', 'schools' ), true )
		? certificate_generator_get_sql_row_for_post_cached( $post_id, $ajax_post_type )
		: null;
		$email          = ( $ajax_sql_row !== null && ! empty( $ajax_sql_row['email'] ) )
		? $ajax_sql_row['email']
		: get_post_meta( $post_id, 'email', true );
		if ( empty( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'No email address found for this entry.', 'certificate-generator' ) ) );
			wp_die();
		}

		// Check if certificate type exists — SQL-first, CPT fallback
		$certificate_type = ( $ajax_sql_row !== null && ! empty( $ajax_sql_row['certificate_type'] ) )
		? $ajax_sql_row['certificate_type']
		: get_post_meta( $post_id, 'certificate_type', true );
		if ( empty( $certificate_type ) ) {
			wp_send_json_error( array( 'message' => __( 'Certificate type is missing. Cannot generate certificate.', 'certificate-generator' ) ) );
			wp_die();
		}

		// Check if certificate template exists — SQL table only.
		$template = function_exists( 'certificate_generator_select_certificate_template' )
		? certificate_generator_select_certificate_template( $certificate_type, '', false )
		: null;

		if ( ! $template ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: certificate type */ __( 'No certificate template found for type: %s', 'certificate-generator' ), esc_html( $certificate_type ) ) ) );
			wp_die();
		}

		$template_id  = $template->id ?? null;
		$template_url = $template->template_url ?? '';

		if ( empty( $template_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Certificate template is missing a background image URL.', 'certificate-generator' ) ) );
			wp_die();
		}

		// Check if template has field positions configured
		$post_type = get_post_type( $post_id );
		$fields    = array();
		switch ( $post_type ) {
			case 'students':
				$fields = class_exists( 'CertificateGenerator_Field_Schema' )
				? CertificateGenerator_Field_Schema::get_all_renderable_fields( get_post_meta( $post_id, 'certificate_type', true ) )
				: array( 'student_name', 'school_name', 'issue_date' );
				break;
			case 'teachers':
				$fields = array( 'teacher_name', 'school_name', 'issue_date' );
				break;
			case 'schools':
				$fields = array( 'school_name', 'issue_date' );
				break;
		}

		// Debug log the fields and post type
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			certificate_generator_debug_log( "Post type: {$post_type}, Fields: " . wp_json_encode( $fields ) );
			certificate_generator_debug_log( "Template ID: {$template_id}, Template URL: {$template_url}" );
		}

		// Check if the template has at least the minimum number of field positions configured
		$field_count       = count( $fields );
		$missing_positions = array();
		$has_enough_fields = true;
		$field_config      = ! empty( $template->field_config ) ? json_decode( $template->field_config, true ) : array();

		// First check if we have enough field positions defined in the template
		for ( $i = 1; $i <= $field_count; $i++ ) {
			// Skip checking if field is not visible
			$is_visible = $field_config[ $i ]['visible'] ?? '1';
			if ( (string) $is_visible === '0' ) {
				continue;
			}

			$position_x = $field_config[ $i ]['position_x'] ?? '';
			$position_y = $field_config[ $i ]['position_y'] ?? '';

			// Debug log to check what's happening
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				certificate_generator_debug_log( "Checking field position for field_{$i} (X): " . ( $position_x ? $position_x : 'empty' ) );
				certificate_generator_debug_log( "Checking field position for field_{$i} (Y): " . ( $position_y ? $position_y : 'empty' ) );
			}

			if ( empty( $position_x ) || empty( $position_y ) ) {
				$has_enough_fields   = false;
				$missing_positions[] = $fields[ $i - 1 ]; // Map field number to field name
				// Don't break, collect all missing fields
			}
		}

		// If we don't have enough field positions, return an error with instructions
		if ( ! $has_enough_fields ) {
			certificate_generator_debug_log( "Certificate template is missing field positions. Template ID: {$template_id}" );

			$missing_fields = implode( ', ', $missing_positions );
			$error_message  = sprintf( /* translators: %s: comma-separated field names */ __( 'Certificate template is missing field positions for: %s', 'certificate-generator' ), esc_html( $missing_fields ) );
			$error_message .= '<br><br>' . __( 'To fix this issue:', 'certificate-generator' );
			$error_message .= '<ol>';
			$error_message .= '<li>' . __( 'Edit the certificate template (ID: ', 'certificate-generator' ) . esc_html( $template_id ) . ')</li>';
			$error_message .= '<li>' . __( 'Make sure all field positions (Field 1, Field 2, Field 3) have both X and Y coordinates defined', 'certificate-generator' ) . '</li>';
			$error_message .= '<li>' . __( 'Field 1 maps to student_name/teacher_name, Field 2 maps to school_name, and Field 3 maps to issue_date', 'certificate-generator' ) . '</li>';
			$error_message .= '</ol>';

			wp_send_json_error( array( 'message' => $error_message ) );
			wp_die();
		}

		// Send the email with the correct fields based on post type
		// We need to explicitly pass the fields to ensure the certificate is generated correctly
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			certificate_generator_debug_log( "Sending email for post ID: {$post_id} with fields: " . wp_json_encode( $fields ) );
		}

		// Check if certificate file already exists
		$certificate_path   = get_post_meta( $post_id, 'certificate_file_path', true );
		$certificate_exists = ! empty( $certificate_path ) && file_exists( $certificate_path );

		if ( ! $certificate_exists ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				certificate_generator_debug_log( 'Certificate file does not exist or path is empty. Generating new certificate.' );
			}
			// Try to generate the certificate first to ensure it exists
			$certificate_result = certificate_generator_generate_certificate_pdf_email( $post_id, $fields );
			if ( ! $certificate_result ) {
				wp_send_json_error( array( 'message' => __( 'Failed to generate certificate. Please check certificate template settings.', 'certificate-generator' ) ) );
				wp_die();
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			certificate_generator_debug_log( "Certificate file already exists at: {$certificate_path}" );
		}

		// Look up the wp_certificate_generator row by email.
		// certificate_generator_send_email() expects that table's id, not the WP post_id.
		$cg_table = $wpdb->prefix . 'certificate_generator';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$cg_id    = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM $cg_table WHERE email = %s ORDER BY id DESC LIMIT 1", $email )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $cg_id ) {
			wp_send_json_error( array( 'message' => __( 'Certificate record not found for this email. Try regenerating the certificate first.', 'certificate-generator' ) ) );
			wp_die();
		}

		// Now send the email
		$success = certificate_generator_send_email( $cg_id );

		if ( $success ) {
			wp_send_json_success( array( 'message' => __( 'Email sent successfully!', 'certificate-generator' ) ) );
			wp_die();
		} else {
			// Get certificate file path to check if generation succeeded
			$certificate_path = get_post_meta( $post_id, 'certificate_file_path', true );

			if ( empty( $certificate_path ) || ! file_exists( $certificate_path ) ) {
				// Check for specific issues with certificate generation
				if ( empty( $template_url ) ) {
					wp_send_json_error( array( 'message' => __( 'Certificate template URL is missing. Please check the template settings.', 'certificate-generator' ) ) );
					wp_die();
				}

				// Validate template URL if function exists
				if ( function_exists( 'certificate_generator_validate_template_url' ) ) {
					$validation_result = certificate_generator_validate_template_url( $template_url );
					if ( $validation_result !== true ) {
						// Extract the error message from the HTML
						$error_message = wp_strip_all_tags( $validation_result );
						// Clean up the error message
						$error_message = str_replace( 'Error:', '', $error_message );
						$error_message = trim( $error_message );
						wp_send_json_error( array( 'message' => __( 'Template URL error: ', 'certificate-generator' ) . esc_html( $error_message ) ) );
						wp_die();
					}
				}

				// Check for missing field positions using the correct meta key format
				$missing_positions = array();
				$field_count       = count( $fields );

				for ( $i = 1; $i <= $field_count; $i++ ) {
					$position_x = get_post_meta( $template_id, "field_{$i}_position_x", true );
					$position_y = get_post_meta( $template_id, "field_{$i}_position_y", true );

					if ( empty( $position_x ) || empty( $position_y ) ) {
						$missing_positions[] = $fields[ $i - 1 ]; // Map field number to field name
					}
				}

				if ( ! empty( $missing_positions ) ) {
					$missing_fields = implode( ', ', $missing_positions );

					$error_message  = sprintf( /* translators: %s: comma-separated field names */ __( 'Certificate template is missing field definitions for: %s', 'certificate-generator' ), esc_html( $missing_fields ) );
					$error_message .= '<br><br>' . __( 'To fix this issue:', 'certificate-generator' ) . '<ol>';
					$error_message .= '<li>' . __( 'Edit the certificate template (ID: ', 'certificate-generator' ) . esc_html( $template_id ) . ')</li>';
					$error_message .= '<li>' . __( 'Make sure all field positions (Field 1, Field 2, Field 3) have both X and Y coordinates defined', 'certificate-generator' ) . '</li>';
					$error_message .= '<li>' . __( 'Field 1 maps to student_name, Field 2 maps to school_name, and Field 3 maps to issue_date', 'certificate-generator' ) . '</li>';
					$error_message .= '</ol>';

					wp_send_json_error( array( 'message' => $error_message ) );
					wp_die();
				}

				// If no specific issue was found, return a general error
				wp_send_json_error( array( 'message' => __( 'Failed to generate certificate. Please check certificate template settings.', 'certificate-generator' ) ) );
				wp_die();
			} else {
				wp_send_json_error( array( 'message' => __( 'Failed to send email. Please check the email settings and try again.', 'certificate-generator' ) ) );
				wp_die();
			}
		}
		wp_die();
	}
} // end if(false) — dead CPT send-single-email block

// Add JavaScript to handle the email sending button
add_action( 'admin_footer', 'certificate_generator_admin_footer_js' );
function certificate_generator_admin_footer_js() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		return;
	}
	?>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		// Bulk-send opt-in/opt-out toggle
		$(document).on('click', '.cg-bulk-send-toggle', function() {
			var btn      = $(this);
			var postId   = btn.data('post-id');
			var postType = btn.data('post-type');
			var current  = parseInt(btn.data('current'), 10);
			var newVal   = current === 1 ? 0 : 1;

			btn.prop('disabled', true);

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: {
					action:    'certificate_generator_toggle_bulk_send',
					post_id:   postId,
					post_type: postType,
					new_value: newVal,
					nonce:     '<?php echo esc_html( wp_create_nonce( 'certificate_generator_toggle_bulk_send' ) ); ?>'
				},
				success: function(response) {
					if (response.success) {
						var d       = response.data;
						var color   = d.new_value ? '#46b450' : '#d63638';
						var title   = d.new_value
							? '<?php echo esc_js( __( 'Recipient will receive bulk emails. Click to exclude.', 'certificate-generator' ) ); ?>'
							: '<?php echo esc_js( __( 'Recipient is excluded from bulk emails. Click to include.', 'certificate-generator' ) ); ?>';
						btn.data('current', d.new_value)
							.attr('title', title)
							.css({'border-color': color, 'color': color})
							.text(d.label);
					} else {
						alert(response.data.message);
					}
					btn.prop('disabled', false);
				},
				error: function() {
					alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'certificate-generator' ) ); ?>');
					btn.prop('disabled', false);
				}
			});
		});

		// Individual email sending functionality
		$('.send-certificate-email').on('click', function() {
			var button = $(this);
			var postId = button.data('post-id');
			var statusSpan = $('#email-status-' + postId);

			button.prop('disabled', true);
			statusSpan.html('<span class="spinner is-active" style="float: none; margin: 0 0 0 8px;"></span>');

			$.ajax({
				url: ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'certificate_generator_send_single_email',
					post_id: postId,
					nonce: '<?php echo esc_html( wp_create_nonce( 'certificate_generator_send_email' ) ); ?>'
				},
				success: function(response) {
					button.prop('disabled', false);
					if (response.success) {
						statusSpan.html('<div style="color: green; margin-left: 8px;">' + response.data.message + '</div>');
					} else {
						var errorContainer = $('<div style="color: red; margin-left: 8px; max-width: 500px;"></div>');
						errorContainer.text(response.data.message);
						statusSpan.empty().append(errorContainer);
					}

					setTimeout(function() {
						statusSpan.html('');
					}, 5000);
				},
				error: function(jqXHR, textStatus, errorThrown) {
					button.prop('disabled', false);
					var errorMessage = '<?php echo esc_js( __( 'An error occurred. Please try again.', 'certificate-generator' ) ); ?>';
					if (textStatus === 'parsererror') {
						errorMessage = '<?php echo esc_js( __( 'Server returned an invalid response. Please check server logs for errors.', 'certificate-generator' ) ); ?>';
						if (jqXHR.responseText) {
							errorMessage += '\nRaw Response: ' + jqXHR.responseText.substring(0, 200);
						}
					} else if (jqXHR.status) {
						errorMessage += ' (' + jqXHR.status + ' ' + errorThrown + ')';
					}

					statusSpan.html('<span style="color: red; margin-left: 8px;">' + errorMessage + '</span>');

					setTimeout(function() {
						statusSpan.html('');
					}, 5000);
				}
			});
		});

		// Bulk email functionality
		var bulkEmailModal = $('#bulk-email-modal');
		var selectedPostIds = [];
		var currentPostType = '<?php echo esc_js( get_current_screen()->post_type ); ?>';

		// Intercept bulk action form submission
		$('#posts-filter').on('submit', function(e) {
			var bulkAction = $('#bulk-action-selector-top').val() || $('#bulk-action-selector-bottom').val();

			if (bulkAction === 'send_bulk_emails') {
				e.preventDefault();

				// Get selected post IDs
				selectedPostIds = [];
				$('input[name="post[]"]:checked').each(function() {
					selectedPostIds.push($(this).val());
				});

				if (selectedPostIds.length === 0) {
					alert('<?php echo esc_js( __( 'Please select at least one item.', 'certificate-generator' ) ); ?>');
					return false;
				}

				// Show modal
				bulkEmailModal.show();
				resetModal();
			}
		});

		// Modal functionality
		function resetModal() {
			$('.bulk-email-progress').hide();
			$('.bulk-email-results').hide();
			$('#bulk-email-confirm').prop('disabled', false).show();
			$('#bulk-email-cancel').text('<?php echo esc_js( __( 'Cancel', 'certificate-generator' ) ); ?>');
			$('.progress-fill').css('width', '0%');
			$('.progress-text').text('<?php echo esc_js( __( 'Processing...', 'certificate-generator' ) ); ?>');
		}

		// Close modal
		$('.bulk-email-modal-close, #bulk-email-cancel').on('click', function() {
			bulkEmailModal.hide();
		});

		// Close modal when clicking outside
		bulkEmailModal.on('click', function(e) {
			if (e.target === this) {
				bulkEmailModal.hide();
			}
		});

		// Confirm bulk email sending
		$('#bulk-email-confirm').on('click', function() {
			var skipAlreadySent = $('#skip-already-sent').is(':checked');

			// Show progress
			$('.bulk-email-progress').show();
			$('#bulk-email-confirm').prop('disabled', true);

			// Start progress animation
			$('.progress-fill').css('width', '10%');
			$('.progress-text').text('<?php echo esc_js( __( 'Initializing...', 'certificate-generator' ) ); ?>');

			// Send AJAX request
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'certificate_generator_bulk_email_progress',
					post_ids: selectedPostIds,
					post_type: currentPostType,
					skip_already_sent: skipAlreadySent,
					nonce: '<?php echo esc_html( wp_create_nonce( 'certificate_generator_bulk_email' ) ); ?>'
				},
				xhr: function() {
					var xhr = new window.XMLHttpRequest();
					// Simulate progress for better UX
					var progressInterval = setInterval(function() {
						var currentWidth = parseInt($('.progress-fill').css('width'));
						var containerWidth = $('.progress-bar').width();
						var currentPercent = (currentWidth / containerWidth) * 100;

						if (currentPercent < 90) {
							$('.progress-fill').css('width', (currentPercent + 5) + '%');
						}
					}, 500);

					xhr.progressInterval = progressInterval;
					return xhr;
				},
				success: function(response, textStatus, xhr) {
					// Clear progress interval
					if (xhr.progressInterval) {
						clearInterval(xhr.progressInterval);
					}

					if (response.success) {
						// Check if batched processing is required
						if (response.data.requires_batching) {
							$('.progress-text').text('<?php echo esc_js( __( 'Processing in batches...', 'certificate-generator' ) ); ?>');
							processBatchQueue(response.data.batch_id, response.data.total_posts);
						} else {
							// Small batch completed immediately
							$('.progress-fill').css('width', '100%');
							$('.progress-text').text('<?php echo esc_js( __( 'Queued Successfully!', 'certificate-generator' ) ); ?>');

							var message = '<?php echo esc_js( __( 'Successfully queued ', 'certificate-generator' ) ); ?>' + response.data.queued + ' <?php echo esc_js( __( 'emails for sending.', 'certificate-generator' ) ); ?>';
							if (response.data.skipped > 0) {
								message += '<br><em><?php echo esc_js( __( 'Skipped: ', 'certificate-generator' ) ); ?>' + response.data.skipped + ' <?php echo esc_js( __( 'already sent', 'certificate-generator' ) ); ?></em>';
							}
							message += '<br><br><strong><?php echo esc_js( __( 'What happens next:', 'certificate-generator' ) ); ?></strong><ul style="margin-top: 10px; text-align: left;">';
							message += '<li><?php echo esc_js( __( 'Emails will be sent in the background', 'certificate-generator' ) ); ?></li>';
							message += '<li><?php echo esc_js( __( 'You can close this window and continue working', 'certificate-generator' ) ); ?></li>';
							message += '</ul>';

							showResults(message, null, 'success');
						}
					} else {
						$('.progress-fill').css('width', '100%');
						$('.progress-text').text('<?php echo esc_js( __( 'Error', 'certificate-generator' ) ); ?>');
						showResults(response.data.message || '<?php echo esc_js( __( 'An error occurred', 'certificate-generator' ) ); ?>', null, 'error');
					}
				},
				error: function(xhr, textStatus, errorThrown) {
					// Clear progress interval
					if (xhr.progressInterval) {
						clearInterval(xhr.progressInterval);
					}

					$('.progress-fill').css('width', '100%');
					$('.progress-text').text('<?php echo esc_js( __( 'Error occurred', 'certificate-generator' ) ); ?>');

					var errorMessage = '<?php echo esc_js( __( 'An error occurred while sending bulk emails.', 'certificate-generator' ) ); ?>';
					if (xhr.status) {
						errorMessage += ' (' + xhr.status + ' ' + errorThrown + ')';
					}

					showResults(errorMessage, null, 'error');
				}
			});
		});

		// Process queue in batches for large datasets
		function processBatchQueue(batchId, totalPosts) {
			var offset = 0;
			var batchSize = 100;
			var totalQueued = 0;
			var totalSkipped = 0;

			function processNextBatch() {
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'certificate_generator_process_batch',
						batch_id: batchId,
						offset: offset,
						limit: batchSize,
						nonce: '<?php echo esc_html( wp_create_nonce( 'certificate_generator_bulk_email' ) ); ?>'
					},
					success: function(response) {
						if (response.success) {
							totalQueued += response.data.queued || 0;
							totalSkipped += response.data.skipped || 0;

							// Update progress
							var progress = response.data.progress || 0;
							$('.progress-fill').css('width', progress + '%');
							$('.progress-text').text('<?php echo esc_js( __( 'Queuing emails: ', 'certificate-generator' ) ); ?>' + response.data.processed + ' / ' + response.data.total);

							if (response.data.complete) {
								// All batches processed
								$('.progress-fill').css('width', '100%');
								$('.progress-text').text('<?php echo esc_js( __( 'All emails queued!', 'certificate-generator' ) ); ?>');

								var message = '<?php echo esc_js( __( 'Successfully queued ', 'certificate-generator' ) ); ?>' + totalQueued + ' <?php echo esc_js( __( 'emails for sending.', 'certificate-generator' ) ); ?>';
								if (totalSkipped > 0) {
									message += '<br><em><?php echo esc_js( __( 'Skipped: ', 'certificate-generator' ) ); ?>' + totalSkipped + ' <?php echo esc_js( __( 'already sent', 'certificate-generator' ) ); ?></em>';
								}
								message += '<br><br><strong><?php echo esc_js( __( 'What happens next:', 'certificate-generator' ) ); ?></strong><ul style="margin-top: 10px; text-align: left;">';
								message += '<li><?php echo esc_js( __( 'Emails will be sent in the background', 'certificate-generator' ) ); ?></li>';
								message += '<li><?php echo esc_js( __( 'You can close this window', 'certificate-generator' ) ); ?></li>';
								message += '<li><?php echo esc_js( __( 'Processing continues automatically', 'certificate-generator' ) ); ?></li>';
								message += '</ul>';

								showResults(message, null, 'success');
							} else {
								// Process next batch
								offset += batchSize;
								processNextBatch();
							}
						} else {
							$('.progress-text').text('<?php echo esc_js( __( 'Error occurred', 'certificate-generator' ) ); ?>');
							showResults(response.data.message || '<?php echo esc_js( __( 'Batch processing failed', 'certificate-generator' ) ); ?>', null, 'error');
						}
					},
					error: function() {
						$('.progress-text').text('<?php echo esc_js( __( 'Connection error', 'certificate-generator' ) ); ?>');
						showResults('<?php echo esc_js( __( 'Failed to process batch. Please try again.', 'certificate-generator' ) ); ?>', null, 'error');
					}
				});
			}

			// Start processing
			processNextBatch();
		}

		function showResults(message, results, type) {
			var resultsDiv = $('.bulk-email-results');
			var resultType = 'success';

			if (type === 'error') {
				resultType = 'error';
			} else if (results && results.failure > 0 && results.success === 0) {
				resultType = 'error';
			} else if (results && results.failure > 0) {
				resultType = 'warning';
			}

			resultsDiv.removeClass('success warning error').addClass(resultType);
			resultsDiv.html('<strong>' + message + '</strong>');

			if (results && results.errors && results.errors.length > 0) {
				var errorList = '<ul style="margin-top: 10px; margin-bottom: 0;">';
				results.errors.slice(0, 10).forEach(function(error) { // Show only first 10 errors
					errorList += '<li>' + error + '</li>';
				});
				if (results.errors.length > 10) {
					errorList += '<li><em>... and ' + (results.errors.length - 10) + ' more errors</em></li>';
				}
				errorList += '</ul>';
				resultsDiv.append(errorList);
			}

			resultsDiv.show();
			$('#bulk-email-confirm').hide();
			$('#bulk-email-cancel').text('<?php echo esc_js( __( 'Close', 'certificate-generator' ) ); ?>');

			// Auto-reload page after 3 seconds for successful operations
			if (resultType === 'success' || resultType === 'warning') {
				setTimeout(function() {
					window.location.reload();
				}, 3000);
			}
		}
	});
	</script>
	<?php
}

// CPT column/sort hooks for students/teachers/schools removed — those CPTs are deregistered.
// SQL-backed admin pages (StudentsPage, TeachersPage, SchoolsPage) handle their own columns.

// Add bulk actions for sending emails
function certificate_generator_add_bulk_actions( $bulk_actions ) {
	$bulk_actions['send_bulk_emails'] = __( 'Send Bulk Emails', 'certificate-generator' );
	return $bulk_actions;
}

// Handle bulk actions
function certificate_generator_handle_bulk_actions( $redirect_to, $action, $post_ids ) {
	if ( $action !== 'send_bulk_emails' ) {
		return $redirect_to;
	}

	if ( empty( $post_ids ) ) {
		return $redirect_to;
	}

	// Get the post type from the current screen
	$screen    = get_current_screen();
	$post_type = $screen->post_type;

	// Validate post type
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		$redirect_to = add_query_arg( 'bulk_email_error', 'invalid_post_type', $redirect_to );
		return $redirect_to;
	}

	// Process bulk emails
	$results = certificate_generator_send_bulk_emails( $post_type, true, $post_ids );

	// Add results to redirect URL
	$redirect_to = add_query_arg(
		array(
			'bulk_emails_sent'    => $results['success'],
			'bulk_emails_failed'  => $results['failure'],
			'bulk_emails_skipped' => $results['skipped'],
			'bulk_emails_total'   => $results['total'],
		),
		$redirect_to
	);

	return $redirect_to;
}

// Display bulk action notices
function certificate_generator_bulk_action_notices() {
	if ( ! empty( $_REQUEST['bulk_emails_sent'] ) || ! empty( $_REQUEST['bulk_emails_failed'] ) || ! empty( $_REQUEST['bulk_emails_skipped'] ) ) {
		$sent    = isset( $_REQUEST['bulk_emails_sent'] ) ? intval( $_REQUEST['bulk_emails_sent'] ) : 0;
		$failed  = isset( $_REQUEST['bulk_emails_failed'] ) ? intval( $_REQUEST['bulk_emails_failed'] ) : 0;
		$skipped = isset( $_REQUEST['bulk_emails_skipped'] ) ? intval( $_REQUEST['bulk_emails_skipped'] ) : 0;
		$total   = isset( $_REQUEST['bulk_emails_total'] ) ? intval( $_REQUEST['bulk_emails_total'] ) : 0;

		$message = sprintf(
			/* translators: 1: emails sent, 2: emails failed, 3: emails skipped, 4: total recipients */ __( 'Bulk email results: %1$d sent, %2$d failed, %3$d skipped out of %4$d total.', 'certificate-generator' ),
			$sent,
			$failed,
			$skipped,
			$total
		);

		$notice_type = 'success';
		if ( $failed > 0 && $sent === 0 ) {
			$notice_type = 'error';
		} elseif ( $failed > 0 ) {
			$notice_type = 'warning';
		}

		certificate_generator_ui_notice( $notice_type, esc_html( $message ) );
	}

	if ( ! empty( $_REQUEST['bulk_email_error'] ) ) {
		$error   = sanitize_text_field( wp_unslash( $_REQUEST['bulk_email_error'] ) );
		$message = __( 'Bulk email error: Invalid post type.', 'certificate-generator' );
		certificate_generator_ui_notice( 'error', esc_html( $message ) );
	}
}

// CPT bulk-action hooks removed — CPTs are deregistered.

// Add admin notices
add_action( 'admin_notices', 'certificate_generator_bulk_action_notices' );

// AJAX handler for bulk email progress
add_action( 'wp_ajax_certificate_generator_bulk_email_progress', 'certificate_generator_bulk_email_progress_ajax' );
function certificate_generator_bulk_email_progress_ajax() {
	// Check nonce for security
	check_ajax_referer( 'certificate_generator_bulk_email', 'nonce' );

	// Check if user has permission
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'certificate-generator' ) ) );
		wp_die();
	}

	// Get parameters from request
	$post_ids          = isset( $_POST['post_ids'] ) ? array_map( 'intval', $_POST['post_ids'] ) : array();
	$post_type         = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';
	$skip_already_sent = isset( $_POST['skip_already_sent'] ) ? (bool) $_POST['skip_already_sent'] : true;

	if ( empty( $post_ids ) || empty( $post_type ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid parameters.', 'certificate-generator' ) ) );
		wp_die();
	}

	// Validate post type
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		wp_send_json_error( array( 'message' => __( 'Invalid post type.', 'certificate-generator' ) ) );
		wp_die();
	}

	// Queue bulk emails instead of sending synchronously
	$queue_result = certificate_generator_queue_bulk_emails_async( $post_type, $skip_already_sent, $post_ids );

	if ( $queue_result['success'] ) {
		wp_send_json_success(
			array(
				'message'  => sprintf(
					/* translators: %d: number of queued emails */ __( '%d emails have been queued for sending. You can close this window and the emails will be sent in the background.', 'certificate-generator' ),
					$queue_result['queued']
				),
				'queued'   => $queue_result['queued'],
				'skipped'  => $queue_result['skipped'],
				'batch_id' => $queue_result['batch_id'],
			)
		);
	} else {
		wp_send_json_error(
			array(
				'message' => __( 'Failed to queue emails. Please try again.', 'certificate-generator' ),
			)
		);
	}

	wp_die();
}

// Add enhanced bulk email modal and JavaScript
add_action( 'admin_footer', 'certificate_generator_bulk_email_modal' );
function certificate_generator_bulk_email_modal() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		return;
	}
	?>
	<!-- Bulk Email Modal -->
	<div id="bulk-email-modal" style="display: none;">
		<div class="bulk-email-modal-content">
			<div class="bulk-email-modal-header">
				<h3><?php esc_html_e( 'Send Bulk Emails', 'certificate-generator' ); ?></h3>
				<span class="bulk-email-modal-close">&times;</span>
			</div>
			<div class="bulk-email-modal-body">
				<p><?php esc_html_e( 'Send certificate emails to selected entries?', 'certificate-generator' ); ?></p>
				<div class="bulk-email-options">
					<label>
						<input type="checkbox" id="skip-already-sent" checked>
						<?php esc_html_e( 'Skip entries that have already been emailed', 'certificate-generator' ); ?>
					</label>
				</div>
				<div class="bulk-email-progress" style="display: none;">
					<div class="progress-bar">
						<div class="progress-fill"></div>
					</div>
					<div class="progress-text"><?php esc_html_e( 'Processing...', 'certificate-generator' ); ?></div>
				</div>
				<div class="bulk-email-results" style="display: none;"></div>
			</div>
			<div class="bulk-email-modal-footer">
				<button type="button" class="button button-secondary" id="bulk-email-cancel"><?php esc_html_e( 'Cancel', 'certificate-generator' ); ?></button>
				<button type="button" class="button button-primary" id="bulk-email-confirm"><?php esc_html_e( 'Send Emails', 'certificate-generator' ); ?></button>
			</div>
		</div>
	</div>

	<style>
	#bulk-email-modal {
		position: fixed;
		z-index: 999999;
		left: 0;
		top: 0;
		width: 100%;
		height: 100%;
		background-color: rgba(0,0,0,0.5);
	}

	.bulk-email-modal-content {
		background-color: #fefefe;
		margin: 10% auto;
		padding: 0;
		border: 1px solid #888;
		width: 500px;
		max-width: 90%;
		border-radius: 4px;
		box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
	}

	.bulk-email-modal-header {
		background: #f1f1f1;
		padding: 15px 20px;
		border-bottom: 1px solid #ddd;
		display: flex;
		justify-content: space-between;
		align-items: center;
	}

	.bulk-email-modal-header h3 {
		margin: 0;
		font-size: 16px;
	}

	.bulk-email-modal-close {
		color: #aaa;
		font-size: 28px;
		font-weight: bold;
		cursor: pointer;
		line-height: 1;
	}

	.bulk-email-modal-close:hover {
		color: #000;
	}

	.bulk-email-modal-body {
		padding: 20px;
	}

	.bulk-email-options {
		margin: 15px 0;
	}

	.bulk-email-options label {
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.bulk-email-progress {
		margin: 15px 0;
	}

	.progress-bar {
		width: 100%;
		height: 20px;
		background-color: #f0f0f0;
		border-radius: 10px;
		overflow: hidden;
		margin-bottom: 10px;
	}

	.progress-fill {
		height: 100%;
		background-color: #0073aa;
		width: 0%;
		transition: width 0.3s ease;
		border-radius: 10px;
	}

	.progress-text {
		text-align: center;
		font-size: 14px;
		color: #666;
	}

	.bulk-email-results {
		margin: 15px 0;
		padding: 10px;
		border-radius: 4px;
	}

	.bulk-email-results.success {
		background-color: #d4edda;
		border: 1px solid #c3e6cb;
		color: #155724;
	}

	.bulk-email-results.warning {
		background-color: #fff3cd;
		border: 1px solid #ffeaa7;
		color: #856404;
	}

	.bulk-email-results.error {
		background-color: #f8d7da;
		border: 1px solid #f5c6cb;
		color: #721c24;
	}

	.bulk-email-modal-footer {
		background: #f1f1f1;
		padding: 15px 20px;
		border-top: 1px solid #ddd;
		display: flex;
		justify-content: flex-end;
		gap: 10px;
	}
	</style>
	<?php
}

/**
 * Add custom filter dropdowns to list tables
 */
function certificate_generator_add_list_filters( $post_type ) {
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		return;
	}

	// Get current filter values
	$filter_school       = isset( $_GET['filter_school'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_school'] ) ) : '';
	$filter_cert_type    = isset( $_GET['filter_cert_type'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_cert_type'] ) ) : '';
	$filter_email_status = isset( $_GET['filter_email_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_email_status'] ) ) : '';

	// Get unique schools
	$schools = certificate_generator_get_unique_schools( $post_type );

	// Get unique certificate types
	$cert_types = certificate_generator_get_unique_certificate_types( $post_type );

	?>
	<div class="cert-list-filters">
		<!-- School Name Filter -->
		<div class="cert-list-filter-item">
			<label for="filter_school"><?php esc_html_e( 'School', 'certificate-generator' ); ?></label>
			<select name="filter_school" id="filter_school">
				<option value=""><?php esc_html_e( 'All Schools', 'certificate-generator' ); ?></option>
				<?php foreach ( $schools as $school ) : ?>
					<option value="<?php echo esc_attr( $school ); ?>" <?php selected( $filter_school, $school ); ?>>
						<?php echo esc_html( $school ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<!-- Certificate Type Filter -->
		<div class="cert-list-filter-item">
			<label for="filter_cert_type"><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></label>
			<select name="filter_cert_type" id="filter_cert_type">
				<option value=""><?php esc_html_e( 'All Types', 'certificate-generator' ); ?></option>
				<?php foreach ( $cert_types as $type ) : ?>
					<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filter_cert_type, $type ); ?>>
						<?php echo esc_html( $type ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<!-- Email Status Filter -->
		<div class="cert-list-filter-item">
			<label for="filter_email_status"><?php esc_html_e( 'Email Status', 'certificate-generator' ); ?></label>
			<select name="filter_email_status" id="filter_email_status">
				<option value=""><?php esc_html_e( 'All Statuses', 'certificate-generator' ); ?></option>
				<option value="has_email" <?php selected( $filter_email_status, 'has_email' ); ?>>
					<?php esc_html_e( 'Has Email', 'certificate-generator' ); ?>
				</option>
				<option value="no_email" <?php selected( $filter_email_status, 'no_email' ); ?>>
					<?php esc_html_e( 'No Email', 'certificate-generator' ); ?>
				</option>
				<option value="sent" <?php selected( $filter_email_status, 'sent' ); ?>>
					<?php esc_html_e( 'Email Sent', 'certificate-generator' ); ?>
				</option>
				<option value="not_sent" <?php selected( $filter_email_status, 'not_sent' ); ?>>
					<?php esc_html_e( 'Not Sent', 'certificate-generator' ); ?>
				</option>
			</select>
		</div>
	</div>
	<?php
}
add_action( 'restrict_manage_posts', 'certificate_generator_add_list_filters' );

/**
 * Apply custom filters to query
 */
function certificate_generator_apply_list_filters( $query ) {
	global $pagenow, $wpdb;

	if ( ! is_admin() || $pagenow != 'edit.php' || ! $query->is_main_query() ) {
		return;
	}

	$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		return;
	}

	$meta_query = $query->get( 'meta_query' ) ?: array();

	// School filter
	if ( ! empty( $_GET['filter_school'] ) ) {
		$meta_query[] = array(
			'key'     => 'school_name',
			'value'   => sanitize_text_field( wp_unslash( $_GET['filter_school'] ) ),
			'compare' => '=',
		);
	}

	// Certificate type filter
	if ( ! empty( $_GET['filter_cert_type'] ) ) {
		$meta_query[] = array(
			'key'     => 'certificate_type',
			'value'   => sanitize_text_field( wp_unslash( $_GET['filter_cert_type'] ) ),
			'compare' => '=',
		);
	}

	// Email status filter
	if ( ! empty( $_GET['filter_email_status'] ) ) {
		$email_status = sanitize_text_field( wp_unslash( $_GET['filter_email_status'] ) );

		switch ( $email_status ) {
			case 'has_email':
				$meta_query[] = array(
					'key'     => 'email',
					'value'   => '',
					'compare' => '!=',
				);
				break;

			case 'no_email':
				$meta_query[] = array(
					'relation' => 'OR',
					array(
						'key'     => 'email',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'email',
						'value'   => '',
						'compare' => '=',
					),
				);
				break;

			case 'sent':
			case 'not_sent':
				// For sent/not sent, we need to join with email logs
				// This is more complex and requires custom SQL
				add_filter( 'posts_join', 'certificate_generator_filter_posts_join', 10, 2 );
				add_filter( 'posts_where', 'certificate_generator_filter_posts_where', 10, 2 );
				add_filter( 'posts_groupby', 'certificate_generator_filter_posts_groupby', 10, 2 );
				break;
		}
	}

	if ( ! empty( $meta_query ) ) {
		$query->set( 'meta_query', $meta_query );
	}
}
add_action( 'pre_get_posts', 'certificate_generator_apply_list_filters' );

/**
 * Join email logs table for sent/not sent filtering
 */
function certificate_generator_filter_posts_join( $join, $query ) {
	global $wpdb;

	if ( ! is_admin() || ! $query->is_main_query() ) {
		return $join;
	}

	if ( ! empty( $_GET['filter_email_status'] ) && in_array( $_GET['filter_email_status'], array( 'sent', 'not_sent' ) ) ) {
		$table_name = $wpdb->prefix . 'cert_email_logs';
		$join      .= " LEFT JOIN (
            SELECT certificate_id, MAX(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as is_sent
            FROM $table_name
            GROUP BY certificate_id
        ) el ON {$wpdb->posts}.ID = el.certificate_id";
	}

	return $join;
}

/**
 * Add WHERE clause for sent/not sent filtering
 */
function certificate_generator_filter_posts_where( $where, $query ) {
	global $wpdb;

	if ( ! is_admin() || ! $query->is_main_query() ) {
		return $where;
	}

	if ( ! empty( $_GET['filter_email_status'] ) ) {
		$email_status = sanitize_text_field( wp_unslash( $_GET['filter_email_status'] ) );

		if ( $email_status === 'sent' ) {
			$where .= ' AND el.is_sent = 1';
		} elseif ( $email_status === 'not_sent' ) {
			$where .= ' AND (el.is_sent IS NULL OR el.is_sent = 0)';
		}
	}

	return $where;
}

/**
 * Add GROUP BY for email log joins
 */
function certificate_generator_filter_posts_groupby( $groupby, $query ) {
	global $wpdb;

	if ( ! is_admin() || ! $query->is_main_query() ) {
		return $groupby;
	}

	if ( ! empty( $_GET['filter_email_status'] ) && in_array( $_GET['filter_email_status'], array( 'sent', 'not_sent' ) ) ) {
		if ( empty( $groupby ) ) {
			$groupby = "{$wpdb->posts}.ID";
		}
	}

	return $groupby;
}

/**
 * Add "Send to Filtered List" button above the table
 */
function certificate_generator_add_send_filtered_button( $which ) {
	global $typenow;

	if ( ! in_array( $typenow, array( 'students', 'teachers', 'schools' ) ) ) {
		return;
	}

	if ( $which !== 'top' ) {
		return;
	}

	// Count filtered posts
	$args = array(
		'post_type'      => $typenow,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	);

	// Apply same filters
	if ( ! empty( $_GET['filter_school'] ) ) {
		$args['meta_query'][] = array(
			'key'     => 'school_name',
			'value'   => sanitize_text_field( wp_unslash( $_GET['filter_school'] ) ),
			'compare' => '=',
		);
	}

	if ( ! empty( $_GET['filter_cert_type'] ) ) {
		$args['meta_query'][] = array(
			'key'     => 'certificate_type',
			'value'   => sanitize_text_field( wp_unslash( $_GET['filter_cert_type'] ) ),
			'compare' => '=',
		);
	}

	$query          = new WP_Query( $args );
	$filtered_count = $query->found_posts;

	if ( $filtered_count > 0 ) :
		?>
	<button type="button" class="cert-bulk-send-filtered" id="cert-send-filtered-list"
			data-post-type="<?php echo esc_attr( $typenow ); ?>"
			data-count="<?php echo esc_attr( $filtered_count ); ?>">
		📧 <?php printf( /* translators: %d: number of records in the filtered list */ esc_html__( 'Send to Filtered List (%d)', 'certificate-generator' ), (int) $filtered_count ); ?>
	</button>

	<script type="text/javascript">
	jQuery(document).ready(function($) {
		$('#cert-send-filtered-list').on('click', function() {
			var postType = $(this).data('post-type');
			var count = $(this).data('count');

			if (!confirm('Send emails to ' + count + ' filtered ' + postType + '?')) {
				return;
			}

			// Build filter parameters from URL
			var filters = {
				post_types: [postType],
				schools: [],
				certificate_types: [],
				email_status: ['not_sent'],
				skip_already_sent: true
			};

			// Get URL parameters
			var urlParams = new URLSearchParams(window.location.search);

			if (urlParams.get('filter_school')) {
				filters.schools.push(urlParams.get('filter_school'));
			}

			if (urlParams.get('filter_cert_type')) {
				filters.certificate_types.push(urlParams.get('filter_cert_type'));
			}

			if (urlParams.get('filter_email_status')) {
				filters.email_status = [urlParams.get('filter_email_status')];
			}

			// Send AJAX request
			$.ajax({
				url: ajaxurl,
				type: 'POST',
				data: {
					action: 'certificate_generator_send_to_filtered',
					nonce: '<?php echo esc_html( wp_create_nonce( 'cert_filter_nonce' ) ); ?>',
					filters: filters
				},
				success: function(response) {
					if (response.success) {
						alert('Success! Queued ' + response.data.queued + ' certificates for sending.');
						location.reload();
					} else {
						alert('Error: ' + response.data.message);
					}
				},
				error: function() {
					alert('Failed to start bulk send. Please try again.');
				}
			});
		});
	});
	</script>
		<?php
	endif;
}
add_action( 'manage_posts_extra_tablenav', 'certificate_generator_add_send_filtered_button' );

/**
 * Add Bulk Edit Fields
 *
 * @param string $column_name Column name
 * @param string $post_type Post type
 */
function certificate_generator_bulk_edit_fields( $column_name, $post_type ) {
	// Only run for our custom post types
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		return;
	}

	// Only output once (hook fires for every column)
	if ( $column_name !== 'email' ) {
		return;
	}
	?>
	<fieldset class="inline-edit-col-right inline-edit-book">
		<div class="inline-edit-col">
			<h4><?php esc_html_e( 'Certificate Data', 'certificate-generator' ); ?></h4>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Email', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="text" name="certificate_bulk_edit[email]" class="text" placeholder="<?php esc_attr_e( '— No Change —', 'certificate-generator' ); ?>">
					</span>
				</label>
			</div>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'School Name', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<?php
						$schools = function_exists( 'certificate_generator_get_unique_schools' )
							? certificate_generator_get_unique_schools( $post_type )
							: array();
						?>
						<select name="certificate_bulk_edit[school_name]" class="certificate-bulk-school-select">
							<option value=""><?php esc_html_e( '— No Change —', 'certificate-generator' ); ?></option>
							<?php foreach ( $schools as $school ) : ?>
								<option value="<?php echo esc_attr( $school ); ?>"><?php echo esc_html( $school ); ?></option>
							<?php endforeach; ?>
							<option value="__new__"><?php esc_html_e( 'Set New...', 'certificate-generator' ); ?></option>
						</select>
						<input type="text" name="certificate_bulk_edit[new_school_name]" class="text mt-1 certificate-bulk-new-school" style="display:none; margin-top: 5px;" placeholder="<?php esc_attr_e( 'Enter new school name', 'certificate-generator' ); ?>">
					</span>
				</label>
			</div>

			<?php if ( $post_type === 'students' || $post_type === 'teachers' ) : ?>
			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Teacher Name', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="text" name="certificate_bulk_edit[teacher_name]" class="text" placeholder="<?php esc_attr_e( '— No Change —', 'certificate-generator' ); ?>">
					</span>
				</label>
			</div>
			<?php endif; ?>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Issue Date', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="date" name="certificate_bulk_edit[issue_date]" class="text">
						<span class="description" style="display:block; margin-top:2px; font-size:10px; color:#666;"><?php esc_html_e( 'Leave empty to keep current', 'certificate-generator' ); ?></span>
					</span>
				</label>
			</div>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Cert Type', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<?php
						$types = function_exists( 'certificate_generator_get_unique_certificate_types' )
							? certificate_generator_get_unique_certificate_types( $post_type )
							: array();
						?>
						<select name="certificate_bulk_edit[certificate_type]">
							<option value=""><?php esc_html_e( '— No Change —', 'certificate-generator' ); ?></option>
							<?php foreach ( $types as $type ) : ?>
								<option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $type ); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
				</label>
			</div>
		</div>
	</fieldset>
	<script type="text/javascript">
	jQuery(document).ready(function($) {
		// Toggle new school input
		$('.certificate-bulk-school-select').on('change', function() {
			var val = $(this).val();
			var input = $(this).siblings('.certificate-bulk-new-school');
			if (val === '__new__') {
				input.show();
			} else {
				input.hide().val(''); // hide and clear
			}
		});
	});
	</script>
	<?php
}
add_action( 'bulk_edit_custom_box', 'certificate_generator_bulk_edit_fields', 10, 2 );

/**
 * Add Bulk Edit fields for Certificates CPT
 */
function certificate_generator_bulk_edit_fields_certificates( $column_name, $post_type ) {
	if ( $post_type !== 'certificates' || $column_name !== 'title' ) {
		return;
	}

	// Get fonts if available
	$font_options = array();
	if ( class_exists( 'CertificateGenerator_FontManager' ) ) {
		$font_manager = CertificateGenerator_FontManager::getInstance();
		$font_options = $font_manager->get_font_options();
	}
	?>
	<fieldset class="inline-edit-col-right inline-edit-book">
		<div class="inline-edit-col">
			<h4><?php esc_html_e( 'Certificate Configuration', 'certificate-generator' ); ?></h4>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Cert Type', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="text" name="certificate_bulk_edit[certificate_type]" class="text" placeholder="<?php esc_attr_e( '— No Change —', 'certificate-generator' ); ?>">
					</span>
				</label>
			</div>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Event Date', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="date" name="certificate_bulk_edit[event_date]" class="text">
						<span class="description" style="display:block; margin-top:2px; font-size:10px; color:#666;"><?php esc_html_e( 'Leave empty to keep current', 'certificate-generator' ); ?></span>
					</span>
				</label>
			</div>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Orientation', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<select name="certificate_bulk_edit[template_orientation]">
							<option value=""><?php esc_html_e( '— No Change —', 'certificate-generator' ); ?></option>
							<option value="landscape"><?php esc_html_e( 'Landscape', 'certificate-generator' ); ?></option>
							<option value="portrait"><?php esc_html_e( 'Portrait', 'certificate-generator' ); ?></option>
						</select>
					</span>
				</label>
			</div>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Font Size', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="number" name="certificate_bulk_edit[font_size]" class="text" min="6" max="72" placeholder="<?php esc_attr_e( '— No Change —', 'certificate-generator' ); ?>">
					</span>
				</label>
			</div>

			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Font Color', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<input type="color" name="certificate_bulk_edit[font_color]" class="text" style="height: 25px;">
						<span class="description"><?php esc_html_e( 'Select to change', 'certificate-generator' ); ?></span>
					</span>
				</label>
			</div>

			<?php if ( ! empty( $font_options ) ) : ?>
			<div class="inline-edit-group">
				<label class="alignleft">
					<span class="title"><?php esc_html_e( 'Font Style', 'certificate-generator' ); ?></span>
					<span class="input-text-wrap">
						<select name="certificate_bulk_edit[font_style]">
							<option value=""><?php esc_html_e( '— No Change —', 'certificate-generator' ); ?></option>
							<?php foreach ( $font_options as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</span>
				</label>
			</div>
			<?php endif; ?>
		</div>
	</fieldset>
	<?php
}
add_action( 'bulk_edit_custom_box', 'certificate_generator_bulk_edit_fields_certificates', 10, 2 );

/**
 * Save Bulk Edit Fields
 *
 * @param int $post_id Post ID
 */
function certificate_generator_save_bulk_edit_fields( $post_id ) {
	// Check if we are performing a bulk edit
	if ( ! isset( $_REQUEST['certificate_bulk_edit'] ) || ! is_array( $_REQUEST['certificate_bulk_edit'] ) ) {
		return;
	}

	// Verify permissions (usually handled by bulk_edit_posts but good practice)
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$data      = wp_unslash( $_REQUEST['certificate_bulk_edit'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below
	$post_type = get_post_type( $post_id );

	if ( in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		// 1. Email
		if ( ! empty( $data['email'] ) ) {
			update_post_meta( $post_id, 'email', sanitize_email( $data['email'] ) );
		}

		// 2. School Name
		if ( ! empty( $data['school_name'] ) ) {
			$school_name = sanitize_text_field( $data['school_name'] );
			if ( $school_name === '__new__' && ! empty( $data['new_school_name'] ) ) {
				$school_name = sanitize_text_field( $data['new_school_name'] );
			}

			if ( $school_name !== '__new__' ) {
				update_post_meta( $post_id, 'school_name', $school_name );
			}
		} elseif ( isset( $data['new_school_name'] ) && ! empty( $data['new_school_name'] ) ) {
			// Handle case where only new_school_name is sent (should cover above logic but safety net)
			update_post_meta( $post_id, 'school_name', sanitize_text_field( $data['new_school_name'] ) );
		}

		// 3. Teacher Name
		if ( ! empty( $data['teacher_name'] ) ) {
			update_post_meta( $post_id, 'teacher_name', sanitize_text_field( $data['teacher_name'] ) );
		}

		// 4. Issue Date
		if ( ! empty( $data['issue_date'] ) ) {
			update_post_meta( $post_id, 'issue_date', sanitize_text_field( $data['issue_date'] ) );
		}

		// 5. Certificate Type
		if ( ! empty( $data['certificate_type'] ) ) {
			update_post_meta( $post_id, 'certificate_type', sanitize_text_field( $data['certificate_type'] ) );
		}
	} elseif ( $post_type === 'certificates' ) {
		// 1. Certificate Type
		if ( ! empty( $data['certificate_type'] ) ) {
			update_post_meta( $post_id, 'certificate_type', sanitize_text_field( $data['certificate_type'] ) );
		}

		// 2. Orientation
		if ( ! empty( $data['template_orientation'] ) ) {
			update_post_meta( $post_id, 'template_orientation', sanitize_text_field( $data['template_orientation'] ) );
			// Update derived width if needed logic exists, otherwise assume width is updated manually or handled on save
			// Note: certificate-post-type.php sets display name but doesn't auto-update width on save unless logic is added.
		}

		// 3. Font Size
		if ( ! empty( $data['font_size'] ) ) {
			update_post_meta( $post_id, 'font_size', intval( $data['font_size'] ) );
		}

		// 4. Font Color
		if ( ! empty( $data['font_color'] ) && $data['font_color'] !== '#000000' ) { // Check against default to ensure intent? Or just check not empty
			// Color input usually sends hex, but "No Change" is tricky with color input default.
			// With color inputs, they default to black (#000000). To avoid overwriting with black unwantedly,
			// we should probably check if the user actually interacted. But standard bulk edit color picker is hard.
			// Let's assume if they touch it it sends value.
			// Actually, color input in bulk edit row will default to black.
			// It's safer to only update if it is NOT black, or use a text input/JS toggle.
			// For now, I'll update it.
			update_post_meta( $post_id, 'font_color', sanitize_hex_color( $data['font_color'] ) );
		}

		// 5. Font Style
		if ( ! empty( $data['font_style'] ) ) {
			update_post_meta( $post_id, 'font_style', sanitize_text_field( $data['font_style'] ) );
		}

		// 6. Event Date
		if ( isset( $data['event_date'] ) && $data['event_date'] !== '' ) {
			$raw = sanitize_text_field( $data['event_date'] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
				$parsed = DateTime::createFromFormat( 'Y-m-d', $raw );
				if ( $parsed && $parsed->format( 'Y-m-d' ) === $raw ) {
					update_post_meta( $post_id, 'event_date', $raw );
					delete_transient( 'certificate_generator_duplicate_template_warning' );
				}
			}
		}
	}
}
add_action( 'save_post', 'certificate_generator_save_bulk_edit_fields' );

// ──────────────────────────────────────────────────────────────────────────────
// Certificates CPT: admin columns
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Add certificate_type and event_date columns to the certificates list table.
 */
function certificate_generator_certificates_add_columns( $columns ) {
	$new_columns = array();
	foreach ( $columns as $key => $value ) {
		$new_columns[ $key ] = $value;
		if ( $key === 'title' ) {
			$new_columns['certificate_type'] = __( 'Certificate Type', 'certificate-generator' );
			$new_columns['event_date']       = __( 'Event Date', 'certificate-generator' );
		}
	}
	return $new_columns;
}
add_filter( 'manage_certificates_posts_columns', 'certificate_generator_certificates_add_columns' );

/**
 * Populate the custom columns for the certificates CPT.
 */
function certificate_generator_certificates_populate_column( $column, $post_id ) {
	switch ( $column ) {
		case 'certificate_type':
			$type = get_post_meta( $post_id, 'certificate_type', true );
			echo $type ? esc_html( $type ) : '—';
			break;
		case 'event_date':
			$date = get_post_meta( $post_id, 'event_date', true );
			if ( $date ) {
				$dt = DateTime::createFromFormat( 'Y-m-d', $date );
				echo $dt ? esc_html( $dt->format( 'd-m-Y' ) ) : esc_html( $date );
			} else {
				echo '—';
			}
			break;
	}
}
add_action( 'manage_certificates_posts_custom_column', 'certificate_generator_certificates_populate_column', 10, 2 );

/**
 * Make certificate_type and event_date sortable.
 */
function certificate_generator_certificates_sortable_columns( $columns ) {
	$columns['certificate_type'] = 'certificate_type';
	$columns['event_date']       = 'event_date';
	return $columns;
}
add_filter( 'manage_edit-certificates_sortable_columns', 'certificate_generator_certificates_sortable_columns' );

/**
 * Handle meta_key-based sorting for the certificates list table.
 */
function certificate_generator_certificates_sort_query( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( $query->get( 'post_type' ) !== 'certificates' ) {
		return;
	}

	$orderby = $query->get( 'orderby' );
	if ( $orderby === 'certificate_type' ) {
		$query->set( 'meta_key', 'certificate_type' );
		$query->set( 'orderby', 'meta_value' );
	} elseif ( $orderby === 'event_date' ) {
		$query->set( 'meta_key', 'event_date' );
		$query->set( 'orderby', 'meta_value' );
	}
}
add_action( 'pre_get_posts', 'certificate_generator_certificates_sort_query' );
?>