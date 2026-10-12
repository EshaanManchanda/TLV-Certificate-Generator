<?php
/**
 * Text is vertically centred on the field's Y, as the template editor shows it
 * (certificate_generator_line_baselines(), includes/Services/certificate-search.php).
 */

class LineBaselinesTest extends WP_UnitTestCase {

	public function test_single_line_sits_half_a_cap_height_below_the_centre(): void {
		[ $baselines, $line_height ] = certificate_generator_line_baselines( 100.0, 72.0, 1 ); // 72 pt = 25.4 mm em
		$this->assertEqualsWithDelta( 100 + 0.35 * 25.4, $baselines[0], 0.001 );
		$this->assertEqualsWithDelta( 1.2 * 25.4, $line_height, 0.001 );
	}

	public function test_lines_are_centred_as_a_block(): void {
		[ $baselines, $line_height ] = certificate_generator_line_baselines( 100.0, 20.0, 3 );
		$this->assertCount( 3, $baselines );
		$this->assertEqualsWithDelta( $line_height, $baselines[1] - $baselines[0], 0.001 );
		$this->assertEqualsWithDelta( $line_height, $baselines[2] - $baselines[1], 0.001 );
		// The middle line is the one centred on Y.
		$this->assertEqualsWithDelta( certificate_generator_line_baselines( 100.0, 20.0, 1 )[0][0], $baselines[1], 0.001 );
	}
}
