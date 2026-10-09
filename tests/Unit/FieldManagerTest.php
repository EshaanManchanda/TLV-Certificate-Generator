<?php
/**
 * Covers src/Services/FieldManager.php — the core/extra_fields split-merge logic
 * that backs every CSV import/export and admin save path for students/teachers/schools.
 */

use PHPUnit\Framework\TestCase;

class FieldManagerTest extends TestCase {

	public function test_split_buckets_known_core_keys_and_unknown_extra_keys(): void {
		$result = \CertificateGenerator\Services\FieldManager::split(
			'students',
			array(
				'student_name' => 'Jane Doe',
				'email'        => 'jane@example.com',
				'jersey_number' => '7',
			)
		);

		$this->assertSame( 'Jane Doe', $result['core']['student_name'] );
		$this->assertSame( 'jane@example.com', $result['core']['email'] );
		$this->assertSame( '7', $result['extra']['jersey_number'] );
		$this->assertArrayNotHasKey( 'jersey_number', $result['core'] );
	}

	public function test_split_strips_field_prefix_before_matching_core_keys(): void {
		$result = \CertificateGenerator\Services\FieldManager::split(
			'students',
			array( 'field_student_name' => 'Jane Doe' )
		);

		$this->assertSame( 'Jane Doe', $result['core']['student_name'] );
		$this->assertArrayNotHasKey( 'field_student_name', $result['core'] );
	}

	public function test_split_drops_empty_extra_values_but_keeps_falsy_meaningful_ones(): void {
		$result = \CertificateGenerator\Services\FieldManager::split(
			'students',
			array(
				'blank_field' => '',
				'null_field'  => null,
				'zero_field'  => '0',
			)
		);

		$this->assertArrayNotHasKey( 'blank_field', $result['extra'] );
		$this->assertArrayNotHasKey( 'null_field', $result['extra'] );
		$this->assertSame( '0', $result['extra']['zero_field'] );
	}

	public function test_merge_recombines_core_row_and_extra_fields_json(): void {
		$merged = \CertificateGenerator\Services\FieldManager::merge(
			array(
				'student_name' => 'Jane Doe',
				'extra_fields' => '{"jersey_number":"7"}',
			),
			'{"jersey_number":"7"}'
		);

		$this->assertSame( 'Jane Doe', $merged['student_name'] );
		$this->assertSame( '7', $merged['jersey_number'] );
		$this->assertArrayNotHasKey( 'extra_fields', $merged );
	}

	public function test_prepare_for_db_and_prepare_for_display_round_trip(): void {
		$flat = array(
			'student_name'  => 'Jane Doe',
			'jersey_number' => '7',
		);

		$db_row  = \CertificateGenerator\Services\FieldManager::prepare_for_db( 'students', $flat );
		$display = \CertificateGenerator\Services\FieldManager::prepare_for_display( $db_row );

		$this->assertSame( $flat, $display );
	}
}
