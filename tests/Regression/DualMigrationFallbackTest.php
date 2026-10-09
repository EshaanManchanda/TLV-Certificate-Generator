<?php
/**
 * Regression guard for the dual migration setup: Plugin::activate() tries the
 * namespaced MigrationRunner first and only falls back to the legacy
 * CertificateGenerator_Migrator if MigrationRunner doesn't exist (src/Core/Plugin.php). Since
 * both classes are intentionally kept (per standing instruction not to remove
 * SQL migrations), this pins the fallback *order* — if that check is ever
 * reordered or dropped, the legacy migrator (which never writes certificate_generator_db_version)
 * would silently take over and MigrationRunner's version tracking would stop
 * advancing.
 */
class DualMigrationFallbackTest extends WP_UnitTestCase {

	public function test_activate_prefers_migration_runner_over_legacy_migrator(): void {
		$this->assertTrue(
			class_exists( '\CertificateGenerator\Database\Migrations\MigrationRunner' ),
			'MigrationRunner must exist for this regression test to be meaningful.'
		);
		$this->assertTrue(
			class_exists( '\CertificateGenerator_Migrator' ),
			'CertificateGenerator_Migrator must exist for this regression test to be meaningful.'
		);

		delete_option( 'certificate_generator_db_version' );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		\CertificateGenerator\Core\Plugin::activate();

		// CertificateGenerator_Migrator never touches certificate_generator_db_version — only MigrationRunner does.
		// Landing on the runner's target version proves MigrationRunner ran, not the legacy fallback.
		$this->assertSame(
			( new \CertificateGenerator\Database\Migrations\MigrationRunner() )->get_target_version(),
			get_option( 'certificate_generator_db_version' )
		);
	}
}
