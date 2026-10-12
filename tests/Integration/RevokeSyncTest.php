<?php
/**
 * Revoke/reinstate keeps both certificate tables in sync and fires hooks, and a
 * certificate recorded only in wp_cg_certificates still verifies.
 */
class RevokeSyncTest extends WP_UnitTestCase {

	private \CertificateGenerator\Services\SerialNumberService $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = new \CertificateGenerator\Services\SerialNumberService();
	}

	public function test_revoke_updates_both_tables_and_fires_hook(): void {
		$this->insert_legacy( 'SYNC-001' );
		$this->insert_modern( 'SYNC-001', 1 );
		$fired = array();
		add_action(
			'certificate_generator_certificate_revoked',
			function ( $serial, $reason ) use ( &$fired ) {
				$fired[] = array( $serial, $reason );
			},
			10,
			2
		);

		$this->assertTrue( certificate_generator_set_certificate_revoked( 'SYNC-001', 'issued in error' ) );

		$this->assertSame( 'revoked', $this->modern_status( 'SYNC-001' ) );
		$this->assertTrue( ! empty( $this->service->verify( 'SYNC-001' )['revoked'] ) );
		$this->assertSame( array( array( 'SYNC-001', 'issued in error' ) ), $fired );
	}

	public function test_reinstate_restores_sent_status_and_fires_hook(): void {
		$this->insert_legacy( 'SYNC-002' );
		$this->insert_modern( 'SYNC-002', 1 );
		certificate_generator_set_certificate_revoked( 'SYNC-002', '' );
		$fired = 0;
		add_action(
			'certificate_generator_certificate_reinstated',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		$this->assertTrue( certificate_generator_set_certificate_revoked( 'SYNC-002', null ) );

		$this->assertSame( 'sent', $this->modern_status( 'SYNC-002' ) );
		$this->assertTrue( $this->service->verify( 'SYNC-002' )['valid'] );
		$this->assertSame( 1, $fired );
	}

	public function test_unknown_serial_returns_false(): void {
		$this->assertFalse( certificate_generator_set_certificate_revoked( 'NOPE-404', 'x' ) );
	}

	public function test_serial_only_in_modern_table_verifies_and_can_be_revoked(): void {
		$this->insert_modern( 'MODERN-ONLY-001', 0 );

		$result = $this->service->verify( 'MODERN-ONLY-001' );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'M***** S******', $result['data']['student_name'] ); // serial-only answer is masked

		certificate_generator_set_certificate_revoked( 'MODERN-ONLY-001', 'duplicate' );
		$this->assertTrue( ! empty( $this->service->verify( 'MODERN-ONLY-001' )['revoked'] ) );
	}

	private function insert_legacy( string $serial ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			array(
				'student_name'     => 'Legacy Student',
				'certificate_data' => '{}',
				'serial_number'    => $serial,
			)
		);
	}

	private function insert_modern( string $serial, int $email_sent ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cg_certificates',
			array(
				'recipient_name'   => 'Modern Student',
				'certificate_type' => 'Participation',
				'serial_number'    => $serial,
				'status'           => $email_sent ? 'sent' : 'generated',
				'email_sent'       => $email_sent,
			)
		);
	}

	private function modern_status( string $serial ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cg_certificates WHERE serial_number = %s", $serial ) );
	}
}
