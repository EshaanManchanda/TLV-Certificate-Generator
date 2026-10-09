<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Base migration class.
 */
abstract class Migration {

	abstract public function up(): void;
	abstract public function down(): void;
	abstract public function get_version(): string;

	protected function run_sql( string $sql ): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	protected function table_exists( string $table ): bool {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->prefix . $table
			)
		) === $wpdb->prefix . $table;
	}

	protected function column_exists( string $table, string $column ): bool {
		global $wpdb;
		return in_array( $column, $wpdb->get_col( "DESCRIBE {$wpdb->prefix}{$table}", 0 ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	protected function index_exists( string $table, string $index ): bool {
		global $wpdb;
		$indexes = $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}{$table} WHERE Key_name != 'PRIMARY'", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		return in_array( $index, $indexes, true );
	}

	protected function add_column( string $table, string $column, string $definition, string $after = '' ): void {
		global $wpdb;
		$full_table = $wpdb->prefix . $table;
		$sql        = "ALTER TABLE {$full_table} ADD COLUMN {$column} {$definition}";
		if ( $after !== '' ) {
			$sql .= " AFTER {$after}";
		}
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	protected function add_index( string $table, string $index, string $columns ): void {
		global $wpdb;
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}{$table} ADD INDEX {$index} ({$columns})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	protected function drop_column( string $table, string $column ): void {
		global $wpdb;
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}{$table} DROP COLUMN {$column}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	protected function drop_index( string $table, string $index ): void {
		global $wpdb;
		$wpdb->query( "ALTER TABLE {$wpdb->prefix}{$table} DROP INDEX {$index}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}
}
