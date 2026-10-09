<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include required libraries
require_once CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/fpdf.php';

// Handle individual certificate download
add_action( 'init', 'handle_individual_certificate_download' );
function handle_individual_certificate_download() {
	if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'download_certificate' || ! isset( $_GET['student_id'] ) ) {
		return;
	}

	// Auth gate: must be logged in with edit capability
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'You must be logged in to download certificates.', 'Forbidden', array( 'response' => 403 ) );
	}

	$student_row_id = intval( $_GET['student_id'] );

	// Nonce check
	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cg_download_cert_' . $student_row_id ) ) {
		wp_die( 'Security check failed.', 'Forbidden', array( 'response' => 403 ) );
	}

	global $wpdb;
	$students_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
	$student        = $students_table
		? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $students_table WHERE id = %d", $student_row_id ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		: null;

	if ( ! $student ) {
		wp_die( 'Invalid student ID' );
	}

	$post_id      = (int) ( $student['wp_post_id'] ?? $student['id'] ?? 0 );
	$student_name = $student['student_name'];
	$cert_type_dl = $student['certificate_type'];
	$fields       = class_exists( 'CG_Field_Schema' )
		? CG_Field_Schema::get_all_renderable_fields( $cert_type_dl )
		: array( 'student_name', 'school_name', 'issue_date' );

	// Pass the SQL row as student data — same as scs_student_search_shortcode() —
	// so rendering reads straight from the row instead of post meta.
	$file_url = generate_certificate_pdf( $post_id, $fields, $student );

	if ( ! $file_url ) {
		wp_die( 'Failed to generate certificate. Please contact the administrator.' );
	}

	$upload_dir = wp_upload_dir();
	$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_url );

	if ( ! file_exists( $file_path ) ) {
		wp_die( 'Certificate file not found. Please contact the administrator.' );
	}

	$filename = function_exists( 'cg_certificate_pdf_filename' )
		? cg_certificate_pdf_filename( $student_name, $cert_type_dl, (string) $student['id'] )
		: sanitize_file_name( $student_name . '_' . $student['id'] . '_certificate.pdf' );

	header( 'Content-Type: application/pdf' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $file_path ) );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- stream a large PDF to the browser without loading it into memory
	exit;
}

// Handle individual certificate PNG download — same auth/nonce/lookup pattern
// as handle_individual_certificate_download(), generated on-demand (not persisted).
add_action( 'init', 'handle_individual_certificate_png_download' );
function handle_individual_certificate_png_download() {
	if ( ! isset( $_GET['action'] ) || $_GET['action'] !== 'download_certificate_png' || ! isset( $_GET['student_id'] ) ) {
		return;
	}

	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		wp_die( 'You must be logged in to download certificates.', 'Forbidden', array( 'response' => 403 ) );
	}

	$student_row_id = intval( $_GET['student_id'] );

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cg_download_cert_' . $student_row_id ) ) {
		wp_die( 'Security check failed.', 'Forbidden', array( 'response' => 403 ) );
	}

	global $wpdb;
	$students_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
	$student        = $students_table
		? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $students_table WHERE id = %d", $student_row_id ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		: null;

	if ( ! $student ) {
		wp_die( 'Invalid student ID' );
	}

	$post_id      = (int) ( $student['wp_post_id'] ?? $student['id'] ?? 0 );
	$student_name = $student['student_name'];
	$cert_type_dl = $student['certificate_type'];
	$fields       = class_exists( 'CG_Field_Schema' )
		? CG_Field_Schema::get_all_renderable_fields( $cert_type_dl )
		: array( 'student_name', 'school_name', 'issue_date' );

	$file_url = \CertificateGenerator\Services\PngGenerator::generate( $post_id, $fields, $student );

	if ( ! $file_url ) {
		wp_die( 'Failed to generate certificate PNG. Please contact the administrator.' );
	}

	$upload_dir = wp_upload_dir();
	$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_url );

	if ( ! file_exists( $file_path ) ) {
		wp_die( 'Certificate file not found. Please contact the administrator.' );
	}

	$filename = sanitize_file_name( $student_name . '_' . $student['id'] . '_certificate.png' );

	header( 'Content-Type: image/png' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	header( 'Content-Length: ' . filesize( $file_path ) );
	header( 'Cache-Control: no-cache, must-revalidate' );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );
	readfile( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- stream a large PDF to the browser without loading it into memory
	exit;
}
