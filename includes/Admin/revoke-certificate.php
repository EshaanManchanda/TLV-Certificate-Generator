<?php
/**
 * Admin tool: revoke a certificate by serial number.
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_certificate_generator_revoke_certificate', 'certificate_generator_ajax_revoke_certificate' );
add_action( 'wp_ajax_certificate_generator_unrevoke_certificate', 'certificate_generator_ajax_unrevoke_certificate' );
add_action( 'wp_ajax_certificate_generator_reissue_certificate', 'certificate_generator_ajax_reissue_certificate' );

function certificate_generator_ajax_revoke_certificate() {
	check_ajax_referer( 'certificate_generator_revoke_certificate', 'nonce' );

	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
	}

	$serial = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

	if ( empty( $serial ) ) {
		wp_send_json_error( array( 'message' => 'Serial number is required' ) );
	}

	if ( ! certificate_generator_set_certificate_revoked( $serial, $reason ) ) {
		wp_send_json_error( array( 'message' => 'No certificate found with that serial number' ) );
	}

	wp_send_json_success( array( 'message' => "Certificate {$serial} has been revoked." ) );
}

function certificate_generator_ajax_unrevoke_certificate() {
	check_ajax_referer( 'certificate_generator_revoke_certificate', 'nonce' );

	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
	}

	$serial = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
	if ( empty( $serial ) ) {
		wp_send_json_error( array( 'message' => 'Serial number is required' ) );
	}

	if ( ! certificate_generator_set_certificate_revoked( $serial, null ) ) {
		wp_send_json_error( array( 'message' => 'No certificate found with that serial number' ) );
	}

	wp_send_json_success( array( 'message' => "Certificate {$serial} has been reinstated." ) );
}

function certificate_generator_ajax_reissue_certificate() {
	check_ajax_referer( 'certificate_generator_revoke_certificate', 'nonce' );

	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
	}

	$serial = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
	if ( '' === $serial || '' === $reason ) {
		wp_send_json_error( array( 'message' => 'Serial number and reason are required' ) );
	}

	$revision = certificate_generator_reissue_certificate( $serial, $reason );
	if ( is_wp_error( $revision ) ) {
		wp_send_json_error( array( 'message' => $revision->get_error_message() ) );
	}

	wp_send_json_success( array( 'message' => "Certificate {$serial} reissued as revision {$revision}. The corrected PDF is generated on its next download or email." ) );
}

/**
 * Reissue a certificate with its template as it is now: same serial and verification
 * code, a new layout snapshot, revision + 1, and a "reissued" event with the reason.
 * The PDF cache key covers the layout, so the next download renders the new version.
 *
 * @param string $serial Serial number.
 * @param string $reason Why it was reissued (shown in the history).
 * @return int|WP_Error New revision number.
 */
function certificate_generator_reissue_certificate( string $serial, string $reason ) {
	global $wpdb;
	$table = $wpdb->prefix . 'cg_certificates';
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT template_id, certificate_type, revision, certificate_data FROM $table WHERE serial_number = %s ORDER BY id DESC LIMIT 1", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix
	if ( ! $row ) {
		return new WP_Error( 'not_found', 'No issued certificate found with that serial number.' );
	}

	// Its own template as edited since; otherwise whichever template now matches its type and date.
	$template_id = (int) $row->template_id;
	$snapshot    = certificate_generator_template_snapshot_json( $template_id );
	if ( ! $snapshot ) {
		$data        = json_decode( (string) $row->certificate_data, true );
		$issue       = is_array( $data ) ? (string) ( $data['issue_date'] ?? '' ) : '';
		$dt          = $issue ? ( DateTime::createFromFormat( '!d-m-Y', $issue ) ?: DateTime::createFromFormat( '!Y-m-d', $issue ) ) : false;
		$template    = certificate_generator_select_certificate_template( (string) $row->certificate_type, $dt ? $dt->format( 'Y-m-d' ) : '' );
		$template_id = $template && isset( $template->id ) && ! isset( $template->ID ) ? (int) $template->id : 0;
		$snapshot    = certificate_generator_template_snapshot_json( $template_id );
	}
	if ( ! $snapshot ) {
		return new WP_Error( 'no_template', 'No template found for this certificate type.' );
	}

	$revision = (int) $row->revision + 1;
	$wpdb->update(
		$table,
		array(
			'layout_snapshot' => $snapshot,
			'revision'        => $revision,
			'template_id'     => $template_id,
		),
		array( 'serial_number' => $serial ),
		array( '%s', '%d', '%d' ),
		array( '%s' )
	);
	certificate_generator_record_event( $serial, 'reissued', array( 'reason' => $reason, 'revision' => $revision ) );
	return $revision;
}

/**
 * Revoke ($reason is a string) or reinstate ($reason is null) a certificate.
 *
 * Keeps the legacy table (which verification reads) and wp_cg_certificates.status in sync.
 *
 * @param string      $serial Serial number.
 * @param string|null $reason Revocation reason, or null to reinstate.
 * @return bool False when neither table has that serial.
 */
function certificate_generator_set_certificate_revoked( $serial, $reason ) {
	global $wpdb;
	$legacy = $wpdb->prefix . 'certificate_generator';
	$modern = $wpdb->prefix . 'cg_certificates';
	$revoke = null !== $reason;

	$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $legacy WHERE serial_number = %s UNION SELECT 1 FROM $modern WHERE serial_number = %s LIMIT 1", $serial, $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix
	if ( ! $exists ) {
		return false;
	}

	$wpdb->update(
		$legacy,
		array(
			'revoked_at'     => $revoke ? current_time( 'mysql' ) : null,
			'revoked_reason' => $revoke ? substr( $reason, 0, 255 ) : null,
		),
		array( 'serial_number' => $serial ),
		array( '%s', '%s' ),
		array( '%s' )
	);

	if ( $revoke ) {
		$wpdb->update( $modern, array( 'status' => 'revoked' ), array( 'serial_number' => $serial ), array( '%s' ), array( '%s' ) );
		do_action( 'certificate_generator_certificate_revoked', $serial, $reason );
	} else {
		// Back to 'sent' or 'generated' depending on whether it was emailed.
		$wpdb->query( $wpdb->prepare( "UPDATE $modern SET status = IF(email_sent = 1, 'sent', 'generated') WHERE serial_number = %s AND status = 'revoked'", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix
		do_action( 'certificate_generator_certificate_reinstated', $serial );
	}
	return true;
}

function certificate_generator_render_revoke_certificate_page() {
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'certificate-generator' ) );
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'certificate_generator';
	$revoked = $wpdb->get_results( "SELECT serial_number, student_name, revoked_at, revoked_reason FROM $table WHERE revoked_at IS NOT NULL ORDER BY revoked_at DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$nonce   = wp_create_nonce( 'certificate_generator_revoke_certificate' );
	?>
	<div class="wrap">
		<?php
		certificate_generator_ui_page_header(
			__( 'Revoke Certificate', 'certificate-generator' ),
			__( 'A revoked certificate shows as "Revoked" on the public verification page instead of valid/expired. You can reinstate it at any time.', 'certificate-generator' )
		);
		certificate_generator_ui_card_open( __( 'Revoke by Serial Number', 'certificate-generator' ), array( 'icon' => 'dismiss', 'class' => 'cg-narrow' ) );
		?>
			<table class="form-table">
				<tr>
					<th><label for="cg-revoke-serial"><?php esc_html_e( 'Serial Number', 'certificate-generator' ); ?></label></th>
					<td><input type="text" id="cg-revoke-serial" class="regular-text" placeholder="CERT-2026-00001" value="<?php echo esc_attr( isset( $_GET['serial'] ) ? sanitize_text_field( wp_unslash( $_GET['serial'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET parameter, no state change ?>"></td>
				</tr>
				<tr>
					<th><label for="cg-revoke-reason"><?php esc_html_e( 'Reason (optional)', 'certificate-generator' ); ?></label></th>
					<td><input type="text" id="cg-revoke-reason" class="regular-text" placeholder="e.g. issued in error">
						<p class="cg-hint"><?php esc_html_e( 'Shown to anyone who verifies this certificate.', 'certificate-generator' ); ?></p></td>
				</tr>
			</table>
			<p class="submit">
				<button type="button" class="button button-primary" id="cg-revoke-btn"><?php esc_html_e( 'Revoke Certificate', 'certificate-generator' ); ?></button>
			</p>
			<div id="cg-revoke-result" aria-live="polite"></div>
		<?php
		certificate_generator_ui_card_close();
		certificate_generator_ui_card_open( __( 'Currently Revoked (latest 50)', 'certificate-generator' ), array( 'icon' => 'list-view' ) );
		?>
			<div class="cg-table-wrap">
			<table class="widefat striped cg-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Serial Number', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Name', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Revoked At', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'certificate-generator' ); ?></th>
						<th><?php esc_html_e( 'Action', 'certificate-generator' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $revoked ) ) : ?>
						<?php echo certificate_generator_ui_empty_row( 5, __( 'No revoked certificates. Every issued certificate currently verifies normally.', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
					<?php else : ?>
						<?php foreach ( $revoked as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( add_query_arg( 'serial', $row->serial_number ) ); ?>"><code><?php echo esc_html( $row->serial_number ); ?></code></a></td>
							<td><?php echo esc_html( $row->student_name ); ?></td>
							<td><?php echo esc_html( $row->revoked_at ); ?></td>
							<td><?php echo esc_html( $row->revoked_reason ); ?></td>
							<td><button type="button" class="button cg-unrevoke-btn" data-serial="<?php echo esc_attr( $row->serial_number ); ?>"><?php esc_html_e( 'Reinstate', 'certificate-generator' ); ?></button></td>
						</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
			</div>
		<?php
		certificate_generator_ui_card_close();
		$history_serial = isset( $_GET['serial'] ) ? sanitize_text_field( wp_unslash( $_GET['serial'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET parameter, no state change
		if ( '' !== $history_serial && function_exists( 'certificate_generator_render_certificate_history' ) ) {
			certificate_generator_render_certificate_history( $history_serial );
			/* translators: %s: certificate serial number */
			certificate_generator_ui_card_open( sprintf( __( 'Reissue %s', 'certificate-generator' ), $history_serial ), array( 'icon' => 'update', 'class' => 'cg-narrow cg-reissue' ) );
			?>
				<p class="cg-hint"><?php esc_html_e( 'Certificates keep the layout they were issued with. After correcting the template, reissue to apply it: the certificate keeps its serial number and verification code, and its next download or email uses the template as it is now.', 'certificate-generator' ); ?></p>
				<p><label for="cg-reissue-reason"><?php esc_html_e( 'Reason', 'certificate-generator' ); ?></label><br>
					<input type="text" id="cg-reissue-reason" class="regular-text" placeholder="e.g. corrected spelling of the course name"></p>
				<p><button type="button" class="button" id="cg-reissue-btn" data-serial="<?php echo esc_attr( $history_serial ); ?>"><?php esc_html_e( 'Reissue Certificate', 'certificate-generator' ); ?></button></p>
			<?php
			certificate_generator_ui_card_close();
		}
		?>
	</div>
	<script>
	jQuery(document).ready(function($) {
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;
		var result = document.getElementById('cg-revoke-result');

		function run(btn, action, data, failText) {
			CGUI.busy(btn, true);
			$.post(ajaxurl, $.extend({ action: action, nonce: nonce }, data))
				.done(function(response) {
					result.textContent = '';
					if (response && response.success) {
						CGUI.notice('success', response.data.message, result);
						setTimeout(function() { location.reload(); }, 1200);
					} else {
						CGUI.notice('error', response && response.data && response.data.message ? response.data.message : failText, result);
						CGUI.busy(btn, false);
					}
				})
				.fail(function() {
					result.textContent = '';
					CGUI.notice('error', failText, result);
					CGUI.busy(btn, false);
				});
		}

		$('#cg-revoke-btn').on('click', function() {
			var btn = this;
			var serial = $('#cg-revoke-serial').val().trim();
			var reason = $('#cg-revoke-reason').val().trim();
			if (!serial) { alert('Enter a serial number.'); return; }
			CGUI.confirm({
				title: 'Revoke ' + serial + '?',
				message: 'The public verification page will show it as revoked' + (reason ? ', with the reason "' + reason + '"' : '') + '. You can reinstate it later.',
				confirmLabel: 'Revoke',
				danger: true
			}).then(function(ok) {
				if (ok) { run(btn, 'certificate_generator_revoke_certificate', { serial_number: serial, reason: reason }, 'Failed to revoke certificate.'); }
			});
		});

		$('#cg-reissue-btn').on('click', function() {
			var btn = this;
			var serial = $(this).data('serial');
			var reason = $('#cg-reissue-reason').val().trim();
			if (!reason) { alert('Enter the reason for reissuing.'); return; }
			run(btn, 'certificate_generator_reissue_certificate', { serial_number: serial, reason: reason }, 'Failed to reissue certificate.');
		});

		$('.cg-unrevoke-btn').on('click', function() {
			var btn = this;
			var serial = $(this).data('serial');
			CGUI.confirm({
				title: 'Reinstate ' + serial + '?',
				message: 'It will verify as valid again on the public verification page.',
				confirmLabel: 'Reinstate'
			}).then(function(ok) {
				if (ok) { run(btn, 'certificate_generator_unrevoke_certificate', { serial_number: serial }, 'Failed to reinstate certificate.'); }
			});
		});
	});
	</script>
	<?php
}
