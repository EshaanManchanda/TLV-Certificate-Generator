<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 001: Add time-based columns and serial number support.
 */
class Migration001_AddTimeColumns extends Migration {

	public function get_version(): string {
		return '001';
	}

	public function up(): void {
		global $wpdb;
		$table = 'certificate_generator';

		if ( ! $this->table_exists( $table ) ) {
			$charset_collate = $wpdb->get_charset_collate();
			$this->run_sql(
				"CREATE TABLE {$wpdb->prefix}{$table} (
                id mediumint(9) NOT NULL AUTO_INCREMENT,
                student_name varchar(255) NOT NULL,
                certificate_data text NOT NULL,
                created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                issued_at datetime NULL,
                expires_at datetime NULL,
                updated_at datetime NULL,
                generated_via enum('manual','bulk','api','automatic') DEFAULT 'manual',
                serial_number varchar(50) NULL,
                PRIMARY KEY (id),
                INDEX idx_issued_at (issued_at),
                INDEX idx_expires_at (expires_at),
                INDEX idx_serial_number (serial_number)
            ) {$charset_collate};"
			);
			return;
		}

		if ( ! $this->column_exists( $table, 'issued_at' ) ) {
			$this->add_column( $table, 'issued_at', 'datetime NULL', 'created_at' );
		}
		if ( ! $this->column_exists( $table, 'expires_at' ) ) {
			$this->add_column( $table, 'expires_at', 'datetime NULL', 'issued_at' );
		}
		if ( ! $this->column_exists( $table, 'updated_at' ) ) {
			$this->add_column( $table, 'updated_at', 'datetime NULL', 'expires_at' );
		}
		if ( ! $this->column_exists( $table, 'generated_via' ) ) {
			$this->add_column( $table, 'generated_via', "enum('manual','bulk','api','automatic') DEFAULT 'manual'", 'updated_at' );
		}
		if ( ! $this->column_exists( $table, 'serial_number' ) ) {
			$this->add_column( $table, 'serial_number', 'varchar(50) NULL', 'updated_at' );
		}

		if ( ! $this->index_exists( $table, 'idx_issued_at' ) ) {
			$this->add_index( $table, 'idx_issued_at', 'issued_at' );
		}
		if ( ! $this->index_exists( $table, 'idx_expires_at' ) ) {
			$this->add_index( $table, 'idx_expires_at', 'expires_at' );
		}
		if ( ! $this->index_exists( $table, 'idx_serial_number' ) ) {
			$this->add_index( $table, 'idx_serial_number', 'serial_number' );
		}

		$this->migrate_existing_data( $table );
	}

	public function down(): void {
		global $wpdb;
		$table = 'certificate_generator';

		if ( $this->index_exists( $table, 'idx_serial_number' ) ) {
			$this->drop_index( $table, 'idx_serial_number' );
		}
		if ( $this->index_exists( $table, 'idx_expires_at' ) ) {
			$this->drop_index( $table, 'idx_expires_at' );
		}
		if ( $this->index_exists( $table, 'idx_issued_at' ) ) {
			$this->drop_index( $table, 'idx_issued_at' );
		}
		if ( $this->column_exists( $table, 'serial_number' ) ) {
			$this->drop_column( $table, 'serial_number' );
		}
		if ( $this->column_exists( $table, 'generated_via' ) ) {
			$this->drop_column( $table, 'generated_via' );
		}
		if ( $this->column_exists( $table, 'updated_at' ) ) {
			$this->drop_column( $table, 'updated_at' );
		}
		if ( $this->column_exists( $table, 'expires_at' ) ) {
			$this->drop_column( $table, 'expires_at' );
		}
		if ( $this->column_exists( $table, 'issued_at' ) ) {
			$this->drop_column( $table, 'issued_at' );
		}
	}

	private function migrate_existing_data( string $table ): void {
		global $wpdb;

		$wpdb->query( "UPDATE {$wpdb->prefix}{$table} SET issued_at = created_at, updated_at = created_at WHERE issued_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$wpdb->query( "UPDATE {$wpdb->prefix}{$table} SET generated_via = 'manual' WHERE generated_via IS NULL OR generated_via = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}
}
