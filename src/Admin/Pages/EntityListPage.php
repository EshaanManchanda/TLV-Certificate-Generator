<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Database\CustomTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared list view for the Students / Teachers / Schools / Templates admin tables.
 *
 * Owns filtering, sorting, pagination (filters preserved across pages), row status
 * columns, and every bulk action (edit, delete, export CSV, generate, email,
 * duplicate-to-date) including "select all N matching this filter". Subclasses
 * supply config() and keep their own add/edit forms.
 *
 * config() keys:
 *   title, singular ('student'), plural ('students'), name_col,
 *   ids_field, nonce_action, nonce_field   — POST field names (kept per page for back-compat),
 *   search_cols    string[]                — columns the search box matches,
 *   columns        [col => label]          — table columns (all sortable),
 *   select_filters [param => [col, label, options?]] — dropdown filters; options default to DISTINCT values,
 *   statuses       string[]                — allowed values of the status column,
 *   date_col       'issue_date'|'event_date',
 *   bulk_fields    [col => text|type|type_free|date|status|event|entity_type],
 *   has_email, can_generate, can_empty, is_templates  bool,
 *   gen_fields     string[]|null           — fields passed to certificate_generator_generate_certificate_pdf (null = CertificateGenerator_Field_Schema).
 */
abstract class EntityListPage {

	protected string $slug;
	protected string $edit_slug;
	protected string $table_key;

	/** Rows processed per synchronous "Generate certificates" run. */
	private const GENERATE_CAP = 200;

	private const ENTITY_TABLES = array( 'students', 'teachers', 'schools' );

	private array $memo = array();

	abstract protected function config(): array;

	// ── Registration ─────────────────────────────────────────────────────────

	/** Register the list screen; early actions (single delete, CSV export) run on its load- hook, before output. */
	protected function add_list_page( string $page_title, string $menu_title ): void {
		$hook = add_submenu_page( 'cg-dashboard', $page_title, $menu_title, 'manage_options', $this->slug, array( $this, 'render_list' ) );
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_early_actions' ) );
		}
	}

	/** AJAX handlers; called on admin_init during admin-ajax.php, where admin_menu never fires. */
	public function register_ajax(): void {
		if ( ! empty( $this->config()['has_email'] ) ) {
			add_action( 'wp_ajax_certificate_generator_' . $this->config()['singular'] . '_send_email', array( $this, 'send_email_ajax' ) );
		}
	}

	public function handle_early_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$c     = $this->config();
		$table = $this->table();

		// Single-row delete link.
		if ( sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) ) === 'delete' && ! empty( $_GET['id'] ) ) {
			$id = absint( $_GET['id'] );
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'cg_delete_' . $c['singular'] . '_single_' . $id ) ) {
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
				wp_safe_redirect( add_query_arg( 'cg_msg', 'deleted', $this->list_url( $this->filters() ) ) );
				exit;
			}
		}

		// Export selected / all matching rows as an importer-compatible CSV.
		if ( sanitize_text_field( wp_unslash( $_POST['bulk_action'] ?? '' ) ) === 'export' && $this->verify_bulk_nonce() ) {
			$ids = $this->selected_ids( $this->filters() );
			if ( $ids ) {
				$this->export_csv( $ids );
				exit;
			}
		}
	}

	// ── Filters / query ──────────────────────────────────────────────────────

	protected function table(): string {
		return CustomTables::instance()->get_table( $this->table_key );
	}

	/** Current filter/sort state from the query string. */
	protected function filters(): array {
		$c = $this->config();
		$f = array(
			's'            => sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ),
			'event_filter' => absint( $_GET['event_filter'] ?? 0 ),
			'date_from'    => $this->valid_date( wp_unslash( $_GET['date_from'] ?? '' ) ),
			'date_to'      => $this->valid_date( wp_unslash( $_GET['date_to'] ?? '' ) ),
			'tpl_match'    => sanitize_key( $_GET['tpl_match'] ?? '' ),
			'email_status' => sanitize_key( $_GET['email_status'] ?? '' ),
			'usage'        => sanitize_key( $_GET['usage'] ?? '' ),
		);
		foreach ( array_keys( $c['select_filters'] ) as $param ) {
			$f[ $param ] = sanitize_text_field( wp_unslash( $_GET[ $param ] ?? '' ) );
		}
		$sortable      = array_keys( $c['columns'] );
		$f['orderby']  = in_array( $_GET['orderby'] ?? '', $sortable, true ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
		$f['order']    = strtoupper( (string) sanitize_text_field( wp_unslash( $_GET['order'] ?? 'DESC' ) ) ) === 'ASC' ? 'ASC' : 'DESC';
		$f['paged']    = max( 1, (int) ( absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ) );
		return $f;
	}

	/** Filter args worth carrying in URLs (non-empty, excluding paging). */
	private function filter_args( array $f ): array {
		unset( $f['paged'] );
		if ( $f['orderby'] === 'created_at' && $f['order'] === 'DESC' ) {
			unset( $f['orderby'], $f['order'] );
		}
		return array_filter( $f, static fn( $v ) => $v !== '' && $v !== 0 );
	}

	protected function list_url( array $f = array(), array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $this->slug ), $f ? $this->filter_args( $f ) : array(), $extra ), admin_url( 'admin.php' ) );
	}

	/** @return array{0:string,1:array} WHERE clause (alias e) + params. */
	protected function where( array $f ): array {
		global $wpdb;
		$c      = $this->config();
		$where  = array( '1=1' );
		$params = array();

		if ( $f['s'] !== '' ) {
			$like  = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$parts = array();
			foreach ( $c['search_cols'] as $col ) {
				$parts[]  = "e.$col LIKE %s";
				$params[] = $like;
			}
			$where[] = '(' . implode( ' OR ', $parts ) . ')';
		}
		foreach ( $c['select_filters'] as $param => $def ) {
			if ( $f[ $param ] !== '' && $this->has_column( $def['col'] ) ) {
				$where[]  = "e.{$def['col']} = %s";
				$params[] = $f[ $param ];
			}
		}
		if ( $f['event_filter'] ) {
			$where[]  = 'e.event_id = %d';
			$params[] = $f['event_filter'];
		}
		$date_col = $c['date_col'];
		if ( $f['date_from'] !== '' ) {
			$where[]  = "e.$date_col >= %s";
			$params[] = $f['date_from'];
		}
		if ( $f['date_to'] !== '' ) {
			$where[]  = "e.$date_col <= %s";
			$params[] = $f['date_to'];
		}

		if ( empty( $c['is_templates'] ) ) {
			$published = $this->template_match_sql( "'published'" );
			$pending   = $this->template_match_sql( "'scheduled','draft'" );
			if ( $f['tpl_match'] === 'ready' ) {
				$where[] = $published;
			} elseif ( $f['tpl_match'] === 'pending' ) {
				$where[] = "NOT $published AND $pending";
			} elseif ( $f['tpl_match'] === 'none' ) {
				$where[] = "NOT $published AND NOT $pending";
			}
		} elseif ( $f['usage'] === 'used' || $f['usage'] === 'unused' ) {
			$used    = $this->template_usage_sql();
			$where[] = $f['usage'] === 'used' ? $used : "NOT $used";
		}

		if ( ! empty( $c['has_email'] ) && $f['email_status'] !== '' ) {
			$sql = $this->email_status_sql( $f['email_status'] );
			if ( $sql ) {
				$where[] = $sql;
			}
		}

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * Row e has a template of the given status(es) matching certificate_type + issue_date
	 * — same rule as certificate_generator_select_certificate_template(): an undated template matches any date.
	 */
	private function template_match_sql( string $statuses ): string {
		$tpl = CustomTables::instance()->get_table( 'certificate_templates' );
		return "EXISTS (SELECT 1 FROM $tpl t WHERE t.certificate_type = e.certificate_type AND t.status IN ($statuses)"
			. " AND (e.issue_date IS NULL OR t.event_date IS NULL OR t.event_date < '1000-01-01' OR t.event_date = e.issue_date))";
	}

	/** Template e is used by at least one recipient of its entity_type. */
	private function template_usage_sql(): string {
		$parts = array();
		foreach ( self::ENTITY_TABLES as $entity ) {
			$tbl     = CustomTables::instance()->get_table( $entity );
			$parts[] = "(e.entity_type = '$entity' AND EXISTS (SELECT 1 FROM $tbl x WHERE x.certificate_type = e.certificate_type"
				. " AND (e.event_date IS NULL OR e.event_date < '1000-01-01' OR x.issue_date = e.event_date)))";
		}
		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * Email status filter. ponytail: "any sent / any failed / any pending" per address —
	 * the badge uses the newest log-vs-queue row, so an address that failed then succeeded
	 * filters as Sent. Good enough to find who still needs an email.
	 */
	private function email_status_sql( string $status ): string {
		global $wpdb;
		if ( $status === 'no_email' ) {
			return "(e.email IS NULL OR e.email = '')";
		}
		$log = $wpdb->prefix . 'cert_email_logs';
		$q   = $wpdb->prefix . 'cert_email_queue';
		if ( ! $this->table_exists( $log ) || ! $this->table_exists( $q ) ) {
			return '';
		}
		$l_has  = static fn( string $st ) => "EXISTS (SELECT 1 FROM $log l WHERE l.recipient_email = e.email AND l.status IN ($st))";
		$q_has  = static fn( string $st ) => "EXISTS (SELECT 1 FROM $q q WHERE q.recipient_email = e.email AND q.status IN ($st))";
		$sent   = '(' . $l_has( "'sent'" ) . ' OR ' . $q_has( "'sent'" ) . ')';
		$failed = '(' . $l_has( "'failed','bounced'" ) . ' OR ' . $q_has( "'failed'" ) . ')';
		$queued = $q_has( "'pending','sending'" );
		$any    = "(EXISTS (SELECT 1 FROM $log l WHERE l.recipient_email = e.email) OR EXISTS (SELECT 1 FROM $q q WHERE q.recipient_email = e.email))";
		switch ( $status ) {
			case 'sent':
				return $sent;
			case 'failed':
				return "NOT $sent AND $failed";
			case 'queued':
				return "NOT $sent AND NOT $failed AND $queued";
			case 'never':
				return "e.email <> '' AND NOT $any";
		}
		return '';
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		if ( ! isset( $this->memo[ 'exists_' . $table ] ) ) {
			$this->memo[ 'exists_' . $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		return $this->memo[ 'exists_' . $table ];
	}

	private function has_column( string $col ): bool {
		global $wpdb;
		if ( ! isset( $this->memo[ 'col_' . $col ] ) ) {
			$this->memo[ 'col_' . $col ] = (bool) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $this->table() . ' LIKE %s', $col ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		}
		return $this->memo[ 'col_' . $col ];
	}

	private function distinct( string $col ): array {
		global $wpdb;
		if ( ! $this->has_column( $col ) ) {
			return array();
		}
		return $wpdb->get_col( 'SELECT DISTINCT ' . $col . ' FROM ' . $this->table() . " WHERE $col IS NOT NULL AND $col != '' ORDER BY $col" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}

	private function events(): array {
		global $wpdb;
		if ( ! isset( $this->memo['events'] ) ) {
			$tbl                  = CustomTables::instance()->get_table( 'events' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$this->memo['events'] = $tbl && $this->table_exists( $tbl )
				? ( $wpdb->get_results( "SELECT id, event_code, event_name FROM $tbl ORDER BY event_code", ARRAY_A ) ?: array() )
				: array();
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $this->memo['events'];
	}

	/** All templates, small table — powers the per-row Template column and the type dropdown. */
	private function templates(): array {
		global $wpdb;
		if ( ! isset( $this->memo['templates'] ) ) {
			$tbl                     = CustomTables::instance()->get_table( 'certificate_templates' );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$this->memo['templates'] = $this->table_exists( $tbl )
				? ( $wpdb->get_results( "SELECT certificate_type, event_date, status FROM $tbl", ARRAY_A ) ?: array() )
				: array();
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $this->memo['templates'];
	}

	/** Certificate types a recipient can be set to: those that have a template (falls back to types already in use). */
	private function certificate_type_options(): array {
		$types = array_unique( array_filter( array_column( $this->templates(), 'certificate_type' ) ) );
		sort( $types );
		return $types ?: $this->distinct( 'certificate_type' );
	}

	// ── Bulk actions ─────────────────────────────────────────────────────────

	private function verify_bulk_nonce(): bool {
		$c = $this->config();
		return ! empty( $_POST[ $c['nonce_field'] ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $c['nonce_field'] ] ) ), $c['nonce_action'] );
	}

	/** Checked rows, or every row matching the current filters when "select all matching" was used. */
	protected function selected_ids( array $f ): array {
		global $wpdb;
		if ( ! empty( $_POST['select_all_matching'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
			[ $where, $params ] = $this->where( $f );
			$sql                = 'SELECT e.id FROM ' . $this->table() . " e WHERE $where";
			return array_map( 'intval', $params ? $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		}
		return array_values( array_filter( array_map( 'absint', (array) ( $_POST[ $this->config()['ids_field'] ] ?? array() ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
	}

	/** Run a statement against ids in chunks; returns total affected rows. */
	private function for_ids( array $ids, callable $run ): int {
		$affected = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$affected += (int) $run( implode( ',', array_map( 'intval', $chunk ) ) );
		}
		return $affected;
	}

	private function rows_by_ids( array $ids ): array {
		global $wpdb;
		$rows = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$rows = array_merge( $rows, $wpdb->get_results( 'SELECT * FROM ' . $this->table() . ' WHERE id IN (' . implode( ',', array_map( 'intval', $chunk ) ) . ') ORDER BY id', ARRAY_A ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		}
		return $rows;
	}

	/** @return array{0:string,1:string} message + notice type. */
	protected function handle_bulk(): array {
		if ( ! $this->verify_bulk_nonce() ) {
			return array( '', '' );
		}
		global $wpdb;
		$c      = $this->config();
		$table  = $this->table();
		$action = sanitize_key( $_POST['bulk_action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
		$label  = $c['singular'] . '(s)';

		if ( $action === 'empty_all' && ! empty( $c['can_empty'] ) ) {
			if ( sanitize_text_field( wp_unslash( $_POST['empty_confirm'] ?? '' ) ) !== 'DELETE' ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
				return array( 'Type DELETE to confirm emptying the table.', 'error' );
			}
			$wpdb->query( "DELETE FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return array( 'All ' . $c['plural'] . ' have been removed.', 'success' );
		}

		$ids = $this->selected_ids( $this->filters() );
		if ( ! $ids ) {
			return $action ? array( 'No ' . $c['plural'] . ' selected.', 'warning' ) : array( '', '' );
		}

		switch ( $action ) {
			case 'delete':
				$n = $this->for_ids( $ids, static fn( $in ) => $wpdb->query( "DELETE FROM $table WHERE id IN ($in)" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return array( "$n $label deleted.", 'success' );

			case 'bulk_edit':
				$data = $this->bulk_edit_data();
				if ( ! $data ) {
					return array( 'Choose at least one field to change.', 'warning' );
				}
				$set    = implode( ', ', array_map( static fn( $col ) => "`$col` = %s", array_keys( $data ) ) );
				$values = array_values( $data );
				$n      = $this->for_ids( $ids, static fn( $in ) => $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE id IN ($in)", ...$values ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( function_exists( 'certificate_generator_flush_filter_caches' ) ) {
					certificate_generator_flush_filter_caches();
				}
				return array( "$n $label updated via bulk edit.", 'success' );

			case 'generate':
				return empty( $c['can_generate'] ) ? array( '', '' ) : $this->bulk_generate( $ids );

			case 'send_email':
				return empty( $c['has_email'] ) ? array( '', '' ) : $this->bulk_queue_emails( $ids );

			case 'duplicate_to_date':
				return empty( $c['is_templates'] ) ? array( '', '' ) : $this->bulk_duplicate_templates( $ids );
		}
		return array( '', '' );
	}

	/** Validated column => value map from the bulk_{col} inputs; blank inputs are left unchanged. */
	private function bulk_edit_data(): array {
		$c    = $this->config();
		$data = array();
		foreach ( $c['bulk_fields'] as $col => $type ) {
			$key = $type === 'event' ? 'bulk_event_id' : 'bulk_' . $col;
			$raw = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
			if ( ! is_string( $raw ) || $raw === '' ) {
				continue;
			}
			switch ( $type ) {
				case 'date':
					$date = $this->valid_date( $raw );
					if ( $date !== '' ) {
						$data[ $col ] = $date;
						if ( $this->has_column( 'year' ) ) {
							$data['year'] = substr( $date, 0, 4 );
						}
					}
					break;
				case 'status':
					if ( in_array( $raw, $c['statuses'], true ) ) {
						$data[ $col ] = $raw;
					}
					break;
				case 'entity_type':
					if ( in_array( $raw, self::ENTITY_TABLES, true ) ) {
						$data[ $col ] = $raw;
					}
					break;
				case 'event':
					if ( absint( $raw ) > 0 ) {
						$data['event_id'] = absint( $raw );
					}
					break;
				default: // text, type, type_free
					$val = sanitize_text_field( $raw );
					if ( $val !== '' ) {
						$data[ $col ] = $val;
					}
			}
		}
		return $data;
	}

	private function bulk_generate( array $ids ): array {
		if ( ! function_exists( 'certificate_generator_generate_certificate_pdf' ) || ! function_exists( 'certificate_generator_resolve_entity_template' ) ) {
			return array( 'Certificate generator functions are unavailable.', 'error' );
		}
		$c       = $this->config();
		$skipped = max( 0, count( $ids ) - self::GENERATE_CAP );
		$ids     = array_slice( $ids, 0, self::GENERATE_CAP ); // ponytail: synchronous; move to the background processor if 200/run is too slow
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$ok      = 0;
		$failed  = 0;
		$missing = array();
		foreach ( $this->rows_by_ids( $ids ) as $row ) {
			if ( ! certificate_generator_resolve_entity_template( $row ) ) {
				$missing[ $row['certificate_type'] . ( $row['issue_date'] ? ' (' . $row['issue_date'] . ')' : '' ) ] = true;
				++$failed;
				continue;
			}
			if ( ! isset( $row['place'] ) && isset( $row['city'] ) ) {
				$row['place'] = $row['city'];
			}
			$fields = $c['gen_fields'] ?? ( class_exists( 'CertificateGenerator_Field_Schema' )
				? \CertificateGenerator_Field_Schema::get_all_renderable_fields( $row['certificate_type'] )
				: array( $c['name_col'], 'school_name', 'issue_date' ) );
			if ( certificate_generator_generate_certificate_pdf( (int) ( $row['wp_post_id'] ?? 0 ), $fields, $row ) ) {
				++$ok;
			} else {
				++$failed;
			}
		}

		$msg = "Generated $ok certificate(s).";
		if ( $missing ) {
			$msg .= ' No published template for: ' . implode( ', ', array_keys( $missing ) ) . '.';
		}
		if ( $failed > count( $missing ) ) {
			$msg .= ' Some failed — check the error log.';
		}
		if ( $skipped ) {
			$msg .= " $skipped more selected — run again to continue (max " . self::GENERATE_CAP . ' per run).';
		}
		return array( $msg, $failed || $skipped ? 'warning' : 'success' );
	}

	/** Queue one certificate email per distinct address; the email cron sends them. */
	private function bulk_queue_emails( array $ids ): array {
		if ( ! function_exists( 'certificate_generator_queue_email' ) ) {
			return array( 'Email queue is unavailable.', 'error' );
		}
		$c        = $this->config();
		$queued   = 0;
		$no_email = 0;
		$seen     = array();
		foreach ( $this->rows_by_ids( $ids ) as $row ) {
			$email = sanitize_email( $row['email'] ?? '' );
			if ( ! is_email( $email ) ) {
				++$no_email;
				continue;
			}
			if ( isset( $seen[ strtolower( $email ) ] ) ) {
				continue; // one email carries every certificate for that address
			}
			$seen[ strtolower( $email ) ] = true;
			$cg_id                        = $this->legacy_anchor_id( $email, (string) $row[ $c['name_col'] ], (string) $row['certificate_type'] );
			if ( $cg_id && certificate_generator_queue_email( $cg_id, $email ) ) {
				++$queued;
			}
		}
		$msg = "Queued $queued email(s) — they'll be sent in the background.";
		if ( $no_email ) {
			$msg .= " $no_email selected " . $c['singular'] . '(s) have no valid email.';
		}
		return array( $msg, $no_email ? 'warning' : 'success' );
	}

	/** Copy templates to a new event date — builds next event's set from this one's. */
	private function bulk_duplicate_templates( array $ids ): array {
		global $wpdb;
		$date   = $this->valid_date( wp_unslash( $_POST['bulk_new_event_date'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
		$status = sanitize_key( $_POST['bulk_new_status'] ?? 'draft' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verify_bulk_nonce() ran first
		if ( $date === '' ) {
			return array( 'Choose the new event date.', 'warning' );
		}
		if ( ! in_array( $status, $this->config()['statuses'], true ) ) {
			$status = 'draft';
		}
		$n = 0;
		foreach ( $this->rows_by_ids( $ids ) as $row ) {
			$old_date = substr( (string) $row['event_date'], 0, 10 );
			$old_ym   = substr( $old_date, 0, 7 );
			unset( $row['id'] );
			$row['template_name'] = ( $old_ym !== '' && strpos( $row['template_name'], $old_ym ) !== false )
				? str_replace( $old_ym, substr( $date, 0, 7 ), $row['template_name'] )
				: $row['template_name'] . ' ' . substr( $date, 0, 7 );
			$row['event_date']    = $date;
			$row['year']          = substr( $date, 0, 4 );
			$row['status']        = $status;
			$row['created_at']    = current_time( 'mysql' );
			$row['updated_at']    = current_time( 'mysql' );
			if ( $wpdb->insert( $this->table(), $row ) ) {
				++$n;
			}
		}
		return array( "Created $n template(s) for $date (status: $status).", 'success' );
	}

	/** wp_certificate_generator row id for this email — certificate_generator_send_email()/queue key on it. */
	protected function legacy_anchor_id( string $email, string $name, string $cert_type ): int {
		global $wpdb;
		$cg_table = $wpdb->prefix . 'certificate_generator';
		$cg_id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $cg_table WHERE email = %s ORDER BY id DESC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $cg_id ) {
			$wpdb->insert(
				$cg_table,
				array(
					'email'            => $email,
					'student_name'     => $name,
					'certificate_type' => $cert_type,
					'issued_at'        => current_time( 'mysql' ),
					'generated_via'    => 'manual',
				)
			);
			$cg_id = (int) $wpdb->insert_id;
		}
		return $cg_id;
	}

	// ── CSV export ───────────────────────────────────────────────────────────

	/** Columns each importer recognises (required + known optional), so an export re-imports cleanly. */
	private const EXPORT_COLUMNS = array(
		'students' => array( 'student_name', 'email', 'phone', 'school_name', 'certificate_type', 'issue_date', 'year', 'status', 'send_email', 'event_code', 'photo_url' ),
		'teachers' => array( 'teacher_name', 'email', 'phone', 'school_name', 'certificate_type', 'issue_date', 'year', 'status', 'send_email', 'event_code' ),
		'schools'  => array( 'school_name', 'place', 'certificate_type', 'issue_date', 'year', 'status', 'send_email', 'event_code' ),
	);

	private function export_csv( array $ids ): void {
		$rows = $this->rows_by_ids( $ids );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		if ( ! empty( $this->config()['is_templates'] ) ) {
			if ( function_exists( 'certificate_generator_output_templates_csv' ) ) {
				certificate_generator_output_templates_csv( $rows );
			}
			return;
		}

		$events = array_column( $this->events(), 'event_code', 'id' );
		$extra  = array();
		foreach ( $rows as $r ) {
			$x = json_decode( (string) ( $r['extra_fields'] ?? '' ), true );
			if ( is_array( $x ) ) {
				$extra = array_merge( $extra, array_keys( $x ) );
			}
		}
		$extra   = array_values( array_unique( $extra ) );
		$columns = self::EXPORT_COLUMNS[ $this->table_key ];

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $this->table_key . '_selected_' . gmdate( 'Y-m-d' ) . '.csv' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_merge( $columns, $extra ) );
		foreach ( $rows as $r ) {
			$x    = json_decode( (string) ( $r['extra_fields'] ?? '' ), true ) ?: array();
			$line = array();
			foreach ( $columns as $col ) {
				switch ( $col ) {
					case 'event_code':
						$line[] = $events[ $r['event_id'] ?? 0 ] ?? '';
						break;
					case 'place':
						$line[] = $r['city'] ?? '';
						break;
					case 'send_email':
						$line[] = isset( $r['send_email'] ) && ! $r['send_email'] ? 'false' : 'true';
						break;
					default:
						$line[] = $r[ $col ] ?? '';
				}
			}
			foreach ( $extra as $k ) {
				$line[] = $x[ $k ] ?? '';
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes a php://output / php://temp stream
	}

	// ── Per-row email (AJAX) ─────────────────────────────────────────────────

	public function send_email_ajax(): void {
		$c = $this->config();
		check_ajax_referer( 'cg_' . $c['singular'] . '_send_email', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions.' ) );
		}
		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid ' . $c['singular'] . ' ID.' ) );
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => ucfirst( $c['singular'] ) . ' not found.' ) );
		}
		$email = sanitize_email( $row['email'] ?? '' );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => ucfirst( $c['singular'] ) . ' has no valid email address.' ) );
		}
		$cg_id = $this->legacy_anchor_id( $email, sanitize_text_field( $row[ $c['name_col'] ] ?? '' ), sanitize_text_field( $row['certificate_type'] ?? '' ) );
		if ( ! $cg_id ) {
			wp_send_json_error( array( 'message' => 'Could not create certificate record. Check database permissions.' ) );
		}
		if ( ! function_exists( 'certificate_generator_send_email' ) ) {
			wp_send_json_error( array( 'message' => 'Email send function unavailable.' ) );
		}
		if ( certificate_generator_send_email( $cg_id ) ) {
			wp_send_json_success( array( 'message' => 'Email sent successfully.' ) );
		}
		wp_send_json_error( array( 'message' => 'Email send failed. Check server email configuration and error logs.' ) );
	}

	// ── Rendering ────────────────────────────────────────────────────────────

	public function render_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}
		global $wpdb;
		$c = $this->config();

		[ $message, $notice ] = $this->handle_bulk();
		if ( ! $message && sanitize_text_field( wp_unslash( $_GET['cg_msg'] ?? '' ) ) === 'deleted' ) {
			[ $message, $notice ] = array( ucfirst( $c['singular'] ) . ' deleted.', 'success' );
		}

		$f                  = $this->filters();
		[ $where, $params ] = $this->where( $f );
		$per_page           = 20;
		$table              = $this->table();
		$count_sql          = "SELECT COUNT(*) FROM $table e WHERE $where";
		$total              = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$total_pages        = max( 1, (int) ceil( $total / $per_page ) );
		$f['paged']         = min( $f['paged'], $total_pages );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$rows               = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.* FROM $table e WHERE $where ORDER BY e.`{$f['orderby']}` {$f['order']} LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, ( $f['paged'] - 1 ) * $per_page ) )
			),
			ARRAY_A
		) ?: array();
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$email_statuses = array();
		if ( ! empty( $c['has_email'] ) && $rows && class_exists( '\CertificateGenerator\Services\EmailStatusService' ) ) {
			$email_statuses = \CertificateGenerator\Services\EmailStatusService::getBadgeStatuses( array_column( $rows, 'email' ) );
		}
		$events_map = array_column( $this->events(), 'event_code', 'id' );
		$edit_url   = admin_url( 'admin.php?page=' . $this->edit_slug );
		$active     = $this->filter_args( $f );
		unset( $active['orderby'], $active['order'] );
		$import_url = ! empty( $c['can_import'] ) ? admin_url( 'admin.php?page=cg-bulk-import&tab=' . $c['plural'] ) : '';
		$actions    = '<a href="' . esc_url( $edit_url ) . '" class="button button-primary">Add New</a>'
			. ( $import_url ? ' <a href="' . esc_url( $import_url ) . '" class="button">Import CSV</a>' : '' );
		?>
		<div class="wrap cg-list">
			<?php
			certificate_generator_ui_page_header( $c['title'], $c['lead'] ?? '', $actions );
			if ( $message ) {
				certificate_generator_ui_notice( $notice ?: 'success', esc_html( $message ) );
			}
			?>

			<?php $this->render_filter_form( $f, (bool) $active ); ?>

			<form method="post" id="cg-list-form">
				<?php wp_nonce_field( $c['nonce_action'], $c['nonce_field'] ); ?>
				<input type="hidden" name="select_all_matching" id="cg-select-all-matching" value="0">
				<div class="tablenav top">
					<?php $this->render_bulk_controls(); ?>
					<?php $this->render_pagination( $f, $total, $total_pages ); ?>
					<br class="clear">
				</div>

				<?php if ( $total > count( $rows ) ) : ?>
				<div id="cg-select-all-banner" class="notice notice-info inline cg-select-banner" hidden>
					All <?php echo count( $rows ); ?> on this page are selected.
					<a href="#" id="cg-select-all-link">Select all <?php echo esc_html( number_format_i18n( $total ) ); ?> <?php echo esc_html( $c['plural'] ); ?> matching this filter</a>
					<span id="cg-all-selected-note" hidden><strong>All <?php echo esc_html( number_format_i18n( $total ) ); ?> matching <?php echo esc_html( $c['plural'] ); ?> are selected.</strong> <a href="#" id="cg-select-clear">Clear selection</a></span>
				</div>
				<?php endif; ?>

				<div class="cg-table-wrap">
				<table class="wp-list-table widefat fixed striped cg-table">
					<thead><tr>
						<td class="manage-column check-column"><input type="checkbox" id="cg-cb-all"></td>
						<?php foreach ( $c['columns'] as $col => $label ) : ?>
							<?php
							$is_sorted  = $f['orderby'] === $col;
							$next_order = ( $is_sorted && $f['order'] === 'ASC' ) ? 'DESC' : 'ASC';
							?>
							<th scope="col" class="manage-column <?php echo $is_sorted ? 'sorted ' . esc_attr( strtolower( $f['order'] ) ) : 'sortable desc'; ?>">
								<a href="<?php echo esc_url( $this->list_url( $f, array( 'orderby' => $col, 'order' => $next_order ) ) ); ?>"><span><?php echo esc_html( $label ); ?></span><span class="sorting-indicators"><span class="sorting-indicator asc"></span><span class="sorting-indicator desc"></span></span></a>
							</th>
						<?php endforeach; ?>
						<?php foreach ( $this->extra_columns() as $label ) : ?>
							<th scope="col"><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
						<th scope="col">Actions</th>
					</tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<?php
						$colspan = count( $c['columns'] ) + count( $this->extra_columns() ) + 2;
						if ( $active ) {
							echo certificate_generator_ui_empty_row( $colspan, "No {$c['plural']} match these filters.", $this->list_url(), 'Clear filters' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
						} elseif ( $import_url ) {
							echo certificate_generator_ui_empty_row( $colspan, "No {$c['plural']} yet. Import a CSV or add one by hand.", $import_url, 'Import CSV' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
						} else {
							echo certificate_generator_ui_empty_row( $colspan, "No {$c['plural']} yet.", $edit_url, 'Add one' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
						}
						?>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php $row_id = (int) $row['id']; ?>
							<tr>
								<th scope="row" class="check-column"><input class="cg-cb" type="checkbox" name="<?php echo esc_attr( $c['ids_field'] ); ?>[]" value="<?php echo (int) $row_id; ?>"></th>
								<?php foreach ( array_keys( $c['columns'] ) as $col ) : ?>
									<td><?php echo $this->render_cell( $col, $row, $edit_url ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?></td>
								<?php endforeach; ?>
								<td><?php echo ! empty( $row['event_id'] ) && isset( $events_map[ $row['event_id'] ] ) ? esc_html( $events_map[ $row['event_id'] ] ) : '—'; ?></td>
								<?php if ( empty( $c['is_templates'] ) ) : ?>
									<td><?php echo $this->template_badge( $row ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<?php else : ?>
									<td><?php echo esc_html( number_format_i18n( $this->recipient_count( $row ) ) ); ?></td>
								<?php endif; ?>
								<?php if ( ! empty( $c['has_email'] ) ) : ?>
									<td class="cg-email-status-cell" data-row="<?php echo $row_id; ?>"><?php echo $this->email_badge( $row, $email_statuses ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<?php endif; ?>
								<td><?php echo $this->row_actions( $row, $edit_url ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
					</tbody>
				</table>
				</div>
				<div class="tablenav bottom"><?php $this->render_pagination( $f, $total, $total_pages ); ?><br class="clear"></div>
			</form>

			<?php if ( ! empty( $c['can_empty'] ) ) : ?>
				<details class="cg-danger-zone">
					<summary>Danger zone</summary>
					<form method="post" data-cg-danger data-cg-confirm-label="Empty table" data-cg-confirm="<?php echo esc_attr( 'Delete ALL ' . $c['plural'] . '? This cannot be undone.' ); ?>">
						<?php wp_nonce_field( $c['nonce_action'], $c['nonce_field'] ); ?>
						<input type="hidden" name="bulk_action" value="empty_all">
						<label>Type <code>DELETE</code> to remove every <?php echo esc_html( $c['singular'] ); ?> in this table:
							<input type="text" name="empty_confirm" autocomplete="off"></label>
						<button type="submit" class="button button-link-delete">Empty table</button>
					</form>
				</details>
			<?php endif; ?>
		</div>
		<?php
		$this->render_script( count( $rows ) );
	}

	private function extra_columns(): array {
		$c    = $this->config();
		$cols = array( 'Event', empty( $c['is_templates'] ) ? 'Template' : 'Recipients' );
		if ( ! empty( $c['has_email'] ) ) {
			$cols[] = 'Email Status';
		}
		return $cols;
	}

	private function render_filter_form( array $f, bool $has_active ): void {
		$c = $this->config();
		?>
		<form method="get" class="cg-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( $this->slug ); ?>">
			<?php if ( $f['orderby'] !== 'created_at' || $f['order'] !== 'DESC' ) : ?>
				<input type="hidden" name="orderby" value="<?php echo esc_attr( $f['orderby'] ); ?>">
				<input type="hidden" name="order" value="<?php echo esc_attr( $f['order'] ); ?>">
			<?php endif; ?>
			<input type="search" name="s" value="<?php echo esc_attr( $f['s'] ); ?>" placeholder="Search…">
			<?php foreach ( $c['select_filters'] as $param => $def ) : ?>
				<?php $options = $def['options'] ?? array_combine( $this->distinct( $def['col'] ), $this->distinct( $def['col'] ) ); ?>
				<?php
				if ( ! $options ) {
					continue;
				}
				?>
				<select name="<?php echo esc_attr( $param ); ?>">
					<option value="">— <?php echo esc_html( $def['label'] ); ?> —</option>
					<?php foreach ( $options as $val => $text ) : ?>
						<option value="<?php echo esc_attr( (string) $val ); ?>" <?php selected( $f[ $param ], (string) $val ); ?>><?php echo esc_html( (string) $text ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endforeach; ?>
			<?php if ( $this->events() ) : ?>
				<select name="event_filter">
					<option value="">— All Events —</option>
					<?php foreach ( $this->events() as $ev ) : ?>
						<option value="<?php echo esc_attr( $ev['id'] ); ?>" <?php selected( $f['event_filter'], (int) $ev['id'] ); ?>><?php echo esc_html( $ev['event_code'] . ' — ' . $ev['event_name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<?php if ( empty( $c['is_templates'] ) ) : ?>
				<select name="tpl_match">
					<option value="">— Any template status —</option>
					<option value="ready" <?php selected( $f['tpl_match'], 'ready' ); ?>>Template ready</option>
					<option value="pending" <?php selected( $f['tpl_match'], 'pending' ); ?>>Template scheduled / draft</option>
					<option value="none" <?php selected( $f['tpl_match'], 'none' ); ?>>No matching template</option>
				</select>
			<?php else : ?>
				<select name="usage">
					<option value="">— Any usage —</option>
					<option value="used" <?php selected( $f['usage'], 'used' ); ?>>In use</option>
					<option value="unused" <?php selected( $f['usage'], 'unused' ); ?>>Unused</option>
				</select>
			<?php endif; ?>
			<?php if ( ! empty( $c['has_email'] ) ) : ?>
				<select name="email_status">
					<option value="">— Any email status —</option>
					<?php foreach ( array( 'sent' => 'Sent', 'failed' => 'Failed', 'queued' => 'Queued', 'never' => 'Never sent', 'no_email' => 'Missing email' ) as $val => $text ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $f['email_status'], $val ); ?>><?php echo esc_html( $text ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>
			<label><?php echo $c['date_col'] === 'event_date' ? 'Event date' : 'Issue date'; ?>
				<input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ); ?>" aria-label="From"> –
				<input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ); ?>" aria-label="To">
			</label>
			<button type="submit" class="button">Filter</button>
			<?php if ( $has_active ) : ?>
				<a href="<?php echo esc_url( $this->list_url() ); ?>" class="button">Clear</a>
			<?php endif; ?>
		</form>
		<?php
	}

	private function render_bulk_controls(): void {
		$c       = $this->config();
		$actions = array( 'bulk_edit' => 'Bulk Edit' );
		if ( ! empty( $c['is_templates'] ) ) {
			$actions['duplicate_to_date'] = 'Duplicate to new event date';
		}
		if ( ! empty( $c['can_generate'] ) ) {
			$actions['generate'] = 'Generate certificates';
		}
		if ( ! empty( $c['has_email'] ) ) {
			$actions['send_email'] = 'Send email (queue)';
		}
		$actions['export'] = 'Export CSV';
		$actions['delete'] = 'Delete';
		?>
		<div class="alignleft actions bulkactions">
			<select name="bulk_action" id="cg-bulk-action">
				<option value="">Bulk Actions</option>
				<?php foreach ( $actions as $val => $text ) : ?>
					<option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $text ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="button action" id="cg-doaction">Apply</button>
			<div class="cg-bulk-panel" data-action="bulk_edit" hidden>
				<em>Blank fields are left unchanged.</em>
				<?php foreach ( $c['bulk_fields'] as $col => $type ) : ?>
					<?php $this->render_bulk_field( $col, $type ); ?>
				<?php endforeach; ?>
			</div>
			<?php if ( ! empty( $c['is_templates'] ) ) : ?>
				<div class="cg-bulk-panel" data-action="duplicate_to_date" hidden>
					<label>New event date <input type="date" name="bulk_new_event_date"></label>
					<label>Status
						<select name="bulk_new_status">
							<?php foreach ( $c['statuses'] as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $s, 'draft' ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_bulk_field( string $col, string $type ): void {
		$label = ucwords( str_replace( array( '_id', '_' ), array( '', ' ' ), $col ) );
		$name  = $type === 'event' ? 'bulk_event_id' : 'bulk_' . $col;
		echo '<label>' . esc_html( $label ) . ' ';
		switch ( $type ) {
			case 'date':
				echo '<input type="date" name="' . esc_attr( $name ) . '">';
				break;
			case 'type':
				echo '<select name="' . esc_attr( $name ) . '"><option value="">— unchanged —</option>';
				foreach ( $this->certificate_type_options() as $t ) {
					echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</option>';
				}
				echo '</select>';
				break;
			case 'type_free':
				echo '<input type="text" name="' . esc_attr( $name ) . '" list="cg-type-options" placeholder="— unchanged —"><datalist id="cg-type-options">';
				foreach ( $this->certificate_type_options() as $t ) {
					echo '<option value="' . esc_attr( $t ) . '">';
				}
				echo '</datalist>';
				break;
			case 'status':
			case 'entity_type':
				$opts = $type === 'status' ? $this->config()['statuses'] : self::ENTITY_TABLES;
				echo '<select name="' . esc_attr( $name ) . '"><option value="">— unchanged —</option>';
				foreach ( $opts as $o ) {
					echo '<option value="' . esc_attr( $o ) . '">' . esc_html( ucfirst( $o ) ) . '</option>';
				}
				echo '</select>';
				break;
			case 'event':
				echo '<select name="bulk_event_id"><option value="">— unchanged —</option>';
				foreach ( $this->events() as $ev ) {
					echo '<option value="' . esc_attr( $ev['id'] ) . '">' . esc_html( $ev['event_code'] . ' — ' . $ev['event_name'] ) . '</option>';
				}
				echo '</select>';
				break;
			default:
				echo '<input type="text" name="' . esc_attr( $name ) . '" placeholder="— unchanged —">';
		}
		echo '</label>';
	}

	private function render_pagination( array $f, int $total, int $total_pages ): void {
		?>
		<div class="tablenav-pages">
			<span class="displaying-num"><?php echo esc_html( number_format_i18n( $total ) ); ?> items</span>
			<?php if ( $total_pages > 1 ) : ?>
				<span class="pagination-links">
					<?php if ( $f['paged'] > 1 ) : ?>
						<a class="button" href="<?php echo esc_url( $this->list_url( $f, array( 'paged' => 1 ) ) ); ?>">«</a>
						<a class="button" href="<?php echo esc_url( $this->list_url( $f, array( 'paged' => $f['paged'] - 1 ) ) ); ?>">‹</a>
					<?php endif; ?>
					<span class="paging-input">Page <?php echo (int) $f['paged']; ?> of <?php echo (int) $total_pages; ?></span>
					<?php if ( $f['paged'] < $total_pages ) : ?>
						<a class="button" href="<?php echo esc_url( $this->list_url( $f, array( 'paged' => $f['paged'] + 1 ) ) ); ?>">›</a>
						<a class="button" href="<?php echo esc_url( $this->list_url( $f, array( 'paged' => $total_pages ) ) ); ?>">»</a>
					<?php endif; ?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Escaped HTML for one table cell. */
	protected function render_cell( string $col, array $row, string $edit_url ): string {
		$c   = $this->config();
		$val = (string) ( $row[ $col ] ?? '' );
		if ( $col === $c['name_col'] ) {
			return '<strong><a href="' . esc_url( add_query_arg( 'id', (int) $row['id'], $edit_url ) ) . '">' . esc_html( $val ) . '</a></strong>';
		}
		if ( $col === 'issue_date' || $col === 'event_date' ) {
			$d = $val !== '' && $val !== '0000-00-00' ? date_create( $val ) : false;
			return $d ? esc_html( $d->format( 'd-m-Y' ) ) : '—';
		}
		if ( $col === 'serial_number' ) {
			return '<code>' . esc_html( $val ) . '</code>';
		}
		if ( $col === 'status' || $col === 'entity_type' || $col === 'orientation' ) {
			return esc_html( ucfirst( $val ) );
		}
		return $val === '' ? '—' : esc_html( $val );
	}

	/** Which template this recipient's certificate will use — mirrors certificate_generator_select_certificate_template(). */
	private function template_badge( array $row ): string {
		$type  = strtolower( trim( (string) $row['certificate_type'] ) );
		$date  = substr( (string) ( $row['issue_date'] ?? '' ), 0, 10 );
		$state = 'none';
		foreach ( $this->templates() as $t ) {
			if ( strtolower( trim( (string) $t['certificate_type'] ) ) !== $type ) {
				continue;
			}
			$ev = substr( (string) $t['event_date'], 0, 10 );
			if ( $date !== '' && $ev !== '' && $ev !== '0000-00-00' && $ev !== $date ) {
				continue;
			}
			if ( $t['status'] === 'published' ) {
				$state = 'ready';
				break;
			}
			if ( in_array( $t['status'], array( 'scheduled', 'draft' ), true ) ) {
				$state = $t['status'];
			}
		}
		$badges = array(
			'ready'     => array( 'Ready', 'good' ),
			'scheduled' => array( 'Scheduled', 'warn' ),
			'draft'     => array( 'Draft', 'warn' ),
			'none'      => array( 'None', 'bad', 'No template with this certificate type and event date' ),
		);
		return certificate_generator_ui_badge( ...$badges[ $state ] );
	}

	/** Recipients whose certificate this template will render (same match rule as generation). */
	private function recipient_count( array $row ): int {
		global $wpdb;
		$entity = in_array( $row['entity_type'] ?? '', self::ENTITY_TABLES, true ) ? $row['entity_type'] : 'students';
		$tbl    = CustomTables::instance()->get_table( $entity );
		$ev     = substr( (string) ( $row['event_date'] ?? '' ), 0, 10 );
		if ( $ev === '' || $ev === '0000-00-00' ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE certificate_type = %s", $row['certificate_type'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $tbl WHERE certificate_type = %s AND issue_date = %s", $row['certificate_type'], $ev ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function email_badge( array $row, array $statuses ): string {
		if ( empty( $row['email'] ) ) {
			return certificate_generator_ui_badge( 'No Email' );
		}
		$badge = $statuses[ $row['email'] ] ?? array( 'status' => 'NotSent', 'last_error' => '' );
		switch ( $badge['status'] ) {
			case 'Sent':
				return certificate_generator_ui_badge( 'Sent', 'good', '', 'cg-email-badge cg-email-sent' );
			case 'Failed':
				return certificate_generator_ui_badge( 'Failed', 'bad', (string) ( $badge['last_error'] ?? '' ), 'cg-email-badge cg-email-failed' );
			case 'Sending':
				return certificate_generator_ui_badge( 'Sending', 'info', '', 'cg-email-badge cg-email-sending' );
			case 'Queued':
				return certificate_generator_ui_badge( 'Queued', 'info', '', 'cg-email-badge cg-email-queued' );
		}
		return certificate_generator_ui_badge( 'Pending', 'warn', '', 'cg-email-badge cg-email-pending' );
	}

	protected function row_actions( array $row, string $edit_url ): string {
		$c       = $this->config();
		$id      = (int) $row['id'];
		$links   = array( '<a href="' . esc_url( add_query_arg( 'id', $id, $edit_url ) ) . '">Edit</a>' );
		if ( ! empty( $c['is_templates'] ) ) {
			$dup     = wp_nonce_url( add_query_arg( array( 'page' => $this->slug, 'action' => 'duplicate', 'id' => $id ), admin_url( 'admin.php' ) ), 'cg_duplicate_template_' . $id );
			$links[] = '<a href="' . esc_url( $dup ) . '">Duplicate</a>';
		}
		$del     = wp_nonce_url( $this->list_url( $this->filters(), array( 'action' => 'delete', 'id' => $id ) ), 'cg_delete_' . $c['singular'] . '_single_' . $id );
		$links[] = '<a href="' . esc_url( $del ) . '" class="cg-link-delete" data-cg-danger data-cg-confirm-label="Delete" data-cg-confirm="' . esc_attr( 'Delete this ' . $c['singular'] . '? This cannot be undone.' ) . '">Delete</a>';
		if ( ! empty( $c['has_email'] ) && ! empty( $row['email'] ) ) {
			$links[] = '<button type="button" class="button-link cg-send-email-btn" data-id="' . $id . '">Send Email</button>';
		}
		return implode( ' | ', $links );
	}

	private function render_script( int $page_count ): void {
		$c = $this->config();
		?>
		<script>
		(function () {
			<?php if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) : ?>
			// Turn the POST history entry into a GET so F5 doesn't repeat the bulk action.
			if (history.replaceState) { history.replaceState(null, '', location.href); }
			<?php endif; ?>
			var form = document.getElementById('cg-list-form');
			if (!form) { return; }
			var sel = document.getElementById('cg-bulk-action');
			var all = document.getElementById('cg-cb-all');
			var matching = document.getElementById('cg-select-all-matching');
			var banner = document.getElementById('cg-select-all-banner');
			var boxes = function () { return form.querySelectorAll('.cg-cb'); };

			function setMatching(on) {
				matching.value = on ? '1' : '0';
				if (!banner) { return; }
				document.getElementById('cg-select-all-link').hidden = on;
				document.getElementById('cg-all-selected-note').hidden = !on;
			}
			all.addEventListener('change', function () {
				boxes().forEach(function (cb) { cb.checked = all.checked; });
				if (banner) { banner.hidden = !all.checked; }
				setMatching(false);
			});
			form.addEventListener('change', function (e) {
				if (e.target.classList.contains('cg-cb') && !e.target.checked) {
					all.checked = false;
					if (banner) { banner.hidden = true; }
					setMatching(false);
				}
			});
			if (banner) {
				document.getElementById('cg-select-all-link').addEventListener('click', function (e) { e.preventDefault(); setMatching(true); });
				document.getElementById('cg-select-clear').addEventListener('click', function (e) {
					e.preventDefault();
					all.checked = false;
					boxes().forEach(function (cb) { cb.checked = false; });
					banner.hidden = true;
					setMatching(false);
				});
			}
			sel.addEventListener('change', function () {
				form.querySelectorAll('.cg-bulk-panel').forEach(function (p) {
					p.hidden = p.getAttribute('data-action') !== sel.value;
				});
			});

			document.getElementById('cg-doaction').addEventListener('click', function (e) {
				var action = sel.value;
				if (!action) { e.preventDefault(); return; }
				var n = matching.value === '1' ? <?php echo (int) $page_count; ?> : form.querySelectorAll('.cg-cb:checked').length;
				var label = matching.value === '1' ? 'ALL matching <?php echo esc_js( $c['plural'] ); ?>' : n + ' selected <?php echo esc_js( $c['singular'] ); ?>(s)';
				if (!n) { e.preventDefault(); alert('Select at least one <?php echo esc_js( $c['singular'] ); ?>.'); return; }
				if (action === 'bulk_edit') {
					var panel = form.querySelector('.cg-bulk-panel[data-action="bulk_edit"]');
					var any = Array.prototype.some.call(panel.querySelectorAll('input,select'), function (el) { return el.value !== ''; });
					if (!any) { e.preventDefault(); alert('Choose at least one field to change.'); return; }
				}
				if (action === 'duplicate_to_date' && !form.querySelector('[name="bulk_new_event_date"]').value) {
					e.preventDefault(); alert('Choose the new event date.'); return;
				}
				var verbs = { delete: 'Delete', bulk_edit: 'Update', generate: 'Generate certificates for', send_email: 'Queue emails to', duplicate_to_date: 'Duplicate' };
				if (!verbs[action]) { return; } // Export: plain submit; the page stays while the file downloads.
				var btn = this;
				e.preventDefault();
				CGUI.confirm({
					message: verbs[action] + ' ' + label + '?' + (action === 'delete' ? ' This cannot be undone.' : ''),
					confirmLabel: verbs[action].split(' ')[0],
					danger: action === 'delete'
				}).then(function (ok) {
					if (!ok) { return; }
					if (form.requestSubmit) { form.requestSubmit(btn); } else { form.submit(); }
					CGUI.busy(btn, true);
				});
			});

			<?php if ( ! empty( $c['has_email'] ) ) : ?>
			var nonce = '<?php echo esc_js( wp_create_nonce( 'cg_' . $c['singular'] . '_send_email' ) ); ?>';
			form.querySelectorAll('.cg-send-email-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var id = btn.getAttribute('data-id');
					CGUI.busy(btn, true);
					var data = new URLSearchParams({ action: 'certificate_generator_<?php echo esc_js( $c['singular'] ); ?>_send_email', nonce: nonce, id: id });
					fetch('<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>', { method: 'POST', body: data })
						.then(function (r) { return r.json(); })
						.then(function (resp) {
							if (resp.success) {
								var cell = form.querySelector('.cg-email-status-cell[data-row="' + id + '"]');
								if (cell) { cell.innerHTML = <?php echo wp_json_encode( certificate_generator_ui_badge( 'Sent', 'good', '', 'cg-email-badge cg-email-sent' ) ); ?>; }
								CGUI.busy(btn, false);
								btn.textContent = 'Sent ✓';
								btn.disabled = true;
							} else {
								alert('Send failed: ' + (resp.data && resp.data.message ? resp.data.message : 'Unknown error'));
								CGUI.busy(btn, false);
							}
						})
						.catch(function () { alert('Request failed. Check network and try again.'); CGUI.busy(btn, false); });
				});
			});
			<?php endif; ?>
		})();
		</script>
		<?php
	}

	private function valid_date( $raw ): string {
		$raw = is_string( $raw ) ? trim( $raw ) : '';
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $raw;
	}
}
