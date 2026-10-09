<?php
declare(strict_types=1);

namespace CertificateGenerator\Listeners;

use CertificateGenerator\Database\EmailLogRepository;

/**
 * Writes one row to wp_cert_email_logs and busts sent-count transients
 * on the cg_email_sent action when CG_USE_EVENTS is enabled.
 *
 * This is the ONLY log writer when the flag is on — the legacy
 * certificate_generator_log_email() writes nothing and delegates here.
 */
class LogEmailListener {

	private EmailLogRepository $repo;

	public function __construct( ?EmailLogRepository $repo = null ) {
		$this->repo = $repo ?? new EmailLogRepository();
	}

	/**
	 * @param array $data  Column-keyed array matching wp_cert_email_logs schema.
	 */
	public function handle( array $data ): void {
		$this->repo->log_send( $data );

		if ( ( $data['status'] ?? '' ) === 'sent' ) {
			delete_transient( 'cg_sent_count_3600' );
			delete_transient( 'cg_sent_count_60' );
		}
	}
}
