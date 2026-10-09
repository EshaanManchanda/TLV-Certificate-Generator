<?php
/**
 * Pins the serial-number fixes:
 * - certificate types share one counter (a shared prefix made per-type counters clash);
 * - the shared counter starts above old per-type counters;
 * - serials already in use (e.g. CSV-imported) are skipped;
 * - storing a serial touches only the one entity row it was generated for.
 */
class SerialUniquenessTest extends WP_UnitTestCase {

	private string $students;

	public function set_up(): void {
		parent::set_up();
		$this->students = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
	}

	public function test_different_types_get_different_serials(): void {
		$gen = CG_Serial_Number_Generator::get_instance();
		$this->assertNotSame( $gen->generate( 'Participation' ), $gen->generate( 'Winner' ) );
	}

	public function test_shared_counter_starts_above_old_per_type_counters(): void {
		update_option( 'cg_serial_seq_participation', 41, false );
		update_option( 'cg_serial_seq_winner', 7, false );

		$serial = CG_Serial_Number_Generator::get_instance()->generate( 'Winner' );

		$this->assertGreaterThan( 41, (int) substr( $serial, -8 ) );
	}

	public function test_serial_already_in_use_is_skipped(): void {
		global $wpdb;
		$wpdb->insert(
			$this->students,
			array( 'student_name' => 'Imported', 'certificate_type' => 'Participation', 'serial_number' => 'CERT-00000001' )
		);

		$serial = CG_Serial_Number_Generator::get_instance()->generate( 'Participation' );

		$this->assertSame( 'CERT-00000002', $serial );
	}

	public function test_serial_is_stored_only_on_the_matching_event_row(): void {
		global $wpdb;
		$base = array( 'student_name' => 'Asha Multi', 'email' => 'asha@example.com', 'certificate_type' => 'Participation' );

		$wpdb->insert( $this->students, $base + array( 'issue_date' => '2026-01-10', 'serial_number' => 'CERT-EVENT-A' ) );
		$event_a = (int) $wpdb->insert_id;
		$wpdb->insert( $this->students, $base + array( 'issue_date' => '2026-03-15' ) );
		$event_b = (int) $wpdb->insert_id;
		$wpdb->insert( $this->students, $base + array( 'issue_date' => '2026-05-20' ) );
		$event_c = (int) $wpdb->insert_id;

		$serial = CG_Serial_Number_Generator::get_instance()->generate(
			'Participation',
			array(
				'email'        => 'asha@example.com',
				'student_name' => 'Asha Multi',
				'issue_date'   => '15-03-2026',
			)
		);

		$get = fn( int $id ) => $wpdb->get_row( $wpdb->prepare( "SELECT serial_number, certificate_type FROM {$this->students} WHERE id = %d", $id ), ARRAY_A );

		$this->assertSame( 'CERT-EVENT-A', $get( $event_a )['serial_number'], 'Other events keep their serial.' );
		$this->assertSame( $serial, $get( $event_b )['serial_number'] );
		$this->assertEmpty( $get( $event_c )['serial_number'], 'Other events without a serial stay empty.' );
		$this->assertSame( 'Participation', $get( $event_a )['certificate_type'] );
	}

	public function test_serial_is_stored_by_row_id_in_the_right_table(): void {
		global $wpdb;
		$teachers = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'teachers' );
		$wpdb->insert( $teachers, array( 'teacher_name' => 'T One', 'email' => 'shared@example.com', 'certificate_type' => 'Mentor' ) );
		$teacher_id = (int) $wpdb->insert_id;
		$wpdb->insert( $this->students, array( 'student_name' => 'S One', 'email' => 'shared@example.com', 'certificate_type' => 'Mentor' ) );
		$student_id = (int) $wpdb->insert_id;

		$serial = CG_Serial_Number_Generator::get_instance()->generate(
			'Mentor',
			array( 'id' => $teacher_id, 'table' => 'teachers', 'email' => 'shared@example.com' )
		);

		$this->assertSame( $serial, $wpdb->get_var( $wpdb->prepare( "SELECT serial_number FROM $teachers WHERE id = %d", $teacher_id ) ) );
		$this->assertEmpty( $wpdb->get_var( $wpdb->prepare( "SELECT serial_number FROM {$this->students} WHERE id = %d", $student_id ) ) );
	}

	public function test_duplicate_report_lists_every_holder_of_a_shared_serial(): void {
		global $wpdb;
		$teachers = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'teachers' );
		$wpdb->insert( $this->students, array( 'student_name' => 'Dup A', 'certificate_type' => 'Participation', 'serial_number' => 'CERT-DUP-1' ) );
		$wpdb->insert( $teachers, array( 'teacher_name' => 'Dup B', 'certificate_type' => 'Winner', 'serial_number' => 'CERT-DUP-1' ) );
		$wpdb->insert( $this->students, array( 'student_name' => 'Unique C', 'certificate_type' => 'Winner', 'serial_number' => 'CERT-UNIQUE-1' ) );

		$rows = CG_Bulk_Serial_Generator::get_instance()->get_duplicate_serials();

		$this->assertEqualsCanonicalizing( array( 'Dup A', 'Dup B' ), array_column( $rows, 'name' ) );
	}
}
