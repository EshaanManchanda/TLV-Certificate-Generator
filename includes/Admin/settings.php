<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Plugin Admin Settings
 *
 * @package Certificate Generator
 */

// Add admin menu item
add_action( 'admin_menu', 'certificate_generator_add_admin_menu' );
function certificate_generator_add_admin_menu() {
	add_options_page(
		__( 'Certificate Generator Settings', 'certificate-generator' ),
		__( 'Certificate Generator', 'certificate-generator' ),
		'manage_options',
		'certificate_generator_settings',
		'certificate_generator_settings_page'
	);
}

// Initialize settings
add_action( 'admin_init', 'certificate_generator_settings_init' );

// Initialize settings
function certificate_generator_settings_init() {
	register_setting(
		'certificate_generator_settings',
		'certificate_generator_settings_email',
		array(
			'sanitize_callback' => 'certificate_generator_sanitize_settings',
		)
	);

	// Rate limit settings
	register_setting(
		'certificate_generator_settings',
		'certificate_generator_rate_limits',
		array(
			'sanitize_callback' => 'certificate_generator_sanitize_rate_limits',
			'type'              => 'array',
		)
	);

	// General Settings Section
	add_settings_section(
		'certificate_generator_settings_section',
		__( 'General Settings', 'certificate-generator' ),
		'certificate_generator_settings_section_callback',
		'certificate_generator_settings'
	);

	add_settings_field(
		'certificate_generator_email',
		__( 'Contact Email', 'certificate-generator' ),
		'certificate_generator_email_render',
		'certificate_generator_settings',
		'certificate_generator_settings_section'
	);

	add_settings_field(
		'certificate_generator_card_styles',
		__( 'Card Styling', 'certificate-generator' ),
		'certificate_generator_card_styles_render',
		'certificate_generator_settings',
		'certificate_generator_settings_section'
	);

	// Add Email Template Settings Section
	add_settings_section(
		'certificate_generator_email_templates_section',
		__( 'Email Templates', 'certificate-generator' ),
		'certificate_generator_email_templates_section_callback',
		'certificate_generator_settings'
	);

	// Add auto-send settings
	add_settings_field(
		'certificate_generator_auto_send_enabled',
		__( 'Auto-Send Emails', 'certificate-generator' ),
		'certificate_generator_auto_send_render',
		'certificate_generator_settings_email',
		'certificate_generator_email_templates_section'
	);

	// Add email logo setting
	add_settings_field(
		'certificate_generator_email_logo',
		__( 'Email Logo', 'certificate-generator' ),
		'certificate_generator_email_logo_render',
		'certificate_generator_settings_email',
		'certificate_generator_email_templates_section'
	);

	// Add email template settings for each post type
	$post_types = array( 'students', 'teachers', 'schools' );

	foreach ( $post_types as $post_type ) {
		add_settings_field(
			'certificate_generator_' . $post_type . '_email_template',
			/* translators: %s: recipient type, e.g. Students */ sprintf( __( '%s Email Template', 'certificate-generator' ), ucfirst( $post_type ) ),
			'certificate_generator_email_template_render',
			'certificate_generator_settings',
			'certificate_generator_email_templates_section',
			array( 'post_type' => $post_type )
		);
	}

	// Add Rate Limit Settings Section
	add_settings_section(
		'certificate_generator_rate_limit_section',
		__( 'Email Rate Limits', 'certificate-generator' ),
		'certificate_generator_rate_limit_section_callback',
		'certificate_generator_settings'
	);

	add_settings_field(
		'certificate_generator_rate_limits',
		__( 'Rate Limit Configuration', 'certificate-generator' ),
		'certificate_generator_rate_limits_render',
		'certificate_generator_settings',
		'certificate_generator_rate_limit_section'
	);

	// Add SMTP Settings Section
	add_settings_section(
		'certificate_generator_smtp_section',
		__( 'SMTP Settings', 'certificate-generator' ),
		'certificate_generator_smtp_section_callback',
		'certificate_generator_settings'
	);

	// Add SMTP fields
	add_settings_field(
		'certificate_generator_smtp_settings',
		__( 'SMTP Configuration', 'certificate-generator' ),
		'certificate_generator_smtp_settings_render',
		'certificate_generator_settings',
		'certificate_generator_smtp_section'
	);
}

function certificate_generator_encrypt_smtp_password( $plaintext ) {
	if ( empty( $plaintext ) ) {
		return '';
	}
	$key    = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
	$iv_len = openssl_cipher_iv_length( 'AES-256-CBC' );
	$iv     = random_bytes( $iv_len );
	$cipher = openssl_encrypt( $plaintext, 'AES-256-CBC', $key, 0, $iv );
	return base64_encode( $iv . $cipher );
}

function certificate_generator_decrypt_smtp_password( $stored ) {
	if ( empty( $stored ) ) {
		return '';
	}
	$key     = substr( hash( 'sha256', SECURE_AUTH_KEY ), 0, 32 );
	$iv_len  = openssl_cipher_iv_length( 'AES-256-CBC' );
	$decoded = base64_decode( $stored );
	$iv      = substr( $decoded, 0, $iv_len );
	$cipher  = substr( $decoded, $iv_len );
	$plain   = openssl_decrypt( $cipher, 'AES-256-CBC', $key, 0, $iv );
	return $plain === false ? '' : $plain;
}

// Sanitize settings input
function certificate_generator_sanitize_settings( $input ) {
	if ( ! is_array( $input ) ) {
		$input = array();
	}

	// Start from existing saved data — prevents data loss when only one tab is submitted
	$existing = get_option( 'certificate_generator_settings_email', array() );
	$output   = is_array( $existing ) ? $existing : array();

	// Detect which tab's form was submitted
	$is_general_tab   = array_key_exists( 'email', $input ) || array_key_exists( 'card_bg', $input );
	$is_templates_tab = array_key_exists( 'email_logo', $input ) || array_key_exists( 'auto_send_enabled', $input )
						|| array_key_exists( 'smtp_host', $input );

	if ( $is_general_tab ) {
		// Validate email
		if ( isset( $input['email'] ) && filter_var( $input['email'], FILTER_VALIDATE_EMAIL ) ) {
			$output['email'] = sanitize_email( $input['email'] );
		} elseif ( array_key_exists( 'email', $input ) ) {
			$output['email'] = ''; // allow clearing
		}

		// Validate colors
		foreach ( array( 'card_bg', 'title_color', 'text_color', 'btn_start', 'btn_end' ) as $color_field ) {
			if ( isset( $input[ $color_field ] ) ) {
				$output[ $color_field ] = sanitize_hex_color( $input[ $color_field ] );
			}
		}

		// Validate numeric values
		foreach ( array( 'hover_effect', 'border_radius' ) as $numeric_field ) {
			if ( isset( $input[ $numeric_field ] ) ) {
				$output[ $numeric_field ] = absint( $input[ $numeric_field ] );
			}
		}
	}

	if ( $is_templates_tab ) {
		// Checkbox: always set so unchecking saves false
		$output['auto_send_enabled'] = ! empty( $input['auto_send_enabled'] );

		// Email logo URL
		if ( isset( $input['email_logo'] ) ) {
			$output['email_logo'] = esc_url_raw( $input['email_logo'] );
		}

		// Email template fields for each post type
		$post_types = array( 'students', 'teachers', 'schools' );
		foreach ( $post_types as $post_type ) {
			$prefix = $post_type . '_email_';

			foreach ( array( 'subject', 'title', 'reply_to', 'cc', 'bcc' ) as $field ) {
				$key = $prefix . $field;
				if ( isset( $input[ $key ] ) ) {
					$output[ $key ] = sanitize_text_field( $input[ $key ] );
				}
			}

			$message_key = $prefix . 'message';
			if ( isset( $input[ $message_key ] ) ) {
				$output[ $message_key ] = wp_kses_post( $input[ $message_key ] );
			}

			// Checkbox: always set for this tab
			$checkbox_key            = $prefix . 'attach_certificate';
			$output[ $checkbox_key ] = isset( $input[ $checkbox_key ] ) ? '1' : '0';
		}

		// SMTP settings
		foreach ( array( 'host', 'port', 'username', 'password', 'encryption' ) as $smtp_field ) {
			$key = 'smtp_' . $smtp_field;
			if ( isset( $input[ $key ] ) ) {
				if ( $smtp_field === 'password' ) {
					$plain = sanitize_text_field( wp_unslash( $input[ $key ] ) );
					if ( $plain !== '' ) {
						$output[ $key ] = certificate_generator_encrypt_smtp_password( $plain );
					}
					// else: leave existing encrypted value untouched (already in $output from $existing)
				} else {
					$output[ $key ] = sanitize_text_field( $input[ $key ] );
				}
			}
		}
	}

	return $output;
}

// Email templates section description
function certificate_generator_email_templates_section_callback() {
	echo '<p>' . esc_html__( 'Configure email templates and auto-send settings for sending certificates to recipients.', 'certificate-generator' ) . '</p>';
}

// Auto-send setting render function
function certificate_generator_auto_send_render() {
	$options           = get_option( 'certificate_generator_settings_email', array() );
	$auto_send_enabled = isset( $options['auto_send_enabled'] ) ? $options['auto_send_enabled'] : false;

	echo '<label>';
	echo '<input type="checkbox" name="certificate_generator_settings_email[auto_send_enabled]" value="1" ' . checked( 1, $auto_send_enabled, false ) . ' />';
	echo ' ' . esc_html__( 'Automatically send certificate emails when certificates are found/generated during search', 'certificate-generator' );
	echo '</label>';
	echo '<p class="description">' . esc_html__( 'When enabled, emails will be sent automatically when users search for and find their certificates, but only if they haven\'t been sent before.', 'certificate-generator' ) . '</p>';
}

// Email logo setting render function
function certificate_generator_email_logo_render() {
	$options    = get_option( 'certificate_generator_settings_email', array() );
	$email_logo = $options['email_logo'] ?? '';
	$field_id   = 'cg_email_logo_url';
	$preview_id = $field_id . '-preview';
	?>
	<div class="cg-row">
		<input type="url"
				id="<?php echo esc_attr( $field_id ); ?>"
				name="certificate_generator_settings_email[email_logo]"
				value="<?php echo esc_url( $email_logo ); ?>"
				class="regular-text cg-grow"
				
				placeholder="https://example.com/logo.png">
		<button type="button"
				class="button button-secondary cg-media-btn"
				data-input="#<?php echo esc_attr( $field_id ); ?>"
				data-preview="#<?php echo esc_attr( $preview_id ); ?>"
				data-title="Select Email Logo"
				data-button-text="Use this image">
			📁 Choose Image
		</button>
	</div>
	<p class="description"><?php esc_html_e( 'Logo shown in certificate emails. Leave blank for no logo.', 'certificate-generator' ); ?></p>
	<div id="<?php echo esc_attr( $preview_id ); ?>" style="margin-top:10px;<?php echo empty( $email_logo ) ? 'display:none' : ''; ?>">
		<?php if ( ! empty( $email_logo ) ) : ?>
			<img class="cg-set-logo" src="<?php echo esc_url( $email_logo ); ?>" alt="Email Logo Preview"
				>
		<?php endif; ?>
	</div>
	<?php
}

// SMTP section description
function certificate_generator_smtp_section_callback() {
	echo '<p>' . esc_html__( 'Configure SMTP settings for sending emails. Leave blank to use the default WordPress mail function.', 'certificate-generator' ) . '</p>';
}

// Render email template fields
function certificate_generator_email_template_render( $args ) {
	$post_type = $args['post_type'];
	$options   = get_option( 'certificate_generator_settings_email' );
	$prefix    = $post_type . '_email_';

	$fields = array(
		'subject'            => array(
			'label'   => __( 'Email Subject', 'certificate-generator' ),
			'type'    => 'text',
			'default' => sprintf( /* translators: %s: recipient type, e.g. Student */ __( 'Your %s Certificate', 'certificate-generator' ), ucfirst( rtrim( $post_type, 's' ) ) ),
			'desc'    => __( 'Subject line for the email', 'certificate-generator' ),
		),
		'title'              => array(
			'label'   => __( 'Email Title', 'certificate-generator' ),
			'type'    => 'text',
			'default' => sprintf( /* translators: %s: recipient type, e.g. Student */ __( 'Your %s Certificate is Ready', 'certificate-generator' ), ucfirst( rtrim( $post_type, 's' ) ) ),
			'desc'    => __( 'Title displayed at the top of the email', 'certificate-generator' ),
		),
		'message'            => array(
			'label'   => __( 'Email Message', 'certificate-generator' ),
			'type'    => 'textarea',
			'default' => sprintf( /* translators: %s: recipient type, e.g. student */ __( 'Dear {name},\n\nPlease find attached your %s certificate.\n\nThank you!', 'certificate-generator' ), rtrim( $post_type, 's' ) ),
			'desc'    => __( 'Message body. Available placeholders: {name} (recipient name), {certificate_title} (certificate type), {result_link} (result page URL), {zip_link} (ZIP download URL if available), {certificate_count} (number of certificates), {email} (recipient email)', 'certificate-generator' ),
		),
		'attach_certificate' => array(
			'label'   => __( 'Attach Certificate', 'certificate-generator' ),
			'type'    => 'checkbox',
			'default' => '1',
			'desc'    => __( 'Attach the generated certificate PDF to the email', 'certificate-generator' ),
		),
		'reply_to'           => array(
			'label'   => __( 'Reply-To Email', 'certificate-generator' ),
			'type'    => 'email',
			'default' => '',
			'desc'    => __( 'Email address for replies (leave blank to use Contact Email)', 'certificate-generator' ),
		),
		'cc'                 => array(
			'label'   => __( 'CC', 'certificate-generator' ),
			'type'    => 'text',
			'default' => '',
			'desc'    => __( 'Carbon copy recipients (comma-separated emails)', 'certificate-generator' ),
		),
		'bcc'                => array(
			'label'   => __( 'BCC', 'certificate-generator' ),
			'type'    => 'text',
			'default' => '',
			'desc'    => __( 'Blind carbon copy recipients (comma-separated emails)', 'certificate-generator' ),
		),
	);

	echo '<div class="email-template-section cg-set-box">';
	echo '<h3>' . esc_html( ucfirst( $post_type ) ) . ' ' . esc_html__( 'Email Template', 'certificate-generator' ) . '</h3>';

	foreach ( $fields as $field => $config ) {
		$key   = $prefix . $field;
		$value = isset( $options[ $key ] ) ? $options[ $key ] : $config['default'];

		echo '<div class="email-field cg-mb">';
		echo '<label class="cg-set-label"><strong>' . esc_html( $config['label'] ) . '</strong></label>';

		if ( $config['type'] === 'textarea' ) {
			// Use WordPress editor for HTML support
			wp_editor(
				$value,
				'certificate_generator_settings_email_' . $key,
				array(
					'textarea_name' => 'certificate_generator_settings_email[' . esc_attr( $key ) . ']',
					'media_buttons' => false,
					'textarea_rows' => 4,
					'teeny'         => true,
					'tinymce'       => array(
						'toolbar1' => 'bold,italic,underline,link,unlink,forecolor,backcolor,removeformat',
						'toolbar2' => '',
					),
				)
			);
		} elseif ( $config['type'] === 'checkbox' ) {
			$checked = ! empty( $value ) ? 'checked' : '';
			echo '<input type="checkbox" name="certificate_generator_settings_email[' . esc_attr( $key ) . ']" value="1" ' . esc_html( $checked ) . '>';
		} else {
			echo '<input class="widefat" type="' . esc_attr( $config['type'] ) . '" name="certificate_generator_settings_email[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}

		echo '<p class="description">' . esc_html( $config['desc'] ) . '</p>';
		echo '</div>';
	}

	echo '</div>';
}

// Render SMTP settings fields
function certificate_generator_smtp_settings_render() {
	$options = get_option( 'certificate_generator_settings_email' );

	// Get email status information
	$email_status        = certificate_generator_get_email_status();
	$wp_mail_smtp_active = $email_status['smtp_configured'];

	// Display current email delivery status
	echo '<div class="smtp-settings cg-set-box">';

	// Email delivery status section
	echo '<div class="email-status-section cg-set-box cg-set-box--muted">';
	echo '<h4 class="cg-m0">' . esc_html__( 'Current Email Delivery Method', 'certificate-generator' ) . '</h4>';
	echo '<table class="form-table cg-m0">';
	echo '<tr><td class="cg-set-kv"><strong>' . esc_html__( 'Method:', 'certificate-generator' ) . '</strong></td><td class="cg-set-kv">' . esc_html( $email_status['method'] ) . '</td></tr>';
	echo '<tr><td class="cg-set-kv"><strong>' . esc_html__( 'From Email:', 'certificate-generator' ) . '</strong></td><td class="cg-set-kv">' . esc_html( $email_status['from_email'] ) . '</td></tr>';
	echo '<tr><td class="cg-set-kv"><strong>' . esc_html__( 'From Name:', 'certificate-generator' ) . '</strong></td><td class="cg-set-kv">' . esc_html( $email_status['from_name'] ) . '</td></tr>';
	echo '</table>';
	echo '</div>';

	if ( $wp_mail_smtp_active ) {
		certificate_generator_ui_notice(
			'success',
			'<strong>' . esc_html__( 'WP Mail SMTP Active!', 'certificate-generator' ) . '</strong><br>'
			. esc_html__( 'WP Mail SMTP plugin is active and configured. All emails will be sent through your SMTP settings for better deliverability.', 'certificate-generator' ) . '<br><br>'
			. '<a href="' . esc_url( admin_url( 'admin.php?page=wp-mail-smtp' ) ) . '" class="button">' . esc_html__( 'Configure WP Mail SMTP', 'certificate-generator' ) . '</a>',
			false,
			true
		);
	} else {
		$php_mail_disabled = ! function_exists( 'mail' );
		if ( $php_mail_disabled ) {
			$configure = ( is_plugin_active( 'wp-mail-smtp/wp_mail_smtp.php' ) || is_plugin_active( 'wp-mail-smtp-pro/wp_mail_smtp.php' ) )
				? ' <a href="' . esc_url( admin_url( 'admin.php?page=wp-mail-smtp' ) ) . '" class="button">' . esc_html__( 'Configure WP Mail SMTP', 'certificate-generator' ) . '</a>'
				: '';
			certificate_generator_ui_notice(
				'error',
				'<strong>' . esc_html__( 'Email Sending Will FAIL — PHP mail() Disabled', 'certificate-generator' ) . '</strong><br>'
				. esc_html__( 'Your server has PHP\'s mail() function disabled. Emails cannot be sent until you configure WP Mail SMTP with a real SMTP provider (e.g. Gmail, Mailgun, SendGrid).', 'certificate-generator' ) . '<br><br>'
				. '<a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener" class="button button-primary">' . esc_html__( 'Get WP Mail SMTP', 'certificate-generator' ) . '</a>' . $configure,
				false,
				true
			);
		} else {
			certificate_generator_ui_notice(
				'info',
				'<strong>' . esc_html__( 'Fallback Email System Active', 'certificate-generator' ) . '</strong><br>'
				. esc_html__( 'Emails will be sent using WordPress default method (PHP mail) with a fallback "noreply@yourdomain.com" sender address, similar to how Forminator works. This works on most shared hosting providers.', 'certificate-generator' ) . '<br>'
				. '<strong>' . esc_html__( 'For better deliverability:', 'certificate-generator' ) . '</strong> ' . esc_html__( 'Install WP Mail SMTP plugin to use proper SMTP configuration.', 'certificate-generator' ) . '<br><br>'
				. '<a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener" class="button button-primary">' . esc_html__( 'Get WP Mail SMTP (Recommended)', 'certificate-generator' ) . '</a>',
				false,
				true
			);
		}
	}

	echo '<p class="cg-hint">' . esc_html__( 'Note: The SMTP settings below are deprecated and will be removed in a future version. Please use WP Mail SMTP plugin for SMTP configuration.', 'certificate-generator' ) . '</p>';

	$fields = array(
		'host'       => array(
			'label'   => __( 'SMTP Host', 'certificate-generator' ),
			'type'    => 'text',
			'default' => 'smtp.gmail.com',
			'desc'    => __( 'SMTP server address (e.g., smtp.gmail.com)', 'certificate-generator' ),
		),
		'port'       => array(
			'label'   => __( 'SMTP Port', 'certificate-generator' ),
			'type'    => 'number',
			'default' => '587',
			'desc'    => __( 'SMTP port (usually 587 for TLS, 465 for SSL)', 'certificate-generator' ),
		),
		'encryption' => array(
			'label'   => __( 'Encryption', 'certificate-generator' ),
			'type'    => 'select',
			'options' => array(
				''    => __( 'None', 'certificate-generator' ),
				'ssl' => __( 'SSL', 'certificate-generator' ),
				'tls' => __( 'TLS', 'certificate-generator' ),
			),
			'default' => 'tls',
			'desc'    => __( 'Type of encryption to use', 'certificate-generator' ),
		),
		'username'   => array(
			'label'   => __( 'SMTP Username', 'certificate-generator' ),
			'type'    => 'text',
			'default' => '',
			'desc'    => __( 'SMTP account username', 'certificate-generator' ),
		),
		'password'   => array(
			'label'   => __( 'SMTP Password', 'certificate-generator' ),
			'type'    => 'password',
			'default' => '',
			'desc'    => __( 'SMTP account password', 'certificate-generator' ),
		),
	);

	foreach ( $fields as $field => $config ) {
		$key   = 'smtp_' . $field;
		$value = isset( $options[ $key ] ) ? $options[ $key ] : $config['default'];
		if ( $field === 'password' ) {
			$value = certificate_generator_decrypt_smtp_password( $value );
		}

		echo '<div class="smtp-field cg-mb">';
		echo '<label class="cg-set-label"><strong>' . esc_html( $config['label'] ) . '</strong></label>';

		if ( $config['type'] === 'select' ) {
			echo '<select class="widefat" name="certificate_generator_settings_email[' . esc_attr( $key ) . ']">';
			foreach ( $config['options'] as $option_value => $option_label ) {
				echo '<option value="' . esc_attr( $option_value ) . '"' . selected( $value, $option_value, false ) . '>' . esc_html( $option_label ) . '</option>';
			}
			echo '</select>';
		} else {
			echo '<input class="widefat" type="' . esc_attr( $config['type'] ) . '" name="certificate_generator_settings_email[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}

		echo '<p class="description">' . esc_html( $config['desc'] ) . '</p>';
		echo '</div>';
	}

	echo '<p>' . esc_html__( 'Note: Password is stored encrypted in the database.', 'certificate-generator' ) . '</p>';

	// ── SMTP Test Email ───────────────────────────────────────────────────────
	echo '<hr class="cg-sep">';
	echo '<h3 class="cg-m0">' . esc_html__( 'Test Email Delivery', 'certificate-generator' ) . '</h3>';
	echo '<p>' . esc_html__( 'Send a test email to confirm your current mail configuration is working.', 'certificate-generator' ) . '</p>';
	echo '<div class="cg-row">';
	echo '<input class="regular-text" type="email" id="cg_smtp_test_recipient" value="' . esc_attr( get_option( 'admin_email' ) ) . '" placeholder="recipient@example.com">';
	echo '<button type="button" id="cg_smtp_test_btn" class="button button-secondary">' . esc_html__( 'Send Test Email', 'certificate-generator' ) . '</button>';
	echo '<span id="cg_smtp_test_result"></span>';
	echo '</div>';
	echo '<script>
    jQuery(function($){
        $("#cg_smtp_test_btn").on("click", function(){
            var btn = $(this), result = $("#cg_smtp_test_result");
            btn.prop("disabled", true).text("' . esc_js( __( 'Sending…', 'certificate-generator' ) ) . '");
            result.css("color","").text("");
            $.post(ajaxurl, {
                action:    "certificate_generator_send_smtp_test",
                nonce:     "' . esc_html( wp_create_nonce( 'cg_smtp_test_nonce' ) ) . '",
                recipient: $("#cg_smtp_test_recipient").val()
            }, function(r){
                if (r.success && r.data.sent) {
                    result.css("color","green").text("✓ ' . esc_js( __( 'Email sent to', 'certificate-generator' ) ) . ' " + r.data.to);
                } else {
                    result.css("color","red").text("✗ ' . esc_js( __( 'Send failed — check your mail configuration.', 'certificate-generator' ) ) . '");
                }
                btn.prop("disabled", false).text("' . esc_js( __( 'Send Test Email', 'certificate-generator' ) ) . '");
            }).fail(function(){
                result.css("color","red").text("✗ ' . esc_js( __( 'AJAX error.', 'certificate-generator' ) ) . '");
                btn.prop("disabled", false).text("' . esc_js( __( 'Send Test Email', 'certificate-generator' ) ) . '");
            });
        });
    });
    </script>';

	echo '</div>';
}

// AJAX: send SMTP test email
add_action(
	'wp_ajax_certificate_generator_send_smtp_test',
	function () {
		check_ajax_referer( 'cg_smtp_test_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$to   = sanitize_email( wp_unslash( $_POST['recipient'] ?? get_option( 'admin_email' ) ) );
		$sent = wp_mail(
			$to,
			'Certificate Generator — SMTP Test',
			"This is a test email sent by the Certificate Generator plugin to verify that your WordPress mail configuration is working correctly.\n\nIf you received this, everything is set up correctly.",
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);
		wp_send_json_success(
			array(
				'sent' => $sent,
				'to'   => $to,
			)
		);
	}
);

// AJAX: publish a single scheduled/draft template immediately.
add_action(
	'wp_ajax_certificate_generator_publish_template_now',
	function () {
		check_ajax_referer( 'certificate_generator_publish_template_now', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$id = absint( $_POST['template_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid template ID' ) );
		}

		if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			wp_send_json_error( array( 'message' => 'Database layer unavailable' ) );
		}

		$tables = \CertificateGenerator\Database\CustomTables::instance();
		$table  = $tables->get_table( 'certificate_templates' );

		$updated = $GLOBALS['wpdb']->update(
			$table,
			array(
				'status'     => 'published',
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( $updated === false ) {
			wp_send_json_error( array( 'message' => 'DB update failed' ) );
		}

		wp_send_json_success( array( 'id' => $id ) );
	}
);

// Render email input field
function certificate_generator_email_render() {
	$options = get_option( 'certificate_generator_settings_email' );
	?>
	<input type="email" name="certificate_generator_settings_email[email]"
			value="<?php echo esc_attr( $options['email'] ?? '' ); ?>"
			required>
	<?php
}

// Get contact email from settings, falling back to WP admin email
function certificate_generator_get_contact_email(): string {
	$options = get_option( 'certificate_generator_settings_email' );
	$email   = $options['email'] ?? '';
	return ! empty( $email ) ? $email : (string) get_option( 'admin_email', '' );
}

// Render card styling fields
function certificate_generator_card_styles_render() {
	$options = get_option( 'certificate_generator_settings_email' );
	$fields  = array(
		'card_bg'       => array(
			'label'   => __( 'Card Background Color', 'certificate-generator' ),
			'default' => '#f9f9f9',
			'desc'    => __( 'Background color for the certificate results container', 'certificate-generator' ),
		),
		'title_color'   => array(
			'label'   => __( 'Title Color', 'certificate-generator' ),
			'default' => '#2c3e50',
			'desc'    => __( 'Color for certificate titles and headings', 'certificate-generator' ),
		),
		'text_color'    => array(
			'label'   => __( 'Text Color', 'certificate-generator' ),
			'default' => '#7f8c8d',
			'desc'    => __( 'Color for regular text in certificates', 'certificate-generator' ),
		),
		'btn_start'     => array(
			'label'   => __( 'Button Gradient Start', 'certificate-generator' ),
			'default' => '#3498db',
			'desc'    => __( 'Starting color for button gradients', 'certificate-generator' ),
		),
		'btn_end'       => array(
			'label'   => __( 'Button Gradient End', 'certificate-generator' ),
			'default' => '#2980b9',
			'desc'    => __( 'Ending color for button gradients', 'certificate-generator' ),
		),
		'hover_effect'  => array(
			'label'   => __( 'Hover Effect Intensity', 'certificate-generator' ),
			'default' => '5',
			'desc'    => __( 'Card lift effect on hover (pixels)', 'certificate-generator' ),
			'type'    => 'range',
		),
		'border_radius' => array(
			'label'   => __( 'Border Radius', 'certificate-generator' ),
			'default' => '12',
			'desc'    => __( 'Rounded corners for cards and buttons (pixels)', 'certificate-generator' ),
			'type'    => 'range',
		),
	);

	echo '<div class="certificate-styling-grid cg-grid cg-mt-s">';

	foreach ( $fields as $field => $config ) {
		$type    = $config['type'] ?? 'color';
		$default = $config['default'];
		$value   = $options[ $field ] ?? $default;

		echo '<div class="style-option cg-set-box">';
		echo '<label class="cg-set-label"><strong>' . esc_html( $config['label'] ) . '</strong></label>';

		if ( $type === 'color' ) {
			echo '<div class="cg-row">';
			echo '<input class="cg-mr" type="color" id="' . esc_html( $field ) . '" name="certificate_generator_settings_email[' . esc_html( $field ) . ']" value="' . esc_attr( $value ) . '">';
			echo '<input class="cg-w80" type="text" value="' . esc_attr( $value ) . '" id="' . esc_html( $field ) . '_text" readonly>';
			echo '</div>';
		} elseif ( $type === 'range' ) {
			echo '<div class="cg-row">';
			echo '<input class="cg-grow" type="range" id="' . esc_html( $field ) . '" name="certificate_generator_settings_email[' . esc_html( $field ) . ']" min="0" max="30" value="' . esc_attr( $value ) . '">';
			echo '<input class="small-text" type="number" value="' . esc_attr( $value ) . '" id="' . esc_html( $field ) . '_number" min="0" max="30">';
			echo '</div>';
		}

		echo '<p class="description">' . esc_html( $config['desc'] ) . '</p>';
		echo '</div>';
	}

	echo '</div>';

	// Add JavaScript to sync color inputs with text fields
	?>
	<script>
	jQuery(document).ready(function($) {
		// Sync color inputs with text fields
		$('input[type="color"]').on('input', function() {
			$('#' + $(this).attr('id') + '_text').val($(this).val());
		});

		// Sync range inputs with number fields
		$('input[type="range"]').on('input', function() {
			$('#' + $(this).attr('id') + '_number').val($(this).val());
		});

		$('input[type="number"]').on('input', function() {
			const id = $(this).attr('id').replace('_number', '');
			$('#' + id).val($(this).val());
		});
	});
	</script>
	<?php
}

// Settings section description
function certificate_generator_settings_section_callback() {
	echo '<p>' . esc_html__( 'Configure the email address and visual styling options used in certificate search results.', 'certificate-generator' ) . '</p>';
}

// Rate limit section description
function certificate_generator_rate_limit_section_callback() {
	echo '<p>' . esc_html__( 'Configure how many emails can be sent per hour and per minute. These limits prevent hitting your SMTP provider\'s rate limits.', 'certificate-generator' ) . '</p>';
}

// Render rate limit fields
function certificate_generator_rate_limits_render() {
	$config = certificate_generator_get_rate_limit_config();
	?>
	<table class="form-table cg-m0">
		<tr>
			<th class="cg-set-kv cg-set-kv--th"><?php esc_html_e( 'Emails per hour', 'certificate-generator' ); ?></th>
			<td class="cg-set-kv">
				<input class="cg-w80" type="number" name="certificate_generator_rate_limits[emails_per_hour]"
						value="<?php echo esc_attr( $config['emails_per_hour'] ); ?>"
						min="10" max="300" step="1">
				<span class="description"><?php esc_html_e( 'Recommended: 80 (Hostinger safe limit)', 'certificate-generator' ); ?></span>
			</td>
		</tr>
		<tr>
			<th class="cg-set-kv cg-set-kv--th"><?php esc_html_e( 'Emails per minute', 'certificate-generator' ); ?></th>
			<td class="cg-set-kv">
				<input class="cg-w80" type="number" name="certificate_generator_rate_limits[emails_per_minute]"
						value="<?php echo esc_attr( $config['emails_per_minute'] ); ?>"
						min="1" max="50" step="1">
				<span class="description"><?php esc_html_e( 'Burst limit to prevent flooding', 'certificate-generator' ); ?></span>
			</td>
		</tr>
		<tr>
			<th class="cg-set-kv cg-set-kv--th"><?php esc_html_e( 'Batch size', 'certificate-generator' ); ?></th>
			<td class="cg-set-kv">
				<input class="cg-w80" type="number" name="certificate_generator_rate_limits[batch_size]"
						value="<?php echo esc_attr( $config['batch_size'] ); ?>"
						min="1" max="50" step="1">
				<span class="description"><?php esc_html_e( 'Number of emails per WP-Cron batch', 'certificate-generator' ); ?></span>
			</td>
		</tr>
		<tr>
			<th class="cg-set-kv cg-set-kv--th"><?php esc_html_e( 'Batch delay (seconds)', 'certificate-generator' ); ?></th>
			<td class="cg-set-kv">
				<input class="cg-w80" type="number" name="certificate_generator_rate_limits[batch_delay]"
						value="<?php echo esc_attr( $config['batch_delay'] ); ?>"
						min="10" max="3600" step="1">
				<span class="description"><?php esc_html_e( 'Seconds to wait between batches', 'certificate-generator' ); ?></span>
			</td>
		</tr>
		<tr>
			<th class="cg-set-kv cg-set-kv--th"><?php esc_html_e( 'Rate limiting enabled', 'certificate-generator' ); ?></th>
			<td class="cg-set-kv">
				<label>
					<input type="checkbox" name="certificate_generator_rate_limits[enabled]" value="1"
							<?php checked( $config['enabled'], true ); ?>>
					<?php esc_html_e( 'Enable rate limiting', 'certificate-generator' ); ?>
				</label>
			</td>
		</tr>
	</table>
	<?php
}

// Sanitize rate limit settings
function certificate_generator_sanitize_rate_limits( $input ) {
	if ( ! is_array( $input ) ) {
		return array();
	}
	return array(
		'emails_per_hour'   => max( 10, min( 300, (int) ( $input['emails_per_hour'] ?? 80 ) ) ),
		'emails_per_minute' => max( 1, min( 50, (int) ( $input['emails_per_minute'] ?? 10 ) ) ),
		'batch_size'        => max( 1, min( 50, (int) ( $input['batch_size'] ?? 10 ) ) ),
		'batch_delay'       => max( 10, min( 3600, (int) ( $input['batch_delay'] ?? 480 ) ) ),
		'enabled'           => ! empty( $input['enabled'] ),
	);
}

// AJAX handler: delete all certificate data (SQL tables first, then CPT posts).
add_action( 'wp_ajax_certificate_generator_delete_all_data', 'certificate_generator_ajax_delete_all_data' );
function certificate_generator_ajax_delete_all_data(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Unauthorized', 'certificate-generator' ), 403 );
	}
	check_ajax_referer( 'certificate_generator_delete_all_data', 'nonce' );

	global $wpdb;
	$deleted = array();

	// 1. SQL custom tables first.
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		foreach ( array( 'students', 'teachers', 'schools' ) as $ent ) {
			$tbl = $tables->get_table( $ent );
			if ( $tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "TRUNCATE TABLE $tbl" );
				$deleted[] = $tbl;
			}
		}
	}

	// Legacy certificate_generator table.
	$cg_table = $wpdb->prefix . 'certificate_generator';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cg_table ) ) === $cg_table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE $cg_table" );
		$deleted[] = $cg_table;
	}

	// 2. CPT posts.
	foreach ( array( 'students', 'teachers', 'schools' ) as $pt ) {
		$ids = get_posts(
			array(
				'post_type'   => $pt,
				'numberposts' => -1,
				'fields'      => 'ids',
				'post_status' => 'any',
			)
		);
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	wp_send_json_success(
		sprintf(
			/* translators: comma-separated table names */
			__( 'All data deleted. Tables cleared: %s', 'certificate-generator' ),
			implode( ', ', $deleted )
		)
	);
}

// AJAX handler for clearing cache
add_action( 'wp_ajax_certificate_generator_clear_cache', 'certificate_generator_clear_cache_ajax' );
function certificate_generator_clear_cache_ajax() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( __( 'Unauthorized user', 'certificate-generator' ), 403 );
	}

	check_ajax_referer( 'certificate_generator_clear_cache', 'nonce' );

	$result = certificate_generator_clear_cache();

	if ( $result ) {
		wp_send_json_success( __( 'Cache and certificate files cleared successfully!', 'certificate-generator' ) );
	} else {
		wp_send_json_error( __( 'Error clearing cache.', 'certificate-generator' ) );
	}
}

// Function to clear cache and certificate files
function certificate_generator_clear_cache() {
	global $wpdb;

	try {
		// ── 1. Delete all plugin transients ───────────────────────────────────
		$transient_patterns = array(
			'_transient_cert_search_%',
			'_transient_timeout_cert_search_%',
			'_transient_cg_unique_%',
			'_transient_timeout_cg_unique_%',
			'_transient_cg_duplicate_template_warning',
			'_transient_timeout_cg_duplicate_template_warning',
			'_transient_certificate_progress_%',
			'_transient_timeout_certificate_progress_%',
			'_transient_cert_batch_%',
			'_transient_timeout_cert_batch_%',
			'_transient_cg_event_date_invalid_%',
			'_transient_timeout_cg_event_date_invalid_%',
		);

		foreach ( $transient_patterns as $pattern ) {
			if ( substr( $pattern, -1 ) === '%' ) {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
						$pattern
					)
				);
			} else {
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name = %s",
						$pattern
					)
				);
			}
		}

		// ── 2. Delete all files in cg_certificates/ ──────────────────────────
		$cg_dir = function_exists( 'certificate_generator_certificates_dir' )
			? certificate_generator_certificates_dir()
			: wp_upload_dir()['basedir'] . '/cg_certificates';

		if ( is_dir( $cg_dir ) ) {
			$all_files = array_merge(
				glob( trailingslashit( $cg_dir ) . '*.pdf' ) ?: array(),
				glob( trailingslashit( $cg_dir ) . '*.zip' ) ?: array()
			);
			foreach ( $all_files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		// ── 3. Legacy cleanup: PDFs/ZIPs scattered in year/month upload dirs ─
		$upload_dir = wp_upload_dir();
		$base       = trailingslashit( $upload_dir['basedir'] );

		$scan_dirs = array( $base );
		foreach ( glob( $base . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR ) ?: array() as $year_dir ) {
			foreach ( glob( trailingslashit( $year_dir ) . '[0-9][0-9]', GLOB_ONLYDIR ) ?: array() as $month_dir ) {
				$scan_dirs[] = trailingslashit( $month_dir );
			}
		}

		foreach ( $scan_dirs as $dir ) {
			$pdfs = array_merge( glob( $dir . 'certificate_*.pdf' ) ?: array(), glob( $dir . '*_certificate.pdf' ) ?: array() );
			foreach ( $pdfs as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}

		$zips = glob( $base . '*certificates*.zip' ) ?: array();
		foreach ( $zips as $zip ) {
			if ( is_file( $zip ) ) {
				wp_delete_file( $zip );
			}
		}

		return true;
	} catch ( Exception $e ) {
		certificate_generator_debug_log( $e->getMessage() );
		return false;
	}
}

// Render settings page
function certificate_generator_settings_page() {
	global $wpdb;
	// Add CSS for API key styling
	?>
	<style>
		.api-key-container {
			background: #f9f9f9;
			padding: 15px;
			border: 1px solid #ddd;
			border-radius: 4px;
			margin-bottom: 15px;
		}
		#certificate_generator_api_key_display {
			background: #fff;
			padding: 8px;
			font-size: 14px;
			line-height: 1.4;
		}
		.api-key-actions {
			margin-top: 10px;
		}
		.api-key-container, .api-key-actions {
			display: none;
		}
		.api-key-container.visible, .api-key-actions.visible {
			display: block;
		}
	</style>
	<?php
	// Get and validate current tab
	$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general';
	// The Pro add-on adds its tabs (API, License) here and renders them on certificate_generator_settings_tab_{key}.
	$cg_tabs = apply_filters(
		'certificate_generator_settings_tabs',
		array(
			'general'        => __( 'General', 'certificate-generator' ),
			'templates'      => __( 'Email Templates', 'certificate-generator' ),
			'scheduling'     => __( 'Scheduling', 'certificate-generator' ),
			'tools'          => __( 'Tools', 'certificate-generator' ),
			'shortcode_text' => __( 'Shortcode Text', 'certificate-generator' ),
		)
	);

	if ( ! isset( $cg_tabs[ $active_tab ] ) ) {
		$active_tab = 'general';
	}
	?>
	<div class="wrap cg-settings">
		<?php
		certificate_generator_ui_page_header(
			__( 'Certificate Generator Settings', 'certificate-generator' ),
			__( 'Email delivery and templates, scheduling, maintenance tools and shortcode text.', 'certificate-generator' )
		);
		certificate_generator_ui_tabs(
			$cg_tabs,
			$active_tab,
			admin_url( 'options-general.php?page=certificate_generator_settings' )
		);
		?>

		<?php
		// Debug information for tab content
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			echo '<!-- Active Tab: ' . esc_html( $active_tab ) . ' -->';
		}
		?>

		<?php if ( $active_tab == 'general' ) : ?>
			<!-- ── GENERAL TAB: Contact Email + Card Styling only ── -->
			<?php certificate_generator_ui_card_open( __( 'General Settings', 'certificate-generator' ), array( 'icon' => 'admin-settings' ) ); ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'certificate_generator_settings' ); ?>
				<?php certificate_generator_settings_section_callback(); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Contact Email', 'certificate-generator' ); ?></label>
						</th>
						<td><?php certificate_generator_email_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Card Styling', 'certificate-generator' ); ?></th>
						<td><?php certificate_generator_card_styles_render(); ?></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Data retention setting — saved independently via AJAX to avoid coupling with options.php -->
			<?php certificate_generator_ui_card_open( __( 'Data Management', 'certificate-generator' ), array( 'icon' => 'database' ) ); ?>
			<p><?php esc_html_e( 'Controls what happens when you delete / uninstall this plugin.', 'certificate-generator' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Keep Data on Uninstall', 'certificate-generator' ); ?></th>
					<td>
						<label>
							<input type="checkbox" id="certificate_generator_keep_data_on_uninstall"
								<?php checked( get_option( 'certificate_generator_keep_data_on_uninstall', '1' ), '1' ); ?>>
							<?php esc_html_e( 'Keep all students, templates, and email logs when the plugin is deleted', 'certificate-generator' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When checked, uninstalling the plugin leaves your data intact so it reappears after reinstalling. When unchecked, all plugin data and tables are permanently deleted on uninstall.', 'certificate-generator' ); ?>
						</p>
						<p><button type="button" id="cg-save-keep-data" class="button button-secondary cg-mt-s">
							<?php esc_html_e( 'Save Preference', 'certificate-generator' ); ?>
						</button>
						<span class="cg-inline-msg" id="cg-keep-data-msg" aria-live="polite"></span></p>
					</td>
				</tr>
			</table>
			<script>
			(function($){
				$('#cg-save-keep-data').on('click', function(){
					var $btn = $(this), $msg = $('#cg-keep-data-msg');
					CGUI.busy($btn[0], true);
					$msg.text('');
					$.post(ajaxurl, {
						action : 'certificate_generator_set_keep_data',
						keep   : $('#certificate_generator_keep_data_on_uninstall').is(':checked') ? '1' : '0',
						nonce  : <?php echo wp_json_encode( wp_create_nonce( 'certificate_generator_set_keep_data' ) ); ?>
					}).done(function(r){
						$msg.text(r.success
							? '<?php echo esc_js( __( 'Saved.', 'certificate-generator' ) ); ?>'
							: '<?php echo esc_js( __( 'Save failed.', 'certificate-generator' ) ); ?>'
						).show();
					}).fail(function(){
						$msg.text('<?php echo esc_js( __( 'Error.', 'certificate-generator' ) ); ?>').show();
					}).always(function(){ CGUI.busy($btn[0], false); });
				});
			})(jQuery);
			</script>

			<?php certificate_generator_ui_card_close(); ?>
			<?php certificate_generator_ui_card_open( __( 'Features', 'certificate-generator' ), array( 'icon' => 'admin-plugins' ) ); ?>
			<p><?php esc_html_e( 'Turn optional feature areas on or off. A toggle here overrides a matching wp-config.php constant, if one is set. Turning a feature off only stops new activity — existing data is not deleted.', 'certificate-generator' ); ?></p>
			<?php
			$certificate_generator_feature_flags = certificate_generator_feature_flags();
			$cg_toggle_overrides = get_option( 'certificate_generator_feature_toggles', array() );
			?>
			<table class="form-table" role="presentation">
				<?php foreach ( $certificate_generator_feature_flags as $cg_flag_name => $cg_flag_label ) : ?>
					<?php
					$cg_flag_checked   = array_key_exists( $cg_flag_name, $cg_toggle_overrides )
						? (bool) $cg_toggle_overrides[ $cg_flag_name ]
						: ( defined( $cg_flag_name ) && (bool) constant( $cg_flag_name ) );
					$cg_flag_in_config = defined( $cg_flag_name );
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $cg_flag_label ); ?></th>
						<td>
							<label>
								<input type="checkbox" class="cg-feature-toggle" data-flag="<?php echo esc_attr( $cg_flag_name ); ?>" <?php checked( $cg_flag_checked ); ?>>
								<?php esc_html_e( 'Enabled', 'certificate-generator' ); ?>
							</label>
							<?php if ( $cg_flag_in_config ) : ?>
								<p class="description"><?php esc_html_e( 'Also set via a wp-config.php constant — this toggle takes precedence.', 'certificate-generator' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p><button type="button" id="cg-save-feature-toggles" class="button button-secondary"><?php esc_html_e( 'Save Feature Toggles', 'certificate-generator' ); ?></button>
			<span class="cg-inline-msg" id="cg-feature-toggles-msg" aria-live="polite"></span></p>
			<?php certificate_generator_ui_card_close(); ?>
			<script>
			(function($){
				$('#cg-save-feature-toggles').on('click', function(){
					var $btn = $(this), $msg = $('#cg-feature-toggles-msg');
					var flags = {};
					$('.cg-feature-toggle').each(function(){
						flags[$(this).data('flag')] = $(this).is(':checked') ? '1' : '0';
					});
					CGUI.busy($btn[0], true);
					$msg.text('');
					$.post(ajaxurl, {
						action : 'certificate_generator_save_feature_toggles',
						flags  : flags,
						nonce  : <?php echo wp_json_encode( wp_create_nonce( 'certificate_generator_save_feature_toggles' ) ); ?>
					}).done(function(r){
						$msg.text(r.success
							? '<?php echo esc_js( __( 'Saved.', 'certificate-generator' ) ); ?>'
							: '<?php echo esc_js( __( 'Save failed.', 'certificate-generator' ) ); ?>'
						).show();
					}).fail(function(){
						$msg.text('<?php echo esc_js( __( 'Error.', 'certificate-generator' ) ); ?>').show();
					}).always(function(){ CGUI.busy($btn[0], false); });
				});
			})(jQuery);
			</script>

		<?php elseif ( $active_tab == 'templates' ) : ?>
			<!-- ── TEMPLATES TAB: Email settings + Rate limits + SMTP status ── -->

			<div class="cg-callout">
				<strong><?php esc_html_e( 'Template Priority', 'certificate-generator' ); ?></strong>
				<span class="cg-ml cg-muted">
					<?php esc_html_e( 'Per-Type Template (Students / Teachers / Schools)', 'certificate-generator' ); ?>
					<span class="cg-sep-dot">→</span>
					<?php esc_html_e( 'Global Fallback Template', 'certificate-generator' ); ?>
					<span class="cg-sep-dot">→</span>
					<?php esc_html_e( '(empty — send blocked)', 'certificate-generator' ); ?>
				</span>
				<p class="cg-hint">
					<?php esc_html_e( 'Fill in per-type templates below for different emails per recipient type. Leave a per-type field blank to fall back to the Global Fallback Template.', 'certificate-generator' ); ?>
				</p>
			</div>

			<?php certificate_generator_ui_card_open( __( 'Per-Type Email Templates', 'certificate-generator' ), array( 'icon' => 'email', 'actions_html' => certificate_generator_ui_badge( __( 'Priority 1 — Overrides Global', 'certificate-generator' ), 'good' ) ) ); ?>
			<form action="options.php" method="post">
				<?php settings_fields( 'certificate_generator_settings' ); ?>
				<?php certificate_generator_email_templates_section_callback(); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-Send Emails', 'certificate-generator' ); ?></th>
						<td><?php certificate_generator_auto_send_render(); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email Logo', 'certificate-generator' ); ?></th>
						<td><?php certificate_generator_email_logo_render(); ?></td>
					</tr>
				</table>

				<?php
				foreach ( array( 'students', 'teachers', 'schools' ) as $_pt ) {
					certificate_generator_email_template_render( array( 'post_type' => $_pt ) );
				}
				?>

				<h3 class="cg-mt-l"><?php esc_html_e( 'Email Rate Limits', 'certificate-generator' ); ?></h3>
				<?php certificate_generator_rate_limit_section_callback(); ?>
				<div class="cg-set-box cg-mt-s">
					<?php certificate_generator_rate_limits_render(); ?>
				</div>

				<?php submit_button(); ?>
			</form>
			<?php certificate_generator_ui_card_close(); ?>

				<?php
				// ── Handle email delivery settings POST ──────────────────────────────
				$cg_email_msg    = '';
				$cg_email_errors = array();
				if (
				'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
				&& ! empty( $_POST['cg_email_settings_nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_email_settings_nonce'] ) ), 'cg_email_settings_save' )
				&& current_user_can( 'manage_options' )
				) {
					if ( class_exists( '\CertificateGenerator\Services\SettingsService' ) ) {
						$result          = \CertificateGenerator\Services\SettingsService::save_email_settings( wp_unslash( $_POST ) );
						$cg_email_errors = $result['errors'] ?? array();
						if ( empty( $cg_email_errors ) ) {
							$cg_email_msg = __( 'Email settings saved.', 'certificate-generator' );
						}
					}
				}

				// ── Handle test email POST ───────────────────────────────────────────
				if (
				'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
				&& ! empty( $_POST['cg_email_test_nonce'] )
				&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_email_test_nonce'] ) ), 'cg_email_test_send' )
				) {
					$cg_test_to = sanitize_email( wp_unslash( $_POST['cg_test_email_to'] ?? get_bloginfo( 'admin_email' ) ) );
					if ( ! is_email( $cg_test_to ) ) {
						$cg_email_errors[] = __( 'Invalid test recipient email address.', 'certificate-generator' );
					} elseif ( class_exists( '\CertificateGenerator\Email\Mailer' ) ) {
						$cg_mailer = \CertificateGenerator\Email\Mailer::make();
						$cg_ok     = $cg_mailer->send_html(
							$cg_test_to,
							'Certificate Generator — Test Email',
							'<p>This is a test email from <strong>Certificate Generator</strong>. Your mail transport is working correctly.</p>'
						);
						if ( $cg_ok ) {
							/* translators: %s: recipient email address */
							$cg_email_msg = sprintf( __( 'Test email sent to <strong>%s</strong>.', 'certificate-generator' ), esc_html( $cg_test_to ) );
						} else {
							$cg_email_errors[] = __( 'Test email failed. Check transport settings and server mail logs.', 'certificate-generator' );
						}
					} else {
						$cg_email_errors[] = __( 'Mailer class not available.', 'certificate-generator' );
					}
				}

				// ── Read current values ──────────────────────────────────────────────
				$cg_ss         = class_exists( '\CertificateGenerator\Services\SettingsService' );
				$cg_transport  = $cg_ss ? \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_email_transport' ) : get_option( 'certificate_generator_email_transport', 'wp_mail' );
				$cg_from_name  = $cg_ss ? \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_email_from_name' ) : get_option( 'certificate_generator_email_from_name', get_bloginfo( 'name' ) );
				$cg_from_email = $cg_ss ? \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_email_from_email' ) : get_option( 'certificate_generator_email_from_email', get_bloginfo( 'admin_email' ) );
				$cg_subject    = $cg_ss ? \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_email_subject' ) : get_option( 'certificate_generator_email_subject', '' );
				$cg_body       = $cg_ss ? \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_email_body' ) : get_option( 'certificate_generator_email_body', '' );
				$cg_smtp_cfg   = $cg_ss ? \CertificateGenerator\Services\SettingsService::get_smtp_config() : array();
				$certificate_generator_smtp_host  = $cg_smtp_cfg['host'] ?? '';
				$certificate_generator_smtp_port  = $cg_smtp_cfg['port'] ?? '587';
				$cg_smtp_user  = $cg_smtp_cfg['username'] ?? '';
				$cg_smtp_enc   = $cg_smtp_cfg['encryption'] ?? 'tls';
				?>

				<?php if ( $cg_email_msg ) : ?>
				<?php certificate_generator_ui_notice( 'success', $cg_email_msg ); ?>
			<?php endif; ?>
				<?php foreach ( $cg_email_errors as $cg_err ) : ?>
				<?php certificate_generator_ui_notice( 'error', esc_html( $cg_err ) ); ?>
			<?php endforeach; ?>

			<?php certificate_generator_ui_card_open( __( 'Email Delivery Configuration', 'certificate-generator' ), array( 'icon' => 'email-alt' ) ); ?>

			<form method="post">
				<?php wp_nonce_field( 'cg_email_settings_save', 'cg_email_settings_nonce' ); ?>

				<h3><?php esc_html_e( 'Transport', 'certificate-generator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="certificate_generator_email_transport"><?php esc_html_e( 'Mail Transport', 'certificate-generator' ); ?></label></th>
						<td>
							<select id="certificate_generator_email_transport" name="certificate_generator_email_transport"
									onchange="
										document.getElementById('cg-smtp-settings').style.display=this.value==='smtp'?'':'none';
										document.getElementById('cg-sender-settings').style.display=this.value==='sender'?'':'none';
										document.getElementById('cg-mandrill-settings').style.display=this.value==='mandrill'?'':'none';
									">
								<option value="wp_mail"  <?php selected( $cg_transport, 'wp_mail' ); ?>><?php esc_html_e( 'wp_mail (server default)', 'certificate-generator' ); ?></option>
								<option value="smtp"     <?php selected( $cg_transport, 'smtp' ); ?>><?php esc_html_e( 'SMTP (custom)', 'certificate-generator' ); ?></option>
								<option value="sender"   <?php selected( $cg_transport, 'sender' ); ?>><?php esc_html_e( 'Sender.net (API)', 'certificate-generator' ); ?></option>
								<option value="mandrill" <?php selected( $cg_transport, 'mandrill' ); ?>><?php esc_html_e( 'Mailchimp Transactional / Mandrill (API)', 'certificate-generator' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Use wp_mail if your host handles outbound mail. Use SMTP to configure Gmail, SendGrid, Mailgun, etc. Sender.net and Mailchimp Transactional send via their API directly (no SMTP setup needed) and require an API key from that provider.', 'certificate-generator' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Sender', 'certificate-generator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="certificate_generator_email_from_name"><?php esc_html_e( 'From Name', 'certificate-generator' ); ?></label></th>
						<td><input type="text" id="certificate_generator_email_from_name" name="certificate_generator_email_from_name" value="<?php echo esc_attr( $cg_from_name ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="certificate_generator_email_from_email"><?php esc_html_e( 'From Email', 'certificate-generator' ); ?></label></th>
						<td><input type="email" id="certificate_generator_email_from_email" name="certificate_generator_email_from_email" value="<?php echo esc_attr( $cg_from_email ); ?>" class="regular-text"></td>
					</tr>
				</table>

				<h3>
					<?php esc_html_e( 'Global Fallback Template', 'certificate-generator' ); ?>
					<span class="cg-badge cg-badge--warn cg-ml">
						<?php esc_html_e( 'Priority 2 — Used when per-type is blank', 'certificate-generator' ); ?>
					</span>
				</h3>
				<p class="description cg-mb"><?php esc_html_e( 'Placeholders: {name} {certificate_title} {result_link} {serial_number} {expires_at} {certificate_count} {site_name}. WordPress shortcodes supported.', 'certificate-generator' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label for="certificate_generator_email_subject"><?php esc_html_e( 'Subject', 'certificate-generator' ); ?></label></th>
						<td><input type="text" id="certificate_generator_email_subject" name="certificate_generator_email_subject" value="<?php echo esc_attr( $cg_subject ); ?>" class="large-text"></td>
					</tr>
					<tr>
						<th><label for="certificate_generator_email_body"><?php esc_html_e( 'Body (HTML)', 'certificate-generator' ); ?></label></th>
						<td><textarea id="certificate_generator_email_body" name="certificate_generator_email_body" rows="8" class="large-text"><?php echo esc_textarea( $cg_body ); ?></textarea></td>
					</tr>
				</table>

				<div id="cg-smtp-settings" style="<?php echo esc_attr( $cg_transport ) === 'smtp' ? '' : 'display:none'; ?>">
					<h3><?php esc_html_e( 'SMTP Configuration', 'certificate-generator' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><label for="certificate_generator_smtp_host"><?php esc_html_e( 'SMTP Host', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="certificate_generator_smtp_host" name="certificate_generator_smtp_host" value="<?php echo esc_attr( $certificate_generator_smtp_host ); ?>" class="regular-text" placeholder="smtp.gmail.com"></td>
						</tr>
						<tr>
							<th><label for="certificate_generator_smtp_port"><?php esc_html_e( 'Port', 'certificate-generator' ); ?></label></th>
							<td><input type="number" id="certificate_generator_smtp_port" name="certificate_generator_smtp_port" value="<?php echo esc_attr( $certificate_generator_smtp_port ); ?>" class="small-text" min="1" max="65535"></td>
						</tr>
						<tr>
							<th><label for="certificate_generator_smtp_encryption"><?php esc_html_e( 'Encryption', 'certificate-generator' ); ?></label></th>
							<td>
								<select id="certificate_generator_smtp_encryption" name="certificate_generator_smtp_encryption">
									<option value="tls"  <?php selected( $cg_smtp_enc, 'tls' ); ?>><?php esc_html_e( 'TLS (STARTTLS) — port 587', 'certificate-generator' ); ?></option>
									<option value="ssl"  <?php selected( $cg_smtp_enc, 'ssl' ); ?>><?php esc_html_e( 'SSL — port 465', 'certificate-generator' ); ?></option>
									<option value="none" <?php selected( $cg_smtp_enc, 'none' ); ?>><?php esc_html_e( 'None — port 25', 'certificate-generator' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="certificate_generator_smtp_username"><?php esc_html_e( 'Username', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="certificate_generator_smtp_username" name="certificate_generator_smtp_username" value="<?php echo esc_attr( $cg_smtp_user ); ?>" class="regular-text" autocomplete="off"></td>
						</tr>
						<tr>
							<th><label for="certificate_generator_smtp_password"><?php esc_html_e( 'Password', 'certificate-generator' ); ?></label></th>
							<td>
								<input type="password" id="certificate_generator_smtp_password" name="certificate_generator_smtp_password" value="" class="regular-text" autocomplete="new-password"
										placeholder="<?php esc_attr_e( 'Leave blank to keep current password', 'certificate-generator' ); ?>">
								<?php if ( $cg_ss && \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_smtp_password' ) ) : ?>
									<p class="description"><?php esc_html_e( 'Password saved. Enter a new one to replace it.', 'certificate-generator' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<div id="cg-sender-settings" style="<?php echo esc_attr( $cg_transport ) === 'sender' ? '' : 'display:none'; ?>">
					<h3><?php esc_html_e( 'Sender.net Configuration', 'certificate-generator' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><label for="certificate_generator_sender_api_key"><?php esc_html_e( 'API Key', 'certificate-generator' ); ?></label></th>
							<td>
								<input type="password" id="certificate_generator_sender_api_key" name="certificate_generator_sender_api_key" value="" class="regular-text" autocomplete="off"
										placeholder="<?php esc_attr_e( 'Leave blank to keep current key', 'certificate-generator' ); ?>">
								<?php if ( $cg_ss && \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_sender_api_key' ) ) : ?>
									<p class="description"><?php esc_html_e( 'API key saved. Enter a new one to replace it.', 'certificate-generator' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<div id="cg-mandrill-settings" style="<?php echo esc_attr( $cg_transport ) === 'mandrill' ? '' : 'display:none'; ?>">
					<h3><?php esc_html_e( 'Mailchimp Transactional (Mandrill) Configuration', 'certificate-generator' ); ?></h3>
					<table class="form-table">
						<tr>
							<th><label for="certificate_generator_mandrill_api_key"><?php esc_html_e( 'API Key', 'certificate-generator' ); ?></label></th>
							<td>
								<input type="password" id="certificate_generator_mandrill_api_key" name="certificate_generator_mandrill_api_key" value="" class="regular-text" autocomplete="off"
										placeholder="<?php esc_attr_e( 'Leave blank to keep current key', 'certificate-generator' ); ?>">
								<?php if ( $cg_ss && \CertificateGenerator\Services\SettingsService::get( 'certificate_generator_mandrill_api_key' ) ) : ?>
									<p class="description"><?php esc_html_e( 'API key saved. Enter a new one to replace it.', 'certificate-generator' ); ?></p>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Requires a Mailchimp Transactional (formerly Mandrill) account — a regular Mailchimp marketing API key will not work here.', 'certificate-generator' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( esc_attr__( 'Save Email Settings', 'certificate-generator' ) ); ?>
			</form>
			<?php certificate_generator_ui_card_close(); ?>

			<?php certificate_generator_ui_card_open( __( 'Send Test Email', 'certificate-generator' ), array( 'icon' => 'email' ) ); ?>
			<form class="cg-row cg-row--end" method="post" data-cg-busy>
				<?php wp_nonce_field( 'cg_email_test_send', 'cg_email_test_nonce' ); ?>
				<div>
					<label class="cg-set-label" for="cg_test_email_to"><?php esc_html_e( 'Recipient', 'certificate-generator' ); ?></label>
					<input type="email" id="cg_test_email_to" name="cg_test_email_to"
							value="<?php echo esc_attr( get_bloginfo( 'admin_email' ) ); ?>" class="regular-text" required>
				</div>
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Send Test', 'certificate-generator' ); ?></button>
			</form>
			<?php certificate_generator_ui_card_close(); ?>

			<?php
			// ── certificate_type → Email Template mapping ───────────────────────
			$cg_et_table  = class_exists( '\CertificateGenerator\Database\CustomTables' )
				? \CertificateGenerator\Database\CustomTables::instance()->get_table( 'email_templates' )
				: '';
			$cg_tpl_table = class_exists( '\CertificateGenerator\Database\CustomTables' )
				? \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' )
				: '';
			$cg_et_msg      = '';
			$cg_et_errors   = array();
			$cg_et_edit_row = null;

			if ( $cg_et_table && 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['cg_et_save'] )
				&& check_admin_referer( 'cg_et_save', 'cg_et_nonce' )
			) {
				$cg_et_id   = absint( $_POST['cg_et_id'] ?? 0 );
				$cg_et_name = sanitize_text_field( wp_unslash( $_POST['cg_et_name'] ?? '' ) );
				$cg_et_type = sanitize_text_field( wp_unslash( $_POST['cg_et_certificate_type'] ?? '' ) );

				if ( ! $cg_et_name ) {
					$cg_et_errors[] = __( 'Template name is required.', 'certificate-generator' );
				}
				if ( ! $cg_et_type ) {
					$cg_et_errors[] = __( 'Certificate type is required.', 'certificate-generator' );
				}

				if ( empty( $cg_et_errors ) ) {
					$cg_et_data = array(
						'name'               => $cg_et_name,
						'certificate_type'   => $cg_et_type,
						'subject'            => sanitize_text_field( wp_unslash( $_POST['cg_et_subject'] ?? '' ) ),
						'title'              => sanitize_text_field( wp_unslash( $_POST['cg_et_title'] ?? '' ) ),
						'message'            => wp_kses_post( wp_unslash( $_POST['cg_et_message'] ?? '' ) ),
						'attach_certificate' => ! empty( $_POST['cg_et_attach'] ) ? 1 : 0,
						'reply_to'           => sanitize_text_field( wp_unslash( $_POST['cg_et_reply_to'] ?? '' ) ),
						'cc'                 => sanitize_text_field( wp_unslash( $_POST['cg_et_cc'] ?? '' ) ),
						'bcc'                => sanitize_text_field( wp_unslash( $_POST['cg_et_bcc'] ?? '' ) ),
						'updated_at'         => current_time( 'mysql' ),
					);

					if ( $cg_et_id ) {
						$wpdb->update( $cg_et_table, $cg_et_data, array( 'id' => $cg_et_id ) );
						$cg_et_msg = __( 'Email template updated.', 'certificate-generator' );
					} else {
						$cg_et_existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $cg_et_table WHERE certificate_type = %s", $cg_et_type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( $cg_et_existing_id ) {
							/* translators: %s: certificate type */
							$cg_et_errors[] = sprintf( __( 'A template is already mapped to certificate type "%s". Edit it instead.', 'certificate-generator' ), $cg_et_type );
						} else {
							$cg_et_data['created_at'] = current_time( 'mysql' );
							$wpdb->insert( $cg_et_table, $cg_et_data );
							$cg_et_msg = __( 'Email template created.', 'certificate-generator' );
						}
					}
				}
			}

			if ( $cg_et_table && isset( $_GET['cg_et_delete'] ) && check_admin_referer( 'cg_et_delete' ) ) {
				$wpdb->delete( $cg_et_table, array( 'id' => absint( $_GET['cg_et_delete'] ) ) );
				$cg_et_msg = __( 'Email template mapping removed.', 'certificate-generator' );
			}

			if ( $cg_et_table && isset( $_GET['cg_et_edit'] ) ) {
				$cg_et_edit_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $cg_et_table WHERE id = %d", absint( $_GET['cg_et_edit'] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}

			$cg_et_rows  = $cg_et_table ? $wpdb->get_results( "SELECT * FROM $cg_et_table ORDER BY certificate_type", ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cg_ct_types = $cg_tpl_table ? $wpdb->get_col( "SELECT DISTINCT certificate_type FROM `{$cg_tpl_table}` WHERE certificate_type != '' ORDER BY certificate_type" ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			?>

			<?php certificate_generator_ui_card_open( __( 'Email Templates by Certificate Type', 'certificate-generator' ), array( 'icon' => 'email-alt2', 'actions_html' => certificate_generator_ui_badge( __( 'Priority 0 — Overrides Per-Type', 'certificate-generator' ), 'info' ) ) ); ?>
			<p class="cg-hint"><?php esc_html_e( 'Map a distinct email (subject/body/attachment) to a specific certificate type — e.g. a different email for "Gold Trophy" vs "Participant". A certificate type with no mapping here falls back to the per-type templates above.', 'certificate-generator' ); ?></p>

			<?php foreach ( $cg_et_errors as $cg_et_err ) : ?>
				<?php certificate_generator_ui_notice( 'error', esc_html( $cg_et_err ), false, true ); ?>
			<?php endforeach; ?>
			<?php if ( $cg_et_msg ) : ?>
				<?php certificate_generator_ui_notice( 'success', esc_html( $cg_et_msg ), true, true ); ?>
			<?php endif; ?>

			<div class="cg-table-wrap">
			<table class="widefat striped cg-table">
				<thead><tr><th><?php esc_html_e( 'Name', 'certificate-generator' ); ?></th><th><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></th><th><?php esc_html_e( 'Subject', 'certificate-generator' ); ?></th><th></th></tr></thead>
				<tbody>
					<?php if ( empty( $cg_et_rows ) ) : ?>
						<?php echo certificate_generator_ui_empty_row( 4, __( 'No certificate-type email templates yet. Add one below to give a certificate type its own email.', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
					<?php endif; ?>
					<?php foreach ( $cg_et_rows as $cg_et_row ) : ?>
						<tr>
							<td><?php echo esc_html( $cg_et_row['name'] ); ?></td>
							<td><code><?php echo esc_html( $cg_et_row['certificate_type'] ); ?></code></td>
							<td><?php echo esc_html( $cg_et_row['subject'] ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'templates', 'cg_et_edit' => $cg_et_row['id'] ) ) ); ?>"><?php esc_html_e( 'Edit', 'certificate-generator' ); ?></a>
								|
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'templates', 'cg_et_delete' => $cg_et_row['id'] ) ), 'cg_et_delete' ) ); ?>"
									class="cg-link-delete" data-cg-danger data-cg-confirm-label="<?php esc_attr_e( 'Delete', 'certificate-generator' ); ?>" data-cg-confirm="<?php esc_attr_e( 'Remove this email template mapping? That certificate type falls back to its per-type email template.', 'certificate-generator' ); ?>"><?php esc_html_e( 'Delete', 'certificate-generator' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>

			<div class="cg-narrow cg-mt-l">
				<h3><?php echo $cg_et_edit_row ? esc_html__( 'Edit Email Template', 'certificate-generator' ) : esc_html__( 'Add Email Template', 'certificate-generator' ); ?></h3>
				<form method="post">
					<?php wp_nonce_field( 'cg_et_save', 'cg_et_nonce' ); ?>
					<input type="hidden" name="cg_et_id" value="<?php echo esc_attr( $cg_et_edit_row['id'] ?? 0 ); ?>">
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="cg_et_name"><?php esc_html_e( 'Template Name', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="cg_et_name" name="cg_et_name" class="regular-text" required value="<?php echo esc_attr( $cg_et_edit_row['name'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th><label for="cg_et_certificate_type"><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></label></th>
							<td>
								<input type="text" id="cg_et_certificate_type" name="cg_et_certificate_type" class="regular-text" list="cg_et_type_list" required
									value="<?php echo esc_attr( $cg_et_edit_row['certificate_type'] ?? '' ); ?>">
								<datalist id="cg_et_type_list">
									<?php foreach ( $cg_ct_types as $cg_ct_type ) : ?>
										<option value="<?php echo esc_attr( $cg_ct_type ); ?>">
									<?php endforeach; ?>
								</datalist>
								<p class="description"><?php esc_html_e( 'Must match a certificate_type used on your PDF templates exactly.', 'certificate-generator' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="cg_et_subject"><?php esc_html_e( 'Subject', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="cg_et_subject" name="cg_et_subject" class="large-text" value="<?php echo esc_attr( $cg_et_edit_row['subject'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th><label for="cg_et_title"><?php esc_html_e( 'Email Title', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="cg_et_title" name="cg_et_title" class="large-text" value="<?php echo esc_attr( $cg_et_edit_row['title'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th><label for="cg_et_message"><?php esc_html_e( 'Message', 'certificate-generator' ); ?></label></th>
							<td><?php wp_editor( $cg_et_edit_row['message'] ?? '', 'cg_et_message', array( 'textarea_rows' => 8 ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Attach Certificate', 'certificate-generator' ); ?></th>
							<td><label><input type="checkbox" name="cg_et_attach" <?php checked( ! isset( $cg_et_edit_row['attach_certificate'] ) || (int) $cg_et_edit_row['attach_certificate'] === 1 ); ?>> <?php esc_html_e( 'Attach the PDF certificate to this email', 'certificate-generator' ); ?></label></td>
						</tr>
						<tr>
							<th><label for="cg_et_reply_to"><?php esc_html_e( 'Reply-To', 'certificate-generator' ); ?></label></th>
							<td><input type="email" id="cg_et_reply_to" name="cg_et_reply_to" class="regular-text" value="<?php echo esc_attr( $cg_et_edit_row['reply_to'] ?? '' ); ?>"></td>
						</tr>
						<tr>
							<th><label for="cg_et_cc"><?php esc_html_e( 'CC', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="cg_et_cc" name="cg_et_cc" class="regular-text" value="<?php echo esc_attr( $cg_et_edit_row['cc'] ?? '' ); ?>" placeholder="a@example.com, b@example.com"></td>
						</tr>
						<tr>
							<th><label for="cg_et_bcc"><?php esc_html_e( 'BCC', 'certificate-generator' ); ?></label></th>
							<td><input type="text" id="cg_et_bcc" name="cg_et_bcc" class="regular-text" value="<?php echo esc_attr( $cg_et_edit_row['bcc'] ?? '' ); ?>"></td>
						</tr>
					</table>
					<?php submit_button( $cg_et_edit_row ? __( 'Update Template', 'certificate-generator' ) : __( 'Add Template', 'certificate-generator' ), 'primary', 'cg_et_save' ); ?>
					<?php if ( $cg_et_edit_row ) : ?>
						<a href="<?php echo esc_url( remove_query_arg( 'cg_et_edit' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'certificate-generator' ); ?></a>
					<?php endif; ?>
				</form>
			</div>
			<?php certificate_generator_ui_card_close(); ?>

			<?php /* ── Shortcode & Placeholder Reference Panel ── */ ?>
			<style>
				.cg-ref-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; margin-top: 12px; }
				.cg-ref-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 16px 18px; }
				.cg-ref-card h3 { margin: 0 0 10px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: #444; display: flex; align-items: center; gap: 6px; }
				.cg-ref-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
				.cg-ref-table td { padding: 4px 6px; border-bottom: 1px solid #f3f3f3; vertical-align: top; }
				.cg-ref-table tr:last-child td { border-bottom: none; }
				.cg-ref-table td:first-child { white-space: nowrap; }
				.cg-tag { display: inline-block; font-family: monospace; font-size: 12px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 3px; padding: 1px 6px; cursor: pointer; transition: background .15s; }
				.cg-tag:hover { background: #e5e7eb; }
				.cg-tag.blue   { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
				.cg-tag.green  { background: #f0fdf4; border-color: #bbf7d0; color: #15803d; }
				.cg-tag.purple { background: #f5f3ff; border-color: #ddd6fe; color: #6d28d9; }
				.cg-tag.orange { background: #fff7ed; border-color: #fed7aa; color: #c2410c; }
				.cg-ref-note  { font-size: 11.5px; color: #777; margin-top: 10px; padding-top: 8px; border-top: 1px solid #f0f0f0; }
				.cg-ref-note a { color: #2271b1; text-decoration: none; }
				.cg-copy-notice { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%); background: #333; color: #fff; padding: 8px 18px; border-radius: 4px; font-size: 13px; pointer-events: none; opacity: 0; transition: opacity .2s; z-index: 9999; }
				.cg-copy-notice.show { opacity: 1; }
			</style>
			<?php certificate_generator_ui_card_open( __( 'Available Shortcodes & Placeholders', 'certificate-generator' ), array( 'icon' => 'tag', 'class' => 'cg-ref-wrap' ) ); ?>
				<p class="cg-hint">
					<?php esc_html_e( 'Click any tag to copy it. Paste directly into Email Subject or Email Body fields above.', 'certificate-generator' ); ?>
				</p>
				<div class="cg-ref-grid">

					<?php /* Card 1: Built-in CG Placeholders */ ?>
					<div class="cg-ref-card">
						<h3><?php esc_html_e( 'Certificate Generator', 'certificate-generator' ); ?> <span class="cg-badge cg-badge--info">Built-in</span></h3>
						<table class="cg-ref-table">
							<tr><td><span class="cg-tag blue" onclick="cgCopyTag(this)">{name}</span></td><td><?php esc_html_e( 'Recipient name', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag blue" onclick="cgCopyTag(this)">{certificate_title}</span></td><td><?php esc_html_e( 'Certificate title / type', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag blue" onclick="cgCopyTag(this)">{zip_link}</span></td><td><?php esc_html_e( 'ZIP download URL (bulk emails)', 'certificate-generator' ); ?></td></tr>
						</table>
						<p class="cg-ref-note"><?php esc_html_e( 'Always available. Replaced before shortcode processing.', 'certificate-generator' ); ?></p>
					</div>

					<?php /* Card 2: Site / WP Dynamic Tags Placeholders */ ?>
					<div class="cg-ref-card">
						<h3><?php esc_html_e( 'Site &amp; User', 'certificate-generator' ); ?> <span class="cg-badge cg-badge--good">{placeholders}</span></h3>
						<table class="cg-ref-table">
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{site_name}</span></td><td><?php esc_html_e( 'Site title', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{site_url}</span></td><td><?php esc_html_e( 'Site URL', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{admin_email}</span></td><td><?php esc_html_e( 'Admin email address', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{current_date}</span></td><td><?php esc_html_e( 'Today\'s date', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{current_year}</span></td><td><?php esc_html_e( 'Current year', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{current_month}</span></td><td><?php esc_html_e( 'Current month name', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{user_name}</span></td><td><?php esc_html_e( 'Logged-in user login', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{user_display_name}</span></td><td><?php esc_html_e( 'Logged-in user display name', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">{user_email}</span></td><td><?php esc_html_e( 'Logged-in user email', 'certificate-generator' ); ?></td></tr>
						</table>
						<p class="cg-ref-note">
							<?php
							if ( class_exists( 'WP_Dynamic_Tags_Plugin' ) ) {
								esc_html_e( '✓ WP Dynamic Tags active — all placeholders available.', 'certificate-generator' );
							} else {
								echo '<span class="cg-text-bad">⚠ Requires <strong>WP Dynamic Tags</strong> plugin to be active.</span>';
							}
							?>
						</p>
					</div>

					<?php /* Card 3: Chatbot Event Placeholders */ ?>
					<div class="cg-ref-card">
						<h3><?php esc_html_e( 'Event Data', 'certificate-generator' ); ?> <span class="cg-badge cg-badge--info">{placeholders}</span></h3>
						<table class="cg-ref-table">
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_title}</span></td><td><?php esc_html_e( 'Upcoming event name', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_fees}</span></td><td><?php esc_html_e( 'Registration fee', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_dis_fees}</span></td><td><?php esc_html_e( 'Discounted fee', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_currency}</span></td><td><?php esc_html_e( 'Currency symbol / code', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_dates}</span></td><td><?php esc_html_e( 'Exam dates summary', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_registration_deadline}</span></td><td><?php esc_html_e( 'Last date to register', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_email}</span></td><td><?php esc_html_e( 'Contact email', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_phone}</span></td><td><?php esc_html_e( 'Contact phone', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag purple" onclick="cgCopyTag(this)">{event_whatsapp}</span></td><td><?php esc_html_e( 'WhatsApp number', 'certificate-generator' ); ?></td></tr>
						</table>
						<p class="cg-ref-note">
							<?php
							if ( class_exists( 'AI_Chatbot_Widget' ) || class_exists( 'AI_Chatbot_API' ) ) {
								esc_html_e( '✓ Chatbot active — event placeholders available after sync.', 'certificate-generator' );
							} else {
								echo '<span class="cg-text-bad">⚠ Requires <strong>chatbot-by-eshaan</strong> plugin.</span>';
							}
							?>
						</p>
					</div>

					<?php /* Card 4: Event Dynamic Tag Shortcodes */ ?>
					<div class="cg-ref-card">
						<h3><?php esc_html_e( 'Event Data', 'certificate-generator' ); ?> <span class="cg-badge cg-badge--warn">[shortcodes]</span></h3>
						<table class="cg-ref-table">
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_fees]</span></td><td><?php esc_html_e( 'Registration fee', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_dis_fees]</span></td><td><?php esc_html_e( 'Discounted fee', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_currency]</span></td><td><?php esc_html_e( 'Currency', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_email]</span></td><td><?php esc_html_e( 'Contact email', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_phone]</span></td><td><?php esc_html_e( 'Contact phone', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_whatsapp]</span></td><td><?php esc_html_e( 'WhatsApp number', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_exam_date1]</span></td><td><?php esc_html_e( 'Exam date 1', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_exam_date2]</span></td><td><?php esc_html_e( 'Exam date 2', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_last_date1]</span></td><td><?php esc_html_e( 'Last registration date 1', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_last_date2]</span></td><td><?php esc_html_e( 'Last registration date 2', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_mock_date1]</span></td><td><?php esc_html_e( 'Mock exam date/time 1', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[event_mock_date2]</span></td><td><?php esc_html_e( 'Mock exam date/time 2', 'certificate-generator' ); ?></td></tr>
						</table>
						<p class="cg-ref-note"><?php esc_html_e( 'Written to WP Dynamic Tags on every chatbot event sync. Available once sync runs.', 'certificate-generator' ); ?></p>
					</div>

					<?php /* Card 5: Certificate Data Shortcodes */ ?>
					<div class="cg-ref-card">
						<h3><?php esc_html_e( 'Certificate Data', 'certificate-generator' ); ?> <span class="cg-badge cg-badge--warn">[shortcodes]</span></h3>
						<table class="cg-ref-table">
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_last_student_name]</span></td><td><?php esc_html_e( 'Most recently saved student', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_last_school_name]</span></td><td><?php esc_html_e( 'Most recently saved school', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_students_name_N]</span></td><td><?php esc_html_e( 'Student name by post ID', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_students_email_N]</span></td><td><?php esc_html_e( 'Student email by post ID', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_school_name_N]</span></td><td><?php esc_html_e( 'School name by post ID', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_cert_type_N]</span></td><td><?php esc_html_e( 'Certificate type by post ID', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag orange" onclick="cgCopyTag(this)">[cg_issue_date_N]</span></td><td><?php esc_html_e( 'Issue date by post ID', 'certificate-generator' ); ?></td></tr>
						</table>
						<p class="cg-ref-note"><?php esc_html_e( 'Replace N with the WordPress post ID. Written automatically when a student/teacher/school is saved.', 'certificate-generator' ); ?></p>
					</div>

					<?php /* Card 6: Additional Site Shortcodes */ ?>
					<div class="cg-ref-card">
						<h3><?php esc_html_e( 'Site Info', 'certificate-generator' ); ?> <span class="cg-badge cg-badge--good">[shortcodes]</span></h3>
						<table class="cg-ref-table">
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">[site_name]</span></td><td><?php esc_html_e( 'Site title', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">[site_url]</span></td><td><?php esc_html_e( 'Site URL', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">[site_description]</span></td><td><?php esc_html_e( 'Site tagline', 'certificate-generator' ); ?></td></tr>
							<tr><td><span class="cg-tag green" onclick="cgCopyTag(this)">[admin_email]</span></td><td><?php esc_html_e( 'Admin email', 'certificate-generator' ); ?></td></tr>
						</table>
						<p class="cg-ref-note">
							<?php
							if ( class_exists( 'WP_Dynamic_Tags_Plugin' ) ) {
								printf(
									'<a href="%s">%s</a>',
									esc_url( admin_url( 'admin.php?page=wp-dynamic-tags' ) ),
									esc_html__( 'View all tags in WP Dynamic Tags →', 'certificate-generator' )
								);
							} else {
								echo '<span class="cg-text-bad">⚠ Requires <strong>WP Dynamic Tags</strong> plugin.</span>';
							}
							?>
						</p>
					</div>

				</div><!-- .cg-ref-grid -->
			<?php certificate_generator_ui_card_close(); ?>
			<div class="cg-copy-notice" id="cg-copy-notice"><?php esc_html_e( 'Copied!', 'certificate-generator' ); ?></div>
			<script>
			function cgCopyTag(el) {
				const text = el.textContent.trim();
				navigator.clipboard.writeText(text).then(function() {
					const notice = document.getElementById('cg-copy-notice');
					notice.classList.add('show');
					setTimeout(function() { notice.classList.remove('show'); }, 1500);
				});
			}
			</script>

		<?php elseif ( $active_tab == 'tools' ) : ?>
			<?php certificate_generator_ui_card_open( __( 'Clear Cache & Certificates', 'certificate-generator' ), array( 'icon' => 'trash', 'id' => 'certificate-generator-cache-section' ) ); ?>
				<p><?php esc_html_e( 'Click the button below to clear all cached results and generated certificate files.', 'certificate-generator' ); ?></p>
				<button type="button" id="clear-cache-btn" class="button button-secondary" data-cg-danger data-cg-confirm-label="<?php esc_attr_e( 'Clear', 'certificate-generator' ); ?>" data-cg-confirm="<?php esc_attr_e( 'Clear all cached results and delete the generated certificate PDF and ZIP files? Student, teacher, school and certificate records are kept.', 'certificate-generator' ); ?>">
					<?php esc_html_e( 'Clear Cache & Certificates', 'certificate-generator' ); ?>
				</button>
				<div id="clear-cache-message" aria-live="polite"></div>
			<?php certificate_generator_ui_card_close(); ?>

			<?php certificate_generator_ui_card_open( __( 'Email Logs', 'certificate-generator' ), array( 'icon' => 'email-alt' ) ); ?>
				<p><?php esc_html_e( 'View and manage email delivery logs for certificate notifications.', 'certificate-generator' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=certificate-email-logs' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'View Email Logs', 'certificate-generator' ); ?>
				</a>
			<?php certificate_generator_ui_card_close(); ?>

			<?php certificate_generator_ui_card_open( __( 'PDF File Integrity Check', 'certificate-generator' ), array( 'icon' => 'media-document' ) ); ?>
				<p><?php esc_html_e( 'Scan the certificates table for rows where the stored PDF path no longer exists on disk.', 'certificate-generator' ); ?></p>
				<button type="button" id="cg_pdf_check_btn" class="button button-secondary"><?php esc_html_e( 'Run Integrity Check', 'certificate-generator' ); ?></button>
				<div class="cg-mt-s" id="cg_pdf_check_result" aria-live="polite"></div>
			<?php certificate_generator_ui_card_close(); ?>

			<?php certificate_generator_ui_card_open( __( 'Delete All Certificate Data', 'certificate-generator' ), array( 'icon' => 'warning', 'class' => 'cg-card--danger' ) ); ?>
				<p><?php esc_html_e( 'Permanently deletes all students, teachers, schools, and certificate records from both the SQL tables and CPT posts. This cannot be undone.', 'certificate-generator' ); ?></p>
				<button type="button" id="cg_delete_all_data_btn" class="button cg-button-danger-solid" data-cg-danger data-cg-confirm-label="<?php esc_attr_e( 'Delete everything', 'certificate-generator' ); ?>" data-cg-confirm="<?php esc_attr_e( 'Permanently delete ALL student, teacher, school and certificate data? This cannot be undone.', 'certificate-generator' ); ?>">
					<?php esc_html_e( 'Delete All Data', 'certificate-generator' ); ?>
				</button>
				<span class="cg-ml" id="cg_delete_all_data_result" aria-live="polite"></span>
			<?php certificate_generator_ui_card_close(); ?>

			<script>
			(function(){
				var btn = document.getElementById('cg_delete_all_data_btn');
				if ( ! btn ) return;
				btn.addEventListener('click', function(){
					btn.disabled = true;
					btn.textContent = '<?php echo esc_js( __( 'Deleting…', 'certificate-generator' ) ); ?>';
					var msg = document.getElementById('cg_delete_all_data_result');
					var fd = new FormData();
					fd.append('action', 'certificate_generator_delete_all_data');
					fd.append('nonce', '<?php echo esc_js( wp_create_nonce( 'certificate_generator_delete_all_data' ) ); ?>');
					fetch(ajaxurl, { method:'POST', body:fd, credentials:'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(r){
							btn.disabled = false;
							btn.textContent = '<?php echo esc_js( __( 'Delete All Data', 'certificate-generator' ) ); ?>';
							if ( r.success ) {
								msg.style.color = 'green';
								msg.textContent = r.data;
							} else {
								msg.style.color = '#dc3232';
								msg.textContent = r.data || '<?php echo esc_js( __( 'Error deleting data.', 'certificate-generator' ) ); ?>';
							}
						})
						.catch(function(){ btn.disabled = false; msg.style.color='#dc3232'; msg.textContent='Request failed.'; });
				});
			}());
			</script>

		<?php elseif ( $active_tab == 'shortcode_text' ) : ?>
			<?php
			$cg_st_fields = array(
				'student' => array(
					'label'   => __( 'Student Search', 'certificate-generator' ),
					'title'   => __( 'Certificate Lookup', 'certificate-generator' ),
					'subtitle' => __( 'Enter your email to find your certificates', 'certificate-generator' ),
					'button'  => __( 'Search Certificates', 'certificate-generator' ),
					'help'    => __( 'Enter the email address you used during registration to find your certificates.', 'certificate-generator' ),
				),
				'teacher' => array(
					'label'   => __( 'Teacher Search', 'certificate-generator' ),
					'title'   => __( 'Teacher Certificate Lookup', 'certificate-generator' ),
					'subtitle' => __( 'Enter teacher email to find their certificates', 'certificate-generator' ),
					'button'  => __( 'Search Certificates', 'certificate-generator' ),
					'help'    => __( 'Enter the email address of the teacher to find their certificates.', 'certificate-generator' ),
				),
				'school'  => array(
					'label'   => __( 'School Search', 'certificate-generator' ),
					'title'   => __( 'School Certificate Lookup', 'certificate-generator' ),
					'subtitle' => __( 'Enter school name and place to find certificates', 'certificate-generator' ),
					'button'  => __( 'Search Certificates', 'certificate-generator' ),
					'help'    => __( 'Enter the school name and place to locate the certificates.', 'certificate-generator' ),
				),
			);
			$cg_st_msg = '';

			if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) && isset( $_POST['cg_shortcode_text_save'] )
				&& check_admin_referer( 'cg_shortcode_text_save', 'cg_shortcode_text_nonce' )
			) {
				$cg_st_data = array();
				foreach ( array_keys( $cg_st_fields ) as $cg_st_group ) {
					foreach ( array( 'title', 'subtitle', 'button', 'help' ) as $cg_st_prop ) {
						$cg_st_input_name                                    = "cg_st_{$cg_st_group}_{$cg_st_prop}";
						$cg_st_option_key                                    = "{$cg_st_group}_{$cg_st_prop}";
						$cg_st_data[ $cg_st_option_key ]                     = sanitize_text_field( wp_unslash( $_POST[ $cg_st_input_name ] ?? '' ) );
					}
				}
				update_option( 'certificate_generator_shortcode_text', $cg_st_data );
				$cg_st_msg = __( 'Shortcode text saved.', 'certificate-generator' );
			}

			$cg_st_saved = get_option( 'certificate_generator_shortcode_text', array() );
			?>
			<?php certificate_generator_ui_card_open( __( 'Shortcode Text', 'certificate-generator' ), array( 'icon' => 'shortcode', 'class' => 'cg-narrow' ) ); ?>
				<p><?php esc_html_e( 'Set sitewide default text for the search shortcodes below. A page can still override any field for itself using a shortcode attribute, e.g. [certificate_generator_student_search title="Custom Title"]. Leave a field blank to use the original default shown as its placeholder.', 'certificate-generator' ); ?></p>

				<?php if ( $cg_st_msg ) : ?>
					<?php certificate_generator_ui_notice( 'success', esc_html( $cg_st_msg ), true, true ); ?>
				<?php endif; ?>

				<form method="post">
					<?php wp_nonce_field( 'cg_shortcode_text_save', 'cg_shortcode_text_nonce' ); ?>
					<?php foreach ( $cg_st_fields as $cg_st_group => $cg_st_defaults ) : ?>
						<h3><?php echo esc_html( $cg_st_defaults['label'] ); ?></h3>
						<table class="form-table">
							<?php foreach ( array( 'title', 'subtitle', 'button', 'help' ) as $cg_st_prop ) : ?>
								<?php
								$cg_st_field_id     = "cg_st_{$cg_st_group}_{$cg_st_prop}";
								$cg_st_option_key   = "{$cg_st_group}_{$cg_st_prop}";
								$cg_st_current      = $cg_st_saved[ $cg_st_option_key ] ?? '';
								?>
								<tr>
									<th><label for="<?php echo esc_attr( $cg_st_field_id ); ?>"><?php echo esc_html( ucfirst( $cg_st_prop ) ); ?></label></th>
									<td>
										<input type="text" class="regular-text" id="<?php echo esc_attr( $cg_st_field_id ); ?>" name="<?php echo esc_attr( $cg_st_field_id ); ?>"
											value="<?php echo esc_attr( $cg_st_current ); ?>"
											placeholder="<?php echo esc_attr( $cg_st_defaults[ $cg_st_prop ] ); ?>">
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endforeach; ?>
					<?php submit_button( __( 'Save Shortcode Text', 'certificate-generator' ), 'primary', 'cg_shortcode_text_save' ); ?>
				</form>
			<?php certificate_generator_ui_card_close(); ?>

		<?php elseif ( $active_tab == 'scheduling' ) : ?>
			<?php certificate_generator_ui_card_open( __( 'Scheduled Templates', 'certificate-generator' ), array( 'icon' => 'calendar-alt' ) ); ?>
				<p><?php esc_html_e( 'Scheduled templates auto-publish hourly via WP-Cron once their Event Date has passed. Draft templates are not auto-published.', 'certificate-generator' ); ?></p>

				<?php
				if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
					$_sched_tables  = \CertificateGenerator\Database\CustomTables::instance();
					$_sched_tpl_tbl = $_sched_tables->get_table( 'certificate_templates' );
					$_sched_rows    = $GLOBALS['wpdb']->get_results(
						"SELECT * FROM $_sched_tpl_tbl
						  WHERE status IN ('scheduled','draft')
						  ORDER BY (event_date IS NULL OR event_date = '0000-00-00') ASC, event_date ASC, template_name ASC",
						ARRAY_A
					);
				} else {
					$_sched_rows = array();
				}

				if ( empty( $_sched_rows ) ) :
					?>
					<?php echo certificate_generator_ui_empty( __( 'No scheduled or draft templates. Every template is published.', 'certificate-generator' ), admin_url( 'admin.php?page=cg-templates' ), __( 'View Templates', 'certificate-generator' ), 'calendar-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
				<?php else : ?>
					<div class="cg-table-wrap">
					<table class="wp-list-table widefat fixed striped cg-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Template Name', 'certificate-generator' ); ?></th>
								<th><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?></th>
								<th><?php esc_html_e( 'Event Date', 'certificate-generator' ); ?></th>
								<th><?php esc_html_e( 'Status', 'certificate-generator' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'certificate-generator' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $_sched_rows as $_sched_row ) : ?>
							<tr data-template-id="<?php echo (int) $_sched_row['id']; ?>">
								<td><?php echo esc_html( $_sched_row['template_name'] ); ?></td>
								<td><?php echo esc_html( $_sched_row['certificate_type'] ); ?></td>
								<td>
									<?php
									if ( ! empty( $_sched_row['event_date'] ) && $_sched_row['event_date'] !== '0000-00-00' ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $_sched_row['event_date'] ) ) );
									} else {
										echo '&mdash;';
									}
									?>
								</td>
								<td>
									<?php if ( $_sched_row['status'] === 'scheduled' ) : ?>
										<?php echo certificate_generator_ui_badge( __( 'Scheduled', 'certificate-generator' ), 'info' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php else : ?>
										<?php echo certificate_generator_ui_badge( __( 'Draft', 'certificate-generator' ), 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<?php endif; ?>
								</td>
								<td>
									<button type="button" class="button button-primary cg-publish-now-btn"
										data-id="<?php echo (int) $_sched_row['id']; ?>"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'certificate_generator_publish_template_now' ) ); ?>">
										<?php esc_html_e( 'Publish Now', 'certificate-generator' ); ?>
									</button>
									&nbsp;
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=cg-template-edit&id=' . (int) $_sched_row['id'] ) ); ?>" class="button button-secondary">
										<?php esc_html_e( 'Edit', 'certificate-generator' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			<?php certificate_generator_ui_card_close(); ?>

			<script>
			(function($) {
				$('.cg-publish-now-btn').on('click', function() {
					var $btn   = $(this);
					var id     = $btn.data('id');
					var nonce  = $btn.data('nonce');
					var $row   = $btn.closest('tr');
					CGUI.busy($btn[0], true);
					$.post(ajaxurl, { action: 'certificate_generator_publish_template_now', template_id: id, nonce: nonce })
						.done(function(resp) {
							if (resp.success) {
								$row.fadeOut(400, function(){ $(this).remove(); });
							} else {
								alert('<?php echo esc_js( __( 'Could not publish template.', 'certificate-generator' ) ); ?>');
								CGUI.busy($btn[0], false);
							}
						})
						.fail(function() {
							alert('<?php echo esc_js( __( 'AJAX error.', 'certificate-generator' ) ); ?>');
							CGUI.busy($btn[0], false);
						});
				});
			})(jQuery);
			</script>

		<?php else : ?>
			<?php do_action( 'certificate_generator_settings_tab_' . $active_tab ); ?>
		<?php endif; ?>
	</div>

	<script>
		(function($) {
			$('#clear-cache-btn').on('click', function() {
				const $messageDiv = $('#clear-cache-message');
				const btn = this;
				$messageDiv.empty();
				CGUI.busy(btn, true);

				$.ajax({
					url: ajaxurl,
					method: 'POST',
					data: {
						action: 'certificate_generator_clear_cache',
						nonce: '<?php echo esc_html( wp_create_nonce( 'certificate_generator_clear_cache' ) ); ?>'
					},
					success: function(response) {
						CGUI.notice(response.success ? 'success' : 'error', response.data || '<?php echo esc_js( __( 'Operation completed.', 'certificate-generator' ) ); ?>', $messageDiv[0]);
					},
					error: function() {
						CGUI.notice('error', '<?php echo esc_js( __( 'Error occurred during the operation.', 'certificate-generator' ) ); ?>', $messageDiv[0]);
					},
					complete: function() { CGUI.busy(btn, false); }
				});
			});

			// S4: PDF integrity check
			$('#cg_pdf_check_btn').on('click', function () {
				var $btn = $(this), $res = $('#cg_pdf_check_result');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Checking…', 'certificate-generator' ) ); ?>');
				$res.html('');
				$.post(ajaxurl, {
					action: 'certificate_generator_pdf_integrity_check',
					nonce: '<?php echo esc_html( wp_create_nonce( 'certificate_generator_pdf_integrity_check' ) ); ?>'
				}, function (r) {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Run Integrity Check', 'certificate-generator' ) ); ?>');
					if (r.success) {
						var d = r.data;
						var color = d.broken === 0 ? 'green' : '#c0392b';
						$res.html(
							'<p style="color:' + color + ';font-weight:600">' +
							'Total with PDF path: ' + d.total + ' &nbsp;|&nbsp; ' +
							'Missing on disk: <strong>' + d.broken + '</strong>' +
							'</p>'
						);
					} else {
						$res.html('<p class="required">Check failed. See error log.</p>');
					}
				}).fail(function () {
					$btn.prop('disabled', false).text('<?php echo esc_js( __( 'Run Integrity Check', 'certificate-generator' ) ); ?>');
					$res.html('<p class="required">AJAX error.</p>');
				});
			});
		})(jQuery);
	</script>
	<?php
}



/**
 * Check if WP Mail SMTP plugin is active AND properly configured
 *
 * @return bool Whether WP Mail SMTP is active and configured
 */
function certificate_generator_is_wp_mail_smtp_active() {
	// Make sure the function exists before using it
	if ( ! function_exists( 'is_plugin_active' ) ) {
		include_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	// Check if plugin is active
	$is_active = is_plugin_active( 'wp-mail-smtp/wp_mail_smtp.php' ) ||
				is_plugin_active( 'wp-mail-smtp-pro/wp_mail_smtp.php' );

	if ( ! $is_active ) {
		return false;
	}

	// Check if WP Mail SMTP is properly configured
	// Method 1: Check WP Mail SMTP Free version
	if ( function_exists( 'wp_mail_smtp' ) ) {
		$options = get_option( 'wp_mail_smtp', array() );

		// Check if a mailer is configured (not 'mail')
		if ( isset( $options['mail']['mailer'] ) && $options['mail']['mailer'] !== 'mail' ) {
			return true;
		}
	}

	// Method 2: Check WP Mail SMTP Pro version
	if ( class_exists( 'WPMailSMTP\\Options' ) ) {
		try {
			$mailer = \WPMailSMTP\Options::init()->get( 'mail', 'mailer' );
			if ( $mailer && $mailer !== 'mail' ) {
				return true;
			}
		} catch ( Exception $e ) {
			certificate_generator_debug_log( 'Certificate Generator: WP Mail SMTP Pro config error — ' . $e->getMessage() );
		}
	}

	return false;
}

// ── AJAX: PDF integrity check ────────────────────────────────────────────────
add_action(
	'wp_ajax_certificate_generator_pdf_integrity_check',
	function () {
		check_ajax_referer( 'certificate_generator_pdf_integrity_check', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			wp_send_json_error( array( 'message' => 'CustomTables class not available.' ) );
		}

		global $wpdb;
		$tables     = \CertificateGenerator\Database\CustomTables::instance();
		$cert_table = $tables->get_table( 'certificates' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$rows = $wpdb->get_results(
			"SELECT id, pdf_path FROM $cert_table WHERE pdf_path IS NOT NULL AND pdf_path != ''",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total  = count( $rows );
		$broken = 0;
		foreach ( $rows as $row ) {
			if ( ! file_exists( $row['pdf_path'] ) ) {
				$broken++;
			}
		}

		wp_send_json_success(
			array(
				'total'  => $total,
				'broken' => $broken,
			)
		);
	}
);

/**
 * Sample field data for one template: every field it renders gets a placeholder,
 * using the saved template row. Shared by Preview Certificate and "Email me a
 * test certificate" so both render the same thing. Null when the template is missing.
 */
function certificate_generator_template_sample_data( int $template_id, string $fallback_type = 'Preview' ): ?array {
	global $wpdb;

	// Load the template row so we use its actual certificate_type and event_date.
	$tbl_templates = class_exists( 'CertificateGenerator\\Database\\CustomTables' )
	? \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' )
	: $wpdb->prefix . 'cg_certificate_templates';
	$tmpl          = $wpdb->get_row( $wpdb->prepare( "SELECT certificate_type, event_date, extra_fields FROM $tbl_templates WHERE id = %d", $template_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	if ( ! $tmpl ) {
		return null;
	}

	$cert_type  = ! empty( $tmpl['certificate_type'] ) ? $tmpl['certificate_type'] : $fallback_type;
	$event_date = ! empty( $tmpl['event_date'] ) ? $tmpl['event_date'] : gmdate( 'Y-m-d' );

	// Resolve the actual field list for this template — explicit per-slot
	// field_{N}_name mappings when present, else the same positional
	// CertificateGenerator_Field_Schema fallback real generation uses — so preview sample data
	// covers every field the template really renders, not just the old
	// hardcoded 4-field + CSV-registry set.
	$tmpl_meta = array();
	if ( ! empty( $tmpl['extra_fields'] ) ) {
		$decoded = json_decode( $tmpl['extra_fields'], true );
		if ( is_array( $decoded ) ) {
			foreach ( $decoded as $k => $v ) {
				$tmpl_meta[ $k ] = array( $v );
			}
		}
	}
	$template_field_count = (int) ( $tmpl_meta['template_field_count'][0] ?? 3 );
	$base_fields           = function_exists( 'certificate_generator_resolve_template_fields' )
		? certificate_generator_resolve_template_fields( $tmpl_meta, $cert_type, $template_field_count )
		: array( 'student_name', 'school_name', 'teacher_name', 'issue_date' );

	// Build dummy data for all fields
	$post_data = array(
		'certificate_type' => $cert_type,
		'issue_date'       => $event_date,
		'_preview_post_id' => $template_id,
	);

	foreach ( $base_fields as $idx => $field ) {
		$post_data[ $field ] = 'Sample ' . ucwords( str_replace( '_', ' ', $field ) );
	}

	// Override known fields with better samples
	$post_data['student_name'] = 'Sample Student Name';
	$post_data['school_name']  = 'Sample School';
	$post_data['teacher_name'] = 'Sample Teacher';

	// A 'photo' slot draws the STUDENT's own photo_url, not template data — there's
	// no synthetic student to source one from, so prefer a real uploaded photo (if
	// any exist yet), else fall back to a bundled generic-avatar placeholder, so
	// the preview shows what a real photo slot would look like instead of silently
	// skipping it the way real generation does when a student genuinely has no photo.
	$post_data['photo_url'] = '';
	if ( class_exists( 'CertificateGenerator\\Database\\CustomTables' ) ) {
		$students_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		if ( $students_table ) {
			$post_data['photo_url'] = (string) $wpdb->get_var( "SELECT photo_url FROM $students_table WHERE photo_url IS NOT NULL AND photo_url != '' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
	if ( ! $post_data['photo_url'] && defined( 'CERTIFICATE_GENERATOR_URL' ) ) {
		$post_data['photo_url'] = CERTIFICATE_GENERATOR_URL . 'assets/images/placeholder-photo.jpg';
	}

	return $post_data;
}

// ── AJAX: Preview Certificate (TemplatesPage "Preview Certificate" button) ──
add_action(
	'wp_ajax_certificate_generator_preview_template',
	function () {
		check_ajax_referer( 'cg_admin_preview_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		$template_id = absint( $_POST['template_id'] ?? $_POST['id'] ?? 0 );
		if ( ! $template_id ) {
			wp_die( 'Template ID required.' );
		}

		if ( ! function_exists( 'certificate_generator_generate_certificate_pdf_with_data' ) ) {
			wp_die( 'PDF generation function not loaded.' );
		}

		$post_data = certificate_generator_template_sample_data( $template_id, sanitize_text_field( wp_unslash( $_POST['certificate_type'] ?? 'Preview' ) ) );
		if ( null === $post_data ) {
			wp_die( 'Template not found (ID: ' . (int) $template_id . ').' );
		}

		$file_url = certificate_generator_generate_certificate_pdf_with_data( $post_data );

		if ( $file_url ) {
			wp_redirect( $file_url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- URL of a PDF this plugin just generated in uploads; may be on a CDN host
			exit;
		}

		wp_die( 'Could not generate preview PDF. Ensure the template URL is set and field positions are configured.' );
	}
);
?>