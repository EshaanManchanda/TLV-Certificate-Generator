<?php
/**
 * Bulk-download benchmark: lookup → PDF render → ZIP, cold (no PDFs on disk) then warm.
 *
 * Usage (from the Local "site shell", or any wp-cli with DB access):
 *   wp eval-file bin/bench-bulk-download.php 100
 *
 * Seeds N synthetic students (email *@cg-bench.invalid, serial BENCH-*) against the first
 * published template, measures, then deletes everything it created and restores the
 * monthly usage counter. Dev/staging only — never run on production.
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit;
}

global $wpdb;
$n = max( 1, (int) ( $args[0] ?? 100 ) );

$tables    = \CertificateGenerator\Database\CustomTables::instance();
$stu_table = $tables->get_table( 'students' );
$template  = $wpdb->get_row( 'SELECT * FROM ' . $tables->get_table( 'certificate_templates' ) . " WHERE status = 'published' ORDER BY id LIMIT 1", ARRAY_A );
if ( ! $template ) {
	WP_CLI::error( 'No published template to benchmark against.' );
}

$usage_before = (int) get_option( CG_License_Manager::OPTION_USAGE, 0 );
set_transient( 'cg_usage_report_lock', 1, HOUR_IN_SECONDS ); // no license-server call mid-benchmark

$renders = 0;
add_action( 'certificate_generator_certificate_generated', function () use ( &$renders ) {
	++$renders;
}, 1 );

// ── Seed ──────────────────────────────────────────────────────────────────────
$issue = substr( (string) $template['event_date'], 0, 10 );
$issue = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue ) && '0000-00-00' !== $issue ? $issue : gmdate( 'Y-m-d' );
for ( $i = 0; $i < $n; $i += 500 ) {
	$values = array();
	for ( $j = $i; $j < min( $n, $i + 500 ); $j++ ) {
		$values[] = $wpdb->prepare(
			'(%s,%s,%s,%s,%s,%d,%s,%s)',
			sprintf( 'Bench Student %05d', $j ),
			sprintf( 'bench%05d@cg-bench.invalid', $j ),
			'Bench School',
			$template['certificate_type'],
			$issue,
			(int) substr( $issue, 0, 4 ),
			sprintf( 'BENCH-%06d', $j ),
			'active'
		);
	}
	$wpdb->query( "INSERT INTO {$stu_table} (student_name, email, school_name, certificate_type, issue_date, year, serial_number, status) VALUES " . implode( ',', $values ) ); // phpcs:ignore
}

$filters = array(
	'entity'            => 'students',
	'email_search'      => '@cg-bench.invalid',
	'schools'           => array(),
	'certificate_types' => array(),
	'year'              => array(),
	'date_from'         => '',
	'date_to'           => '',
	'emails'            => array(),
	'events'            => array(),
);

$zips = array();

/** Mirrors certificate_generator_handle_admin_cert_zip_download() for every part: render, then zip. */
$run = function () use ( $filters, $wpdb, &$zips ) {
	$m = array();

	memory_reset_peak_usage();
	$q0   = $wpdb->num_queries;
	$t0   = microtime( true );
	$rows = array();
	$size = CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE;
	$tot  = certificate_generator_admin_cert_count( $filters );
	for ( $off = 0; $off < $tot; $off += $size ) {
		$rows = array_merge( $rows, certificate_generator_admin_cert_query( $filters, $size, $off ) );
	}
	$m['lookup_s']  = microtime( true ) - $t0;
	$m['lookup_q']  = $wpdb->num_queries - $q0;

	$q0    = $wpdb->num_queries;
	$t0    = microtime( true );
	$paths = array();
	$up    = wp_upload_dir();
	foreach ( $rows as $row ) {
		$url = certificate_generator_generate_certificate_pdf( (int) ( $row['wp_post_id'] ?? $row['id'] ), array(), $row );
		if ( $url ) {
			$paths[ (int) $row['id'] ] = str_replace( $up['baseurl'], $up['basedir'], $url );
		}
	}
	$m['render_s']  = microtime( true ) - $t0;
	$m['render_q']  = $wpdb->num_queries - $q0;
	$m['render_mb'] = memory_get_peak_usage() / 1048576;
	$m['pdfs']      = count( $paths );

	memory_reset_peak_usage();
	$t0      = microtime( true );
	$zip_mb  = 0;
	$entries = 0;
	foreach ( array_chunk( $rows, $size ) as $p => $part ) {
		$res = cg_bench_zip_part( $part, $paths, $p );
		if ( $res ) {
			$zip_mb  += filesize( $res['zip_path'] ) / 1048576;
			$entries += $res['certificate_count'];
			if ( function_exists( 'certificate_generator_admin_cert_manifest_csv' ) ) {
				$zips[] = $res['zip_path']; // kept for reuse, as the admin flow now does
			} else {
				wp_delete_file( $res['zip_path'] ); // the old admin handler deleted it after sending
			}
		}
	}
	$m['zip_s']   = microtime( true ) - $t0;
	$m['zip_mb']  = $zip_mb;
	$m['zip_mem'] = memory_get_peak_usage() / 1048576;
	$m['entries'] = $entries;
	return $m;
};

/** One ZIP part, built the way the admin handler builds it. */
function cg_bench_zip_part( array $rows, array $paths, int $part ) {
	$up   = wp_upload_dir();
	$tmp  = $up['basedir'] . '/temp_cg_bench_' . $part . '_' . wp_generate_password( 6, false );
	$copy = ! function_exists( 'certificate_generator_admin_cert_manifest_csv' ); // pre-optimisation handler copied every PDF
	if ( $copy ) {
		wp_mkdir_p( $tmp );
	}
	$files = array();
	$csv   = array( array( 'name', 'email', 'school', 'cert_type', 'cg_id', 'pdf_filename' ) );
	foreach ( $rows as $row ) {
		if ( empty( $paths[ (int) $row['id'] ] ) ) {
			continue;
		}
		$name = certificate_generator_certificate_pdf_filename( $row['student_name'], $row['certificate_type'], (string) $row['id'] );
		$src  = $paths[ (int) $row['id'] ];
		if ( $copy ) {
			copy( $src, $tmp . '/' . $name );
			$src = $tmp . '/' . $name;
		}
		$files[] = array( 'path' => $src, 'filename' => $name );
		$csv[]   = array( $row['student_name'], $row['email'], $row['school_name'], $row['certificate_type'], (string) $row['id'], $name );
	}
	if ( $copy ) {
		$fh = fopen( $tmp . '/manifest.csv', 'w' );
		foreach ( $csv as $line ) {
			fputcsv( $fh, $line );
		}
		fclose( $fh );
		$files[] = array( 'path' => $tmp . '/manifest.csv', 'filename' => 'manifest.csv' );
		$res     = certificate_generator_create_zip_for_email( $files, 'bench_part' . $part );
		foreach ( $files as $f ) {
			@unlink( $f['path'] );
		}
		@rmdir( $tmp );
		return $res;
	}
	$files[] = array( 'content' => certificate_generator_admin_cert_manifest_csv( $csv ), 'filename' => 'manifest.csv' );
	return certificate_generator_create_zip_for_email( $files, 'bench_part' . $part, array( 'private' => true ) );
}

// ── Cold: no PDF on disk for any benchmark row ───────────────────────────────
$ids = $wpdb->get_col( "SELECT id FROM {$stu_table} WHERE email LIKE '%@cg-bench.invalid'" );
foreach ( $ids as $id ) {
	@unlink( certificate_generator_certificates_dir() . "/certificate_students_row_{$id}.pdf" );
}
$results = array();
$r0             = $renders;
$results['cold'] = $run();
$results['cold']['renders'] = $renders - $r0;
$results['cold']['usage+']  = (int) get_option( CG_License_Manager::OPTION_USAGE, 0 ) - $usage_before;

$r0              = $renders;
$u0              = (int) get_option( CG_License_Manager::OPTION_USAGE, 0 );
$results['warm'] = $run();
$results['warm']['renders'] = $renders - $r0;
$results['warm']['usage+']  = (int) get_option( CG_License_Manager::OPTION_USAGE, 0 ) - $u0;

// ── Cleanup ──────────────────────────────────────────────────────────────────
foreach ( array_unique( $zips ) as $zip ) {
	wp_delete_file( $zip );
}
foreach ( $ids as $id ) {
	@unlink( certificate_generator_certificates_dir() . "/certificate_students_row_{$id}.pdf" );
}
$wpdb->query( "DELETE FROM {$stu_table} WHERE email LIKE '%@cg-bench.invalid'" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}certificate_generator WHERE serial_number LIKE 'BENCH-%'" );
$wpdb->query( 'DELETE FROM ' . $tables->get_table( 'certificates' ) . " WHERE serial_number LIKE 'BENCH-%'" );
update_option( CG_License_Manager::OPTION_USAGE, $usage_before );

// ── Report ───────────────────────────────────────────────────────────────────
WP_CLI::log( sprintf( 'N=%d  template=#%d %s  part_size=%d', $n, $template['id'], $template['certificate_type'], CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE ) );
foreach ( $results as $phase => $m ) {
	WP_CLI::log(
		sprintf(
			'%-4s lookup %.2fs/%dq | render %.2fs (%.1f ms/cert) %dq (%.1f q/cert) peak %.0fMB | renders %d usage+%d | zip %.2fs %.1fMB %d entries peak %.0fMB | total %.2fs',
			$phase,
			$m['lookup_s'],
			$m['lookup_q'],
			$m['render_s'],
			1000 * $m['render_s'] / max( 1, $n ),
			$m['render_q'],
			$m['render_q'] / max( 1, $n ),
			$m['render_mb'],
			$m['renders'],
			$m['usage+'],
			$m['zip_s'],
			$m['zip_mb'],
			$m['entries'],
			$m['zip_mem'],
			$m['lookup_s'] + $m['render_s'] + $m['zip_s']
		)
	);
}
