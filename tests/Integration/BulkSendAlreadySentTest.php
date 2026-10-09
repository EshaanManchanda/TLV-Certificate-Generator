<?php
/**
 * Regression: Bulk Send decided "already sent" per email address, so a new
 * certificate for someone who had already been emailed was silently skipped
 * (audit bug 9). It is now per address + certificate type.
 */

use CertificateGenerator\Database\CustomTables;

class BulkSendAlreadySentTest extends WP_UnitTestCase {

	private function student( string $name, string $type ): void {
		global $wpdb;
		$wpdb->insert(
			CustomTables::instance()->get_table( 'students' ),
			array(
				'student_name'     => $name,
				'email'            => 'x@example.com',
				'school_name'      => 'DPS',
				'certificate_type' => $type,
				'send_email'       => 1,
			)
		);
	}

	private function sent_log( string $type ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cert_email_logs',
			array( 'certificate_id' => 1, 'recipient_email' => 'x@example.com', 'certificate_type' => $type, 'status' => 'sent' )
		);
	}

	public function set_up(): void {
		parent::set_up();
		certificate_generator_create_email_log_table();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}cert_email_logs" );
		$wpdb->query( 'DELETE FROM ' . CustomTables::instance()->get_table( 'students' ) );
	}

	private function not_sent_types(): array {
		$rows = certificate_generator_get_filtered_recipients( array( 'post_types' => array( 'students' ), 'skip_already_sent' => true ) );
		return array_column( $rows, 'certificate_type' );
	}

	public function test_new_certificate_for_emailed_address_is_still_listed(): void {
		$this->student( 'Asha', 'Merit' );
		$this->sent_log( 'Merit' );
		$this->student( 'Asha', 'Participation' ); // imported after the first email

		$this->assertSame( array( 'Participation' ), $this->not_sent_types() );
	}

	public function test_untyped_legacy_log_still_counts_as_sent_for_the_address(): void {
		$this->student( 'Asha', 'Merit' );
		$this->student( 'Asha', 'Participation' );
		$this->sent_log( '' ); // pre-upgrade log row without a type

		$this->assertSame( array(), $this->not_sent_types(), 'Upgrading must not re-send everyone emailed before' );
	}

	public function test_statistics_agree_with_the_list(): void {
		$this->student( 'Asha', 'Merit' );
		$this->sent_log( 'Merit' );
		$this->student( 'Asha', 'Participation' );

		$stats = certificate_generator_get_filter_statistics( array( 'post_types' => array( 'students' ) ) );
		$this->assertSame( 1, (int) $stats['already_sent'] );
	}
}
