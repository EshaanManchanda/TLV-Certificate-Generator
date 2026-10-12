<?php
/**
 * Who may do what, and the School Coordinator role.
 *
 * Capabilities:
 *   certificate_generator_view             see students / teachers and the docs
 *   certificate_generator_issue            edit and import records, generate, download and email certificates
 *   certificate_generator_manage_templates templates, previews, test certificates
 *   certificate_generator_manage_settings  everything else: settings, revoke, analytics, logs, schools, events, …
 *
 * Anyone who can manage_options has all four (user_has_cap below), so admins need no stored
 * capabilities and nothing changes for existing sites. Role editors can grant them to other roles.
 *
 * A user who can view but not manage settings is limited to one school: the
 * certificate_generator_school user meta (a school name, as on student and teacher rows), set
 * by an admin on the user's profile. Every query and record action such a user can reach goes
 * through certificate_generator_school_scope_sql() / certificate_generator_record_in_scope().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CERTIFICATE_GENERATOR_CAPS = array(
	'certificate_generator_view',
	'certificate_generator_issue',
	'certificate_generator_manage_templates',
	'certificate_generator_manage_settings',
);

const CERTIFICATE_GENERATOR_COORDINATOR_ROLE = 'certificate_generator_school_coordinator';

add_filter(
	'user_has_cap',
	static function ( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( CERTIFICATE_GENERATOR_CAPS as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
		return $allcaps;
	}
);

// Activation doesn't run on plugin updates, so the role is created the first time it's missing.
// view_admin_dashboard: plugins that keep non-editors out of wp-admin (WooCommerce) let this role in.
add_action(
	'init',
	static function (): void {
		$role = get_role( CERTIFICATE_GENERATOR_COORDINATOR_ROLE );
		if ( ! $role ) {
			add_role(
				CERTIFICATE_GENERATOR_COORDINATOR_ROLE,
				__( 'School Coordinator', 'certificate-generator' ),
				array(
					'read'                        => true,
					'view_admin_dashboard'        => true,
					'certificate_generator_view'  => true,
					'certificate_generator_issue' => true,
				)
			);
		} elseif ( ! $role->has_cap( 'view_admin_dashboard' ) ) {
			$role->add_cap( 'view_admin_dashboard' ); // roles created before this cap was added
		}
	}
);

// The Dashboard shows site-wide numbers; School Coordinators start on Students instead.
add_action(
	'load-toplevel_page_cg-dashboard',
	static function (): void {
		if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=cg-students' ) );
			exit;
		}
	}
);

/**
 * The school the current user is limited to: null when not limited (admins, anyone without
 * certificate_generator_view — e.g. visitors, cron), '' when limited but no school is assigned
 * (they then see nothing).
 */
function certificate_generator_user_school(): ?string {
	if ( ! current_user_can( 'certificate_generator_view' ) || current_user_can( 'certificate_generator_manage_settings' ) ) {
		return null;
	}
	return trim( (string) get_user_meta( get_current_user_id(), 'certificate_generator_school', true ) );
}

/**
 * SQL condition limiting rows to the current user's school.
 *
 * @param string $column Column holding the school name, with its table alias if any (e.g. 'e.school_name').
 * @return array{0:string,1:array} ['', []] when the user isn't limited.
 */
function certificate_generator_school_scope_sql( string $column = 'school_name' ): array {
	$school = certificate_generator_user_school();
	if ( null === $school ) {
		return array( '', array() );
	}
	if ( '' === $school ) {
		return array( '1=0', array() );
	}
	return array( "$column = %s", array( $school ) );
}

/** True when the current user may act on this student / teacher / school row. */
function certificate_generator_record_in_scope( string $entity, int $id ): bool {
	if ( null === certificate_generator_user_school() ) {
		return true;
	}
	if ( ! in_array( $entity, array( 'students', 'teachers', 'schools' ), true ) || ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return false;
	}
	global $wpdb;
	$table              = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
	[ $scope, $params ] = certificate_generator_school_scope_sql();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders -- table name from $wpdb->prefix; $scope (with its %s) is built above
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM $table WHERE id = %d AND $scope", $id, ...$params ) );
}

/** School names an admin can assign: every school on a student, teacher or school row. */
function certificate_generator_assignable_schools(): array {
	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return array();
	}
	global $wpdb;
	$tables = \CertificateGenerator\Database\CustomTables::instance();
	$names  = array();
	foreach ( array( 'schools', 'students', 'teachers' ) as $entity ) {
		$table = $tables->get_table( $entity );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
		$names = array_merge( $names, (array) $wpdb->get_col( "SELECT DISTINCT school_name FROM $table WHERE school_name IS NOT NULL AND school_name != ''" ) );
	}
	$names = array_values( array_unique( array_map( 'trim', $names ) ) );
	natcasesort( $names );
	return array_values( $names );
}

// ── User profile: "Certificate Generator school" ────────────────────────────

/** Shown to admins on Users → Edit user (and on their own profile). */
function certificate_generator_render_user_school_field( WP_User $user ): void {
	if ( ! current_user_can( 'certificate_generator_manage_settings' ) ) {
		return;
	}
	$current = (string) get_user_meta( $user->ID, 'certificate_generator_school', true );
	$schools = certificate_generator_assignable_schools();
	if ( '' !== $current && ! in_array( $current, $schools, true ) ) {
		$schools[] = $current;
	}
	?>
	<h2><?php esc_html_e( 'Certificate Generator', 'certificate-generator' ); ?></h2>
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="certificate_generator_school"><?php esc_html_e( 'School', 'certificate-generator' ); ?></label></th>
			<td>
				<?php wp_nonce_field( 'certificate_generator_user_school_' . $user->ID, 'certificate_generator_user_school_nonce' ); ?>
				<select name="certificate_generator_school" id="certificate_generator_school">
					<option value=""><?php esc_html_e( '— None —', 'certificate-generator' ); ?></option>
					<?php foreach ( $schools as $school ) : ?>
						<option value="<?php echo esc_attr( $school ); ?>" <?php selected( $current, $school ); ?>><?php echo esc_html( $school ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'A School Coordinator sees and issues certificates only for this school\'s students and teachers. Admins are never limited.', 'certificate-generator' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
}
add_action( 'show_user_profile', 'certificate_generator_render_user_school_field' );
add_action( 'edit_user_profile', 'certificate_generator_render_user_school_field' );

function certificate_generator_save_user_school_field( int $user_id ): void {
	if ( ! isset( $_POST['certificate_generator_user_school_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['certificate_generator_user_school_nonce'] ) ), 'certificate_generator_user_school_' . $user_id )
		|| ! current_user_can( 'certificate_generator_manage_settings' )
		|| ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$school = sanitize_text_field( wp_unslash( $_POST['certificate_generator_school'] ?? '' ) );
	if ( '' === $school ) {
		delete_user_meta( $user_id, 'certificate_generator_school' );
	} else {
		update_user_meta( $user_id, 'certificate_generator_school', $school );
	}
}
add_action( 'personal_options_update', 'certificate_generator_save_user_school_field' );
add_action( 'edit_user_profile_update', 'certificate_generator_save_user_school_field' );
