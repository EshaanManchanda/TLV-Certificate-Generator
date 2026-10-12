<?php
/**
 * Certificate audit trail: what happened to each certificate, by whom, and when.
 *
 * Rows live in wp_cg_certificate_events, keyed on serial. No IP or user agent is
 * stored. Emails are not duplicated here: the Email Logs page already records them.
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record one event for a certificate.
 *
 * @param string $serial Certificate serial.
 * @param string $type   issued|name_changed|reissued|revoked|reinstated|verified (max 32 chars).
 * @param array  $meta   Extra details, stored as JSON.
 */
function certificate_generator_record_event( string $serial, string $type, array $meta = array() ): void {
	if ( '' === $serial ) {
		return;
	}
	global $wpdb;
	$actor = get_current_user_id();
	$wpdb->insert(
		$wpdb->prefix . 'cg_certificate_events',
		array(
			'certificate_serial' => substr( $serial, 0, 50 ),
			'event_type'         => substr( $type, 0, 32 ),
			'actor_id'           => $actor ? $actor : null,
			'meta_json'          => $meta ? wp_json_encode( $meta ) : null,
			'created_at'         => current_time( 'mysql' ),
		)
	);
}

/**
 * Events for one certificate, newest first.
 *
 * @param string $serial Certificate serial.
 * @return array<int,array{event_type:string,actor_id:?string,meta_json:?string,created_at:string}>
 */
function certificate_generator_get_certificate_events( string $serial ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'cg_certificate_events';
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT event_type, actor_id, meta_json, created_at FROM $table WHERE certificate_serial = %s ORDER BY id DESC LIMIT 200", $serial ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix
}

/**
 * Delete events older than $days. Issuance, revocation and name changes are the record
 * of the certificate, so only the high-volume "verified" events are pruned.
 *
 * @param int $days Days to keep.
 * @return int Rows deleted.
 */
function certificate_generator_prune_certificate_events( int $days ): int {
	global $wpdb;
	$table = $wpdb->prefix . 'cg_certificate_events';
	return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE event_type = 'verified' AND created_at < %s", wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name comes from $wpdb->prefix
}

add_action(
	'certificate_generator_certificate_revoked',
	function ( $serial, $reason ) {
		certificate_generator_record_event( (string) $serial, 'revoked', '' !== (string) $reason ? array( 'reason' => (string) $reason ) : array() );
	},
	10,
	2
);
add_action(
	'certificate_generator_certificate_reinstated',
	function ( $serial ) {
		certificate_generator_record_event( (string) $serial, 'reinstated' );
	}
);

/**
 * "History" card for one certificate (admin).
 *
 * @param string $serial Certificate serial.
 */
function certificate_generator_render_certificate_history( string $serial ): void {
	$labels = array(
		'issued'       => __( 'Issued', 'certificate-generator' ),
		'name_changed' => __( 'Name corrected', 'certificate-generator' ),
		'reissued'     => __( 'Reissued', 'certificate-generator' ),
		'revoked'      => __( 'Revoked', 'certificate-generator' ),
		'reinstated'   => __( 'Reinstated', 'certificate-generator' ),
		'verified'     => __( 'Verified publicly', 'certificate-generator' ),
	);
	$events = certificate_generator_get_certificate_events( $serial );

	/* translators: %s: certificate serial number */
	certificate_generator_ui_card_open( sprintf( __( 'History for %s', 'certificate-generator' ), $serial ), array( 'icon' => 'backup', 'class' => 'cg-certificate-history' ) );
	?>
	<div class="cg-table-wrap">
	<table class="widefat striped cg-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'When', 'certificate-generator' ); ?></th>
				<th><?php esc_html_e( 'Event', 'certificate-generator' ); ?></th>
				<th><?php esc_html_e( 'By', 'certificate-generator' ); ?></th>
				<th><?php esc_html_e( 'Details', 'certificate-generator' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $events ) ) : ?>
				<?php echo certificate_generator_ui_empty_row( 4, __( 'No recorded events for this serial. Events are recorded from version 7.7.0 on.', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
			<?php else : ?>
				<?php
				foreach ( $events as $event ) :
					$user    = $event['actor_id'] ? get_userdata( (int) $event['actor_id'] ) : false;
					$meta    = $event['meta_json'] ? (array) json_decode( $event['meta_json'], true ) : array();
					$details = array();
					foreach ( $meta as $key => $value ) {
						$details[] = $key . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
					}
					?>
				<tr>
					<td><?php echo esc_html( $event['created_at'] ); ?></td>
					<td><?php echo esc_html( $labels[ $event['event_type'] ] ?? $event['event_type'] ); ?></td>
					<td><?php echo esc_html( $user ? $user->display_name : ( $event['actor_id'] ? '#' . $event['actor_id'] : __( 'Public / system', 'certificate-generator' ) ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $details ) ); ?></td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
	</div>
	<?php
	certificate_generator_ui_card_close();
}
