<?php
/**
 * Admin Error Reporting for Certificate Generator
 * Provides clear error messages and hosting-specific solutions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'Direct access not allowed' );
}

/**
 * Add admin notices for activation errors and compatibility issues
 */
add_action( 'admin_notices', 'certificate_generator_show_activation_notices' );

function certificate_generator_show_activation_notices() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Check for activation errors
	$activation_error = get_option( 'certificate_generator_activation_error' );
	if ( $activation_error ) {
		certificate_generator_show_error_notice( $activation_error );
		return;
	}

	// Check for compatibility issues
	$compatibility = get_option( 'certificate_generator_compatibility_check' );
	if ( $compatibility && ( ! $compatibility['compatible'] || ! empty( $compatibility['warnings'] ) ) ) {
		certificate_generator_show_compatibility_notice( $compatibility );
	}

	// Check for font system issues
	$activation_info = get_option( 'certificate_generator_activation_info' );
	if ( $activation_info && isset( $activation_info['mode'] ) && $activation_info['mode'] === 'minimal' ) {
		certificate_generator_show_minimal_mode_notice( $activation_info );
	}
}

/**
 * Show critical error notice
 */
function certificate_generator_show_error_notice( $error_message ) {
	?>
	<div class="notice notice-error is-dismissible">
		<h3>🚫 Certificate Generator - Installation Failed</h3>
		<p><strong>Error:</strong> <?php echo esc_html( $error_message ); ?></p>

		<h4>Quick Solutions:</h4>
		<ul style="margin-left: 20px;">
			<li>📞 <strong>Contact your hosting provider</strong> to increase PHP memory limit to 256MB</li>
			<li>⏱️ <strong>Request increased execution time</strong> (300 seconds recommended)</li>
			<li>🔧 <strong>Try FTP installation</strong> if WordPress upload limits are too low</li>
			<li>💾 <strong>Free up disk space</strong> (300MB required for full installation)</li>
		</ul>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cert-gen-compatibility-check' ) ); ?>" class="button button-primary">
				🔍 Run Detailed Compatibility Check
			</a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=cert_gen_clear_errors' ), 'clear_errors' ) ); ?>" class="button">
				✅ Dismiss Error (After Fixing)
			</a>
		</p>
	</div>
	<?php
}

/**
 * Show compatibility warning notice
 */
function certificate_generator_show_compatibility_notice( $compatibility ) {
	$hosting_type = $compatibility['hosting_type'];
	$has_errors   = ! $compatibility['compatible'];

	?>
	<div class="notice notice-<?php echo $has_errors ? 'error' : 'warning'; ?> is-dismissible">
		<h3><?php echo $has_errors ? '🚫' : '⚠️'; ?> Certificate Generator - Compatibility <?php echo $has_errors ? 'Issues' : 'Warnings'; ?></h3>

		<?php if ( $has_errors ) : ?>
			<p><strong>Critical Issues Found:</strong></p>
			<ul style="margin-left: 20px;">
				<?php foreach ( $compatibility['errors'] as $error ) : ?>
					<li>❌ <?php echo esc_html( $error ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $compatibility['warnings'] ) ) : ?>
			<p><strong>Warnings:</strong></p>
			<ul style="margin-left: 20px;">
				<?php foreach ( $compatibility['warnings'] as $warning ) : ?>
					<li>⚠️ <?php echo esc_html( $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( ! empty( $compatibility['recommendations'] ) ) : ?>
			<p><strong>Hosting-Specific Solutions (<?php echo esc_html( ucwords( str_replace( '_', ' ', $hosting_type ) ) ); ?>):</strong></p>
			<ul style="margin-left: 20px;">
				<?php foreach ( $compatibility['recommendations'] as $recommendation ) : ?>
					<li><?php echo wp_kses_post( $recommendation ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php
		// Get installation mode recommendation
		if ( function_exists( 'certificate_generator_get_installation_recommendation' ) ) {
			$recommendation = certificate_generator_get_installation_recommendation();
			if ( $recommendation['mode'] !== 'not_compatible' ) :
				?>
			<div style="background: #f0f8ff; padding: 10px; border-left: 4px solid #0073aa; margin: 10px 0;">
				<p><strong>💡 Recommended Installation Mode: <?php echo esc_html( ucwords( $recommendation['mode'] ) ); ?></strong></p>
				<p><?php echo esc_html( $recommendation['message'] ); ?></p>
				<p><em><?php echo esc_html( $recommendation['action'] ); ?></em></p>
			</div>
				<?php
			endif;
		}
		?>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cert-gen-compatibility-check' ) ); ?>" class="button button-primary">
				🔍 View Detailed Report
			</a>
			<?php if ( ! $has_errors ) : ?>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=cert_gen_force_minimal_activation' ), 'force_minimal' ) ); ?>" class="button">
					⚡ Try Minimal Installation
				</a>
			<?php endif; ?>
		</p>
	</div>
	<?php
}

/**
 * Show minimal mode notice
 */
function certificate_generator_show_minimal_mode_notice( $activation_info ) {
	?>
	<div class="notice notice-info is-dismissible">
		<h3>ℹ️ Certificate Generator - Minimal Mode Active</h3>
		<p>The plugin was installed in <strong>minimal mode</strong> due to server limitations.</p>

		<h4>What's Limited:</h4>
		<ul style="margin-left: 20px;">
			<li>🎨 Only essential fonts are loaded (12 basic fonts instead of 5000+)</li>
			<li>⚡ Reduced memory usage during certificate generation</li>
			<li>🔧 Some advanced features may be disabled</li>
		</ul>

		<h4>To Enable Full Mode:</h4>
		<ul style="margin-left: 20px;">
			<li>📞 Contact your hosting provider to increase PHP memory to 256MB+</li>
			<li>⏱️ Request execution time increase to 300+ seconds</li>
			<li>💾 Ensure at least 300MB free disk space</li>
		</ul>

		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cert-gen-compatibility-check' ) ); ?>" class="button button-primary">
				🔍 Check If Full Mode is Now Possible
			</a>
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?action=cert_gen_try_full_mode' ), 'try_full_mode' ) ); ?>" class="button">
				🚀 Try Upgrade to Full Mode
			</a>
		</p>
	</div>
	<?php
}

/**
 * Add compatibility check page to admin menu
 */
add_action( 'admin_menu', 'certificate_generator_add_compatibility_page' );

function certificate_generator_add_compatibility_page() {
	add_submenu_page(
		'tools.php',
		'Certificate Generator - Compatibility Check',
		'Cert Gen Compatibility',
		'manage_options',
		'cert-gen-compatibility-check',
		'certificate_generator_compatibility_page'
	);
}

/**
 * Compatibility check page
 */
function certificate_generator_compatibility_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Unauthorized' );
	}

	// Run fresh compatibility check
	$compatibility = null;
	if ( function_exists( 'certificate_generator_quick_compatibility_check' ) ) {
		$compatibility = certificate_generator_quick_compatibility_check();
		update_option( 'certificate_generator_compatibility_check', $compatibility );
	}

	?>
	<div class="wrap">
		<?php
		cg_ui_page_header(
			'Certificate Generator — Server Compatibility',
			'Checks this server against what the plugin needs (PHP version, extensions, memory, limits) and suggests fixes.',
			'<a href="' . esc_url( wp_nonce_url( admin_url( 'tools.php?page=cert-gen-compatibility-check&refresh=1' ), 'refresh_check' ) ) . '" class="button button-primary">Refresh Check</a>'
		);
		?>

		<?php if ( $compatibility ) : ?>
			<?php
			cg_ui_notice(
				$compatibility['compatible'] ? 'success' : 'error',
				'<strong>Overall status: ' . ( $compatibility['compatible'] ? 'Compatible' : 'Not compatible' ) . '</strong><br>'
				. 'Hosting type: ' . esc_html( ucwords( str_replace( '_', ' ', $compatibility['hosting_type'] ) ) ) . ' · Last checked: ' . esc_html( current_time( 'mysql' ) ),
				false
			);
			?>

			<?php cg_ui_card_open( 'Detailed Check Results', array( 'icon' => 'list-view' ) ); ?>
				<div class="cg-table-wrap">
				<table class="widefat cg-table">
					<thead>
						<tr>
							<th>Check</th>
							<th>Status</th>
							<th>Current Value</th>
							<th>Required</th>
							<th>Recommended</th>
							<th>Notes</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $compatibility['checks'] as $check_name => $check_result ) : ?>
						<tr>
							<td><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', $check_name ) ) ); ?></strong></td>
							<td>
								<?php
								if ( $check_result['passed'] ) {
									echo cg_ui_badge( 'Pass', 'good' ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $check_result['critical'] ) {
									echo cg_ui_badge( 'Fail (critical)', 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput
								} else {
									echo cg_ui_badge( 'Warning', 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
								?>
							</td>
							<td><?php echo esc_html( $check_result['current'] ); ?></td>
							<td><?php echo isset( $check_result['minimum'] ) ? esc_html( $check_result['minimum'] ) : 'N/A'; ?></td>
							<td><?php echo isset( $check_result['recommended'] ) ? esc_html( $check_result['recommended'] ) : 'N/A'; ?></td>
							<td>
								<?php echo esc_html( $check_result['message'] ); ?>
								<?php if ( ! empty( $check_result['recommendation'] ) ) : ?>
									<br><em><?php echo esc_html( $check_result['recommendation'] ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php cg_ui_card_close(); ?>

			<?php
			if ( function_exists( 'certificate_generator_get_installation_recommendation' ) ) {
				$recommendation = certificate_generator_get_installation_recommendation();
				cg_ui_card_open( 'Installation Recommendation', array( 'icon' => 'lightbulb' ) );
				?>
					<p><strong>Mode:</strong> <?php echo esc_html( ucwords( $recommendation['mode'] ) ); ?></p>
					<p><strong>Message:</strong> <?php echo esc_html( $recommendation['message'] ); ?></p>
					<p><strong>Action:</strong> <?php echo esc_html( $recommendation['action'] ); ?></p>
				<?php
				cg_ui_card_close();
			}
			?>

			<?php if ( ! empty( $compatibility['recommendations'] ) ) : ?>
				<?php cg_ui_card_open( 'Hosting-Specific Solutions', array( 'icon' => 'admin-tools' ) ); ?>
					<ul class="ul-disc">
						<?php foreach ( $compatibility['recommendations'] as $recommendation ) : ?>
							<li><?php echo wp_kses_post( $recommendation ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php cg_ui_card_close(); ?>
			<?php endif; ?>

		<?php else : ?>
			<?php cg_ui_notice( 'error', 'Compatibility checker is not available. Please ensure all plugin files are properly loaded.', false ); ?>
		<?php endif; ?>

	</div>
	<?php
}

/**
 * Handle admin actions
 */
add_action( 'admin_init', 'certificate_generator_handle_admin_actions' );

function certificate_generator_handle_admin_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Clear activation errors
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'cert_gen_clear_errors' ) {
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'clear_errors' ) ) {
			delete_option( 'certificate_generator_activation_error' );
			wp_safe_redirect( admin_url( 'plugins.php?message=errors_cleared' ) );
			exit;
		}
	}

	// Try minimal activation
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'cert_gen_force_minimal_activation' ) {
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'force_minimal' ) ) {
			// Force minimal mode
			update_option( 'certificate_generator_force_minimal_mode', true );

			// Try reactivation
			if ( function_exists( 'certificate_generator_smart_activate' ) ) {
				$result       = certificate_generator_smart_activate();
				$redirect_url = admin_url( 'plugins.php' );
				$redirect_url = add_query_arg( 'message', $result ? 'minimal_success' : 'minimal_failed', $redirect_url );
				wp_safe_redirect( $redirect_url );
				exit;
			}
		}
	}

	// Try full mode upgrade
	if ( isset( $_GET['action'] ) && $_GET['action'] === 'cert_gen_try_full_mode' ) {
		if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'try_full_mode' ) ) {
			// Remove minimal mode restriction
			delete_option( 'certificate_generator_force_minimal_mode' );

			// Run fresh compatibility check
			if ( function_exists( 'certificate_generator_quick_compatibility_check' ) ) {
				$compatibility  = certificate_generator_quick_compatibility_check();
				$recommendation = certificate_generator_get_installation_recommendation();

				if ( $recommendation['mode'] === 'full' ) {
					// Try to upgrade to full mode
					update_option( 'certificate_generator_font_mode', 'full' );
					wp_safe_redirect( admin_url( 'admin.php?page=cert-gen-compatibility-check&message=upgraded_to_full' ) );
				} else {
					wp_safe_redirect( admin_url( 'admin.php?page=cert-gen-compatibility-check&message=still_limited' ) );
				}
				exit;
			}
		}
	}
}

/**
 * Add custom admin messages
 */
add_action( 'admin_notices', 'certificate_generator_show_custom_messages' );

function certificate_generator_show_custom_messages() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_GET['message'] ) ) {
		switch ( $_GET['message'] ) {
			case 'errors_cleared':
				echo '<div class="notice notice-success is-dismissible"><p>✅ Activation errors have been cleared.</p></div>';
				break;
			case 'minimal_success':
				echo '<div class="notice notice-success is-dismissible"><p>✅ Plugin activated in minimal mode successfully!</p></div>';
				break;
			case 'minimal_failed':
				echo '<div class="notice notice-error is-dismissible"><p>❌ Minimal activation also failed. Please check server requirements.</p></div>';
				break;
			case 'upgraded_to_full':
				echo '<div class="notice notice-success is-dismissible"><p>🚀 Successfully upgraded to full mode! All features are now available.</p></div>';
				break;
			case 'still_limited':
				echo '<div class="notice notice-warning is-dismissible"><p>⚠️ Server still has limitations. Remaining in minimal mode.</p></div>';
				break;
		}
	}
}
?>
