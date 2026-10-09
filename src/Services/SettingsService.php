<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Central canonical store for all plugin options.
 *
 * Canonical keys use the cg_* prefix.  The migrate_legacy() method
 * back-fills them from the old certificate_generator_settings_email
 * serialized array so both UIs stay in sync after a single migration.
 */
class SettingsService {

	private static array $defaults = array(
		'certificate_generator_email_transport'     => 'wp_mail',
		'certificate_generator_email_from_name'     => '',
		'certificate_generator_email_from_email'    => '',
		'certificate_generator_email_subject'       => 'Your Certificate is Ready: {certificate_title}',
		'certificate_generator_email_body'          => '<p>Dear {name},</p><p>Congratulations! Your certificate for <strong>{certificate_title}</strong> is ready.</p><p>You can view and download your certificate using the link below:</p><p><a href="{result_link}">View Certificate</a></p><p>Thank you,<br>{site_name}</p>',
		'certificate_generator_smtp_host'           => '',
		'certificate_generator_smtp_port'           => 587,
		'certificate_generator_smtp_username'       => '',
		'certificate_generator_smtp_password'       => '',
		'certificate_generator_smtp_encryption'     => 'tls',
		'certificate_generator_sender_api_key'      => '',
		'certificate_generator_mandrill_api_key'    => '',
		'certificate_generator_serial_prefix'       => 'CERT',
		'certificate_generator_serial_length'       => 8,
		'certificate_generator_serial_suffix'       => '',
		'certificate_generator_serial_reset_period' => 'none',
		'certificate_generator_serial_include_date' => false,
	);

	/** Keys that infer their default from WP globals when not set. */
	private static array $inferred_defaults = array(
		'certificate_generator_email_from_name'  => 'get_bloginfo:name',
		'certificate_generator_email_from_email' => 'get_bloginfo:admin_email',
	);

	public static function get( string $key ) {
		if ( isset( self::$inferred_defaults[ $key ] ) && ! get_option( $key ) ) {
			[$fn, $arg] = explode( ':', self::$inferred_defaults[ $key ] );
			return $fn( $arg );
		}
		return get_option( $key, self::$defaults[ $key ] ?? '' );
	}

	public static function set( string $key, $value ): bool {
		return (bool) update_option( $key, $value );
	}

	/**
	 * Validate and save a batch of email/SMTP settings.
	 * Returns ['saved' => [...], 'errors' => [...]]
	 */
	public static function save_email_settings( array $raw ): array {
		$saved  = array();
		$errors = array();

		$transport = in_array( $raw['certificate_generator_email_transport'] ?? '', array( 'wp_mail', 'smtp', 'sender', 'mandrill' ), true )
			? sanitize_key( $raw['certificate_generator_email_transport'] )
			: 'wp_mail';
		self::set( 'certificate_generator_email_transport', $transport );
		$saved[] = 'certificate_generator_email_transport';

		$from_name = sanitize_text_field( $raw['certificate_generator_email_from_name'] ?? '' );
		self::set( 'certificate_generator_email_from_name', $from_name );
		$saved[] = 'certificate_generator_email_from_name';

		$from_email = sanitize_email( $raw['certificate_generator_email_from_email'] ?? '' );
		if ( $from_email && ! is_email( $from_email ) ) {
			$errors[] = 'Invalid From email address.';
		} else {
			self::set( 'certificate_generator_email_from_email', $from_email );
			$saved[] = 'certificate_generator_email_from_email';
		}

		self::set( 'certificate_generator_email_subject', sanitize_text_field( $raw['certificate_generator_email_subject'] ?? '' ) );
		$saved[] = 'certificate_generator_email_subject';

		self::set( 'certificate_generator_email_body', wp_kses_post( wp_unslash( $raw['certificate_generator_email_body'] ?? '' ) ) );
		$saved[] = 'certificate_generator_email_body';

		self::set( 'certificate_generator_smtp_host', sanitize_text_field( $raw['certificate_generator_smtp_host'] ?? '' ) );
		$saved[] = 'certificate_generator_smtp_host';

		self::set( 'certificate_generator_smtp_port', absint( $raw['certificate_generator_smtp_port'] ?? 587 ) );
		$saved[] = 'certificate_generator_smtp_port';

		self::set( 'certificate_generator_smtp_username', sanitize_text_field( $raw['certificate_generator_smtp_username'] ?? '' ) );
		$saved[] = 'certificate_generator_smtp_username';

		$enc = in_array( $raw['certificate_generator_smtp_encryption'] ?? '', array( 'tls', 'ssl', 'none' ), true )
			? sanitize_key( $raw['certificate_generator_smtp_encryption'] )
			: 'tls';
		self::set( 'certificate_generator_smtp_encryption', $enc );
		$saved[] = 'certificate_generator_smtp_encryption';

		// Only overwrite password when a new one is submitted; always store encrypted.
		$new_pass = $raw['certificate_generator_smtp_password'] ?? '';
		if ( $new_pass !== '' ) {
			self::set( 'certificate_generator_smtp_password', self::encrypt_password( $new_pass ) );
			$saved[] = 'certificate_generator_smtp_password';
		}

		// Same "only overwrite when a new value is submitted" pattern for API keys.
		$new_sender_key = $raw['certificate_generator_sender_api_key'] ?? '';
		if ( $new_sender_key !== '' ) {
			self::set( 'certificate_generator_sender_api_key', self::encrypt_password( $new_sender_key ) );
			$saved[] = 'certificate_generator_sender_api_key';
		}

		$new_mandrill_key = $raw['certificate_generator_mandrill_api_key'] ?? '';
		if ( $new_mandrill_key !== '' ) {
			self::set( 'certificate_generator_mandrill_api_key', self::encrypt_password( $new_mandrill_key ) );
			$saved[] = 'certificate_generator_mandrill_api_key';
		}

		return array(
			'saved'  => $saved,
			'errors' => $errors,
		);
	}

	/**
	 * Seed default option values on plugin activation.
	 * Uses add_option() so existing user customizations are never overwritten.
	 */
	public static function seed_defaults(): void {
		foreach ( self::$defaults as $key => $value ) {
			if ( $value !== '' ) {
				add_option( $key, $value, '', 'no' );
			}
		}

		self::seed_per_type_email_defaults();
	}

	/**
	 * Seed per-type (students/teachers/schools) email template defaults into
	 * certificate_generator_settings_email. Only fills missing keys so existing
	 * user edits are never overwritten.
	 */
	public static function seed_per_type_email_defaults(): void {
		$existing = get_option( 'certificate_generator_settings_email', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$changed = false;
		foreach ( array( 'students', 'teachers', 'schools' ) as $type ) {
			$singular = rtrim( $type, 's' );
			$label    = ucfirst( $singular );

			$defaults = array(
				$type . '_email_subject'            => "Your {$label} Certificate is Ready: {certificate_title}",
				$type . '_email_title'              => "Your {$label} Certificate is Ready",
				$type . '_email_message'            => "<p>Dear {name},</p>\n<p>Congratulations! Your <strong>{certificate_title}</strong> certificate is ready.</p>\n<p><a href=\"{result_link}\">View Your Results Online</a></p>\n<p>Thank you!</p>",
				$type . '_email_attach_certificate' => '1',
			);

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $existing[ $key ] ) || $existing[ $key ] === '' ) {
					$existing[ $key ] = $value;
					$changed          = true;
				}
			}
		}

		if ( $changed ) {
			update_option( 'certificate_generator_settings_email', $existing );
		}
	}

	/**
	 * Back-fill canonical cg_* options from the legacy serialized option.
	 * Safe to call on every boot — skips keys already set.
	 */
	public static function migrate_legacy(): void {
		$legacy = get_option( 'certificate_generator_settings_email', array() );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			return;
		}

		$map = array(
			'email'           => 'certificate_generator_email_from_email',
			'smtp_host'       => 'certificate_generator_smtp_host',
			'smtp_port'       => 'certificate_generator_smtp_port',
			'smtp_username'   => 'certificate_generator_smtp_username',
			'smtp_password'   => 'certificate_generator_smtp_password',
			'smtp_encryption' => 'certificate_generator_smtp_encryption',
		);

		foreach ( $map as $legacy_key => $canonical_key ) {
			if ( ! empty( $legacy[ $legacy_key ] ) && get_option( $canonical_key ) === false ) {
				update_option( $canonical_key, $legacy[ $legacy_key ] );
			}
		}
	}

	/**
	 * Read SMTP config with canonical keys taking priority, falling back
	 * to the legacy serialized option so both UIs stay in sync.
	 */
	public static function get_smtp_config(): array {
		$legacy = get_option( 'certificate_generator_settings_email', array() );

		$read = static function ( string $canonical, string $legacy_key, $default ) use ( $legacy ) {
			$val = get_option( $canonical, false );
			if ( $val !== false && $val !== '' ) {
				return $val;
			}
			return $legacy[ $legacy_key ] ?? $default;
		};

		// Password is stored encrypted regardless of whether it came via the
		// new SettingsService path or was migrated from the legacy option.
		$raw_pass = $read( 'certificate_generator_smtp_password', 'smtp_password', '' );

		return array(
			'host'       => $read( 'certificate_generator_smtp_host', 'smtp_host', '' ),
			'port'       => (int) $read( 'certificate_generator_smtp_port', 'smtp_port', 587 ),
			'username'   => $read( 'certificate_generator_smtp_username', 'smtp_username', '' ),
			'password'   => self::decrypt_password( $raw_pass ),
			'encryption' => $read( 'certificate_generator_smtp_encryption', 'smtp_encryption', 'tls' ),
			'from_email' => $read( 'certificate_generator_email_from_email', 'email', get_bloginfo( 'admin_email' ) ),
			'from_name'  => self::get( 'certificate_generator_email_from_name' ),
		);
	}

	public static function get_sender_config(): array {
		return array(
			'api_key'    => self::decrypt_password( (string) get_option( 'certificate_generator_sender_api_key', '' ) ),
			'from_email' => self::get( 'certificate_generator_email_from_email' ),
			'from_name'  => self::get( 'certificate_generator_email_from_name' ),
		);
	}

	public static function get_mandrill_config(): array {
		return array(
			'api_key'    => self::decrypt_password( (string) get_option( 'certificate_generator_mandrill_api_key', '' ) ),
			'from_email' => self::get( 'certificate_generator_email_from_email' ),
			'from_name'  => self::get( 'certificate_generator_email_from_name' ),
		);
	}

	// ── Symmetric password encryption ────────────────────────────────────────
	// Uses AES-256-CBC keyed on SECURE_AUTH_KEY so the ciphertext is bound
	// to this WordPress installation and is not portable.

	private static function encrypt_password( string $plaintext ): string {
		if ( $plaintext === '' || ! function_exists( 'openssl_encrypt' ) ) {
			return $plaintext;
		}
		$key    = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
		$iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
		$iv     = random_bytes( $iv_len );
		$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( $iv . $cipher );
	}

	private static function decrypt_password( string $stored ): string {
		if ( $stored === '' || ! function_exists( 'openssl_decrypt' ) ) {
			return $stored;
		}
		$decoded = base64_decode( $stored, true );
		if ( $decoded === false ) {
			return $stored; // not base64 → treat as plaintext (legacy plaintext migration)
		}
		$key    = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
		$iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
		if ( strlen( $decoded ) <= $iv_len ) {
			return $stored;
		}
		$iv     = substr( $decoded, 0, $iv_len );
		$cipher = substr( $decoded, $iv_len );
		$plain  = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
		return $plain === false ? $stored : $plain;
	}
}
