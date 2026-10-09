<?php
declare(strict_types=1);

namespace CertificateGenerator\Database;

/**
 * Creates and manages custom database tables for the plugin.
 * Runs safely alongside existing CPT structure - no data is deleted.
 */
class CustomTables {

	// Bump whenever a column/index is added to any create_*_table() method below,
	// so existing installs get dbDelta() re-run (all_tables_exist() alone only
	// catches a fully-missing table, never a stale column on an existing one).
	private const SCHEMA_VERSION = '1.3.1';

	private static ?CustomTables $instance = null;
	private array $tables                  = array();

	/** @var array<string,bool> Per-request cache for table existence checks. */
	private static array $_exists_cache = array();

	public static function instance(): CustomTables {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$prefix = $wpdb->prefix . 'cg_';

		$this->tables = array(
			'students'              => $prefix . 'students',
			'teachers'              => $prefix . 'teachers',
			'schools'               => $prefix . 'schools',
			'certificate_templates' => $prefix . 'certificate_templates',
			'certificates'          => $prefix . 'certificates',
			'email_logs'            => $prefix . 'email_logs',
			'student_certificates'  => $prefix . 'student_certificates',
			'teacher_certificates'  => $prefix . 'teacher_certificates',
			'settings'              => $prefix . 'settings',
			'migrations'            => $prefix . 'migrations',
			'lms_course_map'        => $prefix . 'lms_course_map',
			'lms_tracks'            => $prefix . 'lms_tracks',
			'lms_track_courses'     => $prefix . 'lms_track_courses',
			'renewal_reminders_sent' => $prefix . 'renewal_reminders_sent',
			'custom_fonts'          => $prefix . 'custom_fonts',
			'email_templates'       => $prefix . 'email_templates',
			'events'                => $prefix . 'events',
		);
	}

	public function get_table( string $name ): string {
		return $this->tables[ $name ] ?? '';
	}

	public function get_all_tables(): array {
		return $this->tables;
	}

	public function create_all(): void {
		$this->create_students_table();
		$this->create_teachers_table();
		$this->create_schools_table();
		$this->create_certificate_templates_table();
		$this->create_certificates_table();
		$this->create_email_logs_table();
		$this->create_student_certificates_table();
		$this->create_teacher_certificates_table();
		$this->create_settings_table();
		$this->create_migrations_table();
		$this->create_lms_course_map_table();
		$this->create_lms_tracks_table();
		$this->create_lms_track_courses_table();
		$this->create_renewal_reminders_sent_table();
		$this->create_custom_fonts_table();
		$this->create_email_templates_table();
		$this->create_events_table();

		update_option( 'cg_custom_tables_version', self::SCHEMA_VERSION );
	}

	/**
	 * Check whether a table exists, with a per-request static cache to avoid
	 * repeated SHOW TABLES queries on the same page load.
	 */
	public function table_exists( string $name ): bool {
		$table = $this->get_table( $name );
		if ( empty( $table ) ) {
			return false;
		}
		if ( ! isset( self::$_exists_cache[ $table ] ) ) {
			global $wpdb;
			self::$_exists_cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return self::$_exists_cache[ $table ];
	}

	public function all_tables_exist(): bool {
		foreach ( array_keys( $this->tables ) as $name ) {
			if ( ! $this->table_exists( $name ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * True when any table is missing OR the stored schema version is behind
	 * SCHEMA_VERSION — the latter is what makes dbDelta() re-run (and add any
	 * newly-introduced columns) on installs that already have every table.
	 */
	public function needs_upgrade(): bool {
		return get_option( 'cg_custom_tables_version' ) !== self::SCHEMA_VERSION || ! $this->all_tables_exist();
	}

	private function create_students_table(): void {
		global $wpdb;
		$table           = $this->tables['students'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_post_id BIGINT UNSIGNED DEFAULT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            student_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            school_id BIGINT UNSIGNED DEFAULT NULL,
            school_name VARCHAR(255) DEFAULT NULL,
            certificate_type VARCHAR(255) NOT NULL DEFAULT '',
            year YEAR DEFAULT NULL,
            issue_date DATE DEFAULT NULL,
            serial_number VARCHAR(100) DEFAULT NULL,
            enrollment_date DATE DEFAULT NULL,
            graduation_date DATE DEFAULT NULL,
            status ENUM('active','graduated','transferred','dropped') DEFAULT 'active',
            send_email TINYINT(1) NOT NULL DEFAULT 1,
            photo_url VARCHAR(500) DEFAULT NULL,
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_wp_post_id (wp_post_id),
            INDEX idx_event_id (event_id),
            INDEX idx_email (email),
            INDEX idx_school_id (school_id),
            INDEX idx_status (status),
            INDEX idx_student_name (student_name),
            INDEX idx_serial_number (serial_number),
            INDEX idx_certificate_type (certificate_type),
            INDEX idx_year (year)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_teachers_table(): void {
		global $wpdb;
		$table           = $this->tables['teachers'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_post_id BIGINT UNSIGNED DEFAULT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            teacher_name VARCHAR(255) NOT NULL,
            email VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            school_id BIGINT UNSIGNED DEFAULT NULL,
            school_name VARCHAR(255) DEFAULT NULL,
            department VARCHAR(100) DEFAULT NULL,
            certificate_type VARCHAR(255) NOT NULL DEFAULT '',
            year YEAR DEFAULT NULL,
            issue_date DATE DEFAULT NULL,
            serial_number VARCHAR(100) DEFAULT NULL,
            hire_date DATE DEFAULT NULL,
            status ENUM('active','inactive','retired') DEFAULT 'active',
            send_email TINYINT(1) NOT NULL DEFAULT 1,
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_wp_post_id (wp_post_id),
            INDEX idx_event_id (event_id),
            INDEX idx_email (email),
            INDEX idx_school_id (school_id),
            INDEX idx_status (status),
            INDEX idx_teacher_name (teacher_name),
            INDEX idx_serial_number (serial_number),
            INDEX idx_certificate_type (certificate_type),
            INDEX idx_year (year)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_schools_table(): void {
		global $wpdb;
		$table           = $this->tables['schools'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_post_id BIGINT UNSIGNED DEFAULT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            school_name VARCHAR(255) NOT NULL,
            address TEXT DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            state VARCHAR(100) DEFAULT NULL,
            country VARCHAR(100) DEFAULT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            website VARCHAR(255) DEFAULT NULL,
            principal_name VARCHAR(255) DEFAULT NULL,
            certificate_type VARCHAR(255) NOT NULL DEFAULT '',
            year YEAR DEFAULT NULL,
            issue_date DATE DEFAULT NULL,
            serial_number VARCHAR(100) DEFAULT NULL,
            status ENUM('active','inactive') DEFAULT 'active',
            send_email TINYINT(1) NOT NULL DEFAULT 1,
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_wp_post_id (wp_post_id),
            INDEX idx_event_id (event_id),
            INDEX idx_school_name (school_name),
            INDEX idx_status (status),
            INDEX idx_city (city),
            INDEX idx_serial_number (serial_number),
            INDEX idx_certificate_type (certificate_type),
            INDEX idx_year (year)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_certificate_templates_table(): void {
		global $wpdb;
		$table           = $this->tables['certificate_templates'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_post_id BIGINT UNSIGNED DEFAULT NULL,
            event_id BIGINT UNSIGNED DEFAULT NULL,
            template_name VARCHAR(255) NOT NULL,
            certificate_type VARCHAR(100) NOT NULL,
            entity_type ENUM('students','teachers','schools') NOT NULL DEFAULT 'students',
            year YEAR DEFAULT NULL,
            event_date DATE DEFAULT NULL,
            template_url VARCHAR(500) DEFAULT NULL,
            orientation ENUM('portrait','landscape') DEFAULT 'landscape',
            page_size ENUM('A4','Letter','Legal','Custom') DEFAULT 'A4',
            font_style VARCHAR(50) DEFAULT 'helvetica',
            font_size INT DEFAULT 12,
            font_color VARCHAR(7) DEFAULT '#000000',
            qr_enabled TINYINT(1) DEFAULT 0,
            qr_size INT DEFAULT 15,
            qr_position_x DECIMAL(8,2) DEFAULT 250.00,
            qr_position_y DECIMAL(8,2) DEFAULT 180.00,
            qr_error_correction ENUM('L','M','Q','H') DEFAULT 'L',
            qr_data_fields TEXT DEFAULT NULL,
            serial_number_display TINYINT(1) DEFAULT 0,
            serial_number_position_x DECIMAL(8,2) DEFAULT 105.00,
            serial_number_position_y DECIMAL(8,2) DEFAULT 200.00,
            serial_number_font_size INT DEFAULT 10,
            expiration_period_unit ENUM('never','days','months','years') DEFAULT 'never',
            expiration_period_value INT DEFAULT 0,
            field_config JSON DEFAULT NULL,
            status ENUM('draft','scheduled','published','archived') DEFAULT 'draft',
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_wp_post_id (wp_post_id),
            INDEX idx_event_id (event_id),
            INDEX idx_certificate_type (certificate_type),
            INDEX idx_year (year),
            INDEX idx_event_date (event_date),
            INDEX idx_status (status),
            INDEX idx_type_date (certificate_type, event_date)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_certificates_table(): void {
		global $wpdb;
		$table           = $this->tables['certificates'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_post_id BIGINT UNSIGNED DEFAULT NULL,
            template_id BIGINT UNSIGNED DEFAULT NULL,
            student_id BIGINT UNSIGNED DEFAULT NULL,
            teacher_id BIGINT UNSIGNED DEFAULT NULL,
            school_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_name VARCHAR(255) NOT NULL,
            recipient_email VARCHAR(255) DEFAULT NULL,
            recipient_type ENUM('student','teacher','other') DEFAULT 'student',
            certificate_type VARCHAR(100) NOT NULL,
            serial_number VARCHAR(50) DEFAULT NULL,
            issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            generated_via ENUM('manual','bulk','api','automatic','preview') DEFAULT 'manual',
            generation_source VARCHAR(100) DEFAULT NULL,
            pdf_path VARCHAR(500) DEFAULT NULL,
            pdf_url VARCHAR(500) DEFAULT NULL,
            certificate_data JSON DEFAULT NULL,
            status ENUM('pending','generated','sent','failed','revoked') DEFAULT 'generated',
            email_sent TINYINT(1) DEFAULT 0,
            email_sent_at DATETIME DEFAULT NULL,
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_wp_post_id (wp_post_id),
            INDEX idx_template_id (template_id),
            INDEX idx_student_id (student_id),
            INDEX idx_teacher_id (teacher_id),
            INDEX idx_school_id (school_id),
            INDEX idx_certificate_type (certificate_type),
            INDEX idx_serial_number (serial_number),
            INDEX idx_issued_at (issued_at),
            INDEX idx_expires_at (expires_at),
            INDEX idx_status (status),
            INDEX idx_email_sent (email_sent),
            INDEX idx_recipient_email (recipient_email),
            INDEX idx_type_issued (certificate_type, issued_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_email_logs_table(): void {
		global $wpdb;
		$table           = $this->tables['email_logs'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certificate_id BIGINT UNSIGNED DEFAULT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            recipient_name VARCHAR(255) DEFAULT NULL,
            subject VARCHAR(500) DEFAULT NULL,
            status ENUM('queued','sent','failed','bounced') DEFAULT 'sent',
            error_message TEXT DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_certificate_id (certificate_id),
            INDEX idx_recipient_email (recipient_email),
            INDEX idx_status (status),
            INDEX idx_sent_at (sent_at)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_student_certificates_table(): void {
		global $wpdb;
		$table           = $this->tables['student_certificates'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            student_id BIGINT UNSIGNED NOT NULL,
            certificate_id BIGINT UNSIGNED NOT NULL,
            awarded_date DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_student_cert (student_id, certificate_id),
            INDEX idx_certificate_id (certificate_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_teacher_certificates_table(): void {
		global $wpdb;
		$table           = $this->tables['teacher_certificates'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            teacher_id BIGINT UNSIGNED NOT NULL,
            certificate_id BIGINT UNSIGNED NOT NULL,
            awarded_date DATE DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_teacher_cert (teacher_id, certificate_id),
            INDEX idx_certificate_id (certificate_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_settings_table(): void {
		global $wpdb;
		$table           = $this->tables['settings'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT DEFAULT NULL,
            setting_type ENUM('string','integer','boolean','json','array') DEFAULT 'string',
            description VARCHAR(255) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_setting_key (setting_key)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private function create_migrations_table(): void {
		global $wpdb;
		$table           = $this->tables['migrations'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            migration_name VARCHAR(255) NOT NULL UNIQUE,
            batch INT NOT NULL,
            executed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_batch (batch)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Course → certificate-template mapping for LMS integrations (Tutor LMS, etc.).
	 * One row per (lms_type, course_id): which template to issue and what triggers it.
	 */
	private function create_lms_course_map_table(): void {
		global $wpdb;
		$table           = $this->tables['lms_course_map'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lms_type VARCHAR(50) NOT NULL DEFAULT 'tutor',
            course_id BIGINT UNSIGNED NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            trigger_event ENUM('course_complete','quiz_pass','both') NOT NULL DEFAULT 'course_complete',
            quiz_id BIGINT UNSIGNED DEFAULT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_lms_course (lms_type, course_id),
            INDEX idx_template_id (template_id),
            INDEX idx_enabled (enabled)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * A "track" groups several courses under one certificate template — the
	 * certificate issues once every course in the track is complete for a user,
	 * instead of one certificate per course. lms_type-scoped like lms_course_map.
	 */
	private function create_lms_tracks_table(): void {
		global $wpdb;
		$table           = $this->tables['lms_tracks'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lms_type VARCHAR(50) NOT NULL DEFAULT 'tutor',
            track_name VARCHAR(255) NOT NULL,
            template_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_lms_type (lms_type),
            INDEX idx_template_id (template_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Join table: which courses belong to a track. */
	private function create_lms_track_courses_table(): void {
		global $wpdb;
		$table           = $this->tables['lms_track_courses'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            track_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_track_course (track_id, course_id),
            INDEX idx_track_id (track_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Tracks which recipient-facing renewal reminder stages have already been sent per certificate. */
	private function create_renewal_reminders_sent_table(): void {
		global $wpdb;
		$table           = $this->tables['renewal_reminders_sent'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            certificate_id BIGINT UNSIGNED NOT NULL,
            stage VARCHAR(20) NOT NULL,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_cert_stage (certificate_id, stage),
            INDEX idx_certificate_id (certificate_id)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/** Business-tier custom-uploaded TTF fonts, rendered via tFPDF (see FontManager). */
	private function create_custom_fonts_table(): void {
		global $wpdb;
		$table           = $this->tables['custom_fonts'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            font_name VARCHAR(191) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            uploaded_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_font_name (font_name)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Named email templates mapped 1:1 to a certificate_type (the free-text
	 * value used on certificate_templates). certificate_generator_send_email()
	 * consults this before falling back to the per-post-type
	 * (students/teachers/schools) settings.
	 */
	private function create_email_templates_table(): void {
		global $wpdb;
		$table           = $this->tables['email_templates'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            certificate_type VARCHAR(255) NOT NULL,
            subject VARCHAR(500) DEFAULT NULL,
            title VARCHAR(255) DEFAULT NULL,
            message LONGTEXT DEFAULT NULL,
            attach_certificate TINYINT(1) NOT NULL DEFAULT 1,
            reply_to VARCHAR(255) DEFAULT NULL,
            cc VARCHAR(500) DEFAULT NULL,
            bcc VARCHAR(500) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_certificate_type (certificate_type)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * A recurring or one-off event (e.g. "Python Olympiad", run several times a
	 * year). event_code is the human-readable identifier (e.g. PO-06-2026) used
	 * for lookups; id stays the numeric FK target for students/teachers/schools/
	 * certificate_templates.event_id, matching this schema's existing convention.
	 */
	private function create_events_table(): void {
		global $wpdb;
		$table           = $this->tables['events'];
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_code VARCHAR(50) NOT NULL,
            event_name VARCHAR(255) NOT NULL,
            start_date DATE DEFAULT NULL,
            end_date DATE DEFAULT NULL,
            registration_deadline DATE DEFAULT NULL,
            year YEAR DEFAULT NULL,
            status ENUM('draft','open','closed','completed','archived') DEFAULT 'draft',
            description TEXT DEFAULT NULL,
            extra_fields JSON DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_event_code (event_code),
            INDEX idx_year (year),
            INDEX idx_status (status)
        ) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
