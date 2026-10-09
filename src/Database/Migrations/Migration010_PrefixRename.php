<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Migration 010 — move stored names to the certificate_generator_ prefix.
 *
 * 7.6.0 renamed every option, transient and cron hook from the short cg_ / cert_ prefix
 * (WordPress.org requires a unique prefix of at least 4 characters). This keeps existing
 * sites' data: options and persistent counters are renamed in place (value and autoload
 * unchanged), caches are dropped, and queued cron events are re-scheduled under the new
 * hook with the same time, arguments and recurrence.
 */
class Migration010_PrefixRename extends Migration {

	/** Options renamed one to one. */
	private const OPTIONS = array(
		'cg_archive_after_years', 'cg_cpt_to_sql_migration_completed', 'cg_cpt_to_sql_migration_stats',
		'cg_cpt_to_sql_migration_timestamp', 'cg_cpts_cleaned_up', 'cg_custom_tables_version', 'cg_db_version',
		'cg_defaults_seeded', 'cg_email_body', 'cg_email_from_email', 'cg_email_from_name', 'cg_email_subject',
		'cg_email_title', 'cg_email_transport', 'cg_expiration_notification_email', 'cg_feature_toggles',
		'cg_keep_data_on_uninstall', 'cg_mandrill_api_key', 'cg_migration_queue_columns_done',
		'cg_migration_queue_scope_done', 'cg_migration_scheduled_status_done', 'cg_migration_send_email_column_done',
		'cg_migration_v7_done', 'cg_renewal_reminder_offsets', 'cg_send_expiration_notifications', 'cg_sender_api_key',
		'cg_serial_include_date', 'cg_serial_length', 'cg_serial_prefix', 'cg_serial_reset_period', 'cg_serial_suffix',
		'cg_shortcode_text', 'cg_smtp_encryption', 'cg_smtp_host', 'cg_smtp_password', 'cg_smtp_port',
		'cg_smtp_username', 'cg_test_certificate_sent', 'cg_welcome_dismissed',
	);

	/** Option families with a dynamic suffix: old prefix => new prefix. Serial counters live here. */
	private const OPTION_FAMILIES = array(
		'cg_serial_seq_'          => 'certificate_generator_serial_seq_',
		'cg_extra_fields_'        => 'certificate_generator_extra_fields_',
		'certificate_job_'        => 'certificate_generator_job_',
		'certificate_cache_keys_' => 'certificate_generator_cache_keys_',
	);

	/** Non-expiring transients that hold real counts: renamed, not dropped. */
	private const KEPT_TRANSIENT_FAMILIES = array(
		'cg_analytics_sent_'   => 'certificate_generator_analytics_sent_',
		'cg_analytics_failed_' => 'certificate_generator_analytics_failed_',
	);

	/** Cache transients: dropped (they rebuild on demand). Exact names and prefixes. */
	private const DROPPED_TRANSIENTS = array(
		'cert_gen_rate_limit_hour', 'cert_gen_rate_limit_minute', 'cg_activation_redirect', 'cg_duplicate_template_warning',
		'cg_queue_lock', 'cg_unique_cert_types', 'cg_unique_events', 'cg_unique_import_sources', 'cg_unique_schools',
		'cg_unique_years', 'cg_verify_page_url',
	);
	private const DROPPED_TRANSIENT_PREFIXES = array( 'cg_sent_count_', 'cg_rl_', 'cg_dljob_', 'cg_event_date_invalid_', 'cert_batch_', 'cg_import_resume_' );

	/** Cron hooks: old => new. */
	private const CRON = array(
		'cg_check_expiring_certificates'           => 'certificate_generator_check_expiring_certificates',
		'cg_cleanup_old_certificates'              => 'certificate_generator_cleanup_old_certificates',
		'cg_cleanup_old_zips'                      => 'certificate_generator_cleanup_old_zips',
		'cg_cleanup_qr_codes'                      => 'certificate_generator_cleanup_qr_codes',
		'cg_publish_scheduled_templates'           => 'certificate_generator_publish_scheduled_templates',
		'cg_send_renewal_reminders'                => 'certificate_generator_send_renewal_reminders',
		'generate_certificates_background'         => 'certificate_generator_generate_certificates_background',
		'generate_certificates_background_teachers' => 'certificate_generator_generate_certificates_background_teachers',
		'process_single_certificate_hook_school'   => 'certificate_generator_process_single_certificate_hook_school',
	);

	/**
	 * Shortcodes: old tag => new tag. The old tags are no longer registered, so existing pages are
	 * rewritten — otherwise the verification page every printed QR code links to would break.
	 */
	private const SHORTCODES = array(
		'student_search'        => 'certificate_generator_student_search',
		'teacher_search'        => 'certificate_generator_teacher_search',
		'school_search'         => 'certificate_generator_school_search',
		'cg_verify_certificate' => 'certificate_generator_verify_certificate',
	);

	public function get_version(): string {
		return '010';
	}

	public function up(): void {
		foreach ( self::OPTIONS as $old ) {
			$this->rename_option( $old, 'certificate_generator_' . substr( $old, 3 ) );
		}
		foreach ( self::OPTION_FAMILIES as $old => $new ) {
			$this->rename_family( $old, $new );
		}
		foreach ( self::KEPT_TRANSIENT_FAMILIES as $old => $new ) {
			$this->rename_family( '_transient_' . $old, '_transient_' . $new );
			$this->rename_family( '_transient_timeout_' . $old, '_transient_timeout_' . $new );
		}
		foreach ( self::DROPPED_TRANSIENTS as $name ) {
			delete_transient( $name );
		}
		foreach ( self::DROPPED_TRANSIENT_PREFIXES as $prefix ) {
			$this->delete_family( '_transient_' . $prefix );
			$this->delete_family( '_transient_timeout_' . $prefix );
		}
		$this->move_cron( self::CRON );
		$this->rename_shortcodes( self::SHORTCODES );
		$this->flush_option_cache();
	}

	/**
	 * Rewrite [old ...] / [old] tags in post content and in Elementor's stored layout.
	 * Only exact tags are touched: "[student_search]" and "[student_search " but not "[student_search_x".
	 */
	private function rename_shortcodes( array $map ): void {
		global $wpdb;
		foreach ( $map as $old => $new ) {
			foreach ( array( ']', ' ', '/' ) as $next ) {
				$from = '[' . $old . $next;
				$to   = '[' . $new . $next;
				$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", '%' . $wpdb->esc_like( $from ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_content = REPLACE( post_content, %s, %s ) WHERE post_content LIKE %s", $from, $to, '%' . $wpdb->esc_like( $from ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE( meta_value, %s, %s ) WHERE meta_key = '_elementor_data' AND meta_value LIKE %s", $from, $to, '%' . $wpdb->esc_like( $from ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
				foreach ( $ids as $id ) {
					clean_post_cache( (int) $id );
				}
			}
		}
	}

	public function down(): void {
		$this->rename_shortcodes( array_flip( self::SHORTCODES ) );
		foreach ( self::OPTIONS as $old ) {
			$this->rename_option( 'certificate_generator_' . substr( $old, 3 ), $old );
		}
		foreach ( self::OPTION_FAMILIES as $old => $new ) {
			$this->rename_family( $new, $old );
		}
		foreach ( self::KEPT_TRANSIENT_FAMILIES as $old => $new ) {
			$this->rename_family( '_transient_' . $new, '_transient_' . $old );
			$this->rename_family( '_transient_timeout_' . $new, '_transient_timeout_' . $old );
		}
		$this->move_cron( array_flip( self::CRON ) );
		$this->flush_option_cache();
	}

	/**
	 * Rename one option row in place (value and autoload kept). The site's existing value wins:
	 * a row already under the new name can only have been auto-created (defaults) moments before
	 * this one-off migration ran, so it is replaced.
	 */
	private function rename_option( string $old, string $new ): void {
		global $wpdb;
		$has_old = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
		if ( ! $has_old ) {
			return;
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => $new ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
		$wpdb->update( $wpdb->options, array( 'option_name' => $new ), array( 'option_name' => $old ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
	}

	private function rename_family( string $old_prefix, string $new_prefix ): void {
		global $wpdb;
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $old_prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
		foreach ( $names as $name ) {
			$this->rename_option( $name, $new_prefix . substr( $name, strlen( $old_prefix ) ) );
		}
	}

	private function delete_family( string $prefix ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off migration
	}

	/** Re-schedule queued events under the new hook (same time, args and recurrence), then drop the old ones. */
	private function move_cron( array $map ): void {
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $map as $old => $new ) {
				foreach ( (array) ( $hooks[ $old ] ?? array() ) as $event ) {
					$args = $event['args'] ?? array();
					if ( ! empty( $event['schedule'] ) ) {
						if ( ! wp_next_scheduled( $new, $args ) ) {
							wp_schedule_event( (int) $timestamp, $event['schedule'], $new, $args );
						}
					} elseif ( ! wp_next_scheduled( $new, $args ) ) {
						wp_schedule_single_event( (int) $timestamp, $new, $args );
					}
					wp_unschedule_event( (int) $timestamp, $old, $args );
				}
			}
		}
	}

	private function flush_option_cache(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
