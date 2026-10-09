<?php
/**
 * Exercises the real, currently-wired pipeline end to end:
 *   CSV upload (bulk_import_students(), includes/Services/bulk-import.php)
 *     -> row lands in cg_students
 *     -> serial generated + student row updated (CG_Serial_Number_Generator::generate())
 *     -> certificate record bridged into wp_certificate_generator (cg_insert_certificate_record(),
 *        includes/Services/certificate-search.php — this is the table verify() actually queries)
 *     -> SerialNumberService::verify() finds it
 *
 * PDF/badge rendering itself is intentionally out of scope here — that's already
 * covered by tests/Integration/GeneratePdfFromRowTest.php. This test is about the
 * data pipeline wiring, i.e. does a CSV row really become a verifiable certificate.
 */
class CertificatePipelineTest extends WP_UnitTestCase {

	public function test_csv_import_through_serial_generation_to_verification(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Bulk CSV import is a Pro/Business-gated feature (cg_bulk_import_plan_gate_notice()).

		// ── Step 1: CSV import ──────────────────────────────────────────────
		$csv_path = tempnam( sys_get_temp_dir(), 'cg_pipeline_' ) . '.csv';
		file_put_contents(
			$csv_path,
			"student_name,email,school_name,issue_date,certificate_type\n" .
			"Jane Pipeline,jane.pipeline@example.com,Pipeline High,2026-01-15,Excellence Award\n"
		);

		$_POST = array(
			'submit_students'      => '1',
			'_wpnonce_bulk_import' => wp_create_nonce( 'bulk_import_students_nonce' ),
		);
		$_REQUEST                     = $_POST;
		$_FILES['students_csv']       = array(
			'tmp_name' => $csv_path,
			'error'    => UPLOAD_ERR_OK,
			'name'     => 'students.csv',
		);

		ob_start();
		bulk_import_students();
		$import_output = ob_get_clean();

		unlink( $csv_path );
		$_POST   = array();
		$_REQUEST = array();
		unset( $_FILES['students_csv'] );

		$this->assertStringContainsString( '1 rows read → 1 students saved (1 new, 0 updated)', $import_output );

		$student_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$student_row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$student_table} WHERE email = %s", 'jane.pipeline@example.com' ),
			ARRAY_A
		);

		$this->assertNotNull( $student_row, 'CSV import must create a row in the SQL students table.' );
		$this->assertSame( 'Pipeline High', $student_row['school_name'] );
		$this->assertSame( 'Excellence Award', $student_row['certificate_type'] );

		// ── Step 2: serial generation (the real production call site, per
		//    includes/Services/certificate-search.php ~line 1240) ──────────
		$serial_gen = CG_Serial_Number_Generator::get_instance();
		$serial     = $serial_gen->generate(
			$student_row['certificate_type'],
			array(
				'email'        => $student_row['email'],
				'student_name' => $student_row['student_name'],
			)
		);

		$this->assertNotEmpty( $serial );

		$updated_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT serial_number FROM {$student_table} WHERE email = %s", 'jane.pipeline@example.com' ),
			ARRAY_A
		);
		$this->assertSame( $serial, $updated_row['serial_number'], 'generate() must write the serial back onto the student row.' );

		// ── Step 3: bridge into the verification table ──────────────────────
		cg_insert_certificate_record(
			array(
				'student_name'     => $student_row['student_name'],
				'certificate_type' => $student_row['certificate_type'],
			),
			$serial
		);

		// ── Step 4: public verification ─────────────────────────────────────
		$verification = ( new \CertificateGenerator\Services\SerialNumberService() )->verify( $serial );

		$this->assertTrue( $verification['valid'] );
		$this->assertFalse( $verification['expired'] );
		$this->assertSame( 'Jane Pipeline', $verification['data']['student_name'] );
	}
}
