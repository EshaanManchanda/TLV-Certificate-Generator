<?php
/**
 * Correcting a recipient's name and regenerating keeps the serial, and the
 * verification records for that serial now follow the new name (audit E1).
 */

use CertificateGenerator\Database\CustomTables;

class CertificateCorrectionTest extends WP_UnitTestCase {

	public function test_regenerating_with_same_serial_updates_verify_name(): void {
		global $wpdb;
		$serial = 'TEST-2026-' . wp_rand( 10000, 99999 );

		cg_insert_certificate_record( array( 'student_name' => 'Ashaa Verma', 'email' => 'a@example.com', 'certificate_type' => 'Merit' ), $serial );
		cg_insert_certificate_record( array( 'student_name' => 'Asha Verma', 'email' => 'a@example.com', 'certificate_type' => 'Merit' ), $serial );

		$legacy = $wpdb->get_col( $wpdb->prepare( "SELECT student_name FROM {$wpdb->prefix}certificate_generator WHERE serial_number = %s", $serial ) );
		$this->assertSame( array( 'Asha Verma' ), $legacy, 'still one row, now with the corrected name' );

		$cert_table = CustomTables::instance()->get_table( 'certificates' );
		$modern     = $wpdb->get_col( $wpdb->prepare( "SELECT recipient_name FROM $cert_table WHERE serial_number = %s", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( array( 'Asha Verma' ), $modern );
	}

	public function test_teacher_record_uses_teacher_name_not_blank_student_meta(): void {
		global $wpdb;
		$serial = 'TEST-2026-' . wp_rand( 10000, 99999 );

		cg_insert_certificate_record( array( 'student_name' => 'x', 'certificate_type' => 'Merit' ), $serial );
		// The main generation path passes get_post_meta(0, …) = '' for keys that don't apply.
		cg_sync_certificate_record_name( array( 'student_name' => '', 'teacher_name' => 'R. Iyer' ), $serial );

		$this->assertSame( 'R. Iyer', $wpdb->get_var( $wpdb->prepare( "SELECT student_name FROM {$wpdb->prefix}certificate_generator WHERE serial_number = %s", $serial ) ) );
	}
}
