<?php
/**
 * Regression test: QR code / Serial Number positions that are out of bounds
 * for the chosen orientation must be reset to that orientation's defaults on
 * save, instead of silently persisting an off-page value.
 * See src/Admin/Pages/TemplatesPage.php (bounded_position()).
 */
class TemplatePositionBoundsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function save_template( string $certificate_type, string $orientation, float $qr_x, float $qr_y ): array {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_template_nonce'    => wp_create_nonce( 'cg_save_template' ),
			'template_name'        => 'Position Bounds Test',
			'certificate_type'     => $certificate_type,
			'orientation'          => $orientation,
			'template_field_count' => '2',
			'qr_position_x'        => (string) $qr_x,
			'qr_position_y'        => (string) $qr_y,
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		ob_end_clean();

		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE certificate_type = %s", $certificate_type ), ARRAY_A );

		unset( $_POST, $_SERVER['REQUEST_METHOD'] );
		return $row;
	}

	public function test_in_bounds_position_is_kept_as_is(): void {
		$row = $this->save_template( 'PositionBoundsInRange', 'landscape', 250.0, 180.0 );
		$this->assertEqualsWithDelta( 250.0, (float) $row['qr_position_x'], 0.01 );
		$this->assertEqualsWithDelta( 180.0, (float) $row['qr_position_y'], 0.01 );
	}

	public function test_out_of_bounds_position_resets_to_orientation_default(): void {
		// 250mm exceeds the 210mm portrait page width — must reset, not save as-is.
		$row = $this->save_template( 'PositionBoundsOutOfRange', 'portrait', 250.0, 180.0 );
		$this->assertEqualsWithDelta( 176.8, (float) $row['qr_position_x'], 0.01 );
		$this->assertEqualsWithDelta( 254.6, (float) $row['qr_position_y'], 0.01 );
	}
}
