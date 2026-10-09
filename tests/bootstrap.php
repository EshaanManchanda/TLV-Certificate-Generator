<?php
/**
 * PHPUnit bootstrap — boots the real WordPress test suite (wp-phpunit) against
 * the site's real WP core + a dedicated `wordpress_test` database, then loads
 * this plugin the same way WordPress would.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

$_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load the plugin under test, and create its custom tables, before WP's
 * test scaffolding takes its "clean install" snapshot.
 */
function _cg_manually_load_plugin() {
	require dirname( __DIR__ ) . '/certificate-generator.php';

	if ( function_exists( 'certificate_generator_activate' ) ) {
		certificate_generator_activate();
	}
	// Base schema must exist before migrations run against it — mirrors real
	// activation order (Plugin::boot() creates tables via a plugins_loaded
	// hook that's already fired by the time Plugin::activate() runs its
	// migrations). Plugin::activate() itself is skipped here since it gates
	// on current_user_can('activate_plugins'), which is false this early in
	// bootstrap (no user set yet) — call MigrationRunner directly instead.
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		\CertificateGenerator\Database\CustomTables::instance()->create_all();
	}
	if ( class_exists( '\CertificateGenerator\Database\Migrations\MigrationRunner' ) ) {
		( new \CertificateGenerator\Database\Migrations\MigrationRunner() )->run();
	}
}
tests_add_filter( 'muplugins_loaded', '_cg_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
