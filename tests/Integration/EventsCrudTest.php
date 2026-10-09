<?php
/**
 * CRUD + filter + linked-record coverage for src/Admin/Pages/EventsPage.php.
 * See StudentsCrudTest.php for the [WB]/[BB] tagging convention.
 */
class EventsCrudTest extends WP_UnitTestCase {

	private string $table;

	public function set_up(): void {
		parent::set_up();
		$this->table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'events' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tear_down(): void {
		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/** [WB]+[BB] valid create writes the row and shows a success notice. */
	public function test_create_event_success(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_event_nonce' => wp_create_nonce( 'cg_save_event' ),
			'event_code'     => 'PO-06-2026',
			'event_name'     => 'Python Olympiad June 2026',
			'status'         => 'open',
		);

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Event added.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE event_code = %s", 'PO-06-2026' ), ARRAY_A );
		$this->assertNotNull( $row ); // [WB]
		$this->assertSame( 'Python Olympiad June 2026', $row['event_name'] );
		$this->assertSame( 'open', $row['status'] );
	}

	/** [BB] missing required code/name fails validation; [WB] no row written. */
	public function test_create_event_missing_fields_fails(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_event_nonce' => wp_create_nonce( 'cg_save_event' ),
			'event_code'     => '',
			'event_name'     => '',
		);

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Event code is required.', $output ); // [BB]
		$this->assertStringContainsString( 'Event name is required.', $output ); // [BB]
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	/** [BB] a duplicate event_code (against a different row) is rejected; [WB] the second row is never inserted. */
	public function test_duplicate_event_code_rejected(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'event_code' => 'DUPE-01', 'event_name' => 'First', 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_event_nonce' => wp_create_nonce( 'cg_save_event' ),
			'event_code'     => 'DUPE-01',
			'event_name'     => 'Second',
			'status'         => 'open',
		);

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'is already used by another event', $output ); // [BB]
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE event_code = 'DUPE-01'" ) ); // [WB]
	}

	/** [BB] created event appears in the rendered list. */
	public function test_event_appears_in_list_output(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'event_code' => 'VIS-01', 'event_name' => 'Visible Event', 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'VIS-01', $output ); // [BB]
		$this->assertStringContainsString( 'Visible Event', $output ); // [BB]
	}

	/** [WB]+[BB] update in place, no duplicate row. */
	public function test_update_event_success(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'event_code' => 'UPD-01', 'event_name' => 'Old Name', 'status' => 'draft', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$id = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['id']                = $id;
		$_POST                     = array(
			'cg_event_nonce' => wp_create_nonce( 'cg_save_event' ),
			'event_code'     => 'UPD-01',
			'event_name'     => 'New Name',
			'status'         => 'closed',
		);

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Event updated.', $output ); // [BB]
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE event_code = 'UPD-01'" ) ); // [WB]
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		$this->assertSame( 'New Name', $row['event_name'] );
		$this->assertSame( 'closed', $row['status'] );
	}

	/** [WB] bulk delete removes only the targeted row. */
	public function test_bulk_delete_removes_only_selected_row(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'event_code' => 'DEL-01', 'event_name' => 'Delete Me', 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$to_delete = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'event_code' => 'KEEP-01', 'event_name' => 'Keep Me', 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$to_keep = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_event_nonce' => wp_create_nonce( 'cg_delete_event' ),
			'event_ids'             => array( $to_delete ),
		);

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_list();
		ob_get_clean();

		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_delete ), ARRAY_A ) ); // [WB]
		$this->assertNotNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_keep ), ARRAY_A ) ); // [WB]
	}

	/** [BB] status_filter narrows the rendered event list. */
	public function test_status_filter_narrows_results(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'event_code' => 'OPEN-01', 'event_name' => 'Open Event', 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'event_code' => 'CLOSED-01', 'event_name' => 'Closed Event', 'status' => 'closed', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'status_filter' => 'open' );

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Open Event', $output ); // [BB]
		$this->assertStringNotContainsString( 'Closed Event', $output ); // [BB]
	}

	/**
	 * [BB] the edit screen's "Linked Records" panel correctly counts students/teachers/
	 * schools/templates whose event_id points at this event — the whole point of the FK.
	 */
	public function test_linked_records_count_reflects_related_rows(): void {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		$wpdb->insert( $this->table, array( 'event_code' => 'LINK-01', 'event_name' => 'Linked Event', 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$event_id = (int) $wpdb->insert_id;

		$wpdb->insert( $tables->get_table( 'students' ), array( 'student_name' => 'S1', 'email' => 's1@example.com', 'event_id' => $event_id, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $tables->get_table( 'students' ), array( 'student_name' => 'S2', 'email' => 's2@example.com', 'event_id' => $event_id, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $tables->get_table( 'teachers' ), array( 'teacher_name' => 'T1', 'email' => 't1@example.com', 'event_id' => $event_id, 'created_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'id' => $event_id );

		$page = new \CertificateGenerator\Admin\Pages\EventsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		// Linked Records stat tiles render raw ints per entity — confirm counts, not just presence.
		$this->assertMatchesRegularExpression( '/cg-stat__label">Students<\/div><div class="cg-stat__value">2<\/div>/', $output ); // [BB]
		$this->assertMatchesRegularExpression( '/cg-stat__label">Teachers<\/div><div class="cg-stat__value">1<\/div>/', $output ); // [BB]
		$this->assertMatchesRegularExpression( '/cg-stat__label">Schools<\/div><div class="cg-stat__value">0<\/div>/', $output ); // [BB]
	}
}
