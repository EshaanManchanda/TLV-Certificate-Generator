<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 008 — Add entity_type column to certificate_templates.
 *
 * Backs explicit, entity-scoped field mapping (see TemplatesPage.php's
 * per-slot field_{N}_name dropdown and certificate-search.php's
 * cg_resolve_template_fields()): a template now declares whether it's for
 * students, teachers, or schools, so the field list it offers matches
 * FieldManager::CORE_FIELDS for that type instead of one flat shared list.
 *
 * Defaults existing rows to 'students' — a value, not a guess about intent —
 * and is non-breaking: templates saved before this migration keep working via
 * the old positional field resolution until explicitly re-saved with field
 * mappings (see cg_resolve_template_fields()'s fallback).
 */
class Migration008_AddTemplateEntityType extends Migration {

	public function get_version(): string {
		return '008';
	}

	public function up(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cg_certificate_templates';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$existing = $wpdb->get_results( "SHOW COLUMNS FROM `$table` LIKE 'entity_type'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $existing ) ) {
			$wpdb->query( "ALTER TABLE `$table` ADD COLUMN `entity_type` ENUM('students','teachers','schools') NOT NULL DEFAULT 'students' AFTER `certificate_type`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public function down(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'cg_certificate_templates';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$wpdb->query( "ALTER TABLE `$table` DROP COLUMN IF EXISTS `entity_type`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
