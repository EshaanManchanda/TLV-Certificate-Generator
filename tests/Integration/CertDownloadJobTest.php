<?php
/**
 * Chunked admin bulk download (cg_cert_dl_start → cg_cert_dl_step → cg_cert_dl_zip):
 * permissions, nonce, per-user job isolation, locking, resume, retry and partial failure.
 * Handlers are invoked directly (see StudentEmailAjaxTest for why).
 */
class CertDownloadJobTest extends WP_Ajax_UnitTestCase {

	private static string $template_image;
	private static string $template_url;
	private int $admin    = 0;
	private int $renders  = 0;
	private array $ids    = array();

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] . '/cg-phpunit-dljob' );
		self::$template_image = $upload_dir['basedir'] . '/cg-phpunit-dljob/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-dljob/template.png';
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
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$wpdb->insert(
			$tables->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Job Template',
				'certificate_type' => 'PHPUnitJobCert',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$this->ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert(
				$tables->get_table( 'students' ),
				array(
					'student_name'     => "JOB STUDENT $i",
					'email'            => "job$i@dljob.test",
					'school_name'      => 'Job School',
					'certificate_type' => 'PHPUnitJobCert',
					'issue_date'       => current_time( 'Y-m-d' ),
					'serial_number'    => sprintf( 'JOB-%04d', $i ),
				)
			);
			$this->ids[] = (int) $wpdb->insert_id;
		}

		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
		set_transient( 'cg_usage_report_lock', 1, HOUR_IN_SECONDS );
		$this->renders = 0;
		add_action( 'cg_certificate_generated', array( $this, 'count_render' ) );
	}

	public function count_render(): void {
		++$this->renders;
	}

	private function call( string $handler, array $post = array() ): array {
		$_POST = array_merge(
			array(
				'nonce'         => wp_create_nonce( 'cg_cert_dl_job' ),
				'filter_entity' => 'students',
				'filter_email'  => '@dljob.test',
			),
			$post
		);
		$_REQUEST = $_POST;
		ob_start(); // the AJAX die handler reads the response from this buffer
		try {
			$handler();
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}
		$out                  = json_decode( $this->_last_response, true );
		$this->_last_response = '';
		return $out;
	}

	private function run_job(): array {
		$start = $this->call( 'cg_ajax_cert_dl_start' );
		$this->assertTrue( $start['success'] );
		$job = $start['data']['job_id'];
		do {
			$step = $this->call( 'cg_ajax_cert_dl_step', array( 'job_id' => $job ) );
			$this->assertTrue( $step['success'] );
		} while ( empty( $step['data']['complete'] ) );
		return array( $job, $step['data'] );
	}

	private function zip_entries( string $job, int $part ): array {
		$res = $this->call( 'cg_ajax_cert_dl_zip', array( 'job_id' => $job, 'part' => $part ) );
		$this->assertTrue( $res['success'], wp_json_encode( $res ) );
		$this->assertStringNotContainsString( '&amp;', $res['data']['download_url'], 'JS sets this as href: it must not be HTML-escaped' );
		parse_str( (string) wp_parse_url( $res['data']['download_url'], PHP_URL_QUERY ), $q );
		$this->assertSame( 1, wp_verify_nonce( $q['_wpnonce'], 'cg_cert_dl_file_' . $job ) );
		$job_data = get_transient( 'cg_dljob_' . $job );
		$path     = $job_data['zips'][ $part ];
		$this->assertFileExists( $path );
		$this->assertStringContainsString( '/private/', wp_normalize_path( $path ) );
		$zip   = new ZipArchive();
		$zip->open( $path );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = $zip->getNameIndex( $i );
		}
		$zip->close();
		@unlink( $path );
		return $names;
	}

	public function test_full_job_produces_zip_with_every_certificate_and_manifest(): void {
		[ $job, $done ] = $this->run_job();
		$this->assertSame( 5, $done['processed'] );
		$this->assertSame( 5, $done['total'] );
		$this->assertSame( 1, $done['parts'] );
		$this->assertSame( 0, $done['failed'] );

		$names = $this->zip_entries( $job, 0 );
		$this->assertCount( 6, $names );
		$this->assertContains( 'manifest.csv', $names );
	}

	public function test_restarted_job_reuses_rendered_pdfs(): void {
		$this->run_job();
		$this->assertSame( 5, $this->renders );
		$this->run_job();
		$this->assertSame( 5, $this->renders, 'A second job over the same certificates renders nothing' );
	}

	public function test_interrupted_job_resumes_where_it_stopped(): void {
		add_filter( 'cg_cert_dl_step_budget', '__return_zero' ); // one certificate per step
		$start = $this->call( 'cg_ajax_cert_dl_start' );
		$job   = $start['data']['job_id'];
		$first = $this->call( 'cg_ajax_cert_dl_step', array( 'job_id' => $job ) );
		$this->assertSame( 1, $first['data']['processed'] );
		$this->assertFalse( $first['data']['complete'] );

		// The browser goes away; later steps carry on from the stored cursor.
		$steps = 0;
		do {
			$step = $this->call( 'cg_ajax_cert_dl_step', array( 'job_id' => $job ) );
			++$steps;
		} while ( empty( $step['data']['complete'] ) );
		remove_filter( 'cg_cert_dl_step_budget', '__return_zero' );

		$this->assertSame( 4, $steps );
		$this->assertSame( 5, $this->renders, 'No certificate rendered twice' );
	}

	public function test_zip_retry_rerenders_a_missing_pdf(): void {
		[ $job ] = $this->run_job();
		@unlink( cg_certificates_dir() . '/' . cg_certificate_file_stem( 'students_row_' . $this->ids[0] ) . '.pdf' );

		$this->assertCount( 6, $this->zip_entries( $job, 0 ) );
		$this->assertSame( 6, $this->renders );
	}

	public function test_certificate_without_template_is_reported_failed(): void {
		global $wpdb;
		$wpdb->update(
			\CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' ),
			array( 'certificate_type' => 'NoSuchTemplateType' ),
			array( 'id' => $this->ids[0] )
		);
		[ $job, $done ] = $this->run_job();
		$this->assertSame( 1, $done['failed'] );
		$this->assertCount( 5, $this->zip_entries( $job, 0 ), '4 certificates + manifest' );
	}

	public function test_lock_held_by_another_request_returns_busy(): void {
		$start = $this->call( 'cg_ajax_cert_dl_start' );
		$job   = $start['data']['job_id'];
		add_option( 'cg_dljob_lock_' . $job, time(), '', 'no' );
		$step = $this->call( 'cg_ajax_cert_dl_step', array( 'job_id' => $job ) );
		$this->assertTrue( $step['data']['busy'] );
		$this->assertSame( 0, $this->renders );
		delete_option( 'cg_dljob_lock_' . $job );
	}

	public function test_another_admin_cannot_use_the_job(): void {
		$start = $this->call( 'cg_ajax_cert_dl_start' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$step = $this->call( 'cg_ajax_cert_dl_step', array( 'job_id' => $start['data']['job_id'] ) );
		$this->assertFalse( $step['success'] );
		$zip = $this->call( 'cg_ajax_cert_dl_zip', array( 'job_id' => $start['data']['job_id'], 'part' => 0 ) );
		$this->assertFalse( $zip['success'] );
	}

	public function test_non_admin_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$res = $this->call( 'cg_ajax_cert_dl_start' );
		$this->assertFalse( $res['success'] );
	}

	public function test_bad_nonce_is_rejected(): void {
		$this->expectException( WPAjaxDieStopException::class );
		$_POST    = array( 'nonce' => 'nope', 'filter_entity' => 'students' );
		$_REQUEST = $_POST;
		ob_start();
		cg_ajax_cert_dl_start();
	}

	public function test_job_id_cannot_be_a_path(): void {
		$res = $this->call( 'cg_ajax_cert_dl_step', array( 'job_id' => '../../etc/passwd' ) );
		$this->assertFalse( $res['success'] );
	}
}
