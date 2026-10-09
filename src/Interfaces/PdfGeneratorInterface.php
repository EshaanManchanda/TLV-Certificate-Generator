<?php
declare(strict_types=1);

namespace CertificateGenerator\Interfaces;

/**
 * Contract for PDF certificate generators.
 *
 * Implemented by PdfGenerator (Phase 1) and any future strategy-based
 * generators (Olympiad/Award/Participation — Phase 7).
 */
interface PdfGeneratorInterface {

	/**
	 * Generate a certificate PDF by post ID / SQL row.
	 *
	 * @param int        $post_id      WP post ID of the entity (0 when $student_data supplied).
	 * @param array      $fields       Field names to render.
	 * @param array|null $student_data Pre-fetched SQL row from wp_certificate_generator.
	 * @return string|false  PDF URL on success, false on failure.
	 */
	public function create( int $post_id, array $fields = array(), ?array $student_data = null ): string|false;

	/**
	 * Generate a certificate PDF from a pre-built data array.
	 *
	 * @param array $post_data  Certificate data incl. certificate_type, field values, etc.
	 * @return string|false  PDF URL on success, false on failure.
	 */
	public function createFromArray( array $post_data ): string|false;
}
