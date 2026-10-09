<?php
declare(strict_types=1);

namespace CertificateGenerator\Core;

use CertificateGenerator\Database\Migrations\MigrationRunner;
use CertificateGenerator\Email\Mailer;
use CertificateGenerator\Services\EmailService;
use CertificateGenerator\Services\QRCodeService;
use CertificateGenerator\Services\SerialNumberService;
use CertificateGenerator\Services\SettingsService;

/**
 * Main plugin class. Handles lifecycle, service registration, and bootstrapping.
 */
class Plugin {

	private Container $container;

	public function __construct() {
		$this->container = new Container();
	}

	public function boot(): void {
		SettingsService::migrate_legacy();
		if ( class_exists( '\CertificateGenerator\Database\Migrations\MigrationRunner' ) ) {
			$runner = new MigrationRunner();
			if ( $runner->needs_migration() ) {
				$runner->run();
			}
		}
		if ( get_option( 'cg_defaults_seeded' ) !== '2' ) {
			SettingsService::seed_defaults();
			update_option( 'cg_defaults_seeded', '2', 'no' );
		}
		$this->register_services();
		$this->register_hooks();
	}

	private function register_services(): void {
		$this->container->singleton(
			Container::class,
			function () {
				return $this->container;
			}
		);

		$this->container->singleton(
			SerialNumberService::class,
			function () {
				return new SerialNumberService();
			}
		);

		$this->container->singleton(
			QRCodeService::class,
			function () {
				return new QRCodeService();
			}
		);

		$this->container->singleton(
			Mailer::class,
			function () {
				return Mailer::make();
			}
		);

		$this->container->singleton(
			EmailService::class,
			function ( $c ) {
				return new EmailService( $c->make( Mailer::class ) );
			}
		);
	}

	private function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_api_routes' ) );

		if ( Config::flag( 'CG_USE_EVENTS' ) ) {
			$log_listener   = new \CertificateGenerator\Listeners\LogEmailListener();
			$analytics      = new \CertificateGenerator\Listeners\AnalyticsListener();
			$cache_listener = new \CertificateGenerator\Listeners\InvalidateStatusCacheListener();

			// Priority 10: log first (row must exist before analytics reads counters).
			add_action( 'cg_email_sent', array( $log_listener, 'handle' ), 10 );
			add_action( 'cg_email_sent', array( $analytics, 'handle' ), 20 );
			add_action( 'cg_email_sent', array( $cache_listener, 'handle' ), 30 );
		}

		// Email Logs: Resend one / Resend all failed.
		\CertificateGenerator\Email\EmailResender::register();

		// Template editor: "Email me a test certificate".
		\CertificateGenerator\Admin\TestCertificate::register();

		// Fonts page "Download sample PDF". admin-post requests skip admin_menu, so this can't live in FontsPage::register().
		add_action( 'admin_post_cg_font_sample', array( \CertificateGenerator\Admin\Pages\FontsPage::class, 'stream_sample_pdf' ) );

		if ( Config::flag( 'CG_USE_BADGES' ) ) {
			$badge_listener = new \CertificateGenerator\Listeners\BadgeGenerationListener();
			add_action( 'cg_certificate_generated', array( $badge_listener, 'handle' ), 10, 2 );
		}
	}

	public function register_api_routes(): void {
		$serial_service = $this->container->make( SerialNumberService::class );
		$serial_service->register_api_routes();
	}

	public static function activate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( class_exists( '\CertificateGenerator\Database\Migrations\MigrationRunner' ) ) {
			$runner = new MigrationRunner();
			$runner->run();
		} elseif ( class_exists( '\CG_Migrator' ) ) {
			\CG_Migrator::run();
		}

		// Pre-create centralized certificate storage folder.
		wp_mkdir_p( wp_upload_dir()['basedir'] . '/cg_certificates' );

		// Seed default options so email works out of the box.
		SettingsService::seed_defaults();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( class_exists( '\CG_Cron_Jobs' ) ) {
			\CG_Cron_Jobs::deactivate();
		}

		flush_rewrite_rules();
	}

	public function get_container(): Container {
		return $this->container;
	}
}
