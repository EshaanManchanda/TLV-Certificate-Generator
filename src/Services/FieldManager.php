<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Splits/merges the core-columns-vs-extra_fields-JSON shape used by the
 * students/teachers/schools admin CRUD pages, so CSV import/export and
 * save paths share one definition of "core" fields per entity type.
 */
class FieldManager {

	private const CORE_FIELDS = array(
		'students' => array(
			'student_name', 'email', 'phone', 'school_name', 'certificate_type',
			'issue_date', 'year', 'enrollment_date', 'graduation_date', 'status',
		),
		'teachers' => array(
			'teacher_name', 'email', 'phone', 'school_name', 'department',
			'certificate_type', 'issue_date', 'year', 'hire_date', 'status',
		),
		'schools' => array(
			'school_name', 'email', 'phone', 'address', 'city', 'state', 'country',
			'postal_code', 'website', 'principal_name', 'certificate_type',
			'issue_date', 'year', 'status',
		),
	);

	/**
	 * @return string[] Core column names for the given entity type ('students'|'teachers'|'schools').
	 */
	public static function get_core_fields( string $type ): array {
		return self::CORE_FIELDS[ $type ] ?? array();
	}

	/**
	 * Distinct custom-field keys actually used in an entity type's extra_fields
	 * JSON column, so the template field-mapping dropdown can offer real custom
	 * fields without needing a separate registration step (unlike the old
	 * CG_Field_Schema registry, which only CSV import ever wrote to).
	 *
	 * @return string[]
	 */
	public static function discover_extra_field_keys( string $type ): array {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $type );
		if ( ! $table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$blobs = $wpdb->get_col( "SELECT extra_fields FROM $table WHERE extra_fields IS NOT NULL AND extra_fields != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$keys = array();
		foreach ( $blobs as $blob ) {
			$decoded = json_decode( (string) $blob, true );
			if ( is_array( $decoded ) ) {
				$keys += array_fill_keys( array_keys( $decoded ), true );
			}
		}

		return array_keys( $keys );
	}

	public static function split( string $type, array $flat ): array {
		$core_keys = self::CORE_FIELDS[ $type ] ?? array();
		$core      = array();
		$extra     = array();

		foreach ( $flat as $key => $value ) {
			$key = str_starts_with( $key, 'field_' ) ? substr( $key, strlen( 'field_' ) ) : $key;

			if ( in_array( $key, $core_keys, true ) ) {
				$core[ $key ] = $value;
				continue;
			}

			if ( $value === '' || $value === null ) {
				continue;
			}

			$extra[ $key ] = $value;
		}

		return array(
			'core'  => $core,
			'extra' => $extra,
		);
	}

	public static function merge( array $row, ?string $extra_fields_json ): array {
		unset( $row['extra_fields'] );

		if ( $extra_fields_json ) {
			$decoded = json_decode( $extra_fields_json, true );
			if ( is_array( $decoded ) ) {
				$row = array_merge( $row, $decoded );
			}
		}

		return $row;
	}

	public static function prepare_for_db( string $type, array $flat ): array {
		$result = self::split( $type, $flat );

		$row = $result['core'];
		if ( ! empty( $result['extra'] ) ) {
			$row['extra_fields'] = json_encode( $result['extra'] );
		}

		return $row;
	}

	public static function prepare_for_display( array $db_row ): array {
		return self::merge( $db_row, $db_row['extra_fields'] ?? null );
	}
}
