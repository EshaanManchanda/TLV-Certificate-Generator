<?php
/**
 * An issued certificate keeps the layout it was issued with: later template edits don't
 * re-render it, a reissue does (same serial, revision + 1, "reissued" event), and the
 * public verify answer then carries the correction date.
 */
class TemplateSnapshotTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;
	private int $template_id = 0;
	private int $renders     = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] . '/cg-phpunit-snapshot' );
		self::$template_image = $upload_dir['basedir'] . '/cg-phpunit-snapshot/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-snapshot/template.png';
		$im                   = imagecreatetruecolor( 800, 600 );
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
			$wpdb->prefix . 'cg_certificate_templates',
			array(
				'template_name'    => 'PHPUnit Snapshot Template',
				'certificate_type' => 'PHPUnitSnapshotCert',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$this->template_id = (int) $wpdb->insert_id;
		add_action(
			'certificate_generator_certificate_generated',
			function () {
				++$this->renders;
			}
		);
	}

	public function test_template_edit_does_not_change_issued_certificate_until_reissued(): void {
		global $wpdb;
		$row    = $this->issue( 'Snapshot Student' );
		$serial = (string) $row['serial_number'];
		$this->assertNotSame( '', $serial );
		$this->assertNotNull( certificate_generator_template_snapshot( $serial, 'PHPUnitSnapshotCert' ) );
		$this->assertNull( certificate_generator_template_snapshot( $serial, 'SomeOtherType' ) );

		// Move the field on the template: the issued certificate keeps its layout (cache hit, no render).
		$wpdb->update( $wpdb->prefix . 'cg_certificate_templates', array( 'extra_fields' => wp_json_encode( array( '1_position_x' => 120, '1_position_y' => 90 ) ) ), array( 'id' => $this->template_id ) );
		$before = $this->renders;
		$this->render( $row );
		$this->assertSame( $before, $this->renders, 'template edit re-rendered an issued certificate' );

		// Reissue: new snapshot, revision 1, event, and the next download renders.
		$this->assertSame( 1, certificate_generator_reissue_certificate( $serial, 'moved name' ) );
		$this->render( $row );
		$this->assertSame( $before + 1, $this->renders );
		$this->assertSame( '1', (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(revision) FROM {$wpdb->prefix}cg_certificates WHERE serial_number = %s", $serial ) ) );
		$this->assertSame( 'reissued', certificate_generator_get_certificate_events( $serial )[0]['event_type'] );

		$verify = ( new \CertificateGenerator\Services\SerialNumberService() )->verify( certificate_generator_verification_code( $serial ) );
		$this->assertNotSame( '', $verify['data']['corrected_at'] );
	}

	public function test_reissue_unknown_serial_is_an_error(): void {
		$this->assertInstanceOf( WP_Error::class, certificate_generator_reissue_certificate( 'NOPE-404', 'x' ) );
	}

	private function issue( string $name ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cg_students';
		$wpdb->insert(
			$table,
			array(
				'student_name'     => $name,
				'email'            => 'snapshot@example.test',
				'certificate_type' => 'PHPUnitSnapshotCert',
				'issue_date'       => current_time( 'Y-m-d' ),
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->render( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
	}

	private function render( array $row ): void {
		$this->assertNotEmpty( certificate_generator_generate_certificate_pdf( (int) $row['id'], array(), $row ), 'PDF generation failed' );
	}
}
