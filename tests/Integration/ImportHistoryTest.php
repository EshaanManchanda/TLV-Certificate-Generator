<?php
/**
 * Import history (includes/Services/import-history.php): every import is a run, a resumed
 * import stays one run, flagged rows wait for review, and undo reverts only what nobody
 * touched since — every record it keeps is listed with the reason. Also the
 * {verification_code} / {certificate_link} email tags.
 */

use CertificateGenerator\Database\CustomTables;

class ImportHistoryTest extends WP_UnitTestCase {

	private array $tmp = array();

	public function set_up(): void {
		parent::set_up();
		require_once CERTIFICATE_GENERATOR_PATH . 'includes/Services/bulk-import.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		foreach ( $this->tmp as $f ) {
			@unlink( $f );
		}
		remove_all_filters( 'certificate_generator_import_deadline' );
		parent::tear_down();
	}

	private function csv( array $rows ): string {
		$path = tempnam( sys_get_temp_dir(), 'cg_hist_' );
		file_put_contents( $path, "student_name,email,school_name,certificate_type,issue_date\n" . implode( "\n", $rows ) . "\n" );
		$this->tmp[] = $path;
		return $path;
	}

	private function import( string $file, string $existing = 'update' ): array {
		$result = certificate_generator_import_run( 'students', $file, array( 'source' => 'history.csv', 'date_order' => 'auto', 'existing' => $existing ) );
		$this->assertIsArray( $result );
		return $result;
	}

	private function student( string $email ): ?array {
		global $wpdb;
		$table = CustomTables::instance()->get_table( 'students' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $email ), ARRAY_A );
	}

	private function set( string $email, array $fields ): void {
		global $wpdb;
		$wpdb->update( CustomTables::instance()->get_table( 'students' ), $fields, array( 'email' => $email ) );
	}

	public function test_resumed_import_is_one_run(): void {
		add_filter( 'certificate_generator_import_deadline', fn() => microtime( true ) - 1 ); // stop after the first chunk
		$rows = array();
		for ( $i = 0; $i < CertificateGenerator_Import_Writer::CHUNK + 5; $i++ ) {
			$rows[] = "Hist $i,hist$i@history.test,H School,HistCert,2026-01-15";
		}
		$file  = $this->csv( $rows );
		$first = $this->import( $file );
		$this->assertNotEmpty( $first['report']['stopped_at'] );
		remove_all_filters( 'certificate_generator_import_deadline' );
		$second = $this->import( $file );
		$this->assertSame( $first['run_id'], $second['run_id'] );
		$run = certificate_generator_import_run_get( $second['run_id'] );
		$this->assertSame( array( 'done', CertificateGenerator_Import_Writer::CHUNK + 5 ), array( $run['status'], $run['params']['counts']['new'] ) );
	}

	public function test_flag_then_apply_add_dismiss(): void {
		$this->import( $this->csv( array( 'Flag One,flag1@history.test,F School,HistCert,2026-01-15', 'Flag Two,flag2@history.test,F School,HistCert,2026-01-15', 'Flag Three,flag3@history.test,F School,HistCert,2026-01-15' ) ) );
		$file    = $this->csv( array( 'Flag One,flag1@history.test,F School,HistCert,2026-01-15', 'Flag Two,flag2@history.test,F School,HistCert,2026-01-15', 'Flag Three,flag3@history.test,F School,HistCert,2026-01-15' ) );
		$preview = certificate_generator_import_run( 'students', $file, array( 'dry_run' => true, 'date_order' => 'auto', 'existing' => 'flag' ) );
		$this->assertSame( array( 3, 0 ), array( $preview['report']['counts']['flagged'], $preview['report']['counts']['updated'] ), 'the check step counts flagged rows' );
		$result = $this->import( $file, 'flag' );
		$this->assertSame( 3, $result['report']['counts']['flagged'] );

		// The file's values differ only in a column we change on the record first, so "apply" has something to do.
		$this->set( 'flag1@history.test', array( 'student_name' => 'Flag One (edited)' ) );
		$items = certificate_generator_import_items( $result['run_id'], 'flagged' );
		$this->assertCount( 3, $items );
		$this->assertTrue( certificate_generator_import_resolve_flag( (int) $items[0]['id'], 'apply' ) );
		$this->assertSame( 'Flag One', $this->student( 'flag1@history.test' )['student_name'] );
		$this->assertTrue( certificate_generator_import_resolve_flag( (int) $items[1]['id'], 'add' ) );
		$this->assertTrue( certificate_generator_import_resolve_flag( (int) $items[2]['id'], 'dismiss' ) );
		$this->assertWPError( certificate_generator_import_resolve_flag( (int) $items[2]['id'], 'apply' ), 'already handled' );
		$this->assertSame( 0, certificate_generator_import_item_count( $result['run_id'], 'flagged' ) );

		// Undo reverts the applied change and removes the added row.
		$undo = certificate_generator_import_undo( $result['run_id'] );
		$this->assertSame( 0, $undo['remaining'] );
		$this->assertSame( 'Flag One (edited)', $this->student( 'flag1@history.test' )['student_name'] );
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cg_students WHERE email = 'flag2@history.test'" ) );
	}

	public function test_undo_keeps_what_changed_since_and_says_why(): void {
		$this->import( $this->csv( array( 'Upd Kept,upd1@history.test,U School,HistCert,2026-01-15', 'Upd Back,upd2@history.test,U School,HistCert,2026-01-15' ) ) );
		$this->set( 'upd1@history.test', array( 'phone' => '111' ) );
		$this->set( 'upd2@history.test', array( 'phone' => '222' ) );

		// Second import: updates two existing rows, creates four.
		$path = tempnam( sys_get_temp_dir(), 'cg_hist_' );
		file_put_contents(
			$path,
			"student_name,email,school_name,certificate_type,issue_date,phone\n"
			. "Upd Kept,upd1@history.test,U School,HistCert,2026-01-15,999\n"
			. "Upd Back,upd2@history.test,U School,HistCert,2026-01-15,888\n"
			. "New Plain,new1@history.test,U School,HistCert,2026-01-15,\n"
			. "New Certified,new2@history.test,U School,HistCert,2026-01-15,\n"
			. "New Emailed,new3@history.test,U School,HistCert,2026-01-15,\n"
			. "New Edited,new4@history.test,U School,HistCert,2026-01-15,\n"
		);
		$this->tmp[] = $path;
		$run         = $this->import( $path )['run_id'];
		$this->assertSame( '888', $this->student( 'upd2@history.test' )['phone'] );

		// After the import: a certificate, an email, two edits.
		global $wpdb;
		$this->set( 'new2@history.test', array( 'serial_number' => 'HIST-0001' ) );
		$wpdb->insert( $wpdb->prefix . 'cert_email_queue', array( 'certificate_id' => 1, 'recipient_email' => 'new3@history.test', 'recipient_name' => 'New Emailed', 'post_type' => 'students' ) );
		$wpdb->query( "UPDATE {$wpdb->prefix}cg_students SET student_name = 'New Edited Later', updated_at = DATE_ADD(NOW(), INTERVAL 1 MINUTE) WHERE email = 'new4@history.test'" );
		$this->set( 'upd1@history.test', array( 'phone' => '777' ) ); // its only changed field was edited since

		$plan = certificate_generator_import_undo( $run, true );
		$this->assertSame( 2, $plan['reverted'], 'New Plain deleted + Upd Back restored' );
		$this->assertCount( 4, $plan['skipped'] );
		$this->assertNotNull( $this->student( 'new1@history.test' ), 'the preview writes nothing' );

		$undo = certificate_generator_import_undo( $run, false, 2 ); // small batches: the second call continues
		$this->assertGreaterThan( 0, $undo['remaining'] );
		do {
			$undo = certificate_generator_import_undo( $run, false, 2 );
		} while ( ! is_wp_error( $undo ) && $undo['remaining'] );
		$this->assertSame( 0, $undo['remaining'] );

		$this->assertNull( $this->student( 'new1@history.test' ) );
		foreach ( array( 'new2', 'new3', 'new4' ) as $kept ) {
			$this->assertNotNull( $this->student( "$kept@history.test" ), "$kept kept" );
		}
		$this->assertSame( '222', $this->student( 'upd2@history.test' )['phone'], 'restored' );
		$this->assertSame( '777', $this->student( 'upd1@history.test' )['phone'], 'edited since: kept' );

		$notes = array_column( certificate_generator_import_items( $run, 'skipped' ), 'note' );
		sort( $notes );
		$this->assertCount( 4, $notes );
		$this->assertStringContainsString( 'certificate', implode( '|', $notes ) );
		$this->assertStringContainsString( 'email', implode( '|', $notes ) );
		$this->assertStringContainsString( 'edited since', implode( '|', $notes ) );
		$this->assertSame( 'cancelled', certificate_generator_import_run_get( $run )['status'] );
		$this->assertWPError( certificate_generator_import_undo( $run ), 'undone once' );
	}

	public function test_verification_email_tags(): void {
		$this->assertSame( array( '', '' ), certificate_generator_verification_tags( '' ) );
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'cg_certificates', array( 'recipient_name' => 'Tag Test', 'certificate_type' => 'HistCert', 'serial_number' => 'TAG-0001' ) );
		$code = certificate_generator_verification_code( 'TAG-0001' );
		if ( ! $code ) {
			$this->markTestSkipped( 'no verification code issued for a bare record' );
		}
		list( $shown, $link ) = certificate_generator_verification_tags( 'TAG-0001' );
		$this->assertSame( $code, str_replace( '-', '', $shown ) );
		$this->assertMatchesRegularExpression( '/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $shown );
		$this->assertStringContainsString( 'code=' . $code, $link );
	}
}
