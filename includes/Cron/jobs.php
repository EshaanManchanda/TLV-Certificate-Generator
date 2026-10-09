<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CG_Cron_Jobs {
	public static function init() {
		add_action( 'cg_cleanup_qr_codes', array( __CLASS__, 'cleanup_qr_codes' ) );
		add_action( 'cg_cleanup_old_zips', array( __CLASS__, 'cleanup_old_zips' ) );
		add_action( 'cg_check_expiring_certificates', array( __CLASS__, 'check_expiring_certificates' ) );
		add_action( 'cg_cleanup_old_certificates', array( __CLASS__, 'cleanup_old_certificates' ) );
		add_action( 'cg_publish_scheduled_templates', array( __CLASS__, 'publish_scheduled_templates' ) );
		add_action( 'cg_send_renewal_reminders', array( __CLASS__, 'send_recipient_renewal_reminders' ) );

		if ( ! wp_next_scheduled( 'cg_cleanup_qr_codes' ) ) {
			wp_schedule_event( time(), 'daily', 'cg_cleanup_qr_codes' );
		}

		if ( ! wp_next_scheduled( 'cg_cleanup_old_zips' ) ) {
			wp_schedule_event( time(), 'daily', 'cg_cleanup_old_zips' );
		}

		if ( ! wp_next_scheduled( 'cg_check_expiring_certificates' ) ) {
			wp_schedule_event( time(), 'daily', 'cg_check_expiring_certificates' );
		}

		if ( ! wp_next_scheduled( 'cg_cleanup_old_certificates' ) ) {
			wp_schedule_event( time(), 'weekly', 'cg_cleanup_old_certificates' );
		}

		if ( ! wp_next_scheduled( 'cg_publish_scheduled_templates' ) ) {
			wp_schedule_event( time(), 'hourly', 'cg_publish_scheduled_templates' );
		}

		if ( ! wp_next_scheduled( 'cg_send_renewal_reminders' ) ) {
			wp_schedule_event( time(), 'daily', 'cg_send_renewal_reminders' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'cg_cleanup_qr_codes' );
		wp_clear_scheduled_hook( 'cg_cleanup_old_zips' );
		wp_clear_scheduled_hook( 'cg_check_expiring_certificates' );
		wp_clear_scheduled_hook( 'cg_cleanup_old_certificates' );
		wp_clear_scheduled_hook( 'cg_publish_scheduled_templates' );
		wp_clear_scheduled_hook( 'cg_send_renewal_reminders' );
	}

	/**
	 * Bulk ZIPs are rebuilt on demand (or reused while unchanged), so old ones are safe to drop.
	 * Admin ZIPs (private/) hold many people's certificates and only live a day. Also clears
	 * temp files a crashed render left behind and locks of abandoned download jobs.
	 */
	public static function cleanup_old_zips() {
		$dir   = wp_upload_dir()['basedir'] . '/cg_certificates/';
		$rules = array(
			$dir . 'certificates_*.zip'                                   => 7 * DAY_IN_SECONDS,
			$dir . 'private/certificates_*.zip'                           => DAY_IN_SECONDS,
			$dir . '*.tmp'                                                => HOUR_IN_SECONDS,
			wp_upload_dir()['basedir'] . '/cg-qr-codes/*.tmp'             => HOUR_IN_SECONDS,
		);
		$deleted = 0;
		foreach ( $rules as $pattern => $max_age ) {
			foreach ( glob( $pattern ) ?: array() as $file ) {
				if ( filemtime( $file ) < time() - $max_age ) {
					wp_delete_file( $file );
					++$deleted;
				}
			}
		}

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like( 'cg_dljob_lock_' ) . '%',
				time() - HOUR_IN_SECONDS
			)
		);

		if ( $deleted > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			cg_debug_log( "[CG Cron] ZIP/temp cleanup removed $deleted file(s)" );
		}
	}

	public static function cleanup_qr_codes() {
		$upload_dir = wp_upload_dir();
		$qr_dir     = $upload_dir['basedir'] . '/cg-qr-codes/';

		if ( ! file_exists( $qr_dir ) ) {
			return;
		}

		$files = glob( $qr_dir . '*.png' );
		if ( ! $files ) {
			return;
		}

		$deleted = 0;
		$cutoff  = time() - ( 7 * DAY_IN_SECONDS );

		foreach ( $files as $file ) {
			if ( filemtime( $file ) < $cutoff ) {
				if ( wp_delete_file( $file ) ) {
					++$deleted;
				}
			}
		}

		if ( $deleted > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			cg_debug_log( "[CG Cron] Cleaned up $deleted old QR code files" );
		}
	}

	public static function check_expiring_certificates() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_generator';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$expiring_soon = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table_name 
             WHERE expires_at IS NOT NULL 
             AND expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 7 DAY)
             AND expires_at > %s",
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $expiring_soon ) ) {
			return;
		}

		$notification_email = get_option( 'cg_expiration_notification_email', get_option( 'admin_email' ) );
		$send_notifications = get_option( 'cg_send_expiration_notifications', false );

		if ( ! $send_notifications ) {
			return;
		}

		$subject = 'Certificates Expiring Within 7 Days';
		$message = "The following certificates are expiring within the next 7 days:\n\n";

		foreach ( $expiring_soon as $cert ) {
			$message .= "- {$cert->student_name} (Serial: {$cert->serial_number}) - Expires: {$cert->expires_at}\n";
		}

		$message .= "\n\nTotal: " . count( $expiring_soon ) . " certificates\n";
		$message .= "\nView full report: " . admin_url( 'admin.php?page=cg-analytics' );

		wp_mail( $notification_email, $subject, $message );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			cg_debug_log( '[CG Cron] Sent expiration notification for ' . count( $expiring_soon ) . ' certificates' );
		}
	}

	public static function cleanup_old_certificates() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_generator';

		$archive_after_years = (int) get_option( 'cg_archive_after_years', 5 );
		if ( $archive_after_years <= 0 ) {
			return;
		}

		$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$archive_after_years} years" ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$expired_old = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, student_name, serial_number, expires_at FROM $table_name 
             WHERE expires_at IS NOT NULL 
             AND expires_at < %s 
             AND created_at < %s",
				$cutoff_date,
				$cutoff_date
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $expired_old ) ) {
			return;
		}

		$archive_table  = $table_name . '_archive';
		$archive_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $archive_table ) ) === $archive_table;

		if ( ! $archive_exists ) {
			$charset_collate = $wpdb->get_charset_collate();
			$wpdb->query( "CREATE TABLE $archive_table LIKE $table_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		}

		$archived = 0;
		foreach ( $expired_old as $cert ) {
			$wpdb->insert( $archive_table, (array) $cert );
			$wpdb->delete( $table_name, array( 'id' => $cert->id ) );
			++$archived;
		}

		if ( $archived > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			cg_debug_log( "[CG Cron] Archived $archived old expired certificates" );
		}
	}

	public static function publish_scheduled_templates() {
		if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			return;
		}

		$tables    = \CertificateGenerator\Database\CustomTables::instance();
		$tpl_table = $tables->get_table( 'certificate_templates' );

		$table_exists = $GLOBALS['wpdb']->get_var(
			$GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $tpl_table )
		) === $tpl_table;

		if ( ! $table_exists ) {
			return;
		}

		$today   = current_time( 'Y-m-d' );
		$updated = $GLOBALS['wpdb']->query(
			$GLOBALS['wpdb']->prepare(
				"UPDATE $tpl_table
				    SET status = 'published', updated_at = NOW()
				  WHERE status = 'scheduled'
				    AND event_date IS NOT NULL
				    AND event_date != '0000-00-00'
				    AND event_date <= %s",
				$today
			)
		);

		if ( $updated && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			cg_debug_log( "[CG Cron] Auto-published $updated scheduled certificate template(s)" );
		}
	}

	/**
	 * Recipient-facing renewal reminders — distinct from check_expiring_certificates()
	 * above, which only emails the admin a digest. Sends at configurable day-offsets
	 * before expires_at (default 30/7/1), gated by CG_USE_RENEWAL_REMINDERS and the
	 * Pro/Business plan (mirrors the email_templates feature gate). Only certificates
	 * with a recipient_email on file can receive one — that column is blank for some
	 * SQL-first issuance paths (see TutorLmsListener's idempotency note).
	 *
	 * No self-serve "renew" purchase flow exists anywhere in this plugin, so the
	 * email links to the existing certificate PDF rather than inventing one.
	 */
	public static function send_recipient_renewal_reminders() {
		if ( ! class_exists( '\CertificateGenerator\Core\Config' ) || ! \CertificateGenerator\Core\Config::flag( 'CG_USE_RENEWAL_REMINDERS' ) ) {
			return;
		}
		if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			return;
		}

		global $wpdb;
		$tables     = \CertificateGenerator\Database\CustomTables::instance();
		$cert_table = $tables->get_table( 'certificates' );
		$sent_table = $tables->get_table( 'renewal_reminders_sent' );
		if ( ! $cert_table || ! $sent_table ) {
			return;
		}

		$offsets = get_option( 'cg_renewal_reminder_offsets', array( 30, 7, 1 ) );
		if ( ! is_array( $offsets ) || empty( $offsets ) ) {
			$offsets = array( 30, 7, 1 );
		}

		$sent_count = 0;

		foreach ( $offsets as $days ) {
			$days  = (int) $days;
			if ( $days <= 0 ) {
				continue;
			}
			$stage = $days . 'd';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$certs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.* FROM $cert_table c
					 WHERE c.expires_at IS NOT NULL
					   AND c.recipient_email IS NOT NULL AND c.recipient_email != ''
					   AND c.status != 'revoked'
					   AND DATE(c.expires_at) = DATE_ADD(CURDATE(), INTERVAL %d DAY)
					   AND NOT EXISTS (
						   SELECT 1 FROM $sent_table s WHERE s.certificate_id = c.id AND s.stage = %s
					   )",
					$days,
					$stage
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $certs as $cert ) {
				$subject = sprintf( 'Your certificate expires in %d day%s', $days, $days === 1 ? '' : 's' );
				$message = "Hi {$cert['recipient_name']},\n\n"
					. "Your certificate ({$cert['certificate_type']}, serial {$cert['serial_number']}) expires on "
					. date_i18n( get_option( 'date_format' ), strtotime( $cert['expires_at'] ) ) . ".\n\n"
					. ( ! empty( $cert['pdf_url'] ) ? "You can download it here: {$cert['pdf_url']}\n\n" : '' )
					. "If you have questions about renewing, please contact us.\n";

				if ( wp_mail( $cert['recipient_email'], $subject, $message ) ) {
					$wpdb->insert(
						$sent_table,
						array(
							'certificate_id' => $cert['id'],
							'stage'          => $stage,
						)
					);
					++$sent_count;
				}
			}
		}

		if ( $sent_count > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			cg_debug_log( "[CG Cron] Sent $sent_count recipient renewal reminder(s)" );
		}
	}
}
