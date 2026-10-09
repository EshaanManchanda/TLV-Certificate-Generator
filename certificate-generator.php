<?php
/**
 * Plugin Name:       Certificate Generator – Bulk PDF Certificates, Email & QR Verification
 * Plugin URI:        https://techlovev.in/plugins/certificate-generator
 * Description:       A comprehensive plugin for managing, generating, and bulk-sending certificates for students and teachers.
 * Version:           7.6.0
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Tested up to:      7.1
 * Author:            Eshaan Manchanda
 * Author URI:        https://techlovev.in
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       certificate-generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define constants for plugin paths
define( 'CERTIFICATE_GENERATOR_VERSION', '7.6.0' ); // keep in sync with the Version header and readme.txt Stable tag
define( 'CERTIFICATE_GENERATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'CERTIFICATE_GENERATOR_URL', plugin_dir_url( __FILE__ ) );
define( 'CERTIFICATE_GENERATOR_QUEUE_BATCH_SIZE', 50 );
define( 'CERTIFICATE_GENERATOR_QUEUE_STALE_MINUTES', 10 );
define( 'CERTIFICATE_GENERATOR_QUEUE_MAX_ATTEMPTS', 3 );
define( 'CERTIFICATE_GENERATOR_QUEUE_RUNTIME_BUDGET', 20 );
define( 'CERTIFICATE_GENERATOR_ADMIN_EXPORT_ZIP_PART_SIZE', 200 );

require_once __DIR__ . '/includes/Core/debug-log.php';

// Plugins page action links: Settings | Docs | Pro add-on (hidden once Pro is active)
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( array $links ): array {
		$custom = array(
			'settings' => '<a href="' . esc_url( admin_url( 'options-general.php?page=certificate_generator_settings' ) ) . '">Settings</a>',
			'docs'     => '<a href="' . esc_url( admin_url( 'admin.php?page=cg-documentation' ) ) . '">Docs</a>',
		);
		if ( ! defined( 'CG_PRO_VERSION' ) ) {
			$custom['get_pro'] = '<a href="https://techlovev.in/certificate-generator/pricing" target="_blank" rel="noopener">Pro add-on</a>';
		}
		return array_merge( $custom, $links );
	}
);


/**
 * Format a MySQL datetime string for user-facing display (dd-mm-yyyy).
 *
 * @param string|null $date_string  MySQL datetime/date string.
 * @param bool        $include_time Include HH:ii in output.
 * @return string Formatted date, or '—' for empty/invalid input.
 */
function certificate_generator_format_date( ?string $date_string, bool $include_time = false ): string {
	if ( empty( $date_string ) || $date_string === '0000-00-00 00:00:00' ) {
		return '—';
	}
	$ts = strtotime( $date_string );
	if ( $ts === false ) {
		return $date_string;
	}
	return $include_time ? gmdate( 'd-m-Y H:i', $ts ) : gmdate( 'd-m-Y', $ts );
}

/**
 * Whether an admin screen hook belongs to this plugin.
 */
function certificate_generator_is_admin_page( $hook ): bool {
	$hook = (string) $hook;
	return strpos( $hook, 'cg-' ) !== false
		|| strpos( $hook, 'cert-gen-' ) !== false
		|| strpos( $hook, 'certificate' ) !== false
		|| strpos( $hook, 'students' ) !== false
		|| strpos( $hook, 'teachers' ) !== false
		|| strpos( $hook, 'schools' ) !== false
		|| ( in_array( $hook, array( 'post.php', 'post-new.php' ), true )
			&& in_array( get_current_screen()->post_type ?? '', array( 'students', 'teachers', 'schools', 'certificates' ), true ) );
}

// Hook for kit-scoped tokens in assets/css/cg-ui.css.
add_filter(
	'admin_body_class',
	static function ( $classes ) {
		return certificate_generator_is_admin_page( $GLOBALS['hook_suffix'] ?? '' ) ? $classes . ' cg-admin ' : $classes; // Trailing space: some plugins append without one.
	}
);

// Enqueue CSS and JS for Admin UI
function certificate_generator_custom_admin_assets( $hook ) {
	if ( ! certificate_generator_is_admin_page( $hook ) ) {
		return;
	}

	wp_enqueue_style( 'cg-ui', plugin_dir_url( __FILE__ ) . 'assets/css/cg-ui.css', array(), (string) filemtime( CERTIFICATE_GENERATOR_PATH . 'assets/css/cg-ui.css' ) );
	wp_enqueue_script( 'cg-ui', plugin_dir_url( __FILE__ ) . 'assets/js/cg-ui.js', array(), (string) filemtime( CERTIFICATE_GENERATOR_PATH . 'assets/js/cg-ui.js' ), true );
	wp_localize_script(
		'cg-ui',
		'cgUiL10n',
		array(
			'confirmTitle' => __( 'Are you sure?', 'certificate-generator' ),
			'confirm'      => __( 'Confirm', 'certificate-generator' ),
			'cancel'       => __( 'Cancel', 'certificate-generator' ),
			'dismiss'      => __( 'Dismiss this notice.', 'certificate-generator' ),
		)
	);
	wp_enqueue_style( 'custom-admin-css', plugin_dir_url( __FILE__ ) . 'assets/css/admin-style.css', array( 'cg-ui' ), (string) filemtime( CERTIFICATE_GENERATOR_PATH . 'assets/css/admin-style.css' ) );
	wp_enqueue_script( 'custom-admin-js', plugin_dir_url( __FILE__ ) . 'assets/js/admin-script.js', array( 'jquery' ), CERTIFICATE_GENERATOR_VERSION, true );

	// Shared media uploader — enqueued on all CG admin pages where images may be selected.
	if ( strpos( $hook, 'cg-' ) !== false || strpos( $hook, 'certificate' ) !== false ) {
		wp_enqueue_media();
		wp_enqueue_script( 'cg-media-uploader', plugin_dir_url( __FILE__ ) . 'assets/js/cg-media-uploader.js', array( 'jquery' ), '1.0.0', true );
	}

	// Enqueue filter assets on specific admin pages
	$filter_pages = array( 'settings_page_certificate-bulk-send', 'settings_page_certificate-email-logs', 'edit-students', 'edit-teachers', 'edit-schools' );

	if ( in_array( $hook, $filter_pages ) || strpos( $hook, 'certificate' ) !== false ) {
		wp_enqueue_style( 'cert-filters-css', plugin_dir_url( __FILE__ ) . 'assets/css/admin-filters.css', array( 'cg-ui' ), (string) filemtime( CERTIFICATE_GENERATOR_PATH . 'assets/css/admin-filters.css' ) );
		wp_enqueue_script( 'cert-filters-js', plugin_dir_url( __FILE__ ) . 'assets/js/admin-filters.js', array( 'jquery', 'cg-ui' ), (string) filemtime( CERTIFICATE_GENERATOR_PATH . 'assets/js/admin-filters.js' ), true );

		wp_localize_script(
			'cert-filters-js',
			'certFilterAjax',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cert_bulk_send' ),
				'i18n'    => array(
					'starting'      => __( 'Starting…', 'certificate-generator' ),
					'error_generic' => __( 'An unexpected error occurred.', 'certificate-generator' ),
					'error_send'    => __( 'Failed to start bulk send. Please try again.', 'certificate-generator' ),
				),
			)
		);
	}

	// Localize AJAX data for student edit page
	if ( strpos( $hook, 'post.php' ) !== false || strpos( $hook, 'post-new.php' ) !== false ) {
		wp_localize_script(
			'custom-admin-js',
			'cgStudentAjax',
			array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'schoolNonce' => wp_create_nonce( 'cg_school_autocomplete' ),
			)
		);
	}
}
add_action( 'admin_enqueue_scripts', 'certificate_generator_custom_admin_assets' );

// Include required files with enhanced error handling
$certificate_generator_critical_files = array(
	'includes/Core/server-compatibility.php' => 'Server compatibility checker',
	'includes/Core/error-reporting.php'      => 'Error reporting system',
	'includes/Admin/ui.php'                  => 'Shared admin UI helpers',
);

$certificate_generator_optional_files = array(
	'includes/Core/security-helper.php'                 => 'Security helper (rate limiting)',
	'includes/Core/field-schema.php'                    => 'Field schema manager',
	'includes/Core/post-types.php'                      => 'Certificate post type',
	'includes/Services/certificate-search.php'          => 'Student certificate search',
	'includes/Services/bulk-import.php'                 => 'Bulk import functionality',
	'includes/Services/bulk-export.php'                 => 'Bulk export functionality',
	'includes/Services/bulk-download.php'               => 'Bulk certificate download',
	'includes/Services/background-processor.php'        => 'Background processing',
	'includes/Admin/settings.php'                       => 'Admin settings',
	'includes/Admin/columns.php'                        => 'Admin columns',
	'includes/Email/functions.php'                      => 'Email functions',
	'includes/Email/log.php'                            => 'Email logging',
	'includes/Admin/email-logs.php'                     => 'Admin email logs',
	'includes/Email/queue.php'                          => 'Email queue system',
	'includes/Email/rate-limiter.php'                   => 'Email rate limiter',
	'includes/Services/bulk-email-sender.php'           => 'Bulk email sender',
	'includes/legacy-shims.php'                         => 'v8 anti-corruption shims (frozen, @deprecated v8)',
	'includes/Admin/bulk-email.php'                     => 'Bulk email admin page',
	'includes/Admin/cert-download-admin.php'            => 'Admin certificate download page',
	'includes/Admin/revoke-certificate.php'             => 'Revoke certificate admin tool',
	'includes/Admin/filters-api.php'                    => 'Admin filters API',
	'includes/Public/student-template.php'              => 'Student public profile template',
	'includes/Database/migrator.php'                    => 'Database migrator',
	'includes/Database/migration-scheduled-status.php'  => 'Scheduled status migration',
	'includes/Database/migration-send-email-column.php' => 'Send email column migration',
	'includes/Database/migration-queue-columns.php'     => 'Queue last_attempt_at + indexes migration',
	'includes/Services/serial-generator.php'            => 'Serial number generator',
	'includes/Services/qr-generator.php'                => 'QR code generator',
	'includes/Admin/cg-settings.php'                    => 'Admin settings',
	'includes/Admin/analytics.php'                      => 'Analytics dashboard',
	'includes/Admin/bulk-serial.php'                    => 'Bulk serial generator',
	'includes/Public/verification.php'                  => 'Public verification page',
	'includes/Cron/jobs.php'                            => 'Scheduled cron jobs',
	'includes/Admin/documentation.php'                  => 'Documentation & getting started page',
	'includes/Admin/feedback-contact.php'               => 'Feedback & contact form links',
);

$certificate_generator_missing_critical_files = array();
$certificate_generator_missing_optional_files = array();

// Check and include critical files
foreach ( $certificate_generator_critical_files as $certificate_generator_file => $certificate_generator_description ) {
	$certificate_generator_path = CERTIFICATE_GENERATOR_PATH . $certificate_generator_file;
	if ( file_exists( $certificate_generator_path ) && is_readable( $certificate_generator_path ) ) {
		require_once $certificate_generator_path;
	} else {
		$certificate_generator_missing_critical_files[] = "$certificate_generator_description ($certificate_generator_file)";
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			certificate_generator_debug_log( "Certificate Generator Debug - Missing critical file - $certificate_generator_file" );
		}
	}
}

// Check and include optional files
foreach ( $certificate_generator_optional_files as $certificate_generator_file => $certificate_generator_description ) {
	$certificate_generator_path = CERTIFICATE_GENERATOR_PATH . $certificate_generator_file;
	if ( file_exists( $certificate_generator_path ) && is_readable( $certificate_generator_path ) ) {
		require_once $certificate_generator_path;
	} else {
		$certificate_generator_missing_optional_files[] = "$certificate_generator_description ($certificate_generator_file)";
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			certificate_generator_debug_log( "Certificate Generator Debug - Missing optional file - $certificate_generator_file" );
		}
	}
}

// Handle missing critical files
if ( ! empty( $certificate_generator_missing_critical_files ) ) {
	$certificate_generator_error_message = 'Certificate Generator cannot load due to missing critical files: ' . implode( ', ', $certificate_generator_missing_critical_files );

	// Add admin notice instead of breaking the plugin
	add_action(
		'admin_notices',
		function () use ( $certificate_generator_error_message ) {
			echo '<div class="notice notice-error is-dismissible"><p><strong>Certificate Generator:</strong> ' . esc_html( $certificate_generator_error_message ) . '</p></div>';
		}
	);

	certificate_generator_debug_log( 'Certificate Generator: Critical files missing - plugin may not function properly' );
}

// Store missing files info for admin display
if ( ! empty( $certificate_generator_missing_optional_files ) ) {
	update_option( 'certificate_generator_missing_files', $certificate_generator_missing_optional_files );
}

// ── New Architecture: PSR-4 Autoloader ──────────────────────────────────────
$certificate_generator_autoloader = CERTIFICATE_GENERATOR_PATH . 'vendor/autoload.php';
if ( file_exists( $certificate_generator_autoloader ) ) {
	require_once $certificate_generator_autoloader;
} else {
	// Fallback PSR-4 autoloader — active when composer install hasn't been run.
	// Maps CertificateGenerator\Foo\Bar → src/Foo/Bar.php
	spl_autoload_register(
		function ( string $class ): void {
			$prefix = 'CertificateGenerator\\';
			$len    = strlen( $prefix );
			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				return;
			}
			$relative = substr( $class, $len );
			$certificate_generator_file     = CERTIFICATE_GENERATOR_PATH . 'src/' . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
			if ( file_exists( $certificate_generator_file ) ) {
				require_once $certificate_generator_file;
			}
		}
	);
}

if ( class_exists( '\CertificateGenerator\Core\Plugin' ) ) {
	$certificate_generator_plugin = new \CertificateGenerator\Core\Plugin();

	register_activation_hook( __FILE__, array( \CertificateGenerator\Core\Plugin::class, 'activate' ) );
	register_deactivation_hook( __FILE__, array( \CertificateGenerator\Core\Plugin::class, 'deactivate' ) );

	add_action(
		'plugins_loaded',
		function () use ( $certificate_generator_plugin ) {
			$certificate_generator_plugin->boot();
		}
	);
}

// ── Legacy Enhancement Classes (keep working while src/ migration continues) ─
if ( class_exists( 'CertificateGenerator_Migrator' ) ) {
	CertificateGenerator_Migrator::run();
}

if ( class_exists( 'CertificateGenerator_QR_Code_Generator' ) ) {
	CertificateGenerator_QR_Code_Generator::get_instance()->register_template_meta_fields();
}

if ( class_exists( 'CertificateGenerator_Admin_Settings' ) ) {
	$certificate_generator_admin_settings = new CertificateGenerator_Admin_Settings();
	$certificate_generator_admin_settings->init();
}

if ( class_exists( 'CertificateGenerator_Analytics_Dashboard' ) ) {
	CertificateGenerator_Analytics_Dashboard::get_instance()->init();
}

if ( class_exists( 'CertificateGenerator_Bulk_Serial_Generator' ) ) {
	CertificateGenerator_Bulk_Serial_Generator::get_instance()->init();
}

if ( class_exists( 'CertificateGenerator_Public_Verification' ) ) {
	CertificateGenerator_Public_Verification::get_instance()->init();
}

if ( class_exists( 'CertificateGenerator_Cron_Jobs' ) ) {
	CertificateGenerator_Cron_Jobs::init();
}

// ── Custom Tables: Create tables + sync hooks ────────────────────────────────
if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
	$certificate_generator_custom_tables = \CertificateGenerator\Database\CustomTables::instance();

	// Create/upgrade tables when a table is missing OR the schema version moved on
	// (dbDelta() safely adds any new columns to tables that already exist).
	if ( $certificate_generator_custom_tables->needs_upgrade() ) {
		$certificate_generator_custom_tables->create_all();
	}

	// Page AJAX handlers: admin_menu (below) never fires on admin-ajax.php, so they hook here.
	add_action(
		'admin_init',
		function () {
			if ( ! wp_doing_ajax() ) {
				return;
			}
			foreach ( array( 'StudentsPage', 'TeachersPage', 'SchoolsPage', 'TemplatesPage' ) as $page ) {
				$class = '\CertificateGenerator\Admin\Pages\\' . $page;
				if ( class_exists( $class ) ) {
					( new $class() )->register_ajax();
				}
			}
		}
	);

	// Register all admin pages — centralized menu organization
	add_action(
		'admin_menu',
		function () {
			// Top-level dashboard menu
			add_menu_page(
				'Certificate Generator',
				'Certificate Generator',
				'manage_options',
				'cg-dashboard',
				'certificate_generator_render_dashboard_page',
				'dashicons-award',
				25
			);
			// Explicit landing submenu — without this, whichever add_submenu_page()
			// call happens to fire first under 'cg-dashboard' (across all admin_menu
			// hooks, any priority/order) becomes the top-level link's target instead
			// of the dashboard itself, and WP renders that link as a bare page slug
			// (e.g. "cg-bulk-serials") instead of "admin.php?page=...", which 404s.
			add_submenu_page( 'cg-dashboard', 'Certificate Generator', 'Dashboard', 'manage_options', 'cg-dashboard' );

			// ── Entities (who the certificates are for) ──
			if ( class_exists( '\CertificateGenerator\Admin\Pages\StudentsPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\StudentsPage() )->register();
			}
			if ( class_exists( '\CertificateGenerator\Admin\Pages\TeachersPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\TeachersPage() )->register();
			}
			if ( class_exists( '\CertificateGenerator\Admin\Pages\SchoolsPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\SchoolsPage() )->register();
			}

			// ── Design (templates + the fonts they use) ──
			if ( class_exists( '\CertificateGenerator\Admin\Pages\TemplatesPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\TemplatesPage() )->register();
			}
			if ( class_exists( '\CertificateGenerator\Admin\Pages\FontsPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\FontsPage() )->register();
			}
			if ( class_exists( '\CertificateGenerator\Admin\Pages\EventsPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\EventsPage() )->register();
			}

			// ── Bulk Operations ──
			add_submenu_page( 'cg-dashboard', 'Bulk Import', 'Bulk Import', 'manage_options', 'cg-bulk-import', 'certificate_generator_render_bulk_import_page' );
			add_submenu_page( 'cg-dashboard', 'Bulk Export', 'Bulk Export', 'manage_options', 'cg-bulk-export', 'certificate_generator_render_bulk_export_page' );
			if ( class_exists( 'CertificateGenerator_Bulk_Serial_Generator' ) ) {
				CertificateGenerator_Bulk_Serial_Generator::get_instance()->add_bulk_serial_menu();
			}
			add_submenu_page( 'cg-dashboard', 'Download Certificates', 'Download Certs', 'manage_options', 'cg-cert-download', 'certificate_generator_render_admin_cert_download_page' );

			// ── Email ──
			add_submenu_page( 'cg-dashboard', 'Bulk Send Certificates', 'Bulk Send', 'manage_options', 'certificate-bulk-send', 'certificate_generator_render_bulk_send_page' );
			add_submenu_page( 'cg-dashboard', 'Email Logs', 'Email Logs', 'manage_options', 'certificate-email-logs', 'certificate_generator_render_email_logs_page' );

			// ── Certificate Management & Settings ──
			add_submenu_page( 'cg-dashboard', 'Certificate Analytics', 'Analytics', 'manage_options', 'cg-analytics', 'certificate_generator_render_analytics_page' );
			add_submenu_page( 'cg-dashboard', 'Revoke Certificate', 'Revoke Certificate', 'manage_options', 'cg-revoke-certificate', 'certificate_generator_render_revoke_certificate_page' );
			add_submenu_page( 'cg-dashboard', 'Serial Number Settings', 'Serial Settings', 'manage_options', 'cg-serial-settings', 'certificate_generator_render_serial_settings_page' );

			// ── Integrations: the Pro add-on adds its LMS / WooCommerce pages here ──
			do_action( 'certificate_generator_admin_menu_integrations' );

			// ── Advanced (one-off / rarely-used tools, kept near the bottom) ──
			if ( class_exists( '\CertificateGenerator\Admin\Pages\MigrationPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\MigrationPage() )->register();
			}

			// ── Developer ── (never registers unless CG_TESTING_UI === true)
			if ( class_exists( '\CertificateGenerator\Admin\Pages\TestCenterPage' ) ) {
				( new \CertificateGenerator\Admin\Pages\TestCenterPage() )->register();
			}

			// ── Documentation & Getting Started (always last) ──
			add_submenu_page(
				'cg-dashboard',
				'Documentation',
				'📖 Documentation',
				'manage_options',
				'cg-documentation',
				'certificate_generator_render_documentation_page'
			);

			// ── Feedback & Contact (external Google Forms) ──
			add_submenu_page(
				'cg-dashboard',
				'Feedback',
				'💬 Feedback',
				'manage_options',
				'cg-feedback',
				'certificate_generator_render_feedback_page'
			);
			add_submenu_page(
				'cg-dashboard',
				'Contact Us',
				'✉️ Contact Us',
				'manage_options',
				'cg-contact',
				'certificate_generator_render_contact_page'
			);
		},
		1 // must run before any other admin_menu callback (bulk-serial, settings, etc. are all default priority 10) so 'cg-dashboard' registers its own landing submenu first — see comment above.
	);



}

/**
 * First-run checklist steps, shared by the Dashboard and Documentation → Getting
 * Started so the two can't drift apart. Keys: done, label, url, cta.
 */
function certificate_generator_setup_steps( int $templates, int $records, bool $has_issued ): array {
	return array(
		array(
			'done'  => $templates > 0,
			'label' => __( 'Create your first certificate template', 'certificate-generator' ),
			'url'   => admin_url( 'admin.php?page=cg-templates' ),
			'cta'   => __( 'Add a template', 'certificate-generator' ),
		),
		array(
			'done'  => (bool) get_option( 'certificate_generator_test_certificate_sent' ),
			'label' => __( 'Email yourself a test certificate', 'certificate-generator' ),
			'url'   => admin_url( 'admin.php?page=cg-templates' ),
			'cta'   => __( 'Open a template', 'certificate-generator' ),
		),
		array(
			'done'  => $records > 0,
			'label' => __( 'Add a student, teacher, or school record', 'certificate-generator' ),
			'url'   => admin_url( 'admin.php?page=cg-students' ),
			'cta'   => __( 'Add a record', 'certificate-generator' ),
		),
		array(
			'done'  => $has_issued,
			'label' => __( 'Issue your first certificate', 'certificate-generator' ),
			'url'   => admin_url( 'admin.php?page=cg-students' ),
			'cta'   => __( 'Issue a certificate', 'certificate-generator' ),
		),
	);
}

/**
 * Renders the top-level dashboard: a "Getting Started" checklist for new
 * installs, so first-run admins have somewhere to go instead of a blank page.
 */
function certificate_generator_render_dashboard_page(): void {
	global $wpdb;

	// Data lives in the wp_cg_* custom tables; the legacy CPTs aren't registered on fresh installs.
	$cg_tables = \CertificateGenerator\Database\CustomTables::instance();
	$count     = function ( string $name ) use ( $wpdb, $cg_tables ): int {
		return $cg_tables->table_exists( $name )
			? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $cg_tables->get_table( $name ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: 0;
	};
	$counts = array(
		'students'  => $count( 'students' ),
		'teachers'  => $count( 'teachers' ),
		'schools'   => $count( 'schools' ),
		'templates' => $count( 'certificate_templates' ),
	);

	$certs_table = $wpdb->prefix . 'certificate_generator';
	$has_issued  = false;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $certs_table ) ) === $certs_table ) {
		$has_issued = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $certs_table" ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	$steps = certificate_generator_setup_steps( $counts['templates'], $counts['students'] + $counts['teachers'] + $counts['schools'], $has_issued );
	$done_count = count( array_filter( wp_list_pluck( $steps, 'done' ) ) );

	$actions = array(
		array( 'upload', __( 'Bulk Import', 'certificate-generator' ), __( 'Add many records at once from a CSV file.', 'certificate-generator' ), 'cg-bulk-import' ),
		array( 'download', __( 'Download Certificates', 'certificate-generator' ), __( 'Generate PDFs and download them as a ZIP.', 'certificate-generator' ), 'cg-cert-download' ),
		array( 'email-alt', __( 'Bulk Send', 'certificate-generator' ), __( 'Email certificates to recipients in batches.', 'certificate-generator' ), 'certificate-bulk-send' ),
		array( 'chart-bar', __( 'Analytics', 'certificate-generator' ), __( 'See how many certificates were issued and verified.', 'certificate-generator' ), 'cg-analytics' ),
	);
	?>
	<div class="wrap">
		<?php
		certificate_generator_ui_page_header(
			__( 'Certificate Generator', 'certificate-generator' ),
			__( 'Design templates, manage records, and issue certificates. Use the menu on the left for everything else.', 'certificate-generator' )
		);
		?>

		<?php if ( $done_count < count( $steps ) ) : ?>
			<?php
			certificate_generator_ui_card_open(
				/* translators: 1: completed steps, 2: total steps */
				sprintf( __( 'Getting Started (%1$d of %2$d done)', 'certificate-generator' ), $done_count, count( $steps ) ),
				array(
					'icon'  => 'flag',
					'class' => 'cg-checklist',
				)
			);
			certificate_generator_ui_progress( 'cg-getting-started', __( 'Finish these steps to issue your first certificate.', 'certificate-generator' ), true, $done_count, count( $steps ) );
			?>
			<ul class="cg-checklist__list">
				<?php foreach ( $steps as $step ) : ?>
				<li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
					<span class="dashicons <?php echo $step['done'] ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>" aria-hidden="true"></span>
					<span class="cg-checklist__label"><?php echo esc_html( $step['label'] ); ?></span>
					<?php if ( $step['done'] ) : ?>
						<span class="screen-reader-text"><?php esc_html_e( '(done)', 'certificate-generator' ); ?></span>
					<?php else : ?>
						<a class="button button-small" href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['cta'] ); ?></a>
					<?php endif; ?>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php certificate_generator_ui_card_close(); ?>
		<?php endif; ?>

		<div class="cg-stats">
			<?php
			certificate_generator_ui_stat( __( 'Students', 'certificate-generator' ), $counts['students'] );
			certificate_generator_ui_stat( __( 'Teachers', 'certificate-generator' ), $counts['teachers'] );
			certificate_generator_ui_stat( __( 'Schools', 'certificate-generator' ), $counts['schools'] );
			certificate_generator_ui_stat( __( 'Templates', 'certificate-generator' ), $counts['templates'] );
			?>
		</div>

		<div class="cg-grid">
			<?php foreach ( $actions as $a ) : ?>
				<?php certificate_generator_ui_card_open( $a[1], array( 'icon' => $a[0] ) ); ?>
					<p><?php echo esc_html( $a[2] ); ?></p>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $a[3] ) ); ?>"><?php esc_html_e( 'Open', 'certificate-generator' ); ?></a>
				<?php certificate_generator_ui_card_close(); ?>
			<?php endforeach; ?>
		</div>
	</div>
	<?php
}

// Plugin activation hook
function certificate_generator_activate() {
	try {
		// Run compatibility check first
		if ( function_exists( 'certificate_generator_quick_compatibility_check' ) ) {
			$compatibility = certificate_generator_quick_compatibility_check();

			if ( ! $compatibility['compatible'] ) {
				$error_message = 'Certificate Generator cannot be activated due to server compatibility issues: ' .
								implode( ', ', $compatibility['errors'] );

				update_option( 'certificate_generator_activation_error', $error_message );
				deactivate_plugins( plugin_basename( __FILE__ ) );
				wp_die( esc_html( $error_message ) . '<br><br><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Return to Plugins</a>' );
			}

			update_option( 'certificate_generator_compatibility', $compatibility );
		}

		global $wpdb;

		// Verify required WordPress functions exist
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Create the primary database table
		$table_name      = $wpdb->prefix . 'certificate_generator';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            student_name varchar(255) NOT NULL,
            certificate_data text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            issued_at datetime NULL,
            expires_at datetime NULL,
            updated_at datetime NULL,
            generated_via enum('manual','bulk','api','automatic') DEFAULT 'manual',
            serial_number varchar(50) NULL,
            certificate_type varchar(100) NULL,
            revoked_at datetime NULL,
            revoked_reason varchar(255) NULL,
            PRIMARY KEY (id),
            INDEX idx_issued_at (issued_at),
            INDEX idx_expires_at (expires_at),
            INDEX idx_serial_number (serial_number),
            INDEX idx_certificate_type (certificate_type)
        ) $charset_collate;";

		$result = dbDelta( $sql );

		// Check if table was created successfully
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) {
			// Try alternative method
			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) != $table_name ) {
				throw new Exception( 'Failed to create required database table. Please check database permissions.' );
			}
		}

		// Create email log table
		if ( function_exists( 'certificate_generator_create_email_log_table' ) ) {
			certificate_generator_create_email_log_table();
		}

		// Create email queue table
		if ( function_exists( 'certificate_generator_create_email_queue_table' ) ) {
			certificate_generator_create_email_queue_table();
		}

		// Set plugin version
		update_option( 'certificate_generator_version', CERTIFICATE_GENERATOR_VERSION );

		// Set activation timestamp
		update_option( 'certificate_generator_activated_at', current_time( 'timestamp' ) );

		// Show welcome banner on next admin load
		delete_option( 'certificate_generator_welcome_dismissed' );
		set_transient( 'certificate_generator_activation_redirect', 1, 30 );

		// Determine installation mode based on server capabilities
		$installation_mode = 'minimal'; // Safe default
		if ( function_exists( 'certificate_generator_get_installation_recommendation' ) ) {
			$recommendation    = certificate_generator_get_installation_recommendation();
			$installation_mode = $recommendation['mode'] === 'not_compatible' ? 'minimal' : $recommendation['mode'];
		}

		// Set initial settings with safe defaults
		$default_settings = array(
			'installation_mode'    => $installation_mode,
			'max_memory_usage'     => '64M',
			'enable_error_logging' => true,
			'font_loading_mode'    => 'on_demand',
			'debug_mode'           => false,
		);

		if ( ! get_option( 'certificate_generator_settings' ) ) {
			update_option( 'certificate_generator_settings', $default_settings );
		}

		// Flush rewrite rules
		flush_rewrite_rules();

		// Clear any previous activation errors
		delete_option( 'certificate_generator_activation_error' );

		certificate_generator_debug_log( 'Certificate Generator: Plugin activated successfully.' );

	} catch ( Exception $e ) {
		certificate_generator_debug_log( 'Certificate Generator Activation Error: ' . $e->getMessage() );

		update_option(
			'certificate_generator_activation_error',
			array(
				'message'      => $e->getMessage(),
				'timestamp'    => current_time( 'mysql' ),
				'php_version'  => PHP_VERSION,
				'wp_version'   => get_bloginfo( 'version' ),
				'memory_limit' => ini_get( 'memory_limit' ),
			)
		);

		deactivate_plugins( plugin_basename( __FILE__ ) );

		$error_message  = 'Certificate Generator could not be activated: ' . $e->getMessage();
		$error_message .= '<br>• PHP Version: ' . PHP_VERSION;
		$error_message .= '<br>• WordPress Version: ' . get_bloginfo( 'version' );
		$error_message .= '<br>• Memory Limit: ' . ini_get( 'memory_limit' );
		$error_message .= '<br><br><a href="' . admin_url( 'plugins.php' ) . '" class="button">Return to Plugins</a>';

		wp_die( esc_html( $error_message ) );
	}
}

// Register the activation hook
register_activation_hook( __FILE__, 'certificate_generator_activate' );

// Redirect to Getting Started page after activation (fires once, then clears)
add_action(
	'admin_init',
	function () {
		if ( ! get_transient( 'certificate_generator_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'certificate_generator_activation_redirect' );
		if ( isset( $_GET['activate-multi'] ) ) {
			return; // skip on bulk activate
		}
		wp_safe_redirect( admin_url( 'admin.php?page=cg-documentation&tab=getting-started' ) );
		exit;
	}
);

// Plugin deactivation hook
function certificate_generator_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( 'certificate_generator_cleanup_logs' );
	if ( class_exists( 'CertificateGenerator_Cron_Jobs' ) ) {
		CertificateGenerator_Cron_Jobs::deactivate();
	}
	wp_clear_scheduled_hook( 'cg_reset_monthly_usage' ); // scheduled by versions before 7.6
}
register_deactivation_hook( __FILE__, 'certificate_generator_deactivate' );

// Plugin uninstall hook
function certificate_generator_uninstall() {
	// If the user chose to keep data, stop here — all tables and options are preserved.
	if ( get_option( 'certificate_generator_keep_data_on_uninstall', '1' ) === '1' ) {
		return;
	}

	global $wpdb;

	// Drop legacy tables.
	$legacy_tables = array(
		$wpdb->prefix . 'certificate_generator',
		$wpdb->prefix . 'cert_email_logs',
		$wpdb->prefix . 'cert_email_queue',
	);

	// Drop new cg_* custom tables.
	$cg_prefix = $wpdb->prefix . 'cg_';
	$cg_tables = array(
		$cg_prefix . 'students',
		$cg_prefix . 'teachers',
		$cg_prefix . 'schools',
		$cg_prefix . 'certificate_templates',
		$cg_prefix . 'certificates',
		$cg_prefix . 'email_logs',
		$cg_prefix . 'email_queue',
		$cg_prefix . 'student_certificates',
		$cg_prefix . 'teacher_certificates',
		$cg_prefix . 'settings',
		$cg_prefix . 'migrations',
	);

	foreach ( array_merge( $legacy_tables, $cg_tables ) as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `$table`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// Delete all plugin options.
	$options = array(
		'certificate_generator_version',
		'certificate_generator_activated_at',
		'certificate_generator_activation_error',
		'certificate_generator_compatibility',
		'certificate_generator_missing_files',
		'certificate_generator_rate_limits',
		'certificate_generator_settings_email',
		'certificate_generator_custom_tables_version',
		'certificate_generator_db_version',
		'certificate_generator_migration_v7_done',
		'certificate_generator_migration_scheduled_status_done',
		'certificate_generator_keep_data_on_uninstall',
		'certificate_generator_welcome_dismissed',
		'certificate_generator_email_transport',
		'certificate_generator_email_from_name',
		'certificate_generator_email_from_email',
		'certificate_generator_email_subject',
		'certificate_generator_email_body',
		'certificate_generator_smtp_host',
		'certificate_generator_smtp_port',
		'certificate_generator_smtp_username',
		'certificate_generator_smtp_password',
		'certificate_generator_smtp_encryption',
		'certificate_generator_serial_prefix',
		'certificate_generator_serial_length',
		'certificate_generator_serial_suffix',
		'certificate_generator_serial_reset_period',
		'certificate_generator_serial_include_date',
	);
	foreach ( $options as $opt ) {
		delete_option( $opt );
	}

	// Remove scheduled cron events.
	wp_clear_scheduled_hook( 'certificate_generator_process_email_queue' );
	wp_clear_scheduled_hook( 'certificate_generator_bulk_generate_serials' );
	wp_clear_scheduled_hook( 'certificate_generator_publish_scheduled_templates' );
	wp_clear_scheduled_hook( 'certificate_generator_cleanup_qr_codes' );
	wp_clear_scheduled_hook( 'certificate_generator_check_expiring_certificates' );
	wp_clear_scheduled_hook( 'certificate_generator_cleanup_old_certificates' );
}
register_uninstall_hook( __FILE__, 'certificate_generator_uninstall' );

// ── Block public access to legacy CPT slugs (students/teachers/schools) ──────
add_action(
	'template_redirect',
	function () {
		if ( is_singular( array( 'students', 'teachers', 'schools', 'certificates' ) ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}
);

// ── Plugins-page modal: ask "Keep data?" before deletion ─────────────────────
add_action(
	'admin_footer-plugins.php',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$plugin_file = plugin_basename( __FILE__ );
		$nonce       = wp_create_nonce( 'certificate_generator_set_keep_data' );
		?>
	<style>
	/* plugins.php doesn't load the admin UI kit, so this dialog carries its own styles. */
	#cg-uninstall-modal {
		border:0; border-radius:8px; padding:32px 36px; max-width:420px; width:90%;
		box-shadow:0 8px 40px rgba(0,0,0,.2); text-align:center;
	}
	#cg-uninstall-modal::backdrop { background:rgba(0,0,0,.6); }
	#cg-uninstall-modal h2 { margin:0 0 12px; font-size:20px; color:#1d2327; }
	#cg-uninstall-modal p  { color:#50575e; margin:0 0 24px; line-height:1.6; }
	#cg-uninstall-modal .cg-modal-btns { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
	#cg-uninstall-modal .cg-modal-btns button { padding:10px 22px; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; border:none; }
	#cg-btn-keep   { background:#2271b1; color:#fff; }
	#cg-btn-delete { background:#d63638; color:#fff; }
	#cg-btn-cancel { background:#f0f0f1; color:#2c3338; }
	</style>

	<dialog id="cg-uninstall-modal" aria-labelledby="cg-uninstall-title">
		<form method="dialog">
			<h2 id="cg-uninstall-title"><?php esc_html_e( 'Uninstalling Certificate Generator', 'certificate-generator' ); ?></h2>
			<p><?php esc_html_e( 'Do you want to keep your certificate data (students, templates, email logs)?', 'certificate-generator' ); ?><br>
			<small><?php esc_html_e( 'If you keep the data and reinstall the plugin, everything will still be there.', 'certificate-generator' ); ?></small></p>
			<div class="cg-modal-btns">
				<button id="cg-btn-keep" value="keep"><?php esc_html_e( 'Yes, Keep Data', 'certificate-generator' ); ?></button>
				<button id="cg-btn-delete" value="delete"><?php esc_html_e( 'No, Delete Everything', 'certificate-generator' ); ?></button>
				<button id="cg-btn-cancel" value="cancel" autofocus><?php esc_html_e( 'Cancel', 'certificate-generator' ); ?></button>
			</div>
		</form>
	</dialog>

	<script>
	(function($) {
		var pluginFile = <?php echo wp_json_encode( $plugin_file ); ?>;
		var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
		var dialog     = document.getElementById('cg-uninstall-modal');
		var deleteHref = null;

		// Find and intercept the Delete link for this plugin.
		$('tr[data-plugin="' + pluginFile + '"] .delete a, ' +
			'tr[data-slug="certificate-generator-v7"] .delete a').on('click', function(e) {
			if (!dialog.showModal) { return; } // No <dialog> support: fall back to WP's own confirm.
			e.preventDefault();
			deleteHref = this.href;
			dialog.returnValue = '';
			dialog.showModal();
		});

		// Backdrop click = cancel.
		dialog.addEventListener('click', function(e) { if (e.target === dialog) { dialog.close('cancel'); } });

		// Esc, Cancel and backdrop all land here with returnValue '' or 'cancel'.
		dialog.addEventListener('close', function() {
			var choice = dialog.returnValue;
			if (!deleteHref || (choice !== 'keep' && choice !== 'delete')) { deleteHref = null; return; }
			$.post(ajaxurl, {
				action : 'certificate_generator_set_keep_data',
				keep   : choice === 'keep' ? '1' : '0',
				nonce  : nonce
			}).always(function() {
				window.location.href = deleteHref;
			});
		});
	})(jQuery);
	</script>
		<?php
	}
);

// AJAX: store the keep-data preference before WP proceeds with deletion.
add_action(
	'wp_ajax_certificate_generator_set_keep_data',
	function () {
		check_ajax_referer( 'certificate_generator_set_keep_data', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$keep = ( sanitize_text_field( wp_unslash( $_POST['keep'] ?? '1' ) ) === '0' ) ? '0' : '1';
		update_option( 'certificate_generator_keep_data_on_uninstall', $keep );
		wp_send_json_success();
	}
);

/**
 * Feature toggles shown under Settings → Features, as flag => label. The Pro add-on
 * adds its integration flags through the `certificate_generator_feature_flags` filter.
 */
function certificate_generator_feature_flags(): array {
	return (array) apply_filters(
		'certificate_generator_feature_flags',
		array(
			'CG_USE_BADGES'            => __( 'Badges system', 'certificate-generator' ),
			'CG_USE_EVENTS'            => __( 'Events system', 'certificate-generator' ),
			'CG_USE_RENEWAL_REMINDERS' => __( 'Renewal reminders', 'certificate-generator' ),
		)
	);
}

// AJAX: save DB-backed feature toggle overrides — see Config::flag().
add_action(
	'wp_ajax_certificate_generator_save_feature_toggles',
	function () {
		check_ajax_referer( 'certificate_generator_save_feature_toggles', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$allowed_flags = array_keys( certificate_generator_feature_flags() );
		$raw_flags = map_deep( (array) wp_unslash( $_POST['flags'] ?? array() ), 'sanitize_text_field' );
		$toggles   = array();
		foreach ( $allowed_flags as $flag_name ) {
			if ( isset( $raw_flags[ $flag_name ] ) ) {
				$toggles[ $flag_name ] = sanitize_text_field( $raw_flags[ $flag_name ] ) === '1';
			}
		}
		update_option( 'certificate_generator_feature_toggles', $toggles );
		wp_send_json_success();
	}
);

// Plugin update logic + one-time v7 migration
function certificate_generator_update_check() {
	$current_version = get_option( 'certificate_generator_version', '' );
	$new_version     = CERTIFICATE_GENERATOR_VERSION;

	if ( $current_version !== $new_version ) {
		update_option( 'certificate_generator_version', $new_version );
	}

}
add_action( 'plugins_loaded', 'certificate_generator_update_check' );

// Add admin notices for compatibility and missing files
function certificate_generator_admin_notices() {
	// Check for compatibility warnings
	$compatibility = get_option( 'certificate_generator_compatibility' );
	if ( $compatibility && ! empty( $compatibility['warnings'] ) ) {
		echo '<div class="notice notice-warning is-dismissible">';
		echo '<p><strong>Certificate Generator Warnings:</strong></p>';
		echo '<ul>';
		foreach ( $compatibility['warnings'] as $warning ) {
			echo '<li>' . esc_html( $warning ) . '</li>';
		}
		echo '</ul>';
		if ( ! empty( $compatibility['recommendations'] ) ) {
			echo '<p><strong>Recommendations:</strong></p>';
			echo '<ul>';
			foreach ( $compatibility['recommendations'] as $recommendation ) {
				echo '<li>' . esc_html( $recommendation ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	// Check for missing optional files
	$missing_files = get_option( 'certificate_generator_missing_files' );
	if ( ! empty( $missing_files ) ) {
		echo '<div class="notice notice-info is-dismissible">';
		echo '<p><strong>Certificate Generator:</strong> Some optional features are unavailable due to missing files:</p>';
		echo '<ul>';
		foreach ( $missing_files as $file ) {
			echo '<li>' . esc_html( $file ) . '</li>';
		}
		echo '</ul>';
		echo '<p>You can still use the plugin, but some features may be limited. Please re-upload the complete plugin files if you need these features.</p>';
		echo '</div>';
	}

	// Show installation mode notice
	$settings = get_option( 'certificate_generator_settings' );
	if ( $settings && isset( $settings['installation_mode'] ) && $settings['installation_mode'] === 'minimal' ) {
		echo '<div class="notice notice-info">';
		echo '<p><strong>Certificate Generator:</strong> Running in minimal mode due to server limitations. ';
		echo 'Some advanced features are disabled to ensure compatibility with your hosting environment.</p>';
		echo '</div>';
	}
}
add_action( 'admin_notices', 'certificate_generator_admin_notices' );

// Add memory usage monitoring
function certificate_generator_check_memory_usage() {
	if ( function_exists( 'memory_get_usage' ) && function_exists( 'memory_get_peak_usage' ) ) {
		$current_memory = memory_get_usage( true );
		$peak_memory    = memory_get_peak_usage( true );
		$memory_limit   = ini_get( 'memory_limit' );

		// Convert memory limit to bytes for comparison
		$memory_limit_bytes = certificate_generator_convert_to_bytes( $memory_limit );

		// Log if memory usage is getting high (80% of limit)
		if ( $memory_limit_bytes > 0 && $current_memory > ( $memory_limit_bytes * 0.8 ) ) {
			certificate_generator_debug_log(
				sprintf(
					'Certificate Generator: High memory usage detected. Current: %s, Peak: %s, Limit: %s',
					certificate_generator_format_bytes( $current_memory ),
					certificate_generator_format_bytes( $peak_memory ),
					$memory_limit
				)
			);
		}
	}
}

// Helper function to convert memory string to bytes
function certificate_generator_convert_to_bytes( $size_str ) {
	if ( empty( $size_str ) || $size_str === '-1' ) {
		return -1; // Unlimited
	}

	$size_str  = trim( $size_str );
	$last_char = strtolower( $size_str[ strlen( $size_str ) - 1 ] );
	$size      = (int) $size_str;

	switch ( $last_char ) {
		case 'g':
			$size *= 1024;
		case 'm':
			$size *= 1024;
		case 'k':
			$size *= 1024;
	}

	return $size;
}

// Helper function to format bytes for human reading
function certificate_generator_format_bytes( $bytes ) {
	if ( $bytes == -1 ) {
		return 'Unlimited';
	}

	if ( $bytes >= 1024 * 1024 * 1024 ) {
		return round( $bytes / ( 1024 * 1024 * 1024 ), 1 ) . 'GB';
	} elseif ( $bytes >= 1024 * 1024 ) {
		return round( $bytes / ( 1024 * 1024 ), 1 ) . 'MB';
	} elseif ( $bytes >= 1024 ) {
		return round( $bytes / 1024, 1 ) . 'KB';
	} else {
		return $bytes . ' bytes';
	}
}

// Helper function to get current memory usage formatted
function certificate_generator_get_memory_usage() {
	if ( function_exists( 'memory_get_usage' ) ) {
		return certificate_generator_format_bytes( memory_get_usage( true ) );
	}
	return 'Unknown';
}

// Global debug logging — delegates to error_log when WP_DEBUG is on.
function certificate_generator_log_debug( $message ) {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		certificate_generator_debug_log( '[Certificate Generator] ' . $message );
	}
}

// Add memory monitoring to admin pages
add_action( 'admin_init', 'certificate_generator_check_memory_usage' );

// Redirect removed standalone pages to the main settings page.
add_action(
	'admin_init',
	function () {
		if ( ! current_user_can( 'manage_options' ) || empty( $_GET['page'] ) ) {
			return;
		}
		$page = sanitize_text_field( wp_unslash( $_GET['page'] ) );
		if ( $page === 'cg-email-settings' ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=certificate_generator_settings&tab=templates' ) );
			exit;
		}
		if ( $page === 'cert-gen-debug-dashboard' || $page === 'cert-gen-recovery' ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=certificate_generator_settings' ) );
			exit;
		}
	}
);

// ── Centralized page renderers ──

function certificate_generator_render_bulk_import_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions' );
	}
	require_once CERTIFICATE_GENERATOR_PATH . 'includes/Services/bulk-import.php';
	$tab  = sanitize_key( $_GET['tab'] ?? 'students' );
	$tabs = array(
		'students'     => 'Students',
		'teachers'     => 'Teachers',
		'schools'      => 'Schools',
		'certificates' => 'Certificates',
	);
	$base = admin_url( 'admin.php?page=cg-bulk-import' );
	?>
	<div class="wrap">
		<?php
		certificate_generator_ui_page_header( 'Bulk Import', 'Upload a CSV to add or update records in bulk.' );
		certificate_generator_ui_tabs( $tabs, $tab, $base );
		?>
		<div class="cg-tab-body">
			<?php
			switch ( $tab ) {
				case 'students':
					certificate_generator_bulk_import_students();
					break;
				case 'teachers':
					certificate_generator_bulk_import_teachers();
					break;
				case 'schools':
					certificate_generator_bulk_import_schools();
					break;
				case 'certificates':
					certificate_generator_bulk_import_certificates();
					break;
				default:
					certificate_generator_bulk_import_students();
			}
			if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
				// Turn the POST history entry into a GET so F5 doesn't re-upload the CSV.
				echo '<script>history.replaceState && history.replaceState(null, "", location.href);</script>';
			}
			?>
		</div>
	</div>
	<?php
}

function certificate_generator_render_bulk_export_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions' );
	}
	require_once CERTIFICATE_GENERATOR_PATH . 'includes/Services/bulk-export.php';
	$tab  = sanitize_key( $_GET['tab'] ?? 'students' );
	$tabs = array(
		'students'     => 'Students',
		'teachers'     => 'Teachers',
		'schools'      => 'Schools',
		'certificates' => 'Certificates',
	);
	$base = admin_url( 'admin.php?page=cg-bulk-export' );
	?>
	<div class="wrap">
		<?php
		certificate_generator_ui_page_header( 'Bulk Export', 'Download records as CSV.' );
		certificate_generator_ui_tabs( $tabs, $tab, $base );
		?>
		<div class="cg-tab-body">
			<?php
			switch ( $tab ) {
				case 'students':
					certificate_generator_render_bulk_export_students_page();
					break;
				case 'teachers':
					certificate_generator_render_bulk_export_teachers_page();
					break;
				case 'schools':
					certificate_generator_render_bulk_export_schools_page();
					break;
				case 'certificates':
					certificate_generator_render_bulk_export_certificates_page();
					break;
				default:
					certificate_generator_render_bulk_export_students_page();
			}
			?>
		</div>
	</div>
	<?php
}

function certificate_generator_render_bulk_serials_page(): void {
	$instance = CertificateGenerator_Bulk_Serial_Generator::get_instance();
	$instance->render_bulk_serial_page();
}

function certificate_generator_render_bulk_send_page(): void {
	if ( function_exists( 'certificate_generator_bulk_send_page' ) ) {
		certificate_generator_bulk_send_page();
	} else {
		echo '<div class="wrap"><h1>Bulk Send Certificates</h1><p>Bulk send functionality is not available.</p></div>';
	}
}

function certificate_generator_render_email_logs_page(): void {
	if ( function_exists( 'certificate_generator_email_logs_page' ) ) {
		certificate_generator_email_logs_page();
	} else {
		echo '<div class="wrap"><h1>Email Logs</h1><p>Email logs functionality is not available.</p></div>';
	}
}

function certificate_generator_render_analytics_page(): void {
	if ( class_exists( 'CertificateGenerator_Analytics_Dashboard' ) ) {
		$instance = CertificateGenerator_Analytics_Dashboard::get_instance();
		$instance->render_analytics_page();
	} else {
		echo '<div class="wrap"><h1>Analytics</h1><p>Analytics functionality is not available.</p></div>';
	}
}

function certificate_generator_render_serial_settings_page(): void {
	if ( class_exists( 'CertificateGenerator_Admin_Settings' ) ) {
		$instance = new CertificateGenerator_Admin_Settings();
		$instance->render_serial_settings_page();
	} else {
		echo '<div class="wrap"><h1>Serial Settings</h1><p>Serial settings functionality is not available.</p></div>';
	}
}
?>