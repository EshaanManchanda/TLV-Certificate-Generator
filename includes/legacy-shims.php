<?php
/**
 * Legacy Shims — Certificate Generator v8
 *
 * This file is the ANTI-CORRUPTION BOUNDARY for v8.
 *
 *   ✅ Allowed: thin one-line wrappers that delegate to the new src/ service layer.
 *   ❌ Forbidden: new features, new business logic, new DB queries.
 *
 * These functions are @deprecated v8.
 * They survive the entire v8 release line to keep ~17 call sites untouched.
 * They will be DELETED in v9 once all callers have been updated.
 *
 * When a phase flag is flipped ON, the shim routes to the new service.
 * When a flag is OFF (or the flag constant is not defined), the shim calls the
 * legacy function that still lives in includes/Services/certificate-search.php or
 * includes/Email/functions.php. This means ZERO behavior change until the flag flips.
 *
 * See docs/compatibility.md for the full old→new mapping table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Phase 1: PDF shims ────────────────────────────────────────────────────────
// When CG_USE_NEW_PDF=true, certificate-search.php skips defining the public
// wrappers, and this block provides them — routing through PdfGenerator.
// When CG_USE_NEW_PDF=false (default), certificate-search.php provides the
// functions directly and this block is skipped.
//
// certificate_generator_generate_certificate_pdf_email is NOT overridden here — it already calls
// certificate_generator_generate_certificate_pdf() which is shimmed below, so it inherits the new path.

if ( class_exists( '\CertificateGenerator\Core\Config' )
	&& \CertificateGenerator\Core\Config::flag( 'CG_USE_NEW_PDF' )
	&& ! function_exists( 'certificate_generator_generate_certificate_pdf' ) ) {

	/**
	 * @deprecated v8 Use \CertificateGenerator\Services\PdfGenerator::make() instead.
	 */
	function certificate_generator_generate_certificate_pdf( $post_id, $fields = array(), $student_data = null ) {
		return \CertificateGenerator\Services\PdfGenerator::make(
			(int) $post_id,
			(array) $fields,
			is_array( $student_data ) ? $student_data : null
		);
	}

	/**
	 * @deprecated v8 Use \CertificateGenerator\Services\PdfGenerator::makeFromArray() instead.
	 */
	function certificate_generator_generate_certificate_pdf_with_data( $post_data ) {
		return \CertificateGenerator\Services\PdfGenerator::makeFromArray( (array) $post_data );
	}
}

// ── Phase 2: ZIP shims ────────────────────────────────────────────────────────
// When CG_USE_NEW_ZIP=true, Email/functions.php skips defining the public
// wrapper and this block provides it, routing through ZipService.
// When CG_USE_NEW_ZIP=false (default), Email/functions.php provides the wrapper
// directly and this block is skipped.

if ( class_exists( '\CertificateGenerator\Core\Config' )
	&& \CertificateGenerator\Core\Config::flag( 'CG_USE_NEW_ZIP' )
	&& ! function_exists( 'certificate_generator_create_zip_for_email' ) ) {

	/**
	 * @deprecated v8 Use \CertificateGenerator\Services\ZipService::make() instead.
	 */
	function certificate_generator_create_zip_for_email( $certificates_data, $recipient_email, array $args = array() ) {
		return \CertificateGenerator\Services\ZipService::make(
			(array) $certificates_data,
			(string) $recipient_email,
			$args
		);
	}
}

// ── Phase 4: Email shims ─────────────────────────────────────────────────────
// certificate_generator_send_email() is already delegated from
// src/Services/EmailService::sendById() (Phase-1 bug-fix). Once CG_USE_EVENTS
// is flipped in Phase 4, the send funnel fires do_action('certificate_generator_email_sent').
// No shim override needed here — EmailService is the shim.

// ── Phases 1–2 complete — PDF + ZIP shims active above ────────────────────────
// Phases 3–4 shims will be added below as each phase completes.
