<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CG_Bulk_Serial_Generator {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		// Submenu registration moved to the centralized, ordered menu block in
		// certificate-generator.php (grouped with the other Bulk Operations
		// pages) instead of its own admin_menu hook, so it stops landing at
		// the very end of the submenu regardless of where it logically belongs.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cg_bulk_generate_serials', array( $this, 'ajax_bulk_generate' ) );
		add_action( 'wp_ajax_cg_bulk_generate_status', array( $this, 'ajax_get_status' ) );
	}

	public function add_bulk_serial_menu() {
		add_submenu_page(
			'cg-dashboard',
			'Bulk Serial Numbers',
			'Bulk Serials',
			'manage_options',
			'cg-bulk-serials',
			array( $this, 'render_bulk_serial_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'cg-bulk-serials' ) === false ) {
			return;
		}

		wp_enqueue_script( 'cg-bulk-js', CERTIFICATE_GENERATOR_URL . 'assets/js/bulk-serial-script.js', array( 'cg-ui' ), (string) filemtime( CERTIFICATE_GENERATOR_PATH . 'assets/js/bulk-serial-script.js' ), true );

		wp_localize_script(
			'cg-bulk-js',
			'cgBulkSerial',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cg_bulk_serial_nonce' ),
			)
		);
	}

	/**
	 * Get SQL entity table name and check it exists.
	 *
	 * @param string $entity students|teachers|schools
	 * @return string|null Table name or null when unavailable.
	 */
	private function get_entity_table( string $entity ): ?string {
		if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			return null;
		}
		global $wpdb;
		$tbl = \CertificateGenerator\Database\CustomTables::instance()->get_table( $entity );
		if ( ! $tbl || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
			return null;
		}
		return $tbl;
	}

	/**
	 * Aggregate serial stats across cg_students / cg_teachers / cg_schools.
	 *
	 * @return array{ total_with_serial: int, total_without_serial: int, total_posts: int }
	 */
	private function get_serial_stats(): array {
		global $wpdb;
		$stats = array(
			'total_with_serial'    => 0,
			'total_without_serial' => 0,
			'total_posts'          => 0,
		);

		foreach ( array( 'students', 'teachers', 'schools' ) as $entity ) {
			$tbl = $this->get_entity_table( $entity );
			if ( ! $tbl ) {
				continue;
			}
			$with                           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl WHERE serial_number IS NOT NULL AND serial_number != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$without                        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tbl WHERE serial_number IS NULL OR serial_number = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$stats['total_with_serial']    += $with;
			$stats['total_without_serial'] += $without;
			$stats['total_posts']          += $with + $without;
		}

		return $stats;
	}

	/**
	 * Entity rows whose serial_number is shared with another row (read-only report).
	 *
	 * Each row also carries `verifies_as`: the name the public verify page shows for
	 * that serial (verify() returns the first certificate_generator match).
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_duplicate_serials(): array {
		global $wpdb;

		$parts = array();
		foreach ( array( 'students' => 'student_name', 'teachers' => 'teacher_name', 'schools' => 'school_name' ) as $entity => $name_col ) {
			$tbl = $this->get_entity_table( $entity );
			if ( $tbl ) {
				$parts[] = "SELECT '$entity' AS entity, id, $name_col AS name, certificate_type, issue_date, serial_number FROM $tbl WHERE serial_number IS NOT NULL AND serial_number <> ''";
			}
		}
		if ( ! $parts ) {
			return array();
		}

		$union = implode( ' UNION ALL ', $parts );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT u.* FROM ( $union ) u
			JOIN ( SELECT serial_number FROM ( $union ) x GROUP BY serial_number HAVING COUNT(*) > 1 ) d USING ( serial_number )
			ORDER BY u.serial_number, u.entity, u.id LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$cert_table = $wpdb->prefix . 'certificate_generator';
		$verifies   = array();
		foreach ( $rows as &$row ) {
			$serial = $row['serial_number'];
			if ( ! array_key_exists( $serial, $verifies ) ) {
				$verifies[ $serial ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT student_name FROM $cert_table WHERE serial_number = %s LIMIT 1", $serial ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$row['verifies_as'] = $verifies[ $serial ];
		}
		unset( $row );

		return $rows;
	}

	public function render_bulk_serial_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;

		$stats      = $this->get_serial_stats();
		$duplicates = $this->get_duplicate_serials();

		// Recent serials from wp_cg_certificates (recipient_name column).
		$recent_serials = array();
		if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			$cert_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificates' );
			if ( $cert_table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cert_table ) ) === $cert_table ) {
				$sql            = "SELECT id, recipient_name, serial_number, certificate_type, generated_via, issued_at FROM $cert_table WHERE serial_number IS NOT NULL AND serial_number != '' ORDER BY id DESC LIMIT 50"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$recent_serials = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			}
		}
		?>
		<div class="wrap cg-bulk-serial-page">
			<?php cg_ui_page_header( 'Bulk Serial Numbers', 'Give every certificate record that has no serial number a unique one. Records that already have a serial are left alone.' ); ?>

			<div class="cg-stats">
				<?php
				cg_ui_stat( 'Total Certificate Records', $stats['total_posts'] );
				cg_ui_stat( 'With Serial Number', $stats['total_with_serial'], '', 'good' );
				cg_ui_stat( 'Missing Serial Number', $stats['total_without_serial'], '', $stats['total_without_serial'] ? 'warn' : '' );
				?>
			</div>

			<?php cg_ui_card_open( 'Generate Serial Numbers', array( 'icon' => 'tag' ) ); ?>
				<div class="cg-actions">
					<label><input type="checkbox" id="cg-bulk-students" checked> Include Students</label>
					<label><input type="checkbox" id="cg-bulk-teachers" checked> Include Teachers</label>
					<label><input type="checkbox" id="cg-bulk-schools" checked> Include Schools</label>
				</div>
				<p class="submit">
					<button type="button" id="cg-bulk-generate-btn" class="button button-primary">Generate Serial Numbers</button>
				</p>
				<?php cg_ui_progress( 'cg-bulk-progress' ); ?>
			<?php cg_ui_card_close(); ?>

			<?php cg_ui_card_open( 'Recently Generated Serial Numbers', array( 'icon' => 'list-view' ) ); ?>
				<div class="cg-table-wrap">
				<table class="wp-list-table widefat fixed striped cg-table">
					<thead>
						<tr>
							<th class="num">ID</th>
							<th>Name</th>
							<th>Certificate Type</th>
							<th>Serial Number</th>
							<th>Generated Via</th>
							<th>Issued At</th>
						</tr>
					</thead>
					<tbody id="cg-bulk-recent-serials">
						<?php if ( empty( $recent_serials ) ) : ?>
							<?php echo cg_ui_empty_row( 6, 'No serial numbers yet. Generate certificates, or run bulk generation above.' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
						<?php else : ?>
							<?php foreach ( $recent_serials as $row ) : ?>
								<tr>
									<td class="num"><?php echo esc_html( $row->id ); ?></td>
									<td><?php echo esc_html( $row->recipient_name ); ?></td>
									<td><?php echo esc_html( ! empty( $row->certificate_type ) ? $row->certificate_type : '—' ); ?></td>
									<td><code><?php echo esc_html( $row->serial_number ); ?></code></td>
									<td><?php echo esc_html( ucfirst( ! empty( $row->generated_via ) ? $row->generated_via : 'manual' ) ); ?></td>
									<td><?php echo esc_html( cg_format_date( $row->issued_at, true ) ? cg_format_date( $row->issued_at, true ) : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
				</div>
			<?php cg_ui_card_close(); ?>

			<?php cg_ui_card_open( 'Duplicate Serial Numbers', array( 'icon' => 'warning' ) ); ?>
				<?php if ( empty( $duplicates ) ) : ?>
					<?php echo cg_ui_empty( 'No duplicate serial numbers. Every serial points to exactly one record.', '', '', 'yes-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
				<?php else : ?>
					<p>These serials are shared by more than one record, so the public verify page shows only one holder for each. Nothing here is changed automatically. Review each case; revoke from the Revoke Certificate tool if needed.</p>
					<div class="cg-table-wrap">
					<table class="wp-list-table widefat fixed striped cg-table">
						<thead>
							<tr>
								<th>Serial Number</th>
								<th>Type</th>
								<th class="num">ID</th>
								<th>Name</th>
								<th>Certificate Type</th>
								<th>Issue Date</th>
								<th>Verify Page Shows</th>
								<th><span class="screen-reader-text">Actions</span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $duplicates as $dup ) : ?>
								<tr>
									<td><code><?php echo esc_html( $dup['serial_number'] ); ?></code></td>
									<td><?php echo esc_html( ucfirst( $dup['entity'] ) ); ?></td>
									<td class="num"><?php echo esc_html( $dup['id'] ); ?></td>
									<td><?php echo esc_html( $dup['name'] ); ?></td>
									<td><?php echo esc_html( $dup['certificate_type'] ? $dup['certificate_type'] : '—' ); ?></td>
									<td><?php echo esc_html( $dup['issue_date'] ? $dup['issue_date'] : '—' ); ?></td>
									<td><?php echo esc_html( '' !== $dup['verifies_as'] ? $dup['verifies_as'] : '(no record)' ); ?></td>
									<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=cg-revoke-certificate&serial=' . rawurlencode( $dup['serial_number'] ) ) ); ?>">Revoke…</a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					</div>
				<?php endif; ?>
			<?php cg_ui_card_close(); ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler: generate serial numbers for all entity rows missing one.
	 *
	 * Reads entity data from wp_cg_students/teachers/schools SQL tables.
	 * Nonce: cg_bulk_serial_nonce.
	 *
	 * @return void Outputs JSON and exits.
	 */
	public function ajax_bulk_generate() {
		check_ajax_referer( 'cg_bulk_serial_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$post_types = array();
		if ( ! empty( $_POST['students'] ) ) {
			$post_types[] = 'students';
		}
		if ( ! empty( $_POST['teachers'] ) ) {
			$post_types[] = 'teachers';
		}
		if ( ! empty( $_POST['schools'] ) ) {
			$post_types[] = 'schools';
		}

		if ( empty( $post_types ) ) {
			wp_send_json_error( 'No post types selected' );
		}

		global $wpdb;

		$serial_gen = null;
		if ( class_exists( 'CG_Serial_Number_Generator' ) ) {
			$serial_gen = CG_Serial_Number_Generator::get_instance();
		}

		// Generation results accumulator.
		/** @var array{ success: int, skipped: int, errors: int, details: array<int, array<string,mixed>> } */
		$results = array(
			'success' => 0,
			'skipped' => 0,
			'errors'  => 0,
			'details' => array(),
		);

		// Name column per entity type.
		$name_cols = array(
			'students' => 'student_name',
			'teachers' => 'teacher_name',
			'schools'  => 'school_name',
		);

		foreach ( $post_types as $post_type ) {
			$tbl = $this->get_entity_table( $post_type );
			if ( ! $tbl ) {
				++$results['errors'];
				continue;
			}

			$name_col = $name_cols[ $post_type ];

			// Fetch only rows missing a serial number.
			$sql  = "SELECT id, wp_post_id, $name_col AS entity_name, email, certificate_type, issue_date FROM $tbl WHERE serial_number IS NULL OR serial_number = ''"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( $rows as $row ) {
				$entity_id  = (int) $row['id'];
				$name       = sanitize_text_field( $row['entity_name'] ?? '' );
				$cert_type  = sanitize_text_field( $row['certificate_type'] ?? '' );
				$email      = sanitize_email( $row['email'] ?? '' );
				$wp_post_id = (int) ( $row['wp_post_id'] ?? 0 );

				// Check if this name+type already has a serial in entity table.
				$serial = '';
				if ( function_exists( 'cg_find_existing_serial' ) && $name && $cert_type ) {
					$serial = cg_find_existing_serial( $name, $cert_type, (string) ( $row['issue_date'] ?? '' ) );
				}

				// The row is updated by id below, so no student_data for generate().
				if ( empty( $serial ) && $serial_gen ) {
					$serial = $serial_gen->generate( $cert_type );
				}

				if ( empty( $serial ) ) {
					++$results['errors'];
					continue;
				}

				// Write serial to the entity SQL row directly (covers wp_post_id=0 rows too).
				// issue_date is the event date used for template matching: only fill it when empty.
				$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"UPDATE $tbl SET serial_number = %s, issue_date = COALESCE( issue_date, %s ) WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$serial,
						current_time( 'Y-m-d' ),
						$entity_id
					)
				);

				// Persist to wp_cg_certificates (and legacy table via cg_insert_certificate_record).
				if ( function_exists( 'cg_insert_certificate_record' ) ) {
					cg_insert_certificate_record(
						array(
							$name_col          => $name,
							'email'            => $email,
							'certificate_type' => $cert_type,
							'recipient_type'   => $post_type,
							'wp_post_id'       => $wp_post_id,
							'issue_date'       => (string) ( $row['issue_date'] ?? '' ),
						),
						$serial,
						'bulk'
					);
				}

				++$results['success'];
				$results['details'][] = array(
					'entity_id' => $entity_id,
					'post_type' => $post_type,
					'name'      => $name,
					'serial'    => $serial,
					'issued_at' => current_time( 'mysql' ),
				);
			}
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX handler: return current with/without serial counts from SQL entity tables.
	 *
	 * Nonce: cg_bulk_serial_nonce.
	 *
	 * @return void Outputs JSON and exits.
	 */
	public function ajax_get_status() {
		check_ajax_referer( 'cg_bulk_serial_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$stats = $this->get_serial_stats();

		wp_send_json_success(
			array(
				'total_without' => $stats['total_without_serial'],
				'total_with'    => $stats['total_with_serial'],
			)
		);
	}
}
