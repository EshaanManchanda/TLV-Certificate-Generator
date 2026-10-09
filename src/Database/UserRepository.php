<?php
declare(strict_types=1);

namespace CertificateGenerator\Database;

/**
 * Repository for student/teacher/school entity lookups — all three entity
 * types share the same column shape (name, email, school_name, certificate_type,
 * status, …) so one repo handles all three via the $entity_type constructor arg.
 *
 * Live tables confirmed (Phase 3B — CPTs deregistered, admin pages already
 * query wp_cg_* as the sole data source):
 *   students → wp_cg_students
 *   teachers → wp_cg_teachers
 *   schools  → wp_cg_schools
 *
 * Search column differs per entity type — set via $name_col.
 */
class UserRepository extends Repository {

	private const TABLE_MAP = array(
		'students' => 'cg_students',
		'teachers' => 'cg_teachers',
		'schools'  => 'cg_schools',
	);

	private const NAME_COL = array(
		'students' => 'student_name',
		'teachers' => 'teacher_name',
		'schools'  => 'school_name',
	);

	private string $name_col;

	public function __construct( string $entity_type = 'students', ?object $db = null ) {
		$table          = self::TABLE_MAP[ $entity_type ] ?? 'cg_students';
		$this->name_col = self::NAME_COL[ $entity_type ] ?? 'student_name';
		parent::__construct( $table, $db );
	}

	// ── Simple lookups ────────────────────────────────────────────────────────

	public function find_by_email( string $email ): ?array {
		return $this->find_by( 'email', $email );
	}

	public function find_by_school( int $school_id ): array {
		return $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE school_id = %d ORDER BY {$this->name_col} ASC",
				$school_id
			),
			\ARRAY_A
		) ?: array();
	}

	// ── Admin list — paginated + filtered ────────────────────────────────────

	/**
	 * Return a page of rows matching optional filters.
	 *
	 * Allowed filter keys: search (text — matched against name+email),
	 * school_name (exact), certificate_type (exact), status (exact).
	 * Allowed orderby values are validated by the caller (entity page).
	 *
	 * @param array  $filters  Associative array of filter key → value.
	 * @param string $orderby  Validated column name.
	 * @param string $order    'ASC' or 'DESC'.
	 * @param int    $per_page Rows per page.
	 * @param int    $offset   Row offset.
	 * @return array
	 */
	public function find_page( array $filters, string $orderby, string $order, int $per_page, int $offset ): array {
		[ $where, $params ] = $this->build_where( $filters );
		$sql                = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		return $this->db->get_results(
			$this->db->prepare( $sql, ...array_merge( $params, array( $per_page, $offset ) ) ),
			\ARRAY_A
		) ?: array();
	}

	/**
	 * Count rows matching filters — used for pagination total.
	 *
	 * @param array $filters Same keys as find_page().
	 * @return int
	 */
	public function count_filtered( array $filters ): int {
		[ $where, $params ] = $this->build_where( $filters );
		$sql                = "SELECT COUNT(*) FROM {$this->table} WHERE {$where}";
		return (int) ( $params
			? $this->db->get_var( $this->db->prepare( $sql, ...$params ) )
			: $this->db->get_var( $sql )
		);
	}

	/**
	 * Return distinct non-empty values for a column — used to populate filter dropdowns.
	 *
	 * @param string $col Validated column name (caller must whitelist before passing).
	 * @return string[]
	 */
	public function distinct_column( string $col ): array {
		return $this->db->get_col(
			"SELECT DISTINCT {$col} FROM {$this->table} WHERE {$col} != '' ORDER BY {$col}"
		) ?: array();
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * Build a WHERE fragment + params array from a filters map.
	 *
	 * @return array{string, array}  [where_string, params_array]
	 */
	private function build_where( array $filters ): array {
		$where  = '1=1';
		$params = array();

		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $this->db->esc_like( (string) $filters['search'] ) . '%';
			$where   .= " AND ({$this->name_col} LIKE %s OR email LIKE %s)";
			$params[] = $like;
			$params[] = $like;
		}

		foreach ( array( 'school_name', 'certificate_type', 'status', 'city' ) as $col ) {
			if ( ! empty( $filters[ $col ] ) ) {
				$where   .= " AND {$col} = %s";
				$params[] = (string) $filters[ $col ];
			}
		}

		if ( ! empty( $filters['event_id'] ) ) {
			$where   .= ' AND event_id = %d';
			$params[] = (int) $filters['event_id'];
		}

		return array( $where, $params );
	}
}
