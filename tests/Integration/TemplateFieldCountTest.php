<?php
/**
 * Regression test for the "field count balloons 2 → 50 on export/import" bug:
 * the cg-template-edit save handler used to write width/alignment placeholder
 * defaults for all 50 field slots regardless of how many were actually in
 * use, which later fooled bulk-import's field-count auto-detection into
 * reading MAX_FIELDS back out. See src/Admin/Pages/TemplatesPage.php:472+.
 */
class TemplateFieldCountTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_save_handler_only_persists_fields_actually_in_use(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_template_nonce'    => wp_create_nonce( 'cg_save_template' ),
			'template_name'        => 'Field Count Test',
			'certificate_type'     => 'FieldCountTestType',
			'orientation'          => 'landscape',
			'template_field_count' => '2',
		);
		// Simulate the real form: every one of the 50 field-row inputs exists in
		// the DOM and gets submitted, with width/alignment defaulting to
		// 100/C even for slots beyond the visible field count.
		$max_fields = class_exists( 'CG_Field_Schema' ) ? \CG_Field_Schema::MAX_FIELDS : 50;
		for ( $i = 1; $i <= $max_fields; $i++ ) {
			$_POST["field_{$i}_position_x"] = '105';
			$_POST["field_{$i}_position_y"] = (string) ( 60 + $i * 25 );
			$_POST["field_{$i}_width"]      = '100';
			$_POST["field_{$i}_alignment"]  = 'C';
		}

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		ob_end_clean();

		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE certificate_type = %s", 'FieldCountTestType' ), ARRAY_A );

		$this->assertNotEmpty( $row, 'Template row should have been inserted' );
		$extra = json_decode( $row['extra_fields'], true );

		$this->assertSame( 2, $extra['template_field_count'], 'template_field_count must stay at the submitted value, not balloon to MAX_FIELDS' );
		$this->assertArrayHasKey( 'field_1_width', $extra );
		$this->assertArrayHasKey( 'field_2_width', $extra );
		$this->assertArrayNotHasKey( 'field_3_width', $extra, 'Unused slots must not be persisted — this is what previously fooled the CSV import auto-detect into reading 50' );
		$this->assertArrayNotHasKey( 'field_50_width', $extra );

		unset( $_POST, $_SERVER['REQUEST_METHOD'] );
	}
}
