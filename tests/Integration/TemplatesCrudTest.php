<?php
/**
 * CRUD + bulk-action + filter coverage for src/Admin/Pages/TemplatesPage.php.
 * See StudentsCrudTest.php for the [WB]/[BB] tagging convention.
 *
 * Note: the "Duplicate" row action runs on admin_init via wp_safe_redirect()+exit()
 * (TemplatesPage::register()), which is not safe to invoke directly in-process —
 * covered here only as a black-box check that the link is rendered; the actual
 * redirect/copy behavior needs a browser/HTTP-level (E2E) check.
 */
class TemplatesCrudTest extends WP_UnitTestCase {

	private string $table;
	private string $events_table;

	public function set_up(): void {
		parent::set_up();
		$this->table        = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
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
	public function test_create_template_success(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_template_nonce' => wp_create_nonce( 'cg_save_template' ),
			'template_name'     => 'Merit Certificate',
			'certificate_type'  => 'Merit',
			'status'            => 'draft',
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Template added.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE template_name = %s", 'Merit Certificate' ), ARRAY_A );
		$this->assertNotNull( $row ); // [WB]
		$this->assertSame( 'Merit', $row['certificate_type'] );
		$this->assertSame( 'draft', $row['status'] );
	}

	/** [BB] missing required name/type fails validation; [WB] no row written. */
	public function test_create_template_missing_fields_fails(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_template_nonce' => wp_create_nonce( 'cg_save_template' ),
			'template_name'     => '',
			'certificate_type'  => '',
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Template name is required.', $output ); // [BB]
		$this->assertStringContainsString( 'Certificate type is required.', $output ); // [BB]
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	/** [BB] status=scheduled without an event_date is rejected (business rule specific to Templates). */
	public function test_scheduled_status_without_event_date_fails(): void {
		global $wpdb;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_template_nonce' => wp_create_nonce( 'cg_save_template' ),
			'template_name'     => 'Scheduled No Date',
			'certificate_type'  => 'Merit',
			'status'            => 'scheduled',
			'event_date'        => '',
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'An Event Date is required when status is set to Scheduled.', $output ); // [BB]
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" ) ); // [WB]
	}

	/** [BB] created template appears in the rendered list, with its Duplicate action link. */
	public function test_template_appears_in_list_output_with_duplicate_link(): void {
		global $wpdb;
		$wpdb->insert(
			$this->table,
			array(
				'template_name'    => 'Visible Template',
				'certificate_type' => 'Merit',
				'status'           => 'draft',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Visible Template', $output ); // [BB]
		$this->assertStringContainsString( 'Duplicate', $output ); // [BB]
	}

	/** [WB]+[BB] update in place, no duplicate row. */
	public function test_update_template_success(): void {
		global $wpdb;
		$wpdb->insert(
			$this->table,
			array(
				'template_name'    => 'Old Template',
				'certificate_type' => 'Merit',
				'status'           => 'draft',
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_GET['id']                = $id;
		$_POST                     = array(
			'cg_template_nonce' => wp_create_nonce( 'cg_save_template' ),
			'template_name'     => 'New Template',
			'certificate_type'  => 'Merit',
			'status'            => 'published',
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_edit();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Template updated.', $output ); // [BB]
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE id = {$id}" ) ); // [WB]
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		$this->assertSame( 'New Template', $row['template_name'] );
		$this->assertSame( 'published', $row['status'] );
	}

	/** [WB] delete removes only the targeted row. */
	public function test_bulk_delete_removes_only_selected_row(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'template_name' => 'Delete Tpl', 'certificate_type' => 'X', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$to_delete = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'template_name' => 'Keep Tpl', 'certificate_type' => 'X', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$to_keep = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_template_nonce' => wp_create_nonce( 'cg_delete_template' ),
			'bulk_action'              => 'delete',
			'template_ids'             => array( $to_delete ),
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_list();
		ob_get_clean();

		$this->assertNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_delete ), ARRAY_A ) ); // [WB]
		$this->assertNotNull( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $to_keep ), ARRAY_A ) ); // [WB]
	}

	/** [WB] bulk_edit updates cert_type/status/event_id only (not template_name/template_url). */
	public function test_bulk_edit_updates_cert_type_status_and_event_only(): void {
		global $wpdb;
		$event_id = $this->make_event( 'TPL-BULK-01' );

		$wpdb->insert( $this->table, array( 'template_name' => 'Tpl A', 'certificate_type' => 'Old', 'status' => 'draft', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$id1 = (int) $wpdb->insert_id;
		$wpdb->insert( $this->table, array( 'template_name' => 'Tpl B (untouched)', 'certificate_type' => 'Old', 'status' => 'draft', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$id2_untouched = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_template_nonce' => wp_create_nonce( 'cg_delete_template' ),
			'bulk_action'              => 'bulk_edit',
			'template_ids'             => array( $id1 ),
			'bulk_certificate_type'    => 'New',
			'bulk_status'              => 'published',
			'bulk_event_id'            => (string) $event_id,
		);

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( '1 template(s) updated via bulk edit.', $output ); // [BB]

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id1 ), ARRAY_A );
		$this->assertSame( 'New', $row['certificate_type'] ); // [WB]
		$this->assertSame( 'published', $row['status'] );
		$this->assertSame( (string) $event_id, (string) $row['event_id'] );
		$this->assertSame( 'Tpl A', $row['template_name'] ); // name untouched by bulk edit

		$untouched = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id2_untouched ), ARRAY_A );
		$this->assertSame( 'draft', $untouched['status'] ); // [WB] not selected, unchanged
	}

	/** [BB] event_filter narrows the rendered template list to only the linked event. */
	public function test_event_filter_shows_only_matching_templates(): void {
		global $wpdb;
		$event_a = $this->make_event( 'TPL-FILT-A' );

		$wpdb->insert( $this->table, array( 'template_name' => 'In Event Tpl', 'certificate_type' => 'X', 'event_id' => $event_a, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'template_name' => 'No Event Tpl', 'certificate_type' => 'X', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'event_filter' => (string) $event_a );

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'In Event Tpl', $output ); // [BB]
		$this->assertStringNotContainsString( 'No Event Tpl', $output ); // [BB]
	}

	/** [BB] status_filter still narrows results as before (no regression from the event filter addition). */
	public function test_status_filter_still_narrows_results(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'template_name' => 'Published Tpl', 'certificate_type' => 'X', 'status' => 'published', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$wpdb->insert( $this->table, array( 'template_name' => 'Draft Tpl', 'certificate_type' => 'X', 'status' => 'draft', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );

		$_GET = array( 'status_filter' => 'published' );

		$page = new \CertificateGenerator\Admin\Pages\TemplatesPage();
		ob_start();
		$page->render_list();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Published Tpl', $output ); // [BB]
		$this->assertStringNotContainsString( 'Draft Tpl', $output ); // [BB]
	}

	/** [WB] duplicate_to_date copies templates to the new event date, swapping the Y-m in the name. */
	public function test_duplicate_to_new_event_date(): void {
		global $wpdb;
		$wpdb->insert( $this->table, array( 'template_name' => 'Participation 2026-06', 'certificate_type' => 'Participation', 'event_date' => '2026-06-08', 'status' => 'published', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
		$src = (int) $wpdb->insert_id;

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'cg_delete_template_nonce' => wp_create_nonce( 'cg_delete_template' ),
			'bulk_action'              => 'duplicate_to_date',
			'template_ids'             => array( $src ),
			'bulk_new_event_date'      => '2026-12-15',
			'bulk_new_status'          => 'scheduled',
		);

		ob_start();
		( new \CertificateGenerator\Admin\Pages\TemplatesPage() )->render_list();
		ob_get_clean();

		$copy = $wpdb->get_row( "SELECT * FROM {$this->table} WHERE event_date = '2026-12-15'", ARRAY_A );
		$this->assertNotNull( $copy ); // [WB]
		$this->assertSame( 'Participation 2026-12', $copy['template_name'] );
		$this->assertSame( 'scheduled', $copy['status'] );
		$this->assertSame( 'published', $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$this->table} WHERE id = %d", $src ) ) ); // original untouched
	}
}
