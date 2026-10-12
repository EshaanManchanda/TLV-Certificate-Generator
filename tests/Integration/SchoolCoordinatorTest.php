<?php
/**
 * Capabilities and the School Coordinator role (7.9.0, includes/Core/capabilities.php): admins get
 * every capability through manage_options; a coordinator sees and acts on only their own school's
 * students and teachers — in the lists, by id, in Bulk Send, in Download Certificates, the edit
 * form and the import — whatever ids or filters the request carries.
 */

use CertificateGenerator\Admin\Pages\StudentsPage;
use CertificateGenerator\Database\CustomTables;

class SchoolCoordinatorTest extends WP_UnitTestCase {

	private int $admin;
	private int $coordinator;
	private array $ids = array();
	private array $tmp = array();

	public function set_up(): void {
		parent::set_up();
		do_action( 'init' ); // creates the role when missing
		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->coordinator = self::factory()->user->create( array( 'role' => 'certificate_generator_school_coordinator' ) );
		update_user_meta( $this->coordinator, 'certificate_generator_school', 'Alpha School' );

		global $wpdb;
		$table = CustomTables::instance()->get_table( 'students' );
		foreach ( array( 'Alpha One' => 'Alpha School', 'Alpha Two' => 'Alpha School', 'Beta One' => 'Beta School' ) as $name => $school ) {
			$wpdb->insert(
				$table,
				array(
					'student_name'     => $name,
					'email'            => sanitize_title( $name ) . '@example.test',
					'school_name'      => $school,
					'certificate_type' => 'PHPUnitCoord',
					'issue_date'       => '2026-01-15',
					'send_email'       => 1,
					'import_source'    => 'coord-test',
				)
			);
			$this->ids[ $name ] = (int) $wpdb->insert_id;
		}
	}

	public function tear_down(): void {
		foreach ( $this->tmp as $f ) {
			@unlink( $f );
		}
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$_FILES   = array();
		parent::tear_down();
	}

	private function count_students( string $where = '' ): int {
		global $wpdb;
		$table = CustomTables::instance()->get_table( 'students' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE import_source = 'coord-test'" . $where ); // phpcs:ignore
	}

	public function test_capabilities(): void {
		$caps = array( 'certificate_generator_view', 'certificate_generator_issue', 'certificate_generator_manage_templates', 'certificate_generator_manage_settings' );
		foreach ( $caps as $cap ) {
			$this->assertTrue( user_can( $this->admin, $cap ), "admin: $cap" );
		}
		$this->assertTrue( user_can( $this->coordinator, 'certificate_generator_view' ) );
		$this->assertTrue( user_can( $this->coordinator, 'certificate_generator_issue' ) );
		$this->assertFalse( user_can( $this->coordinator, 'certificate_generator_manage_templates' ) );
		$this->assertFalse( user_can( $this->coordinator, 'certificate_generator_manage_settings' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		foreach ( $caps as $cap ) {
			$this->assertFalse( user_can( $subscriber, $cap ), "subscriber: $cap" );
		}
	}

	public function test_school_scope(): void {
		wp_set_current_user( $this->admin );
		$this->assertNull( certificate_generator_user_school() );
		$this->assertTrue( certificate_generator_record_in_scope( 'students', $this->ids['Beta One'] ) );

		wp_set_current_user( $this->coordinator );
		$this->assertSame( 'Alpha School', certificate_generator_user_school() );
		$this->assertTrue( certificate_generator_record_in_scope( 'students', $this->ids['Alpha One'] ) );
		$this->assertFalse( certificate_generator_record_in_scope( 'students', $this->ids['Beta One'] ) );

		delete_user_meta( $this->coordinator, 'certificate_generator_school' );
		$this->assertSame( '', certificate_generator_user_school() );
		$this->assertFalse( certificate_generator_record_in_scope( 'students', $this->ids['Alpha One'] ), 'No school assigned: nothing' );
	}

	public function test_students_list_shows_only_own_school(): void {
		wp_set_current_user( $this->coordinator );
		$_GET = array( 'page' => 'cg-students' );
		ob_start();
		( new StudentsPage() )->render_list();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Alpha One', $html );
		$this->assertStringContainsString( 'Alpha Two', $html );
		$this->assertStringNotContainsString( 'Beta One', $html );
		$this->assertStringNotContainsString( 'Beta School', $html, 'Not even in the school filter dropdown' );
	}

	public function test_bulk_delete_ignores_other_schools_ids(): void {
		wp_set_current_user( $this->coordinator );
		$_GET  = array( 'page' => 'cg-students' );
		$_POST = array(
			'bulk_action'          => 'delete',
			'student_ids'          => array( $this->ids['Alpha One'], $this->ids['Beta One'] ),
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
		);
		ob_start();
		( new StudentsPage() )->render_list();
		ob_end_clean();
		$this->assertSame( 0, $this->count_students( " AND student_name = 'Alpha One'" ) );
		$this->assertSame( 1, $this->count_students( " AND student_name = 'Beta One'" ), 'Beta One survives' );
	}

	public function test_empty_table_is_admin_only(): void {
		wp_set_current_user( $this->coordinator );
		$_GET  = array( 'page' => 'cg-students' );
		$_POST = array(
			'bulk_action'          => 'empty_all',
			'empty_confirm'        => 'DELETE',
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
		);
		ob_start();
		( new StudentsPage() )->render_list();
		ob_end_clean();
		$this->assertSame( 3, $this->count_students() );
	}

	public function test_edit_form_refuses_other_school_and_keeps_own(): void {
		wp_set_current_user( $this->coordinator );
		$_GET = array( 'id' => $this->ids['Beta One'] );
		try {
			( new StudentsPage() )->render_edit();
			$this->fail( 'Opening another school\'s student should stop' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not found', strtolower( $e->getMessage() ) );
		}

		$_GET                      = array( 'id' => $this->ids['Alpha One'] );
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_student_nonce' => wp_create_nonce( 'cg_save_student' ),
			'student_name'     => 'Alpha One',
			'email'            => 'alpha-one@example.test',
			'school_name'      => 'Beta School', // tries to move the student
			'certificate_type' => 'PHPUnitCoord',
		);
		ob_start();
		( new StudentsPage() )->render_edit();
		ob_end_clean();
		unset( $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( 1, $this->count_students( " AND student_name = 'Alpha One' AND school_name = 'Alpha School'" ) );
	}

	public function test_bulk_send_and_download_are_scoped(): void {
		wp_set_current_user( $this->coordinator );
		$all = array(
			'post_types'        => array( 'students' ),
			'certificate_types' => array( 'PHPUnitCoord' ),
		);
		$this->assertSame( 2, (int) certificate_generator_count_filtered_recipients( $all ) );
		$this->assertSame( 0, (int) certificate_generator_count_filtered_recipients( $all + array( 'schools' => array( 'Beta School' ) ) ), 'Asking for another school returns nothing' );
		$this->assertSame( 2, certificate_generator_admin_cert_count( array( 'entity' => 'students', 'certificate_types' => array( 'PHPUnitCoord' ) ) ) );
		$this->assertSame( array(), certificate_generator_admin_cert_rows_by_ids( 'students', array( $this->ids['Beta One'] ) ) );
		$this->assertSame( array( 'alpha-one@example.test', 'alpha-two@example.test' ), certificate_generator_school_emails( 'Alpha School' ) );

		wp_set_current_user( $this->admin );
		$this->assertSame( 3, (int) certificate_generator_count_filtered_recipients( $all ) );
	}

	public function test_import_saves_every_row_to_own_school(): void {
		wp_set_current_user( $this->coordinator );
		$path = tempnam( sys_get_temp_dir(), 'cg_coord_' );
		file_put_contents( $path, "student_name,email,school_name,issue_date,certificate_type\nGamma New,gamma@example.test,Beta School,2026-01-15,PHPUnitCoord\n" );
		$this->tmp[] = $path;
		certificate_generator_import_run( 'students', $path, array( 'source' => 'coord-test' ) );
		$this->assertSame( 1, $this->count_students( " AND student_name = 'Gamma New' AND school_name = 'Alpha School'" ) );

		$no_school = tempnam( sys_get_temp_dir(), 'cg_coord_' );
		file_put_contents( $no_school, "student_name,email,issue_date,certificate_type\nDelta New,delta@example.test,2026-01-15,PHPUnitCoord\n" );
		$this->tmp[] = $no_school;
		$res         = certificate_generator_import_run( 'students', $no_school, array( 'source' => 'coord-test' ) );
		$this->assertSame( array(), $res['missing'], 'No school column needed' );
		$this->assertSame( 1, $this->count_students( " AND student_name = 'Delta New' AND school_name = 'Alpha School'" ) );

		$this->assertIsString( certificate_generator_import_run( 'schools', $path ), 'Schools import is admin-only' );
	}

	public function test_profile_field_only_admins_can_set(): void {
		wp_set_current_user( $this->coordinator );
		$_POST = array(
			'certificate_generator_user_school_nonce' => wp_create_nonce( 'certificate_generator_user_school_' . $this->coordinator ),
			'certificate_generator_school'            => 'Beta School',
		);
		certificate_generator_save_user_school_field( $this->coordinator );
		$this->assertSame( 'Alpha School', get_user_meta( $this->coordinator, 'certificate_generator_school', true ), 'A coordinator can\'t switch school' );

		wp_set_current_user( $this->admin );
		$_POST['certificate_generator_user_school_nonce'] = wp_create_nonce( 'certificate_generator_user_school_' . $this->coordinator );
		certificate_generator_save_user_school_field( $this->coordinator );
		$this->assertSame( 'Beta School', get_user_meta( $this->coordinator, 'certificate_generator_school', true ) );
	}
}
