<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

use CertificateGenerator\Services\VerificationCodeService;

/**
 * Migration 011 — verification_code on both certificate tables, backfilled from each
 * row's serial (see VerificationCodeService). Public verification looks certificates
 * up by this code; a serial alone only gets a masked answer.
 */
class Migration011_VerificationCode extends Migration {

	private const TABLES = array( 'certificate_generator', 'cg_certificates' );

	public function get_version(): string {
		return '011';
	}

	public function up(): void {
		foreach ( self::TABLES as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			if ( ! $this->column_exists( $table, 'verification_code' ) ) {
				$this->add_column( $table, 'verification_code', 'CHAR(12) NULL DEFAULT NULL', 'serial_number' );
			}
			if ( ! $this->index_exists( $table, 'idx_verification_code' ) ) {
				$this->add_index( $table, 'idx_verification_code', 'verification_code' );
			}
			self::backfill( $table );
		}
	}

	/**
	 * Fill missing codes in batches of 500.
	 * ponytail: runs to completion in one request; fine up to ~100k rows, chunk across requests beyond that.
	 */
	public static function backfill( string $table ): void {
		global $wpdb;
		$full = $wpdb->prefix . $table;
		do {
			$serials = $wpdb->get_col( "SELECT DISTINCT serial_number FROM `$full` WHERE verification_code IS NULL AND serial_number IS NOT NULL AND serial_number <> '' LIMIT 500" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant
			foreach ( $serials as $serial ) {
				$wpdb->update( $full, array( 'verification_code' => VerificationCodeService::for_serial( (string) $serial ) ), array( 'serial_number' => $serial ), array( '%s' ), array( '%s' ) );
			}
		} while ( count( $serials ) === 500 );
	}

	public function down(): void {
		foreach ( self::TABLES as $table ) {
			if ( $this->table_exists( $table ) && $this->column_exists( $table, 'verification_code' ) ) {
				$this->drop_column( $table, 'verification_code' );
			}
		}
	}
}
