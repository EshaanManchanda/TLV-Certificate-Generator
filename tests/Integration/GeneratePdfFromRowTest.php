<?php
/**
 * Regression test for the "wrong certificate in bulk-send ZIP" bug:
 * certificate_generator_generate_pdf_from_row() used to re-query the entity table by
 * `email + certificate_type LIMIT 1` instead of using the row it was given,
 * so multiple students sharing an email + certificate_type (e.g. siblings)
 * all collapsed onto whichever row LIMIT 1 happened to return. See
 * includes/Email/functions.php:80-108.
 */
class GeneratePdfFromRowTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;
	private array $student_ids = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		$upload_dir          = wp_upload_dir();
		$dir                 = $upload_dir['basedir'] . '/cg-phpunit-test';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-test/template.png';

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

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		// A published template for certificate_type "PHPUnitCert".
		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Test Template',
				'certificate_type' => 'PHPUnitCert',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode(
					array(
						'1_position_x' => 50,
						'1_position_y' => 50,
					)
				),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		// Three siblings: same email, same certificate_type, no linked CPT post
		// (wp_post_id NULL) — the exact shape that used to collapse.
		$siblings = array( 'GOURI SHARMA', 'NAVYA SHARMA', 'ARJUN SHARMA' );
		foreach ( $siblings as $name ) {
			$wpdb->insert(
				$tables->get_table( 'students' ),
				array(
					'wp_post_id'       => null,
					'student_name'     => $name,
					'email'            => 'siblings@example.test',
					'certificate_type' => 'PHPUnitCert',
					'issue_date'       => current_time( 'Y-m-d' ),
					'created_at'       => current_time( 'mysql' ),
					'updated_at'       => current_time( 'mysql' ),
				)
			);
			$this->student_ids[ $name ] = $wpdb->insert_id;
		}
	}

	/**
	 * The core regression: generating a PDF for each sibling's own row must
	 * produce a distinct file whose name is keyed by that row's own id —
	 * never all resolving to the same row (old bug) or all overwriting a
	 * shared certificate_0.pdf (old wp_post_id=0 bug).
	 */
	public function test_each_sibling_gets_its_own_pdf_file(): void {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );

		$paths = array();
		foreach ( $this->student_ids as $name => $id ) {
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ),
				ARRAY_A
			);

			$path = certificate_generator_generate_pdf_from_row( $row );

			$this->assertNotEmpty( $path, "PDF generation failed for $name (id=$id)" );
			$this->assertFileExists( $path );
			$this->assertSame(
				certificate_generator_certificate_file_stem( 'students_row_' . $id ) . '.pdf',
				basename( $path ),
				"Row id=$id ($name) must produce its own certificate_students_row_{$id}.pdf, not share another sibling's file"
			);

			$paths[ $name ] = $path;
		}

		$this->assertCount(
			3,
			array_unique( $paths ),
			'All three siblings must resolve to distinct on-disk PDF paths'
		);
	}

	/**
	 * If pdftotext is available, go one step further and confirm the actual
	 * rendered content matches the intended student — this is the exact
	 * failure mode the user reported ("5 certificates have Gouri's name").
	 */
	public function test_each_pdf_contains_the_correct_students_name(): void {
		exec( 'pdftotext -v 2>&1', $out, $code_unused );
		if ( ! shell_exec( 'where pdftotext 2>NUL' ) && ! shell_exec( 'which pdftotext 2>/dev/null' ) ) {
			$this->markTestSkipped( 'pdftotext not available on this machine.' );
		}

		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );

		foreach ( $this->student_ids as $name => $id ) {
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
			$path = certificate_generator_generate_pdf_from_row( $row );

			$text = shell_exec( 'pdftotext ' . escapeshellarg( $path ) . ' - 2>NUL' );

			$this->assertStringContainsString(
				$name,
				trim( (string) $text ),
				"certificate_{$id}.pdf must render \"$name\", not another sibling's name"
			);
		}
	}

	public function test_post_id_falls_back_to_row_id_when_wp_post_id_is_null(): void {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$id    = reset( $this->student_ids );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );

		$this->assertNull( $row['wp_post_id'] );

		$path = certificate_generator_generate_pdf_from_row( $row );

		$this->assertSame( certificate_generator_certificate_file_stem( 'students_row_' . $id ) . '.pdf', basename( $path ) );
	}
}
