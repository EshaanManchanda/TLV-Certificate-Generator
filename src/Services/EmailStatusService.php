<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

use CertificateGenerator\Core\Config;
use CertificateGenerator\Database\EmailLogRepository;
use CertificateGenerator\Database\QueueRepository;

/**
 * Merged badge-status source: latest event across cert_email_logs + cert_email_queue.
 * Replaces the old logs-only, status='sent'-only lookup so the badge reflects the
 * true current state (Sent / Failed / Sending / Queued / NotSent).
 *
 * When CG_USE_REPOSITORIES is on, queries go through EmailLogRepository /
 * QueueRepository. Flag off → legacy global $wpdb path (unchanged behaviour).
 */
class EmailStatusService {

	const STATUS_SENT     = 'Sent';
	const STATUS_FAILED   = 'Failed';
	const STATUS_SENDING  = 'Sending';
	const STATUS_QUEUED   = 'Queued';
	const STATUS_NOT_SENT = 'NotSent';

	/**
	 * Batched status lookup — single query pair, no N+1.
	 *
	 * @param string[] $emails Unique email addresses for the current page.
	 * @return array<string, array{status:string, last_error:string, attempts:int}>
	 *         Keyed by email address.
	 */
	/**
	 * Object-cache group for per-email badge entries.
	 * Invalidated by InvalidateStatusCacheListener on cg_email_sent.
	 */
	private const CACHE_GROUP = 'cg_email_status';
	private const CACHE_TTL   = 300; // 5 minutes

	public static function getBadgeStatuses( array $emails ): array {
		$emails = array_values( array_unique( array_filter( $emails ) ) );
		if ( empty( $emails ) ) {
			return array();
		}

		$result = array_fill_keys(
			$emails,
			array(
				'status'     => self::STATUS_NOT_SENT,
				'last_error' => '',
				'attempts'   => 0,
			)
		);

		// ── Object cache layer (only when event system is active) ────────────────
		$use_cache = Config::flag( 'CG_USE_EVENTS' );
		$misses    = $emails; // default: fetch all

		if ( $use_cache ) {
			$misses = array();
			foreach ( $emails as $email ) {
				$cached = wp_cache_get( strtolower( $email ), self::CACHE_GROUP );
				if ( $cached !== false ) {
					$result[ $email ] = $cached;
				} else {
					$misses[] = $email;
				}
			}
			if ( empty( $misses ) ) {
				return $result;
			}
		}

		if ( Config::flag( 'CG_USE_REPOSITORIES' ) ) {
			[ $log_latest, $queue_latest ] = self::fetch_via_repos( $misses );
		} else {
			[ $log_latest, $queue_latest ] = self::fetch_via_wpdb( $misses );
		}

		foreach ( $misses as $email ) {
			$log   = $log_latest[ $email ] ?? null;
			$queue = $queue_latest[ $email ] ?? null;

			if ( ! $log && ! $queue ) {
				// Keep default NotSent; still cache it so we don't re-query.
			} else {
				$log_ts   = $log ? (int) strtotime( $log['created_at'] ) : 0;
				$queue_ts = $queue ? (int) strtotime( $queue['updated_at'] ) : 0;
				$use_q    = $queue_ts >= $log_ts;

				if ( $use_q && $queue ) {
					switch ( $queue['status'] ) {
						case 'sent':
							$result[ $email ]['status'] = self::STATUS_SENT;
							break;
						case 'failed':
							$result[ $email ]['status']     = self::STATUS_FAILED;
							$result[ $email ]['last_error'] = $queue['error_message'] ?? '';
							$result[ $email ]['attempts']   = (int) ( $queue['attempts'] ?? 0 );
							break;
						case 'sending':
							$result[ $email ]['status'] = self::STATUS_SENDING;
							break;
						default:
							$result[ $email ]['status'] = self::STATUS_QUEUED;
							break;
					}
				} elseif ( $log ) {
					switch ( $log['status'] ) {
						case 'sent':
							$result[ $email ]['status'] = self::STATUS_SENT;
							break;
						case 'failed':
						case 'bounced':
							$result[ $email ]['status']     = self::STATUS_FAILED;
							$result[ $email ]['last_error'] = $log['error_message'] ?? '';
							break;
						case 'queued':
							$result[ $email ]['status'] = self::STATUS_QUEUED;
							break;
						default:
							break;
					}
				}
			}

			// Populate cache for this miss so subsequent requests skip the DB.
			if ( $use_cache ) {
				wp_cache_set( strtolower( $email ), $result[ $email ], self::CACHE_GROUP, self::CACHE_TTL );
			}
		}

		return $result;
	}

	// ── Fetch helpers ─────────────────────────────────────────────────────────

	/** @return array{array, array}  [log_latest_by_email, queue_latest_by_email] */
	private static function fetch_via_repos( array $emails ): array {
		$log_rows   = ( new EmailLogRepository() )->find_by_emails( $emails );
		$queue_rows = ( new QueueRepository() )->find_by_emails( $emails );
		return array(
			self::key_first_by_email( $log_rows, 'recipient_email' ),
			self::key_first_by_email( $queue_rows, 'recipient_email' ),
		);
	}

	/** @return array{array, array} */
	private static function fetch_via_wpdb( array $emails ): array {
		global $wpdb;
		$log_table   = $wpdb->prefix . 'cert_email_logs';
		$queue_table = $wpdb->prefix . 'cert_email_queue';
		$ph          = implode( ',', array_fill( 0, count( $emails ), '%s' ) );

		$log_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT recipient_email, status, error_message, sent_at AS created_at FROM $log_table WHERE recipient_email IN ($ph) ORDER BY sent_at DESC",
				...$emails
			),
			ARRAY_A
		) ?: array();

		$queue_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				"SELECT recipient_email, status, error_message, attempts, updated_at FROM $queue_table WHERE recipient_email IN ($ph) ORDER BY updated_at DESC",
				...$emails
			),
			ARRAY_A
		) ?: array();

		return array(
			self::key_first_by_email( $log_rows, 'recipient_email' ),
			self::key_first_by_email( $queue_rows, 'recipient_email' ),
		);
	}

	/** Deduplicate rows: keep first occurrence (newest) per email key. */
	private static function key_first_by_email( array $rows, string $col ): array {
		$map = array();
		foreach ( $rows as $r ) {
			$key = $r[ $col ] ?? '';
			if ( $key !== '' && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $r;
			}
		}
		return $map;
	}
}
