<?php
/**
 * Bulk import of students / teachers / schools (CG_Import_Writer):
 * every CSV row is accounted for — saved, merged into an earlier row with the same
 * identity, skipped or failed — and the page says which. The regression this guards:
 * a 1000-row file sharing one parent email kept 366 rows and still said "imported 1000".
 */
class BulkImportTest extends WP_UnitTestCase {

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
		remove_all_filters( 'cg_import_deadline' );
		parent::tear_down();
	}

	/** Run an entity import over CSV lines; returns the page HTML. */
	private function import( string $entity, array $lines, string $source = 'test.csv' ): string {
		$path = tempnam( sys_get_temp_dir(), 'cg_imp_' );
		file_put_contents( $path, implode( "\n", $lines ) . "\n" );
		$this->tmp[] = $path;

		$_POST    = array(
			'submit_' . $entity    => '1',
			'_wpnonce_bulk_import' => wp_create_nonce( "bulk_import_{$entity}_nonce" ),
		);
		$_REQUEST = $_POST;
		$_FILES   = array(
			$entity . '_csv' => array(
				'tmp_name' => $path,
				'error'    => UPLOAD_ERR_OK,
				'name'     => $source,
			),
		);
		ob_start();
		call_user_func( 'bulk_import_' . $entity );
		return (string) ob_get_clean();
	}

	private function count_rows( string $entity, string $source = 'test.csv' ): int {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE import_source = %s", $source ) );
	}

	private function text( string $html ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/** The reported file's shape: one shared email, repeated names, names repeating across schools. */
	private function shared_email_file( int $rows ): array {
		$lines = array( 'student_name,email,phone,school_name,certificate_type,issue_date' );
		for ( $i = 0; $i < $rows; $i++ ) {
			// 366 names over 4 schools: some names repeat at the same school (merged), some don't.
			$lines[] = sprintf( 'Student %03d,parent@example.test,+91 9%09d,School %d,Participation,30-09-2026', $i % 366, $i, $i % 4 );
		}
		return $lines;
	}

	public function test_shared_email_file_keeps_every_distinct_person_and_reports_merges(): void {
		$lines    = $this->shared_email_file( 1000 );
		$distinct = count( array_unique( array_map( fn( $l ) => strtolower( implode( '|', array( explode( ',', $l )[0], explode( ',', $l )[3] ) ) ), array_slice( $lines, 1 ) ) ) );

		$html = $this->import( 'students', $lines );

		$this->assertSame( $distinct, $this->count_rows( 'students' ), 'One row per email + name + school' );
		$merged = 1000 - $distinct;
		$this->assertStringContainsString( "1000 rows read → $distinct students saved ($distinct new, 0 updated)", $this->text( $html ) );
		$this->assertStringContainsString( "$merged merged into an earlier row", $this->text( $html ) );
		$this->assertStringContainsString( 'notice-warning', $html, 'Merges are not reported as a clean success' );
		$this->assertMatchesRegularExpression( '/merged into row \d+ \(same email \+ student_name \+ school_name/', $html );
		$this->assertStringContainsString( 'download="import-issues.csv"', $html );
	}

	public function test_reimport_updates_in_place_and_keeps_created_at(): void {
		global $wpdb;
		$lines = $this->shared_email_file( 50 );
		$this->import( 'students', $lines );
		$table   = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$wpdb->query( "UPDATE $table SET created_at = '2020-01-01 00:00:00' WHERE import_source = 'test.csv'" );
		$before = $this->count_rows( 'students' );

		$html = $this->import( 'students', $lines );

		$this->assertSame( $before, $this->count_rows( 'students' ) );
		$this->assertStringContainsString( "50 rows read → $before students saved (0 new, $before updated)", $this->text( $html ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE import_source = 'test.csv' AND created_at != '2020-01-01 00:00:00'" ), 'created_at survives an update' );
	}

	public function test_rows_without_name_or_type_are_skipped_and_listed(): void {
		$html = $this->import(
			'students',
			array(
				'student_name,email,school_name,certificate_type,issue_date',
				'Asha Rao,asha@example.test,North School,Participation,2026-01-15',
				',blank@example.test,North School,Participation,2026-01-15',
				'Ravi Rao,ravi@example.test,North School,,2026-01-15',
			)
		);
		$this->assertSame( 1, $this->count_rows( 'students' ) );
		$this->assertStringContainsString( '2 skipped', $this->text( $html ) );
		$this->assertStringContainsString( '<td class="num">3</td><td></td><td>skipped: student_name is empty</td>', $html );
		$this->assertStringContainsString( '<td class="num">4</td><td>Ravi Rao</td><td>skipped: certificate_type is empty</td>', $html );
	}

	public function test_row_with_extra_values_is_skipped_not_fatal(): void {
		$html = $this->import(
			'students',
			array(
				'student_name,email,school_name,certificate_type,issue_date',
				'Asha Rao,asha@example.test,North, School,Participation,2026-01-15',
				'Ravi Rao,ravi@example.test,North School,Participation,2026-01-15',
			)
		);
		$this->assertSame( 1, $this->count_rows( 'students' ), 'Rows after the malformed one still import' );
		$this->assertStringContainsString( 'skipped: malformed: 6 values but the header has 5 columns', $html );
	}

	public function test_identity_compares_like_the_database_did(): void {
		$this->import( 'students', array( 'student_name,email,school_name,certificate_type,issue_date', 'Asha Rao,Asha@Example.test,North School,Participation,2026-01-15' ) );
		$html = $this->import( 'students', array( 'student_name,email,school_name,certificate_type,issue_date', 'asha rao,asha@example.test,north school ,Participation,2026-01-15' ) );
		$this->assertSame( 1, $this->count_rows( 'students' ) );
		$this->assertStringContainsString( '(0 new, 1 updated)', $this->text( $html ) );
	}

	/** Audit bug 2: rows with no email used to be inserted again on every re-import. */
	public function test_rows_without_email_match_on_name_and_school(): void {
		$lines = array(
			'student_name,email,school_name,certificate_type,issue_date',
			'Asha Rao,,North School,Participation,2026-01-15',
			'Asha Rao,,North School,Participation,2026-01-15', // same person twice in the file
			'Asha Rao,,South School,Participation,2026-01-15', // same name, other school: someone else
			'Ravi Iyer,,North School,Participation,2026-01-15',
		);
		$this->import( 'students', $lines );
		$this->assertSame( 3, $this->count_rows( 'students' ) );

		$again = $this->import( 'students', $lines );
		$this->assertSame( 3, $this->count_rows( 'students' ), 'Re-importing must not duplicate rows that have no email' );
		$this->assertStringContainsString( '(0 new, 3 updated)', $this->text( $again ) );
	}

	public function test_row_without_email_does_not_claim_a_row_that_has_one(): void {
		$this->import( 'students', array( 'student_name,email,school_name,certificate_type,issue_date', 'Asha Rao,asha@example.test,North School,Participation,2026-01-15' ) );
		$this->import( 'students', array( 'student_name,email,school_name,certificate_type,issue_date', 'Asha Rao,,North School,Participation,2026-01-15' ) );
		$this->assertSame( 2, $this->count_rows( 'students' ) );
	}

	public function test_school_auto_created_by_students_import_is_claimed(): void {
		$this->import( 'students', array( 'student_name,email,school_name,certificate_type,issue_date', 'Asha Rao,asha@example.test,Claim School,Participation,2026-01-15' ) );
		global $wpdb;
		$schools = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'schools' );
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM $schools WHERE school_name = 'Claim School'" ) );

		$html = $this->import(
			'schools',
			array(
				'school_name,place,certificate_type,issue_date',
				'Claim School,Pune,School Award,2026-01-15',
				'Claim School,Pune,Host Award,2026-01-15',
			)
		);
		$this->assertSame( '2', $wpdb->get_var( "SELECT COUNT(*) FROM $schools WHERE school_name = 'Claim School'" ), 'The type-less row is claimed once; the second type is a new row' );
		$this->assertStringContainsString( '(1 new, 1 updated)', $this->text( $html ) );
	}

	public function test_teachers_at_two_schools_are_two_people(): void {
		$this->import(
			'teachers',
			array(
				'teacher_name,email,school_name,certificate_type,issue_date',
				'Meera Rao,staff@example.test,North School,Participation,2026-01-15',
				'Meera Rao,staff@example.test,South School,Participation,2026-01-15',
				'Meera Rao,staff@example.test,South School,Participation,2026-01-15',
			)
		);
		$this->assertSame( 2, $this->count_rows( 'teachers' ) );
	}

	public function test_time_limit_stops_at_a_chunk_and_a_second_upload_finishes(): void {
		add_filter( 'cg_import_deadline', fn() => microtime( true ) - 1 ); // already past: stop after the first chunk
		$lines = array( 'student_name,email,school_name,certificate_type,issue_date' );
		for ( $i = 0; $i < CG_Import_Writer::CHUNK + 20; $i++ ) {
			$lines[] = "Timed $i,timed$i@example.test,T School,Participation,2026-01-15";
		}
		$html = $this->import( 'students', $lines );
		$this->assertSame( CG_Import_Writer::CHUNK, $this->count_rows( 'students' ) );
		$this->assertStringContainsString( 'Stopped at row ' . ( CG_Import_Writer::CHUNK + 2 ), $this->text( $html ) );

		remove_all_filters( 'cg_import_deadline' );
		$html = $this->import( 'students', $lines );
		$this->assertSame( CG_Import_Writer::CHUNK + 20, $this->count_rows( 'students' ), 'No duplicates after re-uploading' );
		$this->assertStringContainsString( '(20 new, 0 updated)', $this->text( $html ), 'Resumes at the stop row instead of re-updating' );
		$this->assertStringContainsString( CG_Import_Writer::CHUNK . ' already imported by the earlier upload', $this->text( $html ) );
	}

	/** Audit bug 1: a retry that re-updates earlier rows hits the limit sooner and never progresses. */
	public function test_retry_progresses_even_when_every_upload_is_cut_short(): void {
		$lines = array( 'student_name,email,school_name,certificate_type,issue_date' );
		for ( $i = 0; $i < 2 * CG_Import_Writer::CHUNK + 5; $i++ ) {
			$lines[] = "Slow $i,slow$i@example.test,S School,Participation,2026-01-15";
		}
		add_filter( 'cg_import_deadline', fn() => microtime( true ) - 1 ); // every upload gets one chunk only

		$this->import( 'students', $lines );
		$this->assertSame( CG_Import_Writer::CHUNK, $this->count_rows( 'students' ) );
		$this->import( 'students', $lines );
		$this->assertSame( 2 * CG_Import_Writer::CHUNK, $this->count_rows( 'students' ) );
		$html = $this->import( 'students', $lines );
		$this->assertSame( 2 * CG_Import_Writer::CHUNK + 5, $this->count_rows( 'students' ) );
		$this->assertStringNotContainsString( 'Stopped at row', $this->text( $html ) );
	}

	public function test_an_edited_file_is_imported_from_the_top(): void {
		add_filter( 'cg_import_deadline', fn() => microtime( true ) - 1 );
		$lines = array( 'student_name,email,school_name,certificate_type,issue_date' );
		for ( $i = 0; $i < CG_Import_Writer::CHUNK + 1; $i++ ) {
			$lines[] = "Edit $i,edit$i@example.test,E School,Participation,2026-01-15";
		}
		$this->import( 'students', $lines );
		remove_all_filters( 'cg_import_deadline' );

		$lines[1] = 'Edit 0 Renamed,edit0@example.test,E School,Participation,2026-01-15'; // different file now
		$html     = $this->import( 'students', $lines );
		$this->assertStringNotContainsString( 'already imported by the earlier upload', $this->text( $html ) );
	}

	public function test_duplicates_within_one_chunk_insert_once(): void {
		$html = $this->import(
			'students',
			array(
				'student_name,email,school_name,certificate_type,issue_date,phone',
				'Asha Rao,asha@example.test,North School,Participation,2026-01-15,111',
				'Asha Rao,asha@example.test,North School,Participation,2026-01-15,222',
			)
		);
		global $wpdb;
		$this->assertSame( 1, $this->count_rows( 'students' ) );
		$this->assertSame( '222', $wpdb->get_var( 'SELECT phone FROM ' . \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' ) . " WHERE import_source = 'test.csv'" ), 'The later row wins, as before' );
		$this->assertStringContainsString( '<td class="num">3</td><td>Asha Rao</td><td>merged into row 2', $html );
	}
}
