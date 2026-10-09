<?php
/**
 * First-run: shared checklist steps and the test-certificate guard rails.
 */

use CertificateGenerator\Admin\TestCertificate;

class TestCertificateTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( TestCertificate::DONE_OPTION );
		parent::tear_down();
	}

	public function test_missing_template_explains_instead_of_sending(): void {
		$user   = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
		$result = TestCertificate::send( 999999, $user );
		$this->assertFalse( $result['ok'] );
		$this->assertFalse( (bool) get_option( TestCertificate::DONE_OPTION ) );
	}

	public function test_checklist_has_test_certificate_step_and_no_migration_step(): void {
		$labels = array_column( cg_setup_steps( 1, 0, false ), 'label' );
		$this->assertContains( 'Email yourself a test certificate', $labels );
		$this->assertNotContains( 'Certificate records in custom table', $labels );

		update_option( TestCertificate::DONE_OPTION, 1 );
		$this->assertTrue( cg_setup_steps( 1, 0, false )[1]['done'] );
	}
}
