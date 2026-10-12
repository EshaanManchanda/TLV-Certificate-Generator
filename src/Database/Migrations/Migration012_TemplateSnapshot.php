<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 012 — layout_snapshot + revision on wp_cg_certificates.
 *
 * A certificate stores the template row it was issued with, so later template edits
 * never change certificates already issued; a reissue takes a new snapshot and bumps
 * the revision. Existing certificates get no snapshot and keep following their
 * template until they are reissued.
 */
class Migration012_TemplateSnapshot extends Migration {

	public function get_version(): string {
		return '012';
	}

	public function up(): void {
		if ( ! $this->table_exists( 'cg_certificates' ) ) {
			return;
		}
		if ( ! $this->column_exists( 'cg_certificates', 'revision' ) ) {
			$this->add_column( 'cg_certificates', 'revision', 'INT UNSIGNED NOT NULL DEFAULT 0', 'verification_code' );
		}
		if ( ! $this->column_exists( 'cg_certificates', 'layout_snapshot' ) ) {
			$this->add_column( 'cg_certificates', 'layout_snapshot', 'LONGTEXT NULL DEFAULT NULL', 'certificate_data' );
		}
	}

	public function down(): void {
		foreach ( array( 'revision', 'layout_snapshot' ) as $column ) {
			if ( $this->table_exists( 'cg_certificates' ) && $this->column_exists( 'cg_certificates', $column ) ) {
				$this->drop_column( 'cg_certificates', $column );
			}
		}
	}
}
