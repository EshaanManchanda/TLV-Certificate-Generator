<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CertificateGenerator_Migrator {
	public static function run() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'certificate_generator';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			$sql = "CREATE TABLE $table_name (
              id mediumint(9) NOT NULL AUTO_INCREMENT,
              student_name varchar(255) NOT NULL,
              certificate_data text NOT NULL,
              email varchar(255) NOT NULL DEFAULT '',
              pdf_path varchar(1000) NOT NULL DEFAULT '',
              created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
              issued_at datetime NULL,
              expires_at datetime NULL,
              updated_at datetime NULL,
              generated_via enum('manual','bulk','api','automatic') DEFAULT 'manual',
              serial_number varchar(50) NULL,
              certificate_type varchar(100) NULL,
              revoked_at datetime NULL,
              revoked_reason varchar(255) NULL,
              PRIMARY KEY (id),
              INDEX idx_email (email),
              INDEX idx_issued_at (issued_at),
              INDEX idx_expires_at (expires_at),
              INDEX idx_serial_number (serial_number),
              INDEX idx_certificate_type (certificate_type)
            ) $charset_collate;";
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		} else {
			$columns       = $wpdb->get_col( "DESCRIBE $table_name", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$alter_queries = array();

			if ( ! in_array( 'email', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN email varchar(255) NOT NULL DEFAULT '' AFTER certificate_data";
			}
			if ( ! in_array( 'pdf_path', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN pdf_path varchar(1000) NOT NULL DEFAULT '' AFTER email";
			}
			if ( ! in_array( 'issued_at', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN issued_at datetime NULL AFTER created_at";
			}
			if ( ! in_array( 'expires_at', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN expires_at datetime NULL AFTER issued_at";
			}
			if ( ! in_array( 'updated_at', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN updated_at datetime NULL AFTER expires_at";
			}
			if ( ! in_array( 'generated_via', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN generated_via enum('manual','bulk','api','automatic') DEFAULT 'manual' AFTER updated_at";
			}
			if ( ! in_array( 'serial_number', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN serial_number varchar(50) NULL AFTER updated_at";
			}
			if ( ! in_array( 'certificate_type', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN certificate_type varchar(100) NULL AFTER serial_number";
			}
			if ( ! in_array( 'revoked_at', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN revoked_at datetime NULL AFTER certificate_type";
			}
			if ( ! in_array( 'revoked_reason', $columns ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD COLUMN revoked_reason varchar(255) NULL AFTER revoked_at";
			}

			$indexes = $wpdb->get_col( "SHOW INDEX FROM $table_name WHERE Key_name != 'PRIMARY'", 2 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			if ( ! in_array( 'idx_email', $indexes ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD INDEX idx_email (email)";
			}
			if ( ! in_array( 'idx_issued_at', $indexes ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD INDEX idx_issued_at (issued_at)";
			}
			if ( ! in_array( 'idx_expires_at', $indexes ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD INDEX idx_expires_at (expires_at)";
			}
			if ( ! in_array( 'idx_serial_number', $indexes ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD INDEX idx_serial_number (serial_number)";
			}
			if ( ! in_array( 'idx_certificate_type', $indexes ) ) {
				$alter_queries[] = "ALTER TABLE $table_name ADD INDEX idx_certificate_type (certificate_type)";
			}

			foreach ( $alter_queries as $query ) {
				$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			}
		}

		self::migrate_existing_data( $table_name );
	}

	public static function migrate_existing_data( $table_name ) {
		global $wpdb;
		$wpdb->query( "UPDATE $table_name SET issued_at = created_at, updated_at = created_at WHERE issued_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$wpdb->query( "UPDATE $table_name SET generated_via = 'manual' WHERE generated_via IS NULL OR generated_via = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

		// Backfill email from certificate_data JSON for existing rows.
		$rows       = $wpdb->get_results( "SELECT id, certificate_data FROM $table_name WHERE email = '' AND certificate_data != ''", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$backfilled = 0;
		foreach ( $rows as $row ) {
			$data = json_decode( $row['certificate_data'], true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$email = '';
			foreach ( array( 'email', 'student_email', 'teacher_email', 'school_email', 'recipient_email', 'email_address' ) as $key ) {
				if ( ! empty( $data[ $key ] ) && is_email( $data[ $key ] ) ) {
					$email = $data[ $key ];
					break; }
			}
			if ( $email ) {
				$wpdb->update( $table_name, array( 'email' => sanitize_email( $email ) ), array( 'id' => $row['id'] ), array( '%s' ), array( '%d' ) );
				++$backfilled;
			}
		}
		return $backfilled;
	}
}

add_action(
	'wp_ajax_certificate_generator_run_email_backfill',
	function () {
		check_ajax_referer( 'certificate_generator_run_email_backfill', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_generator';
		$backfilled = CertificateGenerator_Migrator::migrate_existing_data( $table_name );
		$remaining  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE email = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		wp_send_json_success(
			array(
				'backfilled' => $backfilled,
				'remaining'  => $remaining,
				'message'    => "Backfilled {$backfilled} records. {$remaining} records still missing email.",
			)
		);
	}
);
