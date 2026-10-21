<?php
/**
 * Recipient (student / teacher / school) writes shared by the admin pages and add-ons,
 * so every save fires the same lifecycle hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Insert ($id = 0) or update a recipient row. $data must already be sanitized column => value.
 *
 * @param string $entity students | teachers | schools.
 * @return int|WP_Error The row id.
 */
function certificate_generator_save_recipient( string $entity, array $data, int $id = 0 ) {
	global $wpdb;
	if ( ! in_array( $entity, array( 'students', 'teachers', 'schools' ), true ) || ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return new WP_Error( 'invalid_entity', 'Unknown recipient type.' );
	}
	$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );

	$is_new             = ! $id;
	$data['updated_at'] = $data['updated_at'] ?? current_time( 'mysql' );
	if ( ! $is_new ) {
		$result = $wpdb->update( $table, $data, array( 'id' => $id ) );
	} else {
		$data['created_at'] = $data['created_at'] ?? current_time( 'mysql' );
		$result             = $wpdb->insert( $table, $data );
		$id                 = (int) $wpdb->insert_id;
	}
	if ( false === $result ) {
		return new WP_Error( 'db_error', $wpdb->last_error ?: 'Database error.' );
	}

	if ( function_exists( 'certificate_generator_flush_filter_caches' ) ) {
		certificate_generator_flush_filter_caches();
	}

	/**
	 * Fires after a recipient row is created or updated through the admin or an add-on.
	 * Bulk CSV imports fire certificate_generator_import_completed instead.
	 *
	 * @param string $entity students | teachers | schools.
	 * @param int    $id     Row id.
	 * @param bool   $is_new True on insert.
	 */
	do_action( 'certificate_generator_recipient_saved', $entity, $id, $is_new );

	return $id;
}

/** Fields printed for a recipient type when the template's schema doesn't say. */
function certificate_generator_entity_gen_fields( string $entity, string $certificate_type ): array {
	if ( 'teachers' === $entity ) {
		return array( 'teacher_name', 'school_name', 'issue_date' );
	}
	if ( 'schools' === $entity ) {
		return array( 'school_name', 'place', 'issue_date' );
	}
	return class_exists( 'CertificateGenerator_Field_Schema' )
		? CertificateGenerator_Field_Schema::get_all_renderable_fields( $certificate_type )
		: array( 'student_name', 'school_name', 'issue_date' );
}

/**
 * Render (or reuse) the certificate of one recipient row: bulk jobs, WP-CLI and the Pro API
 * all go through here. Safe to repeat: the first render writes the serial back to the row and
 * every later one reuses it (no second certificate), and the PDF cache returns the existing
 * PDF untouched unless the data or template changed. $force skips the cache (same serial).
 *
 * @param string $entity students | teachers | schools.
 * @return array{certificate_id:int,created:bool}|WP_Error created: a new certificate was issued.
 */
function certificate_generator_generate_for_row( string $entity, array $row, bool $force = false ) {
	global $wpdb;
	if ( '' === (string) ( $row['certificate_type'] ?? '' ) ) {
		return new WP_Error( 'no_certificate_type', 'The recipient has no certificate type, so no template matches.', array( 'status' => 422 ) );
	}
	if ( ! certificate_generator_resolve_entity_template( $row ) ) {
		return new WP_Error( 'generation_failed', sprintf( 'No published template for certificate type "%s"%s.', $row['certificate_type'], empty( $row['issue_date'] ) ? '' : ' and issue date ' . $row['issue_date'] ), array( 'status' => 500 ) );
	}
	if ( ! isset( $row['place'] ) && isset( $row['city'] ) ) {
		$row['place'] = $row['city'];
	}

	$issued_id    = 0;
	$generated_id = 0;
	$on_issued    = static function ( $id ) use ( &$issued_id ) {
		$issued_id = (int) $id;
	};
	$on_generated = static function ( $post_id, $path, $new, $id ) use ( &$generated_id ) {
		$generated_id = (int) $id;
	};
	add_action( 'certificate_generator_certificate_issued', $on_issued, 5, 1 );
	add_action( 'certificate_generator_certificate_generated', $on_generated, 5, 4 );
	if ( $force ) {
		add_filter( 'certificate_generator_pdf_cache_enabled', '__return_false', 99 );
	}
	try {
		// Rows without a CPT post pass their table id; the generator keys those PDFs by entity + id.
		$post_id = (int) ( ! empty( $row['wp_post_id'] ) ? $row['wp_post_id'] : $row['id'] );
		$url     = certificate_generator_generate_certificate_pdf( $post_id, certificate_generator_entity_gen_fields( $entity, (string) $row['certificate_type'] ), $row );
	} finally {
		remove_action( 'certificate_generator_certificate_issued', $on_issued, 5 );
		remove_action( 'certificate_generator_certificate_generated', $on_generated, 5 );
		if ( $force ) {
			remove_filter( 'certificate_generator_pdf_cache_enabled', '__return_false', 99 );
		}
	}
	if ( ! $url ) {
		return new WP_Error( 'generation_failed', 'The certificate could not be generated. Check the debug log.', array( 'status' => 500 ) );
	}

	$cert_id = $generated_id ?: $issued_id;
	if ( ! $cert_id ) { // a cached PDF: no hook fired, the certificate exists already
		$table  = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificates' );
		$serial = (string) ( $row['serial_number'] ?? '' );
		$email  = (string) ( $row['email'] ?? '' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		if ( '' !== $serial ) {
			$cert_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE serial_number = %s ORDER BY id DESC LIMIT 1", $serial ) );
		}
		if ( ! $cert_id && '' !== $email ) {
			$cert_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE recipient_email = %s AND certificate_type = %s ORDER BY id DESC LIMIT 1", $email, $row['certificate_type'] ) );
		}
		// phpcs:enable
	}
	if ( ! $cert_id ) {
		return new WP_Error( 'generation_failed', 'The PDF was generated but no certificate record was found.', array( 'status' => 500 ) );
	}
	return array( 'certificate_id' => $cert_id, 'created' => (bool) $issued_id );
}
