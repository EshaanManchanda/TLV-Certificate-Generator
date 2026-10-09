<?php
declare(strict_types=1);

namespace CertificateGenerator\Interfaces;

/**
 * Contract for ZIP certificate archive builders.
 */
interface ZipServiceInterface {

	/**
	 * Create a ZIP archive from a list of certificate file descriptors.
	 *
	 * @param array  $certificates_data Array of ['path' => string, 'filename' => string].
	 * @param string $recipient         Recipient email or name — used to build the ZIP filename.
	 * @param array  $args              'private' => true for an admin-only ZIP with no public URL.
	 * @return array{zip_path:string,zip_url:string,certificate_count:int,failed_count:int,failed_files:array}|false
	 */
	public function create( array $certificates_data, string $recipient = '', array $args = array() ): array|false;
}
