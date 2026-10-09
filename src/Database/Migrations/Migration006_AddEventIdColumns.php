<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 006 — Add event_id column to students, teachers, schools,
 * certificate_templates tables.
 *
 * event_id was added to CustomTables' CREATE TABLE SQL alongside SCHEMA_VERSION
 * 1.2.0, but dbDelta() only re-runs when CustomTables::create_all() is invoked —
 * this migration guarantees the column lands via the same versioned mechanism
 * already used for send_email (002) and badge columns (005), independent of
 * whether that dbDelta path ran.
 */
class Migration006_AddEventIdColumns extends Migration {

	public function get_version(): string {
		return '006';
	}

	public function up(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools', 'certificate_templates' ) as $t ) {
			$table = $prefix . $t;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'event_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `event_id` BIGINT UNSIGNED DEFAULT NULL AFTER `wp_post_id`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$existing_index = $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'idx_event_id'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing_index ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD INDEX `idx_event_id` (`event_id`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	public function down(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools', 'certificate_templates' ) as $t ) {
			$table = $prefix . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "ALTER TABLE `$table` DROP INDEX IF EXISTS `idx_event_id`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `event_id`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}
}
