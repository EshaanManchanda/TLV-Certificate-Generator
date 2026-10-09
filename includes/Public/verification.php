<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CG_Public_Verification {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_shortcode( 'cg_verify_certificate', array( $this, 'render_verification_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cg_public_verify', array( $this, 'ajax_public_verify' ) );
		add_action( 'wp_ajax_nopriv_cg_public_verify', array( $this, 'ajax_public_verify' ) );
	}

	public function enqueue_assets() {
		global $post;

		// Check singular pages/posts directly
		if ( is_singular() && $post && has_shortcode( $post->post_content, 'cg_verify_certificate' ) ) {
			$this->do_enqueue();
			return;
		}

		// Fallback: if serial_number is in URL, enqueue anyway (QR code deep-link)
		if ( isset( $_GET['serial_number'] ) ) {
			$this->do_enqueue();
		}
	}

	private function do_enqueue() {
		wp_enqueue_script( 'cg-verify-js', CERTIFICATE_GENERATOR_URL . 'assets/js/verify-script.js', array( 'jquery' ), '2.0.2', true );
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
		);
	}

	public function render_verification_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'title'       => 'Verify Certificate',
				'description' => 'Enter the serial number printed on your certificate to verify its authenticity.',
			),
			$atts,
			'cg_verify_certificate'
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
						<?php esc_html_e( 'Serial Number', 'certificate-generator' ); ?>
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

		$serial = sanitize_text_field( wp_unslash( $_POST['serial_number'] ?? '' ) );
		if ( empty( $serial ) ) {
			wp_send_json_error( array( 'message' => 'Serial number is required' ) );
		}

		if ( class_exists( 'CG_Serial_Number_Generator' ) ) {
			$serial_gen = CG_Serial_Number_Generator::get_instance();
			$result     = $serial_gen->verify( $serial );
			wp_send_json_success( $result );
		}

		// Fallback: query certificate_generator table directly
		global $wpdb;
		$table = $wpdb->prefix . 'cg_certificates';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			$table = $wpdb->prefix . 'certificate_generator';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$cert = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE serial_number = %s",
				$serial
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $cert ) {
			wp_send_json_success(
				array(
					'valid'   => false,
					'message' => 'Certificate not found',
					'data'    => null,
				)
			);
		}

		$is_expired = false;
		if ( ! empty( $cert->expires_at ) ) {
			$is_expired = strtotime( $cert->expires_at ) < time();
		}

		$recipient_name = $cert->recipient_name ?? $cert->student_name ?? '';

		wp_send_json_success(
			array(
				'valid'   => true,
				'expired' => $is_expired,
				'message' => $is_expired ? 'Certificate has expired' : 'Certificate is valid',
				'data'    => array(
					'student_name'     => $recipient_name,
					'certificate_type' => $cert->certificate_type ?? '',
					'serial_number'    => $cert->serial_number,
					'issued_at'        => ! empty( $cert->issued_at ) ? cg_format_date( $cert->issued_at ) : '',
					'expires_at'       => ! empty( $cert->expires_at ) ? cg_format_date( $cert->expires_at ) : '',
				),
			)
		);
	}
}
