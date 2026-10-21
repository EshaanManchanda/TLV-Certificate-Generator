<?php
/**
 * The background job engine (CertificateGenerator\Services\Jobs) and the failures it must
 * survive: batches of 25, failed items retried alone, one worker per job, a worker that dies
 * mid-batch or after rendering but before saving, a lost cron event, re-render on demand and a
 * deleted PDF — never a second certificate, never a new serial.
 */

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Services\Jobs;

class JobsEngineTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;
	private int $renders = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$dir = wp_upload_dir()['basedir'] . '/cg-phpunit-jobsengine';
		wp_mkdir_p( $dir );
		self::$template_image = $dir . '/template.png';
		self::$template_url   = wp_upload_dir()['baseurl'] . '/cg-phpunit-jobsengine/template.png';
		$im = imagecreatetruecolor( 400, 300 );
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
		$wpdb->insert(
			CustomTables::instance()->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Jobs Engine',
				'certificate_type' => 'PHPUnitEngineCert',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$this->renders = 0;
		add_action( 'certificate_generator_certificate_generated', array( $this, 'count_render' ) );
	}

	public function tear_down(): void {
		wp_unschedule_hook( Jobs::CRON_HOOK );
		parent::tear_down();
	}

	public function count_render(): void {
		++$this->renders;
	}

	private function recipient( string $entity, string $name, string $type = 'PHPUnitEngineCert' ): int {
		global $wpdb;
		$name_col = array( 'students' => 'student_name', 'teachers' => 'teacher_name', 'schools' => 'school_name' )[ $entity ];
		$wpdb->insert(
			CustomTables::instance()->get_table( $entity ),
			array(
				$name_col          => $name,
				'email'            => sanitize_title( $name ) . '@engine.test',
				'certificate_type' => $type,
				'issue_date'       => current_time( 'Y-m-d' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function row( string $entity, int $id ): array {
		global $wpdb;
		$table = CustomTables::instance()->get_table( $entity );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
	}

	private function certificate_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cg_certificates WHERE recipient_email LIKE '%@engine.test'" );
	}

	private function job( array $ids, array $params = array() ): int {
		$job = Jobs::create( $ids, 1, 0, '', $params );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		return (int) $job['id'];
	}

	private function run_job( int $id ): void {
		for ( $i = 0; $i < 20 && in_array( Jobs::get( $id )['status'], array( 'queued', 'running' ), true ); $i++ ) {
			Jobs::process( $id );
		}
	}

	public function test_batches_record_failures_and_retry_reissues_nothing(): void {
		$good = array();
		for ( $i = 1; $i <= 26; $i++ ) {
			$good[] = $this->recipient( 'students', "Batch Student $i" );
		}
		$bad = $this->recipient( 'students', 'Batch Orphan', 'NoSuchEngineType' );
		$id  = $this->job( array_merge( $good, array( $bad ) ) );
		$this->assertNotFalse( wp_next_scheduled( Jobs::CRON_HOOK, array( $id ) ) );

		Jobs::process( $id );
		$job = Jobs::get( $id );
		$this->assertSame( 25, (int) $job['done'] + (int) $job['failed'], 'one batch is 25 items' );
		$this->assertSame( 'running', $job['status'] );

		$this->run_job( $id );
		$job = Jobs::summary( Jobs::get( $id ) );
		$this->assertSame( array( 'done', 26, 1 ), array( $job['status'], $job['done'], $job['failed'] ) );
		$this->assertSame( $bad, $job['items'][0]['ref'], 'failed items first' );
		$this->assertStringContainsString( 'No published template', $job['items'][0]['error'] );

		$serials = array_map( fn( $s ) => $this->row( 'students', $s )['serial_number'], $good );
		$count   = $this->certificate_count();
		$this->assertSame( 26, $count );
		$this->assertFalse( Jobs::retry( 999999 ) );

		global $wpdb;
		$wpdb->update( CustomTables::instance()->get_table( 'students' ), array( 'certificate_type' => 'PHPUnitEngineCert' ), array( 'id' => $bad ) );
		$this->renders = 0;
		$this->assertTrue( Jobs::retry( $id ) );
		$this->run_job( $id );
		$this->assertSame( array( 27, 0 ), array( (int) Jobs::get( $id )['done'], (int) Jobs::get( $id )['failed'] ) );
		$this->assertSame( $count + 1, $this->certificate_count(), 'only the retried item was issued' );
		$this->assertSame( 1, $this->renders, 'done items were not rendered again' );
		$this->assertSame( $serials, array_map( fn( $s ) => $this->row( 'students', $s )['serial_number'], $good ) );
	}

	public function test_one_worker_per_job(): void {
		$ids = array();
		for ( $i = 1; $i <= 27; $i++ ) {
			$ids[] = $this->recipient( 'students', "Lock Student $i" );
		}
		$id = $this->job( $ids );

		// A worker holds the lock: another one does nothing.
		add_option( 'certificate_generator_job_lock_' . $id, time() + 600, '', false );
		Jobs::process( $id );
		$this->assertSame( 0, (int) Jobs::get( $id )['done'] );

		// Its worker died long ago: the lock expired and the next worker takes over.
		update_option( 'certificate_generator_job_lock_' . $id, time() - 1, false );
		// A second worker starting while this one runs (e.g. cron + the progress poll) is a no-op.
		$nested = function () use ( $id ) {
			Jobs::process( $id );
		};
		add_action( 'certificate_generator_certificate_generated', $nested );
		Jobs::process( $id );
		remove_action( 'certificate_generator_certificate_generated', $nested );
		$this->assertSame( 25, (int) Jobs::get( $id )['done'], 'exactly one batch advanced' );
		$this->assertFalse( get_option( 'certificate_generator_job_lock_' . $id ), 'lock released' );
	}

	public function test_worker_crash_and_lost_save_never_duplicate(): void {
		$ids = array( $this->recipient( 'students', 'Crash One' ), $this->recipient( 'students', 'Crash Two' ), $this->recipient( 'students', 'Crash Three' ) );
		$id  = $this->job( $ids );

		// Item 3's worker dies (an Error, not an Exception) after its serial was issued, before the PDF is written.
		$issued = 0;
		$boom   = function () use ( &$issued ) {
			if ( ++$issued === 3 ) {
				throw new Error( 'simulated crash' );
			}
		};
		add_action( 'certificate_generator_certificate_issued', $boom, 1 );
		$this->run_job( $id );
		remove_action( 'certificate_generator_certificate_issued', $boom, 1 );
		$job = Jobs::summary( Jobs::get( $id ) );
		$this->assertSame( array( 2, 1 ), array( $job['done'], $job['failed'] ) );
		$this->assertStringContainsString( 'simulated crash', $job['items'][0]['error'] );
		$serial = $this->row( 'students', $ids[2] )['serial_number'];
		$count  = $this->certificate_count();

		Jobs::retry( $id );
		$this->run_job( $id );
		$this->assertSame( 3, (int) Jobs::get( $id )['done'] );
		if ( $serial ) {
			$this->assertSame( $serial, $this->row( 'students', $ids[2] )['serial_number'], 'the crashed item keeps its serial' );
		}
		$this->assertLessThanOrEqual( 3, $this->certificate_count(), 'one certificate per recipient' );
		$this->assertGreaterThanOrEqual( $count, $this->certificate_count() );

		// The worker rendered item 1 but died before saving it: the item is pending again.
		global $wpdb;
		$job          = Jobs::get( $id );
		$job['items'][0]['status'] = 'pending';
		unset( $job['items'][0]['certificate_id'] );
		$wpdb->update( Jobs::table(), array( 'items' => wp_json_encode( $job['items'] ), 'status' => 'running', 'done' => 2 ), array( 'id' => $id ) );
		$before        = $this->certificate_count();
		$serial1       = $this->row( 'students', $ids[0] )['serial_number'];
		$this->renders = 0;
		$this->run_job( $id );
		$item = Jobs::get( $id )['items'][0];
		$this->assertSame( array( 'done', false ), array( $item['status'], $item['created'] ) );
		$this->assertSame( 0, $this->renders, 'the existing PDF was reused' );
		$this->assertSame( $before, $this->certificate_count() );
		$this->assertSame( $serial1, $this->row( 'students', $ids[0] )['serial_number'] );
	}

	public function test_stalled_job_is_reported_and_resumed(): void {
		$id = $this->job( array( $this->recipient( 'students', 'Stale Student' ) ) );
		wp_clear_scheduled_hook( Jobs::CRON_HOOK, array( $id ) ); // WP-Cron off, browser closed
		global $wpdb;
		$wpdb->update( Jobs::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $id ) );
		$this->assertTrue( Jobs::is_stalled( Jobs::get( $id ) ) );

		do_action( 'certificate_generator_publish_scheduled_templates' ); // the hourly cron
		$this->assertNotFalse( wp_next_scheduled( Jobs::CRON_HOOK, array( $id ) ) );

		Jobs::process( $id ); // what the cron event (or `wp certificate-generator jobs run`) does
		$this->assertSame( 'done', Jobs::get( $id )['status'] );
		$this->assertFalse( Jobs::is_stalled( Jobs::get( $id ) ) );
	}

	public function test_force_rerenders_missing_pdf_rerenders_and_serials_stay(): void {
		$teachers = array( $this->recipient( 'teachers', 'Force Teacher A' ), $this->recipient( 'teachers', 'Force Teacher B' ) );
		$this->run_job( $this->job( $teachers, array( 'entity' => 'teachers' ) ) );
		$serials = array_map( fn( $t ) => $this->row( 'teachers', $t )['serial_number'], $teachers );
		$this->assertNotEmpty( $serials[0] );
		$count = $this->certificate_count();

		// Plain Generate again: valid PDFs are left alone.
		$this->renders = 0;
		$this->run_job( $this->job( $teachers, array( 'entity' => 'teachers' ) ) );
		$this->assertSame( 0, $this->renders );

		// One PDF deleted on disk: only that one is rendered again.
		global $wpdb;
		$path = (string) $wpdb->get_var( $wpdb->prepare( "SELECT pdf_path FROM {$wpdb->prefix}cg_certificates WHERE serial_number = %s", $serials[0] ) );
		$this->assertFileExists( $path );
		unlink( $path );
		$this->run_job( $this->job( $teachers, array( 'entity' => 'teachers' ) ) );
		$this->assertSame( 1, $this->renders );
		$this->assertFileExists( $path );

		// Regenerate: both rendered, same serials, no new certificates.
		$this->renders = 0;
		$this->run_job( $this->job( $teachers, array( 'entity' => 'teachers', 'force' => true ) ) );
		$this->assertSame( 2, $this->renders );
		$this->assertSame( $serials, array_map( fn( $t ) => $this->row( 'teachers', $t )['serial_number'], $teachers ) );
		$this->assertSame( $count, $this->certificate_count() );
	}

	/** The Students list "PDF status" filter: never generated / file deleted on disk / present. */
	public function test_pdf_status_filter(): void {
		$made    = array( $this->recipient( 'students', 'Pdf Filter Made' ), $this->recipient( 'students', 'Pdf Filter Lost' ) );
		$never   = $this->recipient( 'students', 'Pdf Filter Never' );
		$this->run_job( $this->job( $made ) );
		global $wpdb;
		$lost = (string) $wpdb->get_var( $wpdb->prepare( "SELECT pdf_path FROM {$wpdb->prefix}cg_certificates WHERE serial_number = %s", $this->row( 'students', $made[1] )['serial_number'] ) );
		unlink( $lost );

		$ids = function ( string $state ): array {
			$_GET  = array( 'pdf' => $state, 's' => 'Pdf Filter' );
			$page  = new \CertificateGenerator\Admin\Pages\StudentsPage();
			$call  = fn( string $m, ...$a ) => ( new ReflectionMethod( $page, $m ) )->invoke( $page, ...$a );
			[ $where, $params ] = $call( 'where', $call( 'filters' ) );
			global $wpdb;
			$table = CustomTables::instance()->get_table( 'students' );
			return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT e.id FROM $table e WHERE $where ORDER BY e.id", ...$params ) ) );
		};
		$this->assertSame( array( $never ), $ids( 'none' ) );
		$this->assertSame( array( $made[1] ), $ids( 'missing' ) );
		$this->assertSame( array( $made[0] ), $ids( 'ok' ) );
		$_GET = array();
	}

	public function test_diagnostics_report_a_stalled_job_and_overdue_cron_with_fixes(): void {
		$id = $this->job( array( $this->recipient( 'students', 'Diag Student' ) ) );
		global $wpdb;
		$wpdb->update( Jobs::table(), array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ), array( 'id' => $id ) );
		wp_clear_scheduled_hook( Jobs::CRON_HOOK, array( $id ) );
		wp_schedule_single_event( time() - HOUR_IN_SECONDS, Jobs::CRON_HOOK, array( $id ) ); // an event nobody ran

		$checks = array_column( certificate_generator_diagnostics(), null, 'key' );
		foreach ( array( 'certificates_dir', 'fonts', 'fpdf', 'images', 'db_version', 'cron', 'mail', 'jobs' ) as $key ) {
			$this->assertArrayHasKey( $key, $checks );
		}
		$this->assertSame( 'fail', $checks['cron']['status'] );
		$this->assertStringContainsString( 'wp cron event run', $checks['cron']['fix'] );
		$this->assertSame( 'fail', $checks['jobs']['status'] );
		$this->assertStringContainsString( 'jobs run', $checks['jobs']['fix'] );
		$this->assertSame( 'pass', $checks['db_version']['status'] );
		$this->assertSame( '', $checks['db_version']['fix'], 'no fix text on a pass' );
	}

	public function test_create_validates(): void {
		$this->assertWPError( Jobs::create( array(), 1 ) );
		$this->assertWPError( Jobs::create( array( 1 ), 1, 0, '', array( 'entity' => 'templates' ) ) );
		add_filter( 'certificate_generator_job_max_items', fn() => 2 );
		$this->assertSame( 'too_many_items', Jobs::create( array( 1, 2, 3 ), 1 )->get_error_code() );
		$a = Jobs::create( array( 1 ), 1, 0, 'same-key' );
		$this->assertSame( $a['id'], Jobs::create( array( 2 ), 1, 0, 'same-key' )['id'], 'idempotent per user' );
	}
}
