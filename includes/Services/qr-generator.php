<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CG_QR_Code_Generator {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function generate_qr_data( $certificate_data, $serial_number = '' ) {
		$base_url = $this->get_verify_page_url();
		return add_query_arg( 'serial_number', $serial_number, $base_url );
	}

	private function get_verify_page_url(): string {
		$cached = get_transient( 'cg_verify_page_url' );
		if ( $cached ) {
			return $cached;
		}

		global $wpdb;
		$page_id = $wpdb->get_var(
			"SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type = 'page'
             AND post_content LIKE '%[cg_verify_certificate%'
             LIMIT 1"
		);

		$url = $page_id ? get_permalink( $page_id ) : home_url( '/' );
		set_transient( 'cg_verify_page_url', $url, HOUR_IN_SECONDS );
		return $url;
	}

	public function generate_qr_image( $data, $size = 150, $error_correction = 'L' ) {
		if ( ! class_exists( '\Endroid\QrCode\QrCode' ) ) {
			return false; // the QR library ships in vendor/; without it there is no QR
		}

		$ec_levels = array(
			'L' => \Endroid\QrCode\ErrorCorrectionLevel::Low,
			'M' => \Endroid\QrCode\ErrorCorrectionLevel::Medium,
			'Q' => \Endroid\QrCode\ErrorCorrectionLevel::Quartile,
			'H' => \Endroid\QrCode\ErrorCorrectionLevel::High,
		);
		$ec_level  = $ec_levels[ $error_correction ] ?? \Endroid\QrCode\ErrorCorrectionLevel::Low;

		$upload_dir = wp_upload_dir();
		$qr_dir     = $upload_dir['basedir'] . '/cg-qr-codes/';

		if ( ! file_exists( $qr_dir ) ) {
			wp_mkdir_p( $qr_dir );
		}

		// Same data → same image, so a certificate's QR is drawn once, not on every render.
		$filepath = $qr_dir . 'qr_' . md5( $data . '|' . $size . '|' . $error_correction ) . '.png';
		if ( file_exists( $filepath ) ) {
			touch( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- still in use: keep it past the 7-day cleanup
			return $filepath;
		}

		$qr_code = new \Endroid\QrCode\QrCode(
			data: $data,
			errorCorrectionLevel: $ec_level,
			size: $size,
			margin: 2
		);
		// Write aside and rename, so a concurrent render never reads a half-written PNG.
		$tmp = $filepath . '.' . wp_generate_password( 8, false ) . '.tmp';
		( new \Endroid\QrCode\Writer\PngWriter() )->write( $qr_code )->saveToFile( $tmp );
		if ( ! @rename( $tmp, $filepath ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of a temp file in our uploads dir
			wp_delete_file( $tmp ); // another request won the race
		}

		return file_exists( $filepath ) ? $filepath : false;
	}

	public function add_qr_to_pdf( $pdf, $qr_data, $position_x, $position_y, $size_mm = 15 ) {
		$qr_image_path = $this->generate_qr_image( $qr_data, 300 );

		if ( ! $qr_image_path || ! file_exists( $qr_image_path ) ) {
			return false;
		}

		$size_mm = max( 10, min( 50, $size_mm ) );

		$pdf->Image( $qr_image_path, $position_x, $position_y, $size_mm, $size_mm );

		return true;
	}

	public function add_serial_to_pdf( $pdf, $serial_number, $position_x, $position_y, $font_size = 10, $font_style = 'helvetica' ) {
		if ( empty( $serial_number ) ) {
			return false;
		}

		// Normalize font style - convert invalid fonts to valid ones
		$valid_fonts = array( 'helvetica', 'times', 'courier', 'arial', 'helveticab', 'timesb', 'courierb' );
		if ( ! in_array( $font_style, $valid_fonts ) ) {
			$font_style = 'helvetica';
		}

		// Use FontManager to properly load fonts
		$font_manager = CertificateGenerator_FontManager::getInstance();
		$loaded_font  = $font_manager->add_font_to_pdf( $pdf, $font_style, 'B', $font_size );

		$pdf->SetTextColor( 0, 0, 0 );

		$text       = 'Serial: ' . $serial_number;
		$text_width = $pdf->GetStringWidth( $text );

		$pdf->Text( $position_x - ( $text_width / 2 ), $position_y, $text );

		return true;
	}

	public function register_template_meta_fields() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_draggable_assets' ) );
	}

	public function enqueue_draggable_assets( $hook ) {
		global $post;
		if ( $hook === 'post.php' && $post && $post->post_type === 'certificates' ) {
			wp_enqueue_style( 'cg-draggable-css', CERTIFICATE_GENERATOR_URL . 'assets/css/cg-draggable.css', array(), '1.0.0' );
			wp_enqueue_script( 'cg-draggable-js', CERTIFICATE_GENERATOR_URL . 'assets/js/cg-draggable.js', array( 'jquery' ), '1.0.0', true );
			wp_localize_script( 'cg-draggable-js', 'cgDraggable', array( 'enabled' => true ) );
		}
	}

	public function render_position_preview( $post ) {
		?>
		<div id="cg-certificate-preview" style="position: relative; width: 100%; height: 400px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; overflow: hidden;">
			<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #646970; font-size: 14px; text-align: center; pointer-events: none;">
				<p>Draggable QR Code & Serial Number elements will appear here<br>when enabled in settings above.</p>
				<p style="font-size: 12px; color: #999;">Scale: 1mm ≈ 3.78px</p>
			</div>
		</div>
		<p class="description" style="margin-top: 8px;">Drag the QR code or serial number elements to adjust their position on the certificate. Values update automatically.</p>
		<?php
	}

	public function render_qr_serial_meta_box( $post ) {
		wp_nonce_field( 'cg_qr_serial_nonce', 'cg_qr_serial_nonce' );

		$qr_enabled          = get_post_meta( $post->ID, 'qr_enabled', true );
		$qr_size             = get_post_meta( $post->ID, 'qr_size', true ) ?: 15;
		$qr_position_x       = get_post_meta( $post->ID, 'qr_position_x', true ) ?: 250;
		$qr_position_y       = get_post_meta( $post->ID, 'qr_position_y', true ) ?: 180;
		$qr_error_correction = get_post_meta( $post->ID, 'qr_error_correction', true ) ?: 'L';
		$qr_data_fields      = get_post_meta( $post->ID, 'qr_data_fields', true ) ?: '';

		$serial_display    = get_post_meta( $post->ID, 'serial_number_display', true );
		$serial_position_x = get_post_meta( $post->ID, 'serial_number_position_x', true ) ?: 105;
		$serial_position_y = get_post_meta( $post->ID, 'serial_number_position_y', true ) ?: 200;
		$serial_font_size  = get_post_meta( $post->ID, 'serial_number_font_size', true ) ?: 10;
		?>
		<div class="cg-settings-grid">
			<div class="cg-settings-section">
				<h3>QR Code Settings</h3>
				<table class="form-table">
					<tr>
						<th><label for="qr_enabled">Enable QR Code</label></th>
						<td>
							<input type="checkbox" id="qr_enabled" name="qr_enabled" value="1" <?php checked( $qr_enabled, '1' ); ?>>
							<p class="description">Add a QR code to certificates generated from this template</p>
						</td>
					</tr>
					<tr>
						<th><label for="qr_size">QR Size (mm)</label></th>
						<td>
							<input type="number" id="qr_size" name="qr_size" value="<?php echo esc_attr( $qr_size ); ?>" min="10" max="50" step="1" class="small-text">
							<p class="description">Size of QR code on the certificate (10-50mm)</p>
						</td>
					</tr>
					<tr>
						<th><label for="qr_position_x">QR Position X (mm)</label></th>
						<td>
							<input type="number" id="qr_position_x" name="qr_position_x" value="<?php echo esc_attr( $qr_position_x ); ?>" step="0.1" class="regular-text">
							<p class="description">Horizontal position from left edge</p>
						</td>
					</tr>
					<tr>
						<th><label for="qr_position_y">QR Position Y (mm)</label></th>
						<td>
							<input type="number" id="qr_position_y" name="qr_position_y" value="<?php echo esc_attr( $qr_position_y ); ?>" step="0.1" class="regular-text">
							<p class="description">Vertical position from top edge</p>
						</td>
					</tr>
					<tr>
						<th><label for="qr_error_correction">Error Correction</label></th>
						<td>
							<select id="qr_error_correction" name="qr_error_correction">
								<option value="L" <?php selected( $qr_error_correction, 'L' ); ?>>Low (7%)</option>
								<option value="M" <?php selected( $qr_error_correction, 'M' ); ?>>Medium (15%)</option>
								<option value="Q" <?php selected( $qr_error_correction, 'Q' ); ?>>Quartile (25%)</option>
								<option value="H" <?php selected( $qr_error_correction, 'H' ); ?>>High (30%)</option>
							</select>
							<p class="description">Higher correction = more robust but larger QR code</p>
						</td>
					</tr>
					<tr>
						<th><label for="qr_data_fields">QR Data Fields</label></th>
						<td>
							<textarea id="qr_data_fields" name="qr_data_fields" rows="3" class="large-text" placeholder="student_name,certificate_type,issue_date"><?php echo esc_textarea( $qr_data_fields ); ?></textarea>
							<p class="description">Comma-separated field names to include in QR data. Leave empty for default verification URL.</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="cg-settings-section">
				<h3>Serial Number Display</h3>
				<table class="form-table">
					<tr>
						<th><label for="serial_number_display">Show Serial on Certificate</label></th>
						<td>
							<input type="checkbox" id="serial_number_display" name="serial_number_display" value="1" <?php checked( $serial_display, '1' ); ?>>
							<p class="description">Display the serial number text on the generated certificate</p>
						</td>
					</tr>
					<tr>
						<th><label for="serial_position_x">Serial Position X (mm)</label></th>
						<td>
							<input type="number" id="serial_position_x" name="serial_position_x" value="<?php echo esc_attr( $serial_position_x ); ?>" step="0.1" class="regular-text">
							<p class="description">Horizontal position from left edge</p>
						</td>
					</tr>
					<tr>
						<th><label for="serial_position_y">Serial Position Y (mm)</label></th>
						<td>
							<input type="number" id="serial_position_y" name="serial_position_y" value="<?php echo esc_attr( $serial_position_y ); ?>" step="0.1" class="regular-text">
							<p class="description">Vertical position from top edge</p>
						</td>
					</tr>
					<tr>
						<th><label for="serial_font_size">Serial Font Size</label></th>
						<td>
							<input type="number" id="serial_font_size" name="serial_number_font_size" value="<?php echo esc_attr( $serial_font_size ); ?>" min="6" max="24" class="small-text">
							<p class="description">Font size for serial number text</p>
						</td>
					</tr>
				</table>
			</div>
		</div>

		<style>
			.cg-settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
			.cg-settings-section { background: #f9f9f9; padding: 15px; border-radius: 4px; }
			.cg-settings-section h3 { margin-top: 0; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
			.form-table th { width: 200px; }
		</style>
		<?php
	}

	public function render_expiration_meta_box( $post ) {
		wp_nonce_field( 'cg_expiration_nonce', 'cg_expiration_nonce' );

		$exp_value = get_post_meta( $post->ID, 'expiration_period_value', true ) ?: '';
		$exp_unit  = get_post_meta( $post->ID, 'expiration_period_unit', true ) ?: 'never';
		?>
		<table class="form-table">
			<tr>
				<th><label for="expiration_period_unit">Validity Period</label></th>
				<td>
					<select id="expiration_period_unit" name="expiration_period_unit" style="width: 100%;">
						<option value="never" <?php selected( $exp_unit, 'never' ); ?>>Never Expires</option>
						<option value="days" <?php selected( $exp_unit, 'days' ); ?>>Days</option>
						<option value="months" <?php selected( $exp_unit, 'months' ); ?>>Months</option>
						<option value="years" <?php selected( $exp_unit, 'years' ); ?>>Years</option>
					</select>
				</td>
			</tr>
			<tr id="cg-exp-value-row" style="<?php echo $exp_unit === 'never' ? 'display:none;' : ''; ?>">
				<th><label for="expiration_period_value">Duration</label></th>
				<td>
					<input type="number" id="expiration_period_value" name="expiration_period_value" value="<?php echo esc_attr( $exp_value ); ?>" min="1" class="small-text">
					<p class="description">Certificate validity duration</p>
				</td>
			</tr>
		</table>

		<script>
			jQuery(document).ready(function($) {
				$('#expiration_period_unit').on('change', function() {
					if ($(this).val() === 'never') {
						$('#cg-exp-value-row').hide();
					} else {
						$('#cg-exp-value-row').show();
					}
				});
			});
		</script>
		<?php
	}

	public function save_qr_serial_settings( $post_id ) {
		if ( ! isset( $_POST['cg_qr_serial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_qr_serial_nonce'] ) ), 'cg_qr_serial_nonce' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'qr_enabled',
			'qr_size',
			'qr_position_x',
			'qr_position_y',
			'qr_error_correction',
			'qr_data_fields',
			'serial_number_display',
			'serial_number_position_x',
			'serial_number_position_y',
			'serial_number_font_size',
		);

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		if ( ! isset( $_POST['cg_expiration_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_expiration_nonce'] ) ), 'cg_expiration_nonce' ) ) {
			return;
		}

		if ( isset( $_POST['expiration_period_unit'] ) ) {
			update_post_meta( $post_id, 'expiration_period_unit', sanitize_text_field( wp_unslash( $_POST['expiration_period_unit'] ) ) );
		}
		if ( isset( $_POST['expiration_period_value'] ) ) {
			update_post_meta( $post_id, 'expiration_period_value', absint( $_POST['expiration_period_value'] ) );
		}
	}
}