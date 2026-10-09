<?php
/**
 * - generate_certificate_pdf_with_data() must give each real certificate its own
 *   file (callers store the path as pdf_path; a shared certificate_preview.pdf
 *   meant a later email could attach another student's certificate).
 * - _cg_create_zip_impl() reuses an unchanged ZIP and rebuilds when a PDF changes.
 */
class PdfAndZipReuseTest extends WP_UnitTestCase {

	private static string $template_image;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] . '/cg-phpunit-reuse' );
		self::$template_image = $upload_dir['basedir'] . '/cg-phpunit-reuse/template.png';
		$im = imagecreatetruecolor( 800, 600 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagepng( $im, self::$template_image );
		imagedestroy( $im );
	}

	public static function tear_down_after_class(): void {
		@unlink( self::$template_image );
		@rmdir( dirname( self::$template_image ) );
		parent::tear_down_after_class();
	}

	public function test_real_certificates_get_distinct_files(): void {
		global $wpdb;
		$wpdb->insert(
			\CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Reuse Template',
				'certificate_type' => 'PHPUnitReuseCert',
				'template_url'     => wp_upload_dir()['baseurl'] . '/cg-phpunit-reuse/template.png',
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		$urls = array();
		foreach ( array( 'ASHA RAO', 'RAVI RAO' ) as $name ) {
			$urls[] = generate_certificate_pdf_with_data(
				array(
					'certificate_type' => 'PHPUnitReuseCert',
					'issue_date'       => current_time( 'Y-m-d' ),
					'student_name'     => $name,
				)
			);
		}

		$this->assertNotEmpty( $urls[0] );
		$this->assertNotEmpty( $urls[1] );
		$this->assertNotSame( $urls[0], $urls[1] );
		$this->assertStringNotContainsString( 'certificate_preview', $urls[0] );
	}

	public function test_zip_is_reused_until_a_pdf_changes(): void {
		$dir = cg_certificates_dir();
		$pdf = $dir . '/phpunit-reuse.pdf';
		file_put_contents( $pdf, str_repeat( 'a', 500 ) );
		$data = array( array( 'path' => $pdf, 'filename' => 'a.pdf' ) );

		$first = _cg_create_zip_impl( $data, 'reuse@example.test' );
		sleep( 1 ); // without reuse, a rebuild here would get a new timestamped name
		$second = _cg_create_zip_impl( $data, 'reuse@example.test' );
		$this->assertSame( $first['zip_path'], $second['zip_path'] );

		file_put_contents( $pdf, str_repeat( 'b', 600 ) ); // regenerated PDF: new size
		sleep( 1 ); // the rebuilt ZIP's name is timestamped to the second
		$third = _cg_create_zip_impl( $data, 'reuse@example.test' );
		$this->assertNotSame( $first['zip_path'], $third['zip_path'] );

		foreach ( array( $pdf, $first['zip_path'], $third['zip_path'] ) as $f ) {
			@unlink( $f );
		}
	}
}
