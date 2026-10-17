<?php
/**
 * The 5-minute schedule's label must not load translations before init: another plugin's
 * wp_schedule_event() on plugins_loaded runs this filter that early, and WordPress 6.7+
 * logs a _load_textdomain_just_in_time notice (the test framework fails on it).
 */
class CronScheduleLabelTest extends WP_UnitTestCase {

	public function test_label_before_init_does_not_translate(): void {
		global $wp_actions, $l10n, $l10n_unloaded;
		$init = $wp_actions['init'];
		unset( $wp_actions['init'], $l10n['certificate-generator'], $l10n_unloaded['certificate-generator'] );
		try {
			$schedules = certificate_generator_add_cron_schedules( array() );
		} finally {
			$wp_actions['init'] = $init;
		}
		$this->assertSame( 300, $schedules['certificate_generator_5min']['interval'] );
		$this->assertSame( 'Every 5 Minutes (Certificate Generator)', $schedules['certificate_generator_5min']['display'] );
		$this->assertArrayNotHasKey( 'certificate-generator', $l10n, 'no translation loaded before init' );
	}
}
