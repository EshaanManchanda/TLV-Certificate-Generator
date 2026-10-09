<?php
/**
 * CRUD + bulk-action + filter coverage for src/Admin/Pages/StudentsPage.php.
 *
 * Each test is tagged in its docblock:
 *   [WB] white-box  — asserts on internal state (DB rows) the caller shouldn't need to know about.
 *   [BB] black-box  — asserts only on observable output (rendered HTML / return values), as a user would see it.
 * Most tests carry both an [WB] and [BB] assertion since the page mixes DB writes with inline HTML output.
 */
class StudentsCrudTest extends WP_UnitTestCase {

	private string $table;
	private string $events_table;

	public function set_up(): void {
		parent::set_up();
		$this->table        = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
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

	// ── CREATE ───────────────────────────────────────────────────────────────

	/** [WB] a valid POST inserts a row with the correct column values. [BB] renders a success notice. */
	public function test_create_student_success(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_student_nonce' => wp_create_nonce( 'cg_save_student' ),
			'student_name'     => 'Alice Example',
			'email'            => 'alice@example.com',
			'school_name'      => 'Riverside High',
			'certificate_type' => 'Merit',
			'status'           => 'active',
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Student added.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE email = %s", 'alice@example.com' ), ARRAY_A );
		$this->assertNotNull( $row ); // [WB]
		$this->assertSame( 'Alice Example', $row['student_name'] );
		$this->assertSame( 'Riverside High', $row['school_name'] );
		$this->assertSame( 'active', $row['status'] );
	}

	/** [BB] missing required fields renders an error notice and [WB] writes no row. */
	public function test_create_student_missing_required_fields_fails(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_student_nonce' => wp_create_nonce( 'cg_save_student' ),
			'student_name'     => '',
			'email'            => '',
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Student name is required.', $output ); // [BB]
		$this->assertStringContainsString( 'Email is required.', $output ); // [BB]

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
		$this->assertSame( 0, $count ); // [WB]
	}

	/** [BB] a bad nonce is rejected with a security-check error, [WB] no row written. */
	public function test_create_student_bad_nonce_rejected(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_student_nonce' => 'not-a-real-nonce',
			'student_name'     => 'Bad Nonce',
			'email'            => 'badnonce@example.com',
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Security check failed', $output ); // [BB]
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	// ── READ / LIST ──────────────────────────────────────────────────────────

	/** [BB] a created student's name appears as a link in the rendered list. */
	public function test_created_student_appears_in_list_output(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'student_name' => 'Bob Visible', 'email' => 'bob@example.com', 'created_at' => current_time( 'mysql' ) ) );

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Bob Visible', $output ); // [BB]
		$this->assertStringContainsString( 'bob@example.com', $output ); // [BB]
	}

	// ── UPDATE ───────────────────────────────────────────────────────────────

	/** [WB] editing an existing row updates its columns in place (no duplicate row). [BB] shows "updated" notice. */
	public function test_update_student_success(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'student_name' => 'Carl Old', 'email' => 'carl@example.com', 'certificate_type' => 'Old', 'created_at' => current_time( 'mysql' ) ) );
		$id = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['id']                = $id;
		$_POST                     = array(
			'cg_student_nonce' => wp_create_nonce( 'cg_save_student' ),
			'student_name'     => 'Carl New',
			'email'            => 'carl@example.com',
			'certificate_type' => 'New',
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Student updated.', $output ); // [BB]

		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE email = 'carl@example.com'" ) ); // [WB] no duplicate
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		$this->assertSame( 'Carl New', $row['student_name'] ); // [WB]
		$this->assertSame( 'New', $row['certificate_type'] );
	}

	// ── DELETE ───────────────────────────────────────────────────────────────

	/** [WB] bulk "delete" action removes exactly the selected rows and leaves others intact. */
	public function test_bulk_delete_removes_only_selected_rows(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'student_name' => 'Delete Me', 'email' => 'del@example.com', 'created_at' => current_time( 'mysql' ) ) );
		$to_delete = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'student_name' => 'Keep Me', 'email' => 'keep@example.com', 'created_at' => current_time( 'mysql' ) ) );
		$to_keep = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
			'bulk_action'          => 'delete',
			'student_ids'          => array( $to_delete ),
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_list();
		ob_get_clean();

		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_delete ), ARRAY_A ) ); // [WB]
		$this->assertNotNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_keep ), ARRAY_A ) ); // [WB]
	}

	// ── BULK EDIT (incl. event_id) ───────────────────────────────────────────

	/** [WB] bulk_edit updates only the fields supplied, including the new event_id field, across all selected rows. */
	public function test_bulk_edit_updates_selected_rows_including_event(): void {
		global $wpdb;
		$event_id = $this->make_event( 'BULK-01' );

		$wpdb->insert( $this->table, array( 'student_name' => 'S1', 'email' => 's1@example.com', 'school_name' => 'Old School', 'created_at' => current_time( 'mysql' ) ) );
		$id1 = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'student_name' => 'S2', 'email' => 's2@example.com', 'school_name' => 'Old School', 'created_at' => current_time( 'mysql' ) ) );
		$id2 = (int) $wpdb->insert_id;
		// Not selected — must be untouched.
		$wpdb->insert( $this->table, array( 'student_name' => 'S3', 'email' => 's3@example.com', 'school_name' => 'Old School', 'created_at' => current_time( 'mysql' ) ) );
		$id3 = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
			'bulk_action'          => 'bulk_edit',
			'student_ids'          => array( $id1, $id2 ),
			'bulk_school_name'     => 'New School',
			'bulk_event_id'        => (string) $event_id,
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( '2 student(s) updated via bulk edit.', $output ); // [BB]

		foreach ( array( $id1, $id2 ) as $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
			$this->assertSame( 'New School', $row['school_name'] ); // [WB]
			$this->assertSame( (string) $event_id, (string) $row['event_id'] );
		}

		$untouched = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id3 ), ARRAY_A );
		$this->assertSame( 'Old School', $untouched['school_name'] ); // [WB] not selected, must be unchanged
		$this->assertNull( $untouched['event_id'] );
	}

	/** [WB] "empty_all" truncates every row in the table. */
	public function test_empty_all_removes_every_row(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'student_name' => 'X', 'email' => 'x@example.com', 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'student_name' => 'Y', 'email' => 'y@example.com', 'created_at' => current_time( 'mysql' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
			'bulk_action'          => 'empty_all',
			'empty_confirm'        => 'DELETE',
		);

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_list();
		ob_get_clean();

		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	// ── FILTERS (incl. event filter) ─────────────────────────────────────────

	/** [BB] filtering by event only shows students linked to that event; others are excluded from the rendered list. */
	public function test_event_filter_shows_only_matching_students(): void {
		global $wpdb;
		$event_a = $this->make_event( 'FILT-A' );
		$event_b = $this->make_event( 'FILT-B' );

		$wpdb->insert( $this->table, array( 'student_name' => 'In Event A', 'email' => 'ina@example.com', 'event_id' => $event_a, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'student_name' => 'In Event B', 'email' => 'inb@example.com', 'event_id' => $event_b, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'student_name' => 'No Event', 'email' => 'none@example.com', 'created_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'event_filter' => (string) $event_a );

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'In Event A', $output ); // [BB]
		$this->assertStringNotContainsString( 'In Event B', $output ); // [BB]
		$this->assertStringNotContainsString( 'No Event', $output ); // [BB]
	}

	/** [BB] the school_filter dropdown still narrows results as before (no regression from the event filter addition). */
	public function test_school_filter_still_narrows_results(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'student_name' => 'From Riverside', 'email' => 'riverside@example.com', 'school_name' => 'Riverside High', 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'student_name' => 'From Lakeside', 'email' => 'lakeside@example.com', 'school_name' => 'Lakeside High', 'created_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'school_filter' => 'Riverside High' );

		$page = new \CertificateGenerator\Admin\Pages\StudentsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'From Riverside', $output ); // [BB]
		$this->assertStringNotContainsString( 'From Lakeside', $output ); // [BB]
	}

	/** [WB] empty_all without the typed DELETE confirmation removes nothing. */
	public function test_empty_all_requires_typed_confirmation(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'student_name' => 'Stay', 'email' => 'stay@example.com', 'created_at' => current_time( 'mysql' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
			'bulk_action'          => 'empty_all',
			'empty_confirm'        => 'yes',
		);

		ob_start();
		( new \CertificateGenerator\Admin\Pages\StudentsPage() )->render_list();
		ob_get_clean();

		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	/** [WB] bulk edit of issue_date also updates year; select_all_matching applies to every filtered row, not just checked ones. */
	public function test_bulk_edit_issue_date_across_all_matching_rows(): void {
		global $wpdb;
		foreach ( array( 'A1', 'A2', 'A3' ) as $n ) {
			$wpdb->insert( $this->table, array( 'student_name' => $n, 'email' => strtolower( $n ) . '@example.com', 'school_name' => 'Match School', 'issue_date' => '2026-06-08', 'created_at' => current_time( 'mysql' ) ) );
		}
		$wpdb->insert( $this->table, array( 'student_name' => 'Other', 'email' => 'other@example.com', 'school_name' => 'Other School', 'issue_date' => '2026-06-08', 'created_at' => current_time( 'mysql' ) ) );
		$other = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET                      = array( 'school_filter' => 'Match School' );
		$_POST                     = array(
			'cg_bulk_action_nonce' => wp_create_nonce( 'cg_bulk_action' ),
			'bulk_action'          => 'bulk_edit',
			'select_all_matching'  => '1',
			'bulk_issue_date'      => '2026-09-30',
		);

		ob_start();
		( new \CertificateGenerator\Admin\Pages\StudentsPage() )->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( '3 student(s) updated via bulk edit.', $output ); // [BB]
		$this->assertSame( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE issue_date = '2026-09-30' AND year = 2026" ) ); // [WB]
		$this->assertSame( '2026-06-08', $wpdb->get_var( $wpdb->prepare( "SELECT issue_date FROM {$this->table} WHERE id = %d", $other ) ) ); // [WB] outside filter
	}

	/** [BB] tpl_match=none lists only students whose type + issue date has no template. */
	public function test_template_match_filter(): void {
		global $wpdb;
		$tpl = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
		$wpdb->insert( $tpl, array( 'template_name' => 'P June', 'certificate_type' => 'Participation', 'event_date' => '2026-06-08', 'status' => 'published', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'student_name' => 'Has Template', 'email' => 'h@example.com', 'certificate_type' => 'Participation', 'issue_date' => '2026-06-08', 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'student_name' => 'Wrong Date', 'email' => 'w@example.com', 'certificate_type' => 'Participation', 'issue_date' => '2026-09-30', 'created_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'tpl_match' => 'none' );
		ob_start();
		( new \CertificateGenerator\Admin\Pages\StudentsPage() )->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Wrong Date', $output ); // [BB]
		$this->assertStringNotContainsString( 'Has Template', $output ); // [BB]
	}
}
