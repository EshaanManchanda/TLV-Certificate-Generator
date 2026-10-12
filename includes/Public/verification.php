<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CertificateGenerator_Public_Verification {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_shortcode( 'certificate_generator_verify_certificate', array( $this, 'render_verification_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_certificate_generator_public_verify', array( $this, 'ajax_public_verify' ) );
		add_action( 'wp_ajax_nopriv_certificate_generator_public_verify', array( $this, 'ajax_public_verify' ) );
		add_action( 'wp_head', array( $this, 'print_share_meta' ), 5 );
	}

	/**
	 * Link-preview tags for a shared verify link (?code=…), so WhatsApp, LinkedIn and X show
	 * "Verified certificate: Participation" instead of a bare URL. Only for a well-formed code,
	 * within the verify rate limit, with the name masked (or none when the recipient hid it).
	 * Not recorded as a verification: preview bots aren't verifiers. SEO plugins print their own
	 * tags, so this stays out of their way.
	 */
	public function print_share_meta(): void {
		if ( ! is_singular() || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) || defined( 'AIOSEO_VERSION' ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_shortcode( (string) $post->post_content, 'certificate_generator_verify_certificate' ) ) {
			return;
		}
		$code = \CertificateGenerator\Services\VerificationCodeService::normalize( sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public lookup
		if ( ! \CertificateGenerator\Services\VerificationCodeService::is_well_formed( $code )
			|| ( class_exists( 'CertificateGenerator_SecurityHelper' ) && ! CertificateGenerator_SecurityHelper::check_rate_limit( 'cg_verify', 20, 60 ) ) ) {
			return;
		}
		$result = ( new \CertificateGenerator\Services\SerialNumberService() )->verify( $code, false );
		if ( empty( $result['data'] ) ) {
			return;
		}
		$d     = $result['data'];
		$type  = (string) ( $d['certificate_type'] ?? '' );
		$state = ! empty( $result['revoked'] ) ? __( 'Revoked certificate', 'certificate-generator' ) : ( ! empty( $result['expired'] ) ? __( 'Expired certificate', 'certificate-generator' ) : __( 'Verified certificate', 'certificate-generator' ) );
		$title = '' !== $type ? $state . ': ' . $type : $state;
		$name  = empty( $d['name_hidden'] ) && '' !== (string) ( $d['student_name'] ?? '' ) ? \CertificateGenerator\Services\SerialNumberService::mask_name( (string) $d['student_name'] ) : '';
		/* translators: 1: masked recipient name, 2: issuer (site) name */
		$desc = '' !== $name ? sprintf( __( 'Issued to %1$s by %2$s.', 'certificate-generator' ), $name, get_bloginfo( 'name' ) )
			/* translators: %s: issuer (site) name */
			: sprintf( __( 'Issued by %s.', 'certificate-generator' ), get_bloginfo( 'name' ) );
		$url = add_query_arg( 'code', $code, get_permalink( $post ) );

		printf( '<meta property="og:type" content="website" />' . "\n" );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		printf( '<meta name="twitter:card" content="summary" />' . "\n" );
	}

	public function enqueue_assets() {
		global $post;

		// Check singular pages/posts directly
		if ( is_singular() && $post && has_shortcode( $post->post_content, 'certificate_generator_verify_certificate' ) ) {
			$this->do_enqueue();
			return;
		}

		// Fallback: if serial_number is in URL, enqueue anyway (QR code deep-link)
		if ( isset( $_GET['serial_number'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only GET parameter, no state change
			$this->do_enqueue();
		}
	}

	private function do_enqueue() {
		wp_enqueue_script( 'cg-verify-js', CERTIFICATE_GENERATOR_URL . 'assets/js/verify-script.js', array( 'jquery' ), '2.2.0', true );
		wp_localize_script(
			'cg-verify-js',
			'cgVerify',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cg_verify_nonce' ),
			)
		);
	}

	private function get_style_options(): array {
		$options = get_option( 'certificate_generator_settings_email' );
		return array(
			'title_color'   => $options['title_color'] ?? '#2c3e50',
			'text_color'    => $options['text_color'] ?? '#7f8c8d',
			'btn_start'     => $options['btn_start'] ?? '#3498db',
			'btn_end'       => $options['btn_end'] ?? '#2980b9',
			'border_radius' => $options['border_radius'] ?? '12',
			// LinkedIn company page id for "Add to Profile" (Settings → General). Digits only.
			'linkedin_org_id' => preg_replace( '/\D/', '', (string) get_option( 'certificate_generator_linkedin_org_id', '' ) ),
			'share_text'      => certificate_generator_share_text(),
		);
	}

	public function render_verification_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'       => 'Verify Certificate',
				'description' => 'Enter the serial number printed on your certificate to verify its authenticity.',
			),
			$atts,
			'certificate_generator_verify_certificate'
		);

		$s  = $this->get_style_options();
		$br = $s['border_radius'];

		ob_start();
		?>
		<div id="cg-verify-container" style="max-width:600px;margin:40px auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;">

			<!-- Search Form Card -->
			<form id="cg-verify-form" style="padding:35px;background:#ffffff;border-radius:<?php echo esc_attr( $br ); ?>px;box-shadow:0 10px 40px rgba(0,0,0,0.08);transition:all .3s ease;">

				<div style="text-align:center;margin-bottom:30px;">
					<!-- Shield-check icon -->
					<div style="margin-bottom:15px;">
						<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none"
							stroke="<?php echo esc_attr( $s['btn_start'] ); ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
							<polyline points="9 12 12 15 16 10" style="opacity:.6"></polyline>
						</svg>
					</div>
					<h2 style="color:<?php echo esc_attr( $s['title_color'] ); ?>;margin:0;font-size:28px;font-weight:700;">
						<?php echo esc_html( $atts['title'] ); ?>
					</h2>
					<p style="color:<?php echo esc_attr( $s['text_color'] ); ?>;margin-top:10px;font-size:16px;">
						<?php echo esc_html( $atts['description'] ); ?>
					</p>
				</div>

				<!-- Input with floating label -->
				<div style="position:relative;margin-bottom:30px;">
					<label for="cg-serial-input" style="position:absolute;left:16px;top:18px;color:<?php echo esc_attr( $s['text_color'] ); ?>;font-size:16px;transition:all .2s ease;pointer-events:none;">
						<?php esc_html_e( 'Verification Code or Serial Number', 'certificate-generator' ); ?>
					</label>
					<input type="text" id="cg-serial-input" name="serial_number" required autocomplete="off"
							placeholder=""
							style="width:100%;padding:26px 16px 10px 16px;background:#f8f9fa;border:2px solid #eaeaea;border-radius:<?php echo esc_attr( $br ); ?>px;font-size:16px;transition:all .3s ease;outline:none;box-sizing:border-box;"
							onfocus="this.style.borderColor='<?php echo esc_attr( $s['btn_start'] ); ?>';this.previousElementSibling.style.top='8px';this.previousElementSibling.style.fontSize='12px';this.previousElementSibling.style.color='<?php echo esc_attr( $s['btn_start'] ); ?>'"
							onblur="if(this.value===''){this.style.borderColor='#eaeaea';this.previousElementSibling.style.top='18px';this.previousElementSibling.style.fontSize='16px';this.previousElementSibling.style.color='<?php echo esc_attr( $s['text_color'] ); ?>'}else{this.style.borderColor='#eaeaea';this.previousElementSibling.style.color='<?php echo esc_attr( $s['text_color'] ); ?>';}">
				</div>

				<!-- Submit button -->
				<button type="submit" id="cg-verify-btn"
						style="display:flex;align-items:center;justify-content:center;width:100%;padding:16px;background:linear-gradient(135deg,<?php echo esc_attr( $s['btn_start'] ); ?>,<?php echo esc_attr( $s['btn_end'] ); ?>);color:#fff;border:none;border-radius:<?php echo esc_html( $br ); ?>px;font-size:16px;font-weight:600;cursor:pointer;transition:all .3s ease;box-shadow:0 4px 15px rgba(0,0,0,0.1);transform:translateY(0);"
						onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 20px rgba(0,0,0,0.15)'"
						onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 15px rgba(0,0,0,0.1)'">
					<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none"
						stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px;">
						<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
					</svg>
					<?php esc_html_e( 'Verify Certificate', 'certificate-generator' ); ?>
				</button>
			</form>

			<!-- Loading spinner -->
			<div id="cg-verify-loading" style="display:none;text-align:center;padding:40px 35px;background:#ffffff;border-radius:<?php echo esc_attr( $br ); ?>px;box-shadow:0 10px 40px rgba(0,0,0,0.08);margin-top:25px;">
				<div style="width:48px;height:48px;border:4px solid #f3f3f3;border-top:4px solid <?php echo esc_attr( $s['btn_start'] ); ?>;border-radius:50%;animation:cgSpin 1s linear infinite;margin:0 auto 15px;"></div>
				<p style="color:<?php echo esc_attr( $s['text_color'] ); ?>;margin:0;font-size:16px;"><?php esc_html_e( 'Verifying certificate...', 'certificate-generator' ); ?></p>
			</div>

			<!-- Result container (JS fills this) -->
			<div id="cg-verify-result" style="display:none;margin-top:25px;"></div>

			<!-- Help text -->
			<div style="text-align:center;margin-top:20px;padding:0 15px;">
				<p style="color:<?php echo esc_attr( $s['text_color'] ); ?>;font-size:14px;">
					<?php esc_html_e( 'The serial number is printed on your certificate, usually near the bottom or embedded in the QR code.', 'certificate-generator' ); ?>
				</p>
			</div>

			<!-- Pass style vars to JS -->
			<script type="application/json" id="cg-verify-styles"><?php echo wp_json_encode( $s ); ?></script>
		</div>

		<style>
			@keyframes cgSpin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}
			@keyframes cgPulse{0%{transform:scale(1);opacity:1}50%{transform:scale(1.05);opacity:.8}100%{transform:scale(1);opacity:1}}
		</style>
		<?php
		return ob_get_clean();
	}

	public function ajax_public_verify() {
		check_ajax_referer( 'cg_verify_nonce', 'nonce' );

		if ( class_exists( 'CertificateGenerator_SecurityHelper' )
			&& ! CertificateGenerator_SecurityHelper::check_rate_limit( 'cg_verify', 20, 60 )
		) {
			wp_send_json_error( array( 'message' => 'Too many verification attempts. Please try again in a minute.' ), 429 );
		}

		// One field: a verification code (from the QR) or a serial number.
		$input = sanitize_text_field( wp_unslash( $_POST['code'] ?? $_POST['serial_number'] ?? '' ) );
		if ( empty( $input ) ) {
			wp_send_json_error( array( 'message' => 'Verification code or serial number is required' ) );
		}

		wp_send_json_success( ( new \CertificateGenerator\Services\SerialNumberService() )->verify( $input ) );
	}
}

/** True when this page is published and holds the verify shortcode. */
function certificate_generator_is_verify_page( int $page_id ): bool {
	$page = $page_id ? get_post( $page_id ) : null;
	return $page && 'page' === $page->post_type && 'publish' === $page->post_status
		&& has_shortcode( (string) $page->post_content, 'certificate_generator_verify_certificate' );
}

/**
 * Make sure a published verify page exists, so QR codes and {verify_link} never point at a
 * missing page. Adopts a page that already has the shortcode, otherwise creates one. Runs on
 * activation, daily from cron and from the setup checklist, never on a front-end request.
 *
 * @return int The verify page's ID, 0 if it couldn't be created.
 */
function certificate_generator_ensure_verify_page(): int {
	$page_id = (int) get_option( 'certificate_generator_verify_page_id', 0 );
	if ( certificate_generator_is_verify_page( $page_id ) ) {
		return $page_id;
	}

	$page_id = certificate_generator_find_verify_page();
	if ( ! $page_id ) {
		$page_id = (int) wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Verify Certificate', 'certificate-generator' ),
				'post_name'    => 'verify-certificate',
				'post_content' => '[certificate_generator_verify_certificate]',
			)
		);
	}
	if ( $page_id ) {
		update_option( 'certificate_generator_verify_page_id', $page_id );
	}
	delete_transient( 'certificate_generator_verify_page_url' );
	return $page_id;
}

/** First published page holding the verify shortcode, 0 if none. */
function certificate_generator_find_verify_page(): int {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
			'%' . $wpdb->esc_like( '[certificate_generator_verify_certificate' ) . '%'
		)
	);
}

/**
 * URL of the verify page, for QR codes and {verify_link}. Sites that haven't run
 * certificate_generator_ensure_verify_page() yet get any page with the shortcode (looked up
 * once an hour), then /verify-certificate/.
 */
function certificate_generator_verify_page_url(): string {
	$page_id = (int) get_option( 'certificate_generator_verify_page_id', 0 );
	if ( certificate_generator_is_verify_page( $page_id ) ) {
		return (string) get_permalink( $page_id );
	}
	$url = get_transient( 'certificate_generator_verify_page_url' );
	if ( ! $url ) {
		$found = certificate_generator_find_verify_page();
		$url   = $found ? (string) get_permalink( $found ) : home_url( '/verify-certificate/' );
		set_transient( 'certificate_generator_verify_page_url', $url, HOUR_IN_SECONDS );
	}
	return $url;
}

/**
 * Text posted with a shared verify link (X, WhatsApp), before the link itself.
 * {certificate_type} and {issuer} (the site name) are filled in on the verify page.
 * Filter: certificate_generator_share_text (the Pro add-on makes it a setting).
 */
function certificate_generator_share_text(): string {
	/* translators: keep {certificate_type} and {issuer}; they are replaced on the verify page */
	$default = __( 'My {certificate_type} certificate from {issuer} — verify it here:', 'certificate-generator' );
	$text    = trim( (string) apply_filters( 'certificate_generator_share_text', $default ) );
	return '' !== $text ? $text : $default;
}
