<?php
/**
 * Email Logs "Resend" / "Resend all failed" (src/Email/EmailResender.php),
 * and the log CSV export that used to build `LIMIT -1`.
 */

use CertificateGenerator\Email\EmailResender;

class EmailResenderTest extends WP_UnitTestCase {

	private function log_row( int $cert_id, string $email, string $status ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cert_email_logs',
			array(
				'certificate_id'  => $cert_id,
				'recipient_email' => $email,
				'recipient_name'  => 'Test',
				'status'          => $status,
				'error_message'   => $status === 'failed' ? 'SMTP down' : '',
			)
		);
	}

	private function anchor( string $email ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			array( 'student_name' => 'Test', 'certificate_type' => 'Merit', 'email' => $email )
		);
		return (int) $wpdb->insert_id;
	}

	public function set_up(): void {
		parent::set_up();
		certificate_generator_create_email_log_table();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cert_email_logs" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cert_email_queue" );
	}

	public function test_still_failed_skips_later_success(): void {
		$a = $this->anchor( 'a@example.com' );
		$b = $this->anchor( 'b@example.com' );
		$this->log_row( $a, 'a@example.com', 'failed' );
		$this->log_row( $a, 'a@example.com', 'failed' ); // same pair twice → counted once
		$this->log_row( $b, 'b@example.com', 'failed' );
		$this->log_row( $b, 'b@example.com', 'sent' );   // later success → not failed any more
		$this->log_row( 0, 'lms@example.com', 'failed' ); // LMS row: still failed
		$this->log_row( 0, 'done@example.com', 'failed' );
		$this->log_row( $this->anchor( 'done@example.com' ), 'done@example.com', 'sent' ); // resent since

		$failed = EmailResender::still_failed();

		$this->assertEqualsCanonicalizing( array( 'a@example.com', 'lms@example.com' ), array_column( $failed, 'recipient_email' ) );
	}

	public function test_queue_all_failed_queues_once_even_if_clicked_twice(): void {
		$a = $this->anchor( 'a@example.com' );
		$this->log_row( $a, 'a@example.com', 'failed' );

		$this->assertSame( 1, EmailResender::queue_all_failed() );
		EmailResender::queue_all_failed(); // pending row is reused, not duplicated

		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cert_email_queue WHERE status = 'pending'" ) );
	}

	public function test_lms_log_row_is_queued_from_its_student_record(): void {
		global $wpdb;
		$wpdb->insert(
			\CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' ),
			array( 'student_name' => 'Lia', 'email' => 'lms@example.com', 'certificate_type' => 'Course', 'issue_date' => '2026-10-16' )
		);
		$this->log_row( 0, 'lms@example.com', 'failed' );

		$this->assertSame( 1, EmailResender::queue_all_failed() );
		$anchor = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}certificate_generator WHERE email = 'lms@example.com'" );
		$this->assertGreaterThan( 0, $anchor );
		$this->assertSame( (string) $anchor, $wpdb->get_var( "SELECT certificate_id FROM {$wpdb->prefix}cert_email_queue WHERE status = 'pending'" ) );
	}

	public function test_lms_log_row_without_a_certificate_explains(): void {
		$this->log_row( 0, 'gone@example.com', 'failed' );
		global $wpdb;
		$result = EmailResender::resend_log( (int) $wpdb->insert_id );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'No certificate', $result['message'] );
	}

	public function test_export_query_with_unlimited_rows_works(): void {
		$this->log_row( $this->anchor( 'a@example.com' ), 'a@example.com', 'sent' );
		$data = certificate_generator_get_email_logs( array( 'per_page' => -1 ) );
		$this->assertCount( 1, $data['logs'] );
	}
}
