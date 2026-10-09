<?php
declare(strict_types=1);

namespace CertificateGenerator\Database;

/**
 * Base repository providing common database operations.
 *
 * Accepts an optional $db argument so concrete repos can be unit-tested
 * without a live WordPress environment (pass a WpdbFake instead).
 */
abstract class Repository {
	protected string $table;
	protected string $pk = 'id';
	protected object $db;

	public function __construct( string $table, ?object $db = null ) {
		if ( $db !== null ) {
			$this->db = $db;
		} else {
			global $wpdb;
			$this->db = $wpdb;
		}
		$this->table = $this->db->prefix . $table;
	}

	public function get_table(): string {
		return $this->table;
	}

	protected function get_wpdb(): object {
		return $this->db;
	}

	public function find( int $id ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE {$this->pk} = %d",
				$id
			),
			\ARRAY_A
		);

		return $row ?: null;
	}

	public function find_by( string $column, mixed $value ): ?array {
		$row = $this->db->get_row(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE {$column} = %s",
				$value
			),
			\ARRAY_A
		);

		return $row ?: null;
	}

	public function all( int $limit = 100, int $offset = 0 ): array {
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} ORDER BY {$this->pk} DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			\ARRAY_A
		) ?: array();
	}

	public function count(): int {
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	public function delete( int $id ): bool {
		$deleted = $this->db->delete( $this->table, array( $this->pk => $id ), array( '%d' ) );
		return $deleted > 0;
	}

	public function insert( array $data ): int {
		$this->db->insert( $this->table, $data );
		return (int) $this->db->insert_id;
	}

	public function update( int $id, array $data ): bool {
		$updated = $this->db->update( $this->table, $data, array( $this->pk => $id ), null, array( '%d' ) );
		return $updated !== false;
	}
}
