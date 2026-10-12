<?php
/**
 * My Certificates (7.9.0, includes/Public/my-certificates.php): the signed email-link token, the
 * link form that answers the same for known and unknown addresses, its rate limits, the list that
 * shows only the signed-in address's certificates, the show-my-name switch and the student-search
 * setting that hands over to this page.
 */

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Services\SerialNumberService;

class WalletTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;
	private array $mails = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload               = wp_upload_dir();
		$dir                  = $upload['basedir'] . '/cg-phpunit-wallet-test';
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload['baseurl'] . '/cg-phpunit-wallet-test/template.png';
		wp_mkdir_p( $dir );
		$im = imagecreatetruecolor( 800, 600 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagepng( $im, self::$template_image );
		imagedestroy( $im );
	}

	public static function tear_down_after_class(): void {
		@unlink( self::$template_image );
		@rmdir( dirname( self::$template_image ) );
		parent::tear_down_after_class();
	}

	public function set_up(): void {
		parent::set_up();
		$this->mails = array();
		add_filter(
			'pre_wp_mail',
			function ( $r, $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
		// The handler redirects and exits; turn the redirect into an exception carrying the URL.
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new RuntimeException( (string) $location );
			}
		);
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		$_SERVER['REQUEST_URI'] = '/my-certificates/';
	}

	public function tear_down(): void {
		$_GET    = array();
		$_POST   = array();
		$_COOKIE = array();
		delete_option( 'certificate_generator_search_requires_link' );
		parent::tear_down();
	}

	private function add_student( string $name, string $email, string $type ): int {
		global $wpdb;
		$tables = CustomTables::instance();
		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => $type . ' template',
				'certificate_type' => $type,
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
			)
		);
		$wpdb->insert(
			$tables->get_table( 'students' ),
			array(
				'student_name'     => $name,
				'email'            => $email,
				'school_name'      => 'Wallet School',
				'certificate_type' => $type,
				'issue_date'       => current_time( 'Y-m-d' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/** Run the template_redirect handler and return where it redirected to. */
	private function handle(): string {
		try {
			certificate_generator_wallet_handle_request();
		} catch ( RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'The handler did not redirect.' );
	}

	private function request_link( string $email ): string {
		$_POST = array(
			'certificate_generator_wallet_email' => $email,
			'certificate_generator_wallet_nonce' => wp_create_nonce( 'certificate_generator_wallet_link' ),
		);
		return $this->handle();
	}

	public function test_token_round_trip_and_rejections(): void {
		$token = certificate_generator_wallet_token( 'Ana@Example.test' );
		$this->assertSame( 'ana@example.test', certificate_generator_wallet_email_from_token( $token ) );

		$this->assertSame( '', certificate_generator_wallet_email_from_token( certificate_generator_wallet_token( 'ana@example.test', time() - 1 ) ), 'expired' );

		// Same signature, different email.
		$raw   = base64_decode( strtr( $token, '-_', '+/' ) );
		$other = rtrim( strtr( base64_encode( str_replace( 'ana@', 'bob@', $raw ) ), '+/', '-_' ), '=' );
		$this->assertSame( '', certificate_generator_wallet_email_from_token( $other ), 'tampered email' );

		// Later expiry with the old signature.
		$parts    = explode( '|', $raw );
		$parts[1] = (string) ( (int) $parts[1] + 3600 );
		$longer   = rtrim( strtr( base64_encode( implode( '|', $parts ) ), '+/', '-_' ), '=' );
		$this->assertSame( '', certificate_generator_wallet_email_from_token( $longer ), 'tampered expiry' );

		$this->assertSame( '', certificate_generator_wallet_email_from_token( 'not-a-token' ) );
	}

	public function test_known_and_unknown_addresses_get_the_same_answer(): void {
		$this->add_student( 'Known Person', 'known@wallet.test', 'WalletKnown' );

		$known   = $this->request_link( 'known@wallet.test' );
		$unknown = $this->request_link( 'nobody@wallet.test' );

		$this->assertSame( $known, $unknown );
		$this->assertStringContainsString( 'cg_wallet_sent=1', $known );
		$this->assertCount( 1, $this->mails, 'only the known address is emailed' );
		$this->assertSame( 'known@wallet.test', $this->mails[0]['to'] );

		// The emailed link signs in that address.
		preg_match( '/cg_wallet=([A-Za-z0-9_-]+)/', $this->mails[0]['message'], $m );
		$this->assertSame( 'known@wallet.test', certificate_generator_wallet_email_from_token( $m[1] ) );
	}

	public function test_bad_nonce_sends_nothing_but_answers_the_same(): void {
		$this->add_student( 'Known Person', 'nonce@wallet.test', 'WalletNonce' );
		$_POST = array(
			'certificate_generator_wallet_email' => 'nonce@wallet.test',
			'certificate_generator_wallet_nonce' => 'bad',
		);
		$this->assertStringContainsString( 'cg_wallet_sent=1', $this->handle() );
		$this->assertCount( 0, $this->mails );
	}

	public function test_rate_limits(): void {
		$this->add_student( 'Repeat Person', 'repeat@wallet.test', 'WalletRepeat' );

		$this->request_link( 'repeat@wallet.test' );
		$this->request_link( 'repeat@wallet.test' );
		$this->assertCount( 1, $this->mails, 'one link per address every 2 minutes' );

		// Per IP: 5 requests in 10 minutes, whatever the address.
		for ( $i = 0; $i < 5; $i++ ) {
			$this->request_link( "spray$i@wallet.test" );
		}
		$this->add_student( 'Late Person', 'late@wallet.test', 'WalletLate' );
		$this->request_link( 'late@wallet.test' );
		$this->assertCount( 1, $this->mails, 'the sixth request from one IP is not sent' );
	}

	public function test_opening_a_link_sets_the_cookie_and_cleans_the_url(): void {
		$_GET['cg_wallet'] = certificate_generator_wallet_token( 'open@wallet.test' );
		$_SERVER['REQUEST_URI'] = '/my-certificates/?cg_wallet=' . $_GET['cg_wallet'];
		$to = $this->handle();
		$this->assertStringNotContainsString( 'cg_wallet=', $to );
		if ( ! headers_sent() ) { // PHPUnit has usually printed by now, so setcookie() can't run (E2E 20 covers the cookie).
			$this->assertSame( $_GET['cg_wallet'], $_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] ?? '' );
		}

		$_COOKIE = array();
		$_GET    = array( 'cg_wallet' => 'garbage' );
		$_SERVER['REQUEST_URI'] = '/my-certificates/?cg_wallet=garbage';
		$this->assertStringContainsString( 'cg_wallet_expired=1', $this->handle() );
		$this->assertArrayNotHasKey( CERTIFICATE_GENERATOR_WALLET_COOKIE, $_COOKIE );
	}

	public function test_list_shows_only_the_signed_in_address(): void {
		$this->add_student( 'Mine Person', 'mine@wallet.test', 'WalletMineType' );
		$this->add_student( 'Other Person', 'other@wallet.test', 'WalletOtherType' );

		$out = certificate_generator_my_certificates_shortcode();
		$this->assertStringContainsString( 'name="certificate_generator_wallet_email"', $out, 'signed out: the form' );
		$this->assertStringNotContainsString( 'WalletMineType', $out );

		$_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] = certificate_generator_wallet_token( 'mine@wallet.test' );
		$out = certificate_generator_my_certificates_shortcode();
		$this->assertStringContainsString( 'WalletMineType', $out );
		$this->assertStringNotContainsString( 'WalletOtherType', $out );
		$this->assertStringContainsString( 'cg-wallet-show-name', $out );
	}

	public function test_name_switch_hides_the_name_and_checks_ownership(): void {
		global $wpdb;
		$mine  = $this->add_student( 'Hidden Person', 'hide@wallet.test', 'WalletHideType' );
		$other = $this->add_student( 'Someone Else', 'else@wallet.test', 'WalletElseType' );
		$_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] = certificate_generator_wallet_token( 'hide@wallet.test' );
		certificate_generator_my_certificates_shortcode(); // issues the serial
		$_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] = certificate_generator_wallet_token( 'else@wallet.test' );
		certificate_generator_my_certificates_shortcode();

		$students = CustomTables::instance()->get_table( 'students' );
		$serial   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT serial_number FROM $students WHERE id = %d", $mine ) ); // phpcs:ignore
		$theirs   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT serial_number FROM $students WHERE id = %d", $other ) ); // phpcs:ignore
		$this->assertNotSame( '', $serial );

		$this->assertTrue( certificate_generator_wallet_owns_serial( 'hide@wallet.test', $serial ) );
		$this->assertFalse( certificate_generator_wallet_owns_serial( 'hide@wallet.test', $theirs ), "another address's serial" );

		$before = ( new SerialNumberService() )->verify( $serial, false );
		$this->assertTrue( $before['valid'] ?? false );
		$this->assertEmpty( $before['data']['name_hidden'] ?? false );

		certificate_generator_wallet_set_show_name( $serial, false );
		$after = ( new SerialNumberService() )->verify( $serial, false );
		$this->assertTrue( $after['valid'] ?? false, 'still valid' );
		$this->assertTrue( $after['data']['name_hidden'] );
		$this->assertSame( '', $after['data']['student_name'] );
		$this->assertSame( array( 0 ), array_values( certificate_generator_wallet_name_flags( array( $serial ) ) ) );
	}

	public function test_student_search_setting_hands_over_to_the_link_form(): void {
		$this->add_student( 'Search Person', 'search@wallet.test', 'WalletSearchType' );
		$_GET['student_email'] = 'search@wallet.test';

		$this->assertStringContainsString( 'WalletSearchType', certificate_generator_student_search_shortcode( array() ), 'off: the old lookup' );

		update_option( 'certificate_generator_search_requires_link', '1' );
		$out = certificate_generator_student_search_shortcode( array() );
		$this->assertStringContainsString( 'name="certificate_generator_wallet_email"', $out );
		$this->assertStringNotContainsString( 'WalletSearchType', $out );
	}
}
