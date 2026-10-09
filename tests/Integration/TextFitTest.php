<?php
/**
 * Audit B2 / bug 13: long names ran past their field (no shrinking), and a name
 * that wrapped lost its accents ("José" → "Jos?") because wrapped lines were
 * converted to Latin-1 a second time.
 */
class TextFitTest extends WP_UnitTestCase {

	private static string $image;
	private static string $image_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$up              = wp_upload_dir();
		self::$image     = $up['basedir'] . '/cg-phpunit-fit/bg.png';
		self::$image_url = $up['baseurl'] . '/cg-phpunit-fit/bg.png';
		wp_mkdir_p( dirname( self::$image ) );
		$im = imagecreatetruecolor( 800, 566 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagepng( $im, self::$image );
		imagedestroy( $im );
	}

	public static function tear_down_after_class(): void {
		@unlink( self::$image );
		@rmdir( dirname( self::$image ) );
		parent::tear_down_after_class();
	}

	public function test_fit_shrinks_long_text_but_not_below_the_floor(): void {
		$pdf = new FPDF( 'L', 'mm', 'A4' );
		$pdf->AddPage();
		$pdf->SetFont( 'Helvetica', 'B', 24 );

		$this->assertSame( 24.0, certificate_generator_fit_font_size( $pdf, 24.0, 'Asha Rao', 100.0 ), 'short text keeps its size' );

		$pdf->SetFont( 'Helvetica', 'B', 24 );
		$size = certificate_generator_fit_font_size( $pdf, 24.0, 'Venkata Subramanian Lakshminarayanan Iyer', 130.0 );
		$this->assertLessThan( 24.0, $size );
		$this->assertGreaterThan( 14.4, $size, 'fits before reaching the floor' );
		$this->assertLessThanOrEqual( 130.0, $pdf->GetStringWidth( 'Venkata Subramanian Lakshminarayanan Iyer' ) );

		$pdf->SetFont( 'Helvetica', 'B', 24 );
		$this->assertEqualsWithDelta( 14.4, certificate_generator_fit_font_size( $pdf, 24.0, str_repeat( 'Abcdefghij', 6 ), 100.0 ), 0.001, 'stops at 60% and lets the caller wrap' );
	}

	/** Every content stream of an FPDF file, inflated. */
	private function page_streams( string $pdf ): string {
		preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m );
		$out = '';
		foreach ( $m[1] as $raw ) {
			$plain = @gzuncompress( $raw );
			$out  .= false === $plain ? $raw : $plain;
		}
		return $out;
	}

	public function test_wrapped_name_keeps_its_accents_and_is_shrunk(): void {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Fit Template',
				'certificate_type' => 'PHPUnitFit',
				'template_url'     => self::$image_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'font_size'        => 24,
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 148, '1_position_y' => 100, '1_width' => 60 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$wpdb->insert(
			$tables->get_table( 'students' ),
			array(
				'wp_post_id'       => null,
				'student_name'     => 'José Müller Fernández de la Cruz',
				'email'            => 'fit@example.test',
				'certificate_type' => 'PHPUnitFit',
				'issue_date'       => current_time( 'Y-m-d' ),
			)
		);
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $tables->get_table( 'students' ) . ' WHERE id = %d', $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$path = certificate_generator_generate_pdf_from_row( $row );
		$this->assertFileExists( $path );

		$content = $this->page_streams( (string) file_get_contents( $path ) );
		$this->assertStringContainsString( "Jos\xE9", $content, 'é drawn as the Latin-1 byte' );
		$this->assertStringNotContainsString( 'Jos?', $content );
		$this->assertMatchesRegularExpression( '/BT \/F\d+ 14\.40 Tf ET/', $content, 'shrunk to the 60% floor before wrapping' );
	}
}
