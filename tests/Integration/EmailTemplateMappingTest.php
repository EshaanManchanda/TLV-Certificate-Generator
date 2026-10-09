<?php
/**
 * Regression test for certificate_type → email template mapping
 * (wp_cg_email_templates): certificate_generator_send_email() must use the
 * mapped template's subject when the row's certificate_type has one, and
 * must fall back to the existing per-post-type/global settings when it
 * doesn't. See includes/Email/functions.php.
 */
class EmailTemplateMappingTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cg-phpunit-email-template-test';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-email-template-test/template.png';

		$im    = imagecreatetruecolor( 800, 600 );
		$white = imagecolorallocate( $im, 255, 255, 255 );
		imagefill( $im, 0, 0, $white );
		imagepng( $im, self::$template_image );
		imagedestroy( $im );
	}

	public static function tear_down_after_class(): void {
		if ( self::$template_image && file_exists( self::$template_image ) ) {
			unlink( self::$template_image );
			@rmdir( dirname( self::$template_image ) );
		}
		parent::tear_down_after_class();
	}

	private function seed_student( string $cert_type, string $email, string $name ): void {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'Mapping Test Template',
				'certificate_type' => $cert_type,
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$wpdb->insert(
			$tables->get_table( 'students' ),
			array(
				'wp_post_id'       => null,
				'student_name'     => $name,
				'email'            => $email,
				'certificate_type' => $cert_type,
				'issue_date'       => current_time( 'Y-m-d' ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			array(
				'student_name'     => $name,
				'email'            => $email,
				'certificate_type' => $cert_type,
				'certificate_data' => wp_json_encode( array() ),
				'pdf_path'         => '',
				'generated_via'    => 'bulk',
			)
		);
	}

	private function capture_send( string $email ): ?array {
		$captured = null;
		add_filter(
			'wp_mail',
			function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args;
			}
		);
		add_filter( 'pre_wp_mail', '__return_true' );

		global $wpdb;
		$cg_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}certificate_generator WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) );
		certificate_generator_send_email( $cg_id, false );

		return $captured;
	}

	public function test_mapped_certificate_type_overrides_subject(): void {
		global $wpdb;
		$this->seed_student( 'MappedCertType', 'mapped@example.test', 'Mapped Student' );

		$et_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'email_templates' );
		$wpdb->insert(
			$et_table,
			array(
				'name'               => 'Mapped Template',
				'certificate_type'   => 'MappedCertType',
				'subject'            => 'Mapped Subject Line',
				'title'              => 'Mapped Title',
				'message'            => 'Mapped body {name}',
				'attach_certificate' => 1,
				'created_at'         => current_time( 'mysql' ),
				'updated_at'         => current_time( 'mysql' ),
			)
		);

		$captured = $this->capture_send( 'mapped@example.test' );

		$this->assertNotNull( $captured, 'wp_mail should have been called' );
		$this->assertSame( 'Mapped Subject Line', $captured['subject'], 'Mapped email template subject must be used for a matching certificate_type' );
	}

	public function test_unmapped_certificate_type_falls_back_to_existing_settings(): void {
		$this->seed_student( 'UnmappedCertType', 'unmapped@example.test', 'Unmapped Student' );

		$captured = $this->capture_send( 'unmapped@example.test' );

		$this->assertNotNull( $captured, 'wp_mail should have been called' );
		$this->assertNotSame( 'Mapped Subject Line', $captured['subject'], 'An unmapped certificate_type must not pick up another type\'s mapped subject' );
	}
}
