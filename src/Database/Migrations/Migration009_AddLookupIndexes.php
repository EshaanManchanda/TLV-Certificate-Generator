<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 009 — composite indexes for the multi-event lookups.
 *
 * Imports, serial storage and email resolution match entity rows on
 * email + certificate_type + issue_date, templates on certificate_type +
 * event_date, and serial reuse on student_name + certificate_type. Each
 * column had only a single-column index. Prefix lengths keep the keys
 * within older MySQL index-size limits.
 */
class Migration009_AddLookupIndexes extends Migration {

	/** table (without prefix) => [ index name => column list ] */
	private const INDEXES = array(
		'cg_students'              => array( 'idx_lookup' => '`email`(100), `certificate_type`(100), `issue_date`' ),
		'cg_teachers'              => array( 'idx_lookup' => '`email`(100), `certificate_type`(100), `issue_date`' ),
		'cg_schools'               => array( 'idx_lookup' => '`email`(100), `certificate_type`(100), `issue_date`' ),
		'cg_certificate_templates' => array( 'idx_type_date' => '`certificate_type`, `event_date`' ),
		'certificate_generator'    => array( 'idx_name_type' => '`student_name`(100), `certificate_type`' ),
		// Earlier queue-columns migration tried (recipient_email, created_at) and failed:
		// the column is sent_at. Its "done" flag means it never retries, so add it here.
		'cert_email_logs'          => array( 'idx_email_created' => '`recipient_email`(100), `sent_at`' ),
	);

	public function get_version(): string {
		return '009';
	}

	public function up(): void {
		global $wpdb;

		foreach ( self::INDEXES as $table => $indexes ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			foreach ( $indexes as $name => $columns ) {
				if ( ! $this->index_exists( $table, $name ) ) {
					$wpdb->query( "ALTER TABLE `{$wpdb->prefix}{$table}` ADD INDEX `$name` ($columns)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
		}
	}

	public function down(): void {
		global $wpdb;

		foreach ( self::INDEXES as $table => $indexes ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			foreach ( array_keys( $indexes ) as $name ) {
				if ( $this->index_exists( $table, $name ) ) {
					$wpdb->query( "ALTER TABLE `{$wpdb->prefix}{$table}` DROP INDEX `$name`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
		}
	}
}
