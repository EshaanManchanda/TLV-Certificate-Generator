<?php
declare(strict_types=1);

namespace CertificateGenerator\Database;

/**
 * Repository for wp_cert_email_queue — the live email queue table.
 *
 * `certificate_id` column is the FK referencing wp_certificate_generator.id.
 */
class QueueRepository extends Repository {

	public function __construct( ?object $db = null ) {
		parent::__construct( 'cert_email_queue', $db );
	}

	public function find_pending( int $limit = 50 ): array {
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table}
				WHERE status = 'pending'
				AND (scheduled_time IS NULL OR scheduled_time <= %s)
				ORDER BY priority ASC, created_at ASC
				LIMIT %d",
				current_time( 'mysql' ),
				$limit
			),
			\ARRAY_A
		) ?: array();
	}

	public function find_by_certificate_id( int $cg_id ): array {
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE certificate_id = %d ORDER BY created_at DESC",
				$cg_id
			),
			\ARRAY_A
		) ?: array();
	}

	public function has_pending_or_sending( int $cg_id, string $email ): bool {
		$count = (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table}
				WHERE certificate_id = %d AND recipient_email = %s AND status IN ('pending','sending')",
				$cg_id,
				$email
			)
		);
		return $count > 0;
	}

	public function mark_sending( int $id ): bool {
		return $this->update( $id, array( 'status' => 'sending' ) );
	}

	public function mark_sent( int $id ): bool {
		return $this->update(
			$id,
			array(
				'status'  => 'sent',
				'sent_at' => current_time( 'mysql' ),
			)
		);
	}

	public function mark_failed( int $id, string $error = '' ): bool {
		return $this->update(
			$id,
			array(
				'status'        => 'failed',
				'error_message' => $error,
			)
		);
	}

	public function increment_attempts( int $id ): bool {
		$affected = $this->db->query(
			$this->db->prepare(
				"UPDATE {$this->table} SET attempts = attempts + 1 WHERE {$this->pk} = %d",
				$id
			)
		);
		return $affected !== false;
	}

	public function count_by_status( string $status ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
				$status
			)
		);
	}

	/**
	 * Batch fetch all queue rows for the given email addresses, ordered by updated_at DESC.
	 * Used by EmailStatusService to avoid N+1 queries.
	 *
	 * @param string[] $emails
	 * @return array<int, array>
	 */
	public function find_by_emails( array $emails ): array {
		if ( empty( $emails ) ) {
			return array();
		}
		$ph = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
		return $this->db->get_results(
			$this->db->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT recipient_email, status, error_message, attempts, updated_at FROM {$this->table} WHERE recipient_email IN ({$ph}) ORDER BY updated_at DESC",
				...$emails
			),
			\ARRAY_A
		) ?: array();
	}
}
