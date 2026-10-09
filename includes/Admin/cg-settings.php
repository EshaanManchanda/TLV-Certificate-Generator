<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CG_Admin_Settings {
	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_cg_save_serial_settings', array( $this, 'save_serial_settings' ) );
	}

	public function add_settings_page() {
		add_submenu_page(
			'cg-dashboard',
			'Serial Number Settings',
			'Serial Numbers',
			'manage_options',
			'cg-serial-settings',
			array( $this, 'render_serial_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'cg_serial_group', 'cg_serial_prefix', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cg_serial_group', 'cg_serial_length', array( 'sanitize_callback' => static fn( $v ) => max( 4, min( 12, absint( $v ) ?: 8 ) ) ) );
		register_setting( 'cg_serial_group', 'cg_serial_suffix', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'cg_serial_group', 'cg_serial_reset_period', array( 'sanitize_callback' => static fn( $v ) => in_array( $v, array( 'none', 'daily', 'monthly', 'yearly' ), true ) ? $v : 'none' ) );
		register_setting( 'cg_serial_group', 'cg_serial_include_date', array( 'sanitize_callback' => static fn( $v ) => $v ? 1 : 0 ) );
	}

	public function render_serial_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<?php
			cg_ui_page_header( 'Serial Number Configuration', 'Set the format of new certificate serial numbers. Existing serials are never changed.' );
			cg_ui_card_open( 'Format', array( 'icon' => 'admin-generic', 'class' => 'cg-narrow' ) );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cg_save_serial_settings">
				<?php wp_nonce_field( 'cg_serial_nonce', 'cg_serial_nonce' ); ?>
				
				<table class="form-table">
					<tr>
						<th><label for="cg_serial_prefix">Prefix</label></th>
						<td>
							<input type="text" id="cg_serial_prefix" name="cg_serial_prefix" 
									value="<?php echo esc_attr( get_option( 'cg_serial_prefix', 'CERT' ) ); ?>" 
									class="regular-text">
							<p class="description">Prefix added before the serial number (e.g., CERT-000001)</p>
						</td>
					</tr>
					<tr>
						<th><label for="cg_serial_length">Number Length</label></th>
						<td>
							<input type="number" id="cg_serial_length" name="cg_serial_length" 
									value="<?php echo esc_attr( get_option( 'cg_serial_length', 8 ) ); ?>" 
									min="4" max="12" class="small-text">
							<p class="description">Number of digits in the sequential part (4-12)</p>
						</td>
					</tr>
					<tr>
						<th><label for="cg_serial_suffix">Suffix</label></th>
						<td>
							<input type="text" id="cg_serial_suffix" name="cg_serial_suffix" 
									value="<?php echo esc_attr( get_option( 'cg_serial_suffix', '' ) ); ?>" 
									class="regular-text">
							<p class="description">Optional suffix added after the serial number</p>
						</td>
					</tr>
					<tr>
						<th><label for="cg_serial_reset_period">Reset Period</label></th>
						<td>
							<select id="cg_serial_reset_period" name="cg_serial_reset_period">
								<option value="none" <?php selected( get_option( 'cg_serial_reset_period', 'none' ), 'none' ); ?>>Never Reset</option>
								<option value="daily" <?php selected( get_option( 'cg_serial_reset_period' ), 'daily' ); ?>>Daily</option>
								<option value="monthly" <?php selected( get_option( 'cg_serial_reset_period' ), 'monthly' ); ?>>Monthly</option>
								<option value="yearly" <?php selected( get_option( 'cg_serial_reset_period' ), 'yearly' ); ?>>Yearly</option>
							</select>
							<p class="description">When to reset the sequence counter back to 1</p>
						</td>
					</tr>
					<tr>
						<th><label for="cg_serial_include_date">Include Date in Serial</label></th>
						<td>
							<input type="checkbox" id="cg_serial_include_date" name="cg_serial_include_date" 
									value="1" <?php checked( get_option( 'cg_serial_include_date', false ), 1 ); ?>>
							<p class="description">Include date component in serial number (e.g., CERT-20260405-000001)</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button( 'Save Settings' ); ?>
			</form>
			<?php
			cg_ui_card_close();
			cg_ui_card_open( 'Serial Number Preview', array( 'icon' => 'visibility', 'class' => 'cg-narrow', 'id' => 'cg-serial-preview' ) );
			?>
				<p><strong>Format:</strong> <code id="cg-format-preview">CERT-00000001</code></p>
				<p><strong>Next Serial:</strong> <code id="cg-next-serial">Loading...</code></p>
			<?php cg_ui_card_close(); ?>
		</div>

		<script>
			jQuery(document).ready(function($) {
				function updatePreview() {
					var prefix = $('#cg_serial_prefix').val() || 'CERT';
					var length = parseInt($('#cg_serial_length').val()) || 8;
					var suffix = $('#cg_serial_suffix').val();
					var includeDate = $('#cg_serial_include_date').is(':checked');
					var resetPeriod = $('#cg_serial_reset_period').val();
					
					var datePart = '';
					if (includeDate) {
						var now = new Date();
						switch(resetPeriod) {
							case 'daily':
								datePart = now.getFullYear() + 
											String(now.getMonth()+1).padStart(2,'0') + 
											String(now.getDate()).padStart(2,'0') + '-';
								break;
							case 'monthly':
								datePart = now.getFullYear() + 
											String(now.getMonth()+1).padStart(2,'0') + '-';
								break;
							case 'yearly':
								datePart = now.getFullYear() + '-';
								break;
						}
					}
					
					var seq = '1'.padStart(length, '0');
					var format = prefix + '-' + datePart + seq;
					if (suffix) format += '-' + suffix;
					
					$('#cg-format-preview').text(format);
					$('#cg-next-serial').text(format);
				}
				
				$('#cg_serial_prefix, #cg_serial_length, #cg_serial_suffix, #cg_serial_include_date, #cg_serial_reset_period').on('change input', updatePreview);
				updatePreview();
			});
		</script>
		<?php
	}

	public function save_serial_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		if ( ! isset( $_POST['cg_serial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_serial_nonce'] ) ), 'cg_serial_nonce' ) ) {
			wp_die( 'Invalid nonce' );
		}

		$fields = array( 'cg_serial_prefix', 'cg_serial_length', 'cg_serial_suffix', 'cg_serial_reset_period', 'cg_serial_include_date' );

		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				if ( $field === 'cg_serial_include_date' ) {
					update_option( $field, (bool) $_POST[ $field ] );
				} elseif ( $field === 'cg_serial_length' ) {
					update_option( $field, absint( $_POST[ $field ] ) );
				} else {
					update_option( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
				}
			}
		}

		wp_safe_redirect( add_query_arg( 'updated', 'true', admin_url( 'admin.php?page=cg-serial-settings' ) ) );
		exit;
	}
}