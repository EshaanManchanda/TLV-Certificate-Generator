<?php
/**
 * Migration 010 moves stored data from the old cg_ / cert_ names to certificate_generator_
 * without losing anything: settings, serial counters, persistent counters, queued cron events
 * and pages that use the old shortcodes.
 */
use CertificateGenerator\Database\Migrations\Migration010_PrefixRename;

class PrefixRenameMigrationTest extends WP_UnitTestCase {

	public function test_up_renames_stored_data_and_keeps_values(): void {
		update_option( 'cg_serial_prefix', 'OLY' );
		update_option( 'cg_serial_seq_all', 1306 );
		update_option( 'cg_serial_seq_goldtrophy', 8 );
		update_option( 'cg_extra_fields_participation', array( 'house' ) );
		set_transient( 'cg_analytics_sent_participation', 42 );
		set_transient( 'cg_unique_schools', array( 'stale cache' ) );
		update_option( 'certificate_generator_serial_prefix', 'CERT' ); // auto-created default: the site's value must win
		wp_schedule_single_event( time() + 3600, 'generate_certificates_background', array( 'post_id' => 7 ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => 'Check: [cg_verify_certificate] and [student_search title="Find"] but not [student_search_x]' ) );

		( new Migration010_PrefixRename() )->up();

		$this->assertSame( 'OLY', get_option( 'certificate_generator_serial_prefix' ) );
		$this->assertSame( 1306, (int) get_option( 'certificate_generator_serial_seq_all' ) );
		$this->assertSame( 8, (int) get_option( 'certificate_generator_serial_seq_goldtrophy' ) );
		$this->assertSame( array( 'house' ), get_option( 'certificate_generator_extra_fields_participation' ) );
		$this->assertSame( 42, (int) get_transient( 'certificate_generator_analytics_sent_participation' ) );
		foreach ( array( 'cg_serial_prefix', 'cg_serial_seq_all', 'cg_serial_seq_goldtrophy', 'cg_extra_fields_participation' ) as $old ) {
			$this->assertFalse( get_option( $old ), "$old should be gone" );
		}
		$this->assertFalse( get_transient( 'cg_unique_schools' ), 'caches are dropped' );

		$this->assertNotFalse( wp_next_scheduled( 'certificate_generator_generate_certificates_background', array( 'post_id' => 7 ) ), 'queued event moved with its args' );
		$this->assertFalse( wp_next_scheduled( 'generate_certificates_background', array( 'post_id' => 7 ) ) );

		$content = get_post( $page )->post_content;
		$this->assertStringContainsString( '[certificate_generator_verify_certificate]', $content );
		$this->assertStringContainsString( '[certificate_generator_student_search title="Find"]', $content );
		$this->assertStringContainsString( '[student_search_x]', $content, 'only exact tags are rewritten' );
	}

	public function test_second_run_changes_nothing(): void {
		update_option( 'cg_serial_seq_all', 50 );
		$m = new Migration010_PrefixRename();
		$m->up();
		update_option( 'certificate_generator_serial_seq_all', 51 ); // a certificate issued after the update
		$m->up();
		$this->assertSame( 51, (int) get_option( 'certificate_generator_serial_seq_all' ), 'a re-run must not touch already-renamed data' );
	}
}
