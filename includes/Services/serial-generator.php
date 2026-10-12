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

		$wpdb->query( $wpdb->prepare( $sql . ' LIMIT 1', $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders
	}

	/** Kept for callers of the legacy class; the logic lives in SerialNumberService. */
	public function verify( $serial_number ) {
		return ( new \CertificateGenerator\Services\SerialNumberService() )->verify( (string) $serial_number );
	}
}
