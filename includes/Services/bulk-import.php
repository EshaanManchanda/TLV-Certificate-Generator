<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- CSV rows are streamed with fgetcsv()/fputcsv(); WP_Filesystem has no stream API.

/**
 * Resolves an event_code CSV value (e.g. "PO-06-2026") to its wp_cg_events.id.
 * Blank/unmatched codes resolve to null so rows import fine without an event link.
 * Per-request cache — CSV rows for one event share the same code.
 */
function cg_resolve_event_id( string $event_code ): ?int {
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
function cg_import_read_row( array $data, array $header_keys ) {
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
class CG_Import_Writer {

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
	);
	private int $stopped_at  = 0;
	private string $resume_key = '';
	private int $resume_from   = 0;
	private bool $wrote     = false;
	private bool $nested    = false;

	public function __construct( string $table, array $identity, string $name_col ) {
		$this->table     = $table;
		$this->identity  = $identity;
		$this->name_col  = $name_col;
		$limit           = (int) ini_get( 'max_execution_time' );
		$start           = (float) ( $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime( true ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cast to float
		$this->deadline  = (float) apply_filters( 'cg_import_deadline', $limit > 0 ? $start + $limit - 10 : 0.0 );
	}

	/** Pick up where an earlier upload of this same file stopped (see class docblock). */
	public function resume( string $file ): void {
		$hash = is_readable( $file ) ? (string) md5_file( $file ) : '';
		if ( '' === $hash ) {
			return;
		}
		$this->resume_key  = 'cg_import_resume_' . md5( $this->table . '|' . $hash );
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
				$updates[ $id ] = $row;
				$outcome        = $outcome ?? 'updated';
			} else {
				$inserts[ $keyed ? $full : '#' . $line ] = $row;
				$outcome                                 = $outcome ?? 'new';
			}

			if ( $keyed && ! $first ) {
				$this->seen[ $full ] = $line;
			}
			$outcomes[] = array( $line, $name, $outcome, $first );
		}

		$this->begin();
		$ok = ! $inserts || $this->insert_many( array_values( $inserts ) );
		foreach ( $updates as $id => $row ) {
			unset( $row['created_at'] ); // keep when the record was first imported
			$ok = $ok && false !== $wpdb->update( $this->table, $row, array( 'id' => $id ) );
		}
		$error = $ok ? '' : ( $wpdb->last_error ?: 'database error' );
		$this->end( $ok );

		foreach ( $outcomes as list( $line, $name, $outcome, $first ) ) {
			++$this->counts['read'];
			if ( ! $ok ) {
				++$this->counts['failed'];
				$this->issue( $line, $name, 'failed: ' . $error );
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
		cg_debug_log( sprintf( 'Import chunk %s: %d rows, %d new, %d updates%s', $this->table, count( $rows ), count( $inserts ), count( $updates ), $ok ? '' : ' — rolled back: ' . $error ) );
	}

	/** @return array{counts: array, issues: array, stopped_at: int} */
	public function report(): array {
		$this->flush();
		if ( '' !== $this->resume_key ) {
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

	/** Existing rows sharing the chunk's first identity column, grouped by identity. */
	private function preload( array $rows ): array {
		global $wpdb;
		$col  = $this->identity[0];
		$vals = array_values( array_unique( array_filter( array_map( fn( $r ) => (string) ( $r[1][ $col ] ?? '' ), $rows ), fn( $v ) => '' !== $v ) ) );
		$cols = implode( ', ', array_unique( array_merge( array( 'id', 'certificate_type', 'issue_date' ), $this->identity ) ) );
		$in   = implode( ',', array_fill( 0, max( 1, count( $vals ) ), '%s' ) );
		$found = $vals
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			? $wpdb->get_results( $wpdb->prepare( "SELECT $cols FROM {$this->table} WHERE $col IN ($in) ORDER BY id", ...$vals ), ARRAY_A )
			: array();

		// Rows with no email: candidates are existing rows with no email and the same name.
		$names = array_values( array_unique( array_map( fn( $r ) => (string) ( $r[1][ $this->name_col ] ?? '' ), array_filter( $rows, fn( $r ) => '' === (string) ( $r[1][ $col ] ?? '' ) ) ) ) );
		if ( $names && $this->name_col !== $col ) {
			$in_n  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
function cg_import_warn_row( CG_Import_Writer $writer, int $line, string $name, string $raw_email, string $email, string $raw_date, ?string $stored_date ): void {
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
function cg_import_render_report( string $entity, array $report, string $extra_note = '' ): void {
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
				/* translators: %d: CSV row number */
				__( 'Stopped at row %d to stay within the server time limit. Upload the same file again to continue from this row.', 'certificate-generator' ),
				$report['stopped_at']
			)
		) . '</strong></p>';
	}

	if ( $report['issues'] ) {
		$csv = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $csv, array( 'row', 'name', 'issue' ) );
		foreach ( $report['issues'] as $issue ) {
			fputcsv( $csv, $issue );
		}
		rewind( $csv );
		$data = base64_encode( (string) stream_get_contents( $csv ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		fclose( $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		echo '<p><a class="button" download="import-issues.csv" href="data:text/csv;base64,' . esc_attr( $data ) . '">'
			. esc_html(
				sprintf(
					/* translators: %d: number of issues */
					__( 'Download all %d issues (CSV)', 'certificate-generator' ),
					count( $report['issues'] )
				)
			) . '</a></p>';

		echo '<div class="cg-table-wrap cg-import-issues"><table class="widefat striped cg-table"><thead><tr><th class="num">'
			. esc_html__( 'Row', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Name', 'certificate-generator' ) . '</th><th>'
			. esc_html__( 'What happened', 'certificate-generator' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $report['issues'], 0, 100 ) as $issue ) {
			echo '<tr><td class="num">' . (int) $issue[0] . '</td><td>' . esc_html( $issue[1] ) . '</td><td>' . esc_html( $issue[2] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( count( $report['issues'] ) > 100 ) {
			echo '<p class="description">' . esc_html__( 'Showing the first 100 — download the CSV for the full list.', 'certificate-generator' ) . '</p>';
		}
	}
	echo '</div>';
}

function bulk_import_students() {
	// Display the import form first so it stays visible after an import (success or error).
	cg_ui_card_open( 'Upload students CSV', array( 'icon' => 'upload' ) );
	echo '<form method="post" enctype="multipart/form-data" data-cg-busy>';
	wp_nonce_field( 'bulk_import_students_nonce', '_wpnonce_bulk_import' );
	echo '<table class="form-table">';
	echo '<tr><th><label for="students_csv">Upload CSV File</label></th>';
	echo '<td><input type="file" name="students_csv" id="students_csv" accept=".csv" required /></td></tr>';
	echo '</table>';
	echo '<p class="cg-hint">' . esc_html__( 'Large files are imported in passes: if the server time limit is hit, upload the same file again to continue.', 'certificate-generator' ) . '</p>';
	echo '<p class="submit"><input type="submit" name="submit_students" value="Import Students" class="button button-primary" /></p>';
	echo '</form>';
	cg_ui_card_close();

	// Check for file upload and nonce validation
	if ( isset( $_POST['submit_students'] ) && isset( $_FILES['students_csv'] ) ) {
		if ( check_admin_referer( 'bulk_import_students_nonce', '_wpnonce_bulk_import' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
			}
			$file          = $_FILES['students_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- temp path set by PHP, not by the request; nonce and capability checked above
			$file_error    = (int) ( $_FILES['students_csv']['error'] ?? UPLOAD_ERR_NO_FILE );
			$import_source = sanitize_file_name( $_FILES['students_csv']['name'] ?? '' );

			// Error handling for file upload
			if ( $file_error !== UPLOAD_ERR_OK ) {
				cg_ui_notice( 'error', 'File upload error. Please try again.', false );
				return;
			}

			if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
				// Read and strip BOM if present (UTF-8 BOM: EF BB BF)
				$bom = fread( $handle, 3 );
				if ( $bom !== "\xEF\xBB\xBF" ) {
					// No BOM found, rewind to beginning
					rewind( $handle );
				}

				// Read the first row as the header, skip empty lines
				$header      = false;
				$header_line = 0; // CSV row of the header, so data rows report the row a spreadsheet shows
				while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					++$header_line;
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
					cg_ui_notice( 'error', 'CSV file is empty or invalid.', false );
					fclose( $handle );
					return;
				}

				// Trim whitespace and normalize header keys (preserve underscores — sanitize_key strips them!)
				$header      = array_map(
					function ( $val ) {
						return trim( (string) $val );
					},
					$header
				);
				$header_keys = array_map(
					function ( $key ) {
						return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
					},
					$header
				);

				// Check only for missing required fields — never reject extra columns
				$required_fields = array( 'student_name', 'email', 'school_name', 'issue_date', 'certificate_type' );
				$known_optional  = array( 'status', 'send_email', 'phone', 'year', 'event_code', 'photo_url' ); // system columns not registered as PDF field slots
				$missing_fields  = array_diff( $required_fields, $header_keys );
				$extra_columns   = array_values( array_filter( array_diff( $header_keys, array_merge( $required_fields, $known_optional ) ) ) );

				if ( ! empty( $missing_fields ) ) {
					$error_msg  = '<strong>Invalid CSV format.</strong><br><br>';
					$error_msg .= '<strong>Missing required fields:</strong> ' . esc_html( implode( ', ', $missing_fields ) ) . '<br>';
					$error_msg .= '<br><strong>Required fields:</strong> ' . implode( ', ', $required_fields );
					cg_ui_notice( 'error', $error_msg, false );
					fclose( $handle );
					return;
				}

				if ( ! empty( $extra_columns ) ) {
					cg_ui_notice( 'info', '<strong>Extra fields detected:</strong> ' . esc_html( implode( ', ', $extra_columns ) ) . ' — these will be auto-registered for each row\'s certificate type.' );
				}

				// Process each row. $line is the CSV row number (header included), as a spreadsheet shows it.
				if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
					cg_debug_log( '[CertGen] bulk_import_students: CustomTables class not found — nothing imported.' );
					cg_ui_notice( 'error', 'Student table unavailable — nothing was imported.', false );
					fclose( $handle );
					return;
				}
				$tables              = \CertificateGenerator\Database\CustomTables::instance();
				$school_table        = $tables->get_table( 'schools' );
				$writer              = new CG_Import_Writer( $tables->get_table( 'students' ), array( 'email', 'student_name', 'school_name' ), 'student_name' );
				$writer->resume( $file );
				$line                = $header_line;
				$registered_per_type = array(); // track [cert_type_key => [slugs]] to avoid redundant DB writes
				$school_id_cache     = array(); // school_name => id, avoids a SELECT+possible INSERT per row when many students share a school

				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					++$line;
					if ( $writer->done_earlier( $line ) ) {
						continue;
					}

					// Skip empty rows
					if ( ! array_filter(
						$data,
						function ( $val ) {
							return $val !== null && $val !== ''; }
					) ) {
						continue;
					}

					$student_data = cg_import_read_row( $data, $header_keys );
					if ( is_string( $student_data ) ) {
						$writer->skip( $line, (string) ( $data[0] ?? '' ), $student_data );
						continue;
					}
					$student_name = sanitize_text_field( $student_data['student_name'] );
					$cert_type    = sanitize_text_field( $student_data['certificate_type'] );
					if ( '' === $student_name || '' === $cert_type ) {
						$writer->skip( $line, $student_name, ( '' === $student_name ? 'student_name' : 'certificate_type' ) . ' is empty' );
						continue;
					}

					// Register extra fields for this certificate type (runs once per type+slug)
					if ( ! empty( $extra_columns ) && class_exists( 'CG_Field_Schema' ) ) {
						$type_key = CG_Field_Schema::cert_type_to_key( $cert_type );
						foreach ( $extra_columns as $slug ) {
							if ( ! isset( $registered_per_type[ $type_key ][ $slug ] ) ) {
								$ok                                        = CG_Field_Schema::register_field( $cert_type, $slug );
								$registered_per_type[ $type_key ][ $slug ] = $ok ? 'registered' : 'data_only';
							}
						}
					}

					$school_name = sanitize_text_field( $student_data['school_name'] );

					// Build extra fields from extra columns
					$extra_fields = array();
					if ( ! empty( $extra_columns ) ) {
						foreach ( $extra_columns as $slug ) {
							$val = $student_data[ $slug ] ?? '';
							if ( $val !== '' ) {
								$extra_fields[ $slug ] = sanitize_text_field( $val );
							}
						}
					}

					// Accept both 'phone' (SQL export) and 'phone_number' (legacy export)
					$phone = sanitize_text_field( $student_data['phone'] ?? $student_data['phone_number'] ?? '' );

					// Ensure school exists in SQL table (cached — many rows share the same school)
					if ( isset( $school_id_cache[ $school_name ] ) ) {
						$school_id = $school_id_cache[ $school_name ];
					} else {
						$school_id = $GLOBALS['wpdb']->get_var(
							$GLOBALS['wpdb']->prepare(
								"SELECT id FROM $school_table WHERE school_name = %s LIMIT 1",
								$school_name
							)
						);
						if ( ! $school_id ) {
							$GLOBALS['wpdb']->insert(
								$school_table,
								array(
									'school_name' => $school_name,
									'status'      => 'active',
									'created_at'  => current_time( 'mysql' ),
								)
							);
							$school_id = $GLOBALS['wpdb']->insert_id;
						}
						$school_id_cache[ $school_name ] = $school_id;
					}

					$_issue_raw    = sanitize_text_field( $student_data['issue_date'] );
					$_issue_stored = class_exists( '\CertificateGenerator\Helpers\DateHelper' )
						? ( \CertificateGenerator\Helpers\DateHelper::to_storage( $_issue_raw ) ?? $_issue_raw )
						: $_issue_raw;

					// send_email: optional CSV column. false/0/no → 0 (opt-out), anything else → 1 (default).
					$_se_raw    = strtolower( trim( $student_data['send_email'] ?? '1' ) );
					$send_email = in_array( $_se_raw, array( 'false', '0', 'no' ), true ) ? 0 : 1;

					$insert_data = array(
						'student_name'     => $student_name,
						'email'            => sanitize_email( $student_data['email'] ),
						'phone'            => $phone,
						'school_id'        => $school_id,
						'school_name'      => $school_name,
						'event_id'         => cg_resolve_event_id( $student_data['event_code'] ?? '' ),
						'certificate_type' => $cert_type,
						'issue_date'       => $_issue_stored ?: null,
						'year'             => function_exists( 'cg_year_from_issue_date' ) ? cg_year_from_issue_date( $_issue_stored ) : null,
						'status'           => 'active',
						'photo_url'        => ! empty( $student_data['photo_url'] ) ? esc_url_raw( $student_data['photo_url'] ) : null,
						'send_email'       => $send_email,
						'import_source'    => $import_source,
						'created_at'       => current_time( 'mysql' ),
						'updated_at'       => current_time( 'mysql' ),
					);
					if ( ! empty( $extra_fields ) ) {
						$insert_data['extra_fields'] = wp_json_encode( $extra_fields );
					}
					cg_import_warn_row( $writer, $line, $student_name, $student_data['email'], $insert_data['email'], $_issue_raw, $_issue_stored );

					// Same record = email + name + school + certificate type + issue date (see
					// CG_Import_Writer); rows without an email are always new.
					if ( ! $writer->add( $line, $insert_data ) ) {
						break;
					}
				}
				fclose( $handle );
				$report = $writer->report();

				$extra_note = '';
				if ( ! empty( $extra_columns ) && class_exists( 'CG_Field_Schema' ) ) {
					$registered_slugs = array();
					$data_only_slugs  = array();
					foreach ( $registered_per_type as $type_data ) {
						foreach ( $type_data as $slug => $status ) {
							if ( $status === 'registered' && ! in_array( $slug, $registered_slugs, true ) ) {
								$registered_slugs[] = $slug;
							} elseif ( $status === 'data_only' && ! in_array( $slug, $data_only_slugs, true ) ) {
								$data_only_slugs[] = $slug;
							}
						}
					}
					if ( ! empty( $registered_slugs ) ) {
						$extra_note .= ' Extra fields registered for template: ' . implode( ', ', $registered_slugs ) . '.';
					}
					if ( ! empty( $data_only_slugs ) ) {
						$extra_note .= ' Additional fields saved (data only, beyond template limit): ' . implode( ', ', $data_only_slugs ) . '.';
					}
				}
				if ( function_exists( 'cg_flush_filter_caches' ) ) {
					cg_flush_filter_caches();
				}
				cg_import_render_report( 'students', $report, $extra_note );
			} else {
				cg_ui_notice( 'error', 'Unable to open the file. Please check the file and try again.', false );
			}
		}
	}

}



function bulk_import_teachers() {
	// Display the import form first so it stays visible after an import (success or error).
	cg_ui_card_open( 'Upload teachers CSV', array( 'icon' => 'upload' ) );
	echo '<form method="post" enctype="multipart/form-data" data-cg-busy>';
	wp_nonce_field( 'bulk_import_teachers_nonce', '_wpnonce_bulk_import' );
	echo '<table class="form-table">';
	echo '<tr><th><label for="teachers_csv">Upload CSV File</label></th>';
	echo '<td><input type="file" name="teachers_csv" id="teachers_csv" accept=".csv" required /></td></tr>';
	echo '</table>';
	echo '<p class="cg-hint">' . esc_html__( 'Large files are imported in passes: if the server time limit is hit, upload the same file again to continue.', 'certificate-generator' ) . '</p>';
	echo '<p class="submit"><input type="submit" name="submit_teachers" value="Import Teachers" class="button button-primary" /></p>';
	echo '</form>';
	cg_ui_card_close();

	// Check for file upload and nonce validation
	if ( isset( $_POST['submit_teachers'] ) && isset( $_FILES['teachers_csv'] ) ) {
		if ( check_admin_referer( 'bulk_import_teachers_nonce', '_wpnonce_bulk_import' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
			}
			$file          = $_FILES['teachers_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- temp path set by PHP, not by the request; nonce and capability checked above
			$file_error    = (int) ( $_FILES['teachers_csv']['error'] ?? UPLOAD_ERR_NO_FILE );
			$import_source = sanitize_file_name( $_FILES['teachers_csv']['name'] ?? '' );

			// Error handling for file upload
			if ( $file_error !== UPLOAD_ERR_OK ) {
				cg_ui_notice( 'error', 'File upload error. Please try again.', false );
				return;
			}

			if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
				// Read and strip BOM if present (UTF-8 BOM: EF BB BF)
				$bom = fread( $handle, 3 );
				if ( $bom !== "\xEF\xBB\xBF" ) {
					// No BOM found, rewind to beginning
					rewind( $handle );
				}

				// Read the first row as the header, skip empty lines
				$header      = false;
				$header_line = 0; // CSV row of the header, so data rows report the row a spreadsheet shows
				while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					++$header_line;
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
					cg_ui_notice( 'error', 'CSV file is empty or invalid.', false );
					fclose( $handle );
					return;
				}

				// Trim whitespace from all header values, handle null values
				$header      = array_map(
					function ( $val ) {
						return trim( (string) $val );
					},
					$header
				);
				$header_keys = array_map(
					function ( $key ) {
						return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
					},
					$header
				);

				// Validate headers match the required fields
				$required_fields = array(
					'teacher_name',
					'email',
					'school_name',
					'issue_date',
					'certificate_type',
				);

				// Check only for missing required fields — never reject extra columns
				$known_optional = array( 'status', 'send_email', 'phone', 'phone_number', 'year', 'event_code' ); // system columns not registered as PDF field slots
				$missing_fields = array_diff( $required_fields, $header_keys );
				$extra_columns  = array_values( array_filter( array_diff( $header_keys, array_merge( $required_fields, $known_optional ) ) ) );

				if ( ! empty( $missing_fields ) ) {
					$error_msg  = '<strong>Invalid CSV format.</strong><br><br>';
					$error_msg .= '<strong>Missing required fields:</strong> ' . esc_html( implode( ', ', $missing_fields ) ) . '<br>';
					$error_msg .= '<br><strong>Required fields:</strong> ' . implode( ', ', $required_fields );
					cg_ui_notice( 'error', $error_msg, false );
					fclose( $handle );
					return;
				}

				if ( ! empty( $extra_columns ) ) {
					cg_ui_notice( 'info', '<strong>Extra fields detected:</strong> ' . esc_html( implode( ', ', $extra_columns ) ) . ' — these will be saved to extra_fields for each teacher.' );
				}

				// Process each row. $line is the CSV row number (header included), as a spreadsheet shows it.
				if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
					cg_debug_log( '[CertGen] bulk_import_teachers: CustomTables class not found — nothing imported.' );
					cg_ui_notice( 'error', 'Teacher table unavailable — nothing was imported.', false );
					fclose( $handle );
					return;
				}
				$tables          = \CertificateGenerator\Database\CustomTables::instance();
				$school_table    = $tables->get_table( 'schools' );
				$writer          = new CG_Import_Writer( $tables->get_table( 'teachers' ), array( 'email', 'teacher_name', 'school_name' ), 'teacher_name' );
				$writer->resume( $file );
				$line            = $header_line;
				$school_id_cache = array(); // school_name => id, avoids a SELECT+possible INSERT per row when many teachers share a school
				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					++$line;
					if ( $writer->done_earlier( $line ) ) {
						continue;
					}

					// Skip empty rows
					if ( ! array_filter(
						$data,
						function ( $val ) {
							return $val !== null && $val !== ''; }
					) ) {
						continue;
					}

					$teacher_data = cg_import_read_row( $data, $header_keys );
					if ( is_string( $teacher_data ) ) {
						$writer->skip( $line, (string) ( $data[0] ?? '' ), $teacher_data );
						continue;
					}
					$teacher_name = sanitize_text_field( $teacher_data['teacher_name'] );
					$cert_type    = sanitize_text_field( $teacher_data['certificate_type'] );
					if ( '' === $teacher_name || '' === $cert_type ) {
						$writer->skip( $line, $teacher_name, ( '' === $teacher_name ? 'teacher_name' : 'certificate_type' ) . ' is empty' );
						continue;
					}

					$school_name = sanitize_text_field( $teacher_data['school_name'] );

					$_t_issue_raw    = sanitize_text_field( $teacher_data['issue_date'] );
					$_t_issue_stored = class_exists( '\CertificateGenerator\Helpers\DateHelper' )
						? ( \CertificateGenerator\Helpers\DateHelper::to_storage( $_t_issue_raw ) ?? $_t_issue_raw )
						: $_t_issue_raw;

					// Ensure school exists (cached — many rows share the same school)
					if ( isset( $school_id_cache[ $school_name ] ) ) {
						$school_id = $school_id_cache[ $school_name ];
					} else {
						$school_id = $GLOBALS['wpdb']->get_var(
							$GLOBALS['wpdb']->prepare(
								"SELECT id FROM $school_table WHERE school_name = %s LIMIT 1",
								$school_name
							)
						);
						if ( ! $school_id ) {
							$GLOBALS['wpdb']->insert(
								$school_table,
								array(
									'school_name' => $school_name,
									'status'      => 'active',
									'created_at'  => current_time( 'mysql' ),
								)
							);
							$school_id = $GLOBALS['wpdb']->insert_id;
						}
						$school_id_cache[ $school_name ] = $school_id;
					}

					// Build extra fields from extra columns
					$extra_fields = array();
					if ( ! empty( $extra_columns ) ) {
						foreach ( $extra_columns as $slug ) {
							$val = $teacher_data[ $slug ] ?? '';
							if ( $val !== '' ) {
								$extra_fields[ $slug ] = sanitize_text_field( $val );
							}
						}
					}

					// Accept both 'phone' (SQL export) and 'phone_number' (legacy export)
					$phone = sanitize_text_field( $teacher_data['phone'] ?? $teacher_data['phone_number'] ?? '' );

					// send_email: optional CSV column. false/0/no → 0 (opt-out), anything else → 1 (default).
					$_se_raw_t    = strtolower( trim( $teacher_data['send_email'] ?? '1' ) );
					$send_email_t = in_array( $_se_raw_t, array( 'false', '0', 'no' ), true ) ? 0 : 1;

					$insert_data = array(
						'teacher_name'     => $teacher_name,
						'email'            => sanitize_email( $teacher_data['email'] ),
						'phone'            => $phone,
						'school_id'        => $school_id,
						'school_name'      => $school_name,
						'event_id'         => cg_resolve_event_id( $teacher_data['event_code'] ?? '' ),
						'certificate_type' => $cert_type,
						'issue_date'       => $_t_issue_stored ?: null,
						'year'             => function_exists( 'cg_year_from_issue_date' ) ? cg_year_from_issue_date( $_t_issue_stored ) : null,
						'status'           => 'active',
						'send_email'       => $send_email_t,
						'import_source'    => $import_source,
						'created_at'       => current_time( 'mysql' ),
						'updated_at'       => current_time( 'mysql' ),
					);
					if ( ! empty( $extra_fields ) ) {
						$insert_data['extra_fields'] = wp_json_encode( $extra_fields );
					}
					cg_import_warn_row( $writer, $line, $teacher_name, $teacher_data['email'], $insert_data['email'], $_t_issue_raw, $_t_issue_stored );

					// Same record = email + name + school + certificate type + issue date; rows
					// without an email are always new.
					if ( ! $writer->add( $line, $insert_data ) ) {
						break;
					}
				}
				fclose( $handle );
				$report = $writer->report();

				if ( function_exists( 'cg_flush_filter_caches' ) ) {
					cg_flush_filter_caches();
				}
				cg_import_render_report( 'teachers', $report );
			} else {
				cg_ui_notice( 'error', 'Unable to open the file. Please check the file and try again.', false );
			}
		}
	}

}




function bulk_import_schools() {
	// Display the import form first so it stays visible after an import (success or error).
	cg_ui_card_open( 'Upload schools CSV', array( 'icon' => 'upload' ) );
	echo '<form method="post" enctype="multipart/form-data" data-cg-busy>';
	wp_nonce_field( 'bulk_import_schools_nonce', '_wpnonce_bulk_import' );
	echo '<table class="form-table">';
	echo '<tr><th><label for="schools_csv">Upload CSV File</label></th>';
	echo '<td><input type="file" name="schools_csv" id="schools_csv" accept=".csv" required /></td></tr>';
	echo '</table>';
	echo '<p class="cg-hint">' . esc_html__( 'Large files are imported in passes: if the server time limit is hit, upload the same file again to continue.', 'certificate-generator' ) . '</p>';
	echo '<p class="submit"><input type="submit" name="submit_schools" value="Import Schools" class="button button-primary" /></p>';
	echo '</form>';
	cg_ui_card_close();

	// Check for file upload and nonce validation
	if ( isset( $_POST['submit_schools'] ) && isset( $_FILES['schools_csv'] ) ) {
		if ( check_admin_referer( 'bulk_import_schools_nonce', '_wpnonce_bulk_import' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
			}
			$file          = $_FILES['schools_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- temp path set by PHP, not by the request; nonce and capability checked above
			$file_error    = (int) ( $_FILES['schools_csv']['error'] ?? UPLOAD_ERR_NO_FILE );
			$import_source = sanitize_file_name( $_FILES['schools_csv']['name'] ?? '' );

			// Error handling for file upload
			if ( $file_error !== UPLOAD_ERR_OK ) {
				cg_ui_notice( 'error', 'File upload error. Please try again.', false );
				return;
			}

			if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
				// Read and strip BOM if present (UTF-8 BOM: EF BB BF)
				$bom = fread( $handle, 3 );
				if ( $bom !== "\xEF\xBB\xBF" ) {
					// No BOM found, rewind to beginning
					rewind( $handle );
				}

				// Read the first row as the header, skip empty lines
				$header      = false;
				$header_line = 0; // CSV row of the header, so data rows report the row a spreadsheet shows
				while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					++$header_line;
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
					cg_ui_notice( 'error', 'CSV file is empty or invalid.', false );
					fclose( $handle );
					return;
				}

				// Trim whitespace from all header values, handle null values
				$header      = array_map(
					function ( $val ) {
						return trim( (string) $val );
					},
					$header
				);
				$header_keys = array_map(
					function ( $key ) {
						return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
					},
					$header
				);

				// Validate headers — accept 'place' (current export) or 'city' (old SQL export) for the city field
				$has_place       = in_array( 'place', $header_keys, true );
				$has_city        = in_array( 'city', $header_keys, true );
				$required_fields = array(
					'school_name',
					( $has_place || ! $has_city ) ? 'place' : 'city',
					'issue_date',
					'certificate_type',
				);

				// Check only for missing required fields — never reject extra columns
				// Also treat the unused city/place alias as an allowed extra.
				$known_optional = array( 'status', 'send_email', 'year', 'event_code', $has_place ? 'city' : 'place' );
				$missing_fields = array_diff( $required_fields, $header_keys );
				$extra_columns  = array_values( array_filter( array_diff( $header_keys, array_merge( $required_fields, $known_optional ) ) ) );

				if ( ! empty( $missing_fields ) ) {
					$error_msg  = '<strong>Invalid CSV format.</strong><br><br>';
					$error_msg .= '<strong>Missing required fields:</strong> ' . esc_html( implode( ', ', $missing_fields ) ) . '<br>';
					$error_msg .= '<br><strong>Required fields:</strong> ' . implode( ', ', $required_fields );
					cg_ui_notice( 'error', $error_msg, false );
					fclose( $handle );
					return;
				}

				if ( ! empty( $extra_columns ) ) {
					cg_ui_notice( 'info', '<strong>Extra fields detected:</strong> ' . esc_html( implode( ', ', $extra_columns ) ) . ' — these will be saved to extra_fields for each school.' );
				}

				// Process each row. $line is the CSV row number (header included), as a spreadsheet shows it.
				if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
					cg_debug_log( '[CertGen] bulk_import_schools: CustomTables class not found — nothing imported.' );
					cg_ui_notice( 'error', 'School table unavailable — nothing was imported.', false );
					fclose( $handle );
					return;
				}
				// Same record = school name + certificate type + issue date; a type-less school row
				// the students/teachers import auto-created is claimed instead of duplicated.
				$writer = new CG_Import_Writer( \CertificateGenerator\Database\CustomTables::instance()->get_table( 'schools' ), array( 'school_name' ), 'school_name' );
				$writer->resume( $file );
				$line   = $header_line;
				while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					++$line;
					if ( $writer->done_earlier( $line ) ) {
						continue;
					}

					// Skip empty rows
					if ( ! array_filter(
						$data,
						function ( $val ) {
							return $val !== null && $val !== ''; }
					) ) {
						continue;
					}

					$school_data = cg_import_read_row( $data, $header_keys );
					if ( is_string( $school_data ) ) {
						$writer->skip( $line, (string) ( $data[0] ?? '' ), $school_data );
						continue;
					}
					$school_name = sanitize_text_field( $school_data['school_name'] );
					$cert_type   = sanitize_text_field( $school_data['certificate_type'] );
					if ( '' === $school_name || '' === $cert_type ) {
						$writer->skip( $line, $school_name, ( '' === $school_name ? 'school_name' : 'certificate_type' ) . ' is empty' );
						continue;
					}

					$_s_issue_raw    = sanitize_text_field( $school_data['issue_date'] );
					$_s_issue_stored = class_exists( '\CertificateGenerator\Helpers\DateHelper' )
						? ( \CertificateGenerator\Helpers\DateHelper::to_storage( $_s_issue_raw ) ?? $_s_issue_raw )
						: $_s_issue_raw;

					// Build extra fields from extra columns
					$extra_fields = array();
					if ( ! empty( $extra_columns ) ) {
						foreach ( $extra_columns as $slug ) {
							$val = $school_data[ $slug ] ?? '';
							if ( $val !== '' ) {
								$extra_fields[ $slug ] = sanitize_text_field( $val );
							}
						}
					}

					// send_email: optional CSV column. false/0/no → 0 (opt-out), anything else → 1 (default).
					$_se_raw_s    = strtolower( trim( $school_data['send_email'] ?? '1' ) );
					$send_email_s = in_array( $_se_raw_s, array( 'false', '0', 'no' ), true ) ? 0 : 1;

					$insert_data = array(
						'school_name'      => $school_name,
						'city'             => sanitize_text_field( $school_data['place'] ?? $school_data['city'] ?? '' ),
						'event_id'         => cg_resolve_event_id( $school_data['event_code'] ?? '' ),
						'certificate_type' => $cert_type,
						'issue_date'       => $_s_issue_stored ?: null,
						'year'             => function_exists( 'cg_year_from_issue_date' ) ? cg_year_from_issue_date( $_s_issue_stored ) : null,
						'status'           => 'active',
						'send_email'       => $send_email_s,
						'import_source'    => $import_source,
						'created_at'       => current_time( 'mysql' ),
						'updated_at'       => current_time( 'mysql' ),
					);
					if ( ! empty( $extra_fields ) ) {
						$insert_data['extra_fields'] = wp_json_encode( $extra_fields );
					}
					cg_import_warn_row( $writer, $line, $school_name, '', '', $_s_issue_raw, $_s_issue_stored );

					if ( ! $writer->add( $line, $insert_data ) ) {
						break;
					}
				}
				fclose( $handle );
				$report = $writer->report();

				if ( function_exists( 'cg_flush_filter_caches' ) ) {
					cg_flush_filter_caches();
				}
				cg_import_render_report( 'schools', $report );
			} else {
				cg_ui_notice( 'error', 'Unable to open the file. Please check the file and try again.', false );
			}
		}
	}

}




function bulk_import_certificates() {
	// Display the import form first so it stays visible after an import (success or error).
	cg_ui_card_open( 'Upload certificates CSV', array( 'icon' => 'upload' ) );
	echo '<form method="post" enctype="multipart/form-data" data-cg-busy>';
	wp_nonce_field( 'bulk_import_certificates_nonce', '_wpnonce_bulk_import' );
	echo '<table class="form-table">';
	echo '<tr><th><label for="certificates_csv">Upload CSV File</label></th>';
	echo '<td><input type="file" name="certificates_csv" id="certificates_csv" accept=".csv" required /></td></tr>';
	echo '</table>';
	echo '<p class="submit"><input type="submit" name="submit_certificates" value="Import Certificates" class="button button-primary" /></p>';
	echo '</form>';
	cg_ui_card_close();

	// Check for file upload and nonce validation
	if ( isset( $_POST['submit_certificates'] ) && isset( $_FILES['certificates_csv'] ) ) {
		if ( check_admin_referer( 'bulk_import_certificates_nonce', '_wpnonce_bulk_import' ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
			}
			$file       = $_FILES['certificates_csv']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- temp path set by PHP, not by the request; nonce and capability checked above
			$file_error = (int) ( $_FILES['certificates_csv']['error'] ?? UPLOAD_ERR_NO_FILE );

			// Error handling for file upload
			if ( $file_error !== UPLOAD_ERR_OK ) {
				cg_ui_notice( 'error', 'File upload error. Please try again.', false );
				return;
			}

			if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
				// Read and strip BOM if present (UTF-8 BOM: EF BB BF)
				$bom = fread( $handle, 3 );
				if ( $bom !== "\xEF\xBB\xBF" ) {
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
					cg_ui_notice( 'error', 'CSV file is empty or invalid.', false );
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
				$max_fields             = class_exists( 'CG_Field_Schema' ) ? CG_Field_Schema::MAX_FIELDS : 15;
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
					cg_ui_notice( 'error', $error_msg, false );
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
					cg_ui_notice( 'error', 'Certificate templates SQL table not found. Please deactivate and reactivate the plugin to create required tables.', false );
					fclose( $handle );
					return;
				}

				$max_fields_cnt = class_exists( 'CG_Field_Schema' ) ? CG_Field_Schema::MAX_FIELDS : 15;

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
								'event_id'              => cg_resolve_event_id( $certificate_data['event_code'] ?? '' ),
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

				cg_ui_notice( 'success', 'Successfully imported ' . (int) $imported_count . ' certificate templates!', false );
			} else {
				cg_ui_notice( 'error', 'Unable to open the file. Please check the file and try again.', false );
			}
		}
	}

}
