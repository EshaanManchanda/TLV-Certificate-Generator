<?php
declare(strict_types=1);

namespace CertificateGenerator\Email\Transport;

class WpMailTransport implements TransportInterface {

	private string $from_email;
	private string $from_name;
	private string $last_error = '';

	public function __construct( string $from_email, string $from_name ) {
		$this->from_email = $from_email;
		$this->from_name  = $from_name;
	}

	public function send( string $to, string $subject, string $body, array $headers, array $attachments ): bool {
		$from_email = $this->from_email;
		$from_name  = $this->from_name;

		// wp filters are more reliable than inline From: headers — strip duplicates.
		$filter_email = static fn() => $from_email;
		$filter_name  = static fn() => $from_name;
		add_filter( 'wp_mail_from', $filter_email, 999 );
		add_filter( 'wp_mail_from_name', $filter_name, 999 );

		$headers = array_values( array_filter( $headers, static fn( $h ) => stripos( $h, 'From:' ) !== 0 ) );

		$result = wp_mail( $to, $subject, $body, $headers, $attachments );

		remove_filter( 'wp_mail_from', $filter_email, 999 );
		remove_filter( 'wp_mail_from_name', $filter_name, 999 );

		if ( ! $result ) {
			global $phpmailer;
			$this->last_error = ! empty( $phpmailer->ErrorInfo )
				? $phpmailer->ErrorInfo
				: 'wp_mail returned false';
		}

		return $result;
	}

	public function get_last_error(): string {
		return $this->last_error;
	}
}
