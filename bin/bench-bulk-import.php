<?php
/**
 * Bulk student import benchmark against the real database (autocommit, as in production —
 * PHPUnit's per-test transaction hides commit cost).
 *
 * Usage: wp eval-file bin/bench-bulk-import.php 10000
 *
 * Imports N synthetic students twice (fresh, then the same file again as a re-import), reports
 * time / queries / peak memory / rows saved, then deletes everything it created.
 * Dev/staging only — never run on production.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

global $wpdb;
$n      = max( 1, (int) ( $args[0] ?? 1000 ) );
$source = 'cg-bench-import.csv';
$table  = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );

$csv = wp_tempnam( 'cg-bench-import' );
$fh  = fopen( $csv, 'w' );
fwrite( $fh, "student_name,email,school_name,issue_date,certificate_type\n" );
for ( $i = 0; $i < $n; $i++ ) {
	fwrite( $fh, sprintf( "Bench Import %05d,bench-import-%05d@cg-bench.invalid,Bench School %d,2026-01-15,Bench Import Cert\n", $i, $i, $i % 20 ) );
}
fclose( $fh );

wp_set_current_user( (int) $wpdb->get_var( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$wpdb->prefix}capabilities' AND meta_value LIKE '%administrator%' LIMIT 1" ) );

$run = function () use ( $csv, $source, $wpdb, $table ) {
	$_POST    = array(
		'submit_students'      => '1',
		'_wpnonce_bulk_import' => wp_create_nonce( 'bulk_import_students_nonce' ),
	);
	$_REQUEST = $_POST;
	$_FILES   = array(
		'students_csv' => array(
			'tmp_name' => $csv,
			'error'    => UPLOAD_ERR_OK,
			'name'     => $source,
		),
	);
	memory_reset_peak_usage();
	$q0 = $wpdb->num_queries;
	$t0 = microtime( true );
	ob_start();
	bulk_import_students();
	$html = ob_get_clean();
	return array(
		's'    => microtime( true ) - $t0,
		'q'    => $wpdb->num_queries - $q0,
		'mb'   => memory_get_peak_usage() / MB_IN_BYTES,
		'rows' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE import_source = %s", $source ) ),
		'msg'  => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) ),
	);
};

$results = array(
	'fresh'    => $run(),
	're-import' => $run(),
);

$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE import_source = %s", $source ) );
$wpdb->query( "DELETE FROM " . \CertificateGenerator\Database\CustomTables::instance()->get_table( 'schools' ) . " WHERE school_name LIKE 'Bench School %'" );
unlink( $csv );

foreach ( $results as $phase => $r ) {
	WP_CLI::log( sprintf( 'N=%d %-9s %.2fs | %d queries (%.1f/row) | peak %.0f MB | rows in table %d', $n, $phase, $r['s'], $r['q'], $r['q'] / $n, $r['mb'], $r['rows'] ) );
}
WP_CLI::log( 'last report: ' . substr( $results['re-import']['msg'], -220 ) );
