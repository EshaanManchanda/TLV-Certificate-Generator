<?php
/**
 * [certificate_generator_my_certificates] — a recipient's own certificates, opened with an emailed link.
 *
 * Most recipients exist only as CSV rows, not WordPress users, so ownership of the email address is
 * the sign-in: the visitor types their email, gets a link valid for 30 minutes, and the link opens
 * their list (download, verify, share, show or hide their name on the verify page). The link is a
 * signed token — email, expiry and an HMAC with the site's auth salt — so nothing is stored. Opening
 * it moves the token into an HttpOnly cookie and drops it from the URL.
 *
 * The form always answers the same way, whether or not the address has certificates, so it can't be
 * used to find out who has one. Logged-in users whose account email has records skip the link.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CERTIFICATE_GENERATOR_WALLET_COOKIE = 'certificate_generator_wallet';
const CERTIFICATE_GENERATOR_WALLET_TTL    = 30 * MINUTE_IN_SECONDS;

/** Signed sign-in token for an email address. */
function certificate_generator_wallet_token( string $email, ?int $expires = null ): string {
	$email   = strtolower( trim( $email ) );
	$expires = $expires ?? time() + CERTIFICATE_GENERATOR_WALLET_TTL;
	$sig     = hash_hmac( 'sha256', $email . '|' . $expires, wp_salt( 'auth' ) );
	return rtrim( strtr( base64_encode( $email . '|' . $expires . '|' . $sig ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token, not obfuscation
}

/** The email a token signs in, or '' when it is malformed, tampered with or expired. */
function certificate_generator_wallet_email_from_token( string $token ): string {
	$raw = base64_decode( strtr( $token, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe token, not obfuscation
	if ( false === $raw ) {
		return '';
	}
	$sig_at = strrpos( $raw, '|' );
	$exp_at = false !== $sig_at ? strrpos( substr( $raw, 0, $sig_at ), '|' ) : false;
	if ( false === $sig_at || false === $exp_at ) {
		return '';
	}
	$email   = substr( $raw, 0, $exp_at );
	$expires = substr( $raw, $exp_at + 1, $sig_at - $exp_at - 1 );
	$sig     = substr( $raw, $sig_at + 1 );
	if ( ! ctype_digit( $expires ) || (int) $expires < time() || ! is_email( $email ) ) {
		return '';
	}
	$expected = hash_hmac( 'sha256', $email . '|' . $expires, wp_salt( 'auth' ) );
	return hash_equals( $expected, $sig ) ? $email : '';
}

/** True when the address has a student or teacher record. */
function certificate_generator_wallet_has_records( string $email ): bool {
	if ( '' === $email || ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return false;
	}
	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	foreach ( array( 'students', 'teachers' ) as $entity ) {
		$table = $tables->get_table( $entity );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE email = %s LIMIT 1", $email ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
			return true;
		}
	}
	return false;
}

/** The email the visitor is signed in as: from the wallet cookie, else a logged-in account with records. Lower case; '' if none. */
function certificate_generator_wallet_email(): string {
	$token = isset( $_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] ) ) : '';
	$email = '' !== $token ? certificate_generator_wallet_email_from_token( $token ) : '';
	if ( '' === $email && is_user_logged_in() ) {
		$account = strtolower( (string) wp_get_current_user()->user_email );
		$email   = certificate_generator_wallet_has_records( $account ) ? $account : '';
	}
	return $email;
}

/** Email the sign-in link. False when the address has no records or the mail failed (the visitor isn't told which). */
function certificate_generator_wallet_send_link( string $email, string $return_url ): bool {
	$email = strtolower( trim( $email ) );
	if ( ! certificate_generator_wallet_has_records( $email ) ) {
		return false;
	}
	$link    = add_query_arg( 'cg_wallet', certificate_generator_wallet_token( $email ), $return_url );
	$site    = get_bloginfo( 'name' );
	$subject = (string) apply_filters(
		'certificate_generator_wallet_email_subject',
		/* translators: %s: site name */
		sprintf( __( 'Your certificates from %s', 'certificate-generator' ), $site ),
		$email
	);
	$body = (string) apply_filters(
		'certificate_generator_wallet_email_body',
		'<p>' . esc_html__( 'Open your certificates with this link. It works for 30 minutes.', 'certificate-generator' ) . '</p>'
			. '<p><a href="' . esc_url( $link ) . '">' . esc_html__( 'View my certificates', 'certificate-generator' ) . '</a></p>'
			. '<p style="color:#646970;font-size:13px;">' . esc_html__( 'If you didn\'t ask for this, you can ignore this email.', 'certificate-generator' ) . '</p>',
		$email,
		$link
	);
	if ( class_exists( '\CertificateGenerator\Email\Mailer' ) ) {
		return \CertificateGenerator\Email\Mailer::make()->send_html( $email, $subject, $body );
	}
	return wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/** Set (or clear, with '') the wallet cookie. */
function certificate_generator_wallet_set_cookie( string $token, int $expires ): void {
	if ( headers_sent() ) {
		return;
	}
	setcookie(
		CERTIFICATE_GENERATOR_WALLET_COOKIE,
		$token,
		array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		)
	);
	if ( '' === $token ) {
		unset( $_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] );
	} else {
		$_COOKIE[ CERTIFICATE_GENERATOR_WALLET_COOKIE ] = $token;
	}
}

/**
 * Front-end requests: the link form (POST back to the same page), an opened link (?cg_wallet=) and
 * sign out (?cg_wallet_signout=1). Handled before output so cookies and redirects work.
 */
function certificate_generator_wallet_handle_request(): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the token is the credential; sign out is harmless
	if ( isset( $_POST['certificate_generator_wallet_email'] ) ) {
		$back = remove_query_arg( array( 'cg_wallet_sent', 'cg_wallet_expired' ), wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by wp_validate_redirect() below
		$back = wp_validate_redirect( home_url( $back ), home_url( '/' ) );
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['certificate_generator_wallet_nonce'] ?? '' ) ), 'certificate_generator_wallet_link' ) ) {
			$email     = strtolower( sanitize_email( wp_unslash( $_POST['certificate_generator_wallet_email'] ) ) );
			$throttled = class_exists( 'CertificateGenerator_SecurityHelper' ) && ! CertificateGenerator_SecurityHelper::check_rate_limit( 'cg_wallet_link', 5, 10 * MINUTE_IN_SECONDS );
			$key       = 'certificate_generator_wallet_sent_' . md5( $email );
			if ( '' !== $email && ! $throttled && ! get_transient( $key ) ) {
				set_transient( $key, 1, 2 * MINUTE_IN_SECONDS ); // one link per address every 2 minutes
				certificate_generator_wallet_send_link( $email, $back );
			}
		}
		// Same answer whatever happened, so the form doesn't reveal who has certificates.
		wp_safe_redirect( add_query_arg( 'cg_wallet_sent', '1', $back ) );
		exit;
	}

	if ( isset( $_GET['cg_wallet'] ) ) {
		$token = sanitize_text_field( wp_unslash( $_GET['cg_wallet'] ) );
		$email = certificate_generator_wallet_email_from_token( $token );
		$clean = remove_query_arg( 'cg_wallet' );
		if ( '' !== $email ) {
			$raw = (string) base64_decode( strtr( $token, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$exp = (int) substr( $raw, strrpos( substr( $raw, 0, (int) strrpos( $raw, '|' ) ), '|' ) + 1 );
			certificate_generator_wallet_set_cookie( $token, $exp );
		} else {
			$clean = add_query_arg( 'cg_wallet_expired', '1', $clean );
		}
		wp_safe_redirect( $clean );
		exit;
	}

	if ( isset( $_GET['cg_wallet_signout'] ) ) {
		certificate_generator_wallet_set_cookie( '', time() - HOUR_IN_SECONDS );
		wp_safe_redirect( remove_query_arg( 'cg_wallet_signout' ) );
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}
add_action( 'template_redirect', 'certificate_generator_wallet_handle_request' );

/** show_name for each of these serials (1 when unknown). */
function certificate_generator_wallet_name_flags( array $serials ): array {
	global $wpdb;
	$serials = array_values( array_filter( array_map( 'strval', $serials ) ) );
	if ( ! $serials ) {
		return array();
	}
	$in   = implode( ',', array_fill( 0, count( $serials ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT serial_number, show_name FROM {$wpdb->prefix}certificate_generator WHERE serial_number IN ($in)", ...$serials ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
	return array_map( 'intval', array_column( $rows ?: array(), 'show_name', 'serial_number' ) );
}

/** True when this serial is on one of the email's student or teacher records. */
function certificate_generator_wallet_owns_serial( string $email, string $serial ): bool {
	if ( '' === $email || '' === $serial || ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return false;
	}
	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	foreach ( array( 'students', 'teachers' ) as $entity ) {
		$table = $tables->get_table( $entity );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE email = %s AND serial_number = %s LIMIT 1", $email, $serial ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
			return true;
		}
	}
	return false;
}

/** Show or hide the recipient's name when someone verifies this certificate. */
function certificate_generator_wallet_set_show_name( string $serial, bool $show ): void {
	global $wpdb;
	foreach ( array( 'certificate_generator', 'cg_certificates' ) as $table ) {
		$wpdb->update( $wpdb->prefix . $table, array( 'show_name' => $show ? 1 : 0 ), array( 'serial_number' => $serial ) );
	}
	if ( function_exists( 'certificate_generator_record_event' ) ) {
		certificate_generator_record_event( $serial, 'name_visibility', array( 'shown' => $show ) );
	}
}

function certificate_generator_ajax_wallet_show_name(): void {
	check_ajax_referer( 'certificate_generator_wallet', 'nonce' );
	$serial = sanitize_text_field( wp_unslash( $_POST['serial'] ?? '' ) );
	$email  = certificate_generator_wallet_email();
	if ( ! certificate_generator_wallet_owns_serial( $email, $serial ) ) {
		wp_send_json_error( array( 'message' => __( 'Please open your certificates again from the emailed link.', 'certificate-generator' ) ), 403 );
	}
	$show = '1' === sanitize_text_field( wp_unslash( $_POST['show'] ?? '1' ) );
	certificate_generator_wallet_set_show_name( $serial, $show );
	wp_send_json_success( array( 'show' => $show ) );
}
add_action( 'wp_ajax_certificate_generator_wallet_show_name', 'certificate_generator_ajax_wallet_show_name' );
add_action( 'wp_ajax_nopriv_certificate_generator_wallet_show_name', 'certificate_generator_ajax_wallet_show_name' );

/** The page: the email form, or the signed-in recipient's certificates. */
function certificate_generator_my_certificates_shortcode( $atts = array() ): string {
	$email = certificate_generator_wallet_email();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags
	$sent    = isset( $_GET['cg_wallet_sent'] );
	$expired = isset( $_GET['cg_wallet_expired'] );
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$out = '<div class="cg-wallet" style="max-width:720px;margin:0 auto;">';
	if ( '' === $email ) {
		if ( $sent ) {
			$out .= '<p class="cg-wallet-sent" style="padding:12px 16px;background:#edfaef;border-left:4px solid #00a32a;">' . esc_html__( 'If we have certificates for that address, we\'ve emailed you a link to them. It works for 30 minutes.', 'certificate-generator' ) . '</p>';
		}
		if ( $expired ) {
			$out .= '<p class="cg-wallet-expired" style="padding:12px 16px;background:#fcf0f1;border-left:4px solid #d63638;">' . esc_html__( 'That link has expired or isn\'t valid. Ask for a new one below.', 'certificate-generator' ) . '</p>';
		}
		$out .= '<form method="post" class="cg-wallet-form">'
			. wp_nonce_field( 'certificate_generator_wallet_link', 'certificate_generator_wallet_nonce', false, false )
			. '<p><label for="cg-wallet-email"><strong>' . esc_html__( 'Your email address', 'certificate-generator' ) . '</strong></label><br>'
			. '<input type="email" id="cg-wallet-email" name="certificate_generator_wallet_email" required autocomplete="email" style="width:100%;max-width:420px;padding:8px;"></p>'
			. '<p><button type="submit" class="button">' . esc_html__( 'Email me a link to my certificates', 'certificate-generator' ) . '</button></p>'
			. '<p style="color:#646970;font-size:13px;">' . esc_html__( 'Use the address your certificates were sent to. We email you a private link, so only you can open them.', 'certificate-generator' ) . '</p>'
			. '</form></div>';
		return $out;
	}

	$certificates = array();
	foreach ( array( 'students', 'teachers' ) as $entity ) {
		$certificates = array_merge( $certificates, certificate_generator_student_certificates_for_email( $email, $entity )['certificates'] );
	}
	$flags = certificate_generator_wallet_name_flags( array_column( $certificates, 'serial_number' ) );
	$qr    = class_exists( 'CertificateGenerator_QR_Code_Generator' ) ? CertificateGenerator_QR_Code_Generator::get_instance() : null;

	$out .= '<p>' . esc_html(
		sprintf(
			/* translators: %s: email address */
			__( 'Certificates for %s', 'certificate-generator' ),
			$email
		)
	) . ' · <a href="' . esc_url( add_query_arg( 'cg_wallet_signout', '1' ) ) . '">' . esc_html__( 'Sign out', 'certificate-generator' ) . '</a></p>';

	if ( ! $certificates ) {
		$out .= '<p>' . esc_html__( 'There are no certificates ready for this address yet.', 'certificate-generator' ) . '</p></div>';
		return $out;
	}

	$out .= '<table class="cg-wallet-list" style="width:100%;border-collapse:collapse;">';
	foreach ( $certificates as $c ) {
		$serial = (string) ( $c['serial_number'] ?? '' );
		$verify = ( $qr && '' !== $serial ) ? $qr->generate_qr_data( array(), $serial ) : '';
		$shown  = $flags[ $serial ] ?? 1;
		$out   .= '<tr style="border-bottom:1px solid #dcdcde;"><td style="padding:12px 8px;">'
			. '<strong>' . esc_html( (string) $c['certificate_type'] ) . '</strong><br>'
			. '<span style="color:#646970;font-size:13px;">' . esc_html( trim( str_replace( '_', ' ', (string) $c['school_name'] ) . ' · ' . (string) $c['issue_date'], ' ·' ) ) . '</span>'
			. '</td><td style="padding:12px 8px;text-align:right;white-space:nowrap;">'
			. '<a class="button" href="' . esc_url( $c['url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Download', 'certificate-generator' ) . '</a> '
			. ( '' !== $verify ? '<a class="button" href="' . esc_url( $verify ) . '" target="_blank" rel="noopener">' . esc_html__( 'Verify & share', 'certificate-generator' ) . '</a>' : '' )
			. ( '' !== $serial ? '<br><label style="font-size:13px;"><input type="checkbox" class="cg-wallet-show-name" data-serial="' . esc_attr( $serial ) . '"' . checked( 1, $shown, false ) . '> ' . esc_html__( 'Show my name when someone verifies this', 'certificate-generator' ) . '</label>' : '' )
			. '</td></tr>';
	}
	$out .= '</table>';

	if ( count( $certificates ) > 1 ) {
		$zip  = wp_nonce_url(
			add_query_arg(
				array(
					'action'        => 'certificate_generator_student_zip',
					'student_email' => rawurlencode( $email ),
				),
				admin_url( 'admin-ajax.php' )
			),
			'certificate_generator_student_zip'
		);
		$out .= '<p style="margin-top:16px;"><a class="button button-primary" href="' . esc_url( $zip ) . '">' . esc_html__( 'Download all (ZIP)', 'certificate-generator' ) . '</a></p>';
	}

	$out .= '<script>(function(){document.querySelectorAll(".cg-wallet-show-name").forEach(function(b){b.addEventListener("change",function(){'
		. 'var f=new FormData();f.append("action","certificate_generator_wallet_show_name");f.append("nonce",' . wp_json_encode( wp_create_nonce( 'certificate_generator_wallet' ) ) . ');'
		. 'f.append("serial",b.dataset.serial);f.append("show",b.checked?"1":"0");b.disabled=true;'
		. 'fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){b.disabled=false;if(!j.success){b.checked=!b.checked;alert((j.data&&j.data.message)||"Could not save.");}}).catch(function(){b.disabled=false;b.checked=!b.checked;});'
		. '});});})();</script>';
	$out .= '</div>';
	return $out;
}
add_shortcode( 'certificate_generator_my_certificates', 'certificate_generator_my_certificates_shortcode' );
