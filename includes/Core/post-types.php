<?php
/*
Plugin Name: Custom Post Types with ACF and Meta Boxes
Description: A plugin to create custom post types, advanced custom fields, and custom meta boxes for Students, Teachers, Schools, and Certificates.
Version: 1.3
Author: Your Name
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../Services/certificate-search.php';
require_once __DIR__ . '/../Admin/columns.php';


/**
 * Sync Certificate-Generator post meta to WP Dynamic Tags on save.
 * Creates/updates tags like [cg_student_name_123], [cg_last_student_name], etc.
 * Conditional on WP_Dynamic_Tags_Table_Manager being available.
 *
 * @param int $post_id The saved post ID.
 */
function certificate_generator_sync_to_dynamic_tags( $post_id ) {
	if ( ! class_exists( 'WP_Dynamic_Tags_Table_Manager' ) ) {
		return;
	}

	// Avoid infinite loops from wp_update_post inside save_post
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ), true ) ) {
		return;
	}

	// Do not create dynamic tags for student records (user requirement).
	if ( $post_type === 'students' ) {
		return;
	}

	$manager = WP_Dynamic_Tags_Table_Manager::get_instance();

	// Determine name meta key by post type
	$name_key = ( $post_type === 'students' ) ? 'student_name' : ( ( $post_type === 'teachers' ) ? 'teacher_name' : 'school_name' );

	$fields = array(
		'cg_' . $post_type . '_name_' . $post_id  => get_post_meta( $post_id, $name_key, true ),
		'cg_' . $post_type . '_email_' . $post_id => get_post_meta( $post_id, 'email', true ),
		'cg_school_name_' . $post_id              => get_post_meta( $post_id, 'school_name', true ),
		'cg_cert_type_' . $post_id                => get_post_meta( $post_id, 'certificate_type', true ),
		'cg_issue_date_' . $post_id               => get_post_meta( $post_id, 'issue_date', true ),
	);

	// Summary / last-operation tags
	if ( $post_type === 'schools' ) {
		$fields['cg_last_school_name'] = get_post_meta( $post_id, 'school_name', true );
	}

	// Sync extra fields registered for this post's certificate type
	if ( class_exists( 'CertificateGenerator_Field_Schema' ) ) {
		$cert_type    = get_post_meta( $post_id, 'certificate_type', true );
		$extra_fields = CertificateGenerator_Field_Schema::get_extra_fields( $cert_type );
		foreach ( $extra_fields as $slug ) {
			$value = get_post_meta( $post_id, $slug, true );
			if ( $value !== '' && $value !== null ) {
				$fields[ 'cg_' . $slug . '_' . $post_id ] = $value;
			}
		}
	}

	// Resolve the "Certificates" tag group term ID so tags appear under the right group
	$cert_term        = get_term_by( 'slug', 'certificates', 'tag_groups' );
	$cert_category_id = ( $cert_term && ! is_wp_error( $cert_term ) ) ? (int) $cert_term->term_id : 0;

	foreach ( $fields as $shortcode => $value ) {
		if ( $value === '' || $value === null ) {
			continue;
		}

		$tag_name = ucwords( str_replace( array( 'cg_', '_' ), array( '', ' ' ), $shortcode ) );

		$existing = $manager->get_tag_by_shortcode( $shortcode );
		if ( $existing && ! empty( $existing->id ) ) {
			$manager->update_tag( $existing->id, array( 'content' => $value ) );
		} else {
			$manager->create_tag(
				array(
					'tag_name'    => $tag_name,
					'shortcode'   => $shortcode,
					'content'     => $value,
					'category_id' => $cert_category_id,
				)
			);
		}
	}
}

// CPT save hooks removed — certificate_generator_sync_to_dynamic_tags is now triggered from SQL admin page saves.

/**
 * Populate extra_fields JSON column in the SQL table whenever a student/teacher/school is saved.
 * Core columns (name, email, phone, school_name, certificate_type, issue_date, etc.) have their
 * own SQL columns; everything else (registered via CertificateGenerator_Field_Schema) goes to extra_fields JSON.
 */
function certificate_generator_sync_extra_fields_to_sql( int $post_id ): void {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return;
	}
	if ( ! class_exists( 'CertificateGenerator_Field_Schema' ) ) {
		return;
	}

	$post_type = get_post_type( $post_id );
	if ( ! in_array( $post_type, array( 'students', 'teachers', 'schools' ), true ) ) {
		return;
	}

	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	$table  = $tables->get_table( $post_type );
	if ( ! $table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
		return;
	}

	// Core columns that already have dedicated SQL columns — skip from JSON
	$core_keys = array(
		'student_name',
		'teacher_name',
		'school_name',
		'email',
		'phone',
		'certificate_type',
		'issue_date',
		'school_abbreviation',
		'place',
		'email_status',
		'send_email',
		'wp_post_id',
		'status',
		'created_at',
		'updated_at',
		'id',
	);

	$cert_type   = get_post_meta( $post_id, 'certificate_type', true );
	$extra_slugs = CertificateGenerator_Field_Schema::get_extra_fields( $cert_type );

	$extra = array();
	foreach ( $extra_slugs as $slug ) {
		if ( in_array( $slug, $core_keys, true ) ) {
			continue;
		}
		$value = get_post_meta( $post_id, $slug, true );
		if ( $value !== '' && $value !== null ) {
			// Strip field_ prefix for cleaner JSON keys
			$key           = preg_replace( '/^field_/', '', $slug );
			$extra[ $key ] = $value;
		}
	}

	if ( empty( $extra ) ) {
		return;
	}

	$wpdb->update(
		$table,
		array( 'extra_fields' => wp_json_encode( $extra ) ),
		array( 'wp_post_id' => $post_id ),
		array( '%s' ),
		array( '%d' )
	);
}
// CPT extra-fields sync hooks removed — SQL admin pages write extra_fields directly on save.
// Email status/logs meta boxes removed — students/teachers/schools/certificates CPTs are
// unregistered; their edit screens (and thus these meta boxes) are unreachable.

// Extra Fields Meta Box for Students
function certificate_generator_add_students_extra_fields_meta_box() {
	add_meta_box(
		'students_extra_fields_meta_box',
		'Extra Fields',
		'certificate_generator_render_students_extra_fields_form',
		'students',
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes', 'certificate_generator_add_students_extra_fields_meta_box' );

function certificate_generator_render_students_extra_fields_form( $post ) {
	$certificate_type = get_post_meta( $post->ID, 'certificate_type', true );
	if ( ! class_exists( 'CertificateGenerator_Field_Schema' ) ) {
		return;
	}
	$extra_fields = CertificateGenerator_Field_Schema::get_extra_fields( $certificate_type );
	?>
	<div class="cg-student-card" id="cg-extra-fields-card">
		<?php if ( empty( $extra_fields ) ) : ?>
			<p class="description" id="cg-no-extra-msg">
				<?php
				echo $certificate_type
					? esc_html( "No extra fields registered for \"{$certificate_type}\" yet." )
					: 'Set a Certificate Type in the Student Details box above, then add fields here.';
				?>
			</p>
		<?php else : ?>
			<p class="description">
				Extra fields for certificate type: <strong><?php echo esc_html( $certificate_type ); ?></strong>
			</p>
		<?php endif; ?>

		<div id="cg-extra-fields-list">
			<?php
			foreach ( $extra_fields as $slug ) :
				$value = get_post_meta( $post->ID, $slug, true );
				$label = CertificateGenerator_Field_Schema::get_display_label( $slug );
				?>
				<div class="custom-form-group">
					<label for="cg_extra_<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></label>
					<input type="text"
						id="cg_extra_<?php echo esc_attr( $slug ); ?>"
						name="cg_extra_field[<?php echo esc_attr( $slug ); ?>]"
						value="<?php echo esc_attr( $value ); ?>"
						class="custom-form-input">
				</div>
			<?php endforeach; ?>
		</div>

		<!-- Inline Add-Field creator -->
		<div class="cg-add-field-section"
			data-post-id="<?php echo esc_attr( $post->ID ); ?>"
			data-cert-type="<?php echo esc_attr( $certificate_type ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'cg_add_extra_field' ) ); ?>">
			<strong>Add New Field</strong>
			<div class="cg-add-field-row" style="margin-top:8px;">
				<input type="text" id="cg_new_field_slug" placeholder="e.g. team_name"
					class="custom-form-input" style="max-width:200px;">
				<button type="button" id="cg_add_field_btn" class="button button-secondary">
					+ Add Field
				</button>
			</div>
			<p class="description" style="margin-top:4px;">
				Lowercase letters and underscores only. The new field will be saved to the schema for "<strong><?php echo esc_html( $certificate_type ?: 'this certificate type' ); ?></strong>".
			</p>
			<div class="cg-add-field-status"></div>
		</div>
	</div>
	<?php
}

// Students/Teachers/Schools meta-box render + save-handler functions (and the
// add_extra_field/school_autocomplete AJAX actions they used) removed — the CPTs
// are unregistered, so their edit screens are unreachable and these could never run.

// Include FontManager class
require_once plugin_dir_path( __FILE__ ) . 'font-manager.php';

// Add Meta Box for Certificates Post Type
function certificate_generator_add_certificates_meta_box() {
	add_meta_box(
		'certificates_meta_box',
		'Certificate Details',
		'render_certificates_form',
		'certificates',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'certificate_generator_add_certificates_meta_box' );

// Save Certificates Data
function certificate_generator_save_certificates_data( $post_id ) {
	// Check if data is being saved correctly
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['cg_certificate_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cg_certificate_nonce'] ) ), 'cg_save_certificate' ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== 'certificates' ) {
		return;
	}

	if ( isset( $_POST['certificate_type'] ) ) {
		update_post_meta( $post_id, 'certificate_type', sanitize_text_field( wp_unslash( $_POST['certificate_type'] ) ) );
	}

	if ( isset( $_POST['event_date'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_POST['event_date'] ) );
		if ( $raw === '' ) {
			update_post_meta( $post_id, 'event_date', '' );
		} else {
			// Use DateHelper to parse and normalize any input format to Y-m-d
			$stored = class_exists( '\CertificateGenerator\Helpers\DateHelper' )
				? \CertificateGenerator\Helpers\DateHelper::to_storage( $raw )
				: null;
			if ( $stored !== null ) {
				update_post_meta( $post_id, 'event_date', $stored );
			} else {
				set_transient( 'certificate_generator_event_date_invalid_' . $post_id, 1, 30 );
			}
		}
	}
	// Invalidate duplicate-template warning cache when any certificate template is saved
	delete_transient( 'certificate_generator_duplicate_template_warning' );

	if ( isset( $_POST['template_url'] ) ) {
		update_post_meta( $post_id, 'template_url', esc_url_raw( wp_unslash( $_POST['template_url'] ) ) );
	}
	if ( isset( $_POST['template_orientation'] ) ) {
		update_post_meta( $post_id, 'template_orientation', sanitize_text_field( wp_unslash( $_POST['template_orientation'] ) ) );
	}

	if ( isset( $_POST['font_size'] ) ) {
		update_post_meta( $post_id, 'font_size', intval( $_POST['font_size'] ) );
	}

	if ( isset( $_POST['font_color'] ) ) {
		update_post_meta( $post_id, 'font_color', sanitize_hex_color( wp_unslash( $_POST['font_color'] ) ) );
	}

	if ( isset( $_POST['font_style'] ) ) {
		update_post_meta( $post_id, 'font_style', sanitize_text_field( wp_unslash( $_POST['font_style'] ) ) );
	}

	// Save number-of-fields stepper
	if ( isset( $_POST['template_field_count'] ) ) {
		$field_count = max( 2, min( CertificateGenerator_Field_Schema::MAX_FIELDS, intval( $_POST['template_field_count'] ) ) );
		update_post_meta( $post_id, 'template_field_count', $field_count );
	}

	for ( $i = 1; $i <= CertificateGenerator_Field_Schema::MAX_FIELDS; $i++ ) {
		$x_input = sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_position_x" ] ?? '' ) );
		$y_input = sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_position_y" ] ?? '' ) );
		$x_val   = $x_input !== '' ? sanitize_text_field( $x_input ) : strval( 105 );
		$y_val   = $y_input !== '' ? sanitize_text_field( $y_input ) : strval( 60 + ( $i * 25 ) );
		update_post_meta( $post_id, "field_{$i}_position_x", $x_val );
		update_post_meta( $post_id, "field_{$i}_position_y", $y_val );

		$visibility = isset( $_POST[ "field_{$i}_visible" ] ) ? '1' : '0';
		update_post_meta( $post_id, "field_{$i}_visible", $visibility );

		if ( isset( $_POST[ "field_{$i}_width" ] ) ) {
			update_post_meta( $post_id, "field_{$i}_width", intval( $_POST[ "field_{$i}_width" ] ) );
		}

		if ( isset( $_POST[ "field_{$i}_alignment" ] ) ) {
			update_post_meta( $post_id, "field_{$i}_alignment", sanitize_text_field( wp_unslash( $_POST[ "field_{$i}_alignment" ] ) ) );
		}
	}

	// Auto-generate the title for the certificate
	$certificate_type = get_post_meta( $post_id, 'certificate_type', true );
	$template_url     = get_post_meta( $post_id, 'template_url', true );
	$width            = get_post_meta( $post_id, 'width', true );

	if ( $certificate_type || $template_url ) {
		$event_date  = get_post_meta( $post_id, 'event_date', true );
		$date_suffix = $event_date ? ' (' . $event_date . ')' : '';
		$host        = $template_url ? wp_parse_url( $template_url, PHP_URL_HOST ) : 'No Template URL';
		$new_title   = ( $certificate_type ?: 'Certificate' ) . $date_suffix . ' - ' . ( $host ?: 'No Template URL' );
		$new_slug    = sanitize_title( $new_title );

		// Prevent infinite loop by removing and re-adding the save action
		remove_action( 'save_post', 'certificate_generator_save_certificates_data' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $new_title,
				'post_name'  => $new_slug,
			)
		);
		add_action( 'save_post', 'certificate_generator_save_certificates_data' );
	}
}
add_action( 'save_post', 'certificate_generator_save_certificates_data' );

/**
 * Admin notice: invalid event_date format.
 */
function certificate_generator_certificates_event_date_invalid_notice() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->post_type !== 'certificates' || $screen->base !== 'post' ) {
		return;
	}
	global $post;
	if ( ! $post ) {
		return;
	}
	if ( get_transient( 'certificate_generator_event_date_invalid_' . $post->ID ) ) {
		delete_transient( 'certificate_generator_event_date_invalid_' . $post->ID );
		echo '<div class="notice notice-error is-dismissible"><p><strong>Certificate Generator:</strong> Invalid Event Date format. Please use the date picker or enter a date in YYYY-MM-DD format.</p></div>';
	}
}
add_action( 'admin_notices', 'certificate_generator_certificates_event_date_invalid_notice' );

/**
 * Admin notice: multiple templates share the same certificate_type but none have event_date.
 * Only shown on the certificates list screen. Cached for 5 minutes.
 */
function certificate_generator_certificates_duplicate_type_warning() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->id !== 'edit-certificates' ) {
		return;
	}

	$cached = get_transient( 'certificate_generator_duplicate_template_warning' );
	if ( $cached === false ) {
		// Build map: certificate_type => [has_date, count]
		$all      = get_posts(
			array(
				'post_type'      => 'certificates',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$type_map = array();
		foreach ( $all as $id ) {
			$type = get_post_meta( $id, 'certificate_type', true );
			$date = get_post_meta( $id, 'event_date', true );
			if ( ! isset( $type_map[ $type ] ) ) {
				$type_map[ $type ] = array(
					'count'    => 0,
					'has_date' => false,
				);
			}
			++$type_map[ $type ]['count'];
			if ( $date ) {
				$type_map[ $type ]['has_date'] = true;
			}
		}
		$bad_types = array();
		foreach ( $type_map as $type => $info ) {
			if ( $info['count'] > 1 && ! $info['has_date'] ) {
				$bad_types[] = esc_html( $type );
			}
		}
		$cached = $bad_types;
		set_transient( 'certificate_generator_duplicate_template_warning', $cached, 5 * MINUTE_IN_SECONDS );
	}

	if ( ! empty( $cached ) ) {
		$list = implode( ', ', array_map( fn( $t ) => '<strong>' . $t . '</strong>', $cached ) );
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Certificate Generator:</strong> The following certificate types have multiple templates but no <em>Event Date</em> set — the system cannot reliably pick the correct template. Please add an Event Date to each template: ' . esc_html( $list ) . '.</p></div>';
	}
}
add_action( 'admin_notices', 'certificate_generator_certificates_duplicate_type_warning' );

// Preview-certificate button/handler removed — superseded by the nonce-checked
// certificate_generator_preview_template AJAX action in src/Admin/Pages/TemplatesPage.php.

// Fields Definitions
$certificate_generator_student_fields = array(
	array(
		'key'   => 'field_student_name',
		'label' => 'Student Name',
		'name'  => 'student_name',
		'type'  => 'text',
	),
	array(
		'key'   => 'field_email',
		'label' => 'Email',
		'name'  => 'email',
		'type'  => 'email',
	),
	array(
		'key'   => 'field_school_name',
		'label' => 'School Name',
		'name'  => 'school_name',
		'type'  => 'text',
	),
	array(
		'key'            => 'field_issue_date',
		'label'          => 'Issue Date',
		'name'           => 'issue_date',
		'type'           => 'date_picker',
		'display_format' => 'd-m-Y',
		'return_format'  => 'd-m-Y',
		'default_value'  => gmdate( 'd-m-Y' ),
	),
	array(
		'key'   => 'field_certificate_type',
		'label' => 'Certificate Type',
		'name'  => 'certificate_type',
		'type'  => 'text',
	),
);

$certificate_generator_teacher_fields = array(
	array(
		'key'   => 'field_teacher_name',
		'label' => 'Teacher Name',
		'name'  => 'teacher_name',
		'type'  => 'text',
	),
	array(
		'key'   => 'field_email_teacher',
		'label' => 'Email',
		'name'  => 'email',
		'type'  => 'email',
	),
	array(
		'key'   => 'field_school_name_teacher',
		'label' => 'School Name',
		'name'  => 'school_name',
		'type'  => 'text',
	),
	array(
		'key'   => 'field_school_abbreviation_teacher',
		'label' => 'School Abbreviation',
		'name'  => 'school_abbreviation',
		'type'  => 'text',
	),
	array(
		'key'            => 'field_issue_date_teacher',
		'label'          => 'Issue Date',
		'name'           => 'issue_date',
		'type'           => 'date_picker',
		'display_format' => 'd-m-Y',
		'return_format'  => 'd-m-Y',
		'default_value'  => gmdate( 'd-m-Y' ),
	),
	array(
		'key'   => 'field_certificate_type',
		'label' => 'Certificate Type',
		'name'  => 'certificate_type',
		'type'  => 'text',
	),
);

$certificate_generator_school_fields = array(
	array(
		'key'   => 'field_school_name_schools',
		'label' => 'School Name',
		'name'  => 'school_name',
		'type'  => 'text',
	),
	array(
		'key'   => 'field_school_abbreviation_schools',
		'label' => 'School Abbreviation',
		'name'  => 'school_abbreviation',
		'type'  => 'text',
	),
	array(
		'key'   => 'field_place',
		'label' => 'Place',
		'name'  => 'place',
		'type'  => 'text',
	),
	array(
		'key'            => 'field_issue_date_schools',
		'label'          => 'Issue Date',
		'name'           => 'issue_date',
		'type'           => 'date_picker',
		'display_format' => 'd-m-Y',
		'return_format'  => 'd-m-Y',
		'default_value'  => gmdate( 'd-m-Y' ),
	),
	array(
		'key'   => 'field_certificate_type',
		'label' => 'Certificate Type',
		'name'  => 'certificate_type',
		'type'  => 'text',
	),
);

// Define Fields for Certificates
$certificate_generator_certificate_fields = array(
	array(
		'key'   => 'field_certificate_type',
		'label' => 'Certificate Type',
		'name'  => 'certificate_type',
		'type'  => 'text',
	),
	array(
		'key'   => 'field_template_url',
		'label' => 'Template URL',
		'name'  => 'template_url',
		'type'  => 'url',
	),
	array(
		'key'     => 'field_template_orientation',
		'label'   => 'Template Orientation',
		'name'    => 'template_orientation',
		'type'    => 'select',
		'choices' => array(
			'landscape' => 'Landscape',
			'portrait'  => 'Portrait',
		),
	),
	array(
		'key'   => 'field_font_size',
		'label' => 'Font Size',
		'name'  => 'font_size',
		'type'  => 'number',
	),
	array(
		'key'   => 'field_font_color',
		'label' => 'Font Color',
		'name'  => 'font_color',
		'type'  => 'color_picker',
	),
	array(
		'key'     => 'field_font_style',
		'label'   => 'Font Style',
		'name'    => 'font_style',
		'type'    => 'select',
		'choices' => array(
			'Arial'           => 'Arial',
			'Helvetica'       => 'Helvetica',
			'Times New Roman' => 'Times New Roman',
			'Courier New'     => 'Courier New',
			'Verdana'         => 'Verdana',
			'Palatino'        => 'Palatino',
			'Garamond'        => 'Garamond',
		),
	),
	array(
		'key'   => 'field_field_1_position_x',
		'label' => 'Field 1 Position X',
		'name'  => 'field_1_position_x',
		'type'  => 'number',
	),
	array(
		'key'   => 'field_field_1_position_y',
		'label' => 'Field 1 Position Y',
		'name'  => 'field_1_position_y',
		'type'  => 'number',
	),
	array(
		'key'           => 'field_field_1_visible',
		'label'         => 'Field 1 Visible',
		'name'          => 'field_1_visible',
		'type'          => 'true_false',
		'default_value' => 1,
	),
	array(
		'key'   => 'field_field_2_position_x',
		'label' => 'Field 2 Position X',
		'name'  => 'field_2_position_x',
		'type'  => 'number',
	),
	array(
		'key'   => 'field_field_2_position_y',
		'label' => 'Field 2 Position Y',
		'name'  => 'field_2_position_y',
		'type'  => 'number',
	),
	array(
		'key'           => 'field_field_2_visible',
		'label'         => 'Field 2 Visible',
		'name'          => 'field_2_visible',
		'type'          => 'true_false',
		'default_value' => 1,
	),
	array(
		'key'   => 'field_field_3_position_x',
		'label' => 'Field 3 Position X',
		'name'  => 'field_3_position_x',
		'type'  => 'number',
	),
	array(
		'key'   => 'field_field_3_position_y',
		'label' => 'Field 3 Position Y',
		'name'  => 'field_3_position_y',
		'type'  => 'number',
	),
	array(
		'key'           => 'field_field_3_visible',
		'label'         => 'Field 3 Visible',
		'name'          => 'field_3_visible',
		'type'          => 'true_false',
		'default_value' => 1,
	),
);

// Add Advanced Custom Fields
function certificate_generator_add_acf_field_group( $group_key, $title, $fields, $post_type ) {
	if ( function_exists( 'acf_add_local_field_group' ) ) {
		acf_add_local_field_group(
			array(
				'key'      => $group_key,
				'title'    => $title,
				'fields'   => $fields,
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => $post_type,
						),
					),
				),
			)
		);
	}
}

function certificate_generator_add_custom_fields() {
	global $certificate_generator_student_fields, $certificate_generator_teacher_fields, $certificate_generator_school_fields, $certificate_generator_certificate_fields;
	certificate_generator_add_acf_field_group( 'group_students', 'Student Fields', $certificate_generator_student_fields, 'students' );
	certificate_generator_add_acf_field_group( 'group_teachers', 'Teacher Fields', $certificate_generator_teacher_fields, 'teachers' );
	certificate_generator_add_acf_field_group( 'group_schools', 'School Fields', $certificate_generator_school_fields, 'schools' );
	certificate_generator_add_acf_field_group( 'group_certificates', 'Certificate Settings', $certificate_generator_certificate_fields, 'certificates' );
}
add_action( 'acf/init', 'certificate_generator_add_custom_fields' );

// Add Admin Page for Data Management
// function custom_post_admin_menu() {
// add_menu_page(
// 'Custom Post Management',
// 'Post Management',
// 'manage_options',
// 'custom-post-management',
// 'certificate_generator_render_custom_post_admin_page',
// 'dashicons-admin-generic',
// 20
// );
// }
// add_action('admin_menu', 'custom_post_admin_menu');

// Enqueue WordPress media uploader on CPT edit screens
add_action( 'admin_enqueue_scripts', 'certificate_generator_enqueue_cpt_admin_scripts' );
function certificate_generator_enqueue_cpt_admin_scripts( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}
	global $post_type;
	if ( in_array( $post_type, array( 'students', 'teachers', 'schools', 'certificates' ), true ) ) {
		wp_enqueue_media();
	}
	if ( $post_type === 'certificates' ) {
		wp_enqueue_script( 'jquery-ui-draggable' );
	}
}

// Hide auto-generated title box on CPT edit screens
add_action( 'admin_head', 'certificate_generator_cpt_admin_head_styles' );
function certificate_generator_cpt_admin_head_styles() {
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->post_type, array( 'students', 'teachers', 'schools', 'certificates' ), true ) ) {
		return;
	}
	echo '<style>#titlediv{display:none!important}.cg-custom-form-wrap{padding-top:4px}</style>';
}

// Render the Admin Page
function certificate_generator_render_custom_post_admin_page() {
	echo '<div class="wrap">';
	echo '<h1>Manage Custom Post Data</h1>';
	echo '<form method="post" action="options.php">';
	settings_fields( 'custom_post_options_group' );
	do_settings_sections( 'custom-post-management' );
	submit_button();
	echo '</form>';
	echo '</div>';
}
?>