<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Serial number generation service.
 */
class SerialNumberService {

	private string $prefix;
	private int $length;
	private string $suffix;
	private bool $include_date;
	private string $reset_period;

	public function __construct() {
		$this->prefix       = get_option( 'certificate_generator_serial_prefix', 'CERT' );
		$this->length       = (int) get_option( 'certificate_generator_serial_length', 8 );
		$this->suffix       = get_option( 'certificate_generator_serial_suffix', '' );
		$this->include_date = (bool) get_option( 'certificate_generator_serial_include_date', false );
		$this->reset_period = get_option( 'certificate_generator_serial_reset_period', 'none' );
	}

	/**
	 * @param string $certificate_type Unused since serials share one counter across
	 *                                 types; kept so existing callers don't change.
	 */
	public function generate( string $certificate_type = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$date_part = $this->get_date_part();

		// Skip numbers already taken, e.g. serials imported via CSV.
		// ponytail: capped at 1000 skips; a larger imported block needs the counter raised by hand.
		for ( $i = 0; $i < 1000; $i++ ) {
			$padded = str_pad( (string) $this->next_sequence(), $this->length, '0', STR_PAD_LEFT );
			$serial = $this->prefix . '-' . $date_part . $padded;
			if ( '' !== $this->suffix ) {
				$serial .= '-' . $this->suffix;
			}
			if ( ! self::serial_exists( $serial ) ) {
				return $serial;
			}
		}

		certificate_generator_debug_log( 'Certificate Generator: no free serial found after 1000 attempts; last tried ' . $serial ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional ops signal.
		return $serial;
	}

	/**
	 * Whether a serial is already used by any issued certificate or entity row.
	 */
	public static function serial_exists( string $serial ): bool {
		global $wpdb;
		static $tables = null;

		if ( null === $tables ) {
			$tables = array();
			foreach ( array( 'certificate_generator', 'cg_certificates', 'cg_students', 'cg_teachers', 'cg_schools' ) as $t ) {
				$name = $wpdb->prefix . $t;
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) === $name ) {
					$tables[] = $name;
				}
			}
		}

		foreach ( $tables as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE serial_number = %s LIMIT 1", $serial ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return true;
			}
		}
		return false;
	}

	/**
	 * Public verification. The input is a verification code (what the QR carries) or a serial.
	 *
	 * A code gets the full record. Serials are sequential and can be walked, so a serial
	 * alone gets the status with a masked name, unless the site opts back in with the
	 * certificate_generator_verify_serial_full_details option.
	 */
	public function verify( string $input, bool $record = true ): array {
		$input = trim( sanitize_text_field( $input ) );
		$code  = VerificationCodeService::normalize( $input );
		// The check character rejects typos and walked guesses before any query.
		$cert = VerificationCodeService::is_well_formed( $code ) ? $this->find_certificate( 'verification_code', $code ) : null;
		$full = null !== $cert;
		if ( ! $cert && '' !== $input ) {
			$cert = $this->find_certificate( 'serial_number', $input );
			$full = '1' === (string) get_option( 'certificate_generator_verify_serial_full_details', '0' );
		}

		if ( ! $cert ) {
			return array(
				'valid'   => false,
				'message' => 'Certificate not found',
				'data'    => null,
			);
		}

		// Admins checking a certificate aren't verifiers; keep them out of the audit trail.
		if ( $record && function_exists( 'certificate_generator_record_event' ) && ! current_user_can( 'certificate_generator_manage_settings' ) ) {
			\certificate_generator_record_event( (string) $cert['serial_number'], 'verified', array( 'by' => $full ? 'code' : 'serial' ) );
			/** Fires when a visitor (not an admin) verifies a certificate; $by is 'code' or 'serial'. */
			\do_action( 'certificate_generator_certificate_verified', (string) $cert['serial_number'], $full ? 'code' : 'serial' );
		}

		// The recipient can hide their name from verification (My Certificates page).
		$hidden = isset( $cert['show_name'] ) && '0' === (string) $cert['show_name'];
		$name   = $hidden ? '' : ( $full ? (string) $cert['student_name'] : self::mask_name( (string) $cert['student_name'] ) );

		if ( ! empty( $cert['revoked_at'] ) ) {
			return array(
				'valid'   => false,
				'revoked' => true,
				'message' => 'This certificate has been revoked' . ( $full && ! empty( $cert['revoked_reason'] ) ? ': ' . $cert['revoked_reason'] : '.' ),
				'data'    => array(
					'student_name'  => $name,
					'serial_number' => $cert['serial_number'],
					'revoked_at'    => $cert['revoked_at'],
					'masked'        => ! $full,
					'name_hidden'   => $hidden,
				),
			);
		}

		$expired = ! empty( $cert['expires_at'] ) && strtotime( $cert['expires_at'] ) < time();

		// Public response: no PDF URL or internal ids.
		return array(
			'valid'   => true,
			'expired' => $expired,
			'message' => $expired ? 'Certificate has expired' : 'Certificate is valid',
			'data'    => array(
				'student_name'     => $name,
				'certificate_type' => $cert['certificate_type'] ?? '',
				'issued_at'        => $cert['issued_at'],
				'expires_at'       => $cert['expires_at'],
				'serial_number'    => $cert['serial_number'],
				'issuer_name'      => get_bloginfo( 'name' ),
				'masked'           => ! $full,
				'name_hidden'      => $hidden,
				'corrected_at'     => self::last_correction( (string) $cert['serial_number'] ),
				// Only for full answers: the visitor already holds the code (or the site opted out of masking).
				'verification_code' => $full ? VerificationCodeService::format( VerificationCodeService::for_serial( (string) $cert['serial_number'] ) ) : '',
			),
		);
	}

	/**
	 * Certificate row by serial_number or verification_code. Falls back to wp_cg_certificates
	 * for certificates recorded only there (no legacy row).
	 *
	 * @param string $column 'serial_number' or 'verification_code' (never user input).
	 */
	private function find_certificate( string $column, string $value ): ?array {
		global $wpdb;
		$column = 'verification_code' === $column ? 'verification_code' : 'serial_number';
		$legacy = $wpdb->prefix . 'certificate_generator';
		$modern = $wpdb->prefix . 'cg_certificates';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix + constant; $column is whitelisted above.
		$cert = $wpdb->get_row(
			$wpdb->prepare( "SELECT student_name, certificate_type, issued_at, expires_at, serial_number, show_name, revoked_at, revoked_reason FROM {$legacy} WHERE {$column} = %s LIMIT 1", $value ),
			ARRAY_A
		);
		if ( ! $cert ) {
			$cert = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT recipient_name AS student_name, certificate_type, issued_at, expires_at, serial_number, show_name,
						IF(status = 'revoked', updated_at, NULL) AS revoked_at, NULL AS revoked_reason
					FROM {$modern} WHERE {$column} = %s ORDER BY id DESC LIMIT 1",
					$value
				),
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $cert ? $cert : null;
	}

	/** When the certificate was last corrected (reissued or name changed), or '' if never. */
	private static function last_correction( string $serial ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}cg_certificate_events WHERE certificate_serial = %s AND event_type IN ('reissued','name_changed') ORDER BY id DESC LIMIT 1", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix
	}

	/** "Riya Sharma" -> "R*** S*****": first letter of each word (multibyte-safe). */
	public static function mask_name( string $name ): string {
		return (string) preg_replace_callback(
			'/\S+/u',
			static function ( $m ) {
				return mb_substr( $m[0], 0, 1 ) . str_repeat( '*', max( 1, mb_strlen( $m[0] ) - 1 ) );
			},
			$name
		);
	}

	private function get_date_part(): string {
		if ( ! $this->include_date ) {
			return '';
		}

		$stamp = $this->period_stamp();
		return '' === $stamp ? '' : $stamp . '-';
	}

	/**
	 * Current reset-period stamp (Ymd / Ym / Y), or '' when serials never reset.
	 */
	private function period_stamp(): string {
		$formats = array(
			'daily'   => 'Ymd',
			'monthly' => 'Ym',
			'yearly'  => 'Y',
		);
		return isset( $formats[ $this->reset_period ] ) ? gmdate( $formats[ $this->reset_period ] ) : '';
	}

	/**
	 * Next number from the single counter shared by all certificate types.
	 *
	 * Per-type counters (certificate_generator_serial_seq_{type}) with a shared prefix let two types
	 * issue the same serial, so every type now draws from certificate_generator_serial_seq_all.
	 */
	public function next_sequence(): int {
		global $wpdb;

		$stamp         = $this->period_stamp();
		$period_suffix = '' === $stamp ? '' : '_' . $stamp;
		$option_key    = 'certificate_generator_serial_seq_all' . $period_suffix;

		// First use: start above every old per-type counter of this period so no
		// previously issued number is handed out again. A no-op once the row exists.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
				SELECT %s, COALESCE( MAX( CAST( option_value AS UNSIGNED ) ), 0 ), 'no'
				FROM {$wpdb->options} WHERE option_name LIKE %s",
				$option_key,
				$wpdb->esc_like( 'certificate_generator_serial_seq_' ) . '%' . $wpdb->esc_like( $period_suffix )
			)
		);

		// LAST_INSERT_ID(expr) is per-connection, so increment + read is atomic
		// across concurrent requests (a separate SELECT could read another's value).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID( option_value + 1 ) WHERE option_name = %s",
				$option_key
			)
		);

		return (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	}

	/** Must be called during rest_api_init (Plugin::register_api_routes). */
	public function register_api_routes(): void {
		register_rest_route(
			'certificate-generator/v1',
			'/verify/(?P<serial>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'api_verify' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function api_verify( $request ): \WP_REST_Response {
		if ( class_exists( 'CertificateGenerator_SecurityHelper' )
			&& ! \CertificateGenerator_SecurityHelper::check_rate_limit( 'cg_verify_rest', 20, 60 )
		) {
			return new \WP_REST_Response(
				array(
					'valid'   => false,
					'message' => 'Too many verification attempts. Please try again in a minute.',
				),
				429
			);
		}

		$serial = $request->get_param( 'serial' );
		$result = $this->verify( $serial );
		$status   = $result['valid'] || ! empty( $result['revoked'] ) ? 200 : 404;
		$response = new \WP_REST_Response( $result, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
