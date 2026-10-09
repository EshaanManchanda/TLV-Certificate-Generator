<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 003 — Backfill year column on students, teachers, schools from issue_date.
 *
 * The year column already exists in CustomTables schema but is never populated.
 * This migration ensures older installs have the column and sets year = YEAR(issue_date)
 * for all existing rows where year is null/zero.
 */
class Migration003_BackfillYear extends Migration {

	public function get_version(): string {
		return '003';
	}

	public function up(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		foreach ( array( 'students', 'teachers', 'schools' ) as $t ) {
			$table = $prefix . $t;

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				continue;
			}

			// Ensure column exists — older installs created before CustomTables added it.
			$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'year'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `year` YEAR NULL DEFAULT NULL AFTER `issue_date`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `$table` ADD INDEX `idx_year` (`year`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			// Backfill year = YEAR(issue_date) for rows where year is missing.
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$wpdb->query(
				"UPDATE `$table` SET `year` = YEAR(`issue_date`)
				 WHERE `issue_date` IS NOT NULL
				   AND (`year` IS NULL OR `year` = 0)" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public function down(): void {
		// No rollback — backfill is additive; column predates this migration.
	}
}
