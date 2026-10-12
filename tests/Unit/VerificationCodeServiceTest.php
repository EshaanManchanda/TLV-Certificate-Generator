<?php
use CertificateGenerator\Services\VerificationCodeService as Codes;
use PHPUnit\Framework\TestCase;

class VerificationCodeServiceTest extends TestCase {

	public function test_code_is_stable_well_formed_and_secret_dependent(): void {
		$code = Codes::for_serial( 'CERT-00000001', 'secret-a' );

		$this->assertSame( $code, Codes::for_serial( 'CERT-00000001', 'secret-a' ) );
		$this->assertTrue( Codes::is_well_formed( $code ) );
		$this->assertNotSame( $code, Codes::for_serial( 'CERT-00000002', 'secret-a' ) );
		$this->assertNotSame( $code, Codes::for_serial( 'CERT-00000001', 'secret-b' ) );
		$this->assertSame( '', Codes::for_serial( '', 'secret-a' ) );
	}

	public function test_any_single_typo_fails_the_check_character(): void {
		$code = Codes::for_serial( 'CERT-00000042', 'k' );
		for ( $i = 0; $i < Codes::LENGTH; $i++ ) {
			foreach ( str_split( Codes::ALPHABET ) as $c ) {
				if ( $c === $code[ $i ] ) {
					continue;
				}
				$typo      = $code;
				$typo[ $i ] = $c;
				$this->assertFalse( Codes::is_well_formed( $typo ), "typo at $i accepted" );
			}
		}
	}

	public function test_normalize_accepts_display_form_and_lookalikes(): void {
		$code    = Codes::for_serial( 'CERT-00000007', 'k' );
		$display = strtolower( Codes::format( $code ) );

		$this->assertMatchesRegularExpression( '/^.{4}-.{4}-.{4}$/', Codes::format( $code ) );
		$this->assertSame( $code, Codes::normalize( " $display " ) );
		$this->assertSame( '0110', Codes::normalize( 'O-I.L_0' ) );
	}

	public function test_serials_are_not_well_formed_codes(): void {
		$this->assertFalse( Codes::is_well_formed( Codes::normalize( 'CERT-2026-00001' ) ) );
		$this->assertFalse( Codes::is_well_formed( 'ABC' ) );
	}
}
