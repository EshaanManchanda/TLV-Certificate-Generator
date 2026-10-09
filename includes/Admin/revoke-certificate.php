<?php
/**
 * Admin tool: revoke a certificate by serial number.
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_ajax_cg_revoke_certificate', 'cg_ajax_revoke_certificate' );
add_action( 'wp_ajax_cg_unrevoke_certificate', 'cg_ajax_unrevoke_certificate' );

function cg_ajax_revoke_certificate() {
	check_ajax_referer( 'cg_revoke_certificate', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
	}

	$serial = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
	$reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

	if ( empty( $serial ) ) {
		wp_send_json_error( array( 'message' => 'Serial number is required' ) );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'certificate_generator';

	$cert = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE serial_number = %s", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	if ( ! $cert ) {
		wp_send_json_error( array( 'message' => 'No certificate found with that serial number' ) );
	}

	$wpdb->update(
		$table,
		array(
			'revoked_at'     => current_time( 'mysql' ),
			'revoked_reason' => substr( $reason, 0, 255 ),
		),
		array( 'id' => $cert->id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	wp_send_json_success( array( 'message' => "Certificate {$serial} has been revoked." ) );
}

function cg_ajax_unrevoke_certificate() {
	check_ajax_referer( 'cg_revoke_certificate', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
	}

	$serial = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';
	if ( empty( $serial ) ) {
		wp_send_json_error( array( 'message' => 'Serial number is required' ) );
	}

	global $wpdb;
	$table = $wpdb->prefix . 'certificate_generator';

	$cert = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $table WHERE serial_number = %s", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	if ( ! $cert ) {
		wp_send_json_error( array( 'message' => 'No certificate found with that serial number' ) );
	}

	$wpdb->update(
		$table,
		array(
			'revoked_at'     => null,
			'revoked_reason' => null,
		),
		array( 'id' => $cert->id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	wp_send_json_success( array( 'message' => "Certificate {$serial} has been reinstated." ) );
}

function cg_render_revoke_certificate_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'certificate-generator' ) );
	}

	global $wpdb;
	$table   = $wpdb->prefix . 'certificate_generator';
	$revoked = $wpdb->get_results( "SELECT serial_number, student_name, revoked_at, revoked_reason FROM $table WHERE revoked_at IS NOT NULL ORDER BY revoked_at DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$nonce   = wp_create_nonce( 'cg_revoke_certificate' );
	?>
	<div class="wrap">
		<?php
		cg_ui_page_header(
			__( 'Revoke Certificate', 'certificate-generator' ),
			__( 'A revoked certificate shows as "Revoked" on the public verification page instead of valid/expired. You can reinstate it at any time.', 'certificate-generator' )
		);
		cg_ui_card_open( __( 'Revoke by Serial Number', 'certificate-generator' ), array( 'icon' => 'dismiss', 'class' => 'cg-narrow' ) );
		?>
			<table class="form-table">
				<tr>
					<th><label for="cg-revoke-serial"><?php esc_html_e( 'Serial Number', 'certificate-generator' ); ?></label></th>
					<td><input type="text" id="cg-revoke-serial" class="regular-text" placeholder="CERT-2026-00001" value="<?php echo esc_attr( isset( $_GET['serial'] ) ? sanitize_text_field( wp_unslash( $_GET['serial'] ) ) : '' ); ?>"></td>
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
		cg_ui_card_close();
		cg_ui_card_open( __( 'Currently Revoked (latest 50)', 'certificate-generator' ), array( 'icon' => 'list-view' ) );
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
						<?php echo cg_ui_empty_row( 5, __( 'No revoked certificates. Every issued certificate currently verifies normally.', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
					<?php else : ?>
						<?php foreach ( $revoked as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row->serial_number ); ?></code></td>
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
		<?php cg_ui_card_close(); ?>
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
				if (ok) { run(btn, 'cg_revoke_certificate', { serial_number: serial, reason: reason }, 'Failed to revoke certificate.'); }
			});
		});

		$('.cg-unrevoke-btn').on('click', function() {
			var btn = this;
			var serial = $(this).data('serial');
			CGUI.confirm({
				title: 'Reinstate ' + serial + '?',
				message: 'It will verify as valid again on the public verification page.',
				confirmLabel: 'Reinstate'
			}).then(function(ok) {
				if (ok) { run(btn, 'cg_unrevoke_certificate', { serial_number: serial }, 'Failed to reinstate certificate.'); }
			});
		});
	});
	</script>
	<?php
}
