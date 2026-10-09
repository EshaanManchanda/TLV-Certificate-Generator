<?php
declare(strict_types=1);

namespace CertificateGenerator\Listeners;

/**
 * Increments per-type sent/failed counters on certificate_generator_email_sent.
 *
 * Counters stored as transients:
 *   certificate_generator_analytics_sent_{certificate_type}   — rolling count
 *   certificate_generator_analytics_failed_{certificate_type} — rolling count
 *
 * Keys are sanitised to lowercase slug form. The transients have no TTL
 * (persistent) so they accumulate across cron runs.
 *
 * No reads happen here — pure increment, no coupling to other services.
 */
class AnalyticsListener {

	/**
	 * @param array $data  Column-keyed array: status, certificate_type.
	 */
	public function handle( array $data ): void {
		$status = $data['status'] ?? '';
		$type   = sanitize_key( $data['certificate_type'] ?? 'unknown' );

		if ( $status === 'sent' ) {
			$key   = "certificate_generator_analytics_sent_{$type}";
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1 );
		} elseif ( $status === 'failed' ) {
			$key   = "certificate_generator_analytics_failed_{$type}";
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1 );
		}
	}
}
