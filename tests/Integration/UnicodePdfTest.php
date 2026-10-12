<?php
/**
 * Non-Latin-1 names (7.8.0): certificates whose text FPDF can't draw (Hindi, Tamil, Urdu, Ł, …)
 * render through mPDF with shaping (src/Services/MpdfCanvas.php); Latin-1 certificates stay on FPDF.
 * Before, every field was converted to ISO-8859-1, so these printed as "????".
 */

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Services\MpdfCanvas;

class UnicodePdfTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload               = wp_upload_dir();
		$dir                  = $upload['basedir'] . '/cg-phpunit-unicode';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload['baseurl'] . '/cg-phpunit-unicode/template.png';
		$im                   = imagecreatetruecolor( 800, 566 );
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
			CustomTables::instance()->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Unicode',
				'certificate_type' => 'PHPUnitUnicode',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'font_style'       => 'helvetica',
				'font_size'        => 24,
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 148, '1_position_y' => 100, '1_width' => 200 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
	}

	private function pdf_for( string $name ): string {
		global $wpdb;
		$table = CustomTables::instance()->get_table( 'students' );
		$wpdb->insert(
			$table,
			array(
				'student_name'     => $name,
				'email'            => md5( $name ) . '@example.test',
				'school_name'      => 'DPS',
				'certificate_type' => 'PHPUnitUnicode',
				'issue_date'       => '2026-01-15',
			)
		);
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		$path = certificate_generator_generate_pdf_from_row( $row );
		$this->assertNotEmpty( $path, "No PDF for $name" );
		return (string) file_get_contents( $path );
	}

	public function test_detection(): void {
		foreach ( array( 'श्रीमती क्षत्रिय', 'தமிழ்செல்வி', 'محمد علی خان', 'Łukasz', 'O’Brien', 'Иван' ) as $s ) {
			$this->assertTrue( MpdfCanvas::needed_for( array( $s ) ), $s );
		}
		$this->assertFalse( MpdfCanvas::needed_for( array( 'José Müller', 'Asha Rao', 42 ) ) );
		$this->assertTrue( MpdfCanvas::needs_shaping( array( 'Ravi रवि' ) ) );
		$this->assertTrue( MpdfCanvas::needs_shaping( array( 'محمد' ) ) );
		$this->assertFalse( MpdfCanvas::needs_shaping( array( 'Łukasz', 'Иван' ) ), 'GD draws these fine' );
	}

	public function test_latin_certificate_stays_on_fpdf(): void {
		$pdf = $this->pdf_for( 'José Müller' );
		$this->assertStringContainsString( 'FPDF', $pdf );
		$this->assertStringNotContainsString( 'MPDFAA+', $pdf );
	}

	public function test_hindi_certificate_renders_with_mpdf_and_an_indic_font(): void {
		$pdf = $this->pdf_for( 'श्रीमती क्षत्रिय' );
		$this->assertMatchesRegularExpression( '#/BaseFont /MPDFA[A-Z]\+FreeSerif#', $pdf ); // mPDF's font subset
	}

	public function test_mixed_name_embeds_the_field_font_and_the_script_font(): void {
		$pdf   = $this->pdf_for( 'Ravi रवि Kumar' );
		preg_match_all( '#/BaseFont /[A-Z]{6}\+([A-Za-z0-9-]+)#', $pdf, $m );
		$fonts = array_unique( $m[1] );
		$this->assertContains( 'FreeSerif', $fonts );
		$this->assertContains( 'Arimo-Regular', $fonts, 'Latin runs keep the template font (Helvetica → its bundled twin Arimo): ' . implode( ', ', $fonts ) );
	}

	public function test_urdu_tamil_telugu_kannada_oriya_render(): void {
		foreach ( array( 'محمد علی خان', 'தமிழ்செல்வி', 'శ్రీనివాస్', 'ಶ್ರೀನಿವಾಸ', 'ପ୍ରମାଣପତ୍ର' ) as $name ) {
			$this->assertStringContainsString( 'MPDFAA+', $this->pdf_for( $name ), $name );
		}
	}

	public function test_widths_are_shaped(): void {
		$canvas = new MpdfCanvas( 'L', 'mm', 'A4' );
		$canvas->AddPage();
		$canvas->use_font( 'arial', 24 );
		// क्ष is one conjunct glyph: narrower than its three code points drawn one by one would be.
		$this->assertGreaterThan( 0.0, $canvas->GetStringWidth( 'क्षत्रिय' ) );
		$this->assertEqualsWithDelta( $canvas->GetStringWidth( 'Ravi ' ) + $canvas->GetStringWidth( 'रवि' ), $canvas->GetStringWidth( 'Ravi रवि' ), 0.5 );
	}

	public function test_core_fonts_have_a_ttf_for_png_and_mpdf(): void {
		$fm = CertificateGenerator_FontManager::getInstance();
		foreach ( array( 'helvetica', 'helveticab', 'times', 'courier', 'opensans' ) as $font ) {
			$this->assertNotNull( $fm->get_ttf_path_for_gd( $font ), "$font has no TTF, so PNG downloads would skip its text" );
		}
		$this->assertStringContainsString( 'times_new_roman', (string) $fm->get_ttf_path_for_gd( 'times' ) );
	}

	public function test_pdf_text_keeps_utf8_for_unicode_engines(): void {
		$canvas = new MpdfCanvas( 'L', 'mm', 'A4' );
		$this->assertSame( 'Łukasz', certificate_generator_pdf_text( $canvas, 'Łukasz' ) );
		$this->assertSame( "Jos\xE9", certificate_generator_pdf_text( new FPDF(), 'José' ) );
	}

	public function test_png_download_of_a_hindi_certificate_is_the_pdf(): void {
		global $wpdb;
		$table = CustomTables::instance()->get_table( 'students' );
		$wpdb->insert(
			$table,
			array(
				'student_name'     => 'श्रीमती क्षत्रिय',
				'email'            => 'png@example.test',
				'school_name'      => 'DPS',
				'certificate_type' => 'PHPUnitUnicode',
				'issue_date'       => '2026-01-15',
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		$url = \CertificateGenerator\Services\PngGenerator::generate( (int) $row['id'], array( 'student_name' ), $row );
		$this->assertIsString( $url );
		$this->assertStringEndsWith( '.pdf', $url );
	}
}
