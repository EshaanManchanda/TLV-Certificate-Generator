<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 002 — Add send_email opt-out column to students, teachers, schools tables.
 *
 * send_email = 1 (default): recipient included in bulk sends.
 * send_email = 0: recipient skipped by bulk sends; admin can still send individually.
 */
class Migration002_AddSendEmailColumn extends Migration {

	public function get_version(): string {
		return '002';
	}

	public function up(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools' ) as $t ) {
			$table = $prefix . $t;

			// Skip if table doesn't exist yet (fresh install — CustomTables::create_all handles it).
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			// Idempotent: only add if column is missing.
			$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'send_email'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `send_email` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	public function down(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools' ) as $t ) {
			$table = $prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `send_email`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}
}
