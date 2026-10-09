<?php
/**
 * Migration: add last_attempt_at to wp_cert_email_queue; add performance indexes
 * to cert_email_logs and cert_email_queue.
 *
 * Idempotent — SHOW COLUMNS / SHOW INDEX guards prevent duplicate ALTER runs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'cg_migrate_queue_columns', 20 );

function cg_migrate_queue_columns() {
	if ( get_option( 'cg_migration_queue_columns_done' ) ) {
		return;
	}

	global $wpdb;
	$queue_table = $wpdb->prefix . 'cert_email_queue';
	$log_table   = $wpdb->prefix . 'cert_email_logs';

	// Only run if the queue table actually exists.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) !== $queue_table ) {
		return;
	}

	// Add last_attempt_at column if missing.
	$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $queue_table . '` LIKE %s', 'last_attempt_at' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( ! $col ) {
		$wpdb->query( "ALTER TABLE `$queue_table` ADD COLUMN last_attempt_at DATETIME NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Index: cert_email_queue (status, updated_at) — used by stale-reclaim + badge query.
	$idx = $wpdb->get_row( "SHOW INDEX FROM `$queue_table` WHERE Key_name = 'idx_status_updated'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $idx ) {
		$wpdb->query( "ALTER TABLE `$queue_table` ADD INDEX idx_status_updated (status, updated_at)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Index: cert_email_logs (recipient_email, sent_at) — the log has no created_at column.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) ) === $log_table ) {
		$idx2 = $wpdb->get_row( "SHOW INDEX FROM `$log_table` WHERE Key_name = 'idx_email_created'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $idx2 ) {
			$wpdb->query( "ALTER TABLE `$log_table` ADD INDEX idx_email_created (recipient_email, sent_at)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	update_option( 'cg_migration_queue_columns_done', true );
}

add_action( 'plugins_loaded', 'cg_migrate_queue_scope_column', 20 );

/**
 * Add wp_cert_email_queue.scope — JSON {entity: [row ids]} naming exactly which
 * certificates a queued email carries. NULL keeps the old "every certificate for
 * this address" behaviour for rows queued outside the filtered Bulk Send.
 */
function cg_migrate_queue_scope_column() {
	if ( get_option( 'cg_migration_queue_scope_done' ) ) {
		return;
	}

	global $wpdb;
	$queue_table = $wpdb->prefix . 'cert_email_queue';

	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue_table ) ) !== $queue_table ) {
		return;
	}

	$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $queue_table . '` LIKE %s', 'scope' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( ! $col ) {
		$wpdb->query( "ALTER TABLE `$queue_table` ADD COLUMN scope LONGTEXT NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	update_option( 'cg_migration_queue_scope_done', true );
}
