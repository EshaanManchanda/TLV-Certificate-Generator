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
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
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
		'Certificate Generator - Diagnostics',
		'Cert Gen Diagnostics',
		'certificate_generator_manage_settings',
		'cert-gen-compatibility-check',
		'certificate_generator_compatibility_page'
	);
}

/**
 * Compatibility check page
 */
function certificate_generator_compatibility_page() {
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
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
		certificate_generator_ui_page_header(
			'Certificate Generator — Diagnostics',
			'Checks the plugin (folders, libraries, database, scheduled tasks, email queue, bulk jobs) and this server, and says how to fix what fails.',
			'<a href="' . esc_url( wp_nonce_url( admin_url( 'tools.php?page=cert-gen-compatibility-check&refresh=1' ), 'refresh_check' ) ) . '" class="button button-primary">Refresh Check</a>'
		);
		?>

		<?php certificate_generator_ui_card_open( 'Plugin checks', array( 'icon' => 'yes-alt' ) ); ?>
			<div class="cg-table-wrap">
			<table class="widefat cg-table">
				<thead><tr><th>Check</th><th>Status</th><th>Value</th><th>How to fix</th></tr></thead>
				<tbody>
				<?php foreach ( certificate_generator_diagnostics() as $check ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
						<td><?php echo certificate_generator_ui_badge( ucfirst( $check['status'] ), array( 'pass' => 'good', 'warn' => 'warn', 'fail' => 'bad' )[ $check['status'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?></td>
						<td><?php echo esc_html( $check['value'] ); ?></td>
						<td><?php echo esc_html( $check['fix'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php certificate_generator_ui_card_close(); ?>

		<?php if ( $compatibility ) : ?>
			<?php
			certificate_generator_ui_notice(
				$compatibility['compatible'] ? 'success' : 'error',
				'<strong>Overall status: ' . ( $compatibility['compatible'] ? 'Compatible' : 'Not compatible' ) . '</strong><br>'
				. 'Hosting type: ' . esc_html( ucwords( str_replace( '_', ' ', $compatibility['hosting_type'] ) ) ) . ' · Last checked: ' . esc_html( current_time( 'mysql' ) ),
				false
			);
			?>

			<?php certificate_generator_ui_card_open( 'Detailed Check Results', array( 'icon' => 'list-view' ) ); ?>
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
									echo certificate_generator_ui_badge( 'Pass', 'good' ); // phpcs:ignore WordPress.Security.EscapeOutput
								} elseif ( $check_result['critical'] ) {
									echo certificate_generator_ui_badge( 'Fail (critical)', 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput
								} else {
									echo certificate_generator_ui_badge( 'Warning', 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput
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
			<?php certificate_generator_ui_card_close(); ?>

			<?php
			if ( function_exists( 'certificate_generator_get_installation_recommendation' ) ) {
				$recommendation = certificate_generator_get_installation_recommendation();
				certificate_generator_ui_card_open( 'Installation Recommendation', array( 'icon' => 'lightbulb' ) );
				?>
					<p><strong>Mode:</strong> <?php echo esc_html( ucwords( $recommendation['mode'] ) ); ?></p>
					<p><strong>Message:</strong> <?php echo esc_html( $recommendation['message'] ); ?></p>
					<p><strong>Action:</strong> <?php echo esc_html( $recommendation['action'] ); ?></p>
				<?php
				certificate_generator_ui_card_close();
			}
			?>

			<?php if ( ! empty( $compatibility['recommendations'] ) ) : ?>
				<?php certificate_generator_ui_card_open( 'Hosting-Specific Solutions', array( 'icon' => 'admin-tools' ) ); ?>
					<ul class="ul-disc">
						<?php foreach ( $compatibility['recommendations'] as $recommendation ) : ?>
							<li><?php echo wp_kses_post( $recommendation ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php certificate_generator_ui_card_close(); ?>
			<?php endif; ?>

		<?php else : ?>
			<?php certificate_generator_ui_notice( 'error', 'Compatibility checker is not available. Please ensure all plugin files are properly loaded.', false ); ?>
		<?php endif; ?>

	</div>
	<?php
}

/**
 * Handle admin actions
 */
add_action( 'admin_init', 'certificate_generator_handle_admin_actions' );

function certificate_generator_handle_admin_actions() {
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
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
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET parameter, no state change
	if ( isset( $_GET['message'] ) ) {
		switch ( $_GET['message'] ) {
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
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

/**
 * Plugin checks for Tools → Certificate Generator Diagnostics and `wp certificate-generator
 * diagnostics`: each is {key, label, status: pass|warn|fail, value, fix}. A non-pass check
 * always says what to change.
 *
 * @return array<int,array{key:string,label:string,status:string,value:string,fix:string}>
 */
function certificate_generator_diagnostics(): array {
	global $wpdb;
	$checks = array();
	$add    = static function ( string $key, string $label, string $status, string $value, string $fix = '' ) use ( &$checks ): void {
		$fix      = 'pass' === $status ? '' : $fix;
		$checks[] = compact( 'key', 'label', 'status', 'value', 'fix' );
	};

	// Folders.
	$dir = function_exists( 'certificate_generator_certificates_dir' ) ? certificate_generator_certificates_dir() : '';
	$add( 'certificates_dir', 'Certificates folder writable', '' !== $dir && wp_is_writable( $dir ) ? 'pass' : 'fail', $dir, 'Make this folder writable by the web server (permissions 755, owned by the PHP user), or check that wp-content/uploads is writable.' );
	$uploads = wp_upload_dir();
	$add( 'uploads_dir', 'Uploads folder writable', wp_is_writable( $uploads['basedir'] ) ? 'pass' : 'fail', (string) $uploads['basedir'], 'WordPress cannot write to wp-content/uploads: fix its permissions (755) and owner.' );

	// PDF and image libraries.
	$fonts = glob( CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/font/*.php' ) ?: array();
	$add( 'fonts', 'Bundled PDF fonts', $fonts ? 'pass' : 'fail', count( $fonts ) . ' fonts', 'The plugin\'s lib/fpdf/font folder is empty or missing: reinstall the plugin from a fresh ZIP.' );
	$add( 'fpdf', 'PDF library (FPDF / tFPDF)', is_readable( CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/fpdf.php' ) && is_readable( CERTIFICATE_GENERATOR_PATH . 'lib/tfpdf/tfpdf.php' ) ? 'pass' : 'fail', '', 'lib/fpdf or lib/tfpdf is missing: reinstall the plugin from a fresh ZIP.' );
	$gd      = extension_loaded( 'gd' );
	$formats = $gd && function_exists( 'gd_info' ) ? array_keys( array_filter( array( 'PNG' => gd_info()['PNG Support'] ?? false, 'JPEG' => gd_info()['JPEG Support'] ?? false ) ) ) : array();
	$add( 'images', 'Image library (GD)', $gd && 2 === count( $formats ) ? 'pass' : ( $gd || extension_loaded( 'imagick' ) ? 'warn' : 'fail' ), $gd ? 'GD: ' . implode( ', ', $formats ) : ( extension_loaded( 'imagick' ) ? 'Imagick only' : 'none' ), 'Ask your host to enable the PHP "gd" extension with PNG and JPEG support (template images, QR codes, PNG downloads need it).' );

	// Database.
	if ( class_exists( '\CertificateGenerator\Database\Migrations\MigrationRunner' ) ) {
		$runner = new \CertificateGenerator\Database\Migrations\MigrationRunner();
		$have   = $runner->get_current_version();
		$want   = $runner->get_target_version();
		$add( 'db_version', 'Database version', $have === $want ? 'pass' : 'fail', "$have (plugin needs $want)", 'Run the pending migrations: Certificate Generator → Migration, or deactivate and reactivate the plugin.' );
	}

	// Cron.
	$cron_off = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	$overdue  = array();
	foreach ( (array) _get_cron_array() as $time => $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			if ( str_starts_with( (string) $hook, 'certificate_generator_' ) && (int) $time < time() - 10 * MINUTE_IN_SECONDS ) {
				$overdue[ $hook ] = true;
			}
		}
	}
	if ( $overdue ) {
		$add( 'cron', 'Scheduled tasks (WP-Cron)', 'fail', count( $overdue ) . ' overdue: ' . implode( ', ', array_keys( $overdue ) ), $cron_off ? 'DISABLE_WP_CRON is set but no server cron runs WordPress: add a server cron job running `wp cron event run --due-now` (or a request to wp-cron.php) every 5 minutes.' : 'WP-Cron only runs when someone visits the site: add a server cron job running `wp cron event run --due-now` every 5 minutes, then set DISABLE_WP_CRON.' );
	} else {
		$add( 'cron', 'Scheduled tasks (WP-Cron)', $cron_off ? 'warn' : 'pass', $cron_off ? 'DISABLE_WP_CRON set; nothing overdue' : 'running', $cron_off ? 'Nothing is overdue, so a server cron seems to run WordPress. If emails or bulk jobs stall, check that cron job.' : '' );
	}

	// Email queue.
	$queue = $wpdb->prefix . 'cert_email_queue';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $queue ) ) === $queue ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$row    = $wpdb->get_row( "SELECT COUNT(*) AS n, MIN(created_at) AS oldest FROM $queue WHERE status = 'pending'" );
		$n      = (int) ( $row->n ?? 0 );
		$stuck  = $n && $row->oldest && strtotime( (string) $row->oldest ) < strtotime( (string) $wpdb->get_var( 'SELECT NOW()' ) ) - HOUR_IN_SECONDS;
		$add( 'email_queue', 'Email queue', $stuck ? 'warn' : 'pass', $n ? sprintf( '%d pending, oldest %s', $n, $row->oldest ) : 'empty', 'Emails have waited over an hour: check the Scheduled tasks row above, and Email Logs for send errors (SMTP / API credentials, rate limits).' );
	}
	$transport = (string) get_option( 'certificate_generator_email_transport', 'wp_mail' );
	$add( 'mail', 'Email sending', 'pass', 'wp_mail' === $transport ? ( function_exists( 'certificate_generator_is_wp_mail_smtp_active' ) && certificate_generator_is_wp_mail_smtp_active() ? 'wp_mail via WP Mail SMTP' : 'wp_mail (the server\'s PHP mail)' ) : $transport );

	// Bulk jobs.
	if ( class_exists( '\CertificateGenerator\Services\Jobs' ) ) {
		$stalled = 0;
		$failed  = 0;
		$day     = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		foreach ( \CertificateGenerator\Services\Jobs::recent( 100 ) as $job ) {
			$stalled += \CertificateGenerator\Services\Jobs::is_stalled( $job ) ? 1 : 0;
			$failed  += (int) $job['failed'] > 0 && (string) $job['created_at'] >= $day ? 1 : 0;
		}
		$add( 'jobs', 'Bulk generation jobs', $stalled ? 'fail' : ( $failed ? 'warn' : 'pass' ), sprintf( '%d stalled, %d with failed items in the last 24 h', $stalled, $failed ), $stalled ? 'A job has had no worker for 15 minutes: fix Scheduled tasks above, open the job (Certificate Generator → Bulk Jobs) to run it, or run `wp certificate-generator jobs run <id>`.' : 'Open Certificate Generator → Bulk Jobs to see each failed item\'s error, fix it (usually a missing published template), then Retry failed.' );
	}
	return $checks;
}
