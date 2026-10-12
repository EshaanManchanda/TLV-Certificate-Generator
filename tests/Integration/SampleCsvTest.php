<?php
/**
 * The sample CSVs users download from the Documentation page (assets/samples/) import as they
 * are: every row saved, nothing skipped, the date order read without asking, and the templates
 * file creating one template per certificate type the other samples use.
 */

use CertificateGenerator\Database\CustomTables;

class SampleCsvTest extends WP_UnitTestCase {

	private function sample( string $file ): string {
		return CERTIFICATE_GENERATOR_PATH . 'assets/samples/' . $file;
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		parent::tear_down();
	}

	public function people(): array {
		return array(
			'students' => array( 'students', 'students.csv', 4 ),
			'teachers' => array( 'teachers', 'teachers.csv', 2 ),
			'schools'  => array( 'schools', 'schools.csv', 2 ),
		);
	}

	/** @dataProvider people */
	public function test_people_samples_import_cleanly( string $entity, string $file, int $rows ): void {
		$check = certificate_generator_import_run( $entity, $this->sample( $file ), array( 'dry_run' => true ) );
		$this->assertSame( array(), $check['missing'] );
		$this->assertSame( 'dmy', certificate_generator_import_guess_date_order( $check['dates'] ), 'No date-order question' );
		$this->assertSame( array(), $check['report']['issues'], 'No warnings or skips' );

		$result = certificate_generator_import_run( $entity, $this->sample( $file ), array( 'source' => "sample-$file", 'date_order' => 'dmy' ) );
		$this->assertSame( $rows, $result['report']['counts']['new'] );
		$this->assertSame( 0, $result['report']['counts']['skipped'] );
	}

	public function test_students_sample_keeps_hindi_and_extra_field(): void {
		certificate_generator_import_run( 'students', $this->sample( 'students.csv' ), array( 'source' => 'sample-students' ) );
		global $wpdb;
		$table = CustomTables::instance()->get_table( 'students' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s", 'priya.sharma@example.com' ), ARRAY_A );
		$this->assertSame( 'प्रिया शर्मा', $row['student_name'] );
		$this->assertSame( '2026-08-25', $row['issue_date'] );
		$this->assertSame( array( 'grade' => 'B' ), json_decode( $row['extra_fields'], true ) );
	}

	public function test_templates_sample_creates_a_template_per_certificate_type(): void {
		$tmp = tempnam( sys_get_temp_dir(), 'cg_tpl_' );
		copy( $this->sample( 'templates.csv' ), $tmp );
		$_POST    = array(
			'submit_certificates'  => '1',
			'_wpnonce_bulk_import' => wp_create_nonce( 'bulk_import_certificates_nonce' ),
		);
		$_REQUEST = $_POST;
		$_FILES   = array(
			'certificates_csv' => array(
				'tmp_name' => $tmp,
				'error'    => UPLOAD_ERR_OK,
				'name'     => 'templates.csv',
			),
		);
		ob_start();
		certificate_generator_bulk_import_certificates();
		$html = (string) ob_get_clean();
		@unlink( $tmp );
		$this->assertStringContainsString( 'Successfully imported 4 certificate templates', $html );

		global $wpdb;
		$table = CustomTables::instance()->get_table( 'certificate_templates' );
		$rows  = $wpdb->get_results( "SELECT certificate_type, entity_type, extra_fields FROM $table WHERE template_name LIKE '% 2026'", ARRAY_A ); // phpcs:ignore
		$types = array_column( $rows, 'entity_type', 'certificate_type' );
		$this->assertSame( array( 'Participation' => 'students', 'Winner' => 'students', 'Teacher Appreciation' => 'teachers', 'School Participation' => 'schools' ), $types );
		foreach ( $rows as $r ) {
			$extra = json_decode( $r['extra_fields'], true );
			$first = array( 'students' => 'student_name', 'teachers' => 'teacher_name', 'schools' => 'school_name' )[ $r['entity_type'] ];
			$this->assertSame( $first, $extra['field_1_name'], "{$r['certificate_type']}: field 1 shows the name" );
			$this->assertSame( 'issue_date', $extra['field_3_name'] );
		}

		// The people samples' certificate types all have a template.
		foreach ( array( 'students.csv' => 5, 'teachers.csv' => 5, 'schools.csv' => 3 ) as $file => $type_col ) {
			$lines = array_map( 'str_getcsv', array_slice( file( $this->sample( $file ), FILE_IGNORE_NEW_LINES ), 1 ) );
			foreach ( $lines as $line ) {
				$this->assertArrayHasKey( $line[ $type_col ], $types, "$file: {$line[$type_col]}" );
			}
		}
	}
}
