<?php
/**
 * CRUD + bulk-action + filter coverage for src/Admin/Pages/SchoolsPage.php.
 * See StudentsCrudTest.php for the [WB]/[BB] tagging convention.
 */
class SchoolsCrudTest extends WP_UnitTestCase {

	private string $table;
	private string $events_table;

	public function set_up(): void {
		parent::set_up();
		$this->table        = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'schools' );
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
	public function test_create_school_success(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_school_nonce' => wp_create_nonce( 'cg_save_school' ),
			'school_name'     => 'Riverside High',
			'city'            => 'Springfield',
			'status'          => 'active',
		);

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'School added.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE school_name = %s", 'Riverside High' ), ARRAY_A );
		$this->assertNotNull( $row ); // [WB]
		$this->assertSame( 'Springfield', $row['city'] );
	}

	/** [BB] missing required school_name fails validation; [WB] no row written. */
	public function test_create_school_missing_name_fails(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_school_nonce' => wp_create_nonce( 'cg_save_school' ),
			'school_name'     => '',
		);

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'School name is required.', $output ); // [BB]
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	/** [BB] created school appears in the rendered list. */
	public function test_school_appears_in_list_output(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'school_name' => 'Visible School', 'created_at' => current_time( 'mysql' ) ) );

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Visible School', $output ); // [BB]
	}

	/** [WB]+[BB] update in place, no duplicate row. */
	public function test_update_school_success(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'school_name' => 'Old Name School', 'created_at' => current_time( 'mysql' ) ) );
		$id = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['id']                = $id;
		$_POST                     = array(
			'cg_school_nonce' => wp_create_nonce( 'cg_save_school' ),
			'school_name'     => 'New Name School',
		);

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'School updated.', $output ); // [BB]
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE id = {$id}" ) ); // [WB]
		$this->assertSame( 'New Name School', $wpdb->get_var( $wpdb->prepare( "SELECT school_name FROM {$this->table} WHERE id = %d", $id ) ) );
	}

	/** [WB] delete removes only the targeted row. */
	public function test_bulk_delete_removes_only_selected_row(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'school_name' => 'Delete School', 'created_at' => current_time( 'mysql' ) ) );
		$to_delete = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'school_name' => 'Keep School', 'created_at' => current_time( 'mysql' ) ) );
		$to_keep = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_school_nonce' => wp_create_nonce( 'cg_delete_school' ),
			'bulk_action'            => 'delete',
			'school_ids'             => array( $to_delete ),
		);

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_list();
		ob_get_clean();

		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_delete ), ARRAY_A ) ); // [WB]
		$this->assertNotNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_keep ), ARRAY_A ) ); // [WB]
	}

	/**
	 * [WB] bulk_edit on Schools deliberately excludes name/email (would be destructive
	 * across distinct schools) — only certificate_type/status/event_id are bulk-editable.
	 */
	public function test_bulk_edit_updates_cert_type_status_and_event_only(): void {
		global $wpdb;
		$event_id = $this->make_event( 'SCH-BULK-01' );

		$wpdb->insert( $this->table, array( 'school_name' => 'School A', 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
		$id1 = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'school_name' => 'School B (untouched)', 'status' => 'active', 'created_at' => current_time( 'mysql' ) ) );
		$id2_untouched = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_school_nonce' => wp_create_nonce( 'cg_delete_school' ),
			'bulk_action'            => 'bulk_edit',
			'school_ids'             => array( $id1 ),
			'bulk_certificate_type'  => 'Excellence',
			'bulk_status'            => 'inactive',
			'bulk_event_id'          => (string) $event_id,
		);

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( '1 school(s) updated via bulk edit.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id1 ), ARRAY_A );
		$this->assertSame( 'Excellence', $row['certificate_type'] ); // [WB]
		$this->assertSame( 'inactive', $row['status'] );
		$this->assertSame( (string) $event_id, (string) $row['event_id'] );
		$this->assertSame( 'School A', $row['school_name'] ); // name untouched by bulk edit

		$untouched = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id2_untouched ), ARRAY_A );
		$this->assertSame( 'active', $untouched['status'] ); // [WB] not selected, unchanged
	}

	/** [BB] event_filter narrows the rendered school list to only the linked event. */
	public function test_event_filter_shows_only_matching_schools(): void {
		global $wpdb;
		$event_a = $this->make_event( 'SCH-FILT-A' );

		$wpdb->insert( $this->table, array( 'school_name' => 'In Event School', 'event_id' => $event_a, 'created_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'school_name' => 'No Event School', 'created_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'event_filter' => (string) $event_a );

		$page = new \CertificateGenerator\Admin\Pages\SchoolsPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'In Event School', $output ); // [BB]
		$this->assertStringNotContainsString( 'No Event School', $output ); // [BB]
	}
}
