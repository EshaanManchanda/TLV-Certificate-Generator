<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

use CertificateGenerator\Email\Mailer;
use CertificateGenerator\Exception\EmailSendingException;

class EmailService {

	private Mailer $mailer;
	private string $subject_template;
	private string $body_template;

	public function __construct( Mailer $mailer ) {
		$this->mailer           = $mailer;
		$this->subject_template = get_option( 'cg_email_subject', 'Your Certificate: {certificate_title}' );
		$this->body_template    = get_option( 'cg_email_body', '' );
	}

	public function send_certificate( string $to, string $name, array $cert_data, array $attachments = array() ): bool {
		if ( ! is_email( $to ) ) {
			throw new EmailSendingException( esc_html( "Invalid email address: {$to}" ) );
		}

		$cert_data += array( 'email' => $to );
		$subject    = do_shortcode( $this->replace_placeholders( $this->subject_template, $name, $cert_data ) );
		$body       = do_shortcode( $this->replace_placeholders( $this->body_template, $name, $cert_data ) );

		$result = $this->mailer->send_html( $to, $subject, $body, $attachments );

		if ( ! $result ) {
			throw new EmailSendingException( esc_html( "Failed to send certificate email to {$to}" ) );
		}

		return true;
	}

	public function send_bulk( array $recipients, array $cert_data, ?callable $progress_callback = null ): array {
		$results = array(
			'sent'   => 0,
			'failed' => 0,
			'errors' => array(),
		);
		$total   = count( $recipients );

		foreach ( $recipients as $i => $recipient ) {
			try {
				$this->send_certificate(
					$recipient['email'],
					$recipient['name'] ?? $recipient['email'],
					$cert_data,
					$recipient['attachments'] ?? array()
				);
				++$results['sent'];
			} catch ( EmailSendingException $e ) {
				++$results['failed'];
				$results['errors'][] = array(
					'email'   => $recipient['email'],
					'message' => $e->getMessage(),
				);
			}

			if ( $progress_callback && ( $i + 1 ) % 10 === 0 ) {
				call_user_func( $progress_callback, $i + 1, $total, $results );
			}
		}

		return $results;
	}

	// Same placeholder set as the main send path (certificate_generator_send_email()), so one
	// template works for both; LMS emails used to ship a literal {result_link}.
	private function replace_placeholders( string $template, string $name, array $data ): string {
		$email = (string) ( $data['email'] ?? '' );
		return str_replace(
			array( '{name}', '{certificate_title}', '{serial_number}', '{expires_at}', '{issue_date}', '{email}', '{result_link}', '{verify_link}', '{site_name}', '{certificate_count}', '{zip_link}' ),
			array(
				$name,
				$data['certificate_title'] ?? '',
				$data['serial_number'] ?? 'N/A',
				$data['expires_at'] ?? 'Never',
				$data['issue_date'] ?? current_time( 'mysql' ),
				$email,
				home_url( '/result/?student_email=' . rawurlencode( $email ) ),
				home_url( '/verify-certificate/' ),
				get_bloginfo( 'name' ),
				'1',
				'',
			),
			$template
		);
	}

	// ── Static facade ── all new call-sites use these instead of calling procedural functions directly.

	public static function sendById( int $cg_id ): bool {
		if ( ! function_exists( 'certificate_generator_send_email' ) ) {
			return false;
		}
		return (bool) certificate_generator_send_email( $cg_id, false );
	}

	public static function queueById( int $cg_id, string $email, array $opts = array() ): int|false {
		if ( ! function_exists( 'certificate_generator_queue_email' ) ) {
			return false;
		}
		return certificate_generator_queue_email( $cg_id, $email, $opts );
	}

	public static function resendById( int $cg_id ): bool {
		return self::sendById( $cg_id );
	}

	public static function bulkQueue( string $post_type, array $ids = array() ): array {
		if ( ! function_exists( 'certificate_generator_bulk_queue_emails' ) ) {
			return array(
				'queued'  => 0,
				'skipped' => 0,
				'errors'  => array(),
			);
		}
		return certificate_generator_bulk_queue_emails( $post_type, $ids );
	}
}
