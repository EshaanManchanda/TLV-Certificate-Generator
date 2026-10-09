<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 005 — Add badge-image columns.
 *
 * badge_template_url on certificate_templates: optional per-template badge
 * background; when set, BadgeGenerationListener renders a companion badge PNG
 * alongside the certificate PDF. badge_path on certificates: where that PNG
 * was written, once generated.
 */
class Migration005_AddBadgeColumns extends Migration {

	public function get_version(): string {
		return '005';
	}

	public function up(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		$templates_table = $prefix . 'certificate_templates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates_table ) ) === $templates_table ) {
			$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$templates_table` LIKE 'badge_template_url'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE `$templates_table` ADD COLUMN `badge_template_url` VARCHAR(500) NULL DEFAULT NULL AFTER `template_url`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		$certificates_table = $prefix . 'certificates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $certificates_table ) ) === $certificates_table ) {
			$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$certificates_table` LIKE 'badge_path'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( empty( $existing ) ) {
				$wpdb->query( "ALTER TABLE `$certificates_table` ADD COLUMN `badge_path` VARCHAR(500) NULL DEFAULT NULL AFTER `pdf_url`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	}

	public function down(): void {
		global $wpdb;

		$prefix = $wpdb->prefix . 'cg_';

		$templates_table = $prefix . 'certificate_templates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $templates_table ) ) === $templates_table ) {
			$wpdb->query( "ALTER TABLE `$templates_table` DROP COLUMN IF EXISTS `badge_template_url`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$certificates_table = $prefix . 'certificates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $certificates_table ) ) === $certificates_table ) {
			$wpdb->query( "ALTER TABLE `$certificates_table` DROP COLUMN IF EXISTS `badge_path`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
