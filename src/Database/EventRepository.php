<?php
declare(strict_types=1);

namespace CertificateGenerator\Database;

/**
 * Repository for wp_cg_events — recurring or one-off events (e.g. "Python
 * Olympiad" run three times a year), one row per occurrence.
 *
 * Related rows link back via event_id: students, teachers, schools, and
 * certificate_templates each carry an `event_id` FK to this table's `id`.
 */
class EventRepository extends Repository {

	public function __construct( ?object $db = null ) {
		parent::__construct( 'cg_events', $db );
	}

	public function find_by_code( string $event_code ): ?array {
		return $this->find_by( 'event_code', $event_code );
	}

	/**
	 * Builds the human-readable code, e.g. "PO" + "06" + "2026" -> "PO-06-2026".
	 * $prefix is the caller's short event abbreviation (e.g. "PO" for Python Olympiad).
	 */
	public function make_event_code( string $prefix, string $month, string $year ): string {
		$prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $prefix ) ?? '' );
		$month  = str_pad( preg_replace( '/[^0-9]/', '', $month ) ?? '', 2, '0', STR_PAD_LEFT );
		$year   = preg_replace( '/[^0-9]/', '', $year ) ?? '';
		return "{$prefix}-{$month}-{$year}";
	}

	public function students_for_event( int $event_id ): array {
		return $this->rows_for_event( 'students', $event_id );
	}

	public function teachers_for_event( int $event_id ): array {
		return $this->rows_for_event( 'teachers', $event_id );
	}

	public function schools_for_event( int $event_id ): array {
		return $this->rows_for_event( 'schools', $event_id );
	}

	public function templates_for_event( int $event_id ): array {
		return $this->rows_for_event( 'certificate_templates', $event_id );
	}

	private function rows_for_event( string $table_suffix, int $event_id ): array {
		$table = $this->db->prefix . 'cg_' . $table_suffix;
		return $this->db->get_results(
			$this->db->prepare( "SELECT * FROM {$table} WHERE event_id = %d", $event_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			\ARRAY_A
		) ?: array();
	}
}
