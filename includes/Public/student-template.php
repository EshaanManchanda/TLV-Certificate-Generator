<?php
/**
 * Public-facing student certificate profile page.
 *
 * - Overrides the default single template for the 'students' CPT.
 * - Handles on-demand PDF download via ?cg_download_cert=1&id=&nonce=
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Template override ────────────────────────────────────────────────────────

add_filter( 'single_template', 'certificate_generator_student_single_template' );

function certificate_generator_student_single_template( $template ) {
	if ( is_singular( 'students' ) ) {
		$plugin_template = CERTIFICATE_GENERATOR_PATH . 'templates/single-students.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}
	}
	return $template;
}

// ── PDF download handler ─────────────────────────────────────────────────────

add_action( 'template_redirect', 'certificate_generator_handle_cert_download' );

function certificate_generator_handle_cert_download() {
	if ( empty( $_GET['cg_download_cert'] ) ) {
		return;
	}

	$post_id = intval( wp_unslash( $_GET['id'] ?? 0 ) );
	$nonce   = sanitize_text_field( wp_unslash( $_GET['nonce'] ?? '' ) );

	if ( ! $post_id || ! wp_verify_nonce( $nonce, 'cg_download_cert_' . $post_id ) ) {
		wp_die(
			esc_html__( 'This download link has expired. Please reload the page and try again.', 'certificate-generator' ),
			esc_html__( 'Link Expired', 'certificate-generator' ),
			array( 'response' => 403 )
		);
	}

	if ( get_post_type( $post_id ) !== 'students' ) {
		wp_die( esc_html__( 'Invalid request.', 'certificate-generator' ), '', array( 'response' => 400 ) );
	}

	if ( ! function_exists( 'certificate_generator_generate_certificate_pdf_with_data' ) ) {
		wp_die(
			esc_html__( 'Certificate generation is not available.', 'certificate-generator' ),
			'',
			array( 'response' => 500 )
		);
	}

	$certificate_type = get_post_meta( $post_id, 'certificate_type', true );
	if ( empty( $certificate_type ) ) {
		wp_die(
			esc_html__( 'No certificate template is assigned to this student.', 'certificate-generator' ),
			'',
			array( 'response' => 404 )
		);
	}

	$post_data = array(
		'student_name'     => get_post_meta( $post_id, 'student_name', true ),
		'school_name'      => get_post_meta( $post_id, 'school_name', true ),
		'issue_date'       => get_post_meta( $post_id, 'issue_date', true ),
		'certificate_type' => $certificate_type,
		'_preview_post_id' => $post_id,  // gives file a unique name: certificate_preview_{id}.pdf
	);

	// Merge extra fields so they are rendered on the PDF
	if ( class_exists( 'CertificateGenerator_Field_Schema' ) ) {
		foreach ( CertificateGenerator_Field_Schema::get_extra_fields( $certificate_type ) as $slug ) {
			$value = get_post_meta( $post_id, $slug, true );
			if ( $value !== '' && $value !== false ) {
				$post_data[ $slug ] = $value;
			}
		}
	}

	$pdf_url = certificate_generator_generate_certificate_pdf_with_data( $post_data );

	if ( $pdf_url ) {
		nocache_headers();
		wp_redirect( $pdf_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL of a PDF this plugin just generated in uploads; may be on a CDN host
		exit;
	}

	wp_die(
		esc_html__( 'Failed to generate the certificate. Please try again later.', 'certificate-generator' ),
		'',
		array( 'response' => 500 )
	);
}
