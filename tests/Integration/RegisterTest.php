<?php
/**
 * Certificate Register (7.9.0, includes/Admin/register.php): filters, the School Coordinator
 * scope, the CSV (BOM, formula escaping) and the PDF (mPDF, non-Latin names).
 */

class RegisterTest extends WP_UnitTestCase {

	private const TYPE = 'PHPUnitRegister';

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // creates the School Coordinator role when missing
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->issue( 'REG-A1', 'Alpha One', 'Alpha School', '2026-03-10 10:00:00' );
		$this->issue( 'REG-A2', '=HYPERLINK("x")', 'Alpha School', '2026-04-10 10:00:00', array( 'expires_at' => '2020-01-01 00:00:00' ) );
		$this->issue( 'REG-B1', 'अनन्या शर्मा', 'Beta School', '2026-05-10 10:00:00', array( 'revoked_at' => '2026-06-01 00:00:00', 'revoked_reason' => 'Issued in error' ) );
	}

	private function issue( string $serial, string $name, string $school, string $issued, array $extra = array() ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			$extra + array(
				'student_name'      => $name,
				'certificate_data'  => wp_json_encode( array( 'student_name' => $name, 'school_name' => $school ) ),
				'email'             => sanitize_title( $serial ) . '@example.test',
				'serial_number'     => $serial,
				'verification_code' => substr( md5( $serial ), 0, 12 ),
				'certificate_type'  => self::TYPE,
				'issued_at'         => $issued,
			)
		);
	}

	private function rows( array $filters = array() ): array {
		$filters += array( 'type' => self::TYPE, 'school' => '', 'event' => 0, 'from' => '', 'to' => '', 'status' => '' );
		return array_column( certificate_generator_register_rows( $filters ), null, 'serial_number' );
	}

	public function test_filters(): void {
		$this->assertSame( array( 'REG-B1', 'REG-A2', 'REG-A1' ), array_keys( $this->rows() ), 'newest first' );
		$this->assertSame( array( 'REG-A2', 'REG-A1' ), array_keys( $this->rows( array( 'school' => 'Alpha School' ) ) ) );
		$this->assertSame( array( 'REG-A2' ), array_keys( $this->rows( array( 'from' => '2026-04-01', 'to' => '2026-04-30' ) ) ) );
		$this->assertSame( array( 'REG-A1' ), array_keys( $this->rows( array( 'status' => 'valid' ) ) ) );
		$this->assertSame( array( 'REG-A2' ), array_keys( $this->rows( array( 'status' => 'expired' ) ) ) );
		$this->assertSame( array( 'REG-B1' ), array_keys( $this->rows( array( 'status' => 'revoked' ) ) ) );
	}

	public function test_revoked_row_shows_status_and_reason(): void {
		$line = certificate_generator_register_line( $this->rows()['REG-B1'] );
		$this->assertSame( 'Revoked', $line[9] );
		$this->assertSame( 'Issued in error', $line[10] );
		$this->assertSame( 'Beta School', $line[4] );
	}

	public function test_row_without_a_school_shows_none_not_false(): void {
		$this->issue( 'REG-N1', 'No School', 'x', '2026-02-01 10:00:00', array( 'certificate_data' => wp_json_encode( array( 'school_name' => false, 'email' => 'n1@example.test' ) ), 'email' => '' ) );
		$line = certificate_generator_register_line( $this->rows()['REG-N1'] );
		$this->assertSame( '', $line[4] );
		$this->assertSame( 'n1@example.test', $line[3], 'email falls back to the stored data' );
	}

	public function test_coordinator_gets_only_their_school(): void {
		$coordinator = self::factory()->user->create( array( 'role' => 'certificate_generator_school_coordinator' ) );
		update_user_meta( $coordinator, 'certificate_generator_school', 'Alpha School' );
		wp_set_current_user( $coordinator );

		$this->assertSame( array( 'REG-A2', 'REG-A1' ), array_keys( $this->rows() ) );
		$this->assertSame( array(), $this->rows( array( 'school' => 'Beta School' ) ), 'asking for another school gets nothing' );

		delete_user_meta( $coordinator, 'certificate_generator_school' );
		$this->assertSame( array(), $this->rows(), 'no school assigned: nothing' );
	}

	public function test_csv_has_bom_and_escapes_formulas(): void {
		$csv = certificate_generator_register_csv( array_values( $this->rows() ) );
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $csv );
		$this->assertStringContainsString( 'अनन्या शर्मा', $csv );
		$this->assertStringNotContainsString( ',"=HYPERLINK', $csv );
		$this->assertStringContainsString( "'=HYPERLINK", $csv );
	}

	public function test_pdf_renders_with_non_latin_names(): void {
		if ( ! class_exists( '\Mpdf\Mpdf' ) ) {
			$this->markTestSkipped( 'mPDF not installed.' );
		}
		$rows = array_values( $this->rows() );
		$pdf  = certificate_generator_register_pdf( $rows, array( 'type' => self::TYPE, 'school' => '', 'event' => 0, 'from' => '', 'to' => '', 'status' => '' ) );
		$this->assertStringStartsWith( '%PDF', $pdf );
		$this->assertStringContainsString( 'FreeSerif', $pdf, 'the Unicode font is embedded' );

		// FreeSerif (the default) covers Devanagari; scripts it lacks get their own font.
		$this->assertSame( 'अनन्या', \CertificateGenerator\Services\MpdfCanvas::html( 'अनन्या' ) );
		$this->assertStringContainsString( 'font-family:lohittelugu', \CertificateGenerator\Services\MpdfCanvas::html( 'Ravi రవి' ) );
		$this->assertSame( '&lt;b&gt;', \CertificateGenerator\Services\MpdfCanvas::html( '<b>' ) );
	}
}
