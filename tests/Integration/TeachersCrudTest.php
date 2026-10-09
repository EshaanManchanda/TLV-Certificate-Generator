<?php
/**
 * CRUD + bulk-action + filter coverage for src/Admin/Pages/TeachersPage.php.
 * See StudentsCrudTest.php for the [WB]/[BB] tagging convention.
 */
class TeachersCrudTest extends WP_UnitTestCase {

	private string $table;
	private string $events_table;

	public function set_up(): void {
		parent::set_up();
		$this->table        = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'teachers' );
		$this->events_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'events' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tear_down(): void {
		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	private function make_event( string $code ): int {
		global $wpdb;
		$wpdb->insert(
			$this->events_table,
			array(
				'event_code' => $code,
				'event_name' => $code . ' Name',
				'status'     => 'open',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** [WB]+[BB] valid create writes the row and shows a success notice. */
	public function test_create_teacher_success(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_teacher_nonce' => wp_create_nonce( 'cg_save_teacher' ),
			'teacher_name'     => 'Diana Teach',
			'email'            => 'diana@example.com',
			'department'       => 'Science',
		);

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Teacher added.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE email = %s", 'diana@example.com' ), ARRAY_A );
		$this->assertNotNull( $row ); // [WB]
		$this->assertSame( 'Science', $row['department'] );
	}

	/** [BB] missing required name/email fails validation; [WB] no row written. */
	public function test_create_teacher_missing_fields_fails(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_teacher_nonce' => wp_create_nonce( 'cg_save_teacher' ),
			'teacher_name'     => '',
			'email'            => '',
		);

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Teacher name is required.', $output ); // [BB]
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	/** [BB] created teacher appears in the rendered list. */
	public function test_teacher_appears_in_list_output(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'teacher_name' => 'Visible Teacher', 'email' => 'vt@example.com', 'created_at' => current_time( 'mysql' ) ) );

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Visible Teacher', $output ); // [BB]
	}

	/** [WB]+[BB] update in place, no duplicate row created. */
	public function test_update_teacher_success(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'teacher_name' => 'Old Name', 'email' => 'teach@example.com', 'created_at' => current_time( 'mysql' ) ) );
		$id = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['id']                = $id;
		$_POST                     = array(
			'cg_teacher_nonce' => wp_create_nonce( 'cg_save_teacher' ),
			'teacher_name'     => 'New Name',
			'email'            => 'teach@example.com',
		);

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Teacher updated.', $output ); // [BB]
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE email = 'teach@example.com'" ) ); // [WB]
		$this->assertSame( 'New Name', $wpdb->get_var( $wpdb->prepare( "SELECT teacher_name FROM {$this->table} WHERE id = %d", $id ) ) );
	}

	/** [WB] delete removes only the targeted row (bulk form reused for delete, per TeachersPage). */
	public function test_bulk_delete_removes_only_selected_row(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'teacher_name' => 'Delete Me', 'email' => 'del@example.com', 'created_at' => current_time( 'mysql' ) ) );
		$to_delete = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'teacher_name' => 'Keep Me', 'email' => 'keep@example.com', 'created_at' => current_time( 'mysql' ) ) );
		$to_keep = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_teacher_nonce' => wp_create_nonce( 'cg_delete_teacher' ),
			'bulk_action'             => 'delete',
			'teacher_ids'             => array( $to_delete ),
		);

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_list();
		ob_get_clean();

		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_delete ), ARRAY_A ) ); // [WB]
		$this->assertNotNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_keep ), ARRAY_A ) ); // [WB]
	}

	/** [WB] bulk_edit updates department/school/event_id together across selected rows only. */
	public function test_bulk_edit_updates_selected_rows_including_event(): void {
		global $wpdb;
		$event_id = $this->make_event( 'T-BULK-01' );

		$wpdb->insert( $this->table, array( 'teacher_name' => 'T1', 'email' => 't1@example.com', 'department' => 'Old Dept', 'created_at' => current_time( 'mysql' ) ) );
		$id1 = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'teacher_name' => 'T2', 'email' => 't2@example.com', 'department' => 'Old Dept', 'created_at' => current_time( 'mysql' ) ) );
		$id2_untouched = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_teacher_nonce' => wp_create_nonce( 'cg_delete_teacher' ),
			'bulk_action'             => 'bulk_edit',
			'teacher_ids'             => array( $id1 ),
			'bulk_department'         => 'New Dept',
			'bulk_event_id'           => (string) $event_id,
		);

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( '1 teacher(s) updated via bulk edit.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id1 ), ARRAY_A );
		$this->assertSame( 'New Dept', $row['department'] ); // [WB]
		$this->assertSame( (string) $event_id, (string) $row['event_id'] );

		$untouched = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id2_untouched ), ARRAY_A );
		$this->assertSame( 'Old Dept', $untouched['department'] ); // [WB] not selected
	}

	/** [BB] event_filter narrows the rendered teacher list to only the linked event. */
	public function test_event_filter_shows_only_matching_teachers(): void {
		global $wpdb;
		$event_a = $this->make_event( 'T-FILT-A' );

		$wpdb->insert( $this->table, array( 'teacher_name' => 'In Event', 'email' => 'inevt@example.com', 'event_id' => $event_a, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'teacher_name' => 'No Event', 'email' => 'noevt@example.com', 'created_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'event_filter' => (string) $event_a );

		$page = new \CertificateGenerator\Admin\Pages\TeachersPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'In Event', $output ); // [BB]
		$this->assertStringNotContainsString( 'No Event', $output ); // [BB]
	}
}
