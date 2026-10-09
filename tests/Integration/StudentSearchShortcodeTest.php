<?php
/**
 * Black-box coverage of the [certificate_generator_student_search] shortcode (certificate_generator_student_search_shortcode(),
 * includes/Services/certificate-search.php ~line 3130): calls it exactly as WordPress
 * would when rendering the shortcode on a page, asserting only on the returned HTML —
 * never on internal function calls — since that HTML is the entire observable contract
 * a site visitor interacts with.
 */
class StudentSearchShortcodeTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cg-phpunit-shortcode-test';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-shortcode-test/template.png';

		$im    = imagecreatetruecolor( 800, 600 );
		$white = imagecolorallocate( $im, 255, 255, 255 );
		imagefill( $im, 0, 0, $white );
		imagepng( $im, self::$template_image );
		imagedestroy( $im );
	}

	public static function tear_down_after_class(): void {
		if ( self::$template_image && file_exists( self::$template_image ) ) {
			unlink( self::$template_image );
			@rmdir( dirname( self::$template_image ) );
		}
		parent::tear_down_after_class();
	}

	public function tear_down(): void {
		$_GET = array();
		delete_option( 'certificate_generator_shortcode_text' );
		parent::tear_down();
	}

	// ── Search form (no ?student_email in the URL) ──────────────────────────

	/** [BB] with no query param, the shortcode renders the search form with default copy. */
	public function test_renders_search_form_with_default_text_when_no_email_param(): void {
		unset( $_GET['student_email'] );

		$output = certificate_generator_student_search_shortcode( array() );

		$this->assertStringContainsString( '<form method="get"', $output );
		$this->assertStringContainsString( 'name="student_email"', $output );
		$this->assertStringContainsString( 'Certificate Lookup', $output ); // default title
		$this->assertStringContainsString( 'Enter your email to find your certificates', $output ); // default subtitle
		$this->assertStringContainsString( 'Search Certificates', $output ); // default button text
	}

	/** [BB] a shortcode attribute overrides the hardcoded default text. */
	public function test_shortcode_attribute_overrides_default_title(): void {
		unset( $_GET['student_email'] );

		$output = certificate_generator_student_search_shortcode( array( 'title' => 'My Custom Title' ) );

		$this->assertStringContainsString( 'My Custom Title', $output );
		$this->assertStringNotContainsString( 'Certificate Lookup', $output );
	}

	/**
	 * [BB] the admin-configured default (certificate_generator_shortcode_text option) is used when no
	 * shortcode attribute is given, per certificate_generator_get_shortcode_text()'s precedence order.
	 */
	public function test_admin_configured_text_used_when_no_attribute_given(): void {
		unset( $_GET['student_email'] );
		update_option( 'certificate_generator_shortcode_text', array( 'student_title' => 'Admin Configured Title' ) );

		$output = certificate_generator_student_search_shortcode( array() );

		$this->assertStringContainsString( 'Admin Configured Title', $output );
	}

	/** [BB] an explicit shortcode attribute still wins over the admin-configured default. */
	public function test_shortcode_attribute_wins_over_admin_configured_text(): void {
		unset( $_GET['student_email'] );
		update_option( 'certificate_generator_shortcode_text', array( 'student_title' => 'Admin Configured Title' ) );

		$output = certificate_generator_student_search_shortcode( array( 'title' => 'Attribute Wins' ) );

		$this->assertStringContainsString( 'Attribute Wins', $output );
		$this->assertStringNotContainsString( 'Admin Configured Title', $output );
	}

	// ── Search results: unknown email ───────────────────────────────────────

	/** [BB] searching an email with no matching student shows the "No Certificate Found" screen. */
	public function test_unknown_email_shows_no_certificate_found_screen(): void {
		$_GET['student_email'] = 'nobody-registered@example.com';

		$output = certificate_generator_student_search_shortcode( array() );

		$this->assertStringContainsString( 'No Certificate Found', $output );
		$this->assertStringContainsString( "couldn't find any certificates", $output );
	}

	// ── Search results: known email, published template ────────────────────

	/** [BB] a known email with a published, matching template renders the certificate card + download link. */
	public function test_known_email_with_published_template_shows_certificate_card(): void {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'Shortcode Test Template',
				'certificate_type' => 'ShortcodeFoundType',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$wpdb->insert(
			$tables->get_table( 'students' ),
			array(
				'student_name'     => 'Found Student',
				'email'            => 'found@example.com',
				'school_name'      => 'Found School',
				'certificate_type' => 'ShortcodeFoundType',
				'issue_date'       => current_time( 'Y-m-d' ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		$_GET['student_email'] = 'found@example.com';

		$output = certificate_generator_student_search_shortcode( array() );

		// The card renders sanitize_file_name($student_name)/(school_name) — spaces become
		// hyphens (the value doubles as the download-filename stem), so assert that form.
		$this->assertStringContainsString( 'Found-Student', $output );
		$this->assertStringContainsString( 'Found-School', $output );
		$this->assertStringContainsString( 'ShortcodeFoundType', $output );
		$this->assertStringContainsString( 'Download Certificate', $output );
		$this->assertStringNotContainsString( 'No Certificate Found', $output );
	}

	// ── Search results: known email, only a scheduled (not yet published) template ──

	/**
	 * [BB] a registered student whose certificate_type only has a scheduled/draft
	 * template (nothing published yet) gets the "not ready yet" screen, not a
	 * "No Certificate Found" — the distinction matters: one implies "you're not
	 * registered", the other "you are, just wait."
	 */
	public function test_known_email_with_only_scheduled_template_shows_pending_screen(): void {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'Scheduled Only Template',
				'certificate_type' => 'ShortcodePendingType',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'scheduled',
				'event_date'       => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$wpdb->insert(
			$tables->get_table( 'students' ),
			array(
				'student_name'     => 'Pending Student',
				'email'            => 'pending@example.com',
				'certificate_type' => 'ShortcodePendingType',
				'issue_date'       => current_time( 'Y-m-d' ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		$_GET['student_email'] = 'pending@example.com';

		$output = certificate_generator_student_search_shortcode( array() );

		// esc_html() renders the apostrophe as the &#039; entity.
		$this->assertStringContainsString( 'Your Certificate Isn&#039;t Ready Yet', $output );
		$this->assertStringNotContainsString( 'No Certificate Found', $output );
		$this->assertStringNotContainsString( 'Download Certificate', $output );
	}
}
