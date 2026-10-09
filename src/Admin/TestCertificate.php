<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin;

use CertificateGenerator\Email\Mailer;

/**
 * "Email me a test certificate" on the template editor: renders the saved
 * template with the admin's own name (same sample data as Preview Certificate)
 * and emails the PDF to the admin's address through the configured transport.
 *
 * Every plan. Not written to the email log, so it never counts toward the
 * monthly email cap. A success marks the setup checklist step done.
 */
class TestCertificate {

	public const DONE_OPTION = 'cg_test_certificate_sent';

	public static function register(): void {
		add_action( 'wp_ajax_cg_send_test_certificate', array( self::class, 'ajax_send' ) );
	}

	/**
	 * @return array{ok:bool,message:string}
	 */
	public static function send( int $template_id, \WP_User $user ): array {
		if ( ! function_exists( 'cg_template_sample_data' ) || ! function_exists( 'generate_certificate_pdf_with_data' ) ) {
			return array( 'ok' => false, 'message' => __( 'Certificate generation is not loaded.', 'certificate-generator' ) );
		}
		$to = (string) $user->user_email;
		if ( ! is_email( $to ) ) {
			return array( 'ok' => false, 'message' => __( 'Your WordPress profile has no valid email address.', 'certificate-generator' ) );
		}

		$data = cg_template_sample_data( $template_id );
		if ( null === $data ) {
			return array( 'ok' => false, 'message' => __( 'Template not found. Save the template first.', 'certificate-generator' ) );
		}
		$data['student_name'] = $user->display_name ?: $user->user_login;

		$url  = (string) generate_certificate_pdf_with_data( $data );
		$up   = wp_upload_dir();
		$path = $url ? str_replace( $up['baseurl'], $up['basedir'], $url ) : '';
		if ( ! $path || ! file_exists( $path ) ) {
			return array( 'ok' => false, 'message' => __( 'The certificate could not be generated. Check that the template has a background image and field positions.', 'certificate-generator' ) );
		}

		$subject = sprintf(
			/* translators: %s: certificate type */
			__( 'Test certificate: %s', 'certificate-generator' ),
			(string) $data['certificate_type']
		);
		$body = '<p>' . esc_html__( 'This is a test certificate from Certificate Generator. Recipients get the same PDF, with their own details.', 'certificate-generator' ) . '</p>';

		$mailer = Mailer::make();
		if ( ! $mailer->send( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ), array( $path ), false ) ) {
			$error = $mailer->get_last_error();
			return array(
				'ok'      => false,
				'message' => $error
					/* translators: %s: mail error */
					? sprintf( __( 'Email could not be sent: %s', 'certificate-generator' ), $error )
					: __( 'Email could not be sent. Check Settings → Email, or install WP Mail SMTP.', 'certificate-generator' ),
			);
		}

		update_option( self::DONE_OPTION, 1, false );
		return array(
			'ok'      => true,
			/* translators: %s: email address */
			'message' => sprintf( __( 'Sent to %s. Check your inbox (and spam folder).', 'certificate-generator' ), $to ),
		);
	}

	public static function ajax_send(): void {
		check_ajax_referer( 'cg_send_test_certificate', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'certificate-generator' ) ), 403 );
		}
		$result = self::send( absint( $_POST['template_id'] ?? 0 ), wp_get_current_user() );
		$result['ok'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
	}
}
