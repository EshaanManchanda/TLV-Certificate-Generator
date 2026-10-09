<?php
/**
 * Admin Page for Bulk Email Sending
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Handle AJAX requests
add_action( 'wp_ajax_cert_start_bulk_send', 'certificate_generator_ajax_start_bulk_send' );
add_action( 'wp_ajax_cert_get_queue_progress', 'certificate_generator_ajax_get_queue_progress' );
add_action( 'wp_ajax_cert_pause_queue', 'certificate_generator_ajax_pause_queue' );
add_action( 'wp_ajax_cert_resume_queue', 'certificate_generator_ajax_resume_queue' );
add_action( 'wp_ajax_cert_clear_queue', 'certificate_generator_ajax_clear_queue' );

// NEW: Filter-related AJAX handlers
add_action( 'wp_ajax_cert_get_filter_options', 'certificate_generator_ajax_get_filter_options' );
add_action( 'wp_ajax_cert_preview_recipients', 'certificate_generator_ajax_preview_recipients' );
add_action( 'wp_ajax_cert_send_to_filtered', 'certificate_generator_ajax_send_to_filtered' );

function certificate_generator_ajax_start_bulk_send() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$post_type = isset( $_POST['post_type'] ) ? sanitize_text_field( wp_unslash( $_POST['post_type'] ) ) : '';

	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ) ) ) {
		wp_send_json_error( 'Invalid post type' );
	}

	$result = certificate_generator_start_bulk_send( $post_type );

	// If start_bulk_send reports failure (e.g., no emails queued), return JSON error so UI can show details
	if ( is_array( $result ) && isset( $result['success'] ) && $result['success'] === false ) {
		wp_send_json_error( $result );
	}

	wp_send_json_success( $result );
}

function certificate_generator_ajax_get_queue_progress() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$progress = certificate_generator_get_queue_progress();

	wp_send_json_success( $progress );
}

function certificate_generator_ajax_pause_queue() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	certificate_generator_pause_queue();

	wp_send_json_success( 'Queue paused' );
}

function certificate_generator_ajax_resume_queue() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	certificate_generator_resume_queue();

	wp_send_json_success( 'Queue resumed' );
}

function certificate_generator_ajax_clear_queue() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	$cleared = (int) certificate_generator_clear_queue();

	wp_send_json_success(
		array(
			'cleared' => $cleared,
			'message' => sprintf(
				/* translators: %d: number of emails */
				_n( '%d email cleared from queue', '%d emails cleared from queue', $cleared, 'certificate-generator' ),
				$cleared
			),
		)
	);
}

// Render bulk send page
function certificate_generator_bulk_send_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'certificate-generator' ) );
	}

	$progress    = function_exists( 'certificate_generator_get_queue_progress' ) ? certificate_generator_get_queue_progress() : array();
	$rate_status = $progress['rate_status'] ?? ( function_exists( 'certificate_generator_get_rate_limit_status' ) ? certificate_generator_get_rate_limit_status() : array() );

	// Defensive defaults so missing keys never produce PHP notices or unescaped output.
	$stats     = array_merge(
		array(
			'pending' => 0,
			'sending' => 0,
			'sent'    => 0,
			'failed'  => 0,
		),
		( isset( $progress['stats'] ) && is_array( $progress['stats'] ) ) ? $progress['stats'] : array()
	);
	$is_paused = ! empty( $progress['is_paused'] );
	$per_hour  = max( 1, intval( $rate_status['limits']['emails_per_hour'] ?? 80 ) );
	$last_hour = intval( $rate_status['usage']['last_hour'] ?? 0 );

	?>
	<div class="wrap cg-bs">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Bulk Send Certificates', 'certificate-generator' ); ?></h1>
		<p class="cg-bs-lead"><?php esc_html_e( 'Choose who should receive their certificate, check the numbers, then queue the emails. Sending runs in the background — you can close this page.', 'certificate-generator' ); ?></p>

		<?php /* ── Overview: queue + sending rate ── */ ?>
		<div class="cg-bs-overview">
			<section class="cg-bs-card" id="cg-bs-queue" aria-labelledby="cg-bs-queue-title">
				<header class="cg-bs-card-head">
					<h2 id="cg-bs-queue-title"><?php esc_html_e( 'Email queue', 'certificate-generator' ); ?></h2>
					<span class="cg-bs-pill" data-q="state"></span>
				</header>

				<div class="cg-bs-metrics">
					<div class="cg-bs-metric">
						<span class="cg-bs-metric-value" data-q="pending"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></span>
						<span class="cg-bs-metric-label"><?php esc_html_e( 'Waiting', 'certificate-generator' ); ?></span>
					</div>
					<div class="cg-bs-metric">
						<span class="cg-bs-metric-value" data-q="sending"><?php echo esc_html( number_format_i18n( $stats['sending'] ) ); ?></span>
						<span class="cg-bs-metric-label"><?php esc_html_e( 'Sending now', 'certificate-generator' ); ?></span>
					</div>
					<div class="cg-bs-metric">
						<span class="cg-bs-metric-value is-good" data-q="sent"><?php echo esc_html( number_format_i18n( $stats['sent'] ) ); ?></span>
						<span class="cg-bs-metric-label"><?php esc_html_e( 'Sent (all time)', 'certificate-generator' ); ?></span>
					</div>
					<div class="cg-bs-metric">
						<span class="cg-bs-metric-value is-bad" data-q="failed"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span>
						<span class="cg-bs-metric-label"><?php esc_html_e( 'Failed (all time)', 'certificate-generator' ); ?></span>
					</div>
				</div>

				<p class="cg-bs-eta" data-q="eta"></p>

				<div class="cg-bs-actions">
					<button type="button" class="button" id="pause-queue"><?php esc_html_e( 'Pause', 'certificate-generator' ); ?></button>
					<button type="button" class="button" id="resume-queue"><?php esc_html_e( 'Resume', 'certificate-generator' ); ?></button>
					<button type="button" class="button" id="refresh-status"><?php esc_html_e( 'Refresh', 'certificate-generator' ); ?></button>
					<button type="button" class="button-link cg-bs-danger" id="clear-queue"><?php esc_html_e( 'Clear waiting emails', 'certificate-generator' ); ?></button>
					<a class="cg-bs-link" href="<?php echo esc_url( admin_url( 'admin.php?page=certificate-email-logs' ) ); ?>"><?php esc_html_e( 'Email logs →', 'certificate-generator' ); ?></a>
				</div>
			</section>

			<section class="cg-bs-card" id="cg-bs-rate" aria-labelledby="cg-bs-rate-title">
				<header class="cg-bs-card-head">
					<h2 id="cg-bs-rate-title"><?php esc_html_e( 'Sending rate', 'certificate-generator' ); ?></h2>
					<span class="cg-bs-pill" data-r="state"></span>
				</header>
				<p class="cg-bs-rate-figure">
					<span data-r="used"><?php echo esc_html( number_format_i18n( $last_hour ) ); ?></span>
					<span class="cg-bs-rate-of">/ <span data-r="limit"><?php echo esc_html( number_format_i18n( $per_hour ) ); ?></span> <?php esc_html_e( 'emails in the last hour', 'certificate-generator' ); ?></span>
				</p>
				<div class="cg-bs-meter" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( $per_hour ); ?>" aria-valuenow="<?php echo esc_attr( $last_hour ); ?>" aria-label="<?php esc_attr_e( 'Hourly sending limit used', 'certificate-generator' ); ?>">
					<span data-r="bar"></span>
				</div>
				<p class="cg-bs-muted" data-r="note"></p>
			</section>
		</div>

		<?php /* ── Filters + results ── */ ?>
		<div class="cg-bs-main cert-filter-container">
			<aside class="cg-bs-card cert-filter-panel" aria-labelledby="cg-bs-filter-title">
				<header class="cg-bs-card-head">
					<h2 id="cg-bs-filter-title"><?php esc_html_e( 'Who gets an email?', 'certificate-generator' ); ?></h2>
					<button type="button" class="button-link" id="cert-clear-filters"><?php esc_html_e( 'Reset', 'certificate-generator' ); ?></button>
				</header>

				<form id="bulk-send-form" onsubmit="return false;">
					<fieldset class="cg-bs-field">
						<legend><?php esc_html_e( 'Recipients', 'certificate-generator' ); ?></legend>
						<div class="cg-bs-chips">
							<label><input type="checkbox" name="post_types[]" value="students" checked> <?php esc_html_e( 'Students', 'certificate-generator' ); ?></label>
							<label><input type="checkbox" name="post_types[]" value="teachers" checked> <?php esc_html_e( 'Teachers', 'certificate-generator' ); ?></label>
							<label><input type="checkbox" name="post_types[]" value="schools" checked> <?php esc_html_e( 'Schools', 'certificate-generator' ); ?></label>
						</div>
					</fieldset>

					<fieldset class="cg-bs-field">
						<legend><?php esc_html_e( 'Email status', 'certificate-generator' ); ?></legend>
						<div class="cg-bs-chips">
							<label><input type="checkbox" name="email_status[]" value="not_sent" checked> <?php esc_html_e( 'Not sent yet', 'certificate-generator' ); ?></label>
							<label><input type="checkbox" name="email_status[]" value="sent"> <?php esc_html_e( 'Already sent', 'certificate-generator' ); ?></label>
							<label><input type="checkbox" name="email_status[]" value="no_email" checked> <?php esc_html_e( 'No email address', 'certificate-generator' ); ?></label>
						</div>
						<p class="cert-help-text"><?php esc_html_e( 'Tick "Already sent" only if you want to send those people their certificate again.', 'certificate-generator' ); ?></p>
					</fieldset>

					<div class="cg-bs-field">
						<label for="cert-filter-events"><?php esc_html_e( 'Event', 'certificate-generator' ); ?></label>
						<select name="events[]" id="cert-filter-events" class="cert-filter-select" multiple size="4"></select>
					</div>

					<div class="cg-bs-field">
						<label for="cert-filter-certificate-types"><?php esc_html_e( 'Certificate type', 'certificate-generator' ); ?></label>
						<select name="certificate_types[]" id="cert-filter-certificate-types" class="cert-filter-select" multiple size="4"></select>
					</div>

					<div class="cg-bs-field">
						<label for="cert-filter-schools"><?php esc_html_e( 'School', 'certificate-generator' ); ?></label>
						<select name="schools[]" id="cert-filter-schools" class="cert-filter-select" multiple size="5"></select>
					</div>

					<p class="cert-help-text"><?php esc_html_e( 'Lists: nothing selected means "all". Ctrl/Cmd-click to pick several.', 'certificate-generator' ); ?></p>

					<details class="cg-bs-more">
						<summary><?php esc_html_e( 'More filters', 'certificate-generator' ); ?> <span class="cg-bs-count" id="cg-bs-more-count" hidden></span></summary>

						<div class="cg-bs-field">
							<label for="cert-filter-year"><?php esc_html_e( 'Year', 'certificate-generator' ); ?></label>
							<select name="year[]" id="cert-filter-year" class="cert-filter-select" multiple size="3"></select>
						</div>

						<div class="cg-bs-field">
							<label for="cert-filter-sources"><?php esc_html_e( 'Import source', 'certificate-generator' ); ?></label>
							<select name="sources[]" id="cert-filter-sources" class="cert-filter-select" multiple size="3"></select>
							<p class="cert-help-text"><?php esc_html_e( 'The CSV file a record came from, or an LMS integration.', 'certificate-generator' ); ?></p>
						</div>

						<div class="cg-bs-field cg-bs-range">
							<label><?php esc_html_e( 'Issued from', 'certificate-generator' ); ?> <input type="date" name="date_from" id="cert-filter-date-from" class="cert-filter-input"></label>
							<label><?php esc_html_e( 'to', 'certificate-generator' ); ?> <input type="date" name="date_to" id="cert-filter-date-to" class="cert-filter-input"></label>
						</div>

						<div class="cg-bs-field">
							<label for="cert-filter-email-search"><?php esc_html_e( 'Email contains', 'certificate-generator' ); ?></label>
							<input type="search" name="email_search" id="cert-filter-email-search" class="cert-filter-input" placeholder="<?php esc_attr_e( 'e.g. @school.edu', 'certificate-generator' ); ?>">
						</div>

						<div class="cg-bs-field">
							<label for="cert-filter-email-list"><?php esc_html_e( 'Only these emails', 'certificate-generator' ); ?></label>
							<textarea name="email_list" id="cert-filter-email-list" class="cert-filter-textarea" rows="3" placeholder="<?php esc_attr_e( 'Paste emails, one per line or comma-separated', 'certificate-generator' ); ?>"></textarea>
							<p class="cert-help-text" id="cg-bs-email-list-note"></p>
						</div>
					</details>
				</form>
			</aside>

			<section class="cg-bs-card cert-preview-panel" aria-labelledby="cg-bs-results-title" aria-live="polite">
				<header class="cg-bs-card-head">
					<h2 id="cg-bs-results-title"><?php esc_html_e( 'Matching certificates', 'certificate-generator' ); ?></h2>
					<button type="button" class="button" id="cert-export-preview"><?php esc_html_e( 'Export CSV', 'certificate-generator' ); ?></button>
				</header>

				<div class="cert-preview-content">
					<div class="cert-loading"><?php esc_html_e( 'Loading preview…', 'certificate-generator' ); ?></div>
				</div>

				<footer class="cg-bs-sendbar">
					<p class="cg-bs-sendbar-text" id="cg-bs-send-summary"></p>
					<button type="button" class="button button-primary button-hero" id="cert-start-bulk-send" disabled><?php esc_html_e( 'Queue emails', 'certificate-generator' ); ?></button>
				</footer>
				<div id="bulk-send-result"></div>
			</section>
		</div>

		<details class="cg-bs-card cg-bs-help">
			<summary><?php esc_html_e( 'How sending works', 'certificate-generator' ); ?></summary>
			<ul>
				<li>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: emails per hour */
						__( 'Emails go out at up to %d per hour, so your mail host does not flag them as spam.', 'certificate-generator' ),
						$per_hour
					)
				);
				?>
				</li>
				<li><?php esc_html_e( 'Each address gets one email. If someone has several certificates, they are attached together.', 'certificate-generator' ); ?></li>
				<li><?php esc_html_e( 'Records without an email address are skipped.', 'certificate-generator' ); ?></li>
				<li><?php esc_html_e( 'A background job (WP-Cron) sends a batch every 5 minutes and retries failed emails up to 3 times.', 'certificate-generator' ); ?></li>
				<li><?php esc_html_e( 'You can close this page — sending continues automatically. Pause the queue at any time.', 'certificate-generator' ); ?></li>
			</ul>
		</details>
	</div>

	<script>
	window.cgBulkSendQueue = <?php echo wp_json_encode( $progress ); ?>;
	</script>
	<?php
}

/**
 * AJAX: Get filter options (schools, certificate types)
 */
function certificate_generator_ajax_get_filter_options() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}

	$option_type = isset( $_POST['option_type'] ) ? sanitize_text_field( wp_unslash( $_POST['option_type'] ) ) : '';

	$data = array();

	switch ( $option_type ) {
		case 'schools':
			$data = certificate_generator_get_unique_schools();
			break;
		case 'certificate_types':
			$data = certificate_generator_get_unique_certificate_types();
			break;
		case 'sources':
			$data = certificate_generator_get_unique_import_sources();
			break;
		case 'events':
			$data = certificate_generator_get_unique_events();
			break;
		case 'years':
			$data = certificate_generator_get_unique_years();
			break;
		case 'emails':
			$data = certificate_generator_get_unique_emails();
			break;
		default:
			wp_send_json_error( array( 'message' => 'Invalid option type' ) );
	}

	wp_send_json_success( $data );
}

/**
 * Sanitize the Bulk Send filter payload ($_POST['filters']) shared by preview and send.
 */
function cg_bulk_send_read_filters(): array {
	// phpcs:ignore WordPress.Security.NonceVerification -- callers verify the nonce.
	$filters = isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? map_deep( wp_unslash( $_POST['filters'] ), 'sanitize_text_field' ) : array();

	$filters['post_types'] = isset( $filters['post_types'] ) && is_array( $filters['post_types'] )
		? array_map( 'sanitize_text_field', $filters['post_types'] )
		: array( 'students', 'teachers', 'schools' );

	$filters['schools'] = isset( $filters['schools'] ) && is_array( $filters['schools'] )
		? array_map( 'sanitize_text_field', $filters['schools'] )
		: array();

	$filters['certificate_types'] = isset( $filters['certificate_types'] ) && is_array( $filters['certificate_types'] )
		? array_map( 'sanitize_text_field', $filters['certificate_types'] )
		: array();

	$filters['sources'] = isset( $filters['sources'] ) && is_array( $filters['sources'] )
		? array_map( 'sanitize_text_field', $filters['sources'] )
		: array();

	$filters['events'] = isset( $filters['events'] ) && is_array( $filters['events'] )
		? array_map( 'intval', $filters['events'] )
		: array();

	$filters['email_status'] = isset( $filters['email_status'] ) && is_array( $filters['email_status'] )
		? array_map( 'sanitize_text_field', $filters['email_status'] )
		: array();

	$filters['emails'] = isset( $filters['emails'] ) && is_array( $filters['emails'] )
		? array_map( 'sanitize_email', $filters['emails'] )
		: array();

	$filters['email_search'] = isset( $filters['email_search'] )
		? sanitize_text_field( $filters['email_search'] )
		: '';

	$filters['skip_already_sent'] = filter_var( $filters['skip_already_sent'] ?? false, FILTER_VALIDATE_BOOLEAN );

	$filters['year'] = isset( $filters['year'] ) && is_array( $filters['year'] )
		? array_map( 'intval', $filters['year'] )
		: array();

	$filters['date_from'] = isset( $filters['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] )
		? sanitize_text_field( $filters['date_from'] )
		: '';

	$filters['date_to'] = isset( $filters['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] )
		? sanitize_text_field( $filters['date_to'] )
		: '';

	return $filters;
}

/**
 * AJAX: Preview recipients based on filters
 */
function certificate_generator_ajax_preview_recipients() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}

	$filters = cg_bulk_send_read_filters();

	$filters['limit']  = min( 1000, max( 1, isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 100 ) );
	$filters['offset'] = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;

	// Get recipients
	$recipients = certificate_generator_get_filtered_recipients( $filters );

	// Get statistics
	$statistics = certificate_generator_get_filter_statistics( $filters );

	wp_send_json_success(
		array(
			'recipients'      => $recipients,
			'statistics'      => $statistics,
			'filters_applied' => $filters,
		)
	);
}

/**
 * AJAX: Start bulk send with filters
 */
function certificate_generator_ajax_send_to_filtered() {
	check_ajax_referer( 'cert_bulk_send', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ) );
	}

	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// Group every matching certificate by address: one email each, scoped to exactly
	// the certificates that matched (not every certificate that address ever got).
	$filters  = cg_bulk_send_read_filters();
	$by_email = array();
	$page     = 2000;
	for ( $offset = 0; ; $offset += $page ) {
		$rows = certificate_generator_get_filtered_recipients( array_merge( $filters, array( 'limit' => $page, 'offset' => $offset ) ) );
		foreach ( $rows as $r ) {
			$email = sanitize_email( $r['email'] ?? '' );
			if ( ! $email || ! is_email( $email ) ) {
				continue;
			}
			if ( ! isset( $by_email[ $email ] ) ) {
				$by_email[ $email ] = array( 'first' => $r, 'scope' => array() );
			}
			$by_email[ $email ]['scope'][ $r['post_type'] ][] = (int) $r['row_id'];
		}
		if ( count( $rows ) < $page ) {
			break;
		}
	}

	if ( empty( $by_email ) ) {
		wp_send_json_error( array( 'message' => 'No matching certificate has a valid email address' ) );
	}

	global $wpdb;
	$cg_table = $wpdb->prefix . 'certificate_generator';
	$now      = current_time( 'mysql' );
	$emails   = 0;
	$certs    = 0;
	$created  = 0;
	$failed   = array();

	foreach ( $by_email as $email => $group ) {
		$r = $group['first'];

		// The queue keys on a wp_certificate_generator row; prefer one of the same certificate
		// type (it labels the queue and email log), and create one if the address has none yet.
		$anchor = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $cg_table WHERE email = %s ORDER BY (certificate_type = %s) DESC, id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$email,
				$r['certificate_type'] ?? ''
			)
		);
		if ( ! $anchor ) {
			$cert_data = array(
				'student_name'     => $r['name'] ?? '',
				'email'            => $email,
				'certificate_type' => $r['certificate_type'] ?? '',
				'post_type'        => $r['post_type'] ?? 'students',
				'issue_date'       => $r['issue_date'] ?? $now,
			);
			$wpdb->insert(
				$cg_table,
				array(
					'student_name'     => sanitize_text_field( $cert_data['student_name'] ),
					'email'            => $email,
					'certificate_type' => sanitize_text_field( $cert_data['certificate_type'] ),
					'certificate_data' => wp_json_encode( $cert_data ),
					'pdf_path'         => '',
					'generated_via'    => 'bulk_queue',
					'issued_at'        => $now,
					'updated_at'       => $now,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$anchor = (int) $wpdb->insert_id;
			$created += $anchor ? 1 : 0;
		}

		$queued = $anchor ? certificate_generator_queue_email(
			$anchor,
			$email,
			array(
				'scope'          => $group['scope'],
				'recipient_name' => $r['name'] ?? '',
			)
		) : false;

		if ( $queued ) {
			++$emails;
			$certs += array_sum( array_map( 'count', $group['scope'] ) );
		} else {
			$failed[] = $email;
		}
	}

	$response = array(
		'queued'       => $certs,
		'emails'       => $emails,
		'auto_created' => $created,
		'failed'       => $failed,
		'message'      => sprintf(
			/* translators: 1: emails queued, 2: certificates they carry */
			__( 'Queued %1$s carrying %2$s.', 'certificate-generator' ),
			sprintf( /* translators: %s: number of emails */ _n( '%s email', '%s emails', $emails, 'certificate-generator' ), number_format_i18n( $emails ) ),
			sprintf( /* translators: %s: number of certificates */ _n( '%s certificate', '%s certificates', $certs, 'certificate-generator' ), number_format_i18n( $certs ) )
		) . ( $failed ? ' ' . sprintf(
			/* translators: %d: addresses that could not be queued */
			_n( '%d address could not be queued.', '%d addresses could not be queued.', count( $failed ), 'certificate-generator' ),
			count( $failed )
		) : '' ),
	);

	if ( 0 === $emails ) {
		wp_send_json_error( $response );
	}

	wp_send_json_success( $response );
}
?>
