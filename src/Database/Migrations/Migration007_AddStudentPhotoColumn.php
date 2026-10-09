<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 007 — Add photo_url column to students table.
 *
 * Backs the new "photo" certificate field type, which renders a per-student
 * photo the same way the existing "image" field type renders a fixed
 * template-level image (see certificate-search.php).
 */
class Migration007_AddStudentPhotoColumn extends Migration {

	public function get_version(): string {
		return '007';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cg_students';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'photo_url'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $existing ) ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `photo_url` VARCHAR(500) DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public function down(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cg_students';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `photo_url`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
