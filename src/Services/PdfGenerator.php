<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

use CertificateGenerator\Interfaces\PdfGeneratorInterface;

/**
 * PDF generation facade — Phase 1.
 *
 * Phase 1 is a thin coordinator: it delegates to the procedural implementations
 * (_cg_generate_pdf_impl, _cg_generate_pdf_with_data_impl) that live in
 * includes/Services/certificate-search.php.  Those functions contain the actual
 * FPDF rendering logic, template lookup, QR/serial integration, and file output.
 *
 * Future phases will progressively migrate the body here and remove the delegation.
 *
 * Flag-gated routing (docs/compatibility.md):
 *   CG_USE_NEW_PDF=false (default) — generate_certificate_pdf() runs the impl directly.
 *   CG_USE_NEW_PDF=true            — legacy-shims.php routes generate_certificate_pdf()
 *                                    through PdfGenerator::make() → this class.
 *
 * DI usage (Phase 6+):
 *   $generator = $container->make(PdfGenerator::class);
 *   $url = $generator->create($post_id, $fields);
 *
 * Static convenience (from procedural shims):
 *   $url = PdfGenerator::make($post_id, $fields);
 */
class PdfGenerator implements PdfGeneratorInterface {

	// ── Instance methods (PdfGeneratorInterface) ──────────────────────────────

	/**
	 * Generate a certificate PDF by post ID.
	 *
	 * {@inheritdoc}
	 */
	public function create( int $post_id, array $fields = array(), ?array $student_data = null ): string|false {
		if ( ! function_exists( '_cg_generate_pdf_impl' ) ) {
			// certificate-search.php not yet loaded — fall back to the public function.
			if ( function_exists( 'generate_certificate_pdf' ) ) {
				/** @psalm-suppress PossiblyUndefinedFunction */
				return generate_certificate_pdf( $post_id, $fields, $student_data );
			}
			return false;
		}
		/** @psalm-suppress PossiblyUndefinedFunction */
		return _cg_generate_pdf_impl( $post_id, $fields, $student_data );
	}

	/**
	 * Generate a certificate PDF from a data array.
	 *
	 * {@inheritdoc}
	 */
	public function createFromArray( array $post_data ): string|false {
		if ( ! function_exists( '_cg_generate_pdf_with_data_impl' ) ) {
			if ( function_exists( 'generate_certificate_pdf_with_data' ) ) {
				/** @psalm-suppress PossiblyUndefinedFunction */
				return generate_certificate_pdf_with_data( $post_data );
			}
			return false;
		}
		/** @psalm-suppress PossiblyUndefinedFunction */
		return _cg_generate_pdf_with_data_impl( $post_data );
	}

	// ── Static convenience (for use from procedural shims and legacy code) ────

	/**
	 * Static shortcut for create() — convenience for procedural call sites.
	 *
	 * @param int        $post_id
	 * @param array      $fields
	 * @param array|null $student_data
	 * @return string|false
	 */
	public static function make( int $post_id, array $fields = array(), ?array $student_data = null ): string|false {
		return ( new self() )->create( $post_id, $fields, $student_data );
	}

	/**
	 * Static shortcut for createFromArray().
	 *
	 * @param array $post_data
	 * @return string|false
	 */
	public static function makeFromArray( array $post_data ): string|false {
		return ( new self() )->createFromArray( $post_data );
	}
}
