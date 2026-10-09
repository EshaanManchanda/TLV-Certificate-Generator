<?php
/**
 * Regression test for the DB-backed feature toggle override in Config::flag().
 * A value explicitly set via the `certificate_generator_feature_toggles` option (the Tools tab
 * UI) must win over a wp-config.php constant of the same name; an untouched
 * flag must still fall through to the constant, unaffected.
 */
class FeatureToggleTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'certificate_generator_feature_toggles' );
		parent::tear_down();
	}

	public function test_free_plugin_lists_no_integration_toggles(): void {
		$this->assertSame( array( 'CG_USE_BADGES', 'CG_USE_EVENTS', 'CG_USE_RENEWAL_REMINDERS' ), array_keys( certificate_generator_feature_flags() ) );
		$this->assertFalse( class_exists( '\CertificateGenerator\Integrations\LmsBackfill' ), 'LMS/WooCommerce code ships in the Pro add-on' );
	}

	public function test_db_override_wins_over_constant(): void {
		if ( ! defined( 'CG_USE_TUTOR_LMS_INTEGRATION' ) ) {
			define( 'CG_USE_TUTOR_LMS_INTEGRATION', true );
		}
		update_option( 'certificate_generator_feature_toggles', array( 'CG_USE_TUTOR_LMS_INTEGRATION' => false ) );

		$this->assertFalse( \CertificateGenerator\Core\Config::flag( 'CG_USE_TUTOR_LMS_INTEGRATION' ), 'DB toggle set to false must override the true constant' );
	}

	public function test_untouched_flag_falls_through_to_constant(): void {
		delete_option( 'certificate_generator_feature_toggles' );

		if ( ! defined( 'CG_USE_NEW_PDF' ) ) {
			define( 'CG_USE_NEW_PDF', false );
		}
		$this->assertSame( defined( 'CG_USE_NEW_PDF' ) && (bool) constant( 'CG_USE_NEW_PDF' ), \CertificateGenerator\Core\Config::flag( 'CG_USE_NEW_PDF' ), 'A flag never touched by the toggle UI must behave exactly as before' );
	}
}
