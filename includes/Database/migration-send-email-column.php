<?php
/**
 * Migration: add send_email column to cg_students, cg_teachers, cg_schools
 *
 * Runs once on plugins_loaded. Idempotent — checks SHOW COLUMNS before ALTER.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'cg_migrate_send_email_column', 20 );

function cg_migrate_send_email_column() {
	if ( get_option( 'cg_migration_send_email_column_done' ) ) {
		return;
	}

	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return;
	}

	global $wpdb;
	$tables  = \CertificateGenerator\Database\CustomTables::instance();
	$targets = array( 'students', 'teachers', 'schools' );

	foreach ( $targets as $entity ) {
		$tbl = $tables->get_table( $entity );
		if ( ! $tbl ) {
			continue;
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
			continue;
		}

		$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM `' . $tbl . '` LIKE %s', 'send_email' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $col ) {
			continue; // already exists
		}

		$wpdb->query( "ALTER TABLE `$tbl` ADD COLUMN send_email TINYINT(1) NOT NULL DEFAULT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	update_option( 'cg_migration_send_email_column_done', true );
}
