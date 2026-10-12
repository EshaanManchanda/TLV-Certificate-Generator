<?php
/**
 * Personal data export and erasure (7.9.0, includes/Core/privacy.php): the export holds the
 * person's rows and nobody else's; erasing deletes their records and logs, anonymises their
 * certificates and the code still verifies as valid, issued to [erased].
 */

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Services\SerialNumberService;

class PrivacyToolsTest extends WP_UnitTestCase {

	private function seed( string $name, string $email, string $serial ): void {
		global $wpdb;
		$wpdb->insert(
			CustomTables::instance()->get_table( 'students' ),
			array(
				'student_name'     => $name,
				'email'            => $email,
				'school_name'      => 'Privacy School',
				'certificate_type' => 'PrivacyType',
				'issue_date'       => '2026-01-15',
				'serial_number'    => $serial,
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'certificate_generator',
			array(
				'student_name'     => $name,
				'email'            => $email,
				'certificate_data' => wp_json_encode( array( 'name' => $name ) ),
				'pdf_path'         => '',
				'serial_number'    => $serial,
				'certificate_type' => 'PrivacyType',
				'issued_at'        => '2026-01-15 00:00:00',
			)
		);
		$wpdb->insert(
			$wpdb->prefix . 'cert_email_logs',
			array(
				'recipient_email' => $email,
				'recipient_name'  => $name,
				'email_subject'   => 'Your certificate',
				'status'          => 'sent',
			)
		);
		certificate_generator_record_event( $serial, 'issued', array( 'name' => $name ) );
	}

	public function test_registered_with_wordpress(): void {
		$this->assertArrayHasKey( 'certificate-generator', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'certificate-generator', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );
	}

	public function test_export_holds_only_that_persons_rows(): void {
		$this->seed( 'Export Person', 'export@privacy.test', 'PRIV-EXP-1' );
		$this->seed( 'Other Person', 'other@privacy.test', 'PRIV-OTH-1' );

		$out  = certificate_generator_privacy_export( 'Export@Privacy.test', 1 );
		$json = wp_json_encode( $out['data'] );
		$this->assertTrue( $out['done'] );
		$this->assertStringContainsString( 'Export Person', $json );
		$this->assertStringContainsString( 'PRIV-EXP-1', $json );
		$this->assertStringContainsString( 'Your certificate', $json );
		$this->assertStringNotContainsString( 'Other Person', $json );
		$groups = array_unique( array_column( $out['data'], 'group_id' ) );
		$this->assertContains( 'certificate-generator-students', $groups );
		$this->assertContains( 'certificate-generator-certificates', $groups );
		$this->assertContains( 'certificate-generator-email_logs', $groups );
	}

	public function test_erase_deletes_records_and_anonymises_certificates(): void {
		global $wpdb;
		$this->seed( 'Erase Person', 'erase@privacy.test', 'PRIV-ERA-1' );
		$this->seed( 'Keep Person', 'keep@privacy.test', 'PRIV-KEEP-1' );

		$res = certificate_generator_privacy_erase( 'erase@privacy.test', 1 );
		$this->assertTrue( $res['items_removed'] );
		$this->assertTrue( $res['items_retained'] );
		$this->assertNotEmpty( $res['messages'] );
		$this->assertTrue( $res['done'] );

		$students = CustomTables::instance()->get_table( 'students' );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM $students WHERE email = 'erase@privacy.test'" ) ); // phpcs:ignore
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cert_email_logs WHERE recipient_email = 'erase@privacy.test'" ) ); // phpcs:ignore
		$this->assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM $students WHERE email = 'keep@privacy.test'" ), 'others untouched' ); // phpcs:ignore

		$cert = $wpdb->get_row( "SELECT student_name, email, certificate_data FROM {$wpdb->prefix}certificate_generator WHERE serial_number = 'PRIV-ERA-1'", ARRAY_A ); // phpcs:ignore
		$this->assertSame( array( 'student_name' => '[erased]', 'email' => '', 'certificate_data' => '' ), $cert );
		foreach ( certificate_generator_get_certificate_events( 'PRIV-ERA-1' ) as $event ) {
			$this->assertNull( $event['meta_json'] );
		}
		$this->assertNotNull( certificate_generator_get_certificate_events( 'PRIV-KEEP-1' )[0]['meta_json'] );

		$verify = ( new SerialNumberService() )->verify( 'PRIV-ERA-1', false );
		$this->assertTrue( $verify['valid'] ?? false, 'still verifies' );
		$this->assertStringNotContainsString( 'Erase', wp_json_encode( $verify ) );

		$this->assertSame( array(), certificate_generator_privacy_export( 'erase@privacy.test', 1 )['data'], 'nothing left to export' );
	}
}
