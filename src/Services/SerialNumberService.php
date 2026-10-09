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

	public function verify( string $serial ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'certificate_generator';

		$cert = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE serial_number = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
				sanitize_text_field( $serial )
			),
			ARRAY_A
		);

		if ( ! $cert ) {
			return array(
				'valid'   => false,
				'message' => 'Certificate not found',
				'data'    => null,
			);
		}

		$expired = false;
		if ( ! empty( $cert['expires_at'] ) ) {
			$expired = strtotime( $cert['expires_at'] ) < time();
		}

		if ( ! empty( $cert['revoked_at'] ) ) {
			return array(
				'valid'   => false,
				'revoked' => true,
				'message' => 'This certificate has been revoked' . ( ! empty( $cert['revoked_reason'] ) ? ': ' . $cert['revoked_reason'] : '.' ),
				'data'    => array(
					'student_name'  => $cert['student_name'],
					'serial_number' => $cert['serial_number'],
					'revoked_at'    => $cert['revoked_at'],
				),
			);
		}

		// The legacy wp_certificate_generator table (queried above) has no pdf_url
		// column; the newer wp_cg_certificates table does and can carry the same
		// serial_number (certificate_generator_insert_certificate_record() dedupes serials across both),
		// so look there for a downloadable URL to hand the LinkedIn "Add to Profile" button.
		$pdf_url        = '';
		$sql_cert_table = $wpdb->prefix . 'cg_certificates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sql_cert_table ) ) === $sql_cert_table ) {
			$pdf_url = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT pdf_url FROM $sql_cert_table WHERE serial_number = %s LIMIT 1", $serial ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
			);
		}

		return array(
			'valid'   => true,
			'expired' => $expired,
			'message' => $expired ? 'Certificate has expired' : 'Certificate is valid',
			'data'    => array(
				'id'               => $cert['id'],
				'student_name'     => $cert['student_name'],
				'certificate_type' => $cert['certificate_type'] ?? '',
				'issued_at'        => $cert['issued_at'],
				'expires_at'       => $cert['expires_at'],
				'serial_number'    => $cert['serial_number'],
				'created_at'       => $cert['created_at'],
				'pdf_url'          => $pdf_url,
				'issuer_name'      => get_bloginfo( 'name' ),
			),
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
		$status = $result['valid'] || ! empty( $result['revoked'] ) ? 200 : 404;
		return new \WP_REST_Response( $result, $status );
	}
}
