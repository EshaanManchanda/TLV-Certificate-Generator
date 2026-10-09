<?php
/**
 * Audit G2 / bugs 6–7: certificate files couldn't be guessed by counting ids,
 * the folder could be listed, and a fake X-Forwarded-For dodged the rate limit.
 */
class CertificatePrivacyTest extends WP_UnitTestCase {

	private array $server;

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		remove_all_filters( 'cg_client_ip' );
		parent::tear_down();
	}

	public function test_file_names_carry_a_secret_suffix_and_are_stable(): void {
		$a = cg_certificate_file_stem( 'students_row_1000' );
		$b = cg_certificate_file_stem( 'students_row_1001' );

		$this->assertMatchesRegularExpression( '/^certificate_students_row_1000_[0-9a-f]{12}$/', $a );
		$this->assertSame( $a, cg_certificate_file_stem( 'students_row_1000' ), 'same key, same file (cache still works)' );
		$this->assertNotSame( substr( $a, -12 ), substr( $b, -12 ) );
	}

	public function test_certificate_folder_cannot_be_listed(): void {
		$this->assertFileExists( cg_certificates_dir() . '/index.php' );
	}

	private function hit( string $action ): bool {
		return CertificateGenerator_SecurityHelper::check_rate_limit( $action, 2, 60 );
	}

	public function test_fake_forwarded_for_from_a_public_ip_does_not_reset_the_limit(): void {
		$action                 = 'cg_privacy_test_' . wp_rand();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7'; // public client, no proxy in front
		$this->hit( $action );
		$this->hit( $action );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . wp_rand( 1, 250 ); // spoofed
		$this->assertFalse( $this->hit( $action ), 'a forged header must not buy a fresh allowance' );
	}

	public function test_forwarded_for_is_used_behind_a_private_proxy(): void {
		$action                          = 'cg_privacy_test_' . wp_rand();
		$_SERVER['REMOTE_ADDR']          = '10.0.0.5'; // load balancer on the private network
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.10';
		$this->hit( $action );
		$this->hit( $action );

		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.11'; // a different real visitor
		$this->assertTrue( $this->hit( $action ) );
	}
}
