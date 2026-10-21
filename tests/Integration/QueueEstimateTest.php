<?php
/**
 * Queue ETA (certificate_generator_estimate_send_time()): one pending email used to show
 * "about 1 hour to finish" because the hours were rounded up.
 */
class QueueEstimateTest extends WP_UnitTestCase {

	public function test_estimate_follows_the_hourly_rate_with_a_five_minute_floor(): void {
		update_option( 'certificate_generator_rate_limits', array( 'emails_per_hour' => 80 ) );

		$seconds = static fn( int $n ) => strtotime( certificate_generator_estimate_send_time( $n )['estimated_completion'] . ' UTC' ) - time();

		$this->assertEqualsWithDelta( 5 * MINUTE_IN_SECONDS, $seconds( 1 ), 2, 'one email: one queue tick, not an hour' );
		$this->assertEqualsWithDelta( 30 * MINUTE_IN_SECONDS, $seconds( 40 ), 2, '40 emails at 80/hour' );
		$this->assertEqualsWithDelta( 2 * HOUR_IN_SECONDS, $seconds( 160 ), 2 );
	}
}
