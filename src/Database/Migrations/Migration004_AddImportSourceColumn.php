<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 004 — Add import_source provenance column to students, teachers, schools tables.
 *
 * Populated with the uploaded CSV filename on Bulk Import (see includes/Services/bulk-import.php),
 * or a fixed tag like 'tutor_lms' when a record is auto-created by an LMS integration listener.
 * Powers the Bulk Send "Source" filter.
 */
class Migration004_AddImportSourceColumn extends Migration {

	public function get_version(): string {
		return '004';
	}

	public function up(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools' ) as $t ) {
			$table = $prefix . $t;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'import_source'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `import_source` VARCHAR(191) NULL DEFAULT NULL AFTER `send_email`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `$table` ADD INDEX `idx_import_source` (`import_source`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	public function down(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools' ) as $t ) {
			$table = $prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `import_source`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}
}
