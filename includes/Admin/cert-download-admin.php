<?php
/**
 * Admin: Download Student Certificates
 *
 * Filter page that lets an admin query students, teachers or schools by event, email, school, cert type,
 * year, and date range — then download individual PDFs or bulk ZIPs (auto-split
 * into parts of CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE certificates each).
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Part size — defined in certificate-generator.php; fallback for unit tests.
if ( ! defined( 'CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE' ) ) {
	define( 'CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE', 200 );
}

// ── Action hooks (fire before headers sent) ──────────────────────────────────

add_action( 'admin_init', 'certificate_generator_handle_admin_cert_zip_download' );
add_action( 'admin_init', 'certificate_generator_handle_admin_individual_cert_download' );
add_action( 'wp_ajax_certificate_generator_cert_dl_start', 'certificate_generator_ajax_cert_dl_start' );
add_action( 'wp_ajax_certificate_generator_cert_dl_step', 'certificate_generator_ajax_cert_dl_step' );
add_action( 'wp_ajax_certificate_generator_cert_dl_zip', 'certificate_generator_ajax_cert_dl_zip' );
add_action( 'admin_post_certificate_generator_cert_dl_file', 'certificate_generator_handle_cert_dl_file' );

// ── ZIP download handler (partitioned) ───────────────────────────────────────

function certificate_generator_handle_admin_cert_zip_download(): void {
	if ( ! isset( $_POST['cg_download_zip'] ) ) {
		return;
	}

	check_admin_referer( 'cg_admin_cert_zip', '_wpnonce_cg_zip' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
	}

	$filters   = certificate_generator_admin_cert_read_filters();
	$part_size = CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE;
	$total     = certificate_generator_admin_cert_count( $filters );
	$plan      = certificate_generator_admin_cert_part_plan( $total, $part_size );
	$part      = absint( $_POST['cg_zip_part'] ?? 0 );

	if ( 0 === $plan['num_parts'] ) {
		wp_safe_redirect(
			add_query_arg( array( 'page' => 'cg-cert-download', 'cg_error' => 'no_results' ), admin_url( 'admin.php' ) )
		);
		exit;
	}

	if ( $part >= $plan['num_parts'] ) {
		wp_safe_redirect(
			add_query_arg( array( 'page' => 'cg-cert-download', 'cg_error' => 'bad_part' ), admin_url( 'admin.php' ) )
		);
		exit;
	}

	$rows = certificate_generator_admin_cert_query( $filters, $part_size, $part * $part_size );

	if ( empty( $rows ) ) {
		wp_safe_redirect(
			add_query_arg( array( 'page' => 'cg-cert-download', 'cg_error' => 'no_results' ), admin_url( 'admin.php' ) )
		);
		exit;
	}

	// Raise limits — best-effort; the 200-cert part size is the real safety margin.
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	add_filter( 'certificate_generator_memory_limit', static fn() => '512M' ); // filterable target for big ZIPs
	wp_raise_memory_limit( 'certificate_generator' ); // raises only, never lowers
	@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

	// PDFs go into the ZIP straight from cg_certificates/ (no temp copies); the manifest is
	// built in memory. Unchanged certificates come from the PDF cache.
	$name_col   = certificate_generator_admin_cert_name_col( $filters );
	$cert_files = array();
	$manifest   = array( certificate_generator_admin_cert_manifest_header() );
	foreach ( $rows as $row ) {
		$entry = certificate_generator_admin_cert_zip_entry( $row, $name_col );
		if ( $entry ) {
			$cert_files[] = $entry['file'];
			$manifest[]   = $entry['manifest'];
		}
	}

	if ( empty( $cert_files ) ) {
		wp_safe_redirect(
			add_query_arg( array( 'page' => 'cg-cert-download', 'cg_error' => 'no_pdfs' ), admin_url( 'admin.php' ) )
		);
		exit;
	}

	if ( ! function_exists( 'certificate_generator_create_zip_for_email' ) ) {
		wp_die( esc_html__( 'ZIP service unavailable.', 'certificate-generator' ) );
	}

	$cert_files[] = array(
		'content'  => certificate_generator_admin_cert_manifest_csv( $manifest ),
		'filename' => 'manifest.csv',
	);
	$zip_label    = certificate_generator_admin_cert_zip_label( $filters, $part, $plan['num_parts'] );
	$zip_result   = certificate_generator_create_zip_for_email( $cert_files, $zip_label, array( 'private' => true ) );

	if ( ! $zip_result || empty( $zip_result['zip_path'] ) || ! file_exists( $zip_result['zip_path'] ) ) {
		wp_safe_redirect(
			add_query_arg( array( 'page' => 'cg-cert-download', 'cg_error' => 'zip_failed' ), admin_url( 'admin.php' ) )
		);
		exit;
	}

	// The private ZIP stays for reuse; CertificateGenerator_Cron_Jobs::cleanup_old_zips() removes it after a day.
	certificate_generator_admin_cert_stream_zip( $zip_result['zip_path'], certificate_generator_admin_cert_zip_download_name( $filters, $part, $plan['num_parts'] ) );
}

/**
 * Render (or fetch from the PDF cache) one recipient's certificate and describe its ZIP entry.
 *
 * @return array{file: array, manifest: array}|null Null when no PDF could be produced.
 */
function certificate_generator_admin_cert_zip_entry( array $row, string $name_col ): ?array {
	$path = certificate_generator_admin_cert_pdf_path( $row );
	if ( '' === $path ) {
		return null;
	}
	$row_id    = (int) ( $row['id'] ?? 0 );
	$cert_type = (string) ( $row['certificate_type'] ?? '' );
	$safe_name = function_exists( 'certificate_generator_certificate_pdf_filename' )
		? certificate_generator_certificate_pdf_filename( (string) ( $row[ $name_col ] ?? 'recipient' ), $cert_type, (string) $row_id )
		: sanitize_file_name( ( $row[ $name_col ] ?? 'recipient' ) . '_' . $row_id . '.pdf' );

	return array(
		'file'     => array(
			'path'     => $path,
			'filename' => $safe_name,
		),
		'manifest' => array(
			$row[ $name_col ] ?? '',
			$row['email'] ?? '',
			$row['school_name'] ?? '',
			$cert_type,
			(string) $row_id,
			$safe_name,
		),
	);
}

/**
 * Absolute path of a recipient's certificate PDF, rendering it only when the cache misses.
 * '' when the row has no certificate type or generation failed.
 */
function certificate_generator_admin_cert_pdf_path( array $row ): string {
	$cert_type = $row['certificate_type'] ?? '';
	if ( empty( $cert_type ) || ! function_exists( 'certificate_generator_generate_certificate_pdf' ) ) {
		return '';
	}

	$fields = class_exists( 'CertificateGenerator_Field_Schema' )
		? CertificateGenerator_Field_Schema::get_all_renderable_fields( $cert_type )
		: array( 'student_name', 'school_name', 'issue_date' );

	$post_id  = (int) ( $row['wp_post_id'] ?? $row['id'] ?? 0 );
	$file_url = certificate_generator_generate_certificate_pdf( $post_id, $fields, $row );
	if ( ! $file_url ) {
		return '';
	}

	// The generator returns the URL of the file it just wrote; postmeta may belong to another post.
	$upload_dir = wp_upload_dir();
	$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_url );
	return file_exists( $file_path ) ? $file_path : '';
}

function certificate_generator_admin_cert_manifest_header(): array {
	return array( 'name', 'email', 'school', 'cert_type', 'cg_id', 'pdf_filename' );
}

/**
 * The manifest.csv bytes for these rows — same fputcsv() output as the old temp file.
 */
function certificate_generator_admin_cert_manifest_csv( array $lines ): string {
	$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( $lines as $line ) {
		fputcsv( $fh, $line );
	}
	rewind( $fh );
	$csv = (string) stream_get_contents( $fh );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return $csv;
}

/**
 * Send a private ZIP to the browser and end the request. readfile() streams it in chunks,
 * so a large ZIP never sits in PHP memory.
 */
function certificate_generator_admin_cert_stream_zip( string $zip_path, string $download_name ): void {
	while ( ob_get_level() ) {
		ob_end_clean();
	}

	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $download_name ) . '"' );
	header( 'Content-Length: ' . filesize( $zip_path ) );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	readfile( $zip_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

// ── Chunked bulk download job ─────────────────────────────────────────────────
// assets/js/cert-download-job.js runs a large download as many short requests —
// start → step … step → one ZIP per part — instead of one request that renders
// thousands of PDFs. Job state is a per-user transient holding row ids only; the PDFs
// themselves live in the PDF cache, so a retried or restarted job never renders a
// certificate twice.

/** Capability + nonce for every job request. */
function certificate_generator_cert_dl_guard(): void {
	check_ajax_referer( 'certificate_generator_cert_dl_job', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'certificate-generator' ) ), 403 );
	}
}

/** The current user's job, or a JSON error. Job ids are 20 alphanumerics, never a path. */
function certificate_generator_cert_dl_job( string $job_id ): array {
	$job = preg_match( '/^[A-Za-z0-9]{20}$/', $job_id ) ? get_transient( 'certificate_generator_dljob_' . $job_id ) : false;
	if ( ! is_array( $job ) || (int) $job['user_id'] !== get_current_user_id() ) {
		wp_send_json_error( array( 'message' => __( 'This download has expired. Please start it again.', 'certificate-generator' ) ), 404 );
	}
	return $job;
}

/**
 * Take a job's lock: INSERT IGNORE succeeds for exactly one request (add_option() would
 * not — it upserts). A lock older than two minutes belongs to a request that died, and
 * the conditional UPDATE lets exactly one request take it over.
 */
function certificate_generator_cert_dl_lock( string $job_id ): bool {
	global $wpdb;
	$name = 'certificate_generator_dljob_lock_' . $job_id;
	if ( 1 === (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')", $name, time() ) ) ) {
		return true;
	}
	return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s AND option_value < %d", time(), $name, time() - 2 * MINUTE_IN_SECONDS ) );
}

function certificate_generator_cert_dl_unlock( string $job_id ): void {
	global $wpdb;
	$wpdb->delete( $wpdb->options, array( 'option_name' => 'certificate_generator_dljob_lock_' . $job_id ) );
}

function certificate_generator_cert_dl_save( string $job_id, array $job ): void {
	set_transient( 'certificate_generator_dljob_' . $job_id, $job, 6 * HOUR_IN_SECONDS );
}

function certificate_generator_ajax_cert_dl_start(): void {
	certificate_generator_cert_dl_guard();

	$filters = certificate_generator_admin_cert_read_filters( 'POST' );
	$ids     = certificate_generator_admin_cert_query_ids( $filters );
	if ( ! $ids ) {
		wp_send_json_error( array( 'message' => __( 'No recipients matched the selected filters.', 'certificate-generator' ) ) );
	}

	$job_id = wp_generate_password( 20, false );
	certificate_generator_cert_dl_save(
		$job_id,
		array(
			'user_id' => get_current_user_id(),
			'filters' => $filters,
			'ids'     => $ids,
			'cursor'  => 0,       // ids before this index are rendered (or failed)
			'failed'  => array(), // row ids that produced no PDF
			'zips'    => array(), // part index => private ZIP path
		)
	);
	certificate_generator_debug_log( "Bulk download job $job_id started: " . count( $ids ) . ' certificate(s)' );

	wp_send_json_success(
		array(
			'job_id' => $job_id,
			'total'  => count( $ids ),
		)
	);
}

/**
 * Render the next certificates for as long as the time budget allows (default: the email
 * queue's CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET), so one request stays well under proxy timeouts.
 */
function certificate_generator_ajax_cert_dl_step(): void {
	certificate_generator_cert_dl_guard();
	$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- certificate_generator_cert_dl_guard() checks the nonce
	certificate_generator_cert_dl_job( $job_id );
	if ( ! certificate_generator_cert_dl_lock( $job_id ) ) {
		wp_send_json_success( array( 'busy' => true ) ); // another tab or a retry is mid-step
	}

	try {
		$job    = get_transient( 'certificate_generator_dljob_' . $job_id ); // re-read under the lock
		$total  = count( $job['ids'] );
		$budget = (float) apply_filters( 'certificate_generator_cert_dl_step_budget', defined( 'CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET' ) ? CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET : 20 );
		$start  = microtime( true );
		$before = $job['cursor'];
		@set_time_limit( (int) $budget + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		while ( $job['cursor'] < $total ) {
			$batch = array_slice( $job['ids'], $job['cursor'], 50 );
			$rows  = certificate_generator_admin_cert_rows_by_ids( $job['filters']['entity'], $batch );
			foreach ( $batch as $id ) {
				if ( ! isset( $rows[ $id ] ) || '' === certificate_generator_admin_cert_pdf_path( $rows[ $id ] ) ) {
					$job['failed'][] = $id;
				}
				++$job['cursor'];
				if ( microtime( true ) - $start >= $budget ) {
					break 2;
				}
			}
		}
		// Saved once per step: if this request dies mid-way, the next one redoes only
		// cache hits for the certificates this one already rendered.
		certificate_generator_cert_dl_save( $job_id, $job );
		certificate_generator_debug_log( sprintf( 'Bulk download job %s: %d → %d of %d in %.1fs, peak %.0f MB', $job_id, $before, $job['cursor'], $total, microtime( true ) - $start, memory_get_peak_usage() / MB_IN_BYTES ) );
	} finally {
		certificate_generator_cert_dl_unlock( $job_id );
	}

	wp_send_json_success(
		array(
			'processed'  => $job['cursor'],
			'total'      => $total,
			'percentage' => round( 100 * $job['cursor'] / $total, 1 ),
			'complete'   => $job['cursor'] >= $total,
			'failed'     => count( $job['failed'] ),
			'parts'      => certificate_generator_admin_cert_part_plan( $total, CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE )['num_parts'],
		)
	);
}

/** Build one part's private ZIP from the cached PDFs (a missing PDF is rendered again). */
function certificate_generator_ajax_cert_dl_zip(): void {
	certificate_generator_cert_dl_guard();
	$job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- certificate_generator_cert_dl_guard() checks the nonce
	$job    = certificate_generator_cert_dl_job( $job_id );
	$total  = count( $job['ids'] );
	$plan   = certificate_generator_admin_cert_part_plan( $total, CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE );
	$part   = absint( $_POST['part'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- certificate_generator_cert_dl_guard() checks the nonce

	if ( $job['cursor'] < $total || $part >= $plan['num_parts'] ) {
		wp_send_json_error( array( 'message' => __( 'Invalid download part requested. Please try again from the preview.', 'certificate-generator' ) ) );
	}
	if ( ! certificate_generator_cert_dl_lock( $job_id ) ) {
		wp_send_json_success( array( 'busy' => true ) );
	}

	try {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors — one part is bounded by the part size
		$ids      = array_values( array_diff( array_slice( $job['ids'], $part * $plan['part_size'], $plan['part_size'] ), $job['failed'] ) );
		$rows     = certificate_generator_admin_cert_rows_by_ids( $job['filters']['entity'], $ids );
		$name_col = certificate_generator_admin_cert_name_col( $job['filters'] );
		$files    = array();
		$manifest = array( certificate_generator_admin_cert_manifest_header() );
		foreach ( $ids as $id ) {
			$entry = isset( $rows[ $id ] ) ? certificate_generator_admin_cert_zip_entry( $rows[ $id ], $name_col ) : null;
			if ( $entry ) {
				$files[]    = $entry['file'];
				$manifest[] = $entry['manifest'];
			}
		}

		$zip = false;
		if ( $files ) {
			$files[] = array(
				'content'  => certificate_generator_admin_cert_manifest_csv( $manifest ),
				'filename' => 'manifest.csv',
			);
			$zip     = certificate_generator_create_zip_for_email( $files, certificate_generator_admin_cert_zip_label( $job['filters'], $part, $plan['num_parts'] ), array( 'private' => true ) );
		}
		if ( $zip ) {
			$job['zips'][ $part ] = $zip['zip_path'];
			certificate_generator_cert_dl_save( $job_id, $job );
		}
	} finally {
		certificate_generator_cert_dl_unlock( $job_id );
	}

	if ( ! $zip ) {
		wp_send_json_error( array( 'message' => __( 'ZIP file creation failed. Please try again.', 'certificate-generator' ) ) );
	}

	wp_send_json_success(
		array(
			// Raw URL for JS (wp_nonce_url() would HTML-escape the & separators).
			'download_url' => add_query_arg(
				array(
					'action'   => 'certificate_generator_cert_dl_file',
					'job_id'   => $job_id,
					'part'     => $part,
					'_wpnonce' => wp_create_nonce( 'cg_cert_dl_file_' . $job_id ),
				),
				admin_url( 'admin-post.php' )
			),
			'label'        => 1 === $plan['num_parts']
				/* translators: %d: number of certificates */
				? sprintf( __( 'Download ZIP (%d certificates)', 'certificate-generator' ), $zip['certificate_count'] )
				/* translators: 1: part number, 2: number of parts, 3: number of certificates */
				: sprintf( __( 'Download part %1$d of %2$d (%3$d certificates)', 'certificate-generator' ), $part + 1, $plan['num_parts'], $zip['certificate_count'] ),
		)
	);
}

/** Stream a finished part to the admin who owns the job. */
function certificate_generator_handle_cert_dl_file(): void {
	$job_id = sanitize_text_field( wp_unslash( $_GET['job_id'] ?? '' ) );
	check_admin_referer( 'cg_cert_dl_file_' . $job_id );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
	}

	$job  = preg_match( '/^[A-Za-z0-9]{20}$/', $job_id ) ? get_transient( 'certificate_generator_dljob_' . $job_id ) : false;
	$part = absint( $_GET['part'] ?? 0 );
	$path = ( is_array( $job ) && (int) $job['user_id'] === get_current_user_id() ) ? (string) ( $job['zips'][ $part ] ?? '' ) : '';
	$real = $path ? realpath( $path ) : false;
	// The path comes from the job, never the request; still, only private/ is servable.
	if ( ! $real || 0 !== strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( (string) realpath( certificate_generator_private_zip_dir() ) ) ) ) ) {
		wp_die( esc_html__( 'This download has expired. Please start it again.', 'certificate-generator' ), 404 );
	}

	$plan = certificate_generator_admin_cert_part_plan( count( $job['ids'] ), CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE );
	certificate_generator_admin_cert_stream_zip( $real, certificate_generator_admin_cert_zip_download_name( $job['filters'], $part, $plan['num_parts'] ) );
}

// ── Individual PDF download handler (admin-side, works with SQL id) ───────────

function certificate_generator_handle_admin_individual_cert_download(): void {
	if ( ! isset( $_GET['action'] ) || 'cg_admin_download_cert' !== $_GET['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}

	$sql_id = absint( $_GET['sql_id'] ?? 0 );
	$entity = certificate_generator_admin_cert_read_filters( 'GET' )['entity'];
	check_admin_referer( 'cg_admin_dl_cert_' . $entity . '_' . $sql_id );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
	}

	if ( ! $sql_id || ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		wp_die( esc_html__( 'Invalid recipient ID.', 'certificate-generator' ) );
	}

	global $wpdb;
	$tbl = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id = %d", $sql_id ), ARRAY_A );

	if ( ! $row ) {
		wp_die( esc_html__( 'Recipient not found.', 'certificate-generator' ) );
	}

	$cert_type = $row['certificate_type'] ?? '';
	if ( empty( $cert_type ) ) {
		wp_die( esc_html__( 'No certificate type assigned.', 'certificate-generator' ) );
	}

	if ( ! empty( $row['extra_fields'] ) ) {
		$extra = json_decode( $row['extra_fields'], true );
		if ( is_array( $extra ) ) {
			$row = array_merge( $row, $extra );
		}
	}
	unset( $row['extra_fields'] );

	$fields = class_exists( 'CertificateGenerator_Field_Schema' )
		? CertificateGenerator_Field_Schema::get_all_renderable_fields( $cert_type )
		: array( 'student_name', 'school_name', 'issue_date' );

	$post_id  = (int) ( $row['wp_post_id'] ?? $row['id'] ?? 0 );
	$file_url = function_exists( 'certificate_generator_generate_certificate_pdf' )
		? certificate_generator_generate_certificate_pdf( $post_id, $fields, $row )
		: null;

	if ( ! $file_url ) {
		wp_die( esc_html__( 'Failed to generate certificate PDF.', 'certificate-generator' ) );
	}

	$upload_dir = wp_upload_dir();
	$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_url );

	if ( ! file_exists( $file_path ) ) {
		wp_die( esc_html__( 'Certificate PDF file not found.', 'certificate-generator' ) );
	}

	$filename = sanitize_file_name( ( $row[ certificate_generator_admin_cert_entities()[ $entity ][0] ] ?? 'recipient' ) . '_certificate.pdf' );

	if ( ob_get_level() ) {
		ob_end_clean();
	}

	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $file_path ) );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}

// ── Shared helpers ────────────────────────────────────────────────────────────

/**
 * Read and sanitize filter fields from $_GET / $_POST / $_REQUEST.
 *
 * @param string $source 'GET', 'POST', or 'REQUEST' (default).
 * @return array Normalised filter array compatible with certificate_generator_build_recipient_filter_sql().
 */
function certificate_generator_admin_cert_read_filters( string $source = 'REQUEST' ): array {
	// phpcs:disable WordPress.Security.NonceVerification
	if ( $source === 'POST' ) {
		$bag = $_POST;
	} elseif ( $source === 'GET' ) {
		$bag = $_GET;
	} else {
		$bag = $_REQUEST;
	}

	return array(
		'schools'           => isset( $bag['filter_school'] ) && is_array( $bag['filter_school'] )
			? array_map( 'sanitize_text_field', $bag['filter_school'] )
			: array(),
		'certificate_types' => isset( $bag['filter_cert_type'] ) && is_array( $bag['filter_cert_type'] )
			? array_map( 'sanitize_text_field', $bag['filter_cert_type'] )
			: array(),
		'year'              => isset( $bag['filter_year'] ) && is_array( $bag['filter_year'] )
			? array_map( 'intval', $bag['filter_year'] )
			: array(),
		'date_from'         => isset( $bag['filter_date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bag['filter_date_from'] )
			? sanitize_text_field( $bag['filter_date_from'] )
			: '',
		'date_to'           => isset( $bag['filter_date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $bag['filter_date_to'] )
			? sanitize_text_field( $bag['filter_date_to'] )
			: '',
		'email_search'      => isset( $bag['filter_email'] ) ? sanitize_text_field( $bag['filter_email'] ) : '',
		'emails'            => array(),
		'events'            => isset( $bag['filter_event'] ) && is_array( $bag['filter_event'] )
			? array_filter( array_map( 'absint', $bag['filter_event'] ) )
			: array(),
		'entity'            => isset( $bag['filter_entity'] ) && isset( certificate_generator_admin_cert_entities()[ $bag['filter_entity'] ] )
			? $bag['filter_entity']
			: 'students',
	);
	// phpcs:enable WordPress.Security.NonceVerification
}

/**
 * Recipient tables this page can download from => [ name column, label ].
 */
function certificate_generator_admin_cert_entities(): array {
	return array(
		'students' => array( 'student_name', __( 'Students', 'certificate-generator' ) ),
		'teachers' => array( 'teacher_name', __( 'Teachers', 'certificate-generator' ) ),
		'schools'  => array( 'school_name', __( 'Schools', 'certificate-generator' ) ),
	);
}

/**
 * Name column for the filter's recipient type.
 */
function certificate_generator_admin_cert_name_col( array $filters ): string {
	return certificate_generator_admin_cert_entities()[ $filters['entity'] ?? 'students' ][0];
}

/**
 * Query the selected recipient table using the given filters.
 * Ordered by name ASC, id ASC for deterministic pagination.
 *
 * @param array $filters   Normalised filter array from certificate_generator_admin_cert_read_filters().
 * @param int   $limit     Max rows to return.
 * @param int   $offset    Offset for pagination.
 * @return array[]         Flat row arrays (extra_fields decoded and merged).
 */
function certificate_generator_admin_cert_query( array $filters, int $limit = 200, int $offset = 0 ): array {
	if (
		! class_exists( '\CertificateGenerator\Database\CustomTables' ) ||
		! function_exists( 'certificate_generator_build_recipient_filter_sql' )
	) {
		return array();
	}

	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	$tbl    = $tables->get_table( $filters['entity'] ?? 'students' );

	if ( ! $tbl || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
		return array();
	}

	[ $frags, $params ] = certificate_generator_build_recipient_filter_sql( $filters, '' );
	$where    = $frags ? ' WHERE ' . implode( ' AND ', $frags ) : '';
	$name_col = certificate_generator_admin_cert_name_col( $filters );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$sql      = "SELECT * FROM {$tbl}{$where} ORDER BY {$name_col} ASC, id ASC LIMIT %d OFFSET %d";
	$params[] = $limit;
	$params[] = $offset;

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

	return array_map( 'certificate_generator_admin_cert_flatten_row', $rows ?: array() );
}

/** Merge a row's extra_fields JSON into it as flat keys. */
function certificate_generator_admin_cert_flatten_row( array $row ): array {
	if ( ! empty( $row['extra_fields'] ) ) {
		$extra = json_decode( $row['extra_fields'], true );
		if ( is_array( $extra ) ) {
			$row = array_merge( $row, $extra );
		}
	}
	unset( $row['extra_fields'] );
	return $row;
}

/**
 * Ids of every recipient matching the filters, in the same order certificate_generator_admin_cert_query() pages
 * through them. A download job stores only these.
 *
 * @return int[]
 */
function certificate_generator_admin_cert_query_ids( array $filters ): array {
	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) || ! function_exists( 'certificate_generator_build_recipient_filter_sql' ) ) {
		return array();
	}
	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	$entity = $filters['entity'] ?? 'students';
	if ( ! $tables->table_exists( $entity ) ) {
		return array();
	}

	[ $frags, $params ] = certificate_generator_build_recipient_filter_sql( $filters, '' );
	$where              = $frags ? ' WHERE ' . implode( ' AND ', $frags ) : '';
	$sql                = 'SELECT id FROM ' . $tables->get_table( $entity ) . "{$where} ORDER BY " . certificate_generator_admin_cert_name_col( $filters ) . ' ASC, id ASC';

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	return array_map( 'intval', $wpdb->get_col( $params ? $wpdb->prepare( $sql, ...$params ) : $sql ) );
}

/**
 * Rows for these ids in one query, keyed by id, extra_fields flattened.
 *
 * @param string $entity students|teachers|schools (already validated by certificate_generator_admin_cert_read_filters()).
 * @param int[]  $ids
 */
function certificate_generator_admin_cert_rows_by_ids( string $entity, array $ids ): array {
	global $wpdb;
	$ids = array_filter( array_map( 'intval', $ids ) );
	if ( ! $ids || ! isset( certificate_generator_admin_cert_entities()[ $entity ] ) ) {
		return array();
	}
	$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
	$in    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ($in)", ...$ids ), ARRAY_A );
	return array_column( array_map( 'certificate_generator_admin_cert_flatten_row', $rows ?: array() ), null, 'id' );
}

/**
 * Count total recipients matching filters (no LIMIT applied).
 */
function certificate_generator_admin_cert_count( array $filters ): int {
	if (
		! class_exists( '\CertificateGenerator\Database\CustomTables' ) ||
		! function_exists( 'certificate_generator_build_recipient_filter_sql' )
	) {
		return 0;
	}

	global $wpdb;
	$tbl = \CertificateGenerator\Database\CustomTables::instance()->get_table( $filters['entity'] ?? 'students' );

	if ( ! $tbl || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
		return 0;
	}

	[ $frags, $params ] = certificate_generator_build_recipient_filter_sql( $filters, '' );
	$where = $frags ? ' WHERE ' . implode( ' AND ', $frags ) : '';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$sql = "SELECT COUNT(*) FROM {$tbl}{$where}";

	if ( $params ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
	}
	return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
}

// ── Part math + naming helpers ───────────────────────────────────────────────

/**
 * Compute the number of ZIP parts needed.
 *
 * @return array{part_size: int, num_parts: int}
 */
function certificate_generator_admin_cert_part_plan( int $total, int $part_size ): array {
	$part_size = max( 1, $part_size );
	if ( $total <= 0 ) {
		return array( 'part_size' => $part_size, 'num_parts' => 0 );
	}
	return array( 'part_size' => $part_size, 'num_parts' => (int) ceil( $total / $part_size ) );
}

/**
 * Build a slug for the ZIP label (passed to the ZIP builder for internal filename).
 */
function certificate_generator_admin_cert_zip_label( array $filters, int $part, int $num_parts ): string {
	$pieces = array( 'admin', $filters['entity'] ?? 'students' );

	if ( ! empty( $filters['year'] ) ) {
		$pieces[] = implode( '-', array_slice( $filters['year'], 0, 2 ) );
	}
	if ( ! empty( $filters['certificate_types'] ) ) {
		$pieces[] = sanitize_title( $filters['certificate_types'][0] );
	}
	if ( $num_parts > 1 ) {
		$pieces[] = 'part' . ( $part + 1 ) . 'of' . $num_parts;
	}
	$pieces[] = gmdate( 'Y-m-d' );

	return implode( '_', $pieces );
}

/**
 * Build the Content-Disposition download filename for the streamed ZIP.
 */
function certificate_generator_admin_cert_zip_download_name( array $filters, int $part, int $num_parts ): string {
	$pieces = array( 'certificates', $filters['entity'] ?? 'students' );

	if ( ! empty( $filters['year'] ) ) {
		$pieces[] = implode( '-', array_slice( $filters['year'], 0, 2 ) );
	}
	if ( ! empty( $filters['certificate_types'] ) ) {
		$pieces[] = sanitize_title( $filters['certificate_types'][0] );
	}
	if ( $num_parts > 1 ) {
		$pieces[] = 'part' . ( $part + 1 ) . '_of_' . $num_parts;
	}
	$pieces[] = gmdate( 'Y-m-d' );

	return sanitize_file_name( implode( '_', $pieces ) . '.zip' );
}

// ── Page renderer ─────────────────────────────────────────────────────────────

function certificate_generator_render_admin_cert_download_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ) );
	}

	$part_size  = CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE;
	$years      = function_exists( 'certificate_generator_get_unique_years' ) ? certificate_generator_get_unique_years() : array();
	$schools    = function_exists( 'certificate_generator_get_unique_schools' ) ? certificate_generator_get_unique_schools() : array();
	$cert_types = function_exists( 'certificate_generator_get_unique_certificate_types' ) ? certificate_generator_get_unique_certificate_types() : array();
	$events     = function_exists( 'certificate_generator_get_unique_events' ) ? certificate_generator_get_unique_events() : array();
	$entities   = certificate_generator_admin_cert_entities();

	// Filters come from GET (preview request).
	$active    = certificate_generator_admin_cert_read_filters( 'GET' );
	$name_col  = certificate_generator_admin_cert_name_col( $active );
	$previewed = isset( $_GET['cg_preview'] ) && '1' === $_GET['cg_preview']; // phpcs:ignore WordPress.Security.NonceVerification
	$total     = $previewed ? certificate_generator_admin_cert_count( $active ) : 0;
	$rows      = $previewed ? certificate_generator_admin_cert_query( $active, $part_size, 0 ) : array();
	$plan      = certificate_generator_admin_cert_part_plan( $total, $part_size );

	// Turns the ZIP form into a chunked job with a progress bar; without JS the form posts as before.
	if ( $rows ) {
		$js = 'assets/js/cert-download-job.js';
		wp_enqueue_script( 'cg-cert-download-job', CERTIFICATE_GENERATOR_URL . $js, array( 'cg-ui' ), (string) filemtime( CERTIFICATE_GENERATOR_PATH . $js ), true );
		wp_localize_script(
			'cg-cert-download-job',
			'cgCertDlJob',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'certificate_generator_cert_dl_job' ),
				'total'   => $total,
				'i18n'    => array(
					/* translators: %d: number of certificates */
					'start'      => __( 'Download All %d Certificates as ZIP', 'certificate-generator' ),
					'starting'   => __( 'Starting…', 'certificate-generator' ),
					/* translators: 1: certificates ready, 2: total */
					'progress'   => __( 'Preparing certificates… %1$d of %2$d', 'certificate-generator' ),
					/* translators: 1: part number, 2: number of parts */
					'zipping'    => __( 'Building ZIP %1$d of %2$d…', 'certificate-generator' ),
					'done'       => __( 'Done. If the download did not start, use the button below.', 'certificate-generator' ),
					/* translators: %d: number of certificates without a PDF */
					'doneFailed' => __( 'Done, but %d certificate(s) could not be generated — check that they have a published template.', 'certificate-generator' ),
					'error'      => __( 'An unexpected error occurred.', 'certificate-generator' ),
				),
			)
		);
	}

	$error          = isset( $_GET['cg_error'] ) ? sanitize_key( $_GET['cg_error'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	$error_messages = array(
		'no_results' => __( 'No recipients matched the selected filters.', 'certificate-generator' ),
		'no_pdfs'    => __( 'No certificate PDFs could be generated. Ensure recipients have valid certificate templates assigned.', 'certificate-generator' ),
		'zip_failed' => __( 'ZIP file creation failed. Please try again.', 'certificate-generator' ),
		'bad_part'   => __( 'Invalid download part requested. Please try again from the preview.', 'certificate-generator' ),
	);
	?>
	<div class="wrap">
		<?php
		certificate_generator_ui_page_header(
			__( 'Download Certificates', 'certificate-generator' ),
			__( 'Filter students, teachers or schools, preview matches, then download individual PDFs or bulk ZIPs of all certificates.', 'certificate-generator' )
		);
		if ( $error && isset( $error_messages[ $error ] ) ) {
			certificate_generator_ui_notice( 'error', esc_html( $error_messages[ $error ] ) );
		}
		?>

		<?php /* ── Filter form (GET) ── */ ?>
		<form method="get" action="">
			<input type="hidden" name="page" value="cg-cert-download">
			<input type="hidden" name="cg_preview" value="1">

			<?php certificate_generator_ui_card_open( __( 'Filter Recipients', 'certificate-generator' ), array( 'icon' => 'filter', 'class' => 'cg-narrow' ) ); ?>

				<table class="form-table cg-dl-filters">
					<tr>
						<th>
							<label for="cg_filter_entity"><?php esc_html_e( 'Certificates For', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<select id="cg_filter_entity" name="filter_entity">
								<?php foreach ( $entities as $key => $entity ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $active['entity'], $key ); ?>>
										<?php echo esc_html( $entity[1] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_event"><?php esc_html_e( 'Event', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<?php if ( empty( $events ) ) : ?>
								<em class="cg-hint"><?php esc_html_e( 'No events found.', 'certificate-generator' ); ?></em>
							<?php else : ?>
								<select id="cg_filter_event" name="filter_event[]" multiple size="4">
									<?php foreach ( $events as $ev ) : ?>
										<option value="<?php echo esc_attr( (string) $ev['value'] ); ?>"
											<?php selected( in_array( (int) $ev['value'], $active['events'], true ) ); ?>>
											<?php echo esc_html( $ev['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Empty = all events.', 'certificate-generator' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_email"><?php esc_html_e( 'Email', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<input type="text" id="cg_filter_email" name="filter_email"
								value="<?php echo esc_attr( $active['email_search'] ); ?>"
								placeholder="<?php esc_attr_e( 'e.g. gmail.com', 'certificate-generator' ); ?>">
							<p class="description"><?php esc_html_e( 'Partial match — leave empty for all.', 'certificate-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_school"><?php esc_html_e( 'School', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<?php if ( empty( $schools ) ) : ?>
								<em class="cg-hint"><?php esc_html_e( 'No schools found.', 'certificate-generator' ); ?></em>
							<?php else : ?>
								<select id="cg_filter_school" name="filter_school[]" multiple size="5">
									<?php foreach ( $schools as $s ) : ?>
										<option value="<?php echo esc_attr( $s ); ?>"
											<?php selected( in_array( $s, $active['schools'], true ) ); ?>>
											<?php echo esc_html( $s ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Ctrl/Cmd+click for multiple. Empty = all schools.', 'certificate-generator' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_cert_type"><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<?php if ( empty( $cert_types ) ) : ?>
								<em class="cg-hint"><?php esc_html_e( 'No certificate types found.', 'certificate-generator' ); ?></em>
							<?php else : ?>
								<select id="cg_filter_cert_type" name="filter_cert_type[]" multiple size="4">
									<?php foreach ( $cert_types as $c ) : ?>
										<option value="<?php echo esc_attr( $c ); ?>"
											<?php selected( in_array( $c, $active['certificate_types'], true ) ); ?>>
											<?php echo esc_html( $c ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Empty = all types.', 'certificate-generator' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_year"><?php esc_html_e( 'Year', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<?php if ( empty( $years ) ) : ?>
								<em class="cg-hint"><?php esc_html_e( 'No years found.', 'certificate-generator' ); ?></em>
							<?php else : ?>
								<select id="cg_filter_year" name="filter_year[]" multiple size="3">
									<?php foreach ( $years as $y ) : ?>
										<option value="<?php echo esc_attr( $y ); ?>"
											<?php selected( in_array( (int) $y, $active['year'], true ) ); ?>>
											<?php echo esc_html( $y ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Empty = all years.', 'certificate-generator' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_date_from"><?php esc_html_e( 'Issue Date From', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<input type="date" id="cg_filter_date_from" name="filter_date_from"
								value="<?php echo esc_attr( $active['date_from'] ); ?>">
						</td>
					</tr>
					<tr>
						<th>
							<label for="cg_filter_date_to"><?php esc_html_e( 'Issue Date To', 'certificate-generator' ); ?></label>
						</th>
						<td>
							<input type="date" id="cg_filter_date_to" name="filter_date_to"
								value="<?php echo esc_attr( $active['date_to'] ); ?>">
						</td>
					</tr>
				</table>

				<div class="cg-actions">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Preview', 'certificate-generator' ); ?>
					</button>
					<?php if ( $previewed ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=cg-cert-download' ) ); ?>" class="button">
							<?php esc_html_e( 'Clear Filters', 'certificate-generator' ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php certificate_generator_ui_card_close(); ?>
		</form>

		<?php if ( $previewed ) : ?>

			<?php
			certificate_generator_ui_card_open(
				/* translators: %d: number of recipients */
				sprintf( __( 'Results: %d recipient(s) found', 'certificate-generator' ), $total ),
				array( 'icon' => 'groups' )
			);
			?>

				<?php if ( $plan['num_parts'] > 1 ) : ?>
					<p class="cg-hint">
						<?php
						printf(
							/* translators: %1$d: preview count, %2$d: total, %3$d: num parts */
							esc_html__( 'Showing first %1$d of %2$d total recipients. Use the %3$d download buttons below to get all certificates.', 'certificate-generator' ),
							count( $rows ),
							(int) $total,
							(int) $plan['num_parts']
						);
						?>
					</p>
				<?php endif; ?>

				<?php if ( empty( $rows ) ) : ?>
					<?php echo certificate_generator_ui_empty( __( 'No recipients match the selected filters. Widen the filters and preview again.', 'certificate-generator' ), admin_url( 'admin.php?page=cg-cert-download' ), __( 'Clear Filters', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>

				<?php else : ?>

					<?php /* ZIP download form — one form, multiple submit buttons share the same nonce + filters */ ?>
					<form method="post" id="cg-zip-form" data-cg-busy action="<?php echo esc_url( admin_url( 'admin.php?page=cg-cert-download' ) ); ?>">
						<?php wp_nonce_field( 'cg_admin_cert_zip', '_wpnonce_cg_zip' ); ?>
						<input type="hidden" name="cg_download_zip" value="1">

						<?php /* Re-pass active filters as hidden inputs */ ?>
						<input type="hidden" name="filter_entity" value="<?php echo esc_attr( $active['entity'] ); ?>">
						<?php foreach ( $active['events'] as $ev ) : ?>
							<input type="hidden" name="filter_event[]" value="<?php echo esc_attr( (string) $ev ); ?>">
						<?php endforeach; ?>
						<?php foreach ( $active['schools'] as $s ) : ?>
							<input type="hidden" name="filter_school[]" value="<?php echo esc_attr( $s ); ?>">
						<?php endforeach; ?>
						<?php foreach ( $active['certificate_types'] as $c ) : ?>
							<input type="hidden" name="filter_cert_type[]" value="<?php echo esc_attr( $c ); ?>">
						<?php endforeach; ?>
						<?php foreach ( $active['year'] as $y ) : ?>
							<input type="hidden" name="filter_year[]" value="<?php echo esc_attr( (string) $y ); ?>">
						<?php endforeach; ?>
						<?php if ( $active['date_from'] ) : ?>
							<input type="hidden" name="filter_date_from" value="<?php echo esc_attr( $active['date_from'] ); ?>">
						<?php endif; ?>
						<?php if ( $active['date_to'] ) : ?>
							<input type="hidden" name="filter_date_to" value="<?php echo esc_attr( $active['date_to'] ); ?>">
						<?php endif; ?>
						<?php if ( $active['email_search'] ) : ?>
							<input type="hidden" name="filter_email" value="<?php echo esc_attr( $active['email_search'] ); ?>">
						<?php endif; ?>

						<?php /* ── Download buttons ── */ ?>
						<div class="cg-zip-box">
							<?php if ( $plan['num_parts'] <= 1 ) : ?>
								<button type="submit" name="cg_zip_part" value="0" class="button button-primary cg-zip-btn">
									&#x2B07; <?php
									printf(
										/* translators: %d: number of certificates */
										esc_html__( 'Download All %d Certificates as ZIP', 'certificate-generator' ),
										(int) $total
									);
									?>
								</button>
								<span class="cg-hint">
									<?php esc_html_e( 'Includes manifest.csv for reference.', 'certificate-generator' ); ?>
								</span>
							<?php else : ?>
								<p>
									<?php
									printf(
										/* translators: %1$d: total, %2$d: num parts, %3$d: part size */
										esc_html__( '%1$d certificates split into %2$d downloads (%3$d per part). Each ZIP includes a manifest.csv.', 'certificate-generator' ),
										(int) $total,
										(int) $plan['num_parts'],
										(int) $part_size
									);
									?>
								</p>
								<?php for ( $i = 0; $i < $plan['num_parts']; $i++ ) :
									$row_start = ( $i * $part_size ) + 1;
									$row_end   = min( ( $i + 1 ) * $part_size, $total );
								?>
									<button type="submit" name="cg_zip_part" value="<?php echo esc_attr( (string) $i ); ?>"
										class="button button-primary cg-zip-btn">
										&#x2B07; <?php
										printf(
											/* translators: %1$d: part number, %2$d: total parts, %3$d: row start, %4$d: row end */
											esc_html__( 'Part %1$d of %2$d (%3$d–%4$d)', 'certificate-generator' ),
											(int) ( $i + 1 ),
											(int) $plan['num_parts'],
											(int) $row_start,
											(int) $row_end
										);
										?>
									</button>
								<?php endfor; ?>
							<?php endif; ?>
						</div>

						<?php /* ── Results table (shows part 1 preview) ── */ ?>
						<div class="cg-table-wrap">
						<table class="wp-list-table widefat fixed striped cg-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Name', 'certificate-generator' ); ?></th>
									<th><?php esc_html_e( 'Email', 'certificate-generator' ); ?></th>
									<th><?php esc_html_e( 'School', 'certificate-generator' ); ?></th>
									<th><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></th>
									<th><?php esc_html_e( 'Issue Date', 'certificate-generator' ); ?></th>
									<th class="num"><?php esc_html_e( 'Year', 'certificate-generator' ); ?></th>
									<th class="num"><?php esc_html_e( 'Download PDF', 'certificate-generator' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) :
									$sql_id = (int) ( $row['id'] ?? 0 );
									if ( $sql_id > 0 ) {
										$dl_url = wp_nonce_url(
											add_query_arg(
												array(
													'action'        => 'cg_admin_download_cert',
													'sql_id'        => $sql_id,
													'filter_entity' => $active['entity'],
												),
												admin_url( 'admin.php' )
											),
											'cg_admin_dl_cert_' . $active['entity'] . '_' . $sql_id
										);
									} else {
										$dl_url = '';
									}
								?>
									<tr>
										<td><?php echo esc_html( $row[ $name_col ] ?? '—' ); ?></td>
										<td class="cg-break"><?php echo esc_html( $row['email'] ?? '—' ); ?></td>
										<td><?php echo esc_html( $row['school_name'] ?? '—' ); ?></td>
										<td><?php echo esc_html( $row['certificate_type'] ?? '—' ); ?></td>
										<td><?php echo esc_html( $row['issue_date'] ?? '—' ); ?></td>
										<td class="num"><?php echo esc_html( $row['year'] ?? '—' ); ?></td>
										<td class="num">
											<?php if ( $dl_url ) : ?>
												<a href="<?php echo esc_url( $dl_url ); ?>"
													class="button button-small"
													target="_blank"
													title="<?php esc_attr_e( 'Download individual PDF', 'certificate-generator' ); ?>">
													&#x1F4C4; <?php esc_html_e( 'PDF', 'certificate-generator' ); ?>
												</a>
											<?php else : ?>
												<span class="cg-hint" title="<?php esc_attr_e( 'No SQL record ID — use ZIP download', 'certificate-generator' ); ?>">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						</div>

					</form>

				<?php endif; ?>
			<?php certificate_generator_ui_card_close(); ?>

		<?php endif; ?>
	</div>
	<?php
}
