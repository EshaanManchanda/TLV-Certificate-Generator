<?php
/**
 * Confirms admin CRUD pages reject users below manage_options — covers the
 * current_user_can( 'manage_options' ) guards in src/Admin/Pages/*.
 */
class PermissionTest extends WP_UnitTestCase {

	public function test_subscriber_cannot_view_students_list(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();

		$this->expectException( WPDieException::class );
		$page->render_list();
	}

	public function test_subscriber_cannot_view_student_edit_form(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();

		$this->expectException( WPDieException::class );
		$page->render_edit();
	}

	public function test_logged_out_user_cannot_view_students_list(): void {
		wp_set_current_user( 0 );

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();

		$this->expectException( WPDieException::class );
		$page->render_list();
	}

	public function test_subscriber_cannot_view_duplicate_serial_report(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		ob_start();
		CG_Bulk_Serial_Generator::get_instance()->render_bulk_serial_page();
		$this->assertSame( '', ob_get_clean() );
	}
}
