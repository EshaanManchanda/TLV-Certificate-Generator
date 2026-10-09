<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Database\CustomTables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate Templates admin page — list + CRUD form backed by wp_cg_certificate_templates.
 */
class TemplatesPage extends EntityListPage {

	protected string $slug      = 'cg-templates';
	protected string $edit_slug = 'cg-template-edit';
	protected string $table_key = 'certificate_templates';

	protected function config(): array {
		return array(
			'title'          => __( 'Certificate Templates', 'certificate-generator' ),
			'lead'           => __( 'Designs used to render certificates. A recipient uses the published template matching their certificate type and event date.', 'certificate-generator' ),
			'singular'       => 'template',
			'plural'         => 'templates',
			'name_col'       => 'template_name',
			'ids_field'      => 'template_ids',
			'nonce_action'   => 'cg_delete_template',
			'nonce_field'    => 'cg_delete_template_nonce',
			'search_cols'    => array( 'template_name', 'certificate_type' ),
			'columns'        => array(
				'template_name'    => __( 'Name', 'certificate-generator' ),
				'certificate_type' => __( 'Type', 'certificate-generator' ),
				'entity_type'      => __( 'For', 'certificate-generator' ),
				'event_date'       => __( 'Event Date', 'certificate-generator' ),
				'orientation'      => __( 'Orientation', 'certificate-generator' ),
				'status'           => __( 'Status', 'certificate-generator' ),
			),
			'select_filters' => array(
				'type_filter'   => array( 'col' => 'certificate_type', 'label' => __( 'All Types', 'certificate-generator' ) ),
				'status_filter' => array(
					'col'     => 'status',
					'label'   => __( 'All Statuses', 'certificate-generator' ),
					'options' => array(
						'published' => __( 'Published', 'certificate-generator' ),
						'scheduled' => __( 'Scheduled', 'certificate-generator' ),
						'draft'     => __( 'Draft', 'certificate-generator' ),
						'archived'  => __( 'Archived', 'certificate-generator' ),
					),
				),
				'entity_filter' => array(
					'col'     => 'entity_type',
					'label'   => __( 'All Recipients', 'certificate-generator' ),
					'options' => array(
						'students' => __( 'Students', 'certificate-generator' ),
						'teachers' => __( 'Teachers', 'certificate-generator' ),
						'schools'  => __( 'Schools', 'certificate-generator' ),
					),
				),
			),
			'statuses'       => array( 'draft', 'scheduled', 'published', 'archived' ),
			'date_col'       => 'event_date',
			'bulk_fields'    => array(
				'certificate_type' => 'type_free',
				'event_date'       => 'date',
				'status'           => 'status',
				'entity_type'      => 'entity_type',
				'event_id'         => 'event',
			),
			'is_templates'   => true,
		);
	}

	// Default QR/Serial positions per orientation (mm). Landscape values match
	// the certificate_templates column defaults; portrait values are those
	// proportionally scaled to the 210×297mm portrait page.
	private const POSITION_DEFAULTS = array(
		'landscape' => array(
			'qr'     => array( 250.0, 180.0 ),
			'serial' => array( 105.0, 200.0 ),
		),
		'portrait'  => array(
			'qr'     => array( 176.8, 254.6 ),
			'serial' => array( 74.2, 282.9 ),
		),
	);

	/**
	 * Reset x/y to that field's orientation default when out of the page's bounds
	 * (297×210mm landscape, 210×297mm portrait) instead of silently saving an
	 * off-page value.
	 *
	 * @return array{0: float, 1: float}
	 */
	private static function bounded_position( string $orientation, float $x, float $y, string $field ): array {
		$orientation = $orientation === 'portrait' ? 'portrait' : 'landscape';
		$max_x       = $orientation === 'portrait' ? 210.0 : 297.0;
		$max_y       = $orientation === 'portrait' ? 297.0 : 210.0;

		if ( $x < 0 || $x > $max_x || $y < 0 || $y > $max_y ) {
			return self::POSITION_DEFAULTS[ $orientation ][ $field ];
		}
		return array( $x, $y );
	}

	public function register_ajax(): void {
		parent::register_ajax();
		add_action( 'wp_ajax_certificate_generator_use_bundled_template', array( $this, 'ajax_use_bundled_template' ) );
	}

	public function register(): void {
		$this->add_list_page( 'Certificate Templates', 'Templates' );
		add_submenu_page(
			'',
			'Add / Edit Template',
			'Add / Edit Template',
			'manage_options',
			$this->edit_slug,
			array( $this, 'render_edit' )
		);

		// Fix strip_tags(null): hidden pages (parent='') never get $GLOBALS['title'] set by WP.
		// admin_init fires before admin-header.php is included, so this runs before strip_tags($title).
		// Ensure $GLOBALS['title'] is always a string to avoid PHP deprecation warnings when admin-header calls strip_tags().
		add_action(
			'admin_init',
			function (): void {
				if ( empty( $GLOBALS['title'] ) ) {
					$GLOBALS['title'] = '';
				}
			}
		);

		// Specific title for the edit page to improve UX
		add_action(
			'admin_init',
			function (): void {
				if ( ( isset( $_GET['page'] ) && $_GET['page'] === $this->edit_slug )
				&& $GLOBALS['title'] === ''
				) {
					$GLOBALS['title'] = 'Add / Edit Template';
				}
			}
		);

		// Duplicate action — must run on admin_init (before any output) so wp_safe_redirect works.
		add_action(
			'admin_init',
			function (): void {
				if ( ! isset( $_GET['page'], $_GET['action'], $_GET['id'] ) ) {
					return;
				}
				if ( $_GET['page'] !== $this->slug || $_GET['action'] !== 'duplicate' ) {
					return;
				}
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				$src_id = absint( $_GET['id'] );
				if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'cg_duplicate_template_' . $src_id ) ) {
					return;
				}

				global $wpdb;
				$table   = CustomTables::instance()->get_table( $this->table_key );
				$src_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE id = %d", $src_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				if ( $src_row ) {
					unset( $src_row['id'] );
					$src_row['template_name'] = __( 'Copy of', 'certificate-generator' ) . ' ' . $src_row['template_name'];
					$src_row['status']        = 'draft';
					$src_row['created_at']    = current_time( 'mysql' );
					$src_row['updated_at']    = current_time( 'mysql' );
					$wpdb->insert( $table, $src_row );
					$new_id = (int) $wpdb->insert_id;
					if ( $new_id ) {
						wp_safe_redirect( admin_url( 'admin.php?page=' . $this->edit_slug . '&id=' . $new_id . '&duplicated=1' ) );
						exit;
					}
				}
			}
		);

		// Enqueue jQuery UI draggable + shared media uploader on the template edit page
		add_action(
			'admin_enqueue_scripts',
			function ( string $hook ): void {
				if ( $hook !== 'admin_page_' . $this->edit_slug ) {
					return;
				}
				wp_enqueue_script( 'jquery-ui-draggable' );
				wp_enqueue_media();
				wp_enqueue_script(
					'cg-media-uploader',
					CERTIFICATE_GENERATOR_URL . 'assets/js/cg-media-uploader.js',
					array( 'jquery' ),
					'1.0.0',
					true
				);
			}
		);
	}

	/**
	 * Copy a bundled gallery design into uploads (like a normal media-library
	 * selection) so it survives future plugin updates that change/remove the
	 * bundled file, and return its URL for #template_url.
	 */
	public function ajax_use_bundled_template(): void {
		check_ajax_referer( 'cg_bundled_template', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}

		$id  = sanitize_key( $_POST['template_id'] ?? '' );
		$tpl = \CertificateGenerator\Core\BundledTemplates::find( $id );
		if ( ! $tpl ) {
			wp_send_json_error( array( 'message' => 'Unknown gallery template' ), 400 );
		}

		$source_path = CERTIFICATE_GENERATOR_PATH . 'assets/templates/' . $tpl['file'];
		if ( ! file_exists( $source_path ) ) {
			wp_send_json_error( array( 'message' => 'Gallery asset missing' ), 500 );
		}

		$bits = wp_upload_bits( $tpl['file'], null, file_get_contents( $source_path ) );
		if ( ! empty( $bits['error'] ) ) {
			wp_send_json_error( array( 'message' => $bits['error'] ), 500 );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => sanitize_file_name( $tpl['label'] ),
				'post_status'    => 'inherit',
			),
			$bits['file']
		);
		if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $bits['file'] ) );
		}

		wp_send_json_success(
			array(
				'url'         => $bits['url'],
				'orientation' => $tpl['orientation'],
			)
		);
	}

	// ── Add / Edit Form ──────────────────────────────────────────────────────

	public function render_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		global $wpdb;
		$table   = CustomTables::instance()->get_table( $this->table_key );
		$id      = absint( $_GET['id'] ?? 0 );
		$row     = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$message = '';
		$errors  = array();

		$font_manager   = class_exists( 'CertificateGenerator_FontManager' ) ? \CertificateGenerator_FontManager::getInstance() : null;
		$font_options   = $font_manager ? $font_manager->get_font_options( true ) : array( 'helvetica' => 'Helvetica' ); // built-in + uploaded

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ( empty( $_POST['cg_template_nonce'] )
				|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_template_nonce'] ) ), 'cg_save_template' ) ) ) {
			$errors[] = __( 'Security check failed (the page may have been open too long) — please try saving again.', 'certificate-generator' );
		}

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) )
			&& ! empty( $_POST['cg_template_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_template_nonce'] ) ), 'cg_save_template' ) ) {

			$name = sanitize_text_field( wp_unslash( $_POST['template_name'] ?? '' ) );
			$type = sanitize_text_field( wp_unslash( $_POST['certificate_type'] ?? '' ) );
			if ( ! $name ) {
				$errors[] = __( 'Template name is required.', 'certificate-generator' );
			}
			if ( ! $type ) {
				$errors[] = __( 'Certificate type is required.', 'certificate-generator' );
			}
			$raw_status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
			if ( $raw_status === 'scheduled' && empty( trim( sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) ) ) ) ) {
				$errors[] = __( 'An Event Date is required when status is set to Scheduled.', 'certificate-generator' );
			}

			if ( empty( $errors ) ) {
				$event_raw  = sanitize_text_field( wp_unslash( $_POST['event_date'] ?? '' ) );
				$event_date = '';
				if ( $event_raw ) {
					$dt         = \DateTime::createFromFormat( 'd-m-Y', $event_raw );
					$event_date = $dt ? $dt->format( 'Y-m-d' ) : null;
				}

				$data = array(
					'template_name'            => $name,
					'certificate_type'         => $type,
					'entity_type'              => in_array( $_POST['entity_type'] ?? '', array( 'students', 'teachers', 'schools' ), true ) ? sanitize_key( $_POST['entity_type'] ) : 'students',
					'event_id'                 => absint( $_POST['event_id'] ?? 0 ) ?: null,
					'event_date'               => $event_date,
					'template_url'             => esc_url_raw( wp_unslash( $_POST['template_url'] ?? '' ) ),
					'badge_template_url'       => esc_url_raw( wp_unslash( $_POST['badge_template_url'] ?? '' ) ),
					'orientation'              => in_array( $_POST['orientation'] ?? '', array( 'portrait', 'landscape' ), true ) ? sanitize_key( $_POST['orientation'] ) : 'landscape',
					'page_size'                => in_array( wp_unslash( $_POST['page_size'] ?? '' ), array( 'A4', 'Letter', 'Legal', 'Custom' ), true ) ? sanitize_text_field( wp_unslash( $_POST['page_size'] ) ) : 'A4',
					// sanitize_key() would mangle custom uploaded font names (which allow
					// spaces/mixed case via sanitize_text_field() in FontsPage.php), silently
					// breaking the match against wp_cg_custom_fonts.font_name. The posted
					// value already comes from a controlled datalist selection, not free text.
					'font_style'               => sanitize_text_field( wp_unslash( $_POST['font_style'] ?? 'helvetica' ) ),
					'font_size'                => absint( $_POST['font_size'] ?? 12 ),
					'font_color'               => sanitize_hex_color( wp_unslash( $_POST['font_color'] ?? '#000000' ) ) ?: '#000000',
					'qr_enabled'               => ! empty( $_POST['qr_enabled'] ) ? 1 : 0,
					'qr_size'                  => absint( $_POST['qr_size'] ?? 15 ),
					'qr_position_x'            => floatval( $_POST['qr_position_x'] ?? 250 ),
					'qr_position_y'            => floatval( $_POST['qr_position_y'] ?? 180 ),
					'qr_error_correction'      => in_array( $_POST['qr_error_correction'] ?? '', array( 'L', 'M', 'Q', 'H' ), true ) ? sanitize_key( $_POST['qr_error_correction'] ) : 'L',
					'serial_number_display'    => ! empty( $_POST['serial_number_display'] ) ? 1 : 0,
					'serial_number_position_x' => floatval( $_POST['serial_number_position_x'] ?? 105 ),
					'serial_number_position_y' => floatval( $_POST['serial_number_position_y'] ?? 200 ),
					'serial_number_font_size'  => absint( $_POST['serial_number_font_size'] ?? 10 ),
					'expiration_period_unit'   => in_array( $_POST['expiration_period_unit'] ?? '', array( 'never', 'days', 'months', 'years' ), true ) ? sanitize_key( $_POST['expiration_period_unit'] ) : 'never',
					'expiration_period_value'  => absint( $_POST['expiration_period_value'] ?? 0 ),
					'status'                   => in_array( $_POST['status'] ?? '', array( 'draft', 'scheduled', 'published', 'archived' ), true ) ? sanitize_key( $_POST['status'] ) : 'draft',
					'updated_at'               => current_time( 'mysql' ),
				);

				// Out-of-range QR/Serial positions for the chosen orientation get reset
				// to that orientation's own defaults — a value valid in landscape can be
				// off-page in portrait (and vice versa), and nothing else validated this.
				list( $data['qr_position_x'], $data['qr_position_y'] ) = self::bounded_position(
					$data['orientation'], $data['qr_position_x'], $data['qr_position_y'], 'qr'
				);
				list( $data['serial_number_position_x'], $data['serial_number_position_y'] ) = self::bounded_position(
					$data['orientation'], $data['serial_number_position_x'], $data['serial_number_position_y'], 'serial'
				);

				// ── Field positioning → extra_fields JSON ────────────────
				$max_fields   = class_exists( 'CertificateGenerator_Field_Schema' ) ? \CertificateGenerator_Field_Schema::MAX_FIELDS : 15;
				$field_count  = max( 2, min( $max_fields, absint( $_POST['template_field_count'] ?? 3 ) ) );
				$extra_fields = array();
				// Preserve non-field keys already stored in extra_fields
				if ( ! empty( $row['extra_fields'] ) ) {
					$existing_extra = json_decode( $row['extra_fields'], true );
					if ( is_array( $existing_extra ) ) {
						foreach ( $existing_extra as $ek => $ev ) {
							if ( ! preg_match( '/^field_\d+_/', $ek ) && $ek !== 'template_field_count' ) {
								$extra_fields[ $ek ] = $ev;
							}
						}
					}
				}
				$extra_fields['template_field_count'] = $field_count;
				// Allowed field-name keys for this template's entity type — recomputed
				// server-side (never trust the client-submitted <select> options).
				$allowed_field_keys = array_merge(
					\CertificateGenerator\Services\FieldManager::get_core_fields( $data['entity_type'] ),
					\CertificateGenerator\Services\FieldManager::discover_extra_field_keys( $data['entity_type'] )
				);
				// Only persist slots actually in use — writing placeholder
				// width/alignment defaults for unused slots up to MAX_FIELDS
				// corrupts CSV export/import's field-count auto-detection.
				for ( $i = 1; $i <= $field_count; $i++ ) {
					$posted_name                          = sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_name" ] ?? '' ) );
					$extra_fields[ "field_{$i}_name" ]    = in_array( $posted_name, $allowed_field_keys, true ) ? $posted_name : '';
					$x_input                                 = sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_position_x" ] ?? '' ) );
					$y_input                                 = sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_position_y" ] ?? '' ) );
					$extra_fields[ "field_{$i}_position_x" ] = $x_input !== '' ? floatval( $x_input ) : 105;
					$extra_fields[ "field_{$i}_position_y" ] = $y_input !== '' ? floatval( $y_input ) : 60 + ( $i * 25 );
					$extra_fields[ "field_{$i}_visible" ]    = ! empty( $_POST[ "field_{$i}_visible" ] ) ? '1' : '0';
					$extra_fields[ "field_{$i}_width" ]      = floatval( $_POST[ "field_{$i}_width" ] ?? 100 );
					// Height only applies to image/photo slots; empty means "auto,
					// keep the source image's own aspect ratio" (matches pre-height behaviour).
					$height_input                            = sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_height" ] ?? '' ) );
					$extra_fields[ "field_{$i}_height" ]     = $height_input !== '' && is_numeric( $height_input ) ? floatval( $height_input ) : '';
					$alignment                               = strtoupper( sanitize_key( $_POST[ "field_{$i}_alignment" ] ?? 'c' ) );
					$extra_fields[ "field_{$i}_alignment" ]  = in_array( $alignment, array( 'L', 'C', 'R' ), true ) ? $alignment : 'C';

					// Field type: 'text' (default), 'image' — a static per-template image
					// (e.g. an e-signature) placed at this slot instead of student data —
					// or 'photo', identical to 'image' except the URL comes from the
					// student's own photo_url instead of a fixed template-time image.
					$field_type                              = sanitize_key( $_POST[ "field_{$i}_type" ] ?? 'text' );
					$extra_fields[ "field_{$i}_type" ]       = in_array( $field_type, array( 'text', 'image', 'photo' ), true ) ? $field_type : 'text';
					$extra_fields[ "field_{$i}_image_url" ]  = esc_url_raw( wp_unslash( $_POST[ "field_{$i}_image_url" ] ?? '' ) );
				}
				$data['extra_fields'] = wp_json_encode( $extra_fields );

				if ( $id && $row ) {
					$result = $wpdb->update( $table, $data, array( 'id' => $id ) );
					if ( $result === false ) {
						/* translators: %s: database error message */
						$errors[] = sprintf( __( 'Template was not saved — database error: %s', 'certificate-generator' ), $wpdb->last_error );
					} else {
						$message = __( 'Template updated.', 'certificate-generator' );
						$row     = array_merge( $row, $data );
					}
				} else {
					$data['created_at'] = current_time( 'mysql' );
					$result              = $wpdb->insert( $table, $data );
					if ( $result === false ) {
						/* translators: %s: database error message */
						$errors[] = sprintf( __( 'Template was not saved — database error: %s', 'certificate-generator' ), $wpdb->last_error );
					} else {
						$id      = (int) $wpdb->insert_id;
						$row     = array_merge( $data, array( 'id' => $id ) );
						$message = __( 'Template added.', 'certificate-generator' );
					}
				}
			}
		}

		$list_url = admin_url( 'admin.php?page=' . $this->slug );
		?>
		<div class="wrap">
			<?php
			certificate_generator_ui_page_header(
				$id ? __( 'Edit Template', 'certificate-generator' ) : __( 'Add New Template', 'certificate-generator' ),
				__( 'Pick a background, then place each field on the canvas. Once published, recipients whose certificate type (and event date, if set) match get this design.', 'certificate-generator' ),
				'<a href="' . esc_url( $list_url ) . '" class="button">' . esc_html__( '← Back to Templates', 'certificate-generator' ) . '</a>'
			);
			if ( ! empty( $_GET['duplicated'] ) ) {
				certificate_generator_ui_notice( 'success', esc_html__( 'Template duplicated. Update the name and settings, then save.', 'certificate-generator' ) );
			}
			foreach ( $errors as $e ) {
				certificate_generator_ui_notice( 'error', esc_html( $e ), false );
			}
			if ( $message ) {
				certificate_generator_ui_notice( 'success', esc_html( $message ) );
			}
			?>

			<form method="post" id="cg-template-form" data-cg-busy>
				<?php wp_nonce_field( 'cg_save_template', 'cg_template_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="template_name"><?php esc_html_e( 'Template Name', 'certificate-generator' ); ?> <span class="required">*</span></label></th>
						<td><input type="text" id="template_name" name="template_name" class="regular-text" required value="<?php echo esc_attr( $row['template_name'] ?? '' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="certificate_type"><?php esc_html_e( 'Certificate Type', 'certificate-generator' ); ?> <span class="required">*</span></label></th>
						<td><input type="text" id="certificate_type" name="certificate_type" class="regular-text" required value="<?php echo esc_attr( $row['certificate_type'] ?? '' ); ?>">
						<p class="description"><?php esc_html_e( 'Used to match this template when generating certificates.', 'certificate-generator' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="entity_type"><?php esc_html_e( 'Entity Type', 'certificate-generator' ); ?></label></th>
						<td>
							<select id="entity_type" name="entity_type">
								<option value="students" <?php selected( $row['entity_type'] ?? 'students', 'students' ); ?>><?php esc_html_e( 'Student', 'certificate-generator' ); ?></option>
								<option value="teachers" <?php selected( $row['entity_type'] ?? 'students', 'teachers' ); ?>><?php esc_html_e( 'Teacher', 'certificate-generator' ); ?></option>
								<option value="schools" <?php selected( $row['entity_type'] ?? 'students', 'schools' ); ?>><?php esc_html_e( 'School', 'certificate-generator' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Which field set the Field Slots dropdowns below offer.', 'certificate-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="event_date"><?php esc_html_e( 'Event Date', 'certificate-generator' ); ?></label></th>
						<td><input type="text" id="event_date" name="event_date" value="<?php echo esc_attr( ! empty( $row['event_date'] ) ? date_create( $row['event_date'] )->format( 'd-m-Y' ) : '' ); ?>" placeholder="<?php esc_attr_e( 'DD-MM-YYYY', 'certificate-generator' ); ?>">
						<p class="description"><?php esc_html_e( 'For distinguishing multiple templates of the same type. Format: d-m-Y', 'certificate-generator' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="event_id"><?php esc_html_e( 'Event', 'certificate-generator' ); ?></label></th>
						<td><select id="event_id" name="event_id">
							<option value=""><?php esc_html_e( '— None —', 'certificate-generator' ); ?></option>
							<?php
							$events_table = CustomTables::instance()->get_table( 'events' );
							$events       = $wpdb->get_results( "SELECT id, event_code, event_name FROM $events_table ORDER BY event_code", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							foreach ( (array) $events as $ev ) :
								?>
								<option value="<?php echo esc_attr( $ev['id'] ); ?>" <?php selected( (int) ( $row['event_id'] ?? 0 ), (int) $ev['id'] ); ?>><?php echo esc_html( $ev['event_code'] . ' — ' . $ev['event_name'] ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Optional — links this template to a specific event occurrence (e.g. one run of a recurring competition).', 'certificate-generator' ); ?></p></td>
					</tr>
					<tr>
						<th><label for="template_url"><?php esc_html_e( 'Template Image URL', 'certificate-generator' ); ?></label></th>
						<td>
							<div class="cg-tpl-row">
								<input type="text" id="template_url" name="template_url" class="regular-text cg-tpl-grow" value="<?php echo esc_url( $row['template_url'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Paste URL or choose from media library →', 'certificate-generator' ); ?>">
								<button type="button" id="cg_upload_template_btn" class="button button-secondary"><?php esc_html_e( '📁 Choose from Media Library', 'certificate-generator' ); ?></button>
							</div>
							<div class="cg-tpl-row cg-tpl-row--spaced">
								<select id="cg_gallery_select">
									<option value=""><?php esc_html_e( '— Choose from Gallery —', 'certificate-generator' ); ?></option>
									<?php foreach ( \CertificateGenerator\Core\BundledTemplates::all() as $gtpl ) : ?>
										<option value="<?php echo esc_attr( $gtpl['id'] ); ?>"><?php echo esc_html( $gtpl['label'] . ' (' . $gtpl['orientation'] . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" id="cg_use_gallery_btn" class="button"><?php esc_html_e( 'Use Design', 'certificate-generator' ); ?></button>
								<span id="cg_gallery_status" class="cg-tpl-status" aria-live="polite"></span>
							</div>
							<div id="cg_template_preview" class="cg-tpl-preview"<?php echo ! empty( $row['template_url'] ) ? '' : ' style="display:none;"'; ?>>
								<?php if ( ! empty( $row['template_url'] ) ) : ?>
									<img src="<?php echo esc_url( $row['template_url'] ); ?>" class="cg-tpl-thumb" alt="">
								<?php endif; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th><label for="badge_template_url"><?php esc_html_e( 'Badge Image (optional)', 'certificate-generator' ); ?></label></th>
						<td>
							<div class="cg-tpl-row">
								<input type="hidden" id="badge_template_url" name="badge_template_url" value="<?php echo esc_attr( $row['badge_template_url'] ?? '' ); ?>">
								<button type="button" class="button cg-media-btn" data-input="#badge_template_url" data-preview="#cg_badge_preview" data-title="<?php esc_attr_e( 'Select Badge Image', 'certificate-generator' ); ?>" data-button-text="<?php esc_attr_e( 'Use this image', 'certificate-generator' ); ?>"><?php esc_html_e( '📁 Choose from Media Library', 'certificate-generator' ); ?></button>
							</div>
							<div id="cg_badge_preview" class="cg-tpl-preview"<?php echo ! empty( $row['badge_template_url'] ) ? '' : ' style="display:none;"'; ?>>
								<?php if ( ! empty( $row['badge_template_url'] ) ) : ?>
									<img src="<?php echo esc_url( $row['badge_template_url'] ); ?>" class="cg-tpl-thumb cg-tpl-thumb--badge" alt="">
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'When set, a companion badge PNG is generated alongside each certificate PDF for this template.', 'certificate-generator' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="orientation"><?php esc_html_e( 'Orientation', 'certificate-generator' ); ?></label></th>
						<td><select id="orientation" name="orientation">
							<option value="landscape" <?php selected( $row['orientation'] ?? 'landscape', 'landscape' ); ?>><?php esc_html_e( 'Landscape (297x210 mm)', 'certificate-generator' ); ?></option>
							<option value="portrait" <?php selected( $row['orientation'] ?? 'landscape', 'portrait' ); ?>><?php esc_html_e( 'Portrait (210x297 mm)', 'certificate-generator' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th><label for="page_size"><?php esc_html_e( 'Page Size', 'certificate-generator' ); ?></label></th>
						<td><select id="page_size" name="page_size">
							<?php foreach ( array( 'A4', 'Letter', 'Legal', 'Custom' ) as $s ) : ?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $row['page_size'] ?? 'A4', $s ); ?>><?php echo esc_html( $s ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th><label for="font_style_search"><?php esc_html_e( 'Font Style', 'certificate-generator' ); ?></label></th>
						<td>
							<input type="text" id="font_style_search" class="regular-text" autocomplete="off" list="font_style_list"
								placeholder="<?php esc_attr_e( 'Type to search fonts…', 'certificate-generator' ); ?>"
								value="<?php echo esc_attr( $font_options[ $row['font_style'] ?? 'helvetica' ] ?? 'Helvetica' ); ?>">
							<datalist id="font_style_list">
								<?php foreach ( $font_options as $label ) : ?>
									<option value="<?php echo esc_attr( $label ); ?>">
								<?php endforeach; ?>
							</datalist>
							<input type="hidden" id="font_style" name="font_style" value="<?php echo esc_attr( $row['font_style'] ?? 'helvetica' ); ?>">
							<p class="cg-hint"><?php echo esc_html( sprintf( /* translators: %d: number of fonts */ __( '%d fonts available.', 'certificate-generator' ), count( $font_options ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="font_size"><?php esc_html_e( 'Font Size', 'certificate-generator' ); ?></label></th>
						<td><input type="number" id="font_size" name="font_size" value="<?php echo esc_attr( $row['font_size'] ?? 12 ); ?>" min="6" max="48"></td>
					</tr>
					<tr>
						<th><label for="font_color"><?php esc_html_e( 'Font Color', 'certificate-generator' ); ?></label></th>
						<td><input type="color" id="font_color" name="font_color" value="<?php echo esc_attr( $row['font_color'] ?? '#000000' ); ?>"></td>
					</tr>
					<tr>
						<th><label for="status"><?php esc_html_e( 'Status', 'certificate-generator' ); ?></label></th>
						<td><select id="status" name="status">
							<?php
							foreach ( array(
								'draft'     => __( 'Draft', 'certificate-generator' ),
								'scheduled' => __( 'Scheduled', 'certificate-generator' ),
								'published' => __( 'Published', 'certificate-generator' ),
								'archived'  => __( 'Archived', 'certificate-generator' ),
							) as $s => $label ) :
								?>
								<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $row['status'] ?? 'draft', $s ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Scheduled: auto-publishes on the Event Date. Requires Event Date to be set.', 'certificate-generator' ); ?></p>
						</td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'QR Code Settings', 'certificate-generator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label><input type="checkbox" id="qr_enabled" name="qr_enabled" value="1" <?php checked( ! empty( $row['qr_enabled'] ) ); ?>> <?php esc_html_e( 'Enable QR Code', 'certificate-generator' ); ?></label></th>
						<td></td>
					</tr>
					<tr>
						<th><label for="qr_size"><?php esc_html_e( 'QR Size', 'certificate-generator' ); ?></label></th>
						<td><input type="number" id="qr_size" name="qr_size" value="<?php echo esc_attr( $row['qr_size'] ?? 15 ); ?>" min="5" max="50"></td>
					</tr>
					<tr>
						<th><label for="qr_position_x"><?php esc_html_e( 'QR Position X (mm)', 'certificate-generator' ); ?></label></th>
						<td><input type="number" step="0.01" id="qr_position_x" name="qr_position_x" value="<?php echo esc_attr( $row['qr_position_x'] ?? 250 ); ?>"></td>
					</tr>
					<tr>
						<th><label for="qr_position_y"><?php esc_html_e( 'QR Position Y (mm)', 'certificate-generator' ); ?></label></th>
						<td><input type="number" step="0.01" id="qr_position_y" name="qr_position_y" value="<?php echo esc_attr( $row['qr_position_y'] ?? 180 ); ?>"></td>
					</tr>
					<tr>
						<th><label for="qr_error_correction"><?php esc_html_e( 'QR Error Correction', 'certificate-generator' ); ?></label></th>
						<td><select id="qr_error_correction" name="qr_error_correction">
							<?php
							foreach ( array(
								'L' => __( 'Low', 'certificate-generator' ),
								'M' => __( 'Medium', 'certificate-generator' ),
								'Q' => __( 'Quartile', 'certificate-generator' ),
								'H' => __( 'High', 'certificate-generator' ),
							) as $k => $v ) :
								?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $row['qr_error_correction'] ?? 'L', $k ); ?>><?php echo esc_html( $v ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Serial Number Display', 'certificate-generator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label><input type="checkbox" id="serial_number_display" name="serial_number_display" value="1" <?php checked( ! empty( $row['serial_number_display'] ) ); ?>> <?php esc_html_e( 'Show Serial Number on Certificate', 'certificate-generator' ); ?></label></th>
						<td></td>
					</tr>
					<tr>
						<th><label for="serial_number_position_x"><?php esc_html_e( 'Serial X Position (mm)', 'certificate-generator' ); ?></label></th>
						<td><input type="number" step="0.01" id="serial_number_position_x" name="serial_number_position_x" value="<?php echo esc_attr( $row['serial_number_position_x'] ?? 105 ); ?>"></td>
					</tr>
					<tr>
						<th><label for="serial_number_position_y"><?php esc_html_e( 'Serial Y Position (mm)', 'certificate-generator' ); ?></label></th>
						<td><input type="number" step="0.01" id="serial_number_position_y" name="serial_number_position_y" value="<?php echo esc_attr( $row['serial_number_position_y'] ?? 200 ); ?>"></td>
					</tr>
					<tr>
						<th><label for="serial_number_font_size"><?php esc_html_e( 'Serial Font Size', 'certificate-generator' ); ?></label></th>
						<td><input type="number" id="serial_number_font_size" name="serial_number_font_size" value="<?php echo esc_attr( $row['serial_number_font_size'] ?? 10 ); ?>" min="6" max="48"></td>
					</tr>
				</table>

				<h3><?php esc_html_e( 'Expiration', 'certificate-generator' ); ?></h3>
				<table class="form-table">
					<tr>
						<th><label for="expiration_period_unit"><?php esc_html_e( 'Expiration Period', 'certificate-generator' ); ?></label></th>
						<td><select id="expiration_period_unit" name="expiration_period_unit">
							<?php
							foreach ( array(
								'never'  => __( 'Never', 'certificate-generator' ),
								'days'   => __( 'Days', 'certificate-generator' ),
								'months' => __( 'Months', 'certificate-generator' ),
								'years'  => __( 'Years', 'certificate-generator' ),
							) as $k => $v ) :
								?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $row['expiration_period_unit'] ?? 'never', $k ); ?>><?php echo esc_html( $v ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" id="expiration_period_value" name="expiration_period_value" value="<?php echo esc_attr( $row['expiration_period_value'] ?? 0 ); ?>" min="0" class="small-text cg-tpl-inline-num"></td>
					</tr>
				</table>

				<?php
				// ── Field Positioning ─────────────────────────────────────
				$max_fields_ui     = class_exists( '\CertificateGenerator_Field_Schema' ) ? \CertificateGenerator_Field_Schema::MAX_FIELDS : 15;
				$extra_fields_data = array();
				if ( ! empty( $row['extra_fields'] ) ) {
					$decoded = json_decode( $row['extra_fields'], true );
					if ( is_array( $decoded ) ) {
						$extra_fields_data = $decoded;
					}
				}
				$field_count_val = max( 2, min( $max_fields_ui, (int) ( $extra_fields_data['template_field_count'] ?? 3 ) ) );

				$cert_type_val  = $row['certificate_type'] ?? '';
				$all_renderable = ( class_exists( '\CertificateGenerator_Field_Schema' ) && $cert_type_val )
					? \CertificateGenerator_Field_Schema::get_all_renderable_fields( $cert_type_val )
					: array();
				$canvas_labels  = array();
				for ( $j = 1; $j <= $max_fields_ui; $j++ ) {
					$canvas_labels[] = isset( $all_renderable[ $j - 1 ] )
						? \CertificateGenerator_Field_Schema::get_display_label( $all_renderable[ $j - 1 ] )
						: "Field {$j}";
				}
				$template_url_val = $row['template_url'] ?? '';
				$orientation_val  = $row['orientation'] ?? 'landscape';
				$entity_type_val  = $row['entity_type'] ?? 'students';

				// Field-key options for every entity type, so the JS can repopulate
				// each slot's dropdown client-side when Entity Type changes.
				$field_options_by_entity = array();
				foreach ( array( 'students', 'teachers', 'schools' ) as $etype ) {
					$etype_keys = array_values( array_unique( array_merge(
						\CertificateGenerator\Services\FieldManager::get_core_fields( $etype ),
						\CertificateGenerator\Services\FieldManager::discover_extra_field_keys( $etype )
					) ) );
					$field_options_by_entity[ $etype ] = array_map(
						function ( $k ) {
							return array(
								'key'   => $k,
								'label' => \CertificateGenerator_Field_Schema::get_display_label( $k ),
							);
						},
						$etype_keys
					);
				}

				// Does this template already use explicit per-slot field mapping? If so,
				// unmapped slots stay genuinely empty (force a deliberate choice) instead
				// of silently guessing from the old positional list.
				$has_explicit_mapping = false;
				foreach ( $extra_fields_data as $ek => $ev ) {
					if ( preg_match( '/^field_\d+_name$/', $ek ) && $ev !== '' ) {
						$has_explicit_mapping = true;
						break;
					}
				}
				?>

				<h3 class="cg-tpl-h3"><?php esc_html_e( 'Field Positioning', 'certificate-generator' ); ?></h3>
				<p class="description cg-tpl-lead"><?php echo wp_kses( __( 'Drag handles on the canvas <strong>or</strong> click a row then click the canvas to place it. Coordinates update in real time.', 'certificate-generator' ), array( 'strong' => array() ) ); ?></p>

				<style>
				/* ── Form rows (moved from inline styles) ── */
				#cg-template-form{margin-top:20px}
				.cg-tpl-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
				.cg-tpl-row--spaced{margin-top:8px}
				.cg-tpl-grow{flex:1}
				.cg-tpl-status,#cg_preview_status{color:#666;font-style:italic}
				.cg-tpl-preview{margin-top:10px}
				.cg-tpl-thumb{max-width:300px;max-height:180px;border:1px solid #ddd;border-radius:4px;display:block}
				.cg-tpl-thumb--badge{max-width:150px;max-height:150px}
				.cg-tpl-thumb--field{max-width:150px;max-height:90px}
				.cg-tpl-inline-num{margin-left:10px}
				.cg-tpl-h3{margin-bottom:4px}
				.cg-tpl-lead{margin-bottom:14px}
				.cg-tpl-countbar{display:flex;align-items:center;gap:12px;margin:0 0 10px;flex-wrap:wrap}
				.cg-tpl-count{display:flex;align-items:center;gap:6px}
				.cg-tpl-count strong{font-size:13px}
				#template_field_count{width:52px;text-align:center;font-size:14px;font-weight:700;padding:2px 4px}
				.cg-tpl-small{font-size:11px}
				.cg-tpl-check{display:flex;align-items:center;gap:4px}
				#cg-canvas-placeholder{border:2px dashed #ccc;border-radius:6px;padding:40px;text-align:center;color:#888;background:#fafafa}
				#cg-canvas-placeholder p{margin:0;font-size:14px}
				.cg-tpl-canvas-note{margin-top:6px}
				.cg-tpl-grow-label{flex:1;min-width:0}
				.cg-field-name-select{width:100%}
				.cg-tpl-unit{color:#aaa}
				.cg-field-image-row,.cg-field-image-row [id^="cg-field-image-preview-"]{margin-top:6px}
				.cg-handle-hidden-tag{font-size:9px;opacity:.8;margin-left:4px}
				.cg-canvas-error{color:#c0392b;padding:24px;margin:0;font-weight:600}
				#cg_preview_cert_btn,#cg_preview_status{margin-left:8px}

				/* ── Layout ── */
				#cg-editor-layout{display:grid;grid-template-columns:1fr 340px;gap:16px;align-items:start}
				@media(max-width:1100px){#cg-editor-layout{grid-template-columns:1fr}}

				/* ── Toolbar ── */
				#cg-canvas-toolbar{display:flex;align-items:center;gap:10px;padding:8px 10px;background:#f6f7f8;border:1px solid #ddd;border-radius:6px 6px 0 0;flex-wrap:wrap}
				#cg-canvas-toolbar label{font-size:12px;color:#555;margin:0}
				#cg-zoom-slider{width:90px;accent-color:#0073aa;cursor:pointer}
				#cg-zoom-label{font-size:12px;color:#0073aa;font-weight:700;min-width:36px}
				#cg-grid-toggle{font-size:12px}
				#cg-click-mode-toggle{font-size:12px}
				#cg-coords-display{font-size:11px;color:#666;font-family:monospace;margin-left:auto}

				/* ── Canvas wrapper ── */
				#cg-canvas-outer-scroll{overflow:auto;background:#888;border:1px solid #ccc;border-top:none;border-radius:0 0 6px 6px;padding:20px;display:flex;justify-content:center;min-height:200px;max-height:640px;box-sizing:border-box}
				#cg-canvas-container{position:relative;flex-shrink:0;box-shadow:0 6px 24px rgba(0,0,0,.45);cursor:crosshair}
				#cg-canvas-img{display:block}

				/* ── Grid overlay ── */
				#cg-grid-overlay{position:absolute;inset:0;pointer-events:none;z-index:5;display:none}
				#cg-grid-overlay.active{display:block}

				/* ── Loading spinner ── */
				#cg-canvas-spinner{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.7);z-index:50;border-radius:2px}
				#cg-canvas-spinner .cg-spin{width:36px;height:36px;border:4px solid #ddd;border-top-color:#0073aa;border-radius:50%;animation:cg-spin .7s linear infinite} /* keyframes in cg-ui.css */

				/* ── Drag tooltip ── */
				#cg-drag-tooltip{position:absolute;background:rgba(0,0,0,.78);color:#fff;font-size:11px;font-family:monospace;padding:3px 7px;border-radius:4px;pointer-events:none;z-index:100;display:none;white-space:nowrap}

				/* ── Handles ── */
				.cg-field-handle{position:absolute;box-sizing:border-box;border-radius:4px;color:#fff;padding:4px 8px 3px;font-size:11px;font-weight:700;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.3;white-space:nowrap;box-shadow:0 2px 6px rgba(0,0,0,.45);z-index:10;cursor:grab;user-select:none;transition:box-shadow .15s,outline .15s}
				.cg-field-handle:hover{box-shadow:0 3px 10px rgba(0,0,0,.55);z-index:20}
				.cg-field-handle:active{cursor:grabbing}
				.cg-field-handle.cg-active-handle{outline:3px solid #fff;box-shadow:0 0 0 5px rgba(0,115,170,.6),0 3px 10px rgba(0,0,0,.5);z-index:30}
				.cg-field-handle .cg-width-bar{display:block;height:2px;background:rgba(255,255,255,.5);border-radius:1px;margin-top:3px;min-width:4px}
				.cg-field-handle .cg-handle-num{display:inline-block;background:rgba(0,0,0,.2);border-radius:2px;padding:0 3px;margin-right:4px;font-size:10px}

				/* ── Field panel ── */
				#cg-field-panel{background:#fff;border:1px solid #ddd;border-radius:6px;overflow:hidden}
				#cg-field-panel-header{background:#f6f7f8;padding:10px 14px;border-bottom:1px solid #ddd;display:flex;align-items:center;gap:8px}
				#cg-field-panel-header strong{flex:1;font-size:13px}
				.cg-field-row-card{display:flex;align-items:flex-start;gap:0;border-bottom:1px solid #f0f0f1;transition:background .15s;cursor:pointer}
				.cg-field-row-card:last-child{border-bottom:none}
				.cg-field-row-card:hover{background:#f8f9fa}
				.cg-field-row-card.cg-row-active{background:#e8f4fb}
				.cg-field-color-bar{width:5px;flex-shrink:0;align-self:stretch;border-radius:0}
				.cg-field-row-body{padding:8px 12px;flex:1;min-width:0}
				.cg-field-row-label{font-weight:600;font-size:12px;color:#1d2327;margin-bottom:4px;display:flex;align-items:center;gap:6px}
				.cg-field-slot-badge{font-size:10px;color:#888;font-weight:400}
				.cg-field-row-inputs{display:grid;grid-template-columns:1fr 1fr;gap:4px 8px}
				.cg-field-input-wrap{display:flex;align-items:center;gap:4px;font-size:11px;color:#555}
				.cg-field-input-wrap input[type=number]{width:54px;padding:2px 4px;font-size:12px;border:1px solid #ccc;border-radius:3px}
				.cg-field-input-wrap input[type=number]:focus{border-color:#0073aa;outline:none;box-shadow:0 0 0 1px #0073aa}
				.cg-field-row-footer{display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap}
				.cg-field-row-footer select{font-size:11px;padding:1px 2px;border:1px solid #ccc;border-radius:3px}
				.cg-field-row-footer input[type=number]{width:54px;font-size:11px;padding:2px 4px;border:1px solid #ccc;border-radius:3px}
				.cg-field-row-footer label{font-size:11px;color:#555;display:flex;align-items:center;gap:3px}
				.cg-center-btn{font-size:10px;padding:2px 6px;line-height:1.6;margin-left:auto;flex-shrink:0}
				.cg-field-hidden-badge{font-size:10px;color:#c0392b;font-weight:600}

				/* ── Field count control ── */
				#cg-field-count-bar{display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f6f7f8;border-bottom:1px solid #ddd}
				#cg-field-count-bar strong{font-size:12px;color:#555}
				.cg-count-btn{font-size:15px;padding:0 8px;line-height:24px;min-height:0;height:26px}

				/* ── Click-mode banner ── */
				#cg-click-mode-banner{background:#0073aa;color:#fff;padding:5px 12px;font-size:12px;font-weight:600;display:none;align-items:center;gap:8px;border-radius:4px;margin-top:6px}
				#cg-click-mode-banner button{background:rgba(255,255,255,.2);border:none;color:#fff;cursor:pointer;padding:2px 8px;border-radius:3px;font-size:11px}
				</style>

				<!-- Field count + click mode banner outside the 2-col grid -->
				<div class="cg-tpl-countbar">
					<div class="cg-tpl-count">
						<strong><?php esc_html_e( 'Fields on certificate:', 'certificate-generator' ); ?></strong>
						<button type="button" id="cg_field_count_dec" class="button cg-count-btn">−</button>
						<input type="number" id="template_field_count" name="template_field_count"
							value="<?php echo esc_attr( (string) $field_count_val ); ?>"
							min="2" max="<?php echo esc_attr( (string) $max_fields_ui ); ?>">
						<button type="button" id="cg_field_count_inc" class="button cg-count-btn">+</button>
						<span class="description cg-tpl-small"><?php printf( /* translators: %d: maximum number of fields */ esc_html__( 'max %d', 'certificate-generator' ), (int) $max_fields_ui ); ?></span>
					</div>
					<div id="cg-click-mode-banner">
						<span><?php esc_html_e( 'Click mode: click canvas to place', 'certificate-generator' ); ?> <strong id="cg-click-mode-label">—</strong></span>
						<button type="button" id="cg-click-mode-cancel"><?php esc_html_e( '✕ Cancel', 'certificate-generator' ); ?></button>
					</div>
				</div>

				<div id="cg-editor-layout">

					<!-- LEFT: Canvas -->
					<div>
						<!-- Toolbar -->
						<div id="cg-canvas-toolbar">
							<label><?php esc_html_e( 'Zoom', 'certificate-generator' ); ?> <input type="range" id="cg-zoom-slider" min="50" max="200" value="100" step="10"></label>
							<span id="cg-zoom-label">100%</span>
							<label class="cg-tpl-check">
								<input type="checkbox" id="cg-grid-toggle"> <?php esc_html_e( 'Grid', 'certificate-generator' ); ?>
							</label>
							<span id="cg-coords-display">—</span>
						</div>
						<!-- Canvas -->
						<div id="cg-canvas-outer-scroll" <?php echo $template_url_val ? '' : 'style="display:none"'; ?>>
							<div id="cg-canvas-container">
								<img id="cg-canvas-img" src="<?php echo esc_url( $template_url_val ); ?>" alt="Certificate template preview">
								<canvas id="cg-grid-overlay"></canvas>
								<div id="cg-canvas-spinner" style="display:none" role="status" aria-label="<?php esc_attr_e( 'Loading', 'certificate-generator' ); ?>"><div class="cg-spin"></div></div>
								<div id="cg-drag-tooltip"></div>
							</div>
						</div>
						<?php if ( ! $template_url_val ) : ?>
						<div id="cg-canvas-placeholder">
							<p><?php esc_html_e( 'Set a Template URL above to see the canvas', 'certificate-generator' ); ?></p>
						</div>
						<?php endif; ?>
						<p class="description cg-tpl-small cg-tpl-canvas-note"><?php esc_html_e( 'Coordinates in mm · Faded = hidden · Click handle then click canvas to reposition', 'certificate-generator' ); ?></p>
					</div>

					<!-- RIGHT: Field panel -->
					<div id="cg-field-panel">
						<div id="cg-field-panel-header">
							<strong><?php esc_html_e( 'Field Slots', 'certificate-generator' ); ?></strong>
							<span class="description cg-tpl-small"><?php esc_html_e( 'Click row to activate', 'certificate-generator' ); ?></span>
						</div>

						<?php
						for ( $i = 1; $i <= $max_fields_ui; $i++ ) :
							$px    = $extra_fields_data[ "field_{$i}_position_x" ] ?? 0;
							$py    = $extra_fields_data[ "field_{$i}_position_y" ] ?? 0;
							$vis   = ( $extra_fields_data[ "field_{$i}_visible" ] ?? '1' ) === '1'; // default visible
							$pw    = $extra_fields_data[ "field_{$i}_width" ] ?? 100;
							$ph    = $extra_fields_data[ "field_{$i}_height" ] ?? '';
							$pa    = $extra_fields_data[ "field_{$i}_alignment" ] ?? 'C';
							$ftype = $extra_fields_data[ "field_{$i}_type" ] ?? 'text';
							$fimg  = $extra_fields_data[ "field_{$i}_image_url" ] ?? '';
							$show  = $i <= $field_count_val ? '' : 'display:none;';

							$saved_field_key = $extra_fields_data[ "field_{$i}_name" ] ?? '';
							if ( $saved_field_key !== '' ) {
								$current_field_key = $saved_field_key;
							} elseif ( $has_explicit_mapping ) {
								$current_field_key = ''; // template uses explicit mapping elsewhere; force a deliberate choice here too
							} else {
								$current_field_key = $all_renderable[ $i - 1 ] ?? ''; // untouched template — mirror today's positional behaviour
							}
							?>
						<div class="cg-field-row-card" data-field-index="<?php echo (int) $i; ?>" id="cg-row-<?php echo (int) $i; ?>" style="<?php echo esc_attr( $show ); ?>">
							<div class="cg-field-color-bar" id="cg-colorbar-<?php echo (int) $i; ?>"></div>
							<div class="cg-field-row-body">
								<div class="cg-field-row-label">
									<span class="cg-field-slot-badge">slot <?php echo (int) $i; ?></span>
									<?php
									if ( ! $vis ) :
										?>
										<span class="cg-field-hidden-badge"><?php esc_html_e( 'hidden', 'certificate-generator' ); ?></span><?php endif; ?>
								</div>
								<div class="cg-field-row-footer">
									<label class="cg-tpl-grow-label">
										<select id="field_<?php echo (int) $i; ?>_name" name="field_<?php echo (int) $i; ?>_name" class="cg-field-name-select">
											<option value=""><?php esc_html_e( '— Select field —', 'certificate-generator' ); ?></option>
											<?php foreach ( $field_options_by_entity[ $entity_type_val ] as $opt ) : ?>
												<option value="<?php echo esc_attr( $opt['key'] ); ?>" <?php selected( $current_field_key, $opt['key'] ); ?>><?php echo esc_html( $opt['label'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>
								<div class="cg-field-row-inputs">
									<div class="cg-field-input-wrap">
										<span>X</span>
										<input type="number" id="field_<?php echo (int) $i; ?>_position_x" step="0.01" name="field_<?php echo (int) $i; ?>_position_x"
											value="<?php echo esc_attr( $px ); ?>" min="0" max="<?php echo $orientation_val === 'landscape' ? 297 : 210; ?>">
										<span class="cg-tpl-unit">mm</span>
									</div>
									<div class="cg-field-input-wrap">
										<span>Y</span>
										<input type="number" id="field_<?php echo (int) $i; ?>_position_y" step="0.01" name="field_<?php echo (int) $i; ?>_position_y"
											value="<?php echo esc_attr( $py ); ?>" min="0" max="<?php echo $orientation_val === 'landscape' ? 210 : 297; ?>">
										<span class="cg-tpl-unit">mm</span>
									</div>
								</div>
								<div class="cg-field-row-footer">
									<label>W <input type="number" id="field_<?php echo (int) $i; ?>_width" step="0.01" name="field_<?php echo (int) $i; ?>_width" value="<?php echo esc_attr( $pw ); ?>" min="1" max="297"> mm</label>
									<label id="cg-field-height-wrap-<?php echo (int) $i; ?>" style="<?php echo in_array( $ftype, array( 'image', 'photo' ), true ) ? '' : 'display:none;'; ?>">H <input type="number" id="field_<?php echo (int) $i; ?>_height" step="0.01" name="field_<?php echo (int) $i; ?>_height" value="<?php echo esc_attr( $ph ); ?>" min="0" max="297" placeholder="auto"> mm</label>
									<label><?php esc_html_e( 'Align', 'certificate-generator' ); ?>
										<select id="field_<?php echo (int) $i; ?>_alignment" name="field_<?php echo (int) $i; ?>_alignment">
											<option value="L" <?php selected( $pa, 'L' ); ?>>L</option>
											<option value="C" <?php selected( $pa, 'C' ); ?>>C</option>
											<option value="R" <?php selected( $pa, 'R' ); ?>>R</option>
										</select>
									</label>
									<label><input type="checkbox" id="field_<?php echo (int) $i; ?>_visible" name="field_<?php echo (int) $i; ?>_visible" value="1" <?php checked( $vis ); ?>> <?php esc_html_e( 'Visible', 'certificate-generator' ); ?></label>
									<button type="button" class="button cg-center-btn" data-field="<?php echo (int) $i; ?>" title="<?php esc_attr_e( 'Centre on page', 'certificate-generator' ); ?>">⊕ <?php esc_html_e( 'Center', 'certificate-generator' ); ?></button>
								</div>
								<div class="cg-field-row-footer">
									<label><?php esc_html_e( 'Type', 'certificate-generator' ); ?>
										<select id="field_<?php echo (int) $i; ?>_type" name="field_<?php echo (int) $i; ?>_type" class="cg-field-type-select" data-field="<?php echo (int) $i; ?>">
											<option value="text" <?php selected( $ftype, 'text' ); ?>><?php esc_html_e( 'Text', 'certificate-generator' ); ?></option>
											<option value="image" <?php selected( $ftype, 'image' ); ?>><?php esc_html_e( 'Image (e.g. signature)', 'certificate-generator' ); ?></option>
											<option value="photo" <?php selected( $ftype, 'photo' ); ?>><?php esc_html_e( 'Photo (student photo)', 'certificate-generator' ); ?></option>
										</select>
									</label>
								</div>
								<div class="cg-field-image-row" id="cg-field-image-row-<?php echo (int) $i; ?>"<?php echo $ftype === 'image' ? '' : ' style="display:none;"'; ?>>
									<input type="hidden" id="field_<?php echo (int) $i; ?>_image_url" name="field_<?php echo (int) $i; ?>_image_url" value="<?php echo esc_attr( $fimg ); ?>">
									<button type="button" class="button cg-media-btn" data-input="#field_<?php echo (int) $i; ?>_image_url" data-preview="#cg-field-image-preview-<?php echo (int) $i; ?>" data-title="<?php esc_attr_e( 'Select Image', 'certificate-generator' ); ?>" data-button-text="<?php esc_attr_e( 'Use this image', 'certificate-generator' ); ?>"><?php esc_html_e( 'Choose Image', 'certificate-generator' ); ?></button>
									<div id="cg-field-image-preview-<?php echo (int) $i; ?>"<?php echo $fimg ? '' : ' style="display:none;"'; ?>>
										<?php if ( $fimg ) : ?><img src="<?php echo esc_url( $fimg ); ?>" class="cg-tpl-thumb cg-tpl-thumb--field" alt=""><?php endif; ?>
									</div>
								</div>
							</div>
						</div>
						<?php endfor; ?>
					</div><!-- /#cg-field-panel -->

				</div><!-- /#cg-editor-layout -->

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html( $id ? __( 'Update Template', 'certificate-generator' ) : __( 'Add Template', 'certificate-generator' ) ); ?></button>
					<a href="<?php echo esc_url( $list_url ); ?>" class="button"><?php esc_html_e( 'Cancel', 'certificate-generator' ); ?></a>
					<?php if ( $id ) : ?>
					<button type="button" id="cg_preview_cert_btn" class="button button-secondary"><?php esc_html_e( 'Preview Certificate', 'certificate-generator' ); ?></button>
					<button type="button" id="cg_test_cert_btn" class="button button-secondary" title="<?php esc_attr_e( 'Uses the saved template. Save first if you changed anything.', 'certificate-generator' ); ?>">
						<?php
						/* translators: %s: the admin's email address */
						echo esc_html( sprintf( __( 'Email me a test certificate (%s)', 'certificate-generator' ), wp_get_current_user()->user_email ) );
						?>
					</button>
					<span id="cg_preview_status" aria-live="polite"></span>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<script>
		(function ($) {
			var MAX_FIELDS   = <?php echo (int) $max_fields_ui; ?>;
			var FIELD_LABELS = <?php echo wp_json_encode( array_values( $canvas_labels ) ); ?>;
			var FONT_MAP     = <?php echo wp_json_encode( array_flip( $font_options ) ); ?>; // label -> key
			var FIELD_OPTIONS_BY_ENTITY = <?php echo wp_json_encode( $field_options_by_entity ); ?>;
			var COLORS = [
				'#c0392b','#2980b9','#27ae60','#8e44ad','#f39c12',
				'#16a085','#d35400','#1abc9c','#e74c3c','#7f8c8d',
				'#0097a7','#e67e22','#3498db','#9b59b6','#2c3e50'
			];
			var BASE_CANVAS_H = 520;
			var zoomPct       = 100;
			var clickModeField = null; // null = off, number = active field N

			var $canvas  = $('#cg-canvas-container');
			var $img     = $('#cg-canvas-img');
			var $scroll  = $('#cg-canvas-outer-scroll');
			var $tooltip = $('#cg-drag-tooltip');
			var $gridCvs = $('#cg-grid-overlay')[0];

			// ── Helpers ──────────────────────────────────────────────────

			function orientation() { return $('#orientation').val() || 'landscape'; }

			function pageDims() {
				return orientation() === 'landscape' ? { w: 297, h: 210 } : { w: 210, h: 297 };
			}

			function updateInputMaxValues() {
				var d = pageDims();
				for (var _i = 1; _i <= MAX_FIELDS; _i++) {
					$('#field_' + _i + '_position_x').attr('max', d.w);
					$('#field_' + _i + '_position_y').attr('max', d.h);
				}
				$('#qr_position_x, #serial_number_position_x').attr('max', d.w);
				$('#qr_position_y, #serial_number_position_y').attr('max', d.h);
			}

			// Repopulate every slot's field-name dropdown from the newly selected
			// Entity Type, keeping the current selection when it's still valid.
			function repopulateFieldKeySelects() {
				var opts = FIELD_OPTIONS_BY_ENTITY[$('#entity_type').val()] || [];
				for (var _i = 1; _i <= MAX_FIELDS; _i++) {
					var $sel = $('#field_' + _i + '_name');
					if (!$sel.length) continue;
					var cur = $sel.val();
					var stillValid = false;
					$sel.empty();
					$sel.append($('<option>', { value: '', text: '— Select field —' }));
					opts.forEach(function (o) {
						$sel.append($('<option>', { value: o.key, text: o.label }));
						if (o.key === cur) stillValid = true;
					});
					$sel.val(stillValid ? cur : '');
				}
			}
			$('#entity_type').on('change', repopulateFieldKeySelects);

			// Matches TemplatesPage::POSITION_DEFAULTS (PHP) — kept in sync so a
			// value valid in one orientation doesn't silently stay off-page after
			// switching, both here (immediate UI feedback) and server-side on save.
			var POSITION_DEFAULTS = {
				landscape: { qr: [250.0, 180.0], serial: [105.0, 200.0] },
				portrait:  { qr: [176.8, 254.6], serial: [74.2, 282.9] }
			};

			function resetOutOfBoundsQrSerial() {
				var d = pageDims();
				var o = orientation() === 'portrait' ? 'portrait' : 'landscape';
				[['qr', '#qr_position_x', '#qr_position_y'], ['serial', '#serial_number_position_x', '#serial_number_position_y']].forEach(function (f) {
					var x = parseFloat($(f[1]).val());
					var y = parseFloat($(f[2]).val());
					if (isNaN(x) || isNaN(y) || x < 0 || x > d.w || y < 0 || y > d.h) {
						$(f[1]).val(POSITION_DEFAULTS[o][f[0]][0]);
						$(f[2]).val(POSITION_DEFAULTS[o][f[0]][1]);
					}
				});
			}

			function canvasDims() {
				var d  = pageDims();
				var h  = Math.round(BASE_CANVAS_H * zoomPct / 100);
				return { w: Math.round((d.w / d.h) * h), h: h };
			}

			function mmToPx(val, axis) {
				var d = pageDims(), cd = canvasDims();
				return axis === 'x' ? (val / d.w) * cd.w : (val / d.h) * cd.h;
			}

			function pxToMm(val, axis) {
				var d = pageDims(), cd = canvasDims();
				return axis === 'x'
					? Math.round((val / cd.w) * d.w * 10) / 10
					: Math.round((val / cd.h) * d.h * 10) / 10;
			}

			function updateCanvasSize() {
				var cd = canvasDims();
				$canvas.css({ width: cd.w + 'px', height: cd.h + 'px' });
				$img.css({ width: cd.w + 'px', height: cd.h + 'px' });
				if ($gridCvs) {
					$gridCvs.width  = cd.w;
					$gridCvs.height = cd.h;
					$($gridCvs).css({ width: cd.w + 'px', height: cd.h + 'px' });
				}
				drawGrid();
			}

			// ── Grid overlay ─────────────────────────────────────────────

			function drawGrid() {
				if (!$gridCvs) return;
				var ctx = $gridCvs.getContext('2d');
				var cd  = canvasDims(), d = pageDims();
				ctx.clearRect(0, 0, cd.w, cd.h);
				if (!$('#cg-grid-toggle').is(':checked')) return;
				ctx.strokeStyle = 'rgba(0,115,170,0.18)';
				ctx.lineWidth   = 1;
				// 10mm grid
				for (var x = 0; x <= d.w; x += 10) {
					var px = Math.round(mmToPx(x, 'x')) + 0.5;
					ctx.beginPath(); ctx.moveTo(px, 0); ctx.lineTo(px, cd.h); ctx.stroke();
				}
				for (var y = 0; y <= d.h; y += 10) {
					var py = Math.round(mmToPx(y, 'y')) + 0.5;
					ctx.beginPath(); ctx.moveTo(0, py); ctx.lineTo(cd.w, py); ctx.stroke();
				}
				// Labels every 50mm
				ctx.fillStyle = 'rgba(0,115,170,0.45)';
				ctx.font = '9px monospace';
				for (var xL = 0; xL <= d.w; xL += 50) {
					ctx.fillText(xL, Math.round(mmToPx(xL, 'x')) + 2, 10);
				}
				for (var yL = 50; yL <= d.h; yL += 50) {
					ctx.fillText(yL, 2, Math.round(mmToPx(yL, 'y')) - 2);
				}
			}

			// ── Color bars in field panel ─────────────────────────────────

			function applyColors() {
				var count = Math.min(parseInt($('#template_field_count').val(), 10) || 3, MAX_FIELDS);
				for (var i = 1; i <= MAX_FIELDS; i++) {
					var color = i <= count ? COLORS[(i - 1) % COLORS.length] : '#ddd';
					$('#cg-colorbar-' + i).css('background', color);
				}
			}

			// ── Active field highlight ────────────────────────────────────

			function setActiveRow(n) {
				$('.cg-field-row-card').removeClass('cg-row-active');
				$('.cg-field-handle').removeClass('cg-active-handle');
				if (n) {
					$('#cg-row-' + n).addClass('cg-row-active');
					$('#cg-handle-' + n).addClass('cg-active-handle');
				}
			}

			// ── Handle positioning ────────────────────────────────────────

			function positionHandle(n) {
				var $h = $('#cg-handle-' + n);
				if (!$h.length) return;
				var x_mm = parseFloat($('#field_' + n + '_position_x').val()) || 0;
				var y_mm = parseFloat($('#field_' + n + '_position_y').val()) || 0;
				var w_mm = parseFloat($('#field_' + n + '_width').val())      || 100;
				var cx   = mmToPx(x_mm, 'x');
				var cy   = mmToPx(y_mm, 'y');
				var wPx  = Math.max(4, mmToPx(w_mm, 'x'));
				var hw   = $h.outerWidth()  / 2 || 0;
				var hh   = $h.outerHeight() / 2 || 0;
				$h.css({ left: (cx - hw) + 'px', top: (cy - hh) + 'px' });
				$h.find('.cg-width-bar').css('width', wPx + 'px');
				var visible = $('#field_' + n + '_visible').prop('checked');
				// Canvas is a layout editor — always show & keep interactive.
				// "hidden" only means the field won't render on the PDF.
				// Indicate hidden state with a dashed outline + eye-slash suffix, NOT by disabling.
				$h.css({ opacity: '1', 'pointer-events': '' });
				if (visible) {
					$h.css({ outline: 'none' }).removeClass('cg-handle-hidden');
					$h.find('.cg-handle-hidden-tag').remove();
				} else {
					$h.css({ outline: '2px dashed rgba(255,255,255,0.7)' }).addClass('cg-handle-hidden');
					if (!$h.find('.cg-handle-hidden-tag').length) {
						$h.append('<span class="cg-handle-hidden-tag">(hidden)</span>');
					}
				}
				// Update hidden badge in panel
				var $badge = $('#cg-row-' + n + ' .cg-field-hidden-badge');
				if (!visible) { if (!$badge.length) $('#cg-row-' + n + ' .cg-field-row-label').append('<span class="cg-field-hidden-badge">hidden</span>'); }
				else { $badge.remove(); }
			}

			function positionQRHandle() {
				var $h = $('#cg-handle-qr');
				if (!$h.length) return;
				if (!$('#qr_enabled').is(':checked')) { $h.hide(); return; }
				$h.show();
				var cx  = mmToPx(parseFloat($('#qr_position_x').val())  || 250, 'x');
				var cy  = mmToPx(parseFloat($('#qr_position_y').val())  || 180, 'y');
				var sPx = Math.max(mmToPx(parseFloat($('#qr_size').val()) || 15, 'x'), 24);
				$h.css({ left: cx + 'px', top: cy + 'px', width: sPx + 'px', height: sPx + 'px' });
			}

			function positionSerialHandle() {
				var $h = $('#cg-handle-serial');
				if (!$h.length) return;
				if (!$('#serial_number_display').is(':checked')) { $h.hide(); return; }
				$h.show();
				var cx = mmToPx(parseFloat($('#serial_number_position_x').val()) || 105, 'x');
				var cy = mmToPx(parseFloat($('#serial_number_position_y').val()) || 200, 'y');
				var hw = $h.outerWidth() / 2 || 0, hh = $h.outerHeight() / 2 || 0;
				$h.css({ left: (cx - hw) + 'px', top: (cy - hh) + 'px' });
			}

			function repositionAll() {
				$canvas.find('.cg-field-handle').each(function () {
					var n = parseInt($(this).data('field'), 10);
					if (n) positionHandle(n);
				});
				positionQRHandle();
				positionSerialHandle();
			}

			// ── Tooltip helper ────────────────────────────────────────────

			function showTooltip(x, y, text) {
				$tooltip.text(text).css({ left: x + 'px', top: (y - 28) + 'px' }).show();
			}
			function hideTooltip() { $tooltip.hide(); }

			// ── Build all draggable handles ───────────────────────────────

			function buildHandles() {
				$canvas.find('.cg-field-handle').remove();
				var count = Math.min(parseInt($('#template_field_count').val(), 10) || 3, MAX_FIELDS);

				var d = pageDims();
				for (var n = 1; n <= count; n++) {
					var label = FIELD_LABELS[n - 1] || ('Field ' + n);
					var color = COLORS[(n - 1) % COLORS.length];
					var $h = $('<div>')
						.attr({ id: 'cg-handle-' + n, 'data-field': n })
						.addClass('cg-field-handle')
						.css('background', color)
						.html('<span class="cg-handle-num">' + n + '</span><span class="cg-handle-label">' + $('<span>').text(label).html() + '</span><span class="cg-width-bar"></span>');
					$canvas.append($h);

					// If this field has no saved position (0,0), scatter it down the canvas
					// so unpositioned handles don't pile on top of each other at the corner.
					var savedX = parseFloat($('#field_' + n + '_position_x').val()) || 0;
					var savedY = parseFloat($('#field_' + n + '_position_y').val()) || 0;
					if (savedX === 0 && savedY === 0) {
						var autoY = Math.round((d.h / (count + 1)) * n);
						var autoX = Math.round(d.w / 2);
						$('#field_' + n + '_position_x').val(autoX);
						$('#field_' + n + '_position_y').val(autoY);
					}

					positionHandle(n);

					(function (fieldN, $handle) {
						$handle
							.on('mousedown', function () { setActiveRow(fieldN); cancelClickMode(); })
							.draggable({
								containment: '#cg-canvas-container',
								cursor: 'grabbing',
								start: function () { setActiveRow(fieldN); },
								drag: function (e, ui) {
									var hw  = $handle.outerWidth() / 2, hh = $handle.outerHeight() / 2;
									var cd  = canvasDims(), d = pageDims();
									var cx_px = Math.max(0, Math.min(cd.w, ui.position.left + hw));
									var cy_px = Math.max(0, Math.min(cd.h, ui.position.top  + hh));
									var xMm = Math.max(0, Math.min(d.w, pxToMm(cx_px, 'x')));
									var yMm = Math.max(0, Math.min(d.h, pxToMm(cy_px, 'y')));
									$('#field_' + fieldN + '_position_x').val(xMm);
									$('#field_' + fieldN + '_position_y').val(yMm);
									showTooltip(ui.position.left + hw, ui.position.top, 'X:' + xMm + ' Y:' + yMm);
									$('#cg-coords-display').text('X: ' + xMm + ' mm  Y: ' + yMm + ' mm');
								},
								stop: function () { hideTooltip(); }
							});
					})(n, $h);
				}

				// QR handle
				$canvas.find('#cg-handle-qr').remove();
				if ($('#qr_enabled').is(':checked')) {
					var sPx = Math.max(mmToPx(parseFloat($('#qr_size').val()) || 15, 'x'), 24);
					var $qr = $('<div>').attr('id', 'cg-handle-qr').addClass('cg-field-handle')
						.css({ background: '#8e44ad', width: sPx + 'px', height: sPx + 'px', padding: '2px', overflow: 'hidden' })
						.append($('<span>').addClass('cg-handle-label').css('font-size', '9px').text('QR'));
					$canvas.append($qr);
					positionQRHandle();
					$qr.draggable({ containment: '#cg-canvas-container', cursor: 'grabbing',
						drag: function (e, ui) {
							var cd = canvasDims(), d = pageDims();
							var xMm = Math.max(0, Math.min(d.w, pxToMm(Math.max(0, Math.min(cd.w, ui.position.left)), 'x')));
							var yMm = Math.max(0, Math.min(d.h, pxToMm(Math.max(0, Math.min(cd.h, ui.position.top)),  'y')));
							$('#qr_position_x').val(xMm);
							$('#qr_position_y').val(yMm);
							showTooltip(ui.position.left, ui.position.top, 'QR X:' + xMm + ' Y:' + yMm);
						},
						stop: function () { hideTooltip(); }
					});
				}

				// Serial handle
				$canvas.find('#cg-handle-serial').remove();
				if ($('#serial_number_display').is(':checked')) {
					var $sn = $('<div>').attr('id', 'cg-handle-serial').addClass('cg-field-handle')
						.css({ background: '#16a085' })
						.append($('<span>').addClass('cg-handle-label').text('# Serial'));
					$canvas.append($sn);
					positionSerialHandle();
					$sn.draggable({ containment: '#cg-canvas-container', cursor: 'grabbing',
						drag: function (e, ui) {
							var hw = $sn.outerWidth()/2, hh = $sn.outerHeight()/2;
							var cd = canvasDims(), d = pageDims();
							var xMm = Math.max(0, Math.min(d.w, pxToMm(Math.max(0, Math.min(cd.w, ui.position.left + hw)), 'x')));
							var yMm = Math.max(0, Math.min(d.h, pxToMm(Math.max(0, Math.min(cd.h, ui.position.top  + hh)), 'y')));
							$('#serial_number_position_x').val(xMm);
							$('#serial_number_position_y').val(yMm);
							showTooltip(ui.position.left + hw, ui.position.top, 'Serial X:' + xMm + ' Y:' + yMm);
						},
						stop: function () { hideTooltip(); }
					});
				}

				applyColors();
			}

			// ── Click-to-place mode ───────────────────────────────────────

			function enterClickMode(n) {
				clickModeField = n;
				setActiveRow(n);
				var label = FIELD_LABELS[n - 1] || ('Field ' + n);
				$('#cg-click-mode-label').text(label);
				$('#cg-click-mode-banner').css('display', 'flex');
				$canvas.css('cursor', 'crosshair');
			}

			function cancelClickMode() {
				clickModeField = null;
				$('#cg-click-mode-banner').hide();
				$canvas.css('cursor', 'crosshair');
			}

			$canvas.on('click', function (e) {
				if (!clickModeField) return;
				var off    = $canvas.offset();
				var cx_px  = e.pageX - off.left;
				var cy_px  = e.pageY - off.top;
				var d      = pageDims();
				var cd     = canvasDims();
				var xMm = Math.max(0, Math.min(d.w, pxToMm(cx_px, 'x')));
				var yMm = Math.max(0, Math.min(d.h, pxToMm(cy_px, 'y')));
				$('#field_' + clickModeField + '_position_x').val(xMm);
				$('#field_' + clickModeField + '_position_y').val(yMm);
				positionHandle(clickModeField);
				cancelClickMode();
			});

			// Show live coordinates on mouse move over canvas
			$canvas.on('mousemove', function (e) {
				var off   = $canvas.offset();
				var cx_px = e.pageX - off.left;
				var cy_px = e.pageY - off.top;
				var xMm   = Math.max(0, pxToMm(cx_px, 'x'));
				var yMm   = Math.max(0, pxToMm(cy_px, 'y'));
				$('#cg-coords-display').text('X: ' + xMm + ' mm  Y: ' + yMm + ' mm');
			}).on('mouseleave', function () {
				$('#cg-coords-display').text('—');
			});

			// ── Canvas init / reload ──────────────────────────────────────

			function showSpinner(show) {
				$('#cg-canvas-spinner').toggle(show);
			}

			function initCanvas() {
				var url = $('#template_url').val().trim();
				if (!url) {
					$scroll.hide();
					$('#cg-canvas-placeholder').show();
					return;
				}
				$scroll.show();
				$('#cg-canvas-placeholder').hide();
				updateCanvasSize();
				$img.off('load.cgcanvas error.cgcanvas');
				$img.one('error.cgcanvas', function () {
					$scroll.html('<p class="cg-canvas-error">&#9888; Could not load template image. Verify the URL is publicly accessible.</p>');
				});
				if ($img.attr('src') === url) {
					showSpinner(false);
					buildHandles();
					return;
				}
				showSpinner(true);
				$img.attr('src', url);
				if ($img[0].complete && $img[0].naturalWidth) {
					showSpinner(false);
					buildHandles();
				} else {
					$img.one('load.cgcanvas', function () {
						showSpinner(false);
						buildHandles();
					});
				}
			}

			// ── Field count rows helper ───────────────────────────────────

			function updateFieldRows() {
				var count = parseInt($('#template_field_count').val(), 10);
				$('.cg-field-row-card').each(function () {
					$(this).toggle(parseInt($(this).data('field-index'), 10) <= count);
				});
				applyColors();
			}

			// ── All event bindings + boot deferred until DOM+scripts ready ──
			// jQuery UI Draggable is a footer script; wrapping in $(function(){})
			// ensures it is loaded before buildHandles() calls .draggable().
			$(function () {

				$('#cg_field_count_dec').on('click', function () {
					var inp = $('#template_field_count');
					inp.val(Math.max(parseInt(inp.attr('min'), 10), parseInt(inp.val(), 10) - 1));
					updateFieldRows();
					setTimeout(buildHandles, 20);
				});
				$('#cg_field_count_inc').on('click', function () {
					var inp = $('#template_field_count');
					inp.val(Math.min(parseInt(inp.attr('max'), 10), parseInt(inp.val(), 10) + 1));
					updateFieldRows();
					setTimeout(buildHandles, 20);
				});
				$('#template_field_count').on('change', function () { updateFieldRows(); setTimeout(buildHandles, 20); });

				// ── Event bindings ────────────────────────────────────────────

				$('#template_url').on('change', initCanvas);
				$('#orientation').on('change', function () { updateCanvasSize(); updateInputMaxValues(); resetOutOfBoundsQrSerial(); repositionAll(); });
				$('#qr_enabled, #serial_number_display').on('change', buildHandles);
				$('#qr_size,#qr_position_x,#qr_position_y,#serial_number_position_x,#serial_number_position_y').on('change input', function () { positionQRHandle(); positionSerialHandle(); });

				for (var _n = 1; _n <= MAX_FIELDS; _n++) {
					(function (n) {
						$('#field_' + n + '_position_x, #field_' + n + '_position_y, #field_' + n + '_width').on('change input', function () { positionHandle(n); });
						$('#field_' + n + '_visible').on('change', function () { positionHandle(n); });
					})(_n);
				}

				// ── Media uploader (shared CgMediaUploader module) ────────────

				if (typeof CgMediaUploader !== 'undefined') {
					CgMediaUploader.init({
						button:     '#cg_upload_template_btn',
						input:      '#template_url',
						preview:    '#cg_template_preview',
						title:      'Select Template Image',
						buttonText: 'Use this image',
					});
				}

				// ── Bundled template gallery ──────────────────────────────────
				$('#cg_use_gallery_btn').on('click', function () {
					var id = $('#cg_gallery_select').val();
					if (!id) { return; }
					var $status = $('#cg_gallery_status').text('Loading…');
					$.post(ajaxurl, {
						action:      'certificate_generator_use_bundled_template',
						nonce:       '<?php echo esc_js( wp_create_nonce( 'cg_bundled_template' ) ); ?>',
						template_id: id
					}).done(function (resp) {
						if (resp && resp.success) {
							if (resp.data.orientation) {
								$('#orientation').val(resp.data.orientation);
							}
							$('#template_url').val(resp.data.url).trigger('change');
							$status.text('Done.');
						} else {
							$status.text((resp && resp.data && resp.data.message) || 'Failed.');
						}
					}).fail(function () {
						$status.text('Request failed.');
					});
				});

				// Field type toggle: show/hide the image-upload row and the Height input
				// per slot. The "Choose Image" button itself is wired by
				// CgMediaUploader.autoInit() via its .cg-media-btn/data-input/data-preview
				// attributes — no init needed here.
				$('.cg-field-type-select').on('change', function () {
					var n        = $(this).data('field');
					var isImageish = ['image', 'photo'].indexOf($(this).val()) !== -1;
					$('#cg-field-image-row-' + n).toggle($(this).val() === 'image');
					$('#cg-field-height-wrap-' + n).toggle(isImageish);
				});

				// ── Boot ──────────────────────────────────────────────────────
				updateFieldRows();
				applyColors();
				initCanvas();
				updateInputMaxValues();

				// ── Preview Certificate button ────────────────────────────────
				$('#cg_preview_cert_btn').on('click', function () {
					var $btn    = $(this);
					var $status = $('#cg_preview_status');
					$btn.prop('disabled', true);
					$status.text('Generating…');
					var data = $('#cg-template-form').serializeArray();
					data.push({ name: 'action',      value: 'certificate_generator_preview_template' });
					data.push({ name: 'nonce',       value: '<?php echo esc_html( wp_create_nonce( 'cg_admin_preview_nonce' ) ); ?>' });
					data.push({ name: 'template_id', value: '<?php echo (int) $id; ?>' });
					var $form = $('<form method="POST" target="_blank" action="' + ajaxurl + '">');
					$.each(data, function (_, field) {
						$form.append($('<input type="hidden">').attr('name', field.name).val(field.value));
					});
					$('body').append($form);
					$form.submit().remove();
					setTimeout(function () { $btn.prop('disabled', false); $status.text(''); }, 1500);
				});

				// ── Email me a test certificate ───────────────────────────────
				$('#cg_test_cert_btn').on('click', function () {
					var btn     = this;
					var $status = $('#cg_preview_status');
					CGUI.busy(btn, true);
					$status.text('<?php echo esc_js( __( 'Sending…', 'certificate-generator' ) ); ?>');
					$.post(ajaxurl, {
						action: 'certificate_generator_send_test_certificate',
						nonce: '<?php echo esc_js( wp_create_nonce( 'certificate_generator_send_test_certificate' ) ); ?>',
						template_id: '<?php echo (int) $id; ?>'
					}).done(function (response) {
						$status.text((response.data && response.data.message) || '');
					}).fail(function () {
						$status.text('<?php echo esc_js( __( 'An error occurred. Please try again.', 'certificate-generator' ) ); ?>');
					}).always(function () {
						CGUI.busy(btn, false);
					});
				});

				// Row card click-to-activate (moved inside ready to ensure consistent binding)
				$(document).on('click', '.cg-field-row-card', function (e) {
					if ($(e.target).is('input, select, button')) return;
					var n = parseInt($(this).data('field-index'), 10);
					setActiveRow(n);
					if ($('#cg-row-' + n).hasClass('cg-row-active') && clickModeField !== n) {
						enterClickMode(n);
					}
				});

				$(document).on('click', '.cg-center-btn', function (e) {
					e.stopPropagation();
					var n = parseInt($(this).data('field'), 10);
					var d = pageDims();
					$('#field_' + n + '_position_x').val(Math.round(d.w / 2));
					$('#field_' + n + '_position_y').val(Math.round(d.h / 2));
					positionHandle(n);
					setActiveRow(n);
				});

				$('#cg-click-mode-cancel').on('click', cancelClickMode);

				$('#cg-zoom-slider').on('input change', function () {
					zoomPct = parseInt($(this).val(), 10);
					$('#cg-zoom-label').text(zoomPct + '%');
					updateCanvasSize();
					repositionAll();
				});

				$('#cg-grid-toggle').on('change', drawGrid);

				// Font search datalist: keep the hidden font_style value in sync with the typed label.
				$('#font_style_search').on('input change', function () {
					var key = FONT_MAP[this.value];
					if ( key ) { $('#font_style').val( key ); }
				});

			}); // end $(document).ready

		}(jQuery));
		</script>
		<?php
	}
}
