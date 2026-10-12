<?php
/**
 * Certificate Register: every issued certificate (serial, code, recipient, school, type, event,
 * dates, status), filtered and downloaded as CSV or PDF for audits.
 *
 * Rows come from the legacy wp_certificate_generator table, which every issue writes to
 * (certificate_generator_insert_certificate_record()); the event comes from the template of the
 * latest wp_cg_certificates row for the serial. School Coordinators only ever get their own
 * school (certificate_generator_school_scope_sql()), whatever the school filter says.
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** A string field of the certificate's stored data, NULL when missing, not a string (rows without a school store false) or not JSON. */
function certificate_generator_register_json_sql( string $field ): string {
	$path = "JSON_EXTRACT(l.certificate_data, '$.$field')";
	return "IF(JSON_VALID(l.certificate_data) AND JSON_TYPE($path) = 'STRING', JSON_UNQUOTE($path), NULL)";
}

/**
 * Register filters from the request (GET for the page and the download links).
 *
 * @return array{type:string,school:string,event:int,from:string,to:string,status:string}
 */
function certificate_generator_register_filters(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters; downloads check their nonce first
	$get    = static fn( string $key ): string => isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	$date   = static fn( string $value ): string => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	$status = $get( 'status' );
	$filters = array(
		'type'   => $get( 'type' ),
		'school' => $get( 'school' ),
		'event'  => absint( $get( 'event' ) ),
		'from'   => $date( $get( 'from' ) ),
		'to'     => $date( $get( 'to' ) ),
		'status' => in_array( $status, array( 'valid', 'expired', 'revoked' ), true ) ? $status : '',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	return $filters;
}

/**
 * Issued certificates matching the filters, newest first, limited to the user's school.
 *
 * @param array $filters As returned by certificate_generator_register_filters().
 * @param int   $limit   0 for all rows.
 */
function certificate_generator_register_rows( array $filters, int $limit = 0 ): array {
	global $wpdb;
	$legacy = $wpdb->prefix . 'certificate_generator';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) !== $legacy ) {
		return array();
	}
	$school = certificate_generator_register_json_sql( 'school_name' );
	$email  = certificate_generator_register_json_sql( 'email' );
	$now    = gmdate( 'Y-m-d H:i:s' ); // the verify page's rule: strtotime( expires_at ) < time()
	$where  = array( '1=1' );
	$params = array();

	if ( '' !== ( $filters['type'] ?? '' ) ) {
		$where[]  = 'l.certificate_type = %s';
		$params[] = $filters['type'];
	}
	if ( '' !== ( $filters['school'] ?? '' ) ) {
		$where[]  = "$school = %s";
		$params[] = $filters['school'];
	}
	if ( ! empty( $filters['event'] ) ) {
		$where[]  = 'ev.id = %d';
		$params[] = (int) $filters['event'];
	}
	if ( '' !== ( $filters['from'] ?? '' ) ) {
		$where[]  = 'l.issued_at >= %s';
		$params[] = $filters['from'] . ' 00:00:00';
	}
	if ( '' !== ( $filters['to'] ?? '' ) ) {
		$where[]  = 'l.issued_at <= %s';
		$params[] = $filters['to'] . ' 23:59:59';
	}
	switch ( $filters['status'] ?? '' ) {
		case 'revoked':
			$where[] = 'l.revoked_at IS NOT NULL';
			break;
		case 'expired':
			$where[]  = 'l.revoked_at IS NULL AND l.expires_at IS NOT NULL AND l.expires_at < %s';
			$params[] = $now;
			break;
		case 'valid':
			$where[]  = 'l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at >= %s)';
			$params[] = $now;
			break;
	}
	[ $scope, $scope_params ] = certificate_generator_school_scope_sql( $school );
	if ( '' !== $scope ) {
		$where[] = $scope;
		$params  = array_merge( $params, $scope_params );
	}

	$certs     = $wpdb->prefix . 'cg_certificates';
	$templates = $wpdb->prefix . 'cg_certificate_templates';
	$events    = $wpdb->prefix . 'cg_events';
	$sql       = "SELECT l.serial_number, l.verification_code, l.student_name, COALESCE(NULLIF(l.email, ''), c.recipient_email, $email) AS email,
			$school AS school_name, l.certificate_type, ev.event_name, l.issued_at, l.expires_at, l.revoked_at, l.revoked_reason
		FROM $legacy l
		LEFT JOIN $certs c ON c.id = ( SELECT MAX(c2.id) FROM $certs c2 WHERE c2.serial_number = l.serial_number )
		LEFT JOIN $templates t ON t.id = c.template_id
		LEFT JOIN $events ev ON ev.id = t.event_id
		WHERE " . implode( ' AND ', $where ) . '
		ORDER BY l.issued_at DESC, l.id DESC' . ( $limit > 0 ? ' LIMIT ' . (int) $limit : '' );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table names from $wpdb->prefix; every value is a placeholder in $params
	$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, ...$params ) : $sql, ARRAY_A );
	return is_array( $rows ) ? $rows : array();
}

/** 'Revoked', 'Expired' or 'Valid', as the verify page decides. */
function certificate_generator_register_status( array $row ): string {
	if ( ! empty( $row['revoked_at'] ) ) {
		return __( 'Revoked', 'certificate-generator' );
	}
	if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < time() ) {
		return __( 'Expired', 'certificate-generator' );
	}
	return __( 'Valid', 'certificate-generator' );
}

/** Column headings, in the order of certificate_generator_register_line(). */
function certificate_generator_register_columns(): array {
	return array(
		__( 'Serial number', 'certificate-generator' ),
		__( 'Verification code', 'certificate-generator' ),
		__( 'Recipient', 'certificate-generator' ),
		__( 'Email', 'certificate-generator' ),
		__( 'School', 'certificate-generator' ),
		__( 'Certificate type', 'certificate-generator' ),
		__( 'Event', 'certificate-generator' ),
		__( 'Issued', 'certificate-generator' ),
		__( 'Expires', 'certificate-generator' ),
		__( 'Status', 'certificate-generator' ),
		__( 'Revoke reason', 'certificate-generator' ),
	);
}

/** One row as display values. The name is shown even when the recipient hid it from public verification. */
function certificate_generator_register_line( array $row ): array {
	return array(
		(string) $row['serial_number'],
		(string) $row['verification_code'],
		(string) $row['student_name'],
		(string) $row['email'],
		(string) $row['school_name'],
		(string) $row['certificate_type'],
		(string) $row['event_name'],
		certificate_generator_format_date( $row['issued_at'] ),
		$row['expires_at'] ? certificate_generator_format_date( $row['expires_at'] ) : __( 'Never', 'certificate-generator' ),
		certificate_generator_register_status( $row ),
		(string) $row['revoked_reason'],
	);
}

/** CSV bytes: UTF-8 BOM (so Excel keeps non-Latin names) and formula-escaped cells. */
function certificate_generator_register_csv( array $rows ): string {
	// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory php://temp stream
	$fh = fopen( 'php://temp', 'w+' );
	fwrite( $fh, "\xEF\xBB\xBF" );
	certificate_generator_fputcsv( $fh, certificate_generator_register_columns() );
	foreach ( $rows as $row ) {
		certificate_generator_fputcsv( $fh, certificate_generator_register_line( $row ) );
	}
	rewind( $fh );
	$csv = (string) stream_get_contents( $fh );
	fclose( $fh );
	// phpcs:enable
	return $csv;
}

/** Human-readable summary of the filters, for the PDF heading. */
function certificate_generator_register_filter_summary( array $filters ): string {
	$parts = array();
	if ( '' !== $filters['type'] ) {
		$parts[] = __( 'Type', 'certificate-generator' ) . ': ' . $filters['type'];
	}
	$school = certificate_generator_user_school() ?? $filters['school'];
	if ( '' !== $school ) {
		$parts[] = __( 'School', 'certificate-generator' ) . ': ' . $school;
	}
	if ( $filters['event'] ) {
		global $wpdb;
		$parts[] = __( 'Event', 'certificate-generator' ) . ': ' . (string) $wpdb->get_var( $wpdb->prepare( "SELECT event_name FROM {$wpdb->prefix}cg_events WHERE id = %d", $filters['event'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
	}
	if ( '' !== $filters['from'] || '' !== $filters['to'] ) {
		$parts[] = __( 'Issued', 'certificate-generator' ) . ': ' . ( $filters['from'] ? certificate_generator_format_date( $filters['from'] ) : '…' ) . ' – ' . ( $filters['to'] ? certificate_generator_format_date( $filters['to'] ) : '…' );
	}
	if ( '' !== $filters['status'] ) {
		$parts[] = __( 'Status', 'certificate-generator' ) . ': ' . ucfirst( $filters['status'] );
	}
	return $parts ? implode( ' · ', $parts ) : __( 'All certificates', 'certificate-generator' );
}

/**
 * PDF bytes: an A4 landscape table, header row repeated on every page, through mPDF so Hindi,
 * Arabic and other non-Latin names print correctly.
 * ponytail: the whole table is one HTML string; write it in chunks if registers reach tens of thousands of rows.
 */
function certificate_generator_register_pdf( array $rows, array $filters ): string {
	$canvas = '\CertificateGenerator\Services\MpdfCanvas';
	$mpdf   = new \Mpdf\Mpdf(
		array(
			'format'        => 'A4-L',
			'margin_left'   => 10,
			'margin_right'  => 10,
			'margin_top'    => 12,
			'margin_bottom' => 14,
		) + $canvas::config()
	);
	$mpdf->SetTitle( __( 'Certificate Register', 'certificate-generator' ) );
	$mpdf->SetFooter( get_bloginfo( 'name' ) . '|' . gmdate( 'd-m-Y H:i' ) . ' UTC|{PAGENO} / {nbpg}' );

	$html  = '<style>body{font-size:8pt} h1{font-size:14pt;margin:0} p{margin:2pt 0 8pt;color:#555} table{border-collapse:collapse;width:100%} th,td{border:0.3pt solid #999;padding:2pt 3pt;vertical-align:top} th{background:#eee;text-align:left}</style>';
	$html .= '<h1>' . esc_html__( 'Certificate Register', 'certificate-generator' ) . ' — ' . $canvas::html( (string) get_bloginfo( 'name' ) ) . '</h1>';
	/* translators: 1: filter summary, 2: number of certificates */
	$html .= '<p>' . $canvas::html( sprintf( __( '%1$s · %2$d certificates', 'certificate-generator' ), certificate_generator_register_filter_summary( $filters ), count( $rows ) ) ) . '</p>';
	$html .= '<table repeat_header="1"><thead><tr>';
	foreach ( certificate_generator_register_columns() as $heading ) {
		$html .= '<th>' . esc_html( $heading ) . '</th>';
	}
	$html .= '</tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$html .= '<tr>';
		foreach ( certificate_generator_register_line( $row ) as $cell ) {
			$html .= '<td>' . $canvas::html( $cell ) . '</td>';
		}
		$html .= '</tr>';
	}
	if ( ! $rows ) {
		$html .= '<tr><td colspan="11">' . esc_html__( 'No certificates match these filters.', 'certificate-generator' ) . '</td></tr>';
	}
	$html .= '</tbody></table>';

	$mpdf->WriteHTML( $html );
	return (string) $mpdf->Output( '', 'S' );
}

/** Download: admin-post.php?action=certificate_generator_register_export&format=csv|pdf + filters. */
function certificate_generator_handle_register_export(): void {
	check_admin_referer( 'certificate_generator_register_export' );
	if ( ! current_user_can( 'certificate_generator_view' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ), 403 );
	}
	$filters = certificate_generator_register_filters();
	$rows    = certificate_generator_register_rows( $filters );
	$pdf     = 'pdf' === sanitize_key( wp_unslash( $_GET['format'] ?? '' ) );
	$body    = $pdf ? certificate_generator_register_pdf( $rows, $filters ) : certificate_generator_register_csv( $rows );

	nocache_headers();
	header( 'Content-Type: ' . ( $pdf ? 'application/pdf' : 'text/csv; charset=utf-8' ) );
	header( 'Content-Disposition: attachment; filename="certificate-register-' . gmdate( 'Y-m-d' ) . ( $pdf ? '.pdf"' : '.csv"' ) );
	header( 'Content-Length: ' . strlen( $body ) );
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download (CSV cells formula-escaped, PDF HTML escaped)
	exit;
}
add_action( 'admin_post_certificate_generator_register_export', 'certificate_generator_handle_register_export' );

/** Certificate Generator → Certificate Register. */
function certificate_generator_render_register_page(): void {
	if ( ! current_user_can( 'certificate_generator_view' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ) );
	}
	global $wpdb;
	$filters  = certificate_generator_register_filters();
	$preview  = certificate_generator_register_rows( $filters, 101 );
	$own      = certificate_generator_user_school();
	$schools  = null === $own ? certificate_generator_assignable_schools() : array();
	$legacy   = $wpdb->prefix . 'certificate_generator';
	$types    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $legacy ) ) === $legacy
		? (array) $wpdb->get_col( "SELECT DISTINCT certificate_type FROM $legacy WHERE certificate_type IS NOT NULL AND certificate_type != '' ORDER BY certificate_type" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		: array();
	$events   = (array) $wpdb->get_results( "SELECT id, event_name FROM {$wpdb->prefix}cg_events ORDER BY event_name", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
	$download = static fn( string $format ): string => wp_nonce_url(
		add_query_arg( array_filter( $filters ) + array( 'format' => $format ), admin_url( 'admin-post.php?action=certificate_generator_register_export' ) ),
		'certificate_generator_register_export'
	);
	?>
	<div class="wrap">
		<?php certificate_generator_ui_page_header( __( 'Certificate Register', 'certificate-generator' ), __( 'Every issued certificate with its serial, verification code and status. Filter it, then download it as a spreadsheet or a printable PDF.', 'certificate-generator' ) ); ?>

		<?php certificate_generator_ui_card_open( __( 'Filters', 'certificate-generator' ), array( 'icon' => 'filter' ) ); ?>
		<form method="get" class="cg-register-filters">
			<input type="hidden" name="page" value="cg-register">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="cg-reg-type"><?php esc_html_e( 'Certificate type', 'certificate-generator' ); ?></label></th>
					<td><select name="type" id="cg-reg-type">
						<option value=""><?php esc_html_e( 'All', 'certificate-generator' ); ?></option>
						<?php foreach ( $types as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['type'], $type ); ?>><?php echo esc_html( $type ); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
				<tr>
					<th><label for="cg-reg-school"><?php esc_html_e( 'School', 'certificate-generator' ); ?></label></th>
					<td>
						<?php if ( null === $own ) : ?>
							<select name="school" id="cg-reg-school">
								<option value=""><?php esc_html_e( 'All', 'certificate-generator' ); ?></option>
								<?php foreach ( $schools as $school ) : ?>
									<option value="<?php echo esc_attr( $school ); ?>" <?php selected( $filters['school'], $school ); ?>><?php echo esc_html( $school ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<?php echo esc_html( '' !== $own ? $own : __( 'No school assigned to your account', 'certificate-generator' ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $events ) : ?>
				<tr>
					<th><label for="cg-reg-event"><?php esc_html_e( 'Event', 'certificate-generator' ); ?></label></th>
					<td><select name="event" id="cg-reg-event">
						<option value=""><?php esc_html_e( 'All', 'certificate-generator' ); ?></option>
						<?php foreach ( $events as $event ) : ?>
							<option value="<?php echo esc_attr( $event['id'] ); ?>" <?php selected( $filters['event'], (int) $event['id'] ); ?>><?php echo esc_html( $event['event_name'] ); ?></option>
						<?php endforeach; ?>
					</select></td>
				</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Issued', 'certificate-generator' ); ?></th>
					<td>
						<label><?php esc_html_e( 'From', 'certificate-generator' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
						<label><?php esc_html_e( 'To', 'certificate-generator' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
					</td>
				</tr>
				<tr>
					<th><label for="cg-reg-status"><?php esc_html_e( 'Status', 'certificate-generator' ); ?></label></th>
					<td><select name="status" id="cg-reg-status">
						<option value=""><?php esc_html_e( 'All', 'certificate-generator' ); ?></option>
						<option value="valid" <?php selected( $filters['status'], 'valid' ); ?>><?php esc_html_e( 'Valid', 'certificate-generator' ); ?></option>
						<option value="expired" <?php selected( $filters['status'], 'expired' ); ?>><?php esc_html_e( 'Expired', 'certificate-generator' ); ?></option>
						<option value="revoked" <?php selected( $filters['status'], 'revoked' ); ?>><?php esc_html_e( 'Revoked', 'certificate-generator' ); ?></option>
					</select></td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button"><?php esc_html_e( 'Apply filters', 'certificate-generator' ); ?></button>
				<a class="button button-primary" id="cg-register-csv" href="<?php echo esc_url( $download( 'csv' ) ); ?>"><?php esc_html_e( 'Download CSV', 'certificate-generator' ); ?></a>
				<a class="button button-primary" id="cg-register-pdf" href="<?php echo esc_url( $download( 'pdf' ) ); ?>"><?php esc_html_e( 'Download PDF', 'certificate-generator' ); ?></a>
			</p>
		</form>
		<?php certificate_generator_ui_card_close(); ?>

		<p class="cg-register-count">
			<?php
			echo esc_html(
				count( $preview ) > 100
					? __( 'Showing the 100 most recent matching certificates. The downloads include all of them.', 'certificate-generator' )
					/* translators: %d: number of certificates */
					: sprintf( _n( '%d certificate matches.', '%d certificates match.', count( $preview ), 'certificate-generator' ), count( $preview ) )
			);
			?>
		</p>
		<table class="widefat striped cg-register-table">
			<thead><tr>
				<?php foreach ( certificate_generator_register_columns() as $heading ) : ?>
					<th scope="col"><?php echo esc_html( $heading ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php if ( ! $preview ) : ?>
				<?php echo certificate_generator_ui_empty_row( 11, __( 'No certificates match these filters.', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- helper escapes ?>
			<?php endif; ?>
			<?php foreach ( array_slice( $preview, 0, 100 ) as $row ) : ?>
				<tr>
					<?php foreach ( certificate_generator_register_line( $row ) as $cell ) : ?>
						<td><?php echo esc_html( $cell ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
}
