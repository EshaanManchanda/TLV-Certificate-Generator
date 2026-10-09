<?php
declare(strict_types=1);

namespace CertificateGenerator\Listeners;

/**
 * Deletes the per-email badge-status object-cache entry on cg_email_sent,
 * ensuring EmailStatusService::getBadgeStatuses() returns fresh data on
 * the next request.
 *
 * Cache group: cg_email_status  (same group used by EmailStatusService).
 * Key:         recipient_email  (lowercased for consistency).
 */
class InvalidateStatusCacheListener {

	/**
	 * @param array $data  Column-keyed array: recipient_email.
	 */
	public function handle( array $data ): void {
		$email = strtolower( trim( $data['recipient_email'] ?? '' ) );
		if ( $email !== '' ) {
			wp_cache_delete( $email, 'cg_email_status' );
		}
	}
}
