<?php
/**
 * WP-CLI: `wp certificate-generator generate|import|jobs|diagnostics`. Thin wrappers over the
 * same code the admin uses (bulk jobs, the CSV importer, the diagnostics checks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! defined( 'WP_CLI' ) ) {
	return;
}

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Services\Jobs;

class CertificateGenerator_CLI {

	private const ENTITIES = array( 'students', 'teachers', 'schools' );

	private function entity( array $assoc ): string {
		$entity = (string) ( $assoc['entity'] ?? 'students' );
		if ( ! in_array( $entity, self::ENTITIES, true ) ) {
			WP_CLI::error( '--entity must be students, teachers or schools.' );
		}
		return $entity;
	}

	/**
	 * Generate certificates for recipients as a bulk job, run here with a progress bar.
	 *
	 * ## OPTIONS
	 *
	 * [--entity=<entity>]
	 * : students, teachers or schools.
	 * ---
	 * default: students
	 * ---
	 *
	 * [--ids=<ids>]
	 * : Comma-separated record ids.
	 *
	 * [--certificate-type=<type>]
	 * : Every record of this certificate type (with --issue-date, of that date too).
	 *
	 * [--issue-date=<date>]
	 * : YYYY-MM-DD, with --certificate-type.
	 *
	 * [--missing-pdf]
	 * : Every record never generated, or whose PDF file is gone.
	 *
	 * [--force]
	 * : Re-render PDFs that are up to date (same serials).
	 *
	 * ## EXAMPLES
	 *
	 *     wp certificate-generator generate --certificate-type="Participation" --issue-date=2026-10-20
	 *     wp certificate-generator generate --entity=teachers --missing-pdf
	 */
	public function generate( array $args, array $assoc ): void {
		global $wpdb;
		$entity = $this->entity( $assoc );
		$table  = CustomTables::instance()->get_table( $entity );
		if ( ! empty( $assoc['ids'] ) ) {
			$ids = array_map( 'intval', explode( ',', (string) $assoc['ids'] ) );
		} elseif ( ! empty( $assoc['certificate-type'] ) ) {
			$sql = $wpdb->prepare( "SELECT id FROM $table WHERE certificate_type = %s", (string) $assoc['certificate-type'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
			if ( ! empty( $assoc['issue-date'] ) ) {
				$sql .= $wpdb->prepare( ' AND issue_date = %s', (string) $assoc['issue-date'] );
			}
			$ids = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above
		} elseif ( isset( $assoc['missing-pdf'] ) ) {
			$certs = CustomTables::instance()->get_table( 'certificates' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix
			$rows = $wpdb->get_results( "SELECT e.id, (SELECT c.pdf_path FROM $certs c WHERE c.serial_number = e.serial_number AND c.pdf_path <> '' ORDER BY c.id DESC LIMIT 1) AS p FROM $table e WHERE e.certificate_type <> ''", ARRAY_A ) ?: array();
			$ids  = array_map( 'intval', array_column( array_filter( $rows, static fn( $r ) => null === $r['p'] || ! file_exists( (string) $r['p'] ) ), 'id' ) );
		} else {
			WP_CLI::error( 'Say which records: --ids, --certificate-type or --missing-pdf.' );
		}
		if ( ! $ids ) {
			WP_CLI::success( 'Nothing to generate.' );
			return;
		}
		$job = Jobs::create( $ids, get_current_user_id(), 0, '', array( 'entity' => $entity, 'force' => isset( $assoc['force'] ) ) );
		if ( is_wp_error( $job ) ) {
			WP_CLI::error( $job->get_error_message() );
		}
		WP_CLI::log( sprintf( 'Job #%d: %d %s.', $job['id'], $job['total'], $entity ) );
		$this->run_job( (int) $job['id'] );
	}

	private function run_job( int $id ): void {
		$job      = Jobs::get( $id );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Generating', (int) $job['total'] );
		$seen     = (int) $job['done'] + (int) $job['failed'];
		$progress->tick( $seen );
		while ( in_array( $job['status'], array( 'queued', 'running' ), true ) ) {
			if ( Jobs::is_locked( $id ) ) {
				WP_CLI::error( "Another worker is running job #$id right now; try again in a minute." );
			}
			Jobs::process( $id );
			$job  = Jobs::get( $id );
			$now  = (int) $job['done'] + (int) $job['failed'];
			$progress->tick( $now - $seen );
			$seen = $now;
		}
		$progress->finish();
		wp_clear_scheduled_hook( Jobs::CRON_HOOK, array( $id ) );
		$this->report( $job );
	}

	private function report( array $job ): void {
		$s = Jobs::summary( $job );
		foreach ( array_filter( $s['items'], static fn( $i ) => 'failed' === $i['status'] ) as $item ) {
			WP_CLI::warning( sprintf( '#%d: %s', $item['ref'], $item['error'] ?? '' ) );
		}
		$line = sprintf( 'Job #%d %s: %d done, %d failed, %d pending.', $s['id'], $s['status'], $s['done'], $s['failed'], $s['pending'] );
		if ( 'done' === $s['status'] && ! $s['failed'] ) {
			WP_CLI::success( $line );
		} else {
			WP_CLI::log( $line );
		}
	}

	/**
	 * Import a CSV of students, teachers or schools (the same importer as Bulk Import).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the CSV file.
	 *
	 * [--entity=<entity>]
	 * : students, teachers or schools.
	 * ---
	 * default: students
	 * ---
	 *
	 * [--existing=<mode>]
	 * : A row matching an existing record: update, keep, or flag (for review in Import history).
	 * ---
	 * default: update
	 * ---
	 *
	 * [--date-order=<order>]
	 * : dmy, mdy or auto.
	 * ---
	 * default: auto
	 * ---
	 *
	 * [--dry-run]
	 * : Count what would happen; save nothing.
	 *
	 * [--source=<name>]
	 * : The name recorded as import source (default: the file name).
	 */
	public function import( array $args, array $assoc ): void {
		require_once CERTIFICATE_GENERATOR_PATH . 'includes/Services/bulk-import.php';
		$entity = $this->entity( $assoc );
		$file   = (string) ( $args[0] ?? '' );
		if ( ! is_readable( $file ) ) {
			WP_CLI::error( "Can't read $file." );
		}
		$result = certificate_generator_import_run(
			$entity,
			$file,
			array(
				'source'     => (string) ( $assoc['source'] ?? basename( $file ) ),
				'date_order' => (string) ( $assoc['date-order'] ?? 'auto' ),
				'existing'   => (string) ( $assoc['existing'] ?? 'update' ),
				'dry_run'    => isset( $assoc['dry-run'] ),
			)
		);
		if ( is_string( $result ) ) {
			WP_CLI::error( $result );
		}
		if ( $result['missing'] ) {
			WP_CLI::error( 'Required columns not found: ' . implode( ', ', $result['missing'] ) );
		}
		foreach ( array_slice( $result['report']['issues'], 0, 50 ) as list( $line, $name, $reason ) ) {
			WP_CLI::log( "  row $line ($name): $reason" );
		}
		$c = $result['report']['counts'];
		WP_CLI::success(
			sprintf(
				'%s%d rows: %d new, %d updated, %d merged, %d flagged, %d skipped, %d failed.%s',
				isset( $assoc['dry-run'] ) ? 'Dry run — nothing saved. ' : '',
				$c['read'],
				$c['new'],
				$c['updated'],
				$c['merged'],
				$c['flagged'],
				$c['skipped'],
				$c['failed'],
				empty( $result['run_id'] ) ? '' : ' Import #' . $result['run_id'] . ' (Bulk Import → Import history).'
			)
		);
	}

	/**
	 * Bulk generation jobs.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, status, retry or run.
	 *
	 * [<id>]
	 * : The job id (status, retry, run).
	 *
	 * ## EXAMPLES
	 *
	 *     wp certificate-generator jobs list
	 *     wp certificate-generator jobs run 12
	 */
	public function jobs( array $args, array $assoc ): void {
		$action = (string) ( $args[0] ?? 'list' );
		if ( 'list' === $action ) {
			$rows = array_map(
				static fn( $j ) => array(
					'id'      => $j['id'],
					'entity'  => $j['params']['entity'] ?? 'students',
					'status'  => Jobs::is_stalled( $j ) ? 'stalled' : $j['status'],
					'done'    => $j['done'],
					'failed'  => $j['failed'],
					'total'   => $j['total'],
					'created' => $j['created_at'],
				),
				Jobs::recent( 20 )
			);
			\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'entity', 'status', 'done', 'failed', 'total', 'created' ) );
			return;
		}
		$id  = (int) ( $args[1] ?? 0 );
		$job = $id ? Jobs::get( $id ) : null;
		if ( ! $job || 'certificate_generation' !== $job['type'] ) {
			WP_CLI::error( 'No such job.' );
		}
		if ( 'retry' === $action ) {
			if ( ! Jobs::retry( $id ) ) {
				WP_CLI::error( 'Nothing to retry (the job is still running, or has no failed items).' );
			}
			$this->run_job( $id );
		} elseif ( 'run' === $action ) {
			$this->run_job( $id );
		} elseif ( 'status' === $action ) {
			$this->report( $job );
		} else {
			WP_CLI::error( 'Unknown action: use list, status, retry or run.' );
		}
	}

	/**
	 * Check folders, libraries, database, scheduled tasks, email queue and bulk jobs.
	 * Exits with 1 when a check fails.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 */
	public function diagnostics( array $args, array $assoc ): void {
		$checks = certificate_generator_diagnostics();
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $checks, array( 'label', 'status', 'value', 'fix' ) );
		if ( in_array( 'fail', array_column( $checks, 'status' ), true ) ) {
			WP_CLI::halt( 1 );
		}
	}
}

WP_CLI::add_command( 'certificate-generator', 'CertificateGenerator_CLI' );
