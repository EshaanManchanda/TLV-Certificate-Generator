<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CertificateGenerator_Serial_Number_Generator {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function generate( $certificate_type = '', $student_data = null ) {
		// One implementation for format + sequencing, shared with SerialNumberService.
		$serial = ( new \CertificateGenerator\Services\SerialNumberService() )->generate( (string) $certificate_type );

		// Update the entity row if student_data is provided
		if ( ! empty( $student_data ) ) {
			$this->update_student_serial( $student_data, $serial, $certificate_type );
		}

		return $serial;
	}

	/**
	 * Store a newly generated serial on the ONE entity row it was generated for.
	 *
	 * $student_data keys: id, table (students|teachers|schools), email, student_name,
	 * issue_date. Without an id it matches email (or name) + certificate_type +
	 * issue_date, so a person's other events keep their own serial. Only fills an
	 * empty serial and never changes certificate_type.
	 */
	private function update_student_serial( $student_data, $serial, $certificate_type ) {
		global $wpdb;

		if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			return;
		}

		$name_cols = array(
			'students' => 'student_name',
			'teachers' => 'teacher_name',
			'schools'  => 'school_name',
		);
		$entity    = $student_data['table'] ?? 'students';
		if ( ! isset( $name_cols[ $entity ] ) ) {
			$entity = 'students';
		}

		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$only_empty = "( serial_number IS NULL OR serial_number = '' )";

		if ( ! empty( $student_data['id'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET serial_number = %s WHERE id = %d AND $only_empty", $serial, (int) $student_data['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		$email = (string) ( $student_data['email'] ?? '' );
		$name  = (string) ( $student_data['student_name'] ?? '' );
		if ( '' === (string) $certificate_type || ( '' === $email && '' === $name ) ) {
			return;
		}

		if ( '' !== $email ) {
			$sql  = "UPDATE $table SET serial_number = %s WHERE email = %s AND certificate_type = %s AND $only_empty";
			$args = array( $serial, $email, $certificate_type );
		} else {
			$sql  = "UPDATE $table SET serial_number = %s WHERE {$name_cols[ $entity ]} = %s AND certificate_type = %s AND $only_empty";
			$args = array( $serial, $name, $certificate_type );
		}

		$issue_date = (string) ( $student_data['issue_date'] ?? '' );
		$dt         = DateTime::createFromFormat( '!Y-m-d', $issue_date ) ?: DateTime::createFromFormat( '!d-m-Y', $issue_date );
		if ( $dt ) {
			$sql   .= ' AND issue_date = %s';
			$args[] = $dt->format( 'Y-m-d' );
		}

		$wpdb->query( $wpdb->prepare( $sql . ' LIMIT 1', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function verify( $serial_number ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'certificate_generator';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$cert = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table_name WHERE serial_number = %s",
				sanitize_text_field( $serial_number )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $cert ) {
			return array(
				'valid'   => false,
				'message' => 'Certificate not found',
				'data'    => null,
			);
		}

		if ( ! empty( $cert->revoked_at ) ) {
			return array(
				'valid'   => false,
				'revoked' => true,
				'message' => 'This certificate has been revoked' . ( ! empty( $cert->revoked_reason ) ? ': ' . $cert->revoked_reason : '.' ),
				'data'    => array(
					'student_name'  => $cert->student_name,
					'serial_number' => $cert->serial_number,
					'revoked_at'    => $cert->revoked_at,
				),
			);
		}

		$is_expired = false;
		$expires_at = null;
		if ( $cert->expires_at ) {
			$expires_at = $cert->expires_at;
			$is_expired = strtotime( $cert->expires_at ) < time();
		}

		// The legacy wp_certificate_generator table (queried above) has no pdf_url
		// column; the newer wp_cg_certificates table does and can carry the same
		// serial_number (certificate_generator_insert_certificate_record() dedupes serials across both),
		// so look there for a downloadable URL to hand the LinkedIn "Add to Profile" button.
		$pdf_url        = '';
		$sql_cert_table = $wpdb->prefix . 'cg_certificates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sql_cert_table ) ) === $sql_cert_table ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$pdf_url = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT pdf_url FROM $sql_cert_table WHERE serial_number = %s LIMIT 1", $serial_number )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array(
			'valid'   => true,
			'expired' => $is_expired,
			'message' => $is_expired ? 'Certificate has expired' : 'Certificate is valid',
			'data'    => array(
				'id'               => $cert->id,
				'student_name'     => $cert->student_name,
				'certificate_type' => $cert->certificate_type ?? '',
				'issued_at'        => $cert->issued_at,
				'expires_at'       => $expires_at,
				'serial_number'    => $cert->serial_number,
				'created_at'       => $cert->created_at,
				'pdf_url'          => $pdf_url,
				'issuer_name'      => get_bloginfo( 'name' ),
			),
		);
	}

	public function register_api_endpoints() {
		add_action(
			'rest_api_init',
			function () {
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
		);
	}

	public function api_verify( $request ) {
		if ( class_exists( 'CertificateGenerator_SecurityHelper' )
			&& ! CertificateGenerator_SecurityHelper::check_rate_limit( 'cg_verify_rest', 20, 60 )
		) {
			return new WP_REST_Response(
				array(
					'valid'   => false,
					'message' => 'Too many verification attempts. Please try again in a minute.',
				),
				429
			);
		}

		$serial = $request->get_param( 'serial' );
		$result = $this->verify( $serial );

		return new WP_REST_Response( $result, $result['valid'] ? 200 : 404 );
	}
}
