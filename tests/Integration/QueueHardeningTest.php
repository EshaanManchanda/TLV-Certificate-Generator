<?php
/**
 * Regression test for two email-queue bugs fixed together:
 * (1) certificate_generator_pause_queue() didn't actually stop
 *     certificate_generator_process_queue_batch() from processing.
 * (2) A failed send was re-queued to 'pending' with no backoff, letting it
 *     immediately re-compete with fresh items in the next batch pull.
 * See includes/Services/bulk-email-sender.php and includes/Email/queue.php.
 */
class QueueHardeningTest extends WP_UnitTestCase {

	private function seed_queue_row(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cert_email_queue';

		$wpdb->insert(
			$table,
			array(
				'certificate_id'  => 0,
				'recipient_email' => 'queue-hardening-test@example.test',
				'status'          => 'pending',
				'scheduled_time'  => current_time( 'mysql' ),
				'created_at'      => current_time( 'mysql' ),
				'updated_at'      => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'cert_email_queue', array( 'recipient_email' => 'queue-hardening-test@example.test' ) );
		certificate_generator_resume_queue();
		parent::tear_down();
	}

	public function test_paused_queue_does_not_process_pending_items(): void {
		global $wpdb;
		$id = $this->seed_queue_row();

		certificate_generator_pause_queue();
		$results = certificate_generator_process_queue_batch();

		$this->assertSame( 0, $results['processed'], 'A paused queue must not process any items' );

		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cert_email_queue WHERE id = %d", $id ) );
		$this->assertSame( 'pending', $status, 'Item must remain untouched while paused' );
	}

	public function test_retry_backoff_pushes_scheduled_time_forward(): void {
		global $wpdb;
		$id     = $this->seed_queue_row();
		$before = $wpdb->get_var( $wpdb->prepare( "SELECT scheduled_time FROM {$wpdb->prefix}cert_email_queue WHERE id = %d", $id ) );

		certificate_generator_update_queue_status( $id, 'pending', 'simulated failure', 300 );

		$after = $wpdb->get_var( $wpdb->prepare( "SELECT scheduled_time FROM {$wpdb->prefix}cert_email_queue WHERE id = %d", $id ) );
		$this->assertGreaterThan( strtotime( $before ), strtotime( $after ), 'Retry must push scheduled_time into the future, not leave it immediately eligible' );
	}
}
