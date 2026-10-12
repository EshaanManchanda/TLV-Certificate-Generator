<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 013 — show_name on both certificate tables.
 *
 * The recipient chooses on their My Certificates page whether verification shows their name
 * (1, the default) or only the certificate's status and dates (0).
 */
class Migration013_ShowName extends Migration {

	private const TABLES = array( 'certificate_generator', 'cg_certificates' );

	public function get_version(): string {
		return '013';
	}

	public function up(): void {
		foreach ( self::TABLES as $table ) {
			if ( $this->table_exists( $table ) && ! $this->column_exists( $table, 'show_name' ) ) {
				$this->add_column( $table, 'show_name', 'TINYINT(1) NOT NULL DEFAULT 1', 'serial_number' );
			}
		}
	}

	public function down(): void {
		foreach ( self::TABLES as $table ) {
			if ( $this->table_exists( $table ) && $this->column_exists( $table, 'show_name' ) ) {
				$this->drop_column( $table, 'show_name' );
			}
		}
	}
}
