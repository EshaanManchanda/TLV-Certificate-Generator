<?php
/**
 * The import check step warns about names too long for their template field, and the template
 * preview can use a real record (its values and existing serial) without issuing anything.
 */

use CertificateGenerator\Database\CustomTables;

class ImportPreviewTest extends WP_UnitTestCase {

	private int $template_id;
	private array $tmp = array();

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $wpdb;
		$wpdb->insert(
			CustomTables::instance()->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Import Preview',
				'certificate_type' => 'PHPUnitImportPreview',
				'entity_type'      => 'students',
				'template_url'     => 'https://example.test/bg.png',
				'orientation'      => 'landscape',
				'status'           => 'published',
				'font_size'        => 24,
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 148, '1_position_y' => 100, '1_width' => 60 ) ),
			)
		);
		$this->template_id = (int) $wpdb->insert_id;
	}

	public function tear_down(): void {
		foreach ( $this->tmp as $f ) {
			@unlink( $f );
		}
		parent::tear_down();
	}

	private function student( string $name, string $type, string $serial = '' ): int {
		global $wpdb;
		$wpdb->insert(
			CustomTables::instance()->get_table( 'students' ),
			array(
				'student_name'     => $name,
				'email'            => sanitize_title( $name ) . '@example.test',
				'school_name'      => 'DPS',
				'certificate_type' => $type,
				'issue_date'       => '2026-01-15',
				'serial_number'    => $serial ?: null,
				'extra_fields'     => wp_json_encode( array( 'grade' => 'A+' ) ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_dry_run_lists_names_too_long_for_the_field(): void {
		$path = tempnam( sys_get_temp_dir(), 'cg_long_' );
		file_put_contents( $path, "student_name,email,school_name,issue_date,certificate_type\nVenkata Subramanian Lakshminarayanan Chandrasekaran,v@example.test,DPS,2026-01-15,PHPUnitImportPreview\nAsha Rao,a@example.test,DPS,2026-01-15,PHPUnitImportPreview\n" );
		$this->tmp[] = $path;

		$res = certificate_generator_import_run( 'students', $path, array( 'dry_run' => true ) );
		$this->assertSame( array( array( 2, 'Venkata Subramanian Lakshminarayanan Chandrasekaran' ) ), $res['long'] );
	}

	public function test_preview_with_a_real_record_uses_its_values_and_serial(): void {
		$id = $this->student( 'Meera Iyer', 'PHPUnitImportPreview', 'CERT-00000042' );
		$this->student( 'Other Type', 'SomethingElse' );

		$records = certificate_generator_template_preview_records( $this->template_id );
		$this->assertSame( array( $id => 'Meera Iyer — meera-iyer@example.test' ), $records, 'Only records of the template type are offered' );

		$data = certificate_generator_template_record_data( $this->template_id, $id );
		$this->assertSame( 'Meera Iyer', $data['student_name'] );
		$this->assertSame( 'A+', $data['grade'] );
		$this->assertSame( 'CERT-00000042', $data['_preview_serial'] );
		$this->assertSame( $this->template_id, $data['_preview_post_id'] );
	}

	public function test_record_of_another_type_is_refused(): void {
		$other = $this->student( 'Other Type', 'SomethingElse' );
		$this->assertNull( certificate_generator_template_record_data( $this->template_id, $other ) );
	}
}
