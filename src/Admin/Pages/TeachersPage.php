<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Database\CustomTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teachers admin page — list + CRUD form backed by wp_cg_teachers.
 */
class TeachersPage extends EntityListPage {

	protected string $slug      = 'cg-teachers';
	protected string $edit_slug = 'cg-teacher-edit';
	protected string $table_key = 'teachers';

	public function register(): void {
		$this->add_list_page( 'Teachers', 'Teachers' );
		add_submenu_page( '', 'Add / Edit Teacher', '', 'manage_options', $this->edit_slug, array( $this, 'render_edit' ) );
	}

	protected function config(): array {
		return array(
			'title'          => 'Teachers',
			'lead'           => 'Teachers who receive certificates. Filter, bulk edit, generate or email certificates from here.',
			'can_import'     => true,
			'singular'       => 'teacher',
			'plural'         => 'teachers',
			'name_col'       => 'teacher_name',
			'ids_field'      => 'teacher_ids',
			'nonce_action'   => 'cg_delete_teacher',
			'nonce_field'    => 'cg_delete_teacher_nonce',
			'search_cols'    => array( 'teacher_name', 'email', 'serial_number' ),
			'columns'        => array(
				'teacher_name'     => 'Name',
				'email'            => 'Email',
				'school_name'      => 'School',
				'department'       => 'Department',
				'certificate_type' => 'Cert. Type',
				'issue_date'       => 'Issue Date',
				'serial_number'    => 'Serial #',
				'status'           => 'Status',
			),
			'select_filters' => array(
				'school_filter' => array( 'col' => 'school_name', 'label' => 'All Schools' ),
				'cert_filter'   => array( 'col' => 'certificate_type', 'label' => 'All Certificate Types' ),
				'status_filter' => array( 'col' => 'status', 'label' => 'All Statuses', 'options' => array( 'active' => 'Active', 'inactive' => 'Inactive', 'retired' => 'Retired' ) ),
				'year_filter'   => array( 'col' => 'year', 'label' => 'All Years' ),
				'import_filter' => array( 'col' => 'import_source', 'label' => 'All Imports' ),
			),
			'statuses'       => array( 'active', 'inactive', 'retired' ),
			'date_col'       => 'issue_date',
			'bulk_fields'    => array(
				'school_name'      => 'text',
				'department'       => 'text',
				'certificate_type' => 'type',
				'issue_date'       => 'date',
				'status'           => 'status',
				'event_id'         => 'event',
			),
			'has_email'      => true,
			'can_generate'   => true,
			'gen_fields'     => array( 'teacher_name', 'school_name', 'issue_date' ),
		);
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
		$extra   = ! empty( $row['extra_fields'] ) ? ( json_decode( $row['extra_fields'], true ) ?: array() ) : array();
		$message = '';
		$errors  = array();

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ( empty( $_POST['cg_teacher_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_teacher_nonce'] ) ), 'cg_save_teacher' ) ) ) {
			$errors[] = 'Security check failed (the page may have been open too long) — please try saving again.';
		}

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ! empty( $_POST['cg_teacher_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_teacher_nonce'] ) ), 'cg_save_teacher' ) ) {

			$name  = sanitize_text_field( wp_unslash( $_POST['teacher_name'] ?? '' ) );
			$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			if ( ! $name ) {
				$errors[] = 'Teacher name is required.';
			}
			if ( ! $email ) {
				$errors[] = 'Email is required.';
			}

			if ( empty( $errors ) ) {
				$new_extra = array();
				$ex_keys   = map_deep( (array) wp_unslash( $_POST['extra_key'] ?? array() ), 'sanitize_text_field' );
				$ex_vals   = map_deep( (array) wp_unslash( $_POST['extra_val'] ?? array() ), 'sanitize_text_field' );
				foreach ( $ex_keys as $i => $k ) {
					$k = sanitize_key( $k );
					$v = sanitize_text_field( $ex_vals[ $i ] ?? '' );
					if ( $k && $v !== '' ) {
						$new_extra[ $k ] = $v;
					}
				}
				$data = array(
					'teacher_name'     => $name,
					'email'            => $email,
					'phone'            => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
					'school_name'      => sanitize_text_field( wp_unslash( $_POST['school_name'] ?? '' ) ),
					'event_id'         => absint( $_POST['event_id'] ?? 0 ) ?: null,
					'department'       => sanitize_text_field( wp_unslash( $_POST['department'] ?? '' ) ),
					'certificate_type' => sanitize_text_field( wp_unslash( $_POST['certificate_type'] ?? '' ) ),
					'issue_date'       => sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) ) ?: null,
					'year'             => function_exists( 'cg_year_from_issue_date' ) ? cg_year_from_issue_date( sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) ) ?: null ) : null,
					'hire_date'        => sanitize_text_field( wp_unslash( $_POST['hire_date'] ?? '' ) ) ?: null,
					'status'           => in_array( $_POST['status'] ?? '', array( 'active', 'inactive', 'retired' ), true ) ? sanitize_key( $_POST['status'] ) : 'active',
					'extra_fields'     => ! empty( $new_extra ) ? wp_json_encode( $new_extra ) : null,
					'updated_at'       => current_time( 'mysql' ),
				);
				if ( $id && $row ) {
					$result = $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $result === false ) {
						$errors[] = 'Teacher was not saved — database error: ' . $wpdb->last_error;
					} else {
						$message = 'Teacher updated.';
						$row     = array_merge( $row, $data );
						$extra   = $new_extra;
					}
				} else {
					$data['created_at'] = current_time( 'mysql' );
					$result              = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $result === false ) {
						$errors[] = 'Teacher was not saved — database error: ' . $wpdb->last_error;
					} else {
						$id      = (int) $wpdb->insert_id;
						$row     = array_merge( $data, array( 'id' => $id ) );
						$extra   = $new_extra;
						$message = 'Teacher added.';
					}
				}

				if ( function_exists( 'cg_flush_filter_caches' ) ) {
					cg_flush_filter_caches();
				}

				// Phase 6: sync to WP Dynamic Tags if a wp_post_id is linked
				$wp_post_id = (int) ( $row['wp_post_id'] ?? 0 );
				if ( $wp_post_id > 0 && function_exists( 'cg_sync_to_dynamic_tags' ) ) {
					cg_sync_to_dynamic_tags( $wp_post_id );
				}
			}
		}

		$list_url = admin_url( 'admin.php?page=' . $this->slug );
		?>
		<div class="wrap">
			<h1><?php echo $id ? 'Edit Teacher' : 'Add New Teacher'; ?></h1>
			<a href="<?php echo esc_url( $list_url ); ?>">← Back to Teachers</a>

			<?php
			foreach ( $errors as $e ) :
				?>
				<div class="notice notice-error"><p><?php echo esc_html( $e ); ?></p></div><?php endforeach; ?>
			<?php
			if ( $message ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>

			<form method="post" style="max-width:700px; margin-top:20px;">
				<?php wp_nonce_field( 'cg_save_teacher', 'cg_teacher_nonce' ); ?>
				<table class="form-table">
					<tr><th><label for="teacher_name">Name <span style="color:red">*</span></label></th>
						<td><input type="text" id="teacher_name" name="teacher_name" class="regular-text" required value="<?php echo esc_attr( $row['teacher_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="email">Email <span style="color:red">*</span></label></th>
						<td><input type="email" id="email" name="email" class="regular-text" required value="<?php echo esc_attr( $row['email'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="phone">Phone</label></th>
						<td><input type="text" id="phone" name="phone" class="regular-text" value="<?php echo esc_attr( $row['phone'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="school_name">School Name</label></th>
						<td><input type="text" id="school_name" name="school_name" class="regular-text" value="<?php echo esc_attr( $row['school_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="event_id">Event</label></th>
						<td><select id="event_id" name="event_id">
							<option value="">— None —</option>
							<?php
							$events_table = CustomTables::instance()->get_table( 'events' );
							$events       = $wpdb->get_results( "SELECT id, event_code, event_name FROM $events_table ORDER BY event_code", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							foreach ( (array) $events as $ev ) :
								?>
								<option value="<?php echo esc_attr( $ev['id'] ); ?>" <?php selected( (int) ( $row['event_id'] ?? 0 ), (int) $ev['id'] ); ?>><?php echo esc_html( $ev['event_code'] . ' — ' . $ev['event_name'] ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th><label for="department">Department</label></th>
						<td><input type="text" id="department" name="department" class="regular-text" value="<?php echo esc_attr( $row['department'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="certificate_type">Certificate Type</label></th>
						<td><input type="text" id="certificate_type" name="certificate_type" class="regular-text" value="<?php echo esc_attr( $row['certificate_type'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="issue_date">Issue Date</label></th>
						<td><input type="date" id="issue_date" name="issue_date" value="<?php echo esc_attr( $row['issue_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="hire_date">Hire Date</label></th>
						<td><input type="date" id="hire_date" name="hire_date" value="<?php echo esc_attr( $row['hire_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="status">Status</label></th>
						<td><select id="status" name="status">
							<?php foreach ( array( 'active', 'inactive', 'retired' ) as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $row['status'] ?? 'active', $s ); ?>><?php echo esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
				</table>

				<?php if ( ! empty( $row['serial_number'] ) ) : ?>
					<p><strong>Serial Number:</strong> <code><?php echo esc_html( $row['serial_number'] ); ?></code></p>
				<?php endif; ?>

				<h3>Extra Fields</h3>
				<table class="widefat" style="max-width:500px; margin-bottom:8px;">
					<thead><tr><th>Key</th><th>Value</th><th></th></tr></thead>
					<tbody id="cg-extra-tbody-t">
						<?php foreach ( $extra as $k => $v ) : ?>
						<tr>
							<td><input type="text" name="extra_key[]" value="<?php echo esc_attr( $k ); ?>" class="regular-text"></td>
							<td><input type="text" name="extra_val[]" value="<?php echo esc_attr( $v ); ?>" class="regular-text"></td>
							<td><button type="button" class="button button-small cg-rm-row">✕</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="button" id="cg-add-extra-t">+ Add Field</button>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $id ? 'Update Teacher' : 'Add Teacher'; ?></button>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button">Cancel</a>
				</p>
			</form>
		</div>
		<script>
		document.getElementById('cg-add-extra-t').addEventListener('click', function() {
			var tbody = document.getElementById('cg-extra-tbody-t');
			var tr = document.createElement('tr');
			var cells = [
				{type:'text', name:'extra_key[]', placeholder:'key'},
				{type:'text', name:'extra_val[]', placeholder:'value'}
			];
			cells.forEach(function(c) {
				var td = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = c.type; inp.name = c.name; inp.className = 'regular-text'; inp.placeholder = c.placeholder;
				td.appendChild(inp); tr.appendChild(td);
			});
			var td3 = document.createElement('td');
			var btn = document.createElement('button');
			btn.type = 'button'; btn.className = 'button button-small'; btn.textContent = '✕';
			btn.addEventListener('click', function(){ tr.parentNode.removeChild(tr); });
			td3.appendChild(btn); tr.appendChild(td3);
			tbody.appendChild(tr);
		});
		document.querySelectorAll('.cg-rm-row').forEach(function(btn){
			btn.addEventListener('click', function(){ this.closest('tr').remove(); });
		});
		</script>
		<?php
	}
}
