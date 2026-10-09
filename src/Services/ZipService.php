<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

use CertificateGenerator\Interfaces\ZipServiceInterface;

/**
 * ZIP archive facade — Phase 2.
 *
 * Phase 2: thin coordinator delegating to certificate_generator_create_zip_impl() which lives in
 * includes/Email/functions.php (formerly certificate_generator_create_zip_for_email).
 *
 * Flag-gated (docs/compatibility.md):
 *   CG_USE_NEW_ZIP=false (default) — certificate_generator_create_zip_for_email() runs
 *                                    the impl directly.
 *   CG_USE_NEW_ZIP=true            — legacy-shims.php routes the function through
 *                                    ZipService::make() → this class.
 */
class ZipService implements ZipServiceInterface {

	// ── Instance method (ZipServiceInterface) ─────────────────────────────────

	/**
	 * {@inheritdoc}
	 */
	public function create( array $certificates_data, string $recipient = '', array $args = array() ): array|false {
		if ( ! function_exists( 'certificate_generator_create_zip_impl' ) ) {
			if ( function_exists( 'certificate_generator_create_zip_for_email' ) ) {
				/** @psalm-suppress PossiblyUndefinedFunction */
				return certificate_generator_create_zip_for_email( $certificates_data, $recipient, $args );
			}
			return false;
		}
		/** @psalm-suppress PossiblyUndefinedFunction */
		return certificate_generator_create_zip_impl( $certificates_data, $recipient, $args );
	}

	// ── Static convenience (for procedural shims and inline callers) ──────────

	/**
	 * Static shortcut for create().
	 *
	 * @param array  $certificates_data
	 * @param string $recipient
	 * @param array  $args      See certificate_generator_create_zip_impl() ('private' => true for admin ZIPs).
	 * @return array|false
	 */
	public static function make( array $certificates_data, string $recipient = '', array $args = array() ): array|false {
		return ( new self() )->create( $certificates_data, $recipient, $args );
	}
}
