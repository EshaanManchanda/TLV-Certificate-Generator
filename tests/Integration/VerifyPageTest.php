<?php
/**
 * The verify page is created, adopted or re-created (7.9.0, includes/Public/verification.php),
 * and QR codes and {verify_link} point at it. Also the setup checklist steps around it.
 */

class VerifyPageTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'certificate_generator_verify_page_id' );
		delete_transient( 'certificate_generator_verify_page_url' );
		// The dev site may already have verify pages; hide them so each test starts from none.
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->posts} SET post_status = 'draft' WHERE post_type = 'page' AND post_content LIKE '%[certificate_generator_verify_certificate%'" ); // phpcs:ignore
		clean_post_cache( 0 );
		wp_cache_flush();
	}

	public function test_creates_the_page_when_missing(): void {
		$id = certificate_generator_ensure_verify_page();
		$this->assertGreaterThan( 0, $id );
		$this->assertTrue( certificate_generator_is_verify_page( $id ) );
		$this->assertSame( $id, (int) get_option( 'certificate_generator_verify_page_id' ) );
		$this->assertSame( $id, certificate_generator_ensure_verify_page(), 'second run keeps the same page' );
	}

	public function test_adopts_an_existing_shortcode_page(): void {
		$existing = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => 'Check here: [certificate_generator_verify_certificate title="Check"]',
			)
		);
		$this->assertSame( $existing, certificate_generator_ensure_verify_page() );
	}

	public function test_recreates_a_trashed_page(): void {
		$first = certificate_generator_ensure_verify_page();
		wp_trash_post( $first );
		$second = certificate_generator_ensure_verify_page();
		$this->assertNotSame( $first, $second );
		$this->assertTrue( certificate_generator_is_verify_page( $second ) );
	}

	public function test_qr_and_email_links_use_the_page(): void {
		$url = get_permalink( certificate_generator_ensure_verify_page() );
		$this->assertSame( $url, certificate_generator_verify_page_url() );

		$qr = CertificateGenerator_QR_Code_Generator::get_instance()->generate_qr_data( array(), 'NO-SUCH-SERIAL' );
		$this->assertStringStartsWith( $url, $qr );
	}

	public function test_checklist_has_verify_steps(): void {
		$steps = array_column( certificate_generator_setup_steps( 1, 1, true ), 'done', 'label' );
		$this->assertFalse( $steps['Publish the certificate verification page'] );
		certificate_generator_ensure_verify_page();
		$steps = array_column( certificate_generator_setup_steps( 1, 1, true ), 'done', 'label' );
		$this->assertTrue( $steps['Publish the certificate verification page'] );
		$this->assertArrayHasKey( 'Verify a certificate: scan its QR code or enter its code on the verification page', $steps );
	}
}
