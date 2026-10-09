<?php
/**
 * Covers src/Services/SerialNumberService.php — serial generation format,
 * one shared sequence across certificate types, and serial verification.
 */
class SerialNumberServiceTest extends WP_UnitTestCase {

	private \CertificateGenerator\Services\SerialNumberService $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = new \CertificateGenerator\Services\SerialNumberService();
	}

	public function test_generate_uses_default_prefix_and_length(): void {
		$serial = $this->service->generate();

		$this->assertMatchesRegularExpression( '/^CERT-\d{8}$/', $serial );
	}

	public function test_generate_increments_sequence_for_same_type(): void {
		$first  = $this->service->generate( 'Gold' );
		$second = $this->service->generate( 'Gold' );

		$this->assertSame( '00000001', substr( $first, -8 ) );
		$this->assertSame( '00000002', substr( $second, -8 ) );
	}

	public function test_generate_shares_one_sequence_across_certificate_types(): void {
		// Types share the prefix, so per-type counters would both issue CERT-00000001.
		$type_a = $this->service->generate( 'TypeA' );
		$type_b = $this->service->generate( 'TypeB' );

		$this->assertNotSame( $type_a, $type_b );
		$this->assertSame( '00000001', substr( $type_a, -8 ) );
		$this->assertSame( '00000002', substr( $type_b, -8 ) );
	}

	public function test_verify_returns_invalid_for_unknown_serial(): void {
		$result = $this->service->verify( 'NOT-A-REAL-SERIAL' );

		$this->assertFalse( $result['valid'] );
		$this->assertNull( $result['data'] );
	}

	public function test_verify_returns_valid_and_not_expired(): void {
		$this->insert_certificate( 'VALID-SERIAL-001', null );

		$result = $this->service->verify( 'VALID-SERIAL-001' );

		$this->assertTrue( $result['valid'] );
		$this->assertFalse( $result['expired'] );
	}

	public function test_verify_returns_expired_when_expires_at_is_past(): void {
		$this->insert_certificate( 'EXPIRED-SERIAL-001', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		$result = $this->service->verify( 'EXPIRED-SERIAL-001' );

		$this->assertTrue( $result['valid'] );
		$this->assertTrue( $result['expired'] );
	}

	private function insert_certificate( string $serial, ?string $expires_at ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			array(
				'student_name'     => 'Test Student',
				'certificate_data' => '{}',
				'expires_at'       => $expires_at,
				'serial_number'    => $serial,
			)
		);
	}
}
