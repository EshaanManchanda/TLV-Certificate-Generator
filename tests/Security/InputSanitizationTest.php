<?php
/**
 * Feeds SQLi/XSS-shaped payloads through the extra_fields save path
 * (src/Admin/Pages/StudentsPage.php render_edit) and confirms the stored
 * result is inert: no live <script> tag, and no other row is disturbed.
 */
class InputSanitizationTest extends WP_UnitTestCase {

	public function test_script_tag_in_extra_field_value_is_stripped(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_student_nonce' => wp_create_nonce( 'cg_save_student' ),
			'student_name'     => 'XSS Test',
			'email'            => 'xss-test@example.com',
			'extra_key'        => array( 'note' ),
			'extra_val'        => array( '<script>alert(1)</script>' ),
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_edit();
		ob_get_clean();

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT extra_fields FROM {$table} WHERE email = %s", 'xss-test@example.com' ),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		// sanitize_text_field() strips <script>...</script> (tag + content) entirely,
		// so the whole value is dropped as empty rather than stored — extra_fields may
		// legitimately be null here. Either way, no live script tag may survive.
		$this->assertStringNotContainsString( '<script>', $row['extra_fields'] ?? '' );
	}

	public function test_sql_injection_payload_in_extra_key_does_not_affect_other_rows(): void {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );

		$wpdb->insert(
			$table,
			array(
				'student_name' => 'Bystander',
				'email'        => 'bystander@example.com',
				'created_at'   => current_time( 'mysql' ),
			)
		);

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_student_nonce' => wp_create_nonce( 'cg_save_student' ),
			'student_name'     => 'SQLi Test',
			'email'            => 'sqli-test@example.com',
			'extra_key'        => array( "'; DROP TABLE {$table}; --" ),
			'extra_val'        => array( "' OR 1=1 --" ),
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_edit();
		ob_get_clean();

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// The table must still exist and the unrelated row must be untouched.
		$this->assertSame(
			$table,
			$wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) )
		);
		$this->assertSame(
			'Bystander',
			$wpdb->get_var( $wpdb->prepare( "SELECT student_name FROM {$table} WHERE email = %s", 'bystander@example.com' ) )
		);
	}
}
