<?php
/**
 * Excluded from the default `composer test` run (phpunit.xml.dist excludes
 * the "performance" group) — run explicitly via `composer test:performance`.
 * Ceilings here are deliberately generous: the point is catching a gross
 * regression (e.g. an accidental N+1 query turning a linear import into a
 * quadratic one), not micro-benchmarking.
 *
 * @group performance
 */
class BulkGenerationPerformanceTest extends WP_UnitTestCase {

	private function make_csv( int $rows ): string {
		$path = tempnam( sys_get_temp_dir(), 'cg_perf_' ) . '.csv';
		$fh   = fopen( $path, 'w' );
		fwrite( $fh, "student_name,email,school_name,issue_date,certificate_type\n" );
		for ( $i = 0; $i < $rows; $i++ ) {
			fwrite( $fh, "Perf Student {$i},perf-{$i}@example.com,Perf School,2026-01-15,Perf Certificate\n" );
		}
		fclose( $fh );
		return $path;
	}

	private function run_import( int $rows ): array {
		global $wpdb;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$csv_path = $this->make_csv( $rows );

		$_POST                   = array(
			'submit_students'      => '1',
			'_wpnonce_bulk_import' => wp_create_nonce( 'bulk_import_students_nonce' ),
		);
		$_REQUEST                = $_POST;
		$_FILES['students_csv']  = array(
			'tmp_name' => $csv_path,
			'error'    => UPLOAD_ERR_OK,
			'name'     => 'perf.csv',
		);

		$start_time    = microtime( true );
		$start_queries = $wpdb->num_queries;
		memory_reset_peak_usage();

		ob_start();
		certificate_generator_bulk_import_students();
		$output = ob_get_clean();

		$elapsed = microtime( true ) - $start_time;
		$queries = $wpdb->num_queries - $start_queries;
		$peak_mb = memory_get_peak_usage() / 1024 / 1024;
		fwrite( STDERR, sprintf( "\n[import %d rows] %.2fs, %d queries (%.1f/row), peak %.0f MB\n", $rows, $elapsed, $queries, $queries / $rows, $peak_mb ) );

		unlink( $csv_path );
		$_POST   = array();
		$_REQUEST = array();
		unset( $_FILES['students_csv'] );

		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email LIKE %s", 'perf-%@example.com' )
		);

		return array(
			'output'   => $output,
			'elapsed'  => $elapsed,
			'peak_mb'  => $peak_mb,
			'imported' => $count,
		);
	}

	public function test_import_100_rows_completes_within_generous_ceiling(): void {
		$result = $this->run_import( 100 );

		$this->assertSame( 100, $result['imported'] );
		$this->assertLessThan( 30.0, $result['elapsed'], 'Importing 100 rows took an unexpectedly long time.' );
		$this->assertLessThan( 256, $result['peak_mb'], 'Importing 100 rows used an unexpectedly large amount of memory.' );
	}

	public function test_import_1000_rows_completes_within_generous_ceiling(): void {
		$result = $this->run_import( 1000 );

		$this->assertSame( 1000, $result['imported'] );
		$this->assertLessThan( 180.0, $result['elapsed'], 'Importing 1000 rows took an unexpectedly long time.' );
		$this->assertLessThan( 512, $result['peak_mb'], 'Importing 1000 rows used an unexpectedly large amount of memory.' );
	}

	public function test_import_10000_rows_completes_within_generous_ceiling(): void {
		$result = $this->run_import( 10000 );

		$this->assertSame( 10000, $result['imported'] );
		$this->assertLessThan( 600.0, $result['elapsed'], 'Importing 10000 rows took an unexpectedly long time.' );
		$this->assertLessThan( 512, $result['peak_mb'], 'Importing 10000 rows used an unexpectedly large amount of memory.' );
	}
}
