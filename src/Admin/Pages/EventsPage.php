<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Database\CustomTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events admin page — list + CRUD form backed by wp_cg_events.
 *
 * Students/teachers/schools/certificate_templates each carry an `event_id`
 * FK to this table, so an event's edit view also shows how many of each
 * are currently linked to it.
 */
class EventsPage {

	private string $slug      = 'cg-events';
	private string $edit_slug = 'cg-event-edit';
	private string $table_key = 'events';

	private const STATUSES = array( 'draft', 'open', 'closed', 'completed', 'archived' );

	private const STATUS_BADGES = array(
		'open'      => 'good',
		'closed'    => 'warn',
		'completed' => 'info',
	);

	public function register(): void {
		add_submenu_page( 'cg-dashboard', 'Events', 'Events', 'manage_options', $this->slug, array( $this, 'render_list' ) );
		add_submenu_page( '', 'Add / Edit Event', '', 'manage_options', $this->edit_slug, array( $this, 'render_edit' ) );
	}

	// ── List View ────────────────────────────────────────────────────────────

	public function render_list(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		global $wpdb;
		$table   = CustomTables::instance()->get_table( $this->table_key );
		$message = '';

		if ( ! empty( $_POST['cg_delete_event_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_delete_event_nonce'] ) ), 'cg_delete_event' ) ) {
			$ids = array_map( 'absint', (array) ( $_POST['event_ids'] ?? array() ) );
			foreach ( $ids as $id ) {
				if ( $id > 0 ) {
					$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
			$message = count( $ids ) . ' event(s) deleted.';
		}

		if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'delete' && ! empty( $_GET['id'] ) ) {
			$del_id = absint( $_GET['id'] );
			if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cg_delete_event_single_' . $del_id ) ) {
				$wpdb->delete( $table, array( 'id' => $del_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				wp_safe_redirect( admin_url( 'admin.php?page=' . $this->slug . '&deleted=1' ) );
				exit;
			}
		}

		$search   = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$status_f = sanitize_text_field( wp_unslash( $_GET['status_filter'] ?? '' ) );
		$paged    = max( 1, (int) ( absint( wp_unslash( $_GET['paged'] ?? 1 ) ) ) );
		$per_page = 20;
		$allowed  = array( 'event_code', 'event_name', 'start_date', 'end_date', 'registration_deadline', 'status', 'year', 'created_at' );
		$orderby  = in_array( $_GET['orderby'] ?? '', $allowed, true ) ? sanitize_key( $_GET['orderby'] ) : 'start_date';
		$order    = strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ?? 'DESC' ) ) ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = '1=1';
		$params = array();
		if ( $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND (event_code LIKE %s OR event_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}
		if ( $status_f ) {
			$where   .= ' AND status = %s';
			$params[] = $status_f;
		}

		$offset     = ( $paged - 1 ) * $per_page;
		$count_sql  = "SELECT COUNT(*) FROM $table WHERE $where"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total      = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$data_sql   = "SELECT * FROM $table WHERE $where ORDER BY $orderby $order LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows       = $wpdb->get_results( $wpdb->prepare( $data_sql, ...array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Batch-fetch linked-record counts for this page's events (one GROUP BY query per related table).
		$link_counts = array();
		$page_ids    = array_map( 'intval', array_column( $rows, 'id' ) );
		if ( ! empty( $page_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $page_ids ), '%d' ) );
			foreach ( array( 'students', 'teachers', 'schools', 'certificate_templates' ) as $related_key ) {
				$related_table = CustomTables::instance()->get_table( $related_key );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
				$counts = $wpdb->get_results(
					$wpdb->prepare( "SELECT event_id, COUNT(*) AS c FROM $related_table WHERE event_id IN ($placeholders) GROUP BY event_id", ...$page_ids ),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $counts as $c ) {
					$link_counts[ (int) $c['event_id'] ][ $related_key ] = (int) $c['c'];
				}
			}
		}

		$total_pages = (int) ceil( $total / $per_page );
		$list_url    = admin_url( 'admin.php?page=' . $this->slug );
		$edit_url    = admin_url( 'admin.php?page=' . $this->edit_slug );
		?>
		<div class="wrap">
			<?php
			cg_ui_page_header(
				'Events',
				'Group students, teachers, schools and templates under one event code.',
				'<a href="' . esc_url( $edit_url ) . '" class="button button-primary">Add New</a>'
			);
			if ( $message ) {
				cg_ui_notice( 'success', esc_html( $message ) );
			}
			?>

			<form method="get" class="cg-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( $this->slug ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search code or name…">
				<select name="status_filter">
					<option value="">— All Statuses —</option>
					<?php foreach ( self::STATUSES as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_f, $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button">Filter</button>
				<?php if ( $search || $status_f ) : ?>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button">Clear</a>
				<?php endif; ?>
			</form>

			<form method="post" id="cg-events-form">
				<?php wp_nonce_field( 'cg_delete_event', 'cg_delete_event_nonce' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<select name="bulk_action" id="cg-bulk-action-event"><option value="">Bulk Actions</option><option value="delete">Delete</option></select>
						<button type="submit" class="button action" id="cg-doaction-event">Apply</button>
					</div>
					<div class="tablenav-pages">
						<span class="displaying-num"><?php echo number_format( $total ); ?> items</span>
						<?php if ( $total_pages > 1 ) : ?>
							<?php if ( $paged > 1 ) : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, $list_url ) ); ?>">‹</a>
							<?php endif; ?>
							<span class="paging-input">Page <?php echo (int) $paged; ?> of <?php echo (int) $total_pages; ?></span>
							<?php if ( $paged < $total_pages ) : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, $list_url ) ); ?>">›</a>
							<?php endif; ?>
						<?php endif; ?>
					</div>
					<br class="clear">
				</div>

				<div class="cg-table-wrap">
				<table class="wp-list-table widefat fixed striped cg-table">
					<thead><tr>
						<td class="manage-column check-column"><input type="checkbox" id="cb-select-all-ev"></td>
						<?php
						$cols = array(
							'event_code'             => 'Code',
							'event_name'             => 'Name',
							'start_date'             => 'Start',
							'end_date'                => 'End',
							'registration_deadline'  => 'Reg. Deadline',
							'status'                  => 'Status',
						);
						foreach ( $cols as $col_key => $col_label ) :
							$next_order = ( $orderby === $col_key && $order === 'ASC' ) ? 'DESC' : 'ASC';
							$sort_url   = add_query_arg( array( 'orderby' => $col_key, 'order' => $next_order, 'paged' => 1 ), $list_url );
							?>
						<th scope="col" class="<?php echo $orderby === $col_key ? 'sorted ' . esc_html( strtolower( $order ) ) : 'sortable desc'; ?>">
							<a href="<?php echo esc_url( $sort_url ); ?>"><span><?php echo esc_html( $col_label ); ?></span></a>
						</th>
						<?php endforeach; ?>
						<th scope="col">Linked Records</th>
						<th>Actions</th>
					</tr></thead>
					<tbody>
						<?php if ( empty( $rows ) ) : ?>
							<?php
							echo wp_kses_post(
								( $search || $status_f )
								? cg_ui_empty_row( 9, 'No events match these filters.', $list_url, 'Clear filters' )
								: cg_ui_empty_row( 9, 'No events yet. Create one to group records under an event code.', $edit_url, 'Add event' )
							);
							?>
							<?php
						else :
							foreach ( $rows as $row ) :
								$row_id = (int) $row['id'];
								$counts = $link_counts[ $row_id ] ?? array();
								$parts  = array();
								foreach ( array( 'students' => 'student', 'teachers' => 'teacher', 'schools' => 'school', 'certificate_templates' => 'template' ) as $key => $label ) {
									if ( ! empty( $counts[ $key ] ) ) {
										$parts[] = $counts[ $key ] . ' ' . $label . ( $counts[ $key ] > 1 ? 's' : '' );
									}
								}
								?>
						<tr>
							<th scope="row" class="check-column"><input class="cb-select-ev" type="checkbox" name="event_ids[]" value="<?php echo (int) $row_id; ?>"></th>
							<td><strong><a href="<?php echo esc_url( add_query_arg( 'id', $row_id, $edit_url ) ); ?>"><?php echo esc_html( $row['event_code'] ); ?></a></strong></td>
							<td><?php echo esc_html( $row['event_name'] ); ?></td>
							<td><?php echo esc_html( $row['start_date'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $row['end_date'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $row['registration_deadline'] ?? '—' ); ?></td>
							<td><?php echo cg_ui_badge( ucfirst( $row['status'] ?? 'draft' ), self::STATUS_BADGES[ $row['status'] ?? 'draft' ] ?? 'muted' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo $parts ? esc_html( implode( ' · ', $parts ) ) : '—'; ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( 'id', $row_id, $edit_url ) ); ?>">Edit</a>
								&nbsp;|&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => $this->slug, 'action' => 'delete', 'id' => $row_id ), admin_url( 'admin.php' ) ), 'cg_delete_event_single_' . $row_id ) ); ?>"
									class="cg-link-delete" data-cg-danger data-cg-confirm-label="Delete" data-cg-confirm="Delete this event? Linked students, teachers, schools and templates keep their own data but lose the event link.">Delete</a>
							</td>
						</tr>
								<?php
							endforeach;
						endif;
						?>
					</tbody>
				</table>
				</div>
			</form>
		</div>
		<script>
		document.getElementById('cb-select-all-ev').addEventListener('change', function() {
			document.querySelectorAll('.cb-select-ev').forEach(function(cb){ cb.checked = this.checked; }.bind(this));
		});
		document.getElementById('cg-doaction-event').addEventListener('click', function(e) {
			if (document.getElementById('cg-bulk-action-event').value !== 'delete') { e.preventDefault(); return; }
			if (!document.querySelectorAll('.cb-select-ev:checked').length) { e.preventDefault(); alert('Select at least one event.'); return; }
			var btn = this, form = document.getElementById('cg-events-form');
			e.preventDefault();
			CGUI.confirm({ message: 'Delete the selected events? Linked records keep their own data but lose the event link.', confirmLabel: 'Delete', danger: true }).then(function (ok) {
				if (!ok) { return; }
				if (form.requestSubmit) { form.requestSubmit(btn); } else { form.submit(); }
				CGUI.busy(btn, true);
			});
		});
		</script>
		<?php
	}

	// ── Edit / Add Form ──────────────────────────────────────────────────────

	public function render_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		global $wpdb;
		$table   = CustomTables::instance()->get_table( $this->table_key );
		$id      = absint( $_GET['id'] ?? 0 );
		$row     = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$message = '';
		$errors  = array();

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ( empty( $_POST['cg_event_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_event_nonce'] ) ), 'cg_save_event' ) ) ) {
			$errors[] = 'Security check failed (the page may have been open too long) — please try saving again.';
		}

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ! empty( $_POST['cg_event_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_event_nonce'] ) ), 'cg_save_event' ) ) {

			$event_code = strtoupper( sanitize_text_field( wp_unslash( $_POST['event_code'] ?? '' ) ) );
			$event_name = sanitize_text_field( wp_unslash( $_POST['event_name'] ?? '' ) );
			if ( ! $event_code ) {
				$errors[] = 'Event code is required.';
			}
			if ( ! $event_name ) {
				$errors[] = 'Event name is required.';
			}

			if ( empty( $errors ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
				$dupe = $wpdb->get_var(
					$id
						? $wpdb->prepare( "SELECT id FROM $table WHERE event_code = %s AND id != %d", $event_code, $id )
						: $wpdb->prepare( "SELECT id FROM $table WHERE event_code = %s", $event_code )
				);
				// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $dupe ) {
					$errors[] = "Event code \"{$event_code}\" is already used by another event.";
				}
			}

			if ( empty( $errors ) ) {
				$start_date = sanitize_text_field( wp_unslash( $_POST['start_date'] ?? '' ) ) ?: null;
				$data       = array(
					'event_code'             => $event_code,
					'event_name'             => $event_name,
					'start_date'             => $start_date,
					'end_date'               => sanitize_text_field( wp_unslash( $_POST['end_date'] ?? '' ) ) ?: null,
					'registration_deadline'  => sanitize_text_field( wp_unslash( $_POST['registration_deadline'] ?? '' ) ) ?: null,
					'year'                   => $start_date ? (int) substr( $start_date, 0, 4 ) : ( absint( $_POST['year'] ?? 0 ) ?: null ),
					'status'                 => in_array( $_POST['status'] ?? '', self::STATUSES, true ) ? sanitize_key( $_POST['status'] ) : 'draft',
					'description'            => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ) ?: null,
					'updated_at'             => current_time( 'mysql' ),
				);

				if ( $id && $row ) {
					$result = $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $result === false ) {
						$errors[] = 'Event was not saved — database error: ' . $wpdb->last_error;
					} else {
						$message = 'Event updated.';
						$row     = array_merge( $row, $data );
					}
				} else {
					$data['created_at'] = current_time( 'mysql' );
					$result              = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $result === false ) {
						$errors[] = 'Event was not saved — database error: ' . $wpdb->last_error;
					} else {
						$id      = (int) $wpdb->insert_id;
						$row     = array_merge( $data, array( 'id' => $id ) );
						$message = 'Event added.';
					}
				}
			}
		}

		// Linked-record counts for this event (edit view only — nothing to count when adding).
		$link_counts = array();
		if ( $id ) {
			foreach ( array( 'students', 'teachers', 'schools', 'certificate_templates' ) as $related_key ) {
				$related_table               = CustomTables::instance()->get_table( $related_key );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$link_counts[ $related_key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $related_table WHERE event_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			}
		}

		$list_url = admin_url( 'admin.php?page=' . $this->slug );
		?>
		<div class="wrap">
			<?php
			cg_ui_page_header(
				$id ? 'Edit Event' : 'Add New Event',
				'An event code ties students, teachers, schools and templates together.',
				'<a href="' . esc_url( $list_url ) . '" class="button">← Back to Events</a>'
			);
			foreach ( $errors as $e ) {
				cg_ui_notice( 'error', esc_html( $e ), false );
			}
			if ( $message ) {
				cg_ui_notice( 'success', esc_html( $message ) );
			}
			cg_ui_card_open( 'Event details', array( 'class' => 'cg-narrow' ) );
			?>
			<form method="post">
				<?php wp_nonce_field( 'cg_save_event', 'cg_event_nonce' ); ?>
				<table class="form-table">
					<tr><th><label for="event_code">Event Code <span class="required">*</span></label></th>
						<td><input type="text" id="event_code" name="event_code" class="regular-text" required
							placeholder="e.g. PO-06-2026" value="<?php echo esc_attr( $row['event_code'] ?? '' ); ?>">
							<p class="description">Human-readable identifier: PREFIX-MONTH-YEAR, e.g. Python Olympiad run in June 2026 → PO-06-2026.</p></td></tr>
					<tr><th><label for="event_name">Event Name <span class="required">*</span></label></th>
						<td><input type="text" id="event_name" name="event_name" class="regular-text" required value="<?php echo esc_attr( $row['event_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="start_date">Start Date</label></th>
						<td><input type="date" id="start_date" name="start_date" value="<?php echo esc_attr( $row['start_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="end_date">End Date</label></th>
						<td><input type="date" id="end_date" name="end_date" value="<?php echo esc_attr( $row['end_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="registration_deadline">Last Date of Registration</label></th>
						<td><input type="date" id="registration_deadline" name="registration_deadline" value="<?php echo esc_attr( $row['registration_deadline'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="status">Status</label></th>
						<td><select id="status" name="status">
							<?php foreach ( self::STATUSES as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $row['status'] ?? 'draft', $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th><label for="description">Description</label></th>
						<td><textarea id="description" name="description" class="large-text" rows="4"><?php echo esc_textarea( $row['description'] ?? '' ); ?></textarea></td></tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $id ? 'Update Event' : 'Add Event'; ?></button>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button">Cancel</a>
				</p>
			</form>
			<?php cg_ui_card_close(); ?>

			<?php if ( $id ) : ?>
				<?php cg_ui_card_open( 'Linked Records', array( 'class' => 'cg-narrow' ) ); ?>
				<div class="cg-stats">
					<?php
					cg_ui_stat( 'Students', $link_counts['students'] );
					cg_ui_stat( 'Teachers', $link_counts['teachers'] );
					cg_ui_stat( 'Schools', $link_counts['schools'] );
					cg_ui_stat( 'Templates', $link_counts['certificate_templates'] );
					?>
				</div>
				<p class="cg-hint">Link records to this event from their own Add/Edit screens (Event field), or via the <code>event_code</code> column in bulk import CSVs.</p>
				<?php cg_ui_card_close(); ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
