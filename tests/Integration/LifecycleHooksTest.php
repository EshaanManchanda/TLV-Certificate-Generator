<?php
/**
 * 7.9.3: lifecycle actions fire once from their shared function, expiry is stored, the
 * queue kick is really scheduled, and status changes don't undo a revoke.
 */
class LifecycleHooksTest extends WP_UnitTestCase {

	/** @var array<string,array> */
	private array $fired = array();

	private function listen( string $hook, int $args = 1 ): void {
		add_action(
			$hook,
			function ( ...$a ) use ( $hook ) {
				$this->fired[ $hook ][] = $a;
			},
			10,
			$args
		);
	}

	public function test_template_expiry_helper(): void {
		$this->assertNull( certificate_generator_template_expires_at( array() ) );
		$this->assertNull( certificate_generator_template_expires_at( array( 'expiration_period_unit' => array( 'never' ), 'expiration_period_value' => array( '3' ) ) ) );
		$at = certificate_generator_template_expires_at( array( 'expiration_period_unit' => array( 'days' ), 'expiration_period_value' => array( '10' ) ) );
		$this->assertEqualsWithDelta( current_time( 'timestamp' ) + 10 * DAY_IN_SECONDS, strtotime( $at ), 5 ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
	}

	public function test_issued_fires_once_and_stores_expiry_in_both_tables(): void {
		global $wpdb;
		$this->listen( 'certificate_generator_certificate_issued', 4 );
		$data = array( 'student_name' => 'Hook Student', 'email' => 'hook@example.com', 'certificate_type' => 'Hooks', 'expires_at' => '2030-01-02 03:04:05' );

		certificate_generator_insert_certificate_record( $data, 'HOOK-001' );
		certificate_generator_insert_certificate_record( $data, 'HOOK-001' ); // same serial again: no new record, no event

		$this->assertCount( 1, $this->fired['certificate_generator_certificate_issued'] );
		$this->assertSame( 'HOOK-001', $this->fired['certificate_generator_certificate_issued'][0][1] );
		$this->assertGreaterThan( 0, $this->fired['certificate_generator_certificate_issued'][0][0] );
		$this->assertSame( '2030-01-02 03:04:05', $wpdb->get_var( "SELECT expires_at FROM {$wpdb->prefix}certificate_generator WHERE serial_number = 'HOOK-001'" ) );
		$this->assertSame( '2030-01-02 03:04:05', $wpdb->get_var( "SELECT expires_at FROM {$wpdb->prefix}cg_certificates WHERE serial_number = 'HOOK-001'" ) );
	}

	public function test_expired_fires_once_per_certificate(): void {
		global $wpdb;
		$this->listen( 'certificate_generator_certificate_expired', 2 );
		$table = $wpdb->prefix . 'certificate_generator';
		$past  = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$wpdb->insert( $table, array( 'student_name' => 'Old', 'certificate_data' => '{}', 'serial_number' => 'EXP-001', 'expires_at' => $past ) );
		$wpdb->insert( $table, array( 'student_name' => 'Revoked', 'certificate_data' => '{}', 'serial_number' => 'EXP-002', 'expires_at' => $past, 'revoked_at' => $past ) );
		delete_option( 'certificate_generator_expired_checked_at' );

		CertificateGenerator_Cron_Jobs::announce_expired( $table );
		CertificateGenerator_Cron_Jobs::announce_expired( $table );

		$this->assertSame( array( 'EXP-001' ), array_column( $this->fired['certificate_generator_certificate_expired'], 0 ) );
	}

	public function test_recipient_saved_fires_with_is_new(): void {
		$this->listen( 'certificate_generator_recipient_saved', 3 );
		$id = certificate_generator_save_recipient( 'students', array( 'student_name' => 'Saved Student', 'email' => 'saved@example.com' ) );
		$this->assertIsInt( $id );
		$this->assertSame( $id, certificate_generator_save_recipient( 'students', array( 'student_name' => 'Renamed' ), $id ) );

		$this->assertSame( array( array( 'students', $id, true ), array( 'students', $id, false ) ), $this->fired['certificate_generator_recipient_saved'] );
		$this->assertWPError( certificate_generator_save_recipient( 'nope', array() ) );
	}

	public function test_external_id_and_jobs_table(): void {
		global $wpdb;
		$id = certificate_generator_save_recipient( 'students', array( 'student_name' => 'Ext', 'email' => 'ext@example.com', 'external_id' => 'LMS-42' ) );
		$this->assertSame( 'LMS-42', $wpdb->get_var( $wpdb->prepare( "SELECT external_id FROM {$wpdb->prefix}cg_students WHERE id = %d", $id ) ) );
		$this->assertTrue( \CertificateGenerator\Database\CustomTables::instance()->table_exists( 'jobs' ) );
		$this->assertContains( 'external_id', certificate_generator_import_entities()['students']['optional'] );
	}

	public function test_verified_fires_for_visitors_only(): void {
		global $wpdb;
		$this->listen( 'certificate_generator_certificate_verified', 2 );
		$wpdb->insert( $wpdb->prefix . 'certificate_generator', array( 'student_name' => 'Verified', 'certificate_data' => '{}', 'serial_number' => 'VER-001' ) );
		$service = new \CertificateGenerator\Services\SerialNumberService();
		wp_set_current_user( 0 );
		$service->verify( 'VER-001' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$service->verify( 'VER-001' );
		$this->assertSame( array( array( 'VER-001', 'serial' ) ), $this->fired['certificate_generator_certificate_verified'] );
	}

	public function test_email_logged_fires_for_sent_and_failed(): void {
		$this->listen( 'certificate_generator_email_logged' );
		certificate_generator_log_email( 1, 'a@example.com', 'A', 'T', 'S', true );
		certificate_generator_log_email( 1, 'b@example.com', 'B', 'T', 'S', false, 'boom' );
		$this->assertSame( array( 'sent', 'failed' ), array_map( static fn( $a ) => $a[0]['status'], $this->fired['certificate_generator_email_logged'] ) );
	}

	public function test_kick_is_scheduled_next_to_the_recurring_event(): void {
		wp_schedule_event( time(), 'certificate_generator_5min', 'certificate_generator_process_email_queue' );
		certificate_generator_kick_email_queue();
		$this->assertNotFalse( wp_next_scheduled( 'certificate_generator_process_email_queue', array( 'kick' ) ) );
	}

	public function test_email_sent_does_not_unrevoke(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cg_certificates';
		foreach ( array( 'generated', 'revoked' ) as $status ) {
			$wpdb->insert( $table, array( 'recipient_name' => 'X', 'recipient_email' => "$status@example.com", 'certificate_type' => 'Mark', 'status' => $status ) );
			certificate_generator_mark_certificate_email_sent( array( 'email' => "$status@example.com", 'certificate_type' => 'Mark' ) );
		}
		$this->assertSame( 'sent', $wpdb->get_var( "SELECT status FROM $table WHERE recipient_email = 'generated@example.com'" ) );
		$this->assertSame( 'revoked', $wpdb->get_var( "SELECT status FROM $table WHERE recipient_email = 'revoked@example.com'" ) );
	}

	public function test_rate_limiter_counts_in_site_time(): void {
		update_option( 'timezone_string', 'America/New_York' ); // behind UTC: a UTC cutoff would count nothing
		delete_transient( 'certificate_generator_sent_count_3600' );
		certificate_generator_log_email( 1, 'tz@example.com', 'T', 'T', 'S', true );
		$this->assertSame( 1, certificate_generator_get_sent_count( 3600 ) );
	}
}
