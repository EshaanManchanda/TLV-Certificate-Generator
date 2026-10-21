<?php
/**
 * Import history: every CSV import of students, teachers or schools is a run (a wp_cg_jobs row
 * of type "import"). Rows it created carry import_run_id; fields it changed and rows it flagged
 * for review are in wp_cg_import_items. From Bulk Import → Import history a run can be
 * reviewed, its flagged rows applied / added / dismissed, and the import undone.
 *
 * Undo never touches what happened after the import: a created row that has a certificate, an
 * email, or was edited since is kept; an updated field that was changed since keeps its new
 * value. Every row it keeps is recorded with the reason, and undo runs in batches that a
 * second request continues.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CERTIFICATE_GENERATOR_UNDO_BATCH = 500;

function certificate_generator_import_items_table(): string {
	return \CertificateGenerator\Database\CustomTables::instance()->get_table( 'import_items' );
}

function certificate_generator_import_runs_table(): string {
	return \CertificateGenerator\Database\CustomTables::instance()->get_table( 'jobs' );
}

/** update | keep | flag, from the form's cg_existing or the older update_existing bool. */
function certificate_generator_import_existing_mode( array $args ): string {
	$mode = $args['existing'] ?? ( ( $args['update_existing'] ?? true ) ? 'update' : 'keep' );
	return in_array( $mode, array( 'update', 'keep', 'flag' ), true ) ? $mode : 'update';
}

/** @param array $items Rows of [run_id, line, status, record_id, changed JSON|null, file_values JSON|null, created_at, note?]. */
function certificate_generator_import_insert_items( array $items ): bool {
	global $wpdb;
	$values = array();
	foreach ( $items as $i ) {
		$sql = array( $wpdb->prepare( '%d, %d, %s, %d', $i[0], $i[1], $i[2], $i[3] ) );
		foreach ( array( $i[4], $i[5], $i[6], $i[7] ?? null ) as $v ) {
			$sql[] = null === $v ? 'NULL' : $wpdb->prepare( '%s', $v );
		}
		$values[] = '(' . implode( ', ', $sql ) . ')';
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- values prepared above
	return false !== $wpdb->query( 'INSERT INTO ' . certificate_generator_import_items_table() . ' (run_id, line, status, record_id, changed, file_values, created_at, note) VALUES ' . implode( ',', $values ) );
}

/** The run for this import: the one an earlier, timed-out pass of the same file started, or a new one. */
function certificate_generator_import_run_start( string $entity, string $source, string $existing, string $resume_key ): int {
	global $wpdb;
	if ( '' !== $resume_key ) {
		$run = (int) get_transient( $resume_key . '_run' );
		if ( $run && certificate_generator_import_run_get( $run ) ) {
			return $run;
		}
	}
	$wpdb->insert(
		certificate_generator_import_runs_table(),
		array(
			'type'       => 'import',
			'status'     => 'running',
			'params'     => wp_json_encode(
				array(
					'entity'     => $entity,
					'source'     => $source,
					'existing'   => $existing,
					'counts'     => array(),
					'db_started' => $wpdb->get_var( 'SELECT NOW()' ), // DB clock, to compare with the tables' CURRENT_TIMESTAMP columns
				)
			),
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
		)
	);
	$run = (int) $wpdb->insert_id;
	if ( '' !== $resume_key ) {
		set_transient( $resume_key . '_run', $run, DAY_IN_SECONDS );
	}
	return $run;
}

/** Add one pass's counts to the run; on the last pass, mark it done. */
function certificate_generator_import_run_progress( int $run_id, array $counts, bool $finished, string $resume_key ): void {
	global $wpdb;
	$run = certificate_generator_import_run_get( $run_id );
	if ( ! $run ) {
		return;
	}
	$params = $run['params'];
	foreach ( array( 'new', 'updated', 'merged', 'skipped', 'failed', 'flagged' ) as $k ) {
		$params['counts'][ $k ] = (int) ( $params['counts'][ $k ] ?? 0 ) + (int) ( $counts[ $k ] ?? 0 );
	}
	$params['db_last'] = $wpdb->get_var( 'SELECT NOW()' );
	$c                 = $params['counts'];
	$fields            = array(
		'params'     => wp_json_encode( $params ),
		'total'      => $c['new'] + $c['updated'] + $c['merged'] + $c['skipped'] + $c['failed'] + $c['flagged'],
		'done'       => $c['new'] + $c['updated'],
		'failed'     => $c['failed'],
		'updated_at' => current_time( 'mysql', true ),
	);
	if ( $finished ) {
		$fields['status']      = 'done';
		$fields['finished_at'] = current_time( 'mysql', true );
		if ( '' !== $resume_key ) {
			delete_transient( $resume_key . '_run' );
		}
	}
	$wpdb->update( certificate_generator_import_runs_table(), $fields, array( 'id' => $run_id ) );
}

function certificate_generator_import_run_get( int $run_id ): ?array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
	$run = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . certificate_generator_import_runs_table() . " WHERE id = %d AND type = 'import'", $run_id ), ARRAY_A );
	if ( ! $run ) {
		return null;
	}
	$run['params'] = json_decode( (string) $run['params'], true ) ?: array();
	return $run;
}

function certificate_generator_import_run_entity( array $run ): string {
	$entity = (string) ( $run['params']['entity'] ?? '' );
	return in_array( $entity, array( 'students', 'teachers', 'schools' ), true ) ? $entity : 'students';
}

/** @return array<int,array> Items of a run with the given status, oldest first. */
function certificate_generator_import_items( int $run_id, string $status, int $limit = 200 ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . certificate_generator_import_items_table() . ' WHERE run_id = %d AND status = %s ORDER BY id LIMIT %d', $run_id, $status, $limit ), ARRAY_A ) ?: array();
}

function certificate_generator_import_item_count( int $run_id, string $status ): int {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . certificate_generator_import_items_table() . ' WHERE run_id = %d AND status = %s', $run_id, $status ) );
}

function certificate_generator_import_set_item( int $item_id, string $status, string $note = '', ?array $changed = null, int $record_id = 0 ): void {
	global $wpdb;
	$fields = array( 'status' => $status, 'note' => '' === $note ? null : mb_substr( $note, 0, 255 ) );
	if ( null !== $changed ) {
		$fields['changed'] = wp_json_encode( $changed );
	}
	if ( $record_id ) {
		$fields['record_id'] = $record_id;
	}
	$wpdb->update( certificate_generator_import_items_table(), $fields, array( 'id' => $item_id ) );
}

/**
 * A flagged row: apply its file values to the existing record, add it as a new record (part of
 * the run, so undo removes it), or dismiss it.
 *
 * @return true|WP_Error
 */
function certificate_generator_import_resolve_flag( int $item_id, string $action ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
	$item = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . certificate_generator_import_items_table() . " WHERE id = %d AND status = 'flagged'", $item_id ), ARRAY_A );
	$run  = $item ? certificate_generator_import_run_get( (int) $item['run_id'] ) : null;
	if ( ! $run ) {
		return new WP_Error( 'not_found', __( 'That flagged row was already handled.', 'certificate-generator' ) );
	}
	if ( 'dismiss' === $action ) {
		certificate_generator_import_set_item( $item_id, 'dismissed' );
		return true;
	}
	$entity = certificate_generator_import_run_entity( $run );
	$values = array_diff_key( (array) json_decode( (string) $item['file_values'], true ), array_flip( array( 'id', 'created_at', 'updated_at', 'import_run_id' ) ) );
	if ( 'apply' === $action ) {
		$table   = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $item['record_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		if ( ! $current ) {
			return new WP_Error( 'gone', __( 'The existing record was deleted; add the row as a new record instead.', 'certificate-generator' ) );
		}
		$changed = array();
		foreach ( $values as $col => $new ) {
			if ( (string) ( $current[ $col ] ?? '' ) !== (string) $new ) {
				$changed[ $col ] = array( $current[ $col ] ?? null, $new );
			}
		}
		$saved = $changed ? certificate_generator_save_recipient( $entity, array_map( static fn( $c ) => $c[1], $changed ), (int) $item['record_id'] ) : (int) $item['record_id'];
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// From here on it is an update this import made, so undo can revert it.
		certificate_generator_import_set_item( $item_id, 'updated', __( 'applied from a flagged row', 'certificate-generator' ), $changed );
		return true;
	}
	if ( 'add' === $action ) {
		$id = certificate_generator_save_recipient( $entity, $values + array( 'import_run_id' => (int) $run['id'] ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		certificate_generator_import_set_item( $item_id, 'added', '', null, (int) $id );
		return true;
	}
	return new WP_Error( 'invalid_action', 'Unknown action.' );
}

/**
 * Why a row the import created must be kept (empty: safe to delete). $since: the DB time the
 * import started; $last: the DB time it last wrote.
 */
function certificate_generator_import_keep_reason( array $row, string $since, string $last ): string {
	global $wpdb;
	if ( '' !== (string) ( $row['serial_number'] ?? '' ) ) {
		return __( 'has an issued certificate', 'certificate-generator' );
	}
	$email = (string) ( $row['email'] ?? '' );
	if ( '' !== $email ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix
		$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cert_email_queue WHERE recipient_email = %s AND created_at >= %s", $email, $since ) );
		$sent   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cert_email_logs WHERE recipient_email = %s AND sent_at >= %s", $email, $since ) );
		// phpcs:enable
		if ( $queued || $sent ) {
			return __( 'an email was queued or sent to it', 'certificate-generator' );
		}
	}
	if ( '' !== $last && (string) ( $row['updated_at'] ?? '' ) > $last ) {
		return __( 'edited since the import', 'certificate-generator' );
	}
	return '';
}

/**
 * Undo (part of) an import run. Created rows are deleted unless certificate_generator_import_keep_reason()
 * says otherwise; updated fields get their old value back unless they changed since. Each
 * outcome is recorded as an item (reverted / skipped + note), so a stopped undo continues
 * where it left off and nothing is skipped silently.
 *
 * @param bool $dry_run Classify everything, write nothing (the preview).
 * @return array{reverted:int,skipped:array<int,array{record_id:int,note:string}>,remaining:int}|WP_Error
 */
function certificate_generator_import_undo( int $run_id, bool $dry_run = false, int $batch = CERTIFICATE_GENERATOR_UNDO_BATCH ) {
	global $wpdb;
	$run = certificate_generator_import_run_get( $run_id );
	if ( ! $run || ! in_array( $run['status'], array( 'done', 'running', 'rolling_back' ), true ) ) {
		return new WP_Error( 'not_undoable', __( 'This import cannot be undone (unknown, or already undone).', 'certificate-generator' ) );
	}
	$entity = certificate_generator_import_run_entity( $run );
	$table  = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
	$items  = certificate_generator_import_items_table();
	$since  = (string) ( $run['params']['db_started'] ?? '' );
	$last   = (string) ( $run['params']['db_last'] ?? '' );
	$limit  = $dry_run ? PHP_INT_MAX : $batch;
	$out    = array( 'reverted' => 0, 'skipped' => array(), 'remaining' => 0 );
	if ( ! $dry_run ) {
		$wpdb->update( certificate_generator_import_runs_table(), array( 'status' => 'rolling_back', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $run_id ) );
	}

	// 1. Rows the import created, not yet decided (a kept one has a "skipped" item with no changes).
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names from $wpdb->prefix
	$created = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM $table WHERE import_run_id = %d AND id NOT IN (SELECT record_id FROM $items WHERE run_id = %d AND status = 'skipped' AND changed IS NULL) ORDER BY id LIMIT %d",
			$run_id,
			$run_id,
			min( $limit, 1000000 )
		),
		ARRAY_A
	) ?: array();
	// phpcs:enable
	$delete = array();
	$now    = current_time( 'mysql', true );
	$log    = array();
	foreach ( $created as $row ) {
		$reason = certificate_generator_import_keep_reason( $row, $since, $last );
		if ( '' !== $reason ) {
			$out['skipped'][] = array( 'record_id' => (int) $row['id'], 'note' => $reason );
			$log[]            = array( $run_id, 0, 'skipped', (int) $row['id'], null, null, $now, $reason );
		} else {
			$delete[] = (int) $row['id'];
			$log[]    = array( $run_id, 0, 'reverted', (int) $row['id'], null, null, $now, __( 'created by the import; deleted', 'certificate-generator' ) );
		}
	}
	$out['reverted'] += count( $delete );
	if ( ! $dry_run && $created ) {
		$nested = '0' === (string) $wpdb->get_var( 'SELECT @@autocommit' ); // a caller's transaction (PHPUnit): use a savepoint, as the importer does
		$wpdb->query( $nested ? 'SAVEPOINT cg_undo' : 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed statements
		if ( $delete ) {
			$wpdb->query( "DELETE FROM $table WHERE id IN (" . implode( ',', $delete ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix; ids are ints
		}
		$ok = certificate_generator_import_insert_items( $log );
		$wpdb->query( $nested ? ( $ok ? 'RELEASE SAVEPOINT cg_undo' : 'ROLLBACK TO SAVEPOINT cg_undo' ) : ( $ok ? 'COMMIT' : 'ROLLBACK' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed statements
		if ( ! $ok ) {
			return new WP_Error( 'db_error', $wpdb->last_error ?: 'Database error.' );
		}
		if ( $delete ) {
			/** This action is documented in src/Admin/Pages/EntityListPage.php. */
			do_action( 'certificate_generator_recipients_deleted', $entity, $delete );
		}
	}
	$left = $limit - count( $created );

	// 2. Fields the import updated.
	if ( $left > 0 ) {
		foreach ( certificate_generator_import_items( $run_id, 'updated', (int) min( $left, 1000000 ) ) as $item ) {
			$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $item['record_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
			$changed = (array) json_decode( (string) $item['changed'], true );
			$note    = '';
			$restore = array();
			$kept    = array();
			if ( ! $row ) {
				$note = __( 'record deleted since the import', 'certificate-generator' );
			} elseif ( '' !== (string) $row['serial_number'] && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cg_certificates WHERE serial_number = %s AND ( created_at > %s OR updated_at > %s )", $row['serial_number'], $last, $last ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$note = __( 'a certificate was issued or regenerated since the import', 'certificate-generator' );
			} else {
				foreach ( $changed as $col => $pair ) {
					if ( (string) ( $row[ $col ] ?? '' ) === (string) $pair[1] ) {
						$restore[ $col ] = $pair[0];
					} else {
						$kept[] = $col;
					}
				}
				if ( ! $restore ) {
					$note = __( 'every changed field was edited since the import', 'certificate-generator' );
				} elseif ( $kept ) {
					/* translators: %s: column names */
					$note = sprintf( __( 'kept edits made since the import to: %s', 'certificate-generator' ), implode( ', ', $kept ) );
				}
			}
			if ( $restore ) {
				++$out['reverted'];
			} else {
				$out['skipped'][] = array( 'record_id' => (int) $item['record_id'], 'note' => $note );
			}
			if ( ! $dry_run ) {
				if ( $restore ) {
					$wpdb->update( $table, $restore, array( 'id' => (int) $item['record_id'] ) );
				}
				certificate_generator_import_set_item( (int) $item['id'], $restore ? 'reverted' : 'skipped', $note );
			}
		}
	}

	if ( ! $dry_run ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix
		$out['remaining'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE import_run_id = %d AND id NOT IN (SELECT record_id FROM $items WHERE run_id = %d AND status = 'skipped' AND changed IS NULL)", $run_id, $run_id ) )
			+ certificate_generator_import_item_count( $run_id, 'updated' );
		if ( 0 === $out['remaining'] ) {
			$params             = $run['params'];
			$params['undone_at'] = current_time( 'mysql', true );
			$wpdb->update( certificate_generator_import_runs_table(), array( 'status' => 'cancelled', 'params' => wp_json_encode( $params ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $run_id ) );
		}
		if ( function_exists( 'certificate_generator_flush_filter_caches' ) ) {
			certificate_generator_flush_filter_caches();
		}
	}
	return $out;
}

/** @return array<int,array> Recent import runs, newest first. */
function certificate_generator_import_runs( int $limit = 50 ): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . certificate_generator_import_runs_table() . " WHERE type = 'import' ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
	foreach ( $rows as &$row ) {
		$row['params'] = json_decode( (string) $row['params'], true ) ?: array();
	}
	return $rows;
}

/** Bulk Import → Import history: the runs, one run's flagged rows, and undo. */
function certificate_generator_import_history_page(): void {
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
	}
	$run_id = absint( $_GET['run'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only
	$run    = $run_id ? certificate_generator_import_run_get( $run_id ) : null;
	if ( ! $run ) {
		certificate_generator_import_history_list();
		return;
	}

	$entity = certificate_generator_import_run_entity( $run );
	$edit   = array( 'students' => 'cg-student-edit', 'teachers' => 'cg-teacher-edit', 'schools' => 'cg-school-edit' )[ $entity ];
	$link   = static fn( int $id ) => '<a href="' . esc_url( admin_url( 'admin.php?page=' . $edit . '&id=' . $id ) ) . '">#' . $id . '</a>';
	$form   = static fn( string $inner ) => '<form method="post" style="display:inline">' . wp_nonce_field( 'certificate_generator_import_history_' . $run_id, '_wpnonce', true, false ) . $inner . '</form>';

	if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && check_admin_referer( 'certificate_generator_import_history_' . $run_id ) ) {
		$flag = sanitize_key( wp_unslash( $_POST['cg_flag_action'] ?? '' ) );
		if ( $flag ) {
			$done = certificate_generator_import_resolve_flag( absint( $_POST['cg_item'] ?? 0 ), $flag );
			certificate_generator_ui_notice( is_wp_error( $done ) ? 'error' : 'success', esc_html( is_wp_error( $done ) ? $done->get_error_message() : __( 'Done.', 'certificate-generator' ) ), false );
		}
		if ( isset( $_POST['cg_undo'] ) ) {
			$result = certificate_generator_import_undo( $run_id );
			if ( is_wp_error( $result ) ) {
				certificate_generator_ui_notice( 'error', esc_html( $result->get_error_message() ), false );
			} elseif ( $result['remaining'] ) {
				/* translators: 1: reverted in this batch, 2: still to do */
				certificate_generator_ui_notice( 'info', esc_html( sprintf( __( 'Undoing… %1$d reverted in this batch, %2$d to go.', 'certificate-generator' ), $result['reverted'], $result['remaining'] ) ), false );
				echo '<form method="post" id="cg-undo-continue">';
				wp_nonce_field( 'certificate_generator_import_history_' . $run_id );
				echo '<input type="hidden" name="cg_undo" value="1"><p><button class="button">' . esc_html__( 'Continue now', 'certificate-generator' ) . '</button></p></form>';
				echo '<script>setTimeout(function(){document.getElementById("cg-undo-continue").submit();},500);</script>';
				return;
			} else {
				certificate_generator_ui_notice( 'success', esc_html__( 'Import undone. Records it kept are listed below with the reason.', 'certificate-generator' ), false );
			}
		}
		$run = certificate_generator_import_run_get( $run_id );
	}

	$c = $run['params']['counts'] ?? array();
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=cg-bulk-import&tab=history' ) ) . '">&larr; ' . esc_html__( 'All imports', 'certificate-generator' ) . '</a></p>';
	/* translators: 1: run id, 2: file name, 3: entity */
	echo '<h2>' . esc_html( sprintf( __( 'Import #%1$d — %2$s (%3$s)', 'certificate-generator' ), $run_id, $run['params']['source'] ?? '', $entity ) ) . '</h2>';
	echo '<p>' . esc_html(
		sprintf(
			/* translators: 1: status, 2: new, 3: updated, 4: flagged, 5: skipped, 6: failed */
			__( 'Status: %1$s · %2$d new, %3$d updated, %4$d flagged, %5$d skipped, %6$d failed.', 'certificate-generator' ),
			'cancelled' === $run['status'] ? __( 'undone', 'certificate-generator' ) : $run['status'],
			$c['new'] ?? 0,
			$c['updated'] ?? 0,
			$c['flagged'] ?? 0,
			$c['skipped'] ?? 0,
			$c['failed'] ?? 0
		)
	) . '</p>';

	$flagged = certificate_generator_import_items( $run_id, 'flagged' );
	if ( $flagged ) {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
		echo '<h3>' . esc_html__( 'Flagged rows (matched an existing record, left unchanged)', 'certificate-generator' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'CSV row', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Existing record', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Differences (existing → file)', 'certificate-generator' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $flagged as $item ) {
			$current = (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $item['record_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
			$diff    = array();
			foreach ( array_diff_key( (array) json_decode( (string) $item['file_values'], true ), array_flip( array( 'created_at', 'updated_at', 'import_source' ) ) ) as $col => $val ) {
				if ( (string) ( $current[ $col ] ?? '' ) !== (string) $val ) {
					$diff[] = esc_html( $col . ': ' . ( $current[ $col ] ?? '∅' ) . ' → ' . $val );
				}
			}
			$buttons = '';
			foreach ( array( 'apply' => __( 'Apply file values', 'certificate-generator' ), 'add' => __( 'Add as new record', 'certificate-generator' ), 'dismiss' => __( 'Dismiss', 'certificate-generator' ) ) as $action => $label ) {
				$buttons .= $form( '<input type="hidden" name="cg_item" value="' . (int) $item['id'] . '"><button class="button" name="cg_flag_action" value="' . esc_attr( $action ) . '">' . esc_html( $label ) . '</button> ' );
			}
			printf( '<tr><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>', (int) $item['line'], $current ? $link( (int) $item['record_id'] ) : esc_html__( '(deleted)', 'certificate-generator' ), $diff ? implode( '<br>', $diff ) : esc_html__( 'no differences', 'certificate-generator' ), $buttons ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
		}
		echo '</tbody></table>';
	}

	if ( in_array( $run['status'], array( 'done', 'running', 'rolling_back' ), true ) ) {
		echo '<h3>' . esc_html__( 'Undo this import', 'certificate-generator' ) . '</h3>';
		$plan = certificate_generator_import_undo( $run_id, true );
		if ( ! is_wp_error( $plan ) ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: records reverted, 2: records kept */
					__( 'Undo would revert %1$d record(s) (delete the rows this import created, restore the fields it changed) and keep %2$d that changed after the import:', 'certificate-generator' ),
					$plan['reverted'],
					count( $plan['skipped'] )
				)
			) . '</p>';
			if ( $plan['skipped'] ) {
				echo '<ul style="list-style:disc;margin-left:20px">';
				foreach ( array_slice( $plan['skipped'], 0, 100 ) as $s ) {
					echo '<li>' . $link( $s['record_id'] ) . ' — ' . esc_html( $s['note'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link
				}
				echo '</ul>';
			}
			if ( $plan['reverted'] ) {
				echo $form( '<button class="button button-primary" name="cg_undo" value="1" data-cg-confirm="' . esc_attr__( 'Undo this import? Created records are deleted and changed fields restored. Records changed since are kept.', 'certificate-generator' ) . '">' . esc_html__( 'Undo import', 'certificate-generator' ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
			}
		}
	}

	$kept = certificate_generator_import_items( $run_id, 'skipped', 500 );
	if ( $kept ) {
		echo '<h3>' . esc_html__( 'Kept by the undo', 'certificate-generator' ) . '</h3><ul style="list-style:disc;margin-left:20px">';
		foreach ( $kept as $s ) {
			echo '<li>' . $link( (int) $s['record_id'] ) . ' — ' . esc_html( (string) $s['note'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $link
		}
		echo '</ul>';
	}
}

function certificate_generator_import_history_list(): void {
	$runs = certificate_generator_import_runs();
	echo '<p>' . esc_html__( 'Every CSV import of students, teachers or schools. Open one to review flagged rows or undo it.', 'certificate-generator' ) . '</p>';
	echo '<table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'File', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Type', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Result', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Status', 'certificate-generator' ) . '</th><th>' . esc_html__( 'By', 'certificate-generator' ) . '</th><th>' . esc_html__( 'Date (UTC)', 'certificate-generator' ) . '</th></tr></thead><tbody>';
	if ( ! $runs ) {
		echo '<tr><td colspan="7">' . esc_html__( 'No imports recorded yet.', 'certificate-generator' ) . '</td></tr>';
	}
	foreach ( $runs as $run ) {
		$c = $run['params']['counts'] ?? array();
		printf(
			'<tr><td><a href="%s">%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( admin_url( 'admin.php?page=cg-bulk-import&tab=history&run=' . (int) $run['id'] ) ),
			(int) $run['id'],
			esc_html( (string) ( $run['params']['source'] ?? '' ) ),
			esc_html( ucfirst( (string) ( $run['params']['entity'] ?? '' ) ) ),
			/* translators: 1: new, 2: updated, 3: flagged */
			esc_html( sprintf( __( '%1$d new, %2$d updated, %3$d flagged', 'certificate-generator' ), $c['new'] ?? 0, $c['updated'] ?? 0, $c['flagged'] ?? 0 ) ),
			esc_html( 'cancelled' === $run['status'] ? __( 'undone', 'certificate-generator' ) : (string) $run['status'] ),
			esc_html( (string) ( get_userdata( (int) $run['created_by'] )->display_name ?? '—' ) ),
			esc_html( (string) $run['created_at'] )
		);
	}
	echo '</tbody></table>';
}
