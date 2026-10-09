<?php

class SmokeTest extends WP_UnitTestCase {

	public function test_wordpress_loaded(): void {
		$this->assertTrue( function_exists( 'wp_insert_post' ) );
	}

	public function test_plugin_loaded(): void {
		$this->assertTrue( function_exists( 'certificate_generator_generate_pdf_from_row' ) );
	}

	public function test_custom_tables_exist(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cg_students';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}
}
