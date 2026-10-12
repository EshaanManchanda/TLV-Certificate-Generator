<?php
/**
 * Personal data export and erasure (Tools → Export / Erase Personal Data).
 *
 * Export: the person's student and teacher rows, their certificates and their email logs and queue rows.
 * Erase:  student, teacher, email log and queue rows are deleted, and so are their cached PDFs. Certificates
 *         are anonymised, not deleted, so a printed certificate still verifies ("Valid, issued to [erased]").
 *         Their history events lose any stored details.
 *
 * @package CertificateGenerator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CERTIFICATE_GENERATOR_PRIVACY_PAGE = 50;

/**
 * Tables that hold an email address: [ table, email column, export group label ].
 * Missing tables (a site that never ran a migration) are skipped.
 *
 * @return array<string,array{0:string,1:string,2:string}>
 */
function certificate_generator_privacy_tables(): array {
	global $wpdb;
	$cg = $wpdb->prefix . 'cg_';
	$tables = array(
		'students'        => array( $cg . 'students', 'email', __( 'Certificate Generator: student records', 'certificate-generator' ) ),
		'teachers'        => array( $cg . 'teachers', 'email', __( 'Certificate Generator: teacher records', 'certificate-generator' ) ),
		'certificates'    => array( $cg . 'certificates', 'recipient_email', __( 'Certificate Generator: certificates', 'certificate-generator' ) ),
		'legacy_certs'    => array( $wpdb->prefix . 'certificate_generator', 'email', __( 'Certificate Generator: certificates', 'certificate-generator' ) ),
		'email_logs'      => array( $wpdb->prefix . 'cert_email_logs', 'recipient_email', __( 'Certificate Generator: emails sent', 'certificate-generator' ) ),
		'cg_email_logs'   => array( $cg . 'email_logs', 'recipient_email', __( 'Certificate Generator: emails sent', 'certificate-generator' ) ),
		'email_queue'     => array( $wpdb->prefix . 'cert_email_queue', 'recipient_email', __( 'Certificate Generator: queued emails', 'certificate-generator' ) ),
	);
	return array_filter( $tables, static fn( $t ) => $t[0] === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t[0] ) ) );
}

add_filter(
	'wp_privacy_personal_data_exporters',
	static function ( array $exporters ): array {
		$exporters['certificate-generator'] = array(
			'exporter_friendly_name' => __( 'Certificate Generator', 'certificate-generator' ),
			'callback'               => 'certificate_generator_privacy_export',
		);
		return $exporters;
	}
);

add_filter(
	'wp_privacy_personal_data_erasers',
	static function ( array $erasers ): array {
		$erasers['certificate-generator'] = array(
			'eraser_friendly_name' => __( 'Certificate Generator', 'certificate-generator' ),
			'callback'             => 'certificate_generator_privacy_erase',
		);
		return $erasers;
	}
);

/**
 * Exporter: page N returns rows N*50-49 … N*50 of every table; done when every table ran out.
 *
 * @return array{data:array,done:bool}
 */
function certificate_generator_privacy_export( string $email, int $page = 1 ): array {
	global $wpdb;
	$email = strtolower( trim( $email ) );
	$data  = array();
	$done  = true;
	if ( '' === $email ) {
		return compact( 'data', 'done' );
	}
	$offset = ( max( 1, $page ) - 1 ) * CERTIFICATE_GENERATOR_PRIVACY_PAGE;
	foreach ( certificate_generator_privacy_tables() as $key => [ $table, $col, $label ] ) {
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE LOWER($col) = %s ORDER BY id LIMIT %d OFFSET %d", $email, CERTIFICATE_GENERATOR_PRIVACY_PAGE, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column come from the fixed list above
		if ( count( $rows ) === CERTIFICATE_GENERATOR_PRIVACY_PAGE ) {
			$done = false;
		}
		foreach ( $rows as $row ) {
			unset( $row['layout_snapshot'] ); // The template layout, not the person's data.
			$fields = array();
			foreach ( $row as $name => $value ) {
				if ( null !== $value && '' !== (string) $value ) {
					$fields[] = array(
						'name'  => $name,
						'value' => (string) $value,
					);
				}
			}
			$data[] = array(
				'group_id'    => 'certificate-generator-' . ( 'legacy_certs' === $key ? 'certificates' : str_replace( 'cg_', '', $key ) ),
				'group_label' => $label,
				'item_id'     => $key . '-' . $row['id'],
				'data'        => $fields,
			);
		}
	}
	return compact( 'data', 'done' );
}

/**
 * Eraser: one pass does everything (deletes and updates are set-based, no paging needed).
 *
 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
 */
function certificate_generator_privacy_erase( string $email, int $page = 1 ): array {
	global $wpdb;
	$email  = strtolower( trim( $email ) );
	$result = array(
		'items_removed'  => false,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => true,
	);
	$tables = certificate_generator_privacy_tables();
	if ( '' === $email || ! $tables ) {
		return $result;
	}
	$erased = '[erased]';
	$files  = array();

	// Cached PDFs of the person's rows, then the rows.
	foreach ( array( 'students', 'teachers' ) as $entity ) {
		if ( ! isset( $tables[ $entity ] ) ) {
			continue;
		}
		$table = $tables[ $entity ][0];
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, wp_post_id FROM $table WHERE LOWER(email) = %s", $email ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table
			if ( function_exists( 'certificate_generator_certificate_file_stem' ) ) {
				$files[] = certificate_generator_certificate_file_stem( $entity . '_row_' . (int) $row['id'] );
				if ( (int) $row['wp_post_id'] > 0 ) {
					$files[] = certificate_generator_certificate_file_stem( (string) (int) $row['wp_post_id'] );
				}
			}
		}
	}
	foreach ( array( 'students', 'teachers', 'email_logs', 'cg_email_logs', 'email_queue' ) as $key ) {
		if ( isset( $tables[ $key ] ) ) {
			[ $table, $col ] = $tables[ $key ];
			if ( $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE LOWER($col) = %s", $email ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and column
				$result['items_removed'] = true;
			}
		}
	}

	// Certificates: keep serial, code, dates and status so they still verify; drop everything personal.
	$serials = array();
	foreach ( array( 'certificates', 'legacy_certs' ) as $key ) {
		if ( ! isset( $tables[ $key ] ) ) {
			continue;
		}
		[ $table, $col ] = $tables[ $key ];
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, serial_number, pdf_path FROM $table WHERE LOWER($col) = %s", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table and column
		if ( ! $rows ) {
			continue;
		}
		foreach ( $rows as $row ) {
			$serials[] = (string) $row['serial_number'];
			if ( '' !== (string) $row['pdf_path'] ) {
				$files[] = pathinfo( (string) $row['pdf_path'], PATHINFO_FILENAME );
			}
		}
		$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
		if ( 'certificates' === $key ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET recipient_name = %s, recipient_email = NULL, certificate_data = NULL, extra_fields = NULL, pdf_path = NULL, pdf_url = NULL WHERE id IN ($ids)", $erased ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table, ids are integers
		} else {
			// Legacy table: these columns are NOT NULL.
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET student_name = %s, email = '', certificate_data = '', pdf_path = '' WHERE id IN ($ids)", $erased ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table, ids are integers
		}
		$result['items_retained'] = true;
	}

	$serials = array_values( array_filter( array_unique( $serials ) ) );
	$events  = $wpdb->prefix . 'cg_certificate_events';
	if ( $serials && $events === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $events ) ) ) {
		$in = implode( ',', array_fill( 0, count( $serials ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $events SET meta_json = NULL WHERE certificate_serial IN ($in)", $serials ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- fixed table, one placeholder per serial
	}

	// Only files inside the certificates folder, by name stem (PDF and any PNG twin).
	if ( $files && function_exists( 'certificate_generator_certificates_dir' ) ) {
		$dir = certificate_generator_certificates_dir();
		foreach ( array_unique( $files ) as $stem ) {
			foreach ( (array) glob( $dir . '/' . sanitize_file_name( $stem ) . '.*' ) as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
					$result['items_removed'] = $result['items_removed'] || ! file_exists( $file );
				}
			}
		}
	}

	if ( $result['items_retained'] ) {
		$result['messages'][] = __( 'Certificates were anonymised, not deleted: their serial numbers and dates are kept so printed certificates can still be verified. The name now shows as [erased].', 'certificate-generator' );
	}
	return $result;
}
