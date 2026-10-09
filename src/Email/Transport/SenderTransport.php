<?php
declare(strict_types=1);

namespace CertificateGenerator\Email\Transport;

/**
 * Sender.net transactional email API — https://api.sender.net
 * NOTE: field names below match Sender's documented shape as of this
 * writing; verify against api.sender.net if sends start failing after
 * a Sender API change.
 */
class SenderTransport implements TransportInterface {

	private const API_URL = 'https://api.sender.net/v2/message/send';

	private array $config;
	private string $last_error = '';

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function from_options(): self {
		if ( class_exists( '\CertificateGenerator\Services\SettingsService' ) ) {
			return new self( \CertificateGenerator\Services\SettingsService::get_sender_config() );
		}

		return new self(
			array(
				'api_key'    => (string) get_option( 'cg_sender_api_key', '' ),
				'from_email' => (string) get_option( 'cg_email_from_email', get_bloginfo( 'admin_email' ) ),
				'from_name'  => (string) get_option( 'cg_email_from_name', get_bloginfo( 'name' ) ),
			)
		);
	}

	public function send( string $to, string $subject, string $body, array $headers, array $attachments ): bool {
		if ( empty( $this->config['api_key'] ) ) {
			$this->last_error = 'Sender.net API key is not configured.';
			return false;
		}

		$is_html = (bool) array_filter( $headers, static fn( $h ) => stripos( $h, 'text/html' ) !== false );

		$payload                               = array(
			'from'    => array(
				'email' => $this->config['from_email'],
				'name'  => $this->config['from_name'],
			),
			'to'      => array( array( 'email' => $to ) ),
			'subject' => $subject,
		);
		$payload[ $is_html ? 'html' : 'text' ] = $body;

		$encoded_attachments = $this->encode_attachments( $attachments );
		if ( ! empty( $encoded_attachments ) ) {
			$payload['attachments'] = $encoded_attachments;
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $this->config['api_key'],
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->last_error = $response->get_error_message();
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}

		$body_json        = json_decode( wp_remote_retrieve_body( $response ), true );
		$this->last_error = is_array( $body_json )
			? ( $body_json['message'] ?? wp_remote_retrieve_body( $response ) )
			: ( 'Sender.net HTTP ' . $status_code );
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
				'filename' => basename( $path ),
				'content'  => base64_encode( $content ),
			);
		}
		return $encoded;
	}
}
