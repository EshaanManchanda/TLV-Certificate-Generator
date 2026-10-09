<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CG_Analytics_Dashboard {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_cg_get_analytics_data', array( $this, 'ajax_get_analytics_data' ) );
		add_action( 'wp_ajax_cg_export_expiration_report', array( $this, 'export_expiration_report' ) );
		add_action( 'wp_ajax_cg_export_monthly_report', array( $this, 'export_monthly_report' ) );
	}

	public function add_analytics_menu() {
		add_submenu_page(
			'cg-dashboard',
			'Certificate Analytics',
			'Analytics',
			'manage_options',
			'cg-analytics',
			array( $this, 'render_analytics_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'cg-analytics' ) === false ) {
			return;
		}

		wp_enqueue_script( 'chart-js', CERTIFICATE_GENERATOR_URL . 'assets/vendor/chart.js/chart.umd.min.js', array(), '4.4.0', true );
		wp_enqueue_script( 'cg-analytics-js', CERTIFICATE_GENERATOR_URL . 'assets/js/analytics-script.js', array( 'jquery', 'chart-js' ), '1.0.0', true );

		wp_localize_script(
			'cg-analytics-js',
			'cgAnalytics',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cg_analytics_nonce' ),
				'apiUrl'  => rest_url( 'certificate-generator/v1/analytics' ),
			)
		);
	}

	public function render_analytics_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$stats = $this->get_overview_stats();
		?>
		<div class="wrap cg-analytics-page">
			<?php
			cg_ui_page_header(
				'Certificate Analytics',
				'How many certificates have been issued, how they were generated, and which are about to expire.',
				'<a href="' . esc_url( admin_url( 'admin-ajax.php?action=cg_export_expiration_report&nonce=' . wp_create_nonce( 'cg_export_nonce' ) ) ) . '" class="button button-primary">Export Expiration Report (CSV)</a>'
				. '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=cg_export_monthly_report&nonce=' . wp_create_nonce( 'cg_export_nonce' ) ) ) . '" class="button">Export Monthly Report (CSV)</a>'
			);
			?>

			<div class="cg-stats">
				<?php
				cg_ui_stat( 'Total Certificates', $stats['total_certificates'] );
				cg_ui_stat( 'Issued This Month', $stats['issued_this_month'] );
				cg_ui_stat( 'With Serial Number', $stats['with_serial'] );
				cg_ui_stat( 'Expiring in 30 Days', $stats['expiring_soon'], '', $stats['expiring_soon'] ? 'warn' : '' );
				cg_ui_stat( 'Expired', $stats['expired'], '', $stats['expired'] ? 'bad' : '' );
				cg_ui_stat( 'Avg Per Day (30d)', (string) $stats['avg_per_day'] );
				?>
			</div>

			<?php if ( ! $stats['total_certificates'] ) : ?>
				<?php cg_ui_card_open(); ?>
				<?php echo cg_ui_empty( 'No certificates issued yet. Charts appear here once certificates are generated or sent.', admin_url( 'admin.php?page=certificate-bulk-send' ), 'Go to Bulk Send', 'chart-bar' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
				<?php cg_ui_card_close(); ?>
			<?php else : ?>
			<div class="cg-grid cg-charts">
				<?php
				foreach ( array(
					'cg-issuance-chart'   => 'Certificates Issued Over Time',
					'cg-type-chart'       => 'Certificates by Type',
					'cg-method-chart'     => 'Generation Method Distribution',
					'cg-expiration-chart' => 'Expiration Status',
				) as $chart_id => $chart_title ) {
					cg_ui_card_open( $chart_title, array( 'icon' => 'chart-bar' ) );
					echo '<canvas id="' . esc_attr( $chart_id ) . '"></canvas>';
					cg_ui_card_close();
				}
				?>
			</div>
			<?php endif; ?>
		</div>

		<script type="application/json" id="cg-analytics-data">
			<?php echo json_encode( $this->get_chart_data() ); ?>
		</script>
		<?php
	}

	public function get_overview_stats() {
		global $wpdb;
		$table_name   = $wpdb->prefix . 'certificate_generator';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( ! $table_exists ) {
			return array(
				'total_certificates' => 0,
				'issued_this_month'  => 0,
				'with_serial'        => 0,
				'expiring_soon'      => 0,
				'expired'            => 0,
				'avg_per_day'        => 0,
			);
		}

		$total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

		$issued_this_month = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE issued_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

		$with_serial = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE serial_number IS NOT NULL AND serial_number != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$expiring_soon = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE expires_at IS NOT NULL AND expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 30 DAY)",
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$expired = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE expires_at IS NOT NULL AND expires_at < %s",
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$last_30_days = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table_name WHERE issued_at >= DATE_SUB(%s, INTERVAL 30 DAY)",
				current_time( 'mysql' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$avg_per_day = round( $last_30_days / 30, 1 );

		return array(
			'total_certificates' => (int) $total,
			'issued_this_month'  => (int) $issued_this_month,
			'with_serial'        => (int) $with_serial,
			'expiring_soon'      => (int) $expiring_soon,
			'expired'            => (int) $expired,
			'avg_per_day'        => $avg_per_day,
		);
	}

	public function get_chart_data() {
		global $wpdb;
		$table_name   = $wpdb->prefix . 'certificate_generator';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( ! $table_exists ) {
			return array(
				'issuance_over_time' => array(),
				'by_type'            => array(),
				'by_method'          => array(),
				'expiration_status'  => array(),
			);
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$issuance_over_time = $wpdb->get_results(
			"SELECT DATE(issued_at) as date, COUNT(*) as count 
             FROM $table_name 
             WHERE issued_at IS NOT NULL 
             GROUP BY DATE(issued_at) 
             ORDER BY date DESC 
             LIMIT 90",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$by_type = $wpdb->get_results(
			"SELECT certificate_type, COUNT(*) as count
             FROM $table_name
             WHERE certificate_type IS NOT NULL AND certificate_type != ''
             GROUP BY certificate_type
             ORDER BY count DESC
             LIMIT 10",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$by_method = $wpdb->get_results(
			"SELECT generated_via, COUNT(*) as count 
             FROM $table_name 
             WHERE generated_via IS NOT NULL 
             GROUP BY generated_via",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$expiration_status = array(
			array(
				'status' => 'Valid',
				'count'  => (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $table_name WHERE expires_at IS NULL OR expires_at > %s",
						current_time( 'mysql' )
					)
				),
			),
			array(
				'status' => 'Expiring Soon (30d)',
				'count'  => (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $table_name WHERE expires_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 30 DAY)",
						current_time( 'mysql' ),
						current_time( 'mysql' )
					)
				),
			),
			array(
				'status' => 'Expired',
				'count'  => (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM $table_name WHERE expires_at IS NOT NULL AND expires_at < %s",
						current_time( 'mysql' )
					)
				),
			),
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'issuance_over_time' => $issuance_over_time,
			'by_type'            => $by_type,
			'by_method'          => $by_method,
			'expiration_status'  => $expiration_status,
		);
	}

	public function ajax_get_analytics_data() {
		check_ajax_referer( 'cg_analytics_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		wp_send_json_success( $this->get_chart_data() );
	}

	public function export_expiration_report() {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'cg_export_nonce' ) ) {
			wp_die( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$table_name   = $wpdb->prefix . 'certificate_generator';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( ! $table_exists ) {
			wp_die( esc_html__( 'No certificate data available to export.', 'certificate-generator' ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$results = $wpdb->get_results(
			"SELECT id, student_name, serial_number, issued_at, expires_at, created_at
             FROM $table_name
             ORDER BY expires_at IS NULL, expires_at ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="certificate_expiration_report_' . gmdate( 'd-m-Y' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'ID', 'Student Name', 'Serial Number', 'Issued At', 'Expires At', 'Status', 'Created At' ) );

		foreach ( $results as $row ) {
			if ( empty( $row['expires_at'] ) ) {
				$status = 'No Expiration';
			} else {
				$expires_ts = strtotime( $row['expires_at'] );
				if ( $expires_ts < time() ) {
					$status = 'Expired';
				} elseif ( $expires_ts <= strtotime( '+30 days' ) ) {
					$status = 'Expiring Soon (30d)';
				} else {
					$status = 'Valid';
				}
			}
			fputcsv(
				$output,
				array(
					$row['id'],
					$row['student_name'],
					$row['serial_number'] ?? 'N/A',
					cg_format_date( $row['issued_at'] ?? '' ),
					$row['expires_at'] ? cg_format_date( $row['expires_at'] ) : 'N/A',
					$status,
					cg_format_date( $row['created_at'] ?? '' ),
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes a php://output / php://temp stream
		exit;
	}

	public function export_monthly_report() {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'cg_export_nonce' ) ) {
			wp_die( 'Invalid nonce' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$table_name   = $wpdb->prefix . 'certificate_generator';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( ! $table_exists ) {
			wp_die( esc_html__( 'No certificate data available to export.', 'certificate-generator' ) );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$results = $wpdb->get_results(
			"SELECT DATE_FORMAT(issued_at, '%m-%Y') as month, COUNT(*) as count
             FROM $table_name
             WHERE issued_at IS NOT NULL
             GROUP BY DATE_FORMAT(issued_at, '%Y-%m')
             ORDER BY DATE_FORMAT(issued_at, '%Y-%m') DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		header( 'Content-Type: text/csv' );
		header( 'Content-Disposition: attachment; filename="certificate_monthly_report_' . gmdate( 'd-m-Y' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'Month', 'Certificates Issued' ) );

		foreach ( $results as $row ) {
			fputcsv( $output, array( $row['month'], $row['count'] ) );
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes a php://output / php://temp stream
		exit;
	}
}