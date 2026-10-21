<?php
/**
 * QR code and Serial number as Field Slot types (7.9.4): the template editor saves them and
 * turns an old template's QR / serial settings into slots; the PDF draws a serial slot with
 * its own prefix and font and a QR slot at its position; the old template-level QR / serial
 * are drawn only for templates without such a slot.
 */

use CertificateGenerator\Database\CustomTables;

class QrSerialSlotsTest extends WP_UnitTestCase {

	private static string $image;
	private static string $image_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$dir = wp_upload_dir()['basedir'] . '/cg-phpunit-slots';
		wp_mkdir_p( $dir );
		self::$image     = $dir . '/bg.png';
		self::$image_url = wp_upload_dir()['baseurl'] . '/cg-phpunit-slots/bg.png';
		$im = imagecreatetruecolor( 400, 300 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagepng( $im, self::$image );
		imagedestroy( $im );
	}

	public static function tear_down_after_class(): void {
		@unlink( self::$image );
		@rmdir( dirname( self::$image ) );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	private function template( string $type, array $extra, array $cols = array() ): int {
		global $wpdb;
		$wpdb->insert(
			CustomTables::instance()->get_table( 'certificate_templates' ),
			$cols + array(
				'template_name'    => "Slots $type",
				'certificate_type' => $type,
				'template_url'     => self::$image_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'font_style'       => 'helvetica',
				'extra_fields'     => wp_json_encode( $extra ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Render a certificate for a new student; return the PDF's text (all streams inflated) and its image count. */
	private function render( string $type ): array {
		global $wpdb;
		$wpdb->insert( CustomTables::instance()->get_table( 'students' ), array( 'student_name' => 'Slot Student', 'email' => strtolower( $type ) . '@slots.test', 'certificate_type' => $type, 'issue_date' => '2026-10-21' ) );
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CustomTables::instance()->get_table( 'students' ) . ' WHERE id = %d', $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = certificate_generator_generate_for_row( 'students', $row );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$path = (string) $wpdb->get_var( $wpdb->prepare( "SELECT pdf_path FROM {$wpdb->prefix}cg_certificates WHERE id = %d", $result['certificate_id'] ) );
		$raw  = (string) file_get_contents( $path );
		$text = '';
		preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m );
		foreach ( $m[1] as $stream ) {
			$text .= ( @gzuncompress( $stream ) ?: $stream ) . "\n";
		}
		$serial = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT serial_number FROM ' . CustomTables::instance()->get_table( 'students' ) . ' WHERE id = %d', $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array( $text, substr_count( $raw, '/Subtype /Image' ), $serial );
	}

	public function test_serial_and_qr_slots_render_and_replace_the_template_level_ones(): void {
		$this->template(
			'SlotsNew',
			array(
				'template_field_count' => 3,
				'field_1_name'         => 'student_name',
				'field_1_position_x'   => 148,
				'field_1_position_y'   => 80,
				'field_2_name'         => 'serial_number',
				'field_2_type'         => 'serial',
				'field_2_text'         => 'Certificate No.',
				'field_2_position_x'   => 148,
				'field_2_position_y'   => 190,
				'field_2_font_color'   => '#ff0000',
				'field_2_alignment'    => 'R',
				'field_3_name'         => 'qr_code',
				'field_3_type'         => 'qr',
				'field_3_position_x'   => 20,
				'field_3_position_y'   => 20,
				'field_3_width'        => 25,
			),
			array( 'qr_enabled' => 1, 'serial_number_display' => 1 ) // old settings still on: the slots win
		);
		list( $text, $images, $serial ) = $this->render( 'SlotsNew' );
		$this->assertNotSame( '', $serial, 'the first render issued a serial' );
		$this->assertStringContainsString( "(Certificate No. $serial) Tj", $text, 'the serial slot prints on the first render, with its prefix' );
		$this->assertStringContainsString( '1.000 0.000 0.000 rg', $text, 'in the slot colour' );
		$this->assertStringNotContainsString( "(Serial: $serial)", $text, 'no template-level serial as well' );
		$this->assertSame( 2, $images, 'background + one QR code (the template-level QR is not drawn too)' );
	}

	public function test_templates_without_slots_keep_the_template_level_qr_and_serial(): void {
		$this->template(
			'SlotsOld',
			array( 'template_field_count' => 1, 'field_1_name' => 'student_name', 'field_1_position_x' => 148, 'field_1_position_y' => 80 ),
			array( 'qr_enabled' => 1, 'qr_position_x' => 250, 'qr_position_y' => 170, 'qr_size' => 20, 'serial_number_display' => 1, 'serial_number_position_x' => 105, 'serial_number_position_y' => 200 )
		);
		list( $text, $images, $serial ) = $this->render( 'SlotsOld' );
		$this->assertStringContainsString( "(Serial: $serial) Tj", $text );
		$this->assertSame( 2, $images );
	}

	public function test_serial_slot_text_helper(): void {
		$slots = array(
			'serial_number' => array( 'type' => 'serial', 'prefix' => 'No.' ),
			'student_name'  => array( 'type' => 'text' ),
		);
		$this->assertSame( array( 'student_name' => 'Asha', 'serial_number' => 'No. CERT-7' ), certificate_generator_fill_serial_slots( array( 'student_name' => 'Asha' ), $slots, 'CERT-7' ) );
		$this->assertSame( '', certificate_generator_fill_serial_slots( array(), $slots, '' )['serial_number'] );
		$this->assertSame( array( 'serial', 'text' ), certificate_generator_slot_types( $slots ) );
	}

	public function test_editor_moves_old_settings_into_slots_and_saves_the_new_types(): void {
		global $wpdb;
		$id = $this->template(
			'SlotsEdit',
			array( 'template_field_count' => 2, 'field_1_name' => 'student_name', 'field_2_name' => 'school_name' ),
			array( 'qr_enabled' => 1, 'qr_position_x' => 200, 'qr_position_y' => 150, 'qr_size' => 22, 'serial_number_display' => 1, 'serial_number_position_x' => 105, 'serial_number_position_y' => 200, 'serial_number_font_size' => 10 )
		);

		$_GET['id'] = $id;
		$page       = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'QR code + Serial number now live in the Field Slots below', $html );
		$this->assertStringContainsString( 'name="template_field_count"' . "\n" . "\t\t\t\t\t\t\t" . 'value="4"', $html );
		$this->assertMatchesRegularExpression( '/id="field_3_position_x"[^>]*value="200"/s', $html );
		$this->assertMatchesRegularExpression( '/<option value="qr"\s+selected/', $html );
		$this->assertStringNotContainsString( 'QR Code Settings', $html, 'the old section is gone' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_template_nonce'    => wp_create_nonce( 'cg_save_template' ),
			'template_name'        => 'Slots Edit',
			'certificate_type'     => 'SlotsEdit',
			'status'               => 'published',
			'template_field_count' => 3,
			'field_1_name'         => 'student_name',
			'field_1_type'         => 'text',
			'field_2_name'         => '',
			'field_2_type'         => 'serial',
			'field_2_text'         => 'Certificate No.',
			'field_2_font_size'    => '14',
			'field_2_alignment'    => 'l',
			'field_3_name'         => '',
			'field_3_type'         => 'qr',
			'field_3_position_x'   => '200',
			'field_3_position_y'   => '150',
			'field_3_width'        => '22',
		);
		ob_start();
		$page->render_edit();
		$this->assertStringContainsString( 'Template updated.', (string) ob_get_clean() );

		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CustomTables::instance()->get_table( 'certificate_templates' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$extra = json_decode( $row['extra_fields'], true );
		$this->assertSame( array( 'serial', 'serial_number', 'Certificate No.', 14.0, 'L' ), array( $extra['field_2_type'], $extra['field_2_name'], $extra['field_2_text'], (float) $extra['field_2_font_size'], $extra['field_2_alignment'] ) );
		$this->assertSame( array( 'qr', 'qr_code', 22.0 ), array( $extra['field_3_type'], $extra['field_3_name'], (float) $extra['field_3_width'] ) );
		$this->assertSame( array( '0', '0' ), array( (string) $row['qr_enabled'], (string) $row['serial_number_display'] ), 'the template-level settings are off once they live in slots' );
	}
}
