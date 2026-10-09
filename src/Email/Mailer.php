<?php
declare(strict_types=1);

namespace CertificateGenerator\Email;

use CertificateGenerator\Email\Transport\TransportInterface;
use CertificateGenerator\Email\Transport\SmtpTransport;
use CertificateGenerator\Email\Transport\WpMailTransport;
use CertificateGenerator\Email\Transport\SenderTransport;
use CertificateGenerator\Email\Transport\MandrillTransport;

class Mailer {

	private TransportInterface $transport;

	public function __construct( TransportInterface $transport ) {
		$this->transport = $transport;
	}

	/**
	 * Build from plugin options — selects SMTP or wp_mail transport automatically.
	 */
	public static function make(): self {
		$transport = match ( get_option( 'certificate_generator_email_transport', 'wp_mail' ) ) {
			'smtp'     => SmtpTransport::from_options(),
			'sender'   => SenderTransport::from_options(),
			'mandrill' => MandrillTransport::from_options(),
			default    => new WpMailTransport(
				(string) get_option( 'certificate_generator_email_from_email', get_bloginfo( 'admin_email' ) ),
				(string) get_option( 'certificate_generator_email_from_name', get_bloginfo( 'name' ) )
			),
		};

		return new self( $transport );
	}

	public function send( string $to, string $subject, string $message, array $headers = array(), array $attachments = array(), bool $log = true ): bool {
		$valid  = $this->validate_attachments( $attachments );
		$result = $this->transport->send( $to, $subject, $message, $headers, $valid );
		if ( $log ) {
			$this->write_log( $to, $subject, $result, $result ? '' : $this->transport->get_last_error() );
		}
		return $result;
	}

	public function get_last_error(): string {
		return $this->transport->get_last_error();
	}

	public function send_html( string $to, string $subject, string $html_body, array $attachments = array() ): bool {
		return $this->send( $to, $subject, $html_body, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
	}

	private function validate_attachments( array $paths ): array {
		$valid = array();
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				$valid[] = $path;
			} else {
				certificate_generator_debug_log( sprintf( '[CG Mailer] Attachment skipped — missing or unreadable: %s', $path ) );
			}
		}
		return $valid;
	}

	private function write_log( string $to, string $subject, bool $success, string $error ): void {
		// Route to the single canonical log table (wp_cert_email_logs) used by the admin log view.
		if ( function_exists( 'certificate_generator_log_email' ) ) {
			certificate_generator_log_email( 0, $to, '', '', mb_substr( $subject, 0, 490 ), $success, $error );
		}
	}
}
