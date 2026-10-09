<?php
/**
 * Bulk Send dry run (src/Services/PreSendCheck.php): invalid addresses, duplicate
 * rows and names too long for their field are counted before anything is queued.
 */

use CertificateGenerator\Database\CustomTables;

class PreSendCheckTest extends WP_UnitTestCase {

	private function student( string $name, string $email, string $type = 'PHPUnitPreSend' ): void {
		global $wpdb;
		$wpdb->insert(
			CustomTables::instance()->get_table( 'students' ),
			array(
				'student_name'     => $name,
				'email'            => $email,
				'school_name'      => 'DPS',
				'certificate_type' => $type,
				'issue_date'       => '2026-01-15',
				'send_email'       => 1,
			)
		);
	}

	public function set_up(): void {
		parent::set_up();
		certificate_generator_create_email_log_table();
		global $wpdb;
		$tables = CustomTables::instance();
		$wpdb->query( 'DELETE FROM ' . $tables->get_table( 'students' ) );
		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit PreSend',
				'certificate_type' => 'PHPUnitPreSend',
				'template_url'     => 'https://example.test/bg.png',
				'orientation'      => 'landscape',
				'status'           => 'published',
				'font_size'        => 24,
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 148, '1_position_y' => 100, '1_width' => 60 ) ),
			)
		);
	}

	private function stats(): array {
		return certificate_generator_get_filter_statistics( array( 'post_types' => array( 'students' ) ) );
	}

	public function test_clean_list_has_no_warnings(): void {
		$this->student( 'Asha Rao', 'asha@example.test' );
		$s = $this->stats();
		$this->assertSame( array( 0, 0, 0 ), array( $s['invalid_email'], $s['duplicate_rows'], $s['long_names'] ) );
	}

	public function test_invalid_email_and_duplicates_are_counted(): void {
		$this->student( 'Asha Rao', 'asha@example.test' );
		$this->student( 'Asha Rao', 'ASHA@example.test' ); // same certificate again
		$this->student( 'Ravi Iyer', 'ravi@@example' );
		$s = $this->stats();
		$this->assertSame( 1, $s['invalid_email'] );
		$this->assertSame( 1, $s['duplicate_rows'] );
	}

	public function test_name_too_long_for_its_field_is_flagged(): void {
		// 60 mm field at 24 pt: this name doesn't fit even at the 14.4 pt floor.
		$this->student( 'Venkata Subramanian Lakshminarayanan Chandrasekaran', 'v@example.test' );
		$this->student( 'Asha Rao', 'asha@example.test' );
		$this->assertSame( 1, $this->stats()['long_names'] );
	}
}
