<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/import-history.php';

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV rows are streamed with fgetcsv()/fputcsv(); WP_Filesystem has no stream API.

/**
 * Resolves an event_code CSV value (e.g. "PO-06-2026") to its wp_cg_events.id.
 * Blank/unmatched codes resolve to null so rows import fine without an event link.
 * Per-request cache — CSV rows for one event share the same code.
 */
function certificate_generator_resolve_event_id( string $event_code ): ?int {
	static $cache = array();
	$event_code = trim( $event_code );
	if ( $event_code === '' ) {
		return null;
	}
	if ( array_key_exists( $event_code, $cache ) ) {
		return $cache[ $event_code ];
	}
	$event_id = null;
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$events_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'events' );
		if ( $events_table ) {
			$id       = $GLOBALS['wpdb']->get_var(
				$GLOBALS['wpdb']->prepare( "SELECT id FROM $events_table WHERE event_code = %s LIMIT 1", $event_code ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$event_id = $id ? (int) $id : null;
		}
	}
	$cache[ $event_code ] = $event_id;
	return $event_id;
}

/**
 * One CSV record as header => value, trimmed. Short rows are padded (as before); a row with
 * more values than the header (an unquoted comma, usually) returns a string reason instead —
 * array_combine() would throw on it and kill the whole import.
 *
 * @return array|string
 */
function certificate_generator_import_read_row( array $data, array $header_keys ) {
	if ( count( $data ) > count( $header_keys ) && '' !== trim( implode( '', array_slice( $data, count( $header_keys ) ) ) ) ) {
		return sprintf( 'malformed: %d values but the header has %d columns (an unquoted comma?)', count( $data ), count( $header_keys ) );
	}
	$data = array_slice( array_pad( $data, count( $header_keys ), '' ), 0, count( $header_keys ) );
	return array_combine( $header_keys, array_map( fn( $v ) => trim( (string) $v ), $data ) );
}

/**
 * Writes import rows 500 at a time. Per chunk: one indexed query finds the rows that
 * already exist, then new rows go in as one multi-row INSERT and matches are updated, all in
 * one transaction. (It used to be a SELECT plus an INSERT or UPDATE per CSV row, each its
 * own commit: ~35 s for 10,000 rows, past many hosts' time limit.)
 *
 * A row is the same record as an existing one when every identity column matches — case-
 * and trailing-space-insensitive, as MySQL's _ci collation compared them before — and its
 * certificate_type + issue_date match, or the existing row has no certificate_type yet (a
 * school the students import auto-created). Rows with no email match on the remaining
 * identity columns (name + school) against existing rows that have no email either, so a
 * re-import updates them instead of adding duplicates. Every row's outcome is counted and
 * every merge, skip and failure is listed with its CSV row number.
 *
 * Resume: when a file stops at the time limit, the stop row is remembered for that exact
 * file (content hash). Uploading the same file again skips the rows already committed
 * instead of re-updating them; re-updating is slower than inserting, so without this a
 * large file could stop earlier on every retry and never finish.
 */
class CertificateGenerator_Import_Writer {

	const CHUNK = 500;

	private string $table;
	private array $identity;     // identity columns; the first one is indexed and preloaded by
	private string $name_col;
	private float $deadline;     // stop before this timestamp (0 = no limit)
	private array $buffer  = array();
	private array $seen    = array(); // full identity => CSV row it was first seen on
	private array $issues  = array();
	private array $counts  = array(
		'read'    => 0,
		'new'     => 0,
		'updated' => 0,
		'merged'  => 0,
		'skipped' => 0,
		'failed'  => 0,
		'resumed' => 0,
		'flagged' => 0,
	);
	private int $stopped_at  = 0;
	private string $resume_key = '';
	private int $resume_from   = 0;
	private bool $wrote     = false;
	private bool $nested    = false;
	private bool $dry_run;          // count outcomes only: no writes, no time limit, no resume state
	private string $existing;       // a row matching an existing record: update | keep (skip) | flag (keep + list for review)
	private int $run_id = 0;        // the import run (wp_cg_jobs type=import) this writes history to; 0 = none

	/** @param bool|string $existing update | keep | flag (true / false: update / keep, as before). */
	public function __construct( string $table, array $identity, string $name_col, bool $dry_run = false, $existing = 'update' ) {
		$this->table    = $table;
		$this->identity = $identity;
		$this->name_col = $name_col;
		$this->dry_run  = $dry_run;
		$this->existing = is_bool( $existing ) ? ( $existing ? 'update' : 'keep' ) : ( in_array( $existing, array( 'update', 'keep', 'flag' ), true ) ? $existing : 'update' );
		$limit                 = (int) ini_get( 'max_execution_time' );
		$start                 = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to float
		// ponytail: a dry run of a huge file can still hit the PHP time limit; batch it like the import if previews of 50k+ rows matter.
		$this->deadline        = $dry_run ? 0.0 : (float) apply_filters( 'certificate_generator_import_deadline', $limit > 0 ? $start + $limit - 10 : 0.0 );
	}

	/** Record history for this run: inserted rows get import_run_id, updated fields and flagged rows go to wp_cg_import_items. */
	public function track( int $run_id ): void {
		$this->run_id = $run_id;
	}

	public function resume_key(): string {
		return $this->resume_key;
	}

	/** Pick up where an earlier upload of this same file (and same $settings, e.g. the column mapping) stopped. */
	public function resume( string $file, string $settings = '' ): void {
		$hash = is_readable( $file ) ? (string) md5_file( $file ) : '';
		if ( '' === $hash ) {
			return;
		}
		$this->resume_key  = 'certificate_generator_import_resume_' . md5( $this->table . '|' . $hash . '|' . $settings );
		$this->resume_from = (int) get_transient( $this->resume_key );
	}

	/** True (and counted) for rows an earlier run of this file already committed; the caller skips them. */
	public function done_earlier( int $line ): bool {
		if ( $line >= $this->resume_from ) {
			return false;
		}
		++$this->counts['read'];
		++$this->counts['resumed'];
		return true;
	}

	/** Buffer a row; false once the import has stopped (time limit) and the caller should stop reading. */
	public function add( int $line, array $row ): bool {
		$this->buffer[] = array( $line, $row );
		if ( count( $this->buffer ) >= self::CHUNK ) {
			$this->flush();
		}
		return 0 === $this->stopped_at;
	}

	public function skip( int $line, string $name, string $reason ): void {
		++$this->counts['read'];
		++$this->counts['skipped'];
		$this->issue( $line, $name, 'skipped: ' . $reason );
	}

	public function warn( int $line, string $name, string $reason ): void {
		$this->issue( $line, $name, 'warning: ' . $reason );
	}

	public function flush(): void {
		if ( ! $this->buffer || $this->stopped_at ) {
			return;
		}
		// Always write the first chunk (progress), then stop when the time limit is near.
		if ( $this->wrote && $this->deadline > 0 && microtime( true ) > $this->deadline ) {
			$this->stopped_at = $this->buffer[0][0];
			$this->buffer     = array();
			return;
		}
		$this->wrote = true;

		global $wpdb;
		$rows         = $this->buffer;
		$this->buffer = array();
		$existing     = $this->preload( $rows );

		$inserts  = array(); // full identity (or "#line") => row, last one wins
		$updates  = array(); // existing id => row, last one wins
		$lines    = array(); // existing id => CSV line of the row applied to it
		$flags    = array(); // [line, existing id, row]
		$outcomes = array(); // [line, name, outcome, note]
		foreach ( $rows as list( $line, $row ) ) {
			$name     = (string) ( $row[ $this->name_col ] ?? '' );
			$keyed    = '' !== str_replace( "\x1F", '', $this->key( $row ) );
			$full     = $this->key( $row ) . '|' . $this->norm( $row['certificate_type'] ?? '' ) . '|' . (string) ( $row['issue_date'] ?? '' );
			$first    = $keyed ? ( $this->seen[ $full ] ?? 0 ) : 0;
			$outcome  = $first ? 'merged' : null;

			if ( $first && isset( $inserts[ $full ] ) ) {
				$inserts[ $full ] = $row; // same record earlier in this chunk: newer values win
			} elseif ( $keyed && ( $id = $this->claim( $existing, $row ) ) ) {
				if ( 'update' === $this->existing ) {
					$updates[ $id ] = $row;
					$lines[ $id ]   = $line;
				} elseif ( 'flag' === $this->existing && ! $first ) {
					$flags[] = array( $line, $id, $row );
				}
				$outcome = $outcome ?? array( 'update' => 'updated', 'keep' => 'kept', 'flag' => 'flagged' )[ $this->existing ];
			} else {
				$inserts[ $keyed ? $full : '#' . $line ] = $row;
				$outcome                                 = $outcome ?? 'new';
			}

			if ( $keyed && ! $first ) {
				$this->seen[ $full ] = $line;
			}
			$outcomes[] = array( $line, $name, $outcome, $first );
		}

		$ok = true;
		if ( ! $this->dry_run ) {
			$this->begin();
			$items = $this->run_id ? $this->history_items( $updates, $lines, $flags ) : array();
			if ( $this->run_id ) {
				$inserts = array_map( fn( $r ) => $r + array( 'import_run_id' => $this->run_id ), $inserts );
			}
			$ok = ! $inserts || $this->insert_many( array_values( $inserts ) );
			foreach ( $updates as $id => $row ) {
				unset( $row['created_at'] ); // keep when the record was first imported
				$ok = $ok && false !== $wpdb->update( $this->table, $row, array( 'id' => $id ) );
			}
			$ok = $ok && ( ! $items || certificate_generator_import_insert_items( $items ) ); // same transaction: history can't disagree with the data
			$this->end( $ok );
		}
		$error = $ok ? '' : ( $wpdb->last_error ?: 'database error' );

		foreach ( $outcomes as list( $line, $name, $outcome, $first ) ) {
			++$this->counts['read'];
			if ( ! $ok ) {
				++$this->counts['failed'];
				$this->issue( $line, $name, 'failed: ' . $error );
			} elseif ( 'kept' === $outcome ) {
				++$this->counts['skipped'];
				$this->issue( $line, $name, 'skipped: matches an existing record, left unchanged' );
			} elseif ( 'flagged' === $outcome ) {
				++$this->counts['flagged'];
				$this->issue( $line, $name, 'flagged: matches an existing record, left unchanged for review (Import history)' );
			} else {
				++$this->counts[ $outcome ];
				if ( 'merged' === $outcome ) {
					$this->issue( $line, $name, sprintf( 'merged into row %d (same %s, certificate type and issue date)', $first, implode( ' + ', $this->identity ) ) );
				}
			}
		}
		if ( ! $ok ) {
			// Rolled back: forget these identities so a retry inserts them again.
			$lines      = array_flip( array_column( $outcomes, 0 ) );
			$this->seen = array_filter( $this->seen, fn( $l ) => ! isset( $lines[ $l ] ) );
		}
		certificate_generator_debug_log( sprintf( 'Import chunk %s: %d rows, %d new, %d updates%s', $this->table, count( $rows ), count( $inserts ), count( $updates ), $ok ? '' : ' — rolled back: ' . $error ) );
	}

	/** @return array{counts: array, issues: array, stopped_at: int} */
	public function report(): array {
		$this->flush();
		if ( '' !== $this->resume_key && ! $this->dry_run ) {
			if ( $this->stopped_at ) {
				set_transient( $this->resume_key, $this->stopped_at, DAY_IN_SECONDS );
			} else {
				delete_transient( $this->resume_key );
			}
		}
		return array(
			'counts'     => $this->counts,
			'issues'     => $this->issues,
			'stopped_at' => $this->stopped_at,
		);
	}

	/**
	 * One transaction per chunk. If the connection already runs with autocommit off (a caller
	 * owns a transaction — the PHPUnit harness does), START TRANSACTION would commit its work,
	 * so use a savepoint inside it instead.
	 */
	private function begin(): void {
		global $wpdb;
		$this->nested = '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' );
		$wpdb->query( $this->nested ? 'SAVEPOINT cg_import' : 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	private function end( bool $ok ): void {
		global $wpdb;
		if ( $this->nested ) {
			$wpdb->query( $ok ? 'RELEASE SAVEPOINT cg_import' : 'ROLLBACK TO SAVEPOINT cg_import' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		} else {
			$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		}
	}

	/**
	 * History rows for one chunk: each update's changed columns {col: [old, new]} (read before
	 * the update), and each flagged row's file values.
	 */
	private function history_items( array $updates, array $lines, array $flags ): array {
		global $wpdb;
		$items = array();
		$now   = current_time( 'mysql', true );
		if ( $updates ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix; ids are ints
			$current = $wpdb->get_results( "SELECT * FROM {$this->table} WHERE id IN (" . implode( ',', array_map( 'intval', array_keys( $updates ) ) ) . ')', OBJECT_K );
			foreach ( $updates as $id => $row ) {
				$old     = (array) ( $current[ $id ] ?? array() );
				$changed = array();
				foreach ( array_diff_key( $row, array_flip( array( 'created_at', 'updated_at', 'import_run_id' ) ) ) as $col => $new ) {
					if ( (string) ( $old[ $col ] ?? '' ) !== (string) $new ) {
						$changed[ $col ] = array( $old[ $col ] ?? null, $new );
					}
				}
				if ( $changed ) {
					$items[] = array( $this->run_id, $lines[ $id ], 'updated', $id, wp_json_encode( $changed ), null, $now );
				}
			}
		}
		foreach ( $flags as list( $line, $id, $row ) ) {
			$items[] = array( $this->run_id, $line, 'flagged', $id, null, wp_json_encode( $row ), $now );
		}
		return $items;
	}

	/** Existing rows sharing the chunk's first identity column, grouped by identity. */
	private function preload( array $rows ): array {
		global $wpdb;
		$col  = $this->identity[0];
		$vals = array_values( array_unique( array_filter( array_map( fn( $r ) => (string) ( $r[1][ $col ] ?? '' ), $rows ), fn( $v ) => '' !== $v ) ) );
		$cols = implode( ', ', array_unique( array_merge( array( 'id', 'certificate_type', 'issue_date' ), $this->identity ) ) );
		$in   = implode( ',', array_fill( 0, max( 1, count( $vals ) ), '%s' ) );
		$found = $vals
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
			? $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM {$this->table} WHERE $col IN ($in) ORDER BY id", ...$vals ), ARRAY_A )
			: array();

		// Rows with no email: candidates are existing rows with no email and the same name.
		$names = array_values( array_unique( array_map( fn( $r ) => (string) ( $r[1][ $this->name_col ] ?? '' ), array_filter( $rows, fn( $r ) => '' === (string) ( $r[1][ $col ] ?? '' ) ) ) ) );
		if ( $names && $this->name_col !== $col ) {
			$in_n  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
			$found = array_merge( $found ?: array(), $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM {$this->table} WHERE ( $col IS NULL OR $col = '' ) AND {$this->name_col} IN ($in_n) ORDER BY id", ...$names ), ARRAY_A ) ?: array() );
		}

		$by_key = array();
		foreach ( $found ?: array() as $e ) {
			$by_key[ $this->key( $e ) ][] = $e;
		}
		return $by_key;
	}

	/**
	 * Id of the existing row this one updates: same type + date first, else a row with no
	 * type yet — which then takes this row's type, so a second type can't claim it too.
	 */
	private function claim( array &$existing, array $row ): int {
		$key  = $this->key( $row );
		$type = $this->norm( $row['certificate_type'] ?? '' );
		$date = (string) ( $row['issue_date'] ?? '' );
		foreach ( $existing[ $key ] ?? array() as $e ) {
			if ( $this->norm( $e['certificate_type'] ?? '' ) === $type && (string) ( $e['issue_date'] ?? '' ) === $date ) {
				return (int) $e['id'];
			}
		}
		foreach ( $existing[ $key ] ?? array() as $i => $e ) {
			if ( '' === (string) ( $e['certificate_type'] ?? '' ) ) {
				$existing[ $key ][ $i ]['certificate_type'] = $row['certificate_type'] ?? '';
				$existing[ $key ][ $i ]['issue_date']       = $row['issue_date'] ?? null;
				return (int) $e['id'];
			}
		}
		return 0;
	}

	/** One INSERT for many rows; columns are the union of the rows' keys, missing ones NULL. */
	private function insert_many( array $rows ): bool {
		global $wpdb;
		$cols = array_keys( array_merge( ...array_map( fn( $r ) => array_fill_keys( array_keys( $r ), true ), $rows ) ) );
		$vals = array();
		foreach ( $rows as $row ) {
			$vals[] = '(' . implode( ',', array_map( fn( $c ) => isset( $row[ $c ] ) ? $wpdb->prepare( '%s', $row[ $c ] ) : 'NULL', $cols ) ) . ')';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- values prepared above; columns come from the importer map
		return false !== $wpdb->query( "INSERT INTO {$this->table} (`" . implode( '`,`', $cols ) . '`) VALUES ' . implode( ',', $vals ) );
	}

	private function key( array $row ): string {
		return implode( "\x1F", array_map( fn( $c ) => $this->norm( $row[ $c ] ?? '' ), $this->identity ) );
	}

	// ponytail: lower-case + rtrim mirrors the old _ci comparison for ASCII/case; accent-folding (é = e) is not mirrored.
	private function norm( $v ): string {
		return mb_strtolower( rtrim( (string) $v ) );
	}

	private function issue( int $line, string $name, string $reason ): void {
		$this->issues[] = array( $line, $name, $reason );
	}
}

/** Warnings that don't stop a row: an email that doesn't sanitize, a date that couldn't be parsed. */
function certificate_generator_import_warn_row( CertificateGenerator_Import_Writer $writer, int $line, string $name, string $raw_email, string $email, string $raw_date, ?string $stored_date ): void {
	if ( '' !== $raw_email && '' === $email ) {
		$writer->warn( $line, $name, sprintf( 'email "%s" is not a valid address — saved without an email', $raw_email ) );
	}
	if ( '' !== $raw_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $stored_date ) ) {
		$writer->warn( $line, $name, sprintf( 'issue_date "%s" was not recognised — saved as is', $raw_date ) );
	}
}

/**
 * The import summary: what happened to every row, the first 100 issues, and all of them as
 * a downloadable CSV (built in the page — nothing is stored on the server).
 */
function certificate_generator_import_render_report( string $entity, array $report, string $extra_note = '', string $stopped_note = '' ): void {
	$c     = $report['counts'];
	$clean = 0 === $c['merged'] + $c['skipped'] + $c['failed'] && ! $report['stopped_at'];
	$saved = $c['new'] + $c['updated'];

	$summary = sprintf(
		/* translators: 1: rows read, 2: rows saved, 3: entity label, 4: new, 5: updated */
		__( '%1$d rows read → %2$d %3$s saved (%4$d new, %5$d updated).', 'certificate-generator' ),
		$c['read'],
		$saved,
		$entity,
		$c['new'],
		$c['updated']
	);
	$parts = array();
	if ( ! empty( $c['resumed'] ) ) {
		/* translators: %d: rows skipped because an earlier upload of the same file imported them */
		$parts[] = sprintf( __( '%d already imported by the earlier upload of this file', 'certificate-generator' ), $c['resumed'] );
	}
	if ( $c['merged'] ) {
		/* translators: %d: rows merged */
		$parts[] = sprintf( __( '%d merged into an earlier row of this file with the same identity', 'certificate-generator' ), $c['merged'] );
	}
	if ( $c['skipped'] ) {
		/* translators: %d: rows skipped */
		$parts[] = sprintf( __( '%d skipped', 'certificate-generator' ), $c['skipped'] );
	}
	if ( ! empty( $c['flagged'] ) ) {
		/* translators: %d: rows flagged for review */
		$parts[] = sprintf( __( '%d flagged for review in Import history', 'certificate-generator' ), $c['flagged'] );
	}
	if ( $c['failed'] ) {
		/* translators: %d: rows failed */
		$parts[] = sprintf( __( '%d failed', 'certificate-generator' ), $c['failed'] );
	}

	echo '<div class="notice ' . ( $clean ? 'notice-success' : 'notice-warning' ) . ' cg-import-report"><p><strong>' . esc_html( $summary ) . '</strong>';
	if ( $parts ) {
		echo ' ' . esc_html( implode( ' · ', $parts ) ) . '.';
	}
	echo esc_html( $extra_note ) . '</p>';

	if ( $report['stopped_at'] ) {
		echo '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: CSV row number, 2: what happens next */
				__( 'Stopped at row %1$d to stay within the server time limit. %2$s', 'certificate-generator' ),
				$report['stopped_at'],
				'' !== $stopped_note ? $stopped_note : __( 'Upload the same file again to continue from this row.', 'certificate-generator' )
			)
		) . '</strong></p>';
	}
	certificate_generator_import_render_issues( $report['issues'] );
	echo '</div>';
}

/** The first $limit row issues as a table, and all of them as a downloadable CSV (built in the page — nothing stored). */
function certificate_generator_import_render_issues( array $issues, int $limit = 100 ): void {
	if ( ! $issues ) {
		return;
	}
	$csv = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fwrite( $csv, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel reads names as UTF-8
	certificate_generator_fputcsv( $csv, array( 'row', 'name', 'issue' ) );
	foreach ( $issues as $issue ) {
		certificate_generator_fputcsv( $csv, $issue );
	}
	rewind( $csv );
	$data = base64_encode( (string) stream_get_contents( $csv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	fclose( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	echo '<p><a class="button" download="import-issues.csv" href="data:text/csv;base64,' . esc_attr( $data ) . '">'
		. esc_html(
			sprintf(
				/* translators: %d: number of issues */
				__( 'Download all %d issues (CSV)', 'certificate-generator' ),
				count( $issues )
			)
		) . '</a></p>';

	echo '<div class="cg-table-wrap cg-import-issues"><table class="widefat striped cg-table"><thead><tr><th class="num">'
		. esc_html__( 'Row', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Name', 'certificate-generator' ) . '</th><th>'
		. esc_html__( 'What happened', 'certificate-generator' ) . '</th></tr></thead><tbody>';
	foreach ( array_slice( $issues, 0, $limit ) as $issue ) {
		echo '<tr><td class="num">' . (int) $issue[0] . '</td><td>' . esc_html( $issue[1] ) . '</td><td>' . esc_html( $issue[2] ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ( count( $issues ) > $limit ) {
		/* translators: %d: rows shown */
		echo '<p class="description">' . esc_html( sprintf( __( 'Showing the first %d — download the CSV for the full list.', 'certificate-generator' ), $limit ) ) . '</p>';
	}
}

/**
 * What differs between the three record imports; everything else runs through one pipeline
 * (certificate_generator_import_run). `aliases` are other header names people use for a
 * column, matched after certificate_generator_import_norm_header().
 */
function certificate_generator_import_entities(): array {
	$common = array(
		'issue_date'       => array( 'date', 'issued_on', 'issue', 'date_of_issue', 'certificate_date' ),
		'certificate_type' => array( 'type', 'certificate', 'cert_type', 'award', 'category' ),
	);
	$person = fn( string $name_col, array $name_aliases ) => array(
		'identity' => array( 'email', $name_col, 'school_name' ),
		'name'     => $name_col,
		'required' => array( $name_col, 'email', 'school_name', 'issue_date', 'certificate_type' ),
		'optional' => array( 'phone', 'external_id', 'event_code', 'send_email', 'status', 'year' ),
		'aliases'  => $common + array(
			$name_col     => $name_aliases,
			'email'       => array( 'email_address', 'e_mail', 'mail', 'email_id' ),
			'school_name' => array( 'school', 'institution', 'institute', 'college', 'organisation', 'organization' ),
			'phone'       => array( 'phone_number', 'mobile', 'mobile_number', 'contact', 'contact_number' ),
			'external_id' => array( 'external_ref', 'reference', 'ref_id', 'lms_id', 'crm_id' ), // an LMS / CRM / sheet id, used by the API to find the record
		),
		'school'   => true,
	);

	$students                         = $person( 'student_name', array( 'name', 'student', 'full_name', 'participant', 'participant_name', 'recipient' ) );
	$students['optional'][]           = 'photo_url';
	$students['aliases']['photo_url'] = array( 'photo', 'image', 'picture' );
	$students['register_fields']      = true; // extra columns become template fields for the row's certificate type

	return array(
		'students' => $students + array(
			'table' => 'students',
			'label' => __( 'students', 'certificate-generator' ),
		),
		'teachers' => $person( 'teacher_name', array( 'name', 'teacher', 'full_name', 'faculty', 'staff_name' ) ) + array(
			'table' => 'teachers',
			'label' => __( 'teachers', 'certificate-generator' ),
		),
		'schools'  => array(
			'table'    => 'schools',
			'label'    => __( 'schools', 'certificate-generator' ),
			// Same record = school name + certificate type + issue date; a type-less school row
			// the students/teachers import auto-created is claimed instead of duplicated.
			'identity' => array( 'school_name' ),
			'name'     => 'school_name',
			'required' => array( 'school_name', 'place', 'issue_date', 'certificate_type' ),
			'optional' => array( 'event_code', 'send_email', 'status', 'year' ),
			'aliases'  => $common + array(
				'school_name' => array( 'school', 'institution', 'institute', 'college', 'name' ),
				'place'       => array( 'city', 'location', 'town' ),
			),
			'school'   => false,
		),
	);
}

/** "Student Name" / "student-name " → "student_name" (hyphens kept: existing extra-field slugs use them). */
function certificate_generator_import_norm_header( string $header ): string {
	return trim( (string) preg_replace( '/[^a-z0-9\-]+/', '_', strtolower( trim( $header ) ) ), '_' );
}

/**
 * CSV column index => what it imports as: a field key, an extra-field slug, or '' (not imported).
 * Exact names win over aliases; a column the user mapped by hand ($override: '' = automatic,
 * '__ignore', '__extra' or a field key) wins over both, and frees that field from any other column.
 */
function certificate_generator_import_map( array $cfg, array $header, array $override = array() ): array {
	$known  = array_merge( $cfg['required'], $cfg['optional'] );
	$norm   = array_map( fn( $h ) => certificate_generator_import_norm_header( (string) $h ), $header );
	$map    = array_fill_keys( array_keys( $header ), null );
	$taken  = array();
	$assign = function ( int $i, string $target ) use ( &$map, &$taken ) {
		if ( '' !== $target && isset( $taken[ $target ] ) ) {
			$map[ $taken[ $target ] ] = '';
		}
		$map[ $i ] = $target;
		if ( '' !== $target ) {
			$taken[ $target ] = $i;
		}
	};

	foreach ( $norm as $i => $n ) {
		if ( in_array( $n, $known, true ) && ! isset( $taken[ $n ] ) ) {
			$assign( $i, $n );
		}
	}
	foreach ( $norm as $i => $n ) {
		if ( null !== $map[ $i ] ) {
			continue;
		}
		foreach ( $cfg['aliases'] as $field => $aliases ) {
			if ( ! isset( $taken[ $field ] ) && in_array( $n, $aliases, true ) ) {
				$assign( $i, $field );
				continue 2;
			}
		}
		// Unknown header: an extra field under its own name, as before. A repeated header is dropped.
		$map[ $i ] = ( '' === $n || isset( $taken[ $n ] ) || in_array( $n, $known, true ) ) ? '' : $n;
		if ( '' !== $map[ $i ] ) {
			$taken[ $n ] = $i;
		}
	}
	foreach ( $override as $i => $choice ) {
		$i = (int) $i;
		if ( ! array_key_exists( $i, $map ) || '' === $choice ) {
			continue;
		}
		if ( '__ignore' === $choice ) {
			$map[ $i ] = '';
		} elseif ( '__extra' === $choice ) {
			$assign( $i, in_array( $norm[ $i ], $known, true ) ? '' : $norm[ $i ] );
		} elseif ( in_array( $choice, $known, true ) ) {
			$assign( $i, $choice );
		}
	}
	return array_map( 'strval', $map );
}

/**
 * Open a CSV upload as a UTF-8 stream: BOM stripped; UTF-16 and Windows-1252 (Excel's plain
 * "CSV" on Windows) converted; delimiter (comma, semicolon or tab) detected from the header line.
 * Text that still doesn't decode stops the import — it never saves garbled names.
 *
 * @return array{handle: resource, delimiter: string, encoding: string}|string Error message.
 */
function certificate_generator_import_open( string $file ) {
	$raw = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload
	if ( '' === trim( $raw ) ) {
		return __( 'CSV file is empty or invalid.', 'certificate-generator' );
	}
	$encoding = 'UTF-8';
	if ( str_starts_with( $raw, "\xFF\xFE" ) || str_starts_with( $raw, "\xFE\xFF" ) ) {
		$encoding = 'UTF-16';
		$raw      = (string) mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', str_starts_with( $raw, "\xFF\xFE" ) ? 'UTF-16LE' : 'UTF-16BE' );
	} elseif ( str_starts_with( $raw, "﻿" ) ) {
		$raw = substr( $raw, 3 );
	} elseif ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		$encoding = 'Windows-1252';
		$raw      = (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
	}
	if ( preg_match( '/\x{FFFD}|[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $raw ) ) {
		return __( "Couldn't read this file's text encoding. In Excel, use Save As → \"CSV UTF-8 (Comma delimited)\" and upload that file.", 'certificate-generator' );
	}

	preg_match( '/^.*\S.*$/m', $raw, $first );
	$line   = (string) preg_replace( '/"[^"]*"/', '', $first[0] ?? '' );
	$counts = array(
		','  => substr_count( $line, ',' ),
		';'  => substr_count( $line, ';' ),
		"\t" => substr_count( $line, "\t" ),
	);
	$delimiter = max( $counts ) > 0 ? (string) array_search( max( $counts ), $counts, true ) : ',';

	$handle = fopen( 'php://temp', 'r+' );
	fwrite( $handle, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	rewind( $handle );
	return array(
		'handle'    => $handle,
		'delimiter' => $delimiter,
		'encoding'  => $encoding,
	);
}

/** @return array{0: array, 1: int} The first non-empty row (trimmed) and its CSV row number. */
function certificate_generator_import_read_header( $handle, string $delimiter ): array {
	$line = 0;
	while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
		++$line;
		if ( array_filter( $row, fn( $v ) => null !== $v && '' !== $v ) ) {
			return array( array_map( fn( $v ) => trim( (string) $v ), $row ), $line );
		}
	}
	return array( array(), $line );
}

/**
 * Day/month order for dates like 03/04/2026, read from the file's values: 'dmy' or 'mdy' when
 * some value proves it (a part above 12), '' when the values fit both, 'auto' when no value has
 * that shape (ISO dates, "March 3, 2026", …).
 */
function certificate_generator_import_guess_date_order( array $values ): string {
	$dmy       = false;
	$mdy       = false;
	$ambiguous = false;
	foreach ( $values as $v ) {
		if ( ! preg_match( '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-]\d{4}$/', trim( (string) $v ), $m ) ) {
			continue;
		}
		if ( (int) $m[1] > 12 ) {
			$dmy = true;
		} elseif ( (int) $m[2] > 12 ) {
			$mdy = true;
		} else {
			$ambiguous = true;
		}
	}
	if ( $dmy !== $mdy ) {
		return $dmy ? 'dmy' : 'mdy';
	}
	return ( $ambiguous || $dmy ) ? '' : 'auto';
}

/**
 * A CSV date as Y-m-d. $order ('dmy'|'mdy') decides values like 03/04/2026; 'auto' and every
 * other shape use DateHelper::to_storage() as before. Null when it can't be read.
 */
function certificate_generator_import_date( string $raw, string $order = 'auto' ): ?string {
	if ( '' === $raw ) {
		return null;
	}
	$formats = array(
		'dmy' => '!j/n/Y',
		'mdy' => '!n/j/Y',
	);
	if ( isset( $formats[ $order ] ) && preg_match( '/^\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4}$/', $raw ) ) {
		$dt     = DateTime::createFromFormat( $formats[ $order ], str_replace( array( '-', '.' ), '/', $raw ) );
		$errors = DateTime::getLastErrors();
		return ( $dt && ( ! $errors || 0 === $errors['warning_count'] + $errors['error_count'] ) ) ? $dt->format( 'Y-m-d' ) : null;
	}
	return class_exists( '\CertificateGenerator\Helpers\DateHelper' ) ? \CertificateGenerator\Helpers\DateHelper::to_storage( $raw ) : null;
}

/** Id of the school a student/teacher row names, created on a real import (cached per run). */
function certificate_generator_import_school_id( string $school, bool $dry_run, array &$ctx ): ?int {
	if ( array_key_exists( $school, $ctx['schools'] ) ) {
		return $ctx['schools'][ $school ];
	}
	global $wpdb;
	$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'schools' );
	$id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE school_name = %s LIMIT 1", $school ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $id && ! $dry_run ) {
		$wpdb->insert(
			$table,
			array(
				'school_name' => $school,
				'status'      => 'active',
				'created_at'  => current_time( 'mysql' ),
			)
		);
		$id = (int) $wpdb->insert_id;
	}
	$ctx['schools'][ $school ] = $id ?: null;
	return $ctx['schools'][ $school ];
}

/** One CSV row as the table row to write, or null after telling the writer why it was skipped. */
function certificate_generator_import_build_row( string $entity, array $cfg, array $row, array $extras, array $args, array &$ctx, CertificateGenerator_Import_Writer $writer, int $line ): ?array {
	$name_col = $cfg['name'];
	$name     = sanitize_text_field( $row[ $name_col ] ?? '' );
	$type     = sanitize_text_field( $row['certificate_type'] ?? '' );
	if ( '' === $name || '' === $type ) {
		$writer->skip( $line, $name, ( '' === $name ? $name_col : 'certificate_type' ) . ' is empty' );
		return null;
	}
	$dry_run = ! empty( $args['dry_run'] );

	$extra = array();
	foreach ( $extras as $slug ) {
		if ( '' !== ( $row[ $slug ] ?? '' ) ) {
			$extra[ $slug ] = sanitize_text_field( $row[ $slug ] );
		}
		if ( ! $dry_run && ! empty( $cfg['register_fields'] ) && class_exists( 'CertificateGenerator_Field_Schema' ) ) {
			$type_key = CertificateGenerator_Field_Schema::cert_type_to_key( $type );
			if ( ! isset( $ctx['registered'][ $type_key ][ $slug ] ) ) {
				$ctx['registered'][ $type_key ][ $slug ] = CertificateGenerator_Field_Schema::register_field( $type, $slug ) ? 'registered' : 'data_only';
			}
		}
	}

	$raw_date = sanitize_text_field( $row['issue_date'] ?? '' );
	$date     = certificate_generator_import_date( $raw_date, $args['date_order'] ?? 'auto' ) ?? $raw_date;
	$send     = strtolower( trim( $row['send_email'] ?? '1' ) ); // false/0/no opts out; anything else (or no column) sends
	$data     = array(
		$name_col          => $name,
		'event_id'         => certificate_generator_resolve_event_id( $row['event_code'] ?? '' ),
		'certificate_type' => $type,
		'issue_date'       => $date ?: null,
		'year'             => function_exists( 'certificate_generator_year_from_issue_date' ) ? certificate_generator_year_from_issue_date( $date ) : null,
		'status'           => 'active',
		'send_email'       => in_array( $send, array( 'false', '0', 'no' ), true ) ? 0 : 1,
		'import_source'    => $args['source'] ?? '',
		'created_at'       => current_time( 'mysql' ),
		'updated_at'       => current_time( 'mysql' ),
	);
	if ( $cfg['school'] ) {
		$school              = $args['school_lock'] ?? sanitize_text_field( $row['school_name'] ?? '' );
		$data['email']       = sanitize_email( $row['email'] ?? '' );
		$data['phone']       = sanitize_text_field( $row['phone'] ?? '' );
		if ( '' !== trim( (string) ( $row['external_id'] ?? '' ) ) ) { // only when given, so an update without the column keeps it
			$data['external_id'] = mb_substr( sanitize_text_field( $row['external_id'] ), 0, 191 );
		}
		$data['school_id']   = certificate_generator_import_school_id( $school, $dry_run, $ctx );
		$data['school_name'] = $school;
		certificate_generator_import_warn_row( $writer, $line, $name, $row['email'] ?? '', $data['email'], $raw_date, $date );
	} else {
		$data['city'] = sanitize_text_field( $row['place'] ?? '' );
		certificate_generator_import_warn_row( $writer, $line, $name, '', '', $raw_date, $date );
	}
	if ( 'students' === $entity ) {
		$data['photo_url'] = ! empty( $row['photo_url'] ) ? esc_url_raw( $row['photo_url'] ) : null;
	}
	if ( $extra ) {
		$data['extra_fields'] = wp_json_encode( $extra );
	}
	return $data;
}

/**
 * Import one CSV file of students, teachers or schools — or, with dry_run, run the same code
 * without writing, so the preview's counts are the import's counts.
 *
 * @param array $args map (column => choice, see certificate_generator_import_map), date_order
 *                    ('auto'|'dmy'|'mdy'), dry_run, existing (update|keep|flag; the older update_existing bool still works), source.
 * @return array|string Error message, or: map, header, missing, extras, delimiter, encoding,
 *                      report (null when required columns are missing), preview (first 5 rows),
 *                      dates (every issue_date value), registered.
 */
function certificate_generator_import_run( string $entity, string $file, array $args = array() ) {
	$cfg = certificate_generator_import_entities()[ $entity ];

	// A School Coordinator's rows all go to their school: the school column isn't needed and is ignored.
	$school_lock = function_exists( 'certificate_generator_user_school' ) ? certificate_generator_user_school() : null;
	if ( null !== $school_lock ) {
		if ( '' === $school_lock ) {
			return __( 'No school is assigned to your account yet. Ask an administrator to set one on your user profile.', 'certificate-generator' );
		}
		if ( empty( $cfg['school'] ) ) {
			return __( 'Only administrators can import schools.', 'certificate-generator' );
		}
		$cfg['required']     = array_values( array_diff( $cfg['required'], array( 'school_name' ) ) );
		$args['school_lock'] = $school_lock;
	}

	$opened = certificate_generator_import_open( $file );
	if ( is_string( $opened ) ) {
		return $opened;
	}
	$handle = $opened['handle'];
	list( $header, $line ) = certificate_generator_import_read_header( $handle, $opened['delimiter'] );
	if ( ! $header ) {
		fclose( $handle );
		return __( 'CSV file is empty or invalid.', 'certificate-generator' );
	}
	$map    = certificate_generator_import_map( $cfg, $header, $args['map'] ?? array() );
	$known  = array_merge( $cfg['required'], $cfg['optional'] );
	$result = array(
		'map'       => $map,
		'header'    => $header,
		'missing'   => array_values( array_diff( $cfg['required'], $map ) ),
		'extras'    => array_values( array_filter( array_diff( $map, $known ) ) ),
		'delimiter' => $opened['delimiter'],
		'encoding'  => $opened['encoding'],
		'report'    => null,
		'preview'   => array(),
		'dates'     => array(),
		'long'      => array(), // dry run: [line, name] of names too wide for their template field
	);
	if ( $result['missing'] ) {
		// Nothing can be imported yet; still read the dates so the check step can ask for their order.
		while ( in_array( 'issue_date', $map, true ) && ( $data = fgetcsv( $handle, 0, $opened['delimiter'] ) ) !== false ) {
			$row = certificate_generator_import_read_row( $data, $map );
			if ( is_array( $row ) ) {
				$result['dates'][] = $row['issue_date'];
			}
		}
		fclose( $handle );
		return $result;
	}
	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		fclose( $handle );
		return __( 'The plugin tables are unavailable — nothing was imported.', 'certificate-generator' );
	}

	$dry_run  = ! empty( $args['dry_run'] );
	$existing = certificate_generator_import_existing_mode( $args );
	$writer   = new CertificateGenerator_Import_Writer(
		\CertificateGenerator\Database\CustomTables::instance()->get_table( $cfg['table'] ),
		$cfg['identity'],
		$cfg['name'],
		$dry_run,
		$existing
	);
	$run_id = 0;
	if ( ! $dry_run ) {
		$writer->resume( $file, (string) wp_json_encode( array( $map, $args['date_order'] ?? 'auto', $existing ) ) );
		$run_id = certificate_generator_import_run_start( $entity, (string) ( $args['source'] ?? '' ), $existing, $writer->resume_key() );
		$writer->track( $run_id );
	}
	$ctx = array(
		'schools'    => array(),
		'registered' => array(),
	);
	while ( ( $data = fgetcsv( $handle, 0, $opened['delimiter'] ) ) !== false ) {
		++$line;
		if ( $writer->done_earlier( $line ) || ! array_filter( $data, fn( $v ) => null !== $v && '' !== $v ) ) {
			continue;
		}
		$row = certificate_generator_import_read_row( $data, $map );
		if ( is_string( $row ) ) {
			$writer->skip( $line, (string) ( $data[0] ?? '' ), $row );
			continue;
		}
		unset( $row[''] );
		$result['dates'][] = $row['issue_date'] ?? '';
		$insert            = certificate_generator_import_build_row( $entity, $cfg, $row, $result['extras'], $args, $ctx, $writer, $line );
		if ( null === $insert ) {
			continue;
		}
		if ( count( $result['preview'] ) < 5 ) {
			$result['preview'][] = $insert;
		}
		if ( $dry_run && class_exists( '\CertificateGenerator\Services\PreSendCheck' )
			&& \CertificateGenerator\Services\PreSendCheck::name_too_long( $insert[ $cfg['name'] ], $insert['certificate_type'], (string) $insert['issue_date'], $entity ) ) {
			$result['long'][] = array( $line, $insert[ $cfg['name'] ] );
		}
		if ( ! $writer->add( $line, $insert ) ) {
			break;
		}
	}
	fclose( $handle );
	$result['report']     = $writer->report();
	$result['registered'] = $ctx['registered'];
	$result['run_id']     = $run_id;
	if ( $run_id ) {
		certificate_generator_import_run_progress( $run_id, $result['report']['counts'], ! $result['report']['stopped_at'], $writer->resume_key() );
	}
	if ( ! $dry_run && function_exists( 'certificate_generator_flush_filter_caches' ) ) {
		certificate_generator_flush_filter_caches();
	}
	if ( ! $dry_run && empty( $result['report']['stopped_at'] ) ) {
		/**
		 * Fires when a CSV import reaches the end of its file (a timed-out run fires on the
		 * run that finishes it; counts cover that last run, 'resumed' the rows done before).
		 *
		 * @param array $summary entity, source, counts (new, updated, skipped, failed, resumed, …).
		 */
		$c       = $result['report']['counts'];
		$summary = array(
			'entity' => $entity,
			'source' => (string) ( $args['source'] ?? '' ),
			'counts' => $c,
		);
		// Nothing saved and rows failed: the import as a whole failed.
		if ( $c['failed'] > 0 && 0 === $c['new'] + $c['updated'] + $c['resumed'] ) {
			/** Fires when every row of an import failed (same $summary as import_completed). */
			do_action( 'certificate_generator_import_failed', $summary );
		} else {
			do_action( 'certificate_generator_import_completed', $summary );
		}
	}
	return $result;
}

/** " Extra fields registered for template: …" for the students report. */
function certificate_generator_import_extra_note( array $registered ): string {
	$by_status = array(
		'registered' => array(),
		'data_only'  => array(),
	);
	foreach ( $registered as $type_fields ) {
		foreach ( $type_fields as $slug => $status ) {
			$by_status[ $status ][ $slug ] = $slug;
		}
	}
	$note = '';
	if ( $by_status['registered'] ) {
		$note .= ' Extra fields registered for template: ' . implode( ', ', $by_status['registered'] ) . '.';
	}
	if ( $by_status['data_only'] ) {
		$note .= ' Additional fields saved (data only, beyond template limit): ' . implode( ', ', $by_status['data_only'] ) . '.';
	}
	return $note;
}

/**
 * Sample CSV for a record type as a data: URL — built from the same field lists the importer
 * checks, plus the extra fields templates already use, so it can't drift from the importer.
 */
function certificate_generator_import_sample_csv_url( string $entity ): string {
	$cfg     = certificate_generator_import_entities()[ $entity ];
	$example = array(
		'student_name'     => 'Asha Verma',
		'teacher_name'     => 'Ravi Kumar',
		'email'            => 'asha@example.com',
		'school_name'      => 'Green Valley School',
		'place'            => 'Pune',
		'issue_date'       => '25-08-2026',
		'certificate_type' => 'Participation',
		'phone'            => '98765 43210',
		'external_id'      => '',
		'event_code'       => '',
		'send_email'       => 'yes',
		'photo_url'        => '',
	);
	$columns = array_values( array_diff( array_merge( $cfg['required'], $cfg['optional'] ), array( 'status', 'year' ) ) );
	if ( ! empty( $cfg['register_fields'] ) && class_exists( 'CertificateGenerator_Field_Schema' ) ) {
		foreach ( CertificateGenerator_Field_Schema::get_all_registered_types() as $type ) {
			$columns = array_merge( $columns, array_diff( (array) $type['fields'], $columns ) );
		}
	}
	$csv = fopen( 'php://temp', 'r+' );
	fwrite( $csv, "﻿" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM so Excel opens it as UTF-8
	certificate_generator_fputcsv( $csv, $columns );
	certificate_generator_fputcsv( $csv, array_map( fn( $c ) => $example[ $c ] ?? '', $columns ) );
	rewind( $csv );
	$data = (string) stream_get_contents( $csv );
	fclose( $csv );
	return 'data:text/csv;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
}

/** Private path of an uploaded file kept between the preview and the import; '' for an id that isn't this user's. */
function certificate_generator_import_job_path( string $job ): string {
	if ( ! preg_match( '/^import-' . get_current_user_id() . '-[a-f0-9]{32}$/', $job ) ) {
		return '';
	}
	return certificate_generator_private_zip_dir() . '/' . $job . '.csv';
}

/** Keep the upload for the preview → import steps. The daily cron deletes ones left after 2 hours. */
function certificate_generator_import_store_upload( string $tmp ): string {
	$job  = 'import-' . get_current_user_id() . '-' . bin2hex( random_bytes( 16 ) );
	$path = certificate_generator_import_job_path( $job );
	// $tmp is PHP's own upload temp path ($_FILES tmp_name), never request data.
	$ok = copy( $tmp, $path );
	return $ok ? $job : '';
}

/** The import page for students, teachers or schools: upload → check (mapping + dry run) → import. */
function certificate_generator_import_page( string $entity ): void {
	$cfg   = certificate_generator_import_entities()[ $entity ];
	$nonce = "bulk_import_{$entity}_nonce";
	$field = "{$entity}_csv";

	certificate_generator_ui_card_open( sprintf( 'Upload %s CSV', $entity ), array( 'icon' => 'upload' ) );
	echo '<form method="post" enctype="multipart/form-data" data-cg-busy>';
	wp_nonce_field( $nonce, '_wpnonce_bulk_import' );
	echo '<table class="form-table"><tr><th><label for="' . esc_attr( $field ) . '">' . esc_html__( 'Upload CSV File', 'certificate-generator' ) . '</label></th>';
	echo '<td><input type="file" name="' . esc_attr( $field ) . '" id="' . esc_attr( $field ) . '" accept=".csv,.txt" required />';
	echo ' <a class="button-link" download="' . esc_attr( "sample-{$entity}.csv" ) . '" href="' . esc_attr( certificate_generator_import_sample_csv_url( $entity ) ) . '">' . esc_html__( 'Download a sample CSV', 'certificate-generator' ) . '</a></td></tr></table>';
	echo '<p class="cg-hint">' . esc_html__( 'Comma, semicolon or tab separated; UTF-8 or Excel\'s Windows encoding. You\'ll see how the columns map and what will change before anything is saved.', 'certificate-generator' ) . '</p>';
	$school_lock = function_exists( 'certificate_generator_user_school' ) ? certificate_generator_user_school() : null;
	if ( null !== $school_lock && '' !== $school_lock ) {
		/* translators: %s: school name */
		echo '<p class="cg-hint"><strong>' . esc_html( sprintf( __( 'Every row is saved to %s.', 'certificate-generator' ), $school_lock ) ) . '</strong></p>';
	}
	echo '<p class="submit"><input type="submit" name="' . esc_attr( "preview_{$entity}" ) . '" value="' . esc_attr__( 'Upload and check', 'certificate-generator' ) . '" class="button button-primary" /></p>';
	echo '</form>';
	certificate_generator_ui_card_close();

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by check_admin_referer() before any use below
	$direct  = isset( $_POST[ "submit_{$entity}" ] ); // one-step upload + import (scripts, bin/bench-bulk-import.php)
	$preview = isset( $_POST[ "preview_{$entity}" ] );
	$import  = isset( $_POST[ "import_{$entity}" ] );
	if ( ! $direct && ! $preview && ! $import ) {
		return;
	}
	check_admin_referer( $nonce, '_wpnonce_bulk_import' );
	if ( ! current_user_can( 'certificate_generator_issue' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
	}

	$upload = $_FILES[ $field ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- tmp_name is set by PHP; the name is sanitized below
	$job    = sanitize_text_field( wp_unslash( $_POST['cg_import_job'] ?? '' ) );
	$source = sanitize_file_name( wp_unslash( $_POST['cg_import_source'] ?? '' ) );
	if ( $upload && UPLOAD_ERR_NO_FILE !== (int) $upload['error'] ) {
		if ( UPLOAD_ERR_OK !== (int) $upload['error'] ) {
			certificate_generator_ui_notice( 'error', 'File upload error. Please try again.', false );
			return;
		}
		$source = sanitize_file_name( $upload['name'] ?? '' );
		$job    = '';
	}
	$args = array(
		'map'             => array_map( 'sanitize_key', (array) wp_unslash( $_POST['cg_map'] ?? array() ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized per value
		'date_order'      => sanitize_key( wp_unslash( $_POST['cg_date_order'] ?? '' ) ),
		'existing'        => sanitize_key( wp_unslash( $_POST['cg_existing'] ?? 'update' ) ),
		'source'          => $source,
	);
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( $direct ) {
		$args['date_order'] = 'auto';
		$file               = (string) ( $upload['tmp_name'] ?? '' );
	} else {
		if ( '' === $job && $upload ) {
			$job = certificate_generator_import_store_upload( (string) $upload['tmp_name'] );
		}
		$file = certificate_generator_import_job_path( $job );
		if ( '' === $file || ! is_readable( $file ) ) {
			certificate_generator_ui_notice( 'error', __( 'That upload has expired — please choose the file again.', 'certificate-generator' ), false );
			return;
		}
	}

	if ( $preview ) {
		certificate_generator_import_render_check( $entity, $cfg, $job, $args, certificate_generator_import_run( $entity, $file, array( 'dry_run' => true ) + $args ) );
		return;
	}

	$result = certificate_generator_import_run( $entity, $file, $args );
	if ( is_string( $result ) || $result['missing'] ) {
		$message = is_string( $result ) ? $result : '<strong>Invalid CSV format.</strong><br><br><strong>Missing required fields:</strong> ' . esc_html( implode( ', ', $result['missing'] ) ) . '<br><br><strong>Required fields:</strong> ' . implode( ', ', $cfg['required'] );
		certificate_generator_ui_notice( 'error', is_string( $result ) ? esc_html( $message ) : $message, false );
		return;
	}
	if ( $result['extras'] ) {
		certificate_generator_ui_notice( 'info', '<strong>Extra fields detected:</strong> ' . esc_html( implode( ', ', $result['extras'] ) ) . ' — ' . ( empty( $cfg['register_fields'] ) ? 'these will be saved to extra_fields for each record.' : 'these will be auto-registered for each row\'s certificate type.' ) );
	}
	$stopped = (int) $result['report']['stopped_at'];
	certificate_generator_import_render_report( $cfg['label'], $result['report'], certificate_generator_import_extra_note( $result['registered'] ?? array() ), $import && $stopped ? __( 'Continuing automatically…', 'certificate-generator' ) : '' );

	if ( $import && $stopped ) {
		// ponytail: one page load per pass (the writer stops before the time limit); an AJAX progress bar can replace this auto-submit if passes feel slow.
		echo '<form method="post" id="cg-import-continue" data-cg-busy>';
		wp_nonce_field( $nonce, '_wpnonce_bulk_import' );
		certificate_generator_import_hidden_args( $job, $args );
		echo '<input type="hidden" name="' . esc_attr( "import_{$entity}" ) . '" value="1" />';
		echo '<p><button class="button">' . esc_html__( 'Continue now', 'certificate-generator' ) . '</button></p></form>';
		echo '<script>setTimeout(function(){document.getElementById("cg-import-continue").submit();},800);</script>';
	} elseif ( $import ) {
		wp_delete_file( $file );
	}
}

/** Hidden fields that carry the upload and the user's choices from one step to the next. */
function certificate_generator_import_hidden_args( string $job, array $args ): void {
	echo '<input type="hidden" name="cg_import_job" value="' . esc_attr( $job ) . '" />';
	echo '<input type="hidden" name="cg_import_source" value="' . esc_attr( $args['source'] ) . '" />';
	echo '<input type="hidden" name="cg_date_order" value="' . esc_attr( $args['date_order'] ) . '" />';
	echo '<input type="hidden" name="cg_existing" value="' . esc_attr( certificate_generator_import_existing_mode( $args ) ) . '" />';
	foreach ( $args['map'] as $i => $choice ) {
		echo '<input type="hidden" name="cg_map[' . (int) $i . ']" value="' . esc_attr( $choice ) . '" />';
	}
}

/** The check step: how each column maps, the date order, duplicates, and the dry-run counts. */
function certificate_generator_import_render_check( string $entity, array $cfg, string $job, array $args, $result ): void {
	if ( is_string( $result ) ) {
		certificate_generator_ui_notice( 'error', esc_html( $result ), false );
		return;
	}
	$known      = array_merge( $cfg['required'], $cfg['optional'] );
	$guess      = certificate_generator_import_guess_date_order( $result['dates'] );
	$date_order = $args['date_order'] ?: ( '' === $guess ? '' : $guess );
	$report     = $result['report'];
	$delimiters = array(
		','  => __( 'comma', 'certificate-generator' ),
		';'  => __( 'semicolon', 'certificate-generator' ),
		"\t" => __( 'tab', 'certificate-generator' ),
	);
	$first_row  = $result['preview'][0] ?? array();

	certificate_generator_ui_card_open( __( 'Check the import', 'certificate-generator' ), array( 'icon' => 'yes-alt' ) );
	echo '<p class="cg-hint">' . esc_html(
		sprintf(
			/* translators: 1: file name, 2: delimiter name, 3: encoding, 4: column count */
			__( '%1$s — %2$s separated, %3$s, %4$d columns.', 'certificate-generator' ),
			$args['source'],
			$delimiters[ $result['delimiter'] ],
			$result['encoding'],
			count( $result['header'] )
		)
	) . ( 'UTF-8' !== $result['encoding'] ? ' ' . esc_html__( 'Converted to UTF-8.', 'certificate-generator' ) : '' ) . '</p>';

	echo '<form method="post" data-cg-busy>';
	wp_nonce_field( "bulk_import_{$entity}_nonce", '_wpnonce_bulk_import' );
	echo '<input type="hidden" name="cg_import_job" value="' . esc_attr( $job ) . '" />';
	echo '<input type="hidden" name="cg_import_source" value="' . esc_attr( $args['source'] ) . '" />';

	echo '<div class="cg-table-wrap"><table class="widefat striped cg-table cg-import-map"><thead><tr><th>' . esc_html__( 'Column in your file', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Imports as', 'certificate-generator' ) . '</th></tr></thead><tbody>';
	foreach ( $result['header'] as $i => $h ) {
		$target = $result['map'][ $i ];
		echo '<tr><td><strong>' . esc_html( '' === $h ? '(blank)' : $h ) . '</strong></td><td><select name="cg_map[' . (int) $i . ']" aria-label="' . esc_attr( sprintf( 'Imports %s as', $h ) ) . '">';
		foreach ( $known as $key ) {
			$label = $key . ( in_array( $key, $cfg['required'], true ) ? ' *' : '' );
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $target, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$slug = certificate_generator_import_norm_header( $h );
		if ( '' !== $slug && ! in_array( $slug, $known, true ) ) {
			echo '<option value="__extra"' . selected( $target, $slug, false ) . '>' . esc_html( sprintf( 'Extra field (%s)', $slug ) ) . '</option>';
		}
		echo '<option value="__ignore"' . selected( $target, '', false ) . '>' . esc_html__( "Don't import", 'certificate-generator' ) . '</option>';
		echo '</select></td></tr>';
	}
	echo '</tbody></table></div>';

	if ( $result['missing'] ) {
		certificate_generator_ui_notice( 'error', '<strong>' . esc_html__( 'Required columns not mapped:', 'certificate-generator' ) . '</strong> ' . esc_html( implode( ', ', $result['missing'] ) ) . '. ' . esc_html__( 'Pick the column that holds each one above, then check again.', 'certificate-generator' ), false );
	}

	$needs_date = '' === $date_order;
	echo '<table class="form-table"><tr><th><label for="cg_date_order">' . esc_html__( 'Issue dates are written as', 'certificate-generator' ) . '</label></th><td><select name="cg_date_order" id="cg_date_order">';
	if ( $needs_date ) {
		echo '<option value="">' . esc_html__( '— choose —', 'certificate-generator' ) . '</option>';
	}
	foreach (
		array(
			'dmy'  => __( 'Day / month / year (25/08/2026)', 'certificate-generator' ),
			'mdy'  => __( 'Month / day / year (08/25/2026)', 'certificate-generator' ),
			'auto' => __( 'Detect each date (ISO 2026-08-25, "25 August 2026", …)', 'certificate-generator' ),
		) as $value => $label
	) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( $date_order, $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	if ( $needs_date ) {
		echo '<p class="description cg-text-warning">' . esc_html__( 'Dates like 03/04/2026 could be 3 April or 4 March. Choose the order your file uses.', 'certificate-generator' ) . '</p>';
	}
	echo '</td></tr><tr><th>' . esc_html__( 'Rows that match an existing record', 'certificate-generator' ) . '</th><td>';
	$mode = certificate_generator_import_existing_mode( $args );
	echo '<label><input type="radio" name="cg_existing" value="update"' . checked( $mode, 'update', false ) . '> ' . esc_html__( 'Update the record with the file\'s values', 'certificate-generator' ) . '</label><br>';
	echo '<label><input type="radio" name="cg_existing" value="keep"' . checked( $mode, 'keep', false ) . '> ' . esc_html__( 'Leave the record unchanged', 'certificate-generator' ) . '</label><br>';
	echo '<label><input type="radio" name="cg_existing" value="flag"' . checked( $mode, 'flag', false ) . '> ' . esc_html__( 'Leave the record unchanged and flag the row for review (Import history: apply, add as new, or dismiss)', 'certificate-generator' ) . '</label>';
	echo '<p class="description">' . esc_html( sprintf( 'A row matches when its %s, certificate type and issue date are the same.', implode( ' + ', $cfg['identity'] ) ) ) . '</p></td></tr></table>';

	if ( $report ) {
		$c = $report['counts'];
		echo '<div class="notice notice-info inline"><p><strong>' . esc_html(
			sprintf(
				/* translators: 1: rows, 2: new, 3: updated, 4: merged, 5: skipped */
				__( '%1$d rows: %2$d new, %3$d update existing records, %4$d merge into an earlier row of this file, %5$d will be skipped.', 'certificate-generator' ),
				$c['read'],
				$c['new'],
				$c['updated'],
				$c['merged'],
				$c['skipped']
			)
		) . ( $c['flagged'] ? ' ' . esc_html( sprintf( /* translators: %d: rows flagged for review */ __( '%d match existing records and will be flagged for review.', 'certificate-generator' ), $c['flagged'] ) ) : '' ) . '</strong> ' . esc_html__( 'Nothing has been saved yet.', 'certificate-generator' ) . '</p></div>';
		certificate_generator_import_render_issues( $report['issues'], 20 );
		if ( $result['long'] ) {
			certificate_generator_ui_notice(
				'warning',
				esc_html(
					sprintf(
						/* translators: 1: number of names, 2: example rows */
						__( '%1$d names are too long for their template field even at the smallest font size, so they will wrap onto a second line: %2$s', 'certificate-generator' ),
						count( $result['long'] ),
						implode( ', ', array_map( fn( $l ) => sprintf( 'row %d (%s)', $l[0], $l[1] ), array_slice( $result['long'], 0, 5 ) ) ) . ( count( $result['long'] ) > 5 ? ', …' : '' )
					)
				),
				false
			);
		}

		if ( $result['preview'] ) {
			$cols = array_keys( $first_row );
			$cols = array_values( array_diff( $cols, array( 'event_id', 'school_id', 'year', 'status', 'import_source', 'import_run_id', 'created_at', 'updated_at' ) ) );
			echo '<h3>' . esc_html__( 'First rows as they will be saved', 'certificate-generator' ) . '</h3><div class="cg-table-wrap"><table class="widefat striped cg-table"><thead><tr>';
			foreach ( $cols as $col ) {
				echo '<th>' . esc_html( $col ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $result['preview'] as $row ) {
				echo '<tr>';
				foreach ( $cols as $col ) {
					echo '<td>' . esc_html( (string) ( $row[ $col ] ?? '' ) ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
	}

	$blocked = $result['missing'] || $needs_date;
	echo '<p class="submit"><input type="submit" name="' . esc_attr( "preview_{$entity}" ) . '" value="' . esc_attr__( 'Check again', 'certificate-generator' ) . '" class="button" /> ';
	echo '<input type="submit" name="' . esc_attr( "import_{$entity}" ) . '" value="' . esc_attr( sprintf( 'Import %d rows', $report ? $report['counts']['new'] + $report['counts']['updated'] : 0 ) ) . '" class="button button-primary"' . disabled( $blocked, true, false ) . ' /></p>';
	if ( $needs_date && ! $result['missing'] ) {
		echo '<p class="description">' . esc_html__( 'Choose the date order, then check again to enable Import.', 'certificate-generator' ) . '</p>';
	}
	echo '</form>';
	certificate_generator_ui_card_close();
}

function certificate_generator_bulk_import_students() {
	certificate_generator_import_page( 'students' );
}

function certificate_generator_bulk_import_teachers() {
	certificate_generator_import_page( 'teachers' );
}

function certificate_generator_bulk_import_schools() {
	certificate_generator_import_page( 'schools' );
}


function certificate_generator_bulk_import_certificates() {
	// Display the import form first so it stays visible after an import (success or error).
	certificate_generator_ui_card_open( 'Upload certificates CSV', array( 'icon' => 'upload' ) );
	echo '<form method="post" enctype="multipart/form-data" data-cg-busy>';
	wp_nonce_field( 'bulk_import_certificates_nonce', '_wpnonce_bulk_import' );
	echo '<table class="form-table">';
	echo '<tr><th><label for="certificates_csv">Upload CSV File</label></th>';
	echo '<td><input type="file" name="certificates_csv" id="certificates_csv" accept=".csv" required />';
	echo ' <a class="button-link" download href="' . esc_url( CERTIFICATE_GENERATOR_URL . 'assets/samples/templates.csv' ) . '">' . esc_html__( 'Download a sample CSV', 'certificate-generator' ) . '</a></td></tr>';
	echo '<tr><td colspan="2"><p class="cg-hint">' . esc_html__( 'One row per template. Replace template_url with the address of your background image (Media Library → copy URL). field_N_name says what each field shows (student_name, teacher_name, school_name, place, issue_date, certificate_type or any extra CSV field); positions are in mm from the top-left corner.', 'certificate-generator' ) . '</p></td></tr>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="submit_certificates" value="Import Certificates" class="button button-primary" /></p>';
	echo '</form>';
	certificate_generator_ui_card_close();

	// Check for file upload and nonce validation
	if ( isset( $_POST['submit_certificates'] ) && isset( $_FILES['certificates_csv'] ) ) {
		if ( check_admin_referer( 'bulk_import_certificates_nonce', '_wpnonce_bulk_import' ) ) {
			if ( ! current_user_can( 'certificate_generator_manage_templates' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
			}
			$file       = $_FILES['certificates_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- temp path set by PHP, not by the request; nonce and capability checked above
			$file_error = absint( $_FILES['certificates_csv']['error'] ?? UPLOAD_ERR_NO_FILE );

			// Error handling for file upload
			if ( $file_error !== UPLOAD_ERR_OK ) {
				certificate_generator_ui_notice( 'error', 'File upload error. Please try again.', false );
				return;
			}

			if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
				// Read and strip BOM if present (UTF-8 BOM: EF BB BF)
				$bom = fread( $handle, 3 );
				if ( $bom !== "﻿" ) {
					// No BOM found, rewind to beginning
					rewind( $handle );
				}

				// Read the first row as the header, skip empty lines
				$header = false;
				while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					// Skip empty rows (rows with only null or empty values)
					if ( array_filter(
						$row,
						function ( $val ) {
							return $val !== null && $val !== ''; }
					) ) {
						$header = $row;
						break;
					}
				}

				if ( $header === false ) {
					certificate_generator_ui_notice( 'error', 'CSV file is empty or invalid.', false );
					fclose( $handle );
					return;
				}

				// Trim whitespace from all header values, handle null values
				$header = array_map(
					function ( $val ) {
						return trim( (string) $val );
					},
					$header
				);

				// Validate headers match the required fields
				// Accept both 'orientation' (export format) and 'template_orientation' (legacy)
				$has_orientation          = in_array( 'orientation', $header, true );
				$has_template_orientation = in_array( 'template_orientation', $header, true );
				$required_fields          = array(
					'certificate_type',
					'event_date',           // Y-m-d or empty — used for date-based template matching
					'template_url',
					$has_orientation && ! $has_template_orientation ? 'orientation' : 'template_orientation',
					'font_size',
					'font_color',
					'font_style',
					'field_1_position_x',
					'field_1_position_y',
					'field_1_visible',
					'field_1_width',
					'field_1_alignment',
					'field_2_position_x',
					'field_2_position_y',
					'field_2_visible',
					'field_2_width',
					'field_2_alignment',
					'field_3_position_x',
					'field_3_position_y',
					'field_3_visible',
					'field_3_width',
					'field_3_alignment',
				);

				// Build allowed optional columns: export-format extras + template_field_count + field_4..MAX_FIELDS slots
				$max_fields             = class_exists( 'CertificateGenerator_Field_Schema' ) ? CertificateGenerator_Field_Schema::MAX_FIELDS : 15;
				$optional_field_columns = array(
					'template_field_count',
					'template_name',
					'page_size',
					'qr_enabled',
					'serial_number_display',
					'status',
					'event_code',
					'entity_type',
					'field_1_height',
					'field_2_height',
					'field_3_height',
					// Accept whichever orientation alias wasn't chosen as required
					$has_orientation && ! $has_template_orientation ? 'template_orientation' : 'orientation',
				);
				for ( $i = 4; $i <= $max_fields; $i++ ) {
					foreach ( array( 'position_x', 'position_y', 'visible', 'width', 'height', 'alignment' ) as $prop ) {
						$optional_field_columns[] = "field_{$i}_{$prop}";
					}
				}
				// What each slot shows (student_name, school_name, issue_date, …), as the template editor saves it.
				for ( $i = 1; $i <= $max_fields; $i++ ) {
					$optional_field_columns[] = "field_{$i}_name";
				}

				// Only error on missing required fields; extra field_N columns are allowed
				$missing_fields = array_diff( $required_fields, $header );
				$extra_fields   = array_diff( $header, array_merge( $required_fields, $optional_field_columns ) );

				if ( ! empty( $missing_fields ) || ! empty( $extra_fields ) ) {
					$error_msg = '<strong>Invalid CSV format.</strong><br><br>';
					if ( ! empty( $missing_fields ) ) {
						$error_msg .= '<strong>Missing fields:</strong> ' . esc_html( implode( ', ', $missing_fields ) ) . '<br>';
					}
					if ( ! empty( $extra_fields ) ) {
						$error_msg .= '<strong>Extra/incorrect fields:</strong> ' . esc_html( implode( ', ', $extra_fields ) ) . '<br>';
					}
					$error_msg .= '<br><strong>Required fields:</strong> ' . implode( ', ', $required_fields );
					certificate_generator_ui_notice( 'error', $error_msg, false );
					fclose( $handle );
					return;
				}

				// Resolve SQL table (required — CPT storage removed in v7)
				global $wpdb;
				$tpl_table = null;
				if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
					$tpl_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
					if ( $tpl_table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tpl_table ) ) !== $tpl_table ) {
						$tpl_table = null;
					}
				}
				if ( ! $tpl_table ) {
					certificate_generator_ui_notice( 'error', 'Certificate templates SQL table not found. Please deactivate and reactivate the plugin to create required tables.', false );
					fclose( $handle );
					return;
				}

				$max_fields_cnt = class_exists( 'CertificateGenerator_Field_Schema' ) ? CertificateGenerator_Field_Schema::MAX_FIELDS : 15;

				// Process each row
				$imported_count      = 0;
				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					// Skip empty rows
					if ( ! array_filter(
						$data,
						function ( $val ) {
							return $val !== null && $val !== ''; }
					) ) {
						continue;
					}

					// Ensure data array has same number of elements as header
					$data = array_pad( $data, count( $header ), '' );

					// Trim all data values
					$data = array_map(
						function ( $val ) {
							return trim( (string) $val );
						},
						$data
					);

					$certificate_data = array_combine( $header, $data );

					// ── Sanitise core fields ──────────────────────────────────
					$certificate_type = sanitize_text_field( $certificate_data['certificate_type'] ?? '' );
					if ( empty( $certificate_type ) ) {
						continue;
					}

					$template_url = esc_url_raw( $certificate_data['template_url'] ?? '' );

					// Validate / normalise event_date (Y-m-d or empty)
					$event_date = '';
					$raw_date   = sanitize_text_field( $certificate_data['event_date'] ?? '' );
					if ( $raw_date !== '' ) {
						if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_date )
							&& ( $parsed = DateTime::createFromFormat( 'Y-m-d', $raw_date ) )
							&& $parsed->format( 'Y-m-d' ) === $raw_date
						) {
							$event_date = $raw_date;
						}
						// d-m-Y fallback (from older exports)
						elseif ( $parsed = DateTime::createFromFormat( 'd-m-Y', $raw_date ) ) {
							$event_date = $parsed->format( 'Y-m-d' );
						}
					}

					// ── Build extra_fields JSON: field positions + template_field_count ──
					$extra_fields_json = array();

					// Derive template_field_count: use explicit column if present, else auto-detect
					$tfc = (int) ( $certificate_data['template_field_count'] ?? 0 );
					if ( $tfc < 1 ) {
						// Only position_x/position_y are meaningful "field exists" signals —
						// width/alignment always carry non-empty placeholder defaults even
						// for unused slots on older exports, which used to make every import
						// auto-detect balloon to MAX_FIELDS.
						for ( $i = $max_fields_cnt; $i >= 1; $i-- ) {
							foreach ( array( 'position_x', 'position_y' ) as $prop ) {
								if ( ! empty( $certificate_data[ "field_{$i}_{$prop}" ] ?? '' ) ) {
									$tfc = $i;
									break 2;
								}
							}
						}
					}
					$field_count                                = max( 2, $tfc ?: 3 );
					$extra_fields_json['template_field_count'] = $field_count;

					for ( $i = 1; $i <= $field_count; $i++ ) {
						$extra_fields_json[ "field_{$i}_position_x" ] = floatval( $certificate_data[ "field_{$i}_position_x" ] ?? 0 );
						$extra_fields_json[ "field_{$i}_position_y" ] = floatval( $certificate_data[ "field_{$i}_position_y" ] ?? 0 );
						$extra_fields_json[ "field_{$i}_visible" ]    = ( $certificate_data[ "field_{$i}_visible" ] ?? '0' ) ? '1' : '0';
						$extra_fields_json[ "field_{$i}_width" ]      = floatval( $certificate_data[ "field_{$i}_width" ] ?? 100 );
						$alignment                                    = strtoupper( sanitize_key( $certificate_data[ "field_{$i}_alignment" ] ?? 'C' ) );
						$extra_fields_json[ "field_{$i}_alignment" ]  = in_array( $alignment, array( 'L', 'C', 'R' ), true ) ? $alignment : 'C';
						$height_input                                 = $certificate_data[ "field_{$i}_height" ] ?? '';
						$extra_fields_json[ "field_{$i}_height" ]     = $height_input !== '' && is_numeric( $height_input ) ? floatval( $height_input ) : '';
						if ( '' !== ( $certificate_data[ "field_{$i}_name" ] ?? '' ) ) {
							$extra_fields_json[ "field_{$i}_name" ] = sanitize_key( $certificate_data[ "field_{$i}_name" ] );
						}
					}

					// ── Write to SQL table ───────────────────────────────────
						// Skip duplicate: same certificate_type + event_date already exists
						$dup_check_sql = "SELECT id FROM $tpl_table WHERE certificate_type = %s AND event_date " .
							( $event_date ? '= %s' : 'IS NULL' );
						$dup_args      = $event_date
							? array( $certificate_type, $event_date )
							: array( $certificate_type );
						if ( $wpdb->get_var( $wpdb->prepare( $dup_check_sql, ...$dup_args ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
							continue; // Skip duplicate
						}

						$orientation = sanitize_key( $certificate_data['template_orientation'] ?? $certificate_data['orientation'] ?? 'landscape' );
						if ( ! in_array( $orientation, array( 'portrait', 'landscape' ), true ) ) {
							$orientation = 'landscape';
						}

						$page_size_raw = $certificate_data['page_size'] ?? 'A4';
						$page_size     = in_array( $page_size_raw, array( 'A4', 'Letter', 'Legal', 'Custom' ), true ) ? $page_size_raw : 'A4';

						$qr_raw             = strtolower( trim( $certificate_data['qr_enabled'] ?? '0' ) );
						$qr_enabled         = ! in_array( $qr_raw, array( '', '0', 'false', 'no' ), true ) ? 1 : 0;
						$sn_raw             = strtolower( trim( $certificate_data['serial_number_display'] ?? '0' ) );
						$serial_number_disp = ! in_array( $sn_raw, array( '', '0', 'false', 'no' ), true ) ? 1 : 0;

						$host          = $template_url ? wp_parse_url( $template_url, PHP_URL_HOST ) : null;
						$date_suffix   = $event_date ? ' (' . $event_date . ')' : '';
						$auto_name     = ( $certificate_type ?: 'Certificate' ) . $date_suffix . ( $host ? ' — ' . $host : '' );
						$template_name = sanitize_text_field( $certificate_data['template_name'] ?? '' ) ?: $auto_name;

						$entity_type = sanitize_key( $certificate_data['entity_type'] ?? 'students' );
						if ( ! in_array( $entity_type, array( 'students', 'teachers', 'schools' ), true ) ) {
							$entity_type = 'students';
						}

						$wpdb->insert(
							$tpl_table,
							array(
								'template_name'         => $template_name,
								'certificate_type'      => $certificate_type,
								'entity_type'           => $entity_type,
								'event_id'              => certificate_generator_resolve_event_id( $certificate_data['event_code'] ?? '' ),
								'event_date'            => $event_date ?: null,
								'template_url'          => $template_url,
								'orientation'           => $orientation,
								'page_size'             => $page_size,
								'font_size'             => absint( $certificate_data['font_size'] ?? 12 ),
								'font_color'            => sanitize_hex_color( $certificate_data['font_color'] ?? '#000000' ) ?: '#000000',
								'font_style'            => sanitize_key( $certificate_data['font_style'] ?? 'helvetica' ),
								'qr_enabled'            => $qr_enabled,
								'serial_number_display' => $serial_number_disp,
								'status'                => 'published',
								'extra_fields'          => wp_json_encode( $extra_fields_json ),
								'created_at'            => current_time( 'mysql' ),
								'updated_at'            => current_time( 'mysql' ),
							)
						);

						++$imported_count;
				}
				fclose( $handle );

				certificate_generator_ui_notice( 'success', 'Successfully imported ' . (int) $imported_count . ' certificate templates!', false );
			} else {
				certificate_generator_ui_notice( 'error', 'Unable to open the file. Please check the file and try again.', false );
			}
		}
	}

}
