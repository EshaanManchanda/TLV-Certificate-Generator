<?php
/**
 * [certificate_generator_student_search] builds no ZIP while rendering the page; "Download All" (shown for
 * more than 3 certificates, as before) builds it on click via admin-post.php.
 */
class StudentZipEndpointTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] . '/cg-phpunit-studentzip' );
		self::$template_image = $upload_dir['basedir'] . '/cg-phpunit-studentzip/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-studentzip/template.png';
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

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->insert(
			\CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Student ZIP Template',
				'certificate_type' => 'PHPUnitStudentZip',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		set_transient( 'cg_usage_report_lock', 1, HOUR_IN_SECONDS );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function seed( string $email, int $count ): void {
		global $wpdb;
		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				\CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' ),
				array(
					'student_name'     => "Zip Student $i",
					'email'            => $email,
					'school_name'      => 'Zip School',
					'certificate_type' => 'PHPUnitStudentZip',
					'issue_date'       => current_time( 'Y-m-d' ),
					'serial_number'    => sprintf( 'SZ-%s-%d', md5( $email ), $i ),
				)
			);
		}
	}

	private function zips(): array {
		return glob( certificate_generator_certificates_dir() . '/certificates_*.zip' ) ?: array();
	}

	public function test_page_render_builds_no_zip_and_links_to_endpoint(): void {
		$this->seed( 'four@studentzip.test', 4 );
		$before = $this->zips();

		$_GET['student_email'] = 'four@studentzip.test';
		$output = certificate_generator_student_search_shortcode( array() );

		$this->assertSame( $before, $this->zips(), 'Viewing the page must not build a ZIP' );
		$this->assertStringContainsString( 'action=certificate_generator_student_zip', $output );
		$this->assertStringContainsString( 'Download All Certificates (ZIP)', $output );
	}

	public function test_threshold_still_more_than_three(): void {
		$this->seed( 'three@studentzip.test', 3 );
		$_GET['student_email'] = 'three@studentzip.test';
		$output = certificate_generator_student_search_shortcode( array() );
		$this->assertStringNotContainsString( 'action=certificate_generator_student_zip', $output );
		$this->assertSame( 3, substr_count( $output, 'Download Certificate</a>' ) );
	}

	public function test_click_builds_zip_with_every_certificate_and_reuses_it(): void {
		$this->seed( 'click@studentzip.test', 4 );
		$first = certificate_generator_student_zip_for_email( 'click@studentzip.test' );
		$this->assertIsArray( $first );
		$this->assertSame( 4, $first['certificate_count'] );

		$second = certificate_generator_student_zip_for_email( 'click@studentzip.test' );
		$this->assertSame( $first['zip_path'], $second['zip_path'], 'Unchanged certificates reuse the ZIP' );
		@unlink( $first['zip_path'] );
	}

	public function test_unknown_email_gets_no_zip(): void {
		$this->assertFalse( certificate_generator_student_zip_for_email( 'nobody@studentzip.test' ) );
	}

	public function test_endpoint_is_rate_limited(): void {
		$_GET['student_email'] = 'limit@studentzip.test';
		$_GET['_wpnonce']      = wp_create_nonce( 'certificate_generator_student_zip' );
		add_filter( 'certificate_generator_student_zip_rate_limit', '__return_zero' );
		try {
			certificate_generator_handle_student_zip_download();
			$this->fail( 'Expected wp_die' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'Too many', $e->getMessage() );
		} finally {
			remove_filter( 'certificate_generator_student_zip_rate_limit', '__return_zero' );
		}
	}

	public function test_endpoint_rejects_bad_nonce(): void {
		$_GET['student_email'] = 'click@studentzip.test';
		$_GET['_wpnonce']      = 'bad';
		$this->expectException( WPDieException::class );
		certificate_generator_handle_student_zip_download();
	}
}
