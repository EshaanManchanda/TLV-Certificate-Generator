<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 014 — external_id (an LMS / CRM / sheet id) on students, teachers and schools,
 * indexed, so integrations can find a record by their own id. New installs get it from
 * CustomTables; dbDelta doesn't alter these tables (they're created IF NOT EXISTS).
 */
class Migration014_ExternalId extends Migration {

	private const TABLES = array( 'cg_students', 'cg_teachers', 'cg_schools' );

	public function get_version(): string {
		return '014';
	}

	public function up(): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
			if ( ! $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'external_id'" ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `external_id` VARCHAR(191) DEFAULT NULL" );
			}
			if ( ! $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'idx_external_id'" ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD INDEX `idx_external_id` (`external_id`)" );
			}
			// phpcs:enable
		}
	}

	public function down(): void {
		global $wpdb;
		foreach ( self::TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `external_id`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}
}
