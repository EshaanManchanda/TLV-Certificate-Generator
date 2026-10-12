<?php
/**
 * Import UX (7.8.0): header aliases and manual mapping, delimiter/encoding detection, explicit
 * date order, "leave existing unchanged", and the check step whose dry-run counts must equal
 * what the import then does.
 */
class ImportMappingTest extends WP_UnitTestCase {

	private array $tmp = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		foreach ( $this->tmp as $f ) {
			@unlink( $f );
		}
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		parent::tear_down();
	}

	private function file( string $bytes ): string {
		$path = tempnam( sys_get_temp_dir(), 'cg_map_' );
		file_put_contents( $path, $bytes );
		$this->tmp[] = $path;
		return $path;
	}

	private function students( string $source ): array {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE import_source = %s ORDER BY id", $source ), ARRAY_A );
	}

	private function post( string $action, array $fields = array(), ?string $upload = null ): string {
		$_POST    = array(
			$action . '_students'  => '1',
			'_wpnonce_bulk_import' => wp_create_nonce( 'bulk_import_students_nonce' ),
		) + $fields;
		$_REQUEST = $_POST;
		$_FILES   = $upload ? array(
			'students_csv' => array(
				'tmp_name' => $upload,
				'error'    => UPLOAD_ERR_OK,
				'name'     => 'map.csv',
			),
		) : array();
		ob_start();
		certificate_generator_bulk_import_students();
		return (string) ob_get_clean();
	}

	public function test_aliases_and_spaced_headers_map_to_fields(): void {
		$file   = $this->file( "Name,Email Address,School,Date,Type,Grade\nAsha Rao,asha@example.test,North School,2026-01-15,Participation,A\n" );
		$result = certificate_generator_import_run( 'students', $file, array( 'source' => 'alias.csv' ) );

		$this->assertSame( array( 'student_name', 'email', 'school_name', 'issue_date', 'certificate_type', 'grade' ), $result['map'] );
		$this->assertSame( array(), $result['missing'] );
		$rows = $this->students( 'alias.csv' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Asha Rao', $rows[0]['student_name'] );
		$this->assertSame( array( 'grade' => 'A' ), json_decode( $rows[0]['extra_fields'], true ) );
	}

	public function test_exact_header_beats_alias_and_manual_mapping_overrides(): void {
		$cfg = certificate_generator_import_entities()['students'];
		$map = certificate_generator_import_map( $cfg, array( 'name', 'student_name', 'email' ) );
		$this->assertSame( array( 'name', 'student_name', 'email' ), $map, 'name stays an extra field when student_name exists' );

		$map = certificate_generator_import_map( $cfg, array( 'Full Title', 'Mail Me' ), array( 0 => 'student_name', 1 => 'email' ) );
		$this->assertSame( array( 'student_name', 'email' ), $map );

		$map = certificate_generator_import_map( $cfg, array( 'student_name', 'Pupil' ), array( 1 => 'student_name' ) );
		$this->assertSame( array( '', 'student_name' ), $map, 'Mapping a field by hand frees it from the other column' );
	}

	public function test_semicolon_windows_1252_file_is_converted(): void {
		$csv  = "student_name;email;school_name;issue_date;certificate_type\n" . "Jos\xE9 M\xFCller;jose@example.test;Escuela;2026-01-15;Participation\n";
		$file = $this->file( $csv );
		$res  = certificate_generator_import_run( 'students', $file, array( 'source' => 'cp1252.csv' ) );

		$this->assertSame( ';', $res['delimiter'] );
		$this->assertSame( 'Windows-1252', $res['encoding'] );
		$this->assertSame( 'José Müller', $this->students( 'cp1252.csv' )[0]['student_name'] );
	}

	public function test_hindi_utf8_with_bom_and_tabs(): void {
		$file = $this->file( "\xEF\xBB\xBFstudent_name\temail\tschool_name\tissue_date\tcertificate_type\nश्रीमती क्षत्रिय\th@example.test\tविद्यालय\t2026-01-15\tParticipation\n" );
		$res  = certificate_generator_import_run( 'students', $file, array( 'source' => 'hindi.csv' ) );

		$this->assertSame( "\t", $res['delimiter'] );
		$this->assertSame( 'श्रीमती क्षत्रिय', $this->students( 'hindi.csv' )[0]['student_name'] );
	}

	public function test_undecodable_file_stops_before_saving(): void {
		$file = $this->file( "student_name,email,school_name,issue_date,certificate_type\nBad\x01Name,b@example.test,S,2026-01-15,P\n" );
		$res  = certificate_generator_import_run( 'students', $file, array( 'source' => 'bad.csv' ) );

		$this->assertIsString( $res );
		$this->assertStringContainsString( 'CSV UTF-8', $res );
		$this->assertSame( array(), $this->students( 'bad.csv' ) );
	}

	public function test_date_order(): void {
		$this->assertSame( '2026-04-03', certificate_generator_import_date( '03/04/2026', 'dmy' ) );
		$this->assertSame( '2026-03-04', certificate_generator_import_date( '03/04/2026', 'mdy' ) );
		$this->assertSame( '2026-04-03', certificate_generator_import_date( '3-4-2026', 'dmy' ) );
		$this->assertNull( certificate_generator_import_date( '31/02/2026', 'dmy' ) );
		$this->assertSame( '2026-01-15', certificate_generator_import_date( '2026-01-15', 'dmy' ), 'ISO dates ignore the order' );

		$this->assertSame( '', certificate_generator_import_guess_date_order( array( '03/04/2026', '05/06/2026' ) ) );
		$this->assertSame( 'dmy', certificate_generator_import_guess_date_order( array( '03/04/2026', '25/06/2026' ) ) );
		$this->assertSame( 'mdy', certificate_generator_import_guess_date_order( array( '06/25/2026' ) ) );
		$this->assertSame( 'auto', certificate_generator_import_guess_date_order( array( '2026-01-15', '' ) ) );
	}

	public function test_dry_run_counts_match_the_import_and_write_nothing(): void {
		certificate_generator_import_run( 'students', $this->file( "student_name,email,school_name,issue_date,certificate_type\nOld Row,old@example.test,North,2026-01-15,P\n" ), array( 'source' => 'dry.csv' ) );
		$lines = array( 'student_name,email,school_name,issue_date,certificate_type', 'Old Row,old@example.test,North,2026-01-15,P' );
		for ( $i = 0; $i < 30; $i++ ) {
			$lines[] = sprintf( 'Kid %d,kid%d@example.test,North,2026-01-15,P', $i % 25, $i % 25 ); // 5 repeats merge
		}
		$lines[] = ',x@example.test,North,2026-01-15,P'; // skipped: no name
		$file    = $this->file( implode( "\n", $lines ) . "\n" );

		$dry = certificate_generator_import_run( 'students', $file, array( 'source' => 'dry.csv', 'dry_run' => true ) );
		$this->assertCount( 1, $this->students( 'dry.csv' ), 'The dry run wrote nothing' );
		$real = certificate_generator_import_run( 'students', $file, array( 'source' => 'dry.csv' ) );

		$this->assertSame( $dry['report']['counts'], $real['report']['counts'] );
		$this->assertSame( array( 25, 1, 5, 1 ), array( $real['report']['counts']['new'], $real['report']['counts']['updated'], $real['report']['counts']['merged'], $real['report']['counts']['skipped'] ) );
		$this->assertCount( 26, $this->students( 'dry.csv' ) );
	}

	public function test_leave_existing_unchanged(): void {
		$head = "student_name,email,school_name,issue_date,certificate_type,phone\n";
		certificate_generator_import_run( 'students', $this->file( $head . "Asha,a@example.test,North,2026-01-15,P,111\n" ), array( 'source' => 'keep.csv' ) );
		$res = certificate_generator_import_run( 'students', $this->file( $head . "Asha,a@example.test,North,2026-01-15,P,999\n" ), array( 'source' => 'keep.csv', 'update_existing' => false ) );

		$this->assertSame( 1, $res['report']['counts']['skipped'] );
		$this->assertSame( '111', $this->students( 'keep.csv' )[0]['phone'] );
	}

	public function test_check_step_then_import(): void {
		$file = $this->file( "Pupil,Mail,School,Issued On,Award\nAsha Rao,asha@example.test,North School,03/04/2026,Participation\n" );
		$html = $this->post( 'preview', array(), $file );

		$this->assertStringContainsString( 'Check the import', $html );
		$this->assertStringContainsString( 'Required columns not mapped:', $html, '"Pupil" is not an alias' );
		$this->assertStringContainsString( 'could be 3 April or 4 March', $html );
		$this->assertMatchesRegularExpression( '/name="import_students"[^>]*disabled/', $html );
		$this->assertSame( array(), $this->students( 'map.csv' ) );
		preg_match( '/name="cg_import_job" value="([^"]+)"/', $html, $m );
		$job = $m[1];
		$this->assertFileExists( certificate_generator_import_job_path( $job ) );

		$choices = array(
			'cg_import_job'    => $job,
			'cg_import_source' => 'map.csv',
			'cg_map'           => array( 0 => 'student_name' ),
			'cg_date_order'    => 'dmy',
			'cg_existing'      => 'update',
		);
		$html = $this->post( 'preview', $choices );
		$this->assertStringContainsString( '1 rows: 1 new', wp_strip_all_tags( $html ) );
		$this->assertDoesNotMatchRegularExpression( '/name="import_students"[^>]*disabled/', $html );

		$html = $this->post( 'import', $choices );
		$this->assertStringContainsString( '1 rows read → 1 students saved', wp_strip_all_tags( $html ) );
		$rows = $this->students( 'map.csv' );
		$this->assertSame( '2026-04-03', $rows[0]['issue_date'] );
		$this->assertFileDoesNotExist( certificate_generator_import_job_path( $job ), 'The kept upload is deleted after the import' );
	}

	public function test_job_ids_are_per_user(): void {
		$this->assertSame( '', certificate_generator_import_job_path( '../../wp-config' ) );
		$this->assertSame( '', certificate_generator_import_job_path( 'import-999999-' . str_repeat( 'a', 32 ) ) );
		$this->assertNotSame( '', certificate_generator_import_job_path( 'import-' . get_current_user_id() . '-' . str_repeat( 'a', 32 ) ) );
	}

	public function test_sample_csv_has_every_required_column(): void {
		foreach ( certificate_generator_import_entities() as $entity => $cfg ) {
			$csv  = base64_decode( substr( certificate_generator_import_sample_csv_url( $entity ), strlen( 'data:text/csv;base64,' ) ) );
			$file = $this->file( $csv );
			$res  = certificate_generator_import_run( $entity, $file, array( 'dry_run' => true ) );
			$this->assertSame( array(), $res['missing'], $entity );
			$this->assertSame( 1, $res['report']['counts']['new'], $entity );
		}
	}
}
