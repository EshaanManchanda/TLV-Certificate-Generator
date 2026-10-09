<?php
/**
 * CG_Field_Schema — manages per-certificate-type extra field definitions.
 *
 * Extra fields are stored in wp_options keyed by certificate type:
 *   cg_extra_fields_{cert_type_key}  →  ['team_name', 'score']
 *
 * Field values are stored as normal post_meta on student posts.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derive a 4-digit year integer from a stored ISO date string (Y-m-d).
 * Returns null when the date is empty or not a valid post-1970 year.
 *
 * @param string|null $iso_date Date string, e.g. "2025-04-10".
 * @return int|null
 */
function cg_year_from_issue_date( ?string $iso_date ): ?int {
	if ( empty( $iso_date ) ) {
		return null;
	}
	$year = (int) substr( $iso_date, 0, 4 );
	return $year > 1970 ? $year : null;
}

class CG_Field_Schema {

	/** Fields rendered on every certificate (order = slot index). */
	const STANDARD_FIELDS = array( 'student_name', 'school_name', 'teacher_name', 'issue_date' );

	/** Fields stored as meta but never rendered on the PDF. */
	const META_ONLY_FIELDS = array( 'email', 'certificate_type', 'school_abbreviation' );

	/** Maximum total renderable slots (standard + extra). Increased to support wide CSVs. */
	const MAX_FIELDS = 50;

	// ── Key helpers ──────────────────────────────────────────────────────────

	/**
	 * Convert a human-readable cert type to a safe option key.
	 * "Competition 2025" → "competition_2025"
	 */
	public static function cert_type_to_key( string $cert_type ): string {
		return sanitize_key( str_replace( array( ' ', '-' ), '_', strtolower( trim( $cert_type ) ) ) );
	}

	// ── Field retrieval ──────────────────────────────────────────────────────

	/**
	 * Return extra (non-standard) fields registered for a certificate type.
	 */
	public static function get_extra_fields( string $cert_type ): array {
		if ( empty( $cert_type ) ) {
			return array();
		}
		$key    = self::cert_type_to_key( $cert_type );
		$fields = get_option( 'cg_extra_fields_' . $key, array() );
		return is_array( $fields ) ? array_values( $fields ) : array();
	}

	/**
	 * Return ALL renderable fields (standard + extra) in slot order.
	 * Slot 1 = standard[0], Slot 2 = standard[1], …, Slot N = extra[N-3]
	 */
	public static function get_all_renderable_fields( string $cert_type ): array {
		return array_merge( self::STANDARD_FIELDS, self::get_extra_fields( $cert_type ) );
	}

	// ── Registration ─────────────────────────────────────────────────────────

	/**
	 * Register an extra field for a certificate type.
	 * No-op if already registered or if it is a standard/meta-only field.
	 * Returns true on success, false if not registered.
	 */
	public static function register_field( string $cert_type, string $slug ): bool {
		if ( empty( $cert_type ) || empty( $slug ) ) {
			return false;
		}
		// Reject standard and meta-only slugs
		if ( self::is_standard_field( $slug ) || in_array( $slug, self::META_ONLY_FIELDS, true ) ) {
			return false;
		}

		$key    = self::cert_type_to_key( $cert_type );
		$fields = get_option( 'cg_extra_fields_' . $key, array() );
		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		if ( in_array( $slug, $fields, true ) ) {
			return true; // Already registered
		}

		$max_extra = self::MAX_FIELDS - count( self::STANDARD_FIELDS );
		if ( count( $fields ) >= $max_extra ) {
			return false; // Slot limit reached
		}

		$fields[] = $slug;
		update_option( 'cg_extra_fields_' . $key, $fields, false );
		return true;
	}

	/**
	 * Remove an extra field from a certificate type's schema.
	 * Existing post_meta values are preserved (data is not deleted).
	 */
	public static function unregister_field( string $cert_type, string $slug ): bool {
		if ( empty( $cert_type ) || empty( $slug ) ) {
			return false;
		}
		$key    = self::cert_type_to_key( $cert_type );
		$fields = get_option( 'cg_extra_fields_' . $key, array() );
		if ( ! is_array( $fields ) ) {
			return false;
		}
		$updated = array_values( array_diff( $fields, array( $slug ) ) );
		update_option( 'cg_extra_fields_' . $key, $updated, false );
		return true;
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/** True if the slug is one of the three standard renderable fields. */
	public static function is_standard_field( string $slug ): bool {
		return in_array( $slug, self::STANDARD_FIELDS, true );
	}

	/**
	 * Convert a field slug to a human-readable label.
	 * "team_name" → "Team Name"
	 */
	public static function get_display_label( string $slug ): string {
		return ucwords( str_replace( '_', ' ', $slug ) );
	}

	/**
	 * Return all certificate types that have at least one extra field registered.
	 * Returns an array of [ 'key' => option_key, 'label' => cert_type, 'fields' => [...] ]
	 */
	public static function get_all_registered_types(): array {
		global $wpdb;
		$like    = $wpdb->esc_like( 'cg_extra_fields_' ) . '%';
		$options = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like )
		);
		$result  = array();
		foreach ( $options as $row ) {
			$key    = $row->option_name;
			$fields = maybe_unserialize( $row->option_value );
			if ( is_array( $fields ) && ! empty( $fields ) ) {
				$type_key = str_replace( 'cg_extra_fields_', '', $key );
				$result[] = array(
					'key'    => $type_key,
					'label'  => $type_key, // raw key; admins see the key
					'fields' => $fields,
				);
			}
		}
		return $result;
	}
}
