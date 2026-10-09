<?php
/**
 * Guards the standing "never delete/break migrations" constraint: confirms every
 * migration up to the runner's current target actually applied, and that
 * re-running the migration set is safe (each up() is self-guarded/idempotent).
 */
class MigrationRunnerTest extends WP_UnitTestCase {

	public function test_all_migrations_have_applied(): void {
		global $wpdb;

		$runner = new \CertificateGenerator\Database\Migrations\MigrationRunner();

		$this->assertSame( $runner->get_target_version(), $runner->get_current_version() );
		$this->assertFalse( $runner->needs_migration() );

		$this->assertContains( 'serial_number', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}certificate_generator", 0 ) );
		$this->assertContains( 'send_email', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_students", 0 ) );
		$this->assertContains( 'year', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_students", 0 ) );
		$this->assertContains( 'import_source', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_students", 0 ) );
		$this->assertContains( 'badge_template_url', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_certificate_templates", 0 ) );
		$this->assertContains( 'badge_path', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_certificates", 0 ) );
		$this->assertContains( 'event_id', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_students", 0 ) );
		$this->assertContains( 'event_id', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_teachers", 0 ) );
		$this->assertContains( 'event_id', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_schools", 0 ) );
		$this->assertContains( 'event_id', $wpdb->get_col( "DESCRIBE {$wpdb->prefix}cg_certificate_templates", 0 ) );
		$this->assertContains( 'idx_lookup', $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}cg_students", 2 ) );
		$this->assertContains( 'idx_type_date', $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}cg_certificate_templates", 2 ) );
		$this->assertContains( 'idx_name_type', $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}certificate_generator", 2 ) );
	}

	public function test_rerunning_migrations_is_idempotent(): void {
		$runner = new \CertificateGenerator\Database\Migrations\MigrationRunner();

		$runner->run();
		$runner->run();

		$this->assertSame( $runner->get_target_version(), $runner->get_current_version() );
	}
}
