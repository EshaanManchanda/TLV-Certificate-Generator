<?php
/**
 * Audit trail: issue, name correction, revoke, reinstate and public verification are
 * recorded per serial; admins' own checks are not; pruning only drops old "verified" rows.
 */
use CertificateGenerator\Services\SerialNumberService;

class CertificateEventsTest extends WP_UnitTestCase {

	public function test_lifecycle_is_recorded_newest_first(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma' ), 'EVT-001', 'bulk' );
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma' ), 'EVT-001' ); // same name: no event
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharmaa' ), 'EVT-001' );
		certificate_generator_set_certificate_revoked( 'EVT-001', 'typo' );
		certificate_generator_set_certificate_revoked( 'EVT-001', null );

		$events = certificate_generator_get_certificate_events( 'EVT-001' );
		$this->assertSame( array( 'reinstated', 'revoked', 'name_changed', 'issued' ), array_column( $events, 'event_type' ) );
		$this->assertSame( (string) $admin, (string) $events[1]['actor_id'] );
		$this->assertSame( array( 'reason' => 'typo' ), json_decode( $events[1]['meta_json'], true ) );
		$this->assertEquals( array( 'from' => 'Riya Sharma', 'to' => 'Riya Sharmaa' ), json_decode( $events[2]['meta_json'], true ) );
		$this->assertSame( array( 'via' => 'bulk' ), json_decode( $events[3]['meta_json'], true ) );
	}

	public function test_public_verification_is_recorded_but_admin_checks_are_not(): void {
		certificate_generator_insert_certificate_record( array( 'student_name' => 'Riya Sharma' ), 'EVT-002' );
		$service = new SerialNumberService();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$service->verify( 'EVT-002' );
		wp_set_current_user( 0 );
		$service->verify( certificate_generator_verification_code( 'EVT-002' ) );
		$service->verify( 'EVT-002' );

		$verified = array_values( array_filter( certificate_generator_get_certificate_events( 'EVT-002' ), fn( $e ) => 'verified' === $e['event_type'] ) );
		$this->assertCount( 2, $verified );
		$this->assertNull( $verified[0]['actor_id'] );
		$this->assertSame( array( 'serial', 'code' ), array_map( fn( $e ) => json_decode( $e['meta_json'], true )['by'], $verified ) );
	}

	public function test_prune_drops_only_old_verified_events(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cg_certificate_events';
		$old   = wp_date( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS );
		$wpdb->insert( $table, array( 'certificate_serial' => 'EVT-003', 'event_type' => 'verified', 'created_at' => $old ) );
		$wpdb->insert( $table, array( 'certificate_serial' => 'EVT-003', 'event_type' => 'issued', 'created_at' => $old ) );
		certificate_generator_record_event( 'EVT-003', 'verified' );

		$this->assertSame( 1, certificate_generator_prune_certificate_events( 90 ) );
		$this->assertSame( array( 'verified', 'issued' ), array_column( certificate_generator_get_certificate_events( 'EVT-003' ), 'event_type' ) );
	}

	public function test_history_card_lists_events(): void {
		certificate_generator_record_event( 'EVT-004', 'revoked', array( 'reason' => 'duplicate <b>' ) );

		ob_start();
		certificate_generator_render_certificate_history( 'EVT-004' );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'History for EVT-004', $html );
		$this->assertStringContainsString( 'Revoked', $html );
		$this->assertStringContainsString( 'reason: duplicate &lt;b&gt;', $html );
	}
}
