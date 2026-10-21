<?php
/**
 * End-to-end regression test for the bulk-send ZIP bug: sends a real
 * certificate_generator_send_email() for a recipient with multiple siblings
 * sharing one certificate_type, and confirms the ZIP attachment contains one
 * correct, distinct PDF per sibling — the exact scenario the user reported
 * ("5 certificates have Gouri's name, 2 Navya's").
 */
class BulkSendZipTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cg-phpunit-zip-test';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-zip-test/template.png';

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

	public function test_bulk_send_zip_contains_one_correct_pdf_per_sibling(): void {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit ZIP Template',
				'certificate_type' => 'PHPUnitZipCert',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		$email    = 'zip-siblings@example.test';
		$siblings = array( 'GOURI SHARMA', 'NAVYA SHARMA' );
		foreach ( $siblings as $name ) {
			$wpdb->insert(
				$tables->get_table( 'students' ),
				array(
					'wp_post_id'       => null,
					'student_name'     => $name,
					'email'            => $email,
					'certificate_type' => 'PHPUnitZipCert',
					'issue_date'       => current_time( 'Y-m-d' ),
					'created_at'       => current_time( 'mysql' ),
					'updated_at'       => current_time( 'mysql' ),
				)
			);
		}

		// The anchor row certificate_generator_send_email() looks up by cg_id.
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			array(
				'student_name'     => $siblings[0],
				'email'            => $email,
				'certificate_type' => 'PHPUnitZipCert',
				'certificate_data' => wp_json_encode( array() ),
				'pdf_path'         => '',
				'generated_via'    => 'bulk',
			)
		);
		$cg_id = $wpdb->insert_id;

		$captured = null;
		add_filter(
			'wp_mail',
			function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args;
			}
		);
		add_filter( 'pre_wp_mail', '__return_true' ); // Don't actually send.

		// A custom HTML template: {zip_link} used to be blanked before the ZIP existed.
		$email_settings                           = (array) get_option( 'certificate_generator_settings_email', array() );
		$email_settings['students_email_message'] = "<table><tr><td>\n<a href=\"{zip_link}\">\nDownload ZIP\n</a> <a href=\"{result_link}\">View</a></td></tr></table>";
		$email_settings['students_email_subject'] = 'Your Student Certificate is Ready';
		update_option( 'certificate_generator_settings_email', $email_settings );

		// {result_link} goes to the page that holds the search shortcode, not a hardcoded /result/.
		$search_page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[certificate_generator_student_search]',
			)
		);
		delete_transient( 'certificate_generator_result_page_url' );

		$sent = certificate_generator_send_email( $cg_id, false );

		$this->assertTrue( $sent, 'certificate_generator_send_email() should report success' );
		$this->assertNotNull( $captured, 'wp_mail should have been called' );
		$this->assertNotEmpty( $captured['attachments'], 'Email must have a ZIP attachment' );

		$zip_path = $captured['attachments'][0];
		$this->assertFileExists( $zip_path );
		$this->assertStringContainsString( 'href="' . esc_url( certificate_generator_certificates_url() . '/' . basename( $zip_path ) ) . '"', $captured['message'], '{zip_link} must become the ZIP URL' );
		$this->assertStringNotContainsString( '<br', $captured['message'], 'An HTML template must not go through wpautop()' );
		$this->assertSame( 'Your Student Certificates are Ready (2)', $captured['subject'] );
		$this->assertStringContainsString( 'href="' . esc_html( add_query_arg( 'student_email', rawurlencode( $email ), get_permalink( $search_page ) ) ) . '"', $captured['message'], '{result_link} must point at the search page' );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) === true );
		$this->assertSame( 2, $zip->numFiles, 'ZIP must contain exactly one PDF per sibling' );

		$names_seen = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$entry_name = $zip->getNameIndex( $i );
			foreach ( $siblings as $name ) {
				if ( str_contains( $entry_name, str_replace( ' ', '-', $name ) ) ) {
					$names_seen[] = $name;
				}
			}
		}
		$zip->close();

		sort( $names_seen );
		$expected = $siblings;
		sort( $expected );
		$this->assertSame( $expected, $names_seen, 'ZIP must have one entry named for each distinct sibling' );
	}
}
