<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 015 — jobs.params (a generation job's entity / force flag, an import run's
 * settings) and import_run_id on students, teachers and schools, so an import can be listed
 * and undone. The import_items table itself comes from CustomTables (a new table, so
 * CREATE IF NOT EXISTS is enough); these are columns on existing tables, which dbDelta skips.
 */
class Migration015_JobParamsImportRun extends Migration {

	private const ENTITY_TABLES = array( 'cg_students', 'cg_teachers', 'cg_schools' );

	public function get_version(): string {
		return '015';
	}

	private function exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	public function up(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix
		$jobs = $wpdb->prefix . 'cg_jobs';
		if ( $this->exists( $jobs ) && ! $wpdb->get_results( "SHOW COLUMNS FROM `$jobs` LIKE 'params'" ) ) {
			$wpdb->query( "ALTER TABLE `$jobs` ADD COLUMN `params` LONGTEXT DEFAULT NULL AFTER `items`" );
		}
		foreach ( self::ENTITY_TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			if ( ! $this->exists( $table ) ) {
				continue;
			}
			if ( ! $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'import_run_id'" ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `import_run_id` BIGINT UNSIGNED DEFAULT NULL" );
			}
			if ( ! $wpdb->get_results( "SHOW INDEX FROM `$table` WHERE Key_name = 'idx_import_run_id'" ) ) {
				$wpdb->query( "ALTER TABLE `$table` ADD INDEX `idx_import_run_id` (`import_run_id`)" );
			}
		}
		// phpcs:enable
	}

	public function down(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix
		foreach ( self::ENTITY_TABLES as $name ) {
			$table = $wpdb->prefix . $name;
			if ( $this->exists( $table ) ) {
				$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `import_run_id`" );
			}
		}
		$jobs = $wpdb->prefix . 'cg_jobs';
		if ( $this->exists( $jobs ) ) {
			$wpdb->query( "ALTER TABLE `$jobs` DROP COLUMN IF EXISTS `params`" );
		}
		// phpcs:enable
	}
}
