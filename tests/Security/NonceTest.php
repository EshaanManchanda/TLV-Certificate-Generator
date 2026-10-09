<?php
/**
 * Confirms the admin CRUD save paths reject POSTs without a valid nonce
 * instead of silently saving — covers src/Admin/Pages/StudentsPage.php.
 */
class NonceTest extends WP_UnitTestCase {

	public function test_save_without_nonce_is_rejected_and_nothing_is_inserted(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$table          = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$count_before   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'student_name' => 'Nonce Bypass Attempt',
			'email'        => 'nonce-bypass@example.com',
		);
		// Deliberately no cg_student_nonce.

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();

		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$this->assertStringContainsString( 'Security check failed', $output );

		$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$this->assertSame( $count_before, $count_after, 'No row should be inserted when the nonce check fails.' );
	}
}
