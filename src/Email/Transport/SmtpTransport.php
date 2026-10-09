<?php
declare(strict_types=1);

namespace CertificateGenerator\Email\Transport;

class SmtpTransport implements TransportInterface {

	private array $config;
	private string $last_error = '';

	public function __construct( array $config ) {
		$this->config = $config;
	}

	public static function from_options(): self {
		if ( class_exists( '\CertificateGenerator\Services\SettingsService' ) ) {
			return new self( \CertificateGenerator\Services\SettingsService::get_smtp_config() );
		}

		// Fallback when SettingsService is unavailable.
		return new self(
			array(
				'host'       => (string) get_option( 'cg_smtp_host', '' ),
				'port'       => (int) get_option( 'cg_smtp_port', 587 ),
				'username'   => (string) get_option( 'cg_smtp_username', '' ),
				'password'   => (string) get_option( 'cg_smtp_password', '' ),
				'encryption' => (string) get_option( 'cg_smtp_encryption', 'tls' ),
				'from_email' => (string) get_option( 'cg_email_from_email', get_bloginfo( 'admin_email' ) ),
				'from_name'  => (string) get_option( 'cg_email_from_name', get_bloginfo( 'name' ) ),
			)
		);
	}

	public function send( string $to, string $subject, string $body, array $headers, array $attachments ): bool {
		$cfg = $this->config;

		$init = static function ( $mailer ) use ( $cfg ): void {
			$mailer->isSMTP();
			$mailer->Host       = $cfg['host'];
			$mailer->Port       = $cfg['port'];
			$mailer->SMTPAuth   = $cfg['username'] !== '';
			$mailer->Username   = $cfg['username'];
			$mailer->Password   = $cfg['password'];
			$mailer->SMTPSecure = $cfg['encryption'] === 'none' ? '' : $cfg['encryption'];
			$mailer->From       = $cfg['from_email'];
			$mailer->FromName   = $cfg['from_name'];
		};

		add_action( 'phpmailer_init', $init, 999 );

		$headers = array_values( array_filter( $headers, static fn( $h ) => stripos( $h, 'From:' ) !== 0 ) );
		$result  = wp_mail( $to, $subject, $body, $headers, $attachments );

		remove_action( 'phpmailer_init', $init, 999 );

		if ( ! $result ) {
			global $phpmailer;
			$this->last_error = ! empty( $phpmailer->ErrorInfo )
				? $phpmailer->ErrorInfo
				: 'SMTP send failed';
		}

		return $result;
	}

	public function get_last_error(): string {
		return $this->last_error;
	}
}
