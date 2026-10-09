<?php
declare(strict_types=1);

namespace CertificateGenerator\Email;

/**
 * Email Logs "Resend" (one row, sent now) and "Resend all failed" (queued).
 *
 * A log row's certificate_id is the wp_certificate_generator anchor id that
 * certificate_generator_send_email() / certificate_generator_queue_email()
 * already take, so both paths reuse the normal send pipeline: same PDF,
 * same logging, same monthly cap. Rows logged with certificate_id 0 (LMS
 * auto-issue) have no anchor and must be resent from the recipient's record.
 */
class EmailResender {

	public static function register(): void {
		add_action( 'wp_ajax_cg_resend_email_log', array( self::class, 'ajax_resend_one' ) );
		add_action( 'wp_ajax_cg_resend_all_failed', array( self::class, 'ajax_resend_all_failed' ) );
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cert_email_logs';
	}

	/**
	 * Failed sends that never succeeded afterwards, one per (certificate, address).
	 *
	 * @return array<int,array{certificate_id:int,recipient_email:string,recipient_name:string}>
	 */
	public static function still_failed(): array {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$rows = $wpdb->get_results(
			"SELECT l.certificate_id, l.recipient_email, MAX(l.recipient_name) AS recipient_name
			   FROM $t l
			  WHERE l.status = 'failed' AND l.certificate_id > 0 AND l.recipient_email <> ''
			    AND NOT EXISTS (
			        SELECT 1 FROM $t s
			         WHERE s.status = 'sent' AND s.certificate_id = l.certificate_id
			           AND s.recipient_email = l.recipient_email AND s.id > l.id )
			  GROUP BY l.certificate_id, l.recipient_email",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static fn( array $r ): array => array(
				'certificate_id'  => (int) $r['certificate_id'],
				'recipient_email' => (string) $r['recipient_email'],
				'recipient_name'  => (string) $r['recipient_name'],
			),
			$rows ?: array()
		);
	}

	/** Queue every still-failed send. Returns how many were queued. */
	public static function queue_all_failed(): int {
		if ( ! function_exists( 'certificate_generator_queue_email' ) ) {
			return 0;
		}
		$queued = 0;
		foreach ( self::still_failed() as $row ) {
			$ok = certificate_generator_queue_email(
				$row['certificate_id'],
				$row['recipient_email'],
				array( 'priority' => 1, 'recipient_name' => $row['recipient_name'] )
			);
			if ( $ok ) {
				++$queued;
			}
		}
		return $queued;
	}

	/**
	 * Send one logged email again, now.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function resend_log( int $log_id ): array {
		global $wpdb;
		$t   = self::table();
		$log = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $log_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $log ) {
			return array( 'ok' => false, 'message' => __( 'Log entry not found.', 'certificate-generator' ) );
		}
		if ( (int) $log->certificate_id <= 0 ) {
			return array( 'ok' => false, 'message' => __( 'This email was sent by an LMS integration and has no certificate record to resend from. Use Send Email on the student\'s row instead.', 'certificate-generator' ) );
		}
		if ( ! function_exists( 'certificate_generator_send_email' ) ) {
			return array( 'ok' => false, 'message' => __( 'Email send function unavailable.', 'certificate-generator' ) );
		}

		if ( certificate_generator_send_email( (int) $log->certificate_id ) ) {
			return array( 'ok' => true, 'message' => __( 'Email sent.', 'certificate-generator' ) );
		}

		// send_email() logged the reason; surface it.
		$reason = $wpdb->get_var(
			$wpdb->prepare( "SELECT error_message FROM $t WHERE certificate_id = %d AND status = 'failed' ORDER BY id DESC LIMIT 1", (int) $log->certificate_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return array( 'ok' => false, 'message' => $reason ?: __( 'Send failed. Check your email settings.', 'certificate-generator' ) );
	}

	public static function ajax_resend_one(): void {
		check_ajax_referer( 'cg_resend_email', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'certificate-generator' ) ), 403 );
		}
		$result = self::resend_log( absint( $_POST['log_id'] ?? 0 ) );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}

	public static function ajax_resend_all_failed(): void {
		check_ajax_referer( 'cg_resend_email', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'certificate-generator' ) ), 403 );
		}
		$queued = self::queue_all_failed();
		wp_send_json_success(
			array(
				'queued'  => $queued,
				/* translators: %d: number of emails queued */
				'message' => sprintf( _n( '%d email queued. It will send in the background.', '%d emails queued. They will send in the background.', $queued, 'certificate-generator' ), $queued ),
			)
		);
	}
}
