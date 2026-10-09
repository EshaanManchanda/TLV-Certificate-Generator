<?php
declare(strict_types=1);

namespace CertificateGenerator\Database;

/**
 * Complete CPT to SQL Migration Handler.
 * Moves all data from wp_posts/wp_postmeta to custom tables.
 * Safe to run multiple times. Does NOT delete CPT data until verified.
 */
class CptToSqlMigration {

	private CustomTables $tables;
	private array $stats = array();

	public function __construct() {
		$this->tables = CustomTables::instance();
		$this->stats  = array(
			'students'     => 0,
			'teachers'     => 0,
			'schools'      => 0,
			'templates'    => 0,
			'certificates' => 0,
			'email_logs'   => 0,
		);
	}

	/**
	 * Run complete migration.
	 */
	public function run(): array {
		global $wpdb;

		// 1. Migrate Schools
		$this->migrate_schools();

		// 2. Migrate Students (links to schools by name)
		$this->migrate_students();

		// 3. Migrate Teachers (links to schools by name)
		$this->migrate_teachers();

		// 5. Migrate Existing Certificates
		$this->migrate_certificates();

		// 6. Migrate Email Logs
		$this->migrate_email_logs();

		// 7. Update migration flag
		update_option( 'cg_cpt_to_sql_migration_completed', current_time( 'mysql' ) );
		update_option( 'cg_cpt_to_sql_migration_stats', $this->stats );

		return $this->stats;
	}

	private function migrate_schools(): void {
		global $wpdb;
		$table = $this->tables->get_table( 'schools' );
		if ( empty( $table ) ) {
			return;
		}

		$schools = get_posts(
			array(
				'post_type'      => 'schools',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		foreach ( $schools as $post ) {
			$meta  = get_post_meta( $post->ID );
			$extra = $this->extract_extra_fields( 'schools', $meta );

			$wpdb->replace(
				$table,
				array(
					'wp_post_id'     => $post->ID,
					'school_name'    => $post->post_title,
					'address'        => $meta['school_address'][0] ?? '',
					'city'           => $meta['school_city'][0] ?? '',
					'state'          => $meta['school_state'][0] ?? '',
					'country'        => $meta['school_country'][0] ?? '',
					'postal_code'    => $meta['school_postal_code'][0] ?? '',
					'phone'          => $meta['school_phone'][0] ?? '',
					'email'          => $meta['school_email'][0] ?? '',
					'website'        => $meta['school_website'][0] ?? '',
					'principal_name' => $meta['school_principal'][0] ?? '',
					'status'         => $post->post_status === 'publish' ? 'active' : 'inactive',
					'extra_fields'   => ! empty( $extra ) ? wp_json_encode( $extra ) : null,
					'created_at'     => $post->post_date,
					'updated_at'     => $post->post_modified,
				)
			);
			++$this->stats['schools'];
		}
	}

	private function migrate_students(): void {
		global $wpdb;
		$table = $this->tables->get_table( 'students' );
		if ( empty( $table ) ) {
			return;
		}

		$students = get_posts(
			array(
				'post_type'      => 'students',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		foreach ( $students as $post ) {
			$meta  = get_post_meta( $post->ID );
			$extra = $this->extract_extra_fields( 'students', $meta );

			$school_name = $meta['school_name'][0] ?? '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$school_id   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->tables->get_table('schools')} WHERE school_name = %s LIMIT 1",
					$school_name
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$wpdb->replace(
				$table,
				array(
					'wp_post_id'      => $post->ID,
					'student_name'    => $post->post_title,
					'email'           => $meta['email'][0] ?? '',
					'phone'           => $meta['phone'][0] ?? '',
					'school_id'       => $school_id ?: null,
					'school_name'     => $school_name,
					'enrollment_date' => $meta['enrollment_date'][0] ?? null,
					'graduation_date' => $meta['graduation_date'][0] ?? null,
					'status'          => $post->post_status === 'publish' ? 'active' : 'inactive',
					'extra_fields'    => ! empty( $extra ) ? wp_json_encode( $extra ) : null,
					'created_at'      => $post->post_date,
					'updated_at'      => $post->post_modified,
				)
			);
			++$this->stats['students'];
		}
	}

	private function migrate_teachers(): void {
		global $wpdb;
		$table = $this->tables->get_table( 'teachers' );
		if ( empty( $table ) ) {
			return;
		}

		$teachers = get_posts(
			array(
				'post_type'      => 'teachers',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		foreach ( $teachers as $post ) {
			$meta  = get_post_meta( $post->ID );
			$extra = $this->extract_extra_fields( 'teachers', $meta );

			$school_name = $meta['school_name'][0] ?? '';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$school_id   = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$this->tables->get_table('schools')} WHERE school_name = %s LIMIT 1",
					$school_name
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$wpdb->replace(
				$table,
				array(
					'wp_post_id'   => $post->ID,
					'teacher_name' => $post->post_title,
					'email'        => $meta['email'][0] ?? '',
					'phone'        => $meta['phone'][0] ?? '',
					'school_id'    => $school_id ?: null,
					'school_name'  => $school_name,
					'department'   => $meta['department'][0] ?? '',
					'hire_date'    => $meta['hire_date'][0] ?? null,
					'status'       => $post->post_status === 'publish' ? 'active' : 'inactive',
					'extra_fields' => ! empty( $extra ) ? wp_json_encode( $extra ) : null,
					'created_at'   => $post->post_date,
					'updated_at'   => $post->post_modified,
				)
			);
			++$this->stats['teachers'];
		}
	}

	private function migrate_templates(): void {
		global $wpdb;
		$table = $this->tables->get_table( 'certificate_templates' );
		if ( empty( $table ) ) {
			return;
		}

		$templates = get_posts(
			array(
				'post_type'      => 'certificates',
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		foreach ( $templates as $post ) {
			$meta  = get_post_meta( $post->ID );
			$extra = $this->extract_extra_fields( 'templates', $meta );

			$wpdb->replace(
				$table,
				array(
					'wp_post_id'               => $post->ID,
					'template_name'            => $post->post_title,
					'certificate_type'         => $meta['certificate_type'][0] ?? '',
					'event_date'               => $meta['event_date'][0] ?? null,
					'template_url'             => $meta['template_url'][0] ?? '',
					'orientation'              => $meta['template_orientation'][0] ?? 'landscape',
					'page_size'                => $meta['certificate_page_size'][0] ?? 'A4',
					'font_style'               => $meta['font_style'][0] ?? 'helvetica',
					'font_size'                => (int) ( $meta['font_size'][0] ?? 12 ),
					'font_color'               => $meta['font_color'][0] ?? '#000000',
					'qr_enabled'               => (int) ( $meta['qr_enabled'][0] ?? 0 ),
					'qr_size'                  => (int) ( $meta['qr_size'][0] ?? 15 ),
					'qr_position_x'            => (float) ( $meta['qr_position_x'][0] ?? 250.00 ),
					'qr_position_y'            => (float) ( $meta['qr_position_y'][0] ?? 180.00 ),
					'qr_error_correction'      => $meta['qr_error_correction'][0] ?? 'L',
					'qr_data_fields'           => $meta['qr_data_fields'][0] ?? null,
					'serial_number_display'    => (int) ( $meta['serial_number_display'][0] ?? 0 ),
					'serial_number_position_x' => (float) ( $meta['serial_number_position_x'][0] ?? 105.00 ),
					'serial_number_position_y' => (float) ( $meta['serial_number_position_y'][0] ?? 200.00 ),
					'serial_number_font_size'  => (int) ( $meta['serial_number_font_size'][0] ?? 10 ),
					'expiration_period_unit'   => $meta['expiration_period_unit'][0] ?? 'never',
					'expiration_period_value'  => (int) ( $meta['expiration_period_value'][0] ?? 0 ),
					'status'                   => $post->post_status === 'publish' ? 'published' : 'draft',
					'extra_fields'             => ! empty( $extra ) ? wp_json_encode( $extra ) : null,
					'created_at'               => $post->post_date,
					'updated_at'               => $post->post_modified,
				)
			);
			++$this->stats['templates'];
		}
	}

	private function migrate_certificates(): void {
		global $wpdb;
		$table = $this->tables->get_table( 'certificates' );
		if ( empty( $table ) ) {
			return;
		}

		$old_table  = $wpdb->prefix . 'certificate_generator';
		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
		if ( ! $old_exists ) {
			return;
		}

		$certs = $wpdb->get_results( "SELECT * FROM $old_table", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		foreach ( $certs as $cert ) {
			$cert_data = maybe_unserialize( $cert['certificate_data'] );
			$extra     = is_array( $cert_data ) ? $cert_data : array();

			$wpdb->replace(
				$table,
				array(
					'recipient_name'   => $cert['student_name'],
					'certificate_type' => $extra['certificate_type'] ?? '',
					'serial_number'    => $cert['serial_number'] ?? null,
					'issued_at'        => $cert['issued_at'] ?? $cert['created_at'],
					'expires_at'       => $cert['expires_at'] ?? null,
					'generated_via'    => $cert['generated_via'] ?? 'manual',
					'certificate_data' => ! empty( $extra ) ? wp_json_encode( $extra ) : null,
					'status'           => 'generated',
					'created_at'       => $cert['created_at'],
					'updated_at'       => $cert['updated_at'] ?? $cert['created_at'],
				)
			);
			++$this->stats['certificates'];
		}
	}

	private function migrate_email_logs(): void {
		global $wpdb;
		$table = $this->tables->get_table( 'email_logs' );
		if ( empty( $table ) ) {
			return;
		}

		$old_table  = $wpdb->prefix . 'cert_email_logs';
		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table;
		if ( ! $old_exists ) {
			return;
		}

		$logs = $wpdb->get_results( "SELECT * FROM $old_table", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		foreach ( $logs as $log ) {
			$wpdb->replace(
				$table,
				array(
					'recipient_email' => $log['recipient_email'] ?? '',
					'recipient_name'  => $log['recipient_name'] ?? '',
					'subject'         => $log['subject'] ?? '',
					'status'          => $log['status'] ?? 'sent',
					'error_message'   => $log['error_message'] ?? null,
					'sent_at'         => $log['sent_at'] ?? null,
					'created_at'      => $log['created_at'] ?? current_time( 'mysql' ),
				)
			);
			++$this->stats['email_logs'];
		}
	}

	/**
	 * Extract extra fields from post meta, excluding known core keys.
	 */
	private function extract_extra_fields( string $entity_type, array $meta ): array {
		$core_keys = array(
			'schools'   => array( 'school_name', 'school_address', 'school_city', 'school_state', 'school_country', 'school_postal_code', 'school_phone', 'school_email', 'school_website', 'school_principal', '_edit_last', '_edit_lock' ),
			'students'  => array( 'student_name', 'email', 'phone', 'school_name', 'enrollment_date', 'graduation_date', '_edit_last', '_edit_lock' ),
			'teachers'  => array( 'teacher_name', 'email', 'phone', 'school_name', 'department', 'hire_date', '_edit_last', '_edit_lock' ),
			'templates' => array( 'certificate_type', 'event_date', 'template_url', 'template_orientation', 'certificate_page_size', 'font_style', 'font_size', 'font_color', 'qr_enabled', 'qr_size', 'qr_position_x', 'qr_position_y', 'qr_error_correction', 'qr_data_fields', 'serial_number_display', 'serial_number_position_x', 'serial_number_position_y', 'serial_number_font_size', 'expiration_period_unit', 'expiration_period_value', '_edit_last', '_edit_lock' ),
		);

		$known = $core_keys[ $entity_type ] ?? array();
		$extra = array();

		foreach ( $meta as $key => $values ) {
			if ( ! in_array( $key, $known, true ) && strpos( $key, '_' ) !== 0 ) {
				$clean_key = preg_replace( '/^field_/', '', $key );
				if ( ! empty( $values[0] ) ) {
					$extra[ $clean_key ] = $values[0];
				}
			}
		}

		return $extra;
	}

	/**
	 * Normalize issue_date from d-m-Y to Y-m-d in SQL tables.
	 * Fixes "No matching template" errors caused by date format mismatches.
	 * Uses DateHelper for robust parsing.
	 */
	public static function normalize_issue_dates(): array {
		global $wpdb;
		$tables  = CustomTables::instance();
		$results = array();

		if ( ! class_exists( '\CertificateGenerator\Helpers\DateHelper' ) ) {
			return $results;
		}

		$entities = array( 'students', 'teachers', 'schools', 'certificates' );
		foreach ( $entities as $entity ) {
			$table = $tables->get_table( $entity );
			if ( empty( $table ) ) {
				continue;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$rows = $wpdb->get_results(
				"SELECT id, issue_date FROM $table WHERE issue_date IS NOT NULL AND issue_date != ''" // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration over our own table; no user input
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$count = 0;
			foreach ( $rows as $row ) {
				$current = $row->issue_date ?? '';

				// Already in Y-m-d format - skip
				if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $current ) ) {
					continue;
				}

				// Normalize via DateHelper
				$normalized = \CertificateGenerator\Helpers\DateHelper::to_storage( $current );
				if ( $normalized && $normalized !== $current ) {
					$wpdb->update( $table, array( 'issue_date' => $normalized ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
					++$count;
				}
			}

			$results[ $entity ] = $count;
		}

		return $results;
	}
}
