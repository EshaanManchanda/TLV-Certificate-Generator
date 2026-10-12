<?php
/**
 * Public verification by unguessable code: a code gets the full answer, a bare
 * (sequential, walkable) serial gets a masked name, and migration 011 backfills
 * codes for certificates issued before it.
 */
use CertificateGenerator\Database\Migrations\Migration011_VerificationCode;
use CertificateGenerator\Services\SerialNumberService;
use CertificateGenerator\Services\VerificationCodeService;

class VerificationCodeTest extends WP_UnitTestCase {

	private SerialNumberService $service;

	public function set_up(): void {
		parent::set_up();
		$this->service = new SerialNumberService();
	}

	public function test_inserted_record_stores_the_code_and_qr_carries_it(): void {
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma', 'certificate_type' => 'Gold' ), 'CODE-T-001' );
		$code = VerificationCodeService::for_serial( 'CODE-T-001' );

		$this->assertSame( $code, $this->column( 'certificate_generator', 'CODE-T-001' ) );
		$this->assertSame( $code, $this->column( 'cg_certificates', 'CODE-T-001' ) );
		$qr = CertificateGenerator_QR_Code_Generator::get_instance()->generate_qr_data( array(), 'CODE-T-001' );
		$this->assertStringContainsString( 'code=' . $code, $qr );
		$this->assertStringNotContainsString( 'CODE-T-001', $qr );
	}

	public function test_code_gets_full_answer_serial_gets_masked_name(): void {
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma', 'certificate_type' => 'Gold' ), 'CODE-T-002' );
		$code = VerificationCodeService::for_serial( 'CODE-T-002' );

		$by_code = $this->service->verify( strtolower( VerificationCodeService::format( $code ) ) );
		$this->assertTrue( $by_code['valid'] );
		$this->assertSame( 'Riya Sharma', $by_code['data']['student_name'] );
		$this->assertFalse( $by_code['data']['masked'] );
		$this->assertSame( VerificationCodeService::format( $code ), $by_code['data']['verification_code'] );

		$by_serial = $this->service->verify( 'CODE-T-002' );
		$this->assertTrue( $by_serial['valid'] );
		$this->assertSame( 'R*** S*****', $by_serial['data']['student_name'] );
		$this->assertTrue( $by_serial['data']['masked'] );
		$this->assertSame( '', $by_serial['data']['verification_code'] );
	}

	public function test_serial_full_details_option_restores_old_answer(): void {
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma' ), 'CODE-T-003' );
		update_option( 'certificate_generator_verify_serial_full_details', '1' );

		$this->assertSame( 'Riya Sharma', $this->service->verify( 'CODE-T-003' )['data']['student_name'] );
	}

	public function test_revoked_reason_only_on_full_answer(): void {
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma' ), 'CODE-T-004' );
		certificate_generator_set_certificate_revoked( 'CODE-T-004', 'issued in error' );

		$this->assertStringContainsString( 'issued in error', $this->service->verify( VerificationCodeService::for_serial( 'CODE-T-004' ) )['message'] );
		$this->assertStringNotContainsString( 'issued in error', $this->service->verify( 'CODE-T-004' )['message'] );
	}

	public function test_mask_name_is_multibyte_safe(): void {
		$this->assertSame( 'र*** श****', SerialNumberService::mask_name( 'रिया शर्मा' ) );
	}

	public function test_backfill_fills_codes_for_old_rows(): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'certificate_generator', array( 'student_name' => 'Old', 'certificate_data' => '{}', 'serial_number' => 'CODE-OLD-001' ) );
		$this->assertNull( $this->column( 'certificate_generator', 'CODE-OLD-001' ) );

		Migration011_VerificationCode::backfill( 'certificate_generator' );

		$this->assertSame( VerificationCodeService::for_serial( 'CODE-OLD-001' ), $this->column( 'certificate_generator', 'CODE-OLD-001' ) );
	}

	private function column( string $table, string $serial ): ?string {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT verification_code FROM {$wpdb->prefix}{$table} WHERE serial_number = %s", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $v ? null : (string) $v;
	}
}
