<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use function add_submenu_page;
use function current_user_can;
use function wp_die;
use function wp_verify_nonce;
use function sanitize_text_field;
use function wp_unslash;
use function sanitize_email;
use function sanitize_key;
use function admin_url;
use function esc_url;
use function esc_html;
use function esc_attr;
use function selected;
use function number_format;
use function wp_nonce_field;
use function wp_safe_redirect;
use function add_query_arg;
use function wp_nonce_url;

use CertificateGenerator\Database\CustomTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Students admin page — list + CRUD form backed by wp_cg_students.
 */
class StudentsPage extends EntityListPage {

	protected string $slug      = 'cg-students';
	protected string $edit_slug = 'cg-student-edit';
	protected string $table_key = 'students';

	public function register(): void {
		$this->add_list_page( 'Students', 'Students' );
		// Hidden from menu — accessed via ?page=cg-student-edit
		\add_submenu_page(
			'',
			'Add / Edit Student',
			'',
			'manage_options',
			$this->edit_slug,
			array( $this, 'render_edit' )
		);
	}

	protected function config(): array {
		return array(
			'title'          => 'Students',
			'lead'           => 'Everyone who can receive a student certificate. Filter, bulk edit, generate or email certificates from here.',
			'can_import'     => true,
			'singular'       => 'student',
			'plural'         => 'students',
			'name_col'       => 'student_name',
			'ids_field'      => 'student_ids',
			'nonce_action'   => 'cg_bulk_action',
			'nonce_field'    => 'cg_bulk_action_nonce',
			'search_cols'    => array( 'student_name', 'email', 'serial_number' ),
			'columns'        => array(
				'student_name'     => 'Name',
				'email'            => 'Email',
				'school_name'      => 'School',
				'certificate_type' => 'Cert. Type',
				'issue_date'       => 'Issue Date',
				'serial_number'    => 'Serial #',
				'status'           => 'Status',
			),
			'select_filters' => array(
				'school_filter' => array( 'col' => 'school_name', 'label' => 'All Schools' ),
				'cert_filter'   => array( 'col' => 'certificate_type', 'label' => 'All Certificate Types' ),
				'status_filter' => array( 'col' => 'status', 'label' => 'All Statuses', 'options' => array( 'active' => 'Active', 'graduated' => 'Graduated', 'transferred' => 'Transferred', 'dropped' => 'Dropped' ) ),
				'year_filter'   => array( 'col' => 'year', 'label' => 'All Years' ),
				'import_filter' => array( 'col' => 'import_source', 'label' => 'All Imports' ),
			),
			'statuses'       => array( 'active', 'graduated', 'transferred', 'dropped' ),
			'date_col'       => 'issue_date',
			'bulk_fields'    => array(
				'school_name'      => 'text',
				'certificate_type' => 'type',
				'issue_date'       => 'date',
				'status'           => 'status',
				'event_id'         => 'event',
			),
			'has_email'      => true,
			'can_generate'   => true,
			'can_empty'      => true,
			'gen_fields'     => null,
		);
	}

	// ── Add / Edit Form ──────────────────────────────────────────────────────

	public function render_edit(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( 'Insufficient permissions' );
		}

		global $wpdb;
		$table   = CustomTables::instance()->get_table( $this->table_key );
		$id      = absint( $_GET['id'] ?? 0 );
		$row     = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), \ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$extra   = ! empty( $row['extra_fields'] ) ? ( json_decode( $row['extra_fields'], true ) ?: array() ) : array();
		$message = '';
		$errors  = array();

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ( empty( $_POST['cg_student_nonce'] )
				|| ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['cg_student_nonce'] ) ), 'cg_save_student' ) ) ) {
			$errors[] = 'Security check failed (the page may have been open too long) — please try saving again.';
		}

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ! empty( $_POST['cg_student_nonce'] )
			&& \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $_POST['cg_student_nonce'] ) ), 'cg_save_student' ) ) {

			$name  = \sanitize_text_field( wp_unslash( $_POST['student_name'] ?? '' ) );
			$email = \sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
			if ( ! $name ) {
				$errors[] = 'Student name is required.';
			}
			if ( ! $email ) {
				$errors[] = 'Email is required.';
			}

			$photo_url = \esc_url_raw( wp_unslash( $_POST['photo_url'] ?? '' ) );
			if ( $photo_url && ! preg_match( '/\.(jpe?g|png|webp|gif)$/i', wp_parse_url( $photo_url, PHP_URL_PATH ) ?? '' ) ) {
				$errors[] = 'Photo must be a JPG, PNG, WEBP, or GIF image.';
			}

			if ( empty( $errors ) ) {
				$new_extra = array();
				$ex_keys   = map_deep( (array) wp_unslash( $_POST['extra_key'] ?? array() ), 'sanitize_text_field' );
				$ex_vals   = map_deep( (array) wp_unslash( $_POST['extra_val'] ?? array() ), 'sanitize_text_field' );
				foreach ( $ex_keys as $i => $k ) {
					$k = \sanitize_key( $k );
					$v = \sanitize_text_field( $ex_vals[ $i ] ?? '' );
					if ( $k && $v !== '' ) {
						$new_extra[ $k ] = $v;
					}
				}

				$data = array(
					'student_name'     => $name,
					'email'            => $email,
					'phone'            => \sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
					'school_name'      => \sanitize_text_field( wp_unslash( $_POST['school_name'] ?? '' ) ),
					'photo_url'        => $photo_url ?: null,
					'event_id'         => absint( $_POST['event_id'] ?? 0 ) ?: null,
					'certificate_type' => \sanitize_text_field( wp_unslash( $_POST['certificate_type'] ?? '' ) ),
					'issue_date'       => \sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) ) ?: null,
					'year'             => function_exists( 'certificate_generator_year_from_issue_date' ) ? certificate_generator_year_from_issue_date( \sanitize_text_field( wp_unslash( $_POST['issue_date'] ?? '' ) ) ?: null ) : null,
					'enrollment_date'  => \sanitize_text_field( wp_unslash( $_POST['enrollment_date'] ?? '' ) ) ?: null,
					'graduation_date'  => \sanitize_text_field( wp_unslash( $_POST['graduation_date'] ?? '' ) ) ?: null,
					'status'           => in_array( $_POST['status'] ?? '', array( 'active', 'graduated', 'transferred', 'dropped' ), true ) ? \sanitize_key( $_POST['status'] ) : 'active',
					'extra_fields'     => ! empty( $new_extra ) ? wp_json_encode( $new_extra ) : null,
					'updated_at'       => current_time( 'mysql' ),
				);

				if ( $id && $row ) {
					$result = $wpdb->update( $table, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $result === false ) {
						$errors[] = 'Student was not saved — database error: ' . $wpdb->last_error;
					} else {
						$message = 'Student updated.';
						$row     = array_merge( $row, $data );
						$extra   = $new_extra;
					}
				} else {
					$data['created_at'] = current_time( 'mysql' );
					$result              = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( $result === false ) {
						$errors[] = 'Student was not saved — database error: ' . $wpdb->last_error;
					} else {
						$id      = (int) $wpdb->insert_id;
						$row     = array_merge( $data, array( 'id' => $id ) );
						$extra   = $new_extra;
						$message = 'Student added.';
					}
				}

				if ( function_exists( 'certificate_generator_flush_filter_caches' ) ) {
					certificate_generator_flush_filter_caches();
				}

				// Phase 6: sync to WP Dynamic Tags if a wp_post_id is linked
				$wp_post_id = (int) ( $row['wp_post_id'] ?? 0 );
				if ( $wp_post_id > 0 && function_exists( 'certificate_generator_sync_to_dynamic_tags' ) ) {
					certificate_generator_sync_to_dynamic_tags( $wp_post_id );
				}
			}
		}

		$list_url = \admin_url( 'admin.php?page=' . $this->slug );
		?>
		<div class="wrap">
			<h1><?php echo $id ? 'Edit Student' : 'Add New Student'; ?></h1>
			<a href="<?php echo \esc_url( $list_url ); ?>">← Back to Students</a>

			<?php
			foreach ( $errors as $e ) :
				?>
				<div class="notice notice-error"><p><?php echo \esc_html( $e ); ?></p></div><?php endforeach; ?>
			<?php
			if ( $message ) :
				?>
				<div class="notice notice-success is-dismissible"><p><?php echo \esc_html( $message ); ?></p></div><?php endif; ?>

			<form method="post" style="max-width:700px; margin-top:20px;">
				<?php \wp_nonce_field( 'cg_save_student', 'cg_student_nonce' ); ?>
				<table class="form-table">
					<tr><th><label for="student_name">Name <span style="color:red">*</span></label></th>
						<td><input type="text" id="student_name" name="student_name" class="regular-text" required value="<?php echo \esc_attr( $row['student_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="email">Email <span style="color:red">*</span></label></th>
						<td><input type="email" id="email" name="email" class="regular-text" required value="<?php echo \esc_attr( $row['email'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="phone">Phone</label></th>
						<td><input type="text" id="phone" name="phone" class="regular-text" value="<?php echo \esc_attr( $row['phone'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="school_name">School Name</label></th>
						<td><input type="text" id="school_name" name="school_name" class="regular-text" value="<?php echo \esc_attr( $row['school_name'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="event_id">Event</label></th>
						<td><select id="event_id" name="event_id">
							<option value="">— None —</option>
							<?php
							global $wpdb;
							$events_table = CustomTables::instance()->get_table( 'events' );
							$events       = $wpdb->get_results( "SELECT id, event_code, event_name FROM $events_table ORDER BY event_code", \ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							foreach ( (array) $events as $ev ) :
								?>
								<option value="<?php echo \esc_attr( $ev['id'] ); ?>" <?php \selected( (int) ( $row['event_id'] ?? 0 ), (int) $ev['id'] ); ?>><?php echo \esc_html( $ev['event_code'] . ' — ' . $ev['event_name'] ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
					<tr><th><label for="certificate_type">Certificate Type</label></th>
						<td><input type="text" id="certificate_type" name="certificate_type" class="regular-text" value="<?php echo \esc_attr( $row['certificate_type'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="issue_date">Issue Date</label></th>
						<td><input type="date" id="issue_date" name="issue_date" value="<?php echo \esc_attr( $row['issue_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="enrollment_date">Enrollment Date</label></th>
						<td><input type="date" id="enrollment_date" name="enrollment_date" value="<?php echo \esc_attr( $row['enrollment_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="graduation_date">Graduation Date</label></th>
						<td><input type="date" id="graduation_date" name="graduation_date" value="<?php echo \esc_attr( $row['graduation_date'] ?? '' ); ?>"></td></tr>
					<tr><th><label for="photo_url">Photo</label></th>
							<td>
								<input type="hidden" id="photo_url" name="photo_url" value="<?php echo \esc_attr( $row['photo_url'] ?? '' ); ?>">
								<button type="button" class="button cg-media-btn" data-input="#photo_url" data-preview="#cg_photo_preview" data-title="<?php esc_attr_e( 'Select Student Photo', 'certificate-generator' ); ?>" data-button-text="<?php esc_attr_e( 'Use this photo', 'certificate-generator' ); ?>"><?php esc_html_e( '📁 Choose from Media Library', 'certificate-generator' ); ?></button>
								<div id="cg_photo_preview" style="margin-top:10px;<?php echo ! empty( $row['photo_url'] ) ? '' : 'display:none;'; ?>">
									<?php if ( ! empty( $row['photo_url'] ) ) : ?>
										<img src="<?php echo \esc_url( $row['photo_url'] ); ?>" style="max-width:150px;max-height:150px;border:1px solid #ddd;border-radius:4px;display:block;">
									<?php endif; ?>
								</div>
							</td>
						</tr>
						<tr><th><label for="status">Status</label></th>
						<td><select id="status" name="status">
							<?php foreach ( array( 'active', 'graduated', 'transferred', 'dropped' ) as $s ) : ?>
								<option value="<?php echo \esc_attr( $s ); ?>" <?php \selected( $row['status'] ?? 'active', $s ); ?>><?php echo \esc_html( ucfirst( $s ) ); ?></option>
							<?php endforeach; ?>
						</select></td></tr>
				</table>

				<?php if ( ! empty( $row['serial_number'] ) ) : ?>
					<p><strong>Serial Number:</strong> <code><?php echo \esc_html( $row['serial_number'] ); ?></code></p>
				<?php endif; ?>

				<h3>Extra Fields</h3>
				<p class="description">Custom key-value pairs (e.g. grade, parent_name). Stored as JSON.</p>
				<table class="widefat" id="cg-extra-fields-table" style="max-width:500px; margin-bottom:8px;">
					<thead><tr><th>Key</th><th>Value</th><th></th></tr></thead>
					<tbody id="cg-extra-tbody">
						<?php foreach ( $extra as $k => $v ) : ?>
						<tr>
							<td><input type="text" name="extra_key[]" value="<?php echo \esc_attr( $k ); ?>" class="regular-text"></td>
							<td><input type="text" name="extra_val[]" value="<?php echo \esc_attr( $v ); ?>" class="regular-text"></td>
							<td><button type="button" class="button button-small cg-remove-row">✕</button></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="button" id="cg-add-extra-row">+ Add Field</button>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo $id ? 'Update Student' : 'Add Student'; ?></button>
					<a href="<?php echo \esc_url( $list_url ); ?>" class="button">Cancel</a>
				</p>
			</form>
		</div>
		<script>
		document.getElementById('cg-add-extra-row').addEventListener('click', function() {
			var tbody = document.getElementById('cg-extra-tbody');
			var tr = document.createElement('tr');
			var td1 = document.createElement('td');
			var td2 = document.createElement('td');
			var td3 = document.createElement('td');
			var inp1 = document.createElement('input');
			inp1.type = 'text'; inp1.name = 'extra_key[]'; inp1.className = 'regular-text'; inp1.placeholder = 'key';
			var inp2 = document.createElement('input');
			inp2.type = 'text'; inp2.name = 'extra_val[]'; inp2.className = 'regular-text'; inp2.placeholder = 'value';
			var btn = document.createElement('button');
			btn.type = 'button'; btn.className = 'button button-small'; btn.textContent = '✕';
			btn.addEventListener('click', function(){ tr.parentNode.removeChild(tr); });
			td1.appendChild(inp1); td2.appendChild(inp2); td3.appendChild(btn);
			tr.appendChild(td1); tr.appendChild(td2); tr.appendChild(td3);
			tbody.appendChild(tr);
		});
		document.querySelectorAll('.cg-remove-row').forEach(function(btn){
			btn.addEventListener('click', function(){ this.closest('tr').remove(); });
		});
		</script>
		<?php
	}

}
