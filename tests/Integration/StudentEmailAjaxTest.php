<?php
/**
 * Black-box AJAX coverage for StudentsPage::send_email_ajax() (wp_ajax_cg_student_send_email) —
 * the endpoint both the per-row "Send Email" button and the new bulk "Send Email"
 * action (client-side loop over selected rows) call.
 *
 * Uses WP_Ajax_UnitTestCase so wp_send_json_success/error's wp_die() is caught
 * instead of terminating the test process (see vendor/wp-phpunit/.../testcase-ajax.php).
 * Invokes send_email_ajax() directly rather than via _handleAjax()/do_action('admin_init') —
 * do_action('admin_init') fires every admin_init hook registered by every page in this
 * plugin (and WP core), which is unrelated plumbing this test has no business depending
 * on; direct invocation still exercises the real nonce check, capability check, and
 * wp_send_json_*() response, matching how the rest of this suite tests page methods
 * (see InputSanitizationTest, StudentsCrudTest).
 */
class StudentEmailAjaxTest extends WP_Ajax_UnitTestCase {

	private static string $template_image;
	private static string $template_url;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/cg-phpunit-student-email-test';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-student-email-test/template.png';

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

	private function seed_student_with_template( string $email, string $name, string $cert_type ): int {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();

		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'AJAX Test Template',
				'certificate_type' => $cert_type,
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
				'student_name'     => $name,
				'email'            => $email,
				'certificate_type' => $cert_type,
				'issue_date'       => current_time( 'Y-m-d' ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Sets $_POST and mirrors it into $_REQUEST — check_ajax_referer() reads the nonce
	 * from $_REQUEST, not $_POST, and PHP doesn't resync $_REQUEST after bootstrap when
	 * a test assigns to $_POST directly (that's normally admin-ajax.php's job).
	 */
	private function set_ajax_post( array $post ): void {
		$_POST    = $post;
		$_REQUEST = array_merge( $_GET, $_POST );
	}

	/**
	 * Invokes the AJAX method directly, capturing its echoed JSON the way _handleAjax() would.
	 *
	 * dieHandler() (testcase-ajax.php) closes the buffer we open here into
	 * $this->_last_response itself before throwing — it must not be read via a second
	 * ob_get_clean() call, which would pop an unrelated outer buffer instead.
	 */
	private function invoke_send_email_ajax(): array {
		ob_start();
		try {
			( new \CertificateGenerator\Admin\Pages\StudentsPage() )->send_email_ajax();
			// No wp_die() reached (shouldn't happen for this endpoint, but don't leak the buffer if it does).
			$this->_last_response = ob_get_clean();
		} catch ( WPAjaxDieContinueException $e ) {
			// expected: wp_send_json_success/error() dies after echoing JSON; buffer already closed above.
		}
		$decoded = json_decode( $this->_last_response, true );
		$this->assertIsArray( $decoded, 'AJAX response must be valid JSON: ' . $this->_last_response );
		return $decoded;
	}

	/**
	 * [BB]+[WB] happy path: valid student + matching published template →
	 * wp_mail is actually invoked with the right recipient/subject/attachment,
	 * and the JSON response reports success.
	 */
	public function test_send_email_success_sends_real_email_and_reports_success(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$student_id = $this->seed_student_with_template( 'ajax-success@example.com', 'Ajax Success', 'AjaxCertType' );

		$captured = null;
		add_filter(
			'wp_mail',
			function ( $args ) use ( &$captured ) {
				$captured = $args;
				return $args;
			}
		);
		add_filter( 'pre_wp_mail', '__return_true' ); // don't actually attempt SMTP/mail() in test env

		$this->set_ajax_post( array( 'nonce' => wp_create_nonce( 'cg_student_send_email' ), 'id' => $student_id ) );

		$response = $this->invoke_send_email_ajax();
		$this->assertTrue( $response['success'] ); // [BB]

		$this->assertNotNull( $captured, 'wp_mail must actually be called for a valid student+template' ); // [WB]
		$to = is_array( $captured['to'] ) ? $captured['to'][0] : $captured['to'];
		$this->assertSame( 'ajax-success@example.com', $to ); // [WB] correct recipient
		$this->assertNotEmpty( $captured['subject'] ); // [WB] a real subject line was resolved
	}

	/** [BB] a non-existent student id returns a JSON error, not a fatal or a sent email. */
	public function test_send_email_invalid_student_id_returns_error(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$captured = null;
		add_filter( 'wp_mail', function ( $args ) use ( &$captured ) { $captured = $args; return $args; } );
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->set_ajax_post( array( 'nonce' => wp_create_nonce( 'cg_student_send_email' ), 'id' => 999999 ) );

		$response = $this->invoke_send_email_ajax();
		$this->assertFalse( $response['success'] ); // [BB]
		$this->assertStringContainsString( 'not found', strtolower( $response['data']['message'] ?? '' ) );
		$this->assertNull( $captured, 'no email should be sent for a nonexistent student' ); // [WB]
	}

	/** [BB] a student with no email address is rejected before any send attempt. */
	public function test_send_email_student_without_email_returns_error(): void {
		global $wpdb;
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$wpdb->insert( $table, array( 'student_name' => 'No Email Student', 'email' => '', 'created_at' => current_time( 'mysql' ) ) );
		$student_id = (int) $wpdb->insert_id;

		$captured = null;
		add_filter( 'wp_mail', function ( $args ) use ( &$captured ) { $captured = $args; return $args; } );
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->set_ajax_post( array( 'nonce' => wp_create_nonce( 'cg_student_send_email' ), 'id' => $student_id ) );

		$response = $this->invoke_send_email_ajax();
		$this->assertFalse( $response['success'] ); // [BB]
		$this->assertStringContainsString( 'email', strtolower( $response['data']['message'] ?? '' ) );
		$this->assertNull( $captured ); // [WB]
	}

	/** [WB]+[BB] a non-admin user is refused, regardless of a valid nonce/student. */
	public function test_send_email_requires_manage_options_capability(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$student_id = $this->seed_student_with_template( 'no-perm@example.com', 'No Perm', 'NoPermCertType' );

		$captured = null;
		add_filter( 'wp_mail', function ( $args ) use ( &$captured ) { $captured = $args; return $args; } );
		add_filter( 'pre_wp_mail', '__return_true' );

		$this->set_ajax_post( array( 'nonce' => wp_create_nonce( 'cg_student_send_email' ), 'id' => $student_id ) );

		$response = $this->invoke_send_email_ajax();
		$this->assertFalse( $response['success'] ); // [BB]
		$this->assertStringContainsString( 'permission', strtolower( $response['data']['message'] ?? '' ) );
		$this->assertNull( $captured, 'a subscriber must never trigger an actual send' ); // [WB]
	}

	/** [BB] an invalid nonce is rejected by check_ajax_referer() before any business logic runs. */
	public function test_send_email_bad_nonce_is_rejected(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$student_id = $this->seed_student_with_template( 'bad-nonce@example.com', 'Bad Nonce', 'BadNonceCertType' );

		$this->set_ajax_post( array( 'nonce' => 'totally-invalid-nonce', 'id' => $student_id ) );

		// dieHandler() unconditionally calls ob_get_clean() — an open buffer must exist
		// for it to close, same as invoke_send_email_ajax() sets up for the other tests.
		ob_start();
		$this->expectException( WPAjaxDieStopException::class );
		( new \CertificateGenerator\Admin\Pages\StudentsPage() )->send_email_ajax();
	}
}
