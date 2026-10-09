<?php
/**
 * Regression guard for the dual serial-number-generator setup: the legacy
 * CG_Serial_Number_Generator (includes/Services/serial-generator.php) and the
 * namespaced SerialNumberService (src/Services/SerialNumberService.php) both
 * read/increment the SAME `cg_serial_seq_*` wp_options counter with copy-pasted
 * logic. They were never consolidated (see doc note in cleanup history) — this
 * test pins today's behavior so a change to one implementation's sequencing
 * that isn't mirrored in the other gets caught here instead of in production.
 */
class DualSerialGeneratorConsistencyTest extends WP_UnitTestCase {

	private function sequence_number( string $serial ): int {
		return (int) preg_replace( '/\D/', '', $serial );
	}

	public function test_legacy_then_modern_share_one_continuous_sequence(): void {
		$legacy = CG_Serial_Number_Generator::get_instance();
		$modern = new \CertificateGenerator\Services\SerialNumberService();

		$first  = $legacy->generate( 'regression_dual_test' );
		$second = $modern->generate( 'regression_dual_test' );

		$this->assertSame(
			$this->sequence_number( $first ) + 1,
			$this->sequence_number( $second ),
			'Legacy and modern serial generators must increment the same shared counter.'
		);
	}

	public function test_modern_then_legacy_share_one_continuous_sequence(): void {
		$modern = new \CertificateGenerator\Services\SerialNumberService();
		$legacy = CG_Serial_Number_Generator::get_instance();

		$first  = $modern->generate( 'regression_dual_test_reverse' );
		$second = $legacy->generate( 'regression_dual_test_reverse' );

		$this->assertSame(
			$this->sequence_number( $first ) + 1,
			$this->sequence_number( $second ),
			'Modern and legacy serial generators must increment the same shared counter.'
		);
	}

	public function test_both_implementations_use_the_shared_option_key(): void {
		CG_Serial_Number_Generator::get_instance()->generate( 'regression_dual_test_key' );

		$this->assertNotFalse(
			get_option( 'cg_serial_seq_all' ),
			'Both generators must draw from the single cg_serial_seq_all counter.'
		);
	}
}
