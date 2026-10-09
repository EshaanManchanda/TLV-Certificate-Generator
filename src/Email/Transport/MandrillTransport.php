<?php
declare(strict_types=1);

namespace CertificateGenerator\Email\Transport;

/**
 * Mailchimp Transactional (formerly Mandrill) — https://mailchimp.com/developer/transactional/
 * Requires a Mailchimp Transactional account/API key; a regular Mailchimp
 * marketing API key will not work with this endpoint.
 */
class MandrillTransport implements TransportInterface {

	private const API_URL = 'https://mandrillapp.com/api/1.0/messages/send.json';

	private array $config;
	private string $last_error = '';

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function from_options(): self {
		if ( class_exists( '\CertificateGenerator\Services\SettingsService' ) ) {
			return new self( \CertificateGenerator\Services\SettingsService::get_mandrill_config() );
		}

		return new self(
			array(
				'api_key'    => (string) get_option( 'certificate_generator_mandrill_api_key', '' ),
				'from_email' => (string) get_option( 'certificate_generator_email_from_email', get_bloginfo( 'admin_email' ) ),
				'from_name'  => (string) get_option( 'certificate_generator_email_from_name', get_bloginfo( 'name' ) ),
			)
		);
	}

	public function send( string $to, string $subject, string $body, array $headers, array $attachments ): bool {
		if ( empty( $this->config['api_key'] ) ) {
			$this->last_error = 'Mandrill API key is not configured.';
			return false;
		}

		$is_html = (bool) array_filter( $headers, static fn( $h ) => stripos( $h, 'text/html' ) !== false );

		$message                               = array(
			'subject'    => $subject,
			'from_email' => $this->config['from_email'],
			'from_name'  => $this->config['from_name'],
			'to'         => array(
				array(
					'email' => $to,
					'type'  => 'to',
				),
			),
		);
		$message[ $is_html ? 'html' : 'text' ] = $body;

		$encoded_attachments = $this->encode_attachments( $attachments );
		if ( ! empty( $encoded_attachments ) ) {
			$message['attachments'] = $encoded_attachments;
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'key'     => $this->config['api_key'],
						'message' => $message,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$body_json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body_json ) ) {
			$this->last_error = 'Unexpected Mandrill response: ' . wp_remote_retrieve_body( $response );
			return false;
		}

		// A top-level "status": "error" means the whole request was rejected
		// (bad key, etc.) rather than a per-recipient array.
		if ( isset( $body_json['status'] ) && $body_json['status'] === 'error' ) {
			$this->last_error = $body_json['message'] ?? 'Mandrill request rejected';
			return false;
		}

		$recipient = $body_json[0] ?? null;
		$status    = $recipient['status'] ?? '';

		if ( in_array( $status, array( 'sent', 'queued', 'scheduled' ), true ) ) {
			return true;
		}

		$this->last_error = $recipient['reject_reason'] ?? ( 'Mandrill status: ' . $status );
		return false;
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	private function encode_attachments( array $paths ): array {
		$encoded = array();
		foreach ( $paths as $path ) {
			$content = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $content === false ) {
				continue;
			}
			$encoded[] = array(
				'type'    => mime_content_type( $path ) ?: 'application/octet-stream',
				'name'    => basename( $path ),
				'content' => base64_encode( $content ),
			);
		}
		return $encoded;
	}
}
