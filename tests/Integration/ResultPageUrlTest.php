<?php
/**
 * {result_link} page: the search page is used when there is one, "My Certificates" is created
 * when there is none, and a page saved later is picked up at once (not after the hourly cache).
 */
class ResultPageUrlTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 's' => '[certificate_generator_' ) ) as $page ) {
			wp_delete_post( $page->ID, true );
		}
		delete_transient( 'certificate_generator_result_page_url' );
	}

	public function test_without_a_page_one_is_created_for_my_certificates(): void {
		$page_id = certificate_generator_ensure_result_page();
		$this->assertGreaterThan( 0, $page_id );
		$this->assertSame( '[certificate_generator_my_certificates]', get_post( $page_id )->post_content );
		$this->assertSame( $page_id, certificate_generator_ensure_result_page(), 'run twice: no second page' );
		$this->assertStringStartsWith( get_permalink( $page_id ), certificate_generator_result_page_url( 'a@b.test' ) );
	}

	public function test_a_search_page_saved_later_is_used_at_once(): void {
		$this->assertStringContainsString( '/result/', certificate_generator_result_page_url( 'a@b.test' ), 'cached: no page yet' );

		$search = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '[certificate_generator_student_search]',
			)
		);
		$this->assertSame( add_query_arg( 'student_email', 'a%40b.test', get_permalink( $search ) ), certificate_generator_result_page_url( 'a@b.test' ) );
		$this->assertSame( $search, certificate_generator_ensure_result_page(), 'an existing search page is adopted, not duplicated' );
	}
}
