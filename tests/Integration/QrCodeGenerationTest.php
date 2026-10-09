<?php
/**
 * Regression test for the phpqrcode -> endroid/qr-code swap: both QR wrapper
 * classes must still produce a valid, non-empty PNG file for a given data
 * string. See includes/Services/qr-generator.php and src/Services/QRCodeService.php.
 */
class QrCodeGenerationTest extends WP_UnitTestCase {

	public function test_legacy_generator_produces_a_valid_png(): void {
		$path = CG_QR_Code_Generator::get_instance()->generate_qr_image( 'https://example.test/verify/ABC123', 200, 'L' );

		$this->assertNotFalse( $path, 'QR generation should not fail' );
		$this->assertFileExists( $path );
		$this->assertGreaterThan( 100, filesize( $path ) );
		$this->assertSame( 'image/png', mime_content_type( $path ) );

		wp_delete_file( $path );
	}

	public function test_service_class_produces_a_valid_png(): void {
		$service = new \CertificateGenerator\Services\QRCodeService();
		$path    = $service->generate_image( 'https://example.test/verify/ABC123', 200, 'M' );

		$this->assertFileExists( $path );
		$this->assertGreaterThan( 100, filesize( $path ) );
		$this->assertSame( 'image/png', mime_content_type( $path ) );

		wp_delete_file( $path );
	}
}
