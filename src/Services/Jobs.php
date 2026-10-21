<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

use CertificateGenerator\Database\CustomTables;

/**
 * Background bulk jobs in wp_cg_jobs, used by the admin "Generate" action, WP-CLI and the Pro
 * REST API. Items run in batches of BATCH, each batch scheduling the next WP-Cron event, so a
 * job of thousands never holds a request open. The progress page also runs batches while it is
 * open, so a site whose WP-Cron doesn't fire still finishes.
 *
 * Each item goes through certificate_generator_generate_for_row(), which reuses the row's
 * serial and cached PDF, so running an item twice never issues twice. One worker at a time per
 * job (an option-row lock); progress is saved after every item.
 *
 * items JSON: list of {ref: row id, status: pending|done|failed, certificate_id?, created?, error?}.
 * params JSON: {entity: students|teachers|schools, force: bool}.
 */
class Jobs {

	public const CRON_HOOK = 'certificate_generator_job_batch';
	public const BATCH     = 25;
	public const STALE     = 15 * MINUTE_IN_SECONDS;
	public const ENTITIES  = array( 'students', 'teachers', 'schools' );

	public static function table(): string {
		return CustomTables::instance()->get_table( 'jobs' );
	}

	private static function now(): string {
		return current_time( 'mysql', true );
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['items']  = json_decode( (string) $row['items'], true ) ?: array();
		$row['params'] = json_decode( (string) ( $row['params'] ?? '' ), true ) ?: array();
		return $row;
	}

	/**
	 * A certificate_generation job over recipient row ids.
	 *
	 * @param int[] $ids
	 * @param array $params entity (default students), force.
	 * @return array|\WP_Error The job row. The same idempotency key (per API key, or per user) returns the existing job.
	 */
	public static function create( array $ids, int $user_id, int $key_id = 0, string $idempotency_key = '', array $params = array() ) {
		global $wpdb;
		$entity = (string) ( $params['entity'] ?? 'students' );
		if ( ! in_array( $entity, self::ENTITIES, true ) ) {
			return new \WP_Error( 'invalid_entity', 'Unknown recipient type.', array( 'status' => 400 ) );
		}
		$idem = '' === $idempotency_key ? null : mb_substr( ( $key_id ? '' : 'u' . $user_id . ':' ) . $idempotency_key, 0, 100 );
		if ( null !== $idem ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
			$existing = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE api_key_id = %d AND idempotency_key = %s', $key_id, $idem ) );
			if ( $existing ) {
				return self::get( $existing );
			}
		}
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$max = (int) apply_filters( 'certificate_generator_job_max_items', 5000 );
		if ( ! $ids ) {
			return new \WP_Error( 'no_items', 'No recipients matched.', array( 'status' => 400 ) );
		}
		if ( count( $ids ) > $max ) {
			return new \WP_Error( 'too_many_items', sprintf( 'A job can hold at most %d recipients; split it.', $max ), array( 'status' => 400 ) );
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'type'            => 'certificate_generation',
				'status'          => 'queued',
				'total'           => count( $ids ),
				'items'           => wp_json_encode( array_map( static fn( $id ) => array( 'ref' => $id, 'status' => 'pending' ), $ids ) ),
				'params'          => wp_json_encode( array( 'entity' => $entity, 'force' => ! empty( $params['force'] ) ) ),
				'created_by'      => $user_id,
				'api_key_id'      => $key_id,
				'idempotency_key' => $idem,
				'created_at'      => self::now(),
				'updated_at'      => self::now(),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'db_error', $wpdb->last_error, array( 'status' => 500 ) );
		}
		$id = (int) $wpdb->insert_id;
		self::schedule( $id );
		return self::get( $id );
	}

	private static function schedule( int $id ): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK, array( $id ) );
		}
	}

	private static function save( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['items'] ) ) {
			// ponytail: the whole list is rewritten per item (~300 KB at the 5,000 cap); move items to a table if the cap grows.
			$fields['items'] = wp_json_encode( $fields['items'] );
		}
		$wpdb->update( self::table(), $fields + array( 'updated_at' => self::now() ), array( 'id' => $id ) );
	}

	private static function lock_name( int $id ): string {
		return 'certificate_generator_job_lock_' . $id;
	}

	/** One worker per job: add_option() is an INSERT on a unique key, so only one caller wins. */
	private static function lock( int $id ): bool {
		$name = self::lock_name( $id );
		if ( add_option( $name, time() + self::STALE, '', false ) ) {
			return true;
		}
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( (int) get_option( $name ) < time() ) { // its worker died: take over
			delete_option( $name );
			return add_option( $name, time() + self::STALE, '', false );
		}
		return false;
	}

	public static function is_locked( int $id ): bool {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::lock_name( $id ) ) ) >= time();
	}

	/** WP-Cron (and the progress poll): the next BATCH pending items; schedules the next batch or finishes the job. */
	public static function process( $id ): void {
		$id  = (int) $id;
		$job = self::get( $id );
		if ( ! $job || 'certificate_generation' !== $job['type'] || ! in_array( $job['status'], array( 'queued', 'running' ), true ) || ! self::lock( $id ) ) {
			return;
		}
		try {
			self::run_batch( $id, $job );
		} finally {
			delete_option( self::lock_name( $id ) );
		}
	}

	private static function run_batch( int $id, array $job ): void {
		self::save( $id, array( 'status' => 'running' ) ); // also marks it alive for the stale check

		global $wpdb;
		$entity = in_array( $job['params']['entity'] ?? '', self::ENTITIES, true ) ? $job['params']['entity'] : 'students';
		$force  = ! empty( $job['params']['force'] );
		$table  = CustomTables::instance()->get_table( $entity );
		$items  = $job['items'];
		$count  = 0;
		foreach ( $items as $i => $item ) {
			if ( 'pending' !== $item['status'] ) {
				continue;
			}
			if ( ++$count > self::BATCH ) {
				break;
			}
			try {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
				$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $item['ref'] ), ARRAY_A );
				$result = $row ? certificate_generator_generate_for_row( $entity, $row, $force ) : new \WP_Error( 'not_found', 'Recipient not found (deleted?).' );
			} catch ( \Throwable $e ) {
				$result = new \WP_Error( 'exception', $e->getMessage() );
			}
			$items[ $i ] = is_wp_error( $result )
				? array( 'ref' => $item['ref'], 'status' => 'failed', 'error' => $result->get_error_code() . ': ' . $result->get_error_message() )
				: array( 'ref' => $item['ref'], 'status' => 'done', 'certificate_id' => $result['certificate_id'], 'created' => $result['created'] );
			// Progress survives a fatal or a timeout in the next item.
			self::save( $id, self::counts( $items ) + array( 'items' => $items ) );
		}

		$counts = self::counts( $items );
		if ( $counts['done'] + $counts['failed'] < count( $items ) ) {
			self::schedule( $id );
			return;
		}
		$status = 0 === $counts['done'] && $counts['failed'] > 0 ? 'failed' : 'done';
		self::save( $id, array( 'status' => $status, 'finished_at' => self::now() ) );
		/**
		 * Fires once each time a job finishes ('done', possibly with failed items, or 'failed'
		 * when no item succeeded). A retried job fires again.
		 *
		 * @param array $summary self::summary() without the item list.
		 */
		do_action( 'certificate_generator_job_finished', self::summary( self::get( $id ), 0 ) );
	}

	/** @return array{done:int,failed:int} */
	private static function counts( array $items ): array {
		$by = array_count_values( array_column( $items, 'status' ) );
		return array( 'done' => (int) ( $by['done'] ?? 0 ), 'failed' => (int) ( $by['failed'] ?? 0 ) );
	}

	/** Failed items go back to pending and the job runs again; done items are never re-run. */
	public static function retry( int $id ): bool {
		$job = self::get( $id );
		if ( ! $job || 'certificate_generation' !== $job['type'] || in_array( $job['status'], array( 'queued', 'running' ), true ) || ! $job['failed'] ) {
			return false;
		}
		$items = array_map( static fn( $item ) => 'failed' === $item['status'] ? array( 'ref' => $item['ref'], 'status' => 'pending' ) : $item, $job['items'] );
		self::save( $id, self::counts( $items ) + array( 'items' => $items, 'status' => 'queued', 'finished_at' => null ) );
		self::schedule( $id );
		return true;
	}

	/** No worker has touched an unfinished job for STALE: its cron event was lost or never fired. */
	public static function is_stalled( array $job ): bool {
		return in_array( $job['status'], array( 'queued', 'running' ), true )
			&& strtotime( (string) $job['updated_at'] . ' UTC' ) < time() - self::STALE;
	}

	/** Hourly cron: a job whose batch died is scheduled again. */
	public static function resume_stale(): void {
		global $wpdb;
		$stale = gmdate( 'Y-m-d H:i:s', time() - self::STALE );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . self::table() . " WHERE type = 'certificate_generation' AND status IN ('queued','running') AND updated_at < %s", $stale ) ) as $id ) {
			self::schedule( (int) $id );
		}
	}

	/**
	 * The API shape. $per_page items of page $items_page are included (0 = none); failed items
	 * are listed first so a client sees what needs attention.
	 */
	public static function summary( array $job, int $items_page = 1, int $per_page = 100 ): array {
		$out = array(
			'id'          => (int) $job['id'],
			'type'        => (string) $job['type'],
			'status'      => (string) $job['status'],
			'total'       => (int) $job['total'],
			'done'        => (int) $job['done'],
			'failed'      => (int) $job['failed'],
			'pending'     => max( 0, (int) $job['total'] - (int) $job['done'] - (int) $job['failed'] ),
			'created_at'  => (string) $job['created_at'],
			'updated_at'  => (string) $job['updated_at'],
			'finished_at' => (string) ( $job['finished_at'] ?? '' ),
		);
		if ( $items_page > 0 ) {
			$items = $job['items'];
			usort( $items, static fn( $a, $b ) => ( 'failed' === $b['status'] ) <=> ( 'failed' === $a['status'] ) );
			$out['items']       = array_slice( $items, ( $items_page - 1 ) * $per_page, $per_page );
			$out['items_pages'] = (int) ceil( count( $items ) / $per_page );
		}
		return $out;
	}

	/** Recent generation jobs, newest first (rows without items; params decoded). */
	public static function recent( int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, status, total, done, failed, params, created_by, api_key_id, created_at, updated_at, finished_at FROM ' . self::table() . " WHERE type = 'certificate_generation' ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ) ?: array();
		foreach ( $rows as &$row ) {
			$row['params'] = json_decode( (string) $row['params'], true ) ?: array();
		}
		return $rows;
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE type = 'certificate_generation'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from $wpdb->prefix
	}
}
