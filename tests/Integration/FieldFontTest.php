<?php
/**
 * Each template field can have its own font, size and colour; an empty value falls
 * back to the template's.
 */
class FieldFontTest extends WP_UnitTestCase {

	public function test_field_positions_carry_per_field_font(): void {
		$meta = array(
			'field_1_position_x' => array( 10 ),
			'field_1_position_y' => array( 20 ),
			'field_1_font_style' => array( 'times' ),
			'field_1_font_size'  => array( '30' ),
			'field_1_font_color' => array( '#ff0000' ),
			'field_2_position_x' => array( 10 ),
			'field_2_position_y' => array( 40 ),
		);
		$pos  = certificate_generator_build_field_positions( array( 'student_name', 'certificate_type' ), $meta, true );

		$this->assertSame( array( 'times', '30', '#ff0000' ), array( $pos['student_name']['font_style'], $pos['student_name']['font_size'], $pos['student_name']['font_color'] ) );
		$this->assertSame( array( '', '', '' ), array( $pos['certificate_type']['font_style'], $pos['certificate_type']['font_size'], $pos['certificate_type']['font_color'] ) );
	}

	public function test_apply_field_font_uses_field_values_then_template_defaults(): void {
		$pdf = new class( 'L', 'mm', 'A4' ) extends CertificateGenerator_FPDF {
			public function state(): array {
				return array( $this->FontFamily, $this->FontSizePt, $this->TextColor );
			}
		};
		$pdf->AddPage();
		$fonts = CertificateGenerator_FontManager::getInstance();

		$size = certificate_generator_apply_field_font( $pdf, $fonts, array( 'font_style' => 'times', 'font_size' => '30', 'font_color' => '#ff0000' ), 'helvetica', 12.0, '#000000' );
		$this->assertSame( 30.0, $size );
		$this->assertSame( array( 'times', 30.0, '1.000 0.000 0.000 rg' ), $pdf->state() );

		$size = certificate_generator_apply_field_font( $pdf, $fonts, array( 'font_style' => '', 'font_size' => '', 'font_color' => 'not-a-colour' ), 'helvetica', 12.0, '#0000ff' );
		$this->assertSame( 12.0, $size );
		$this->assertSame( array( 'helvetica', 12.0, '0.000 0.000 1.000 rg' ), $pdf->state() );
	}

	public function test_custom_text_slots_get_unique_keys_and_their_text(): void {
		$meta   = array(
			'field_1_name'           => array( 'custom_text' ),
			'field_1_text'           => array( 'Awarded to {student_name}' ),
			'field_2_name'           => array( 'custom_text' ),
			'field_2_text'           => array( 'For {certificate_type} on {issue_date}' ),
			'field_2_text_transform' => array( 'upper' ),
			'field_3_name'           => array( 'student_name' ),
			'field_3_text'           => array( 'ignored on a data slot' ),
		);
		$fields = certificate_generator_resolve_template_fields( $meta, 'Gold', 3 );
		$this->assertSame( array( 'custom_text_1', 'custom_text_2', 'student_name' ), $fields );

		$pos  = certificate_generator_build_field_positions( $fields, $meta, true );
		$data = certificate_generator_fill_custom_text(
			array( 'student_name' => 'Riya' ),
			$pos,
			array( 'student_name' => 'Riya', 'certificate_type' => 'Gold', 'issue_date' => '01-02-2026' )
		);
		$this->assertSame( 'Awarded to Riya', $data['custom_text_1'] );
		$this->assertSame( 'FOR GOLD ON 01-02-2026', certificate_generator_transform_text( $pos['custom_text_2'], $data['custom_text_2'] ) );
		$this->assertSame( '', $pos['student_name']['text'] );
	}

	public function test_merge_tags_drop_unknown_tags_without_double_spaces(): void {
		$this->assertSame( 'Riya of Class 5', certificate_generator_merge_tags( 'Riya {missing} of Class {CLASS}', array( 'class' => 5 ) ) );
		$this->assertSame( 'riya', certificate_generator_transform_text( array( 'text_transform' => '' ), 'riya' ) );
		$this->assertSame( 'ÉLAN', certificate_generator_transform_text( array( 'text_transform' => 'upper' ), 'élan' ) );
	}

	public function test_rendered_pdf_contains_merged_custom_text(): void {
		global $wpdb;
		$upload = wp_upload_dir();
		$image  = $upload['basedir'] . '/cg-phpunit-customtext.png';
		$im     = imagecreatetruecolor( 800, 600 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagepng( $im, $image );
		imagedestroy( $im );

		$wpdb->insert(
			$wpdb->prefix . 'cg_certificate_templates',
			array(
				'template_name'    => 'PHPUnit Custom Text',
				'certificate_type' => 'PHPUnitCustomText',
				'template_url'     => $upload['baseurl'] . '/cg-phpunit-customtext.png',
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode(
					array(
						'template_field_count'   => 2,
						'field_1_name'           => 'custom_text',
						'field_1_text'           => 'Awarded to {student_name} of {school_name}',
						'field_1_position_x'     => 20,
						'field_1_position_y'     => 50,
						'field_1_width'          => 250,
						'field_1_text_transform' => 'upper',
						'field_2_name'           => 'issue_date',
						'field_2_position_x'     => 20,
						'field_2_position_y'     => 80,
					)
				),
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'cg_students',
			array(
				'student_name'     => 'Riya Sharma',
				'school_name'      => 'Green Valley',
				'email'            => 'customtext@example.test',
				'certificate_type' => 'PHPUnitCustomText',
				'issue_date'       => current_time( 'Y-m-d' ),
			)
		);
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cg_students WHERE id = %d", $wpdb->insert_id ), ARRAY_A );

		$url = certificate_generator_generate_certificate_pdf( (int) $row['id'], array(), $row );
		$this->assertNotEmpty( $url );
		$pdf = (string) file_get_contents( str_replace( $upload['baseurl'], $upload['basedir'], $url ) );
		@unlink( $image );

		$text = '';
		preg_match_all( '/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m );
		foreach ( $m[1] as $stream ) {
			$text .= (string) @gzuncompress( $stream ) . $stream;
		}
		$this->assertStringContainsString( 'AWARDED TO RIYA SHARMA OF GREEN VALLEY', $text );
	}
}
