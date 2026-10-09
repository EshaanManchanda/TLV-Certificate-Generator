<?php
/**
 * The list-page email badge read a `created_at` column that cert_email_logs
 * doesn't have (it's `sent_at`), so the query failed and every row showed
 * "not sent" no matter what the log said.
 */
class EmailStatusBadgeTest extends WP_UnitTestCase {

	public function test_logged_send_shows_as_sent(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cert_email_logs',
			array(
				'recipient_email'  => 'badge-sent@example.com',
				'recipient_name'   => 'Badge Sent',
				'certificate_id'   => 1,
				'post_type'        => 'students',
				'certificate_type' => 'Participation',
				'email_subject'    => 'Your certificate',
				'email_body'       => 'Body',
				'status'           => 'sent',
			)
		);

		$statuses = \CertificateGenerator\Services\EmailStatusService::getBadgeStatuses( array( 'badge-sent@example.com' ) );

		$this->assertSame( \CertificateGenerator\Services\EmailStatusService::STATUS_SENT, $statuses['badge-sent@example.com']['status'] );
	}
}
