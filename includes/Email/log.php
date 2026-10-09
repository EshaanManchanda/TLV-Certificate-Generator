<?php
/**
 * Email Log Functionality for Certificate Generator
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create email log table on plugin activation
 */
function certificate_generator_create_email_log_table() {
	static $table_created = false;

	// Only run once per request
	if ( $table_created ) {
		return;
	}

	$table_created = true;
	global $wpdb;

	try {
		if ( ! $wpdb || ! is_object( $wpdb ) ) {
			throw new Exception( 'WordPress database object not available' );
		}

		$table_name   = $wpdb->prefix . 'cert_email_logs';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		if ( $table_exists === $table_name ) {
			return; // Table exists — schema migrations ran on activation, nothing to do.
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            recipient_email varchar(255) NOT NULL,
            recipient_name varchar(255) NOT NULL,
            certificate_id mediumint(9) NOT NULL,
            post_type varchar(50) NOT NULL,
            certificate_type varchar(255) NOT NULL,
            email_subject varchar(500) NOT NULL,
            email_body text NOT NULL,
            attachment_path varchar(500) DEFAULT '',
            sent_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            error_message text DEFAULT '',
            PRIMARY KEY  (id),
            KEY recipient_email (recipient_email),
            KEY certificate_id (certificate_id),
            KEY sent_at (sent_at),
            KEY status (status)
        ) $charset_collate;";

		// Enhanced upgrade.php detection for various hosting environments
		$upgrade_loaded = false;
		$possible_paths = array(
			ABSPATH . 'wp-admin/includes/upgrade.php',
			dirname( ABSPATH ) . '/wp-admin/includes/upgrade.php',
			WP_CONTENT_DIR . '/../wp-admin/includes/upgrade.php',
		);

		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) && is_readable( $path ) ) {
				try {
					require_once $path;
					$upgrade_loaded = true;
					break;
				} catch ( Exception $e ) {
					certificate_generator_debug_log( "Certificate Generator: Failed to load upgrade.php from $path for email log table - " . $e->getMessage() );
					continue;
				}
			}
		}

		// Create table using the best available method
		if ( $upgrade_loaded && function_exists( 'dbDelta' ) ) {
			$result = dbDelta( $sql );
		} else {
			// Fallback to direct SQL
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			if ( $result === false ) {
				throw new Exception( 'Failed to create email log table: ' . $wpdb->last_error );
			}
		}
	} catch ( Exception $e ) {
		certificate_generator_debug_log( 'Certificate Generator: Error during email log table creation - ' . $e->getMessage() );

		// Final fallback attempt
		try {
			global $wpdb;
			if ( $wpdb && is_object( $wpdb ) ) {
				$table_name = $wpdb->prefix . 'cert_email_logs';
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
				$wpdb->query(
					"CREATE TABLE IF NOT EXISTS $table_name (
                    id mediumint(9) NOT NULL AUTO_INCREMENT,
                    recipient_email varchar(255) NOT NULL,
                    recipient_name varchar(255) NOT NULL,
                    certificate_id mediumint(9) NOT NULL,
                    post_type varchar(50) NOT NULL,
                    certificate_type varchar(255) NOT NULL,
                    email_subject varchar(500) NOT NULL,
                    email_body text NOT NULL,
                    attachment_path varchar(500) DEFAULT '',
                    sent_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                    status varchar(20) DEFAULT 'pending' NOT NULL,
                    error_message text DEFAULT '',
                    PRIMARY KEY  (id),
                    KEY recipient_email (recipient_email),
                    KEY certificate_id (certificate_id),
                    KEY sent_at (sent_at),
                    KEY status (status)
                )"
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				// Check if post_type column exists and add it if it doesn't
				$post_type_exists = $wpdb->query( "SHOW COLUMNS FROM `$table_name` LIKE 'post_type'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
				if ( ! $post_type_exists ) {
					$wpdb->query( "ALTER TABLE `$table_name` ADD COLUMN `post_type` varchar(50) NOT NULL AFTER `certificate_id`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
				}
			}
		} catch ( Exception $fallback_error ) {
			certificate_generator_debug_log( 'Certificate Generator: Fallback email log table creation also failed - ' . $fallback_error->getMessage() );
		}
	}
}

/**
 * Log email send attempt
 *
 * @param int    $certificate_id Certificate post ID
 * @param string $recipient_email Recipient email address
 * @param string $recipient_name Recipient name
 * @param string $certificate_type Certificate type
 * @param string $email_subject Email subject
 * @param bool   $success Whether email was sent successfully
 * @param string $error_message Error message if failed
 * @return int|false Log entry ID on success, false on failure
 */
function certificate_generator_log_email( $certificate_id, $recipient_email, $recipient_name = '', $certificate_type = '', $email_subject = '', $success = true, $error_message = '' ) {
	global $wpdb;

	// Ensure the table exists and has the correct structure
	certificate_generator_create_email_log_table();

	$table_name = $wpdb->prefix . 'cert_email_logs';

	$data = array(
		'certificate_id'   => $certificate_id,
		'post_type'        => '',
		'recipient_email'  => $recipient_email,
		'recipient_name'   => $recipient_name,
		'certificate_type' => $certificate_type,
		'email_subject'    => $email_subject,
		'email_body'       => '',
		'status'           => $success ? 'sent' : 'failed',
		'error_message'    => $error_message,
	);

	// CG_USE_EVENTS: delegate write + side-effects to LogEmailListener.
	// Log seam: listeners own the row write; legacy path unchanged when flag is off.
	if ( \CertificateGenerator\Core\Config::flag( 'CG_USE_EVENTS' ) ) {
		do_action( 'certificate_generator_email_sent', $data );
		return true; // truthy; no live caller consumes the id for a follow-up write
	}

	$result = $wpdb->insert( $table_name, $data );

	if ( $result && $success ) {
		delete_transient( 'certificate_generator_sent_count_3600' );
		delete_transient( 'certificate_generator_sent_count_60' );
	}

	return $result ? $wpdb->insert_id : false;
}

/**
 * Get email logs with pagination and filtering
 *
 * @param array $args Query arguments
 * @return array Array containing logs and total count
 */
function certificate_generator_get_email_logs( $args = array() ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_logs';

	$defaults = array(
		'per_page'       => 20,
		'page'           => 1,
		'post_type'      => '',
		'status'         => '',
		'search'         => '',
		'date_from'      => '',
		'date_to'        => '',
		'orderby'        => 'sent_at',
		'order'          => 'DESC',
		'certificate_id' => 0, // Add certificate_id parameter for filtering by certificate
	);

	$args = wp_parse_args( $args, $defaults );

	$where_conditions = array( '1=1' );
	$where_values     = array();

	// Filter by post type
	if ( ! empty( $args['post_type'] ) ) {
		$where_conditions[] = 'post_type = %s';
		$where_values[]     = $args['post_type'];
	}

	// Filter by certificate ID
	if ( ! empty( $args['certificate_id'] ) ) {
		$where_conditions[] = 'certificate_id = %d';
		$where_values[]     = $args['certificate_id'];
	}

	// Filter by status
	if ( ! empty( $args['status'] ) ) {
		$where_conditions[] = 'status = %s';
		$where_values[]     = $args['status'];
	}

	// Search in email or name
	if ( ! empty( $args['search'] ) ) {
		$where_conditions[] = '(recipient_email LIKE %s OR recipient_name LIKE %s)';
		$search_term        = '%' . $wpdb->esc_like( $args['search'] ) . '%';
		$where_values[]     = $search_term;
		$where_values[]     = $search_term;
	}

	// Date range filter
	if ( ! empty( $args['date_from'] ) ) {
		$where_conditions[] = 'sent_at >= %s';
		$where_values[]     = $args['date_from'] . ' 00:00:00';
	}

	if ( ! empty( $args['date_to'] ) ) {
		$where_conditions[] = 'sent_at <= %s';
		$where_values[]     = $args['date_to'] . ' 23:59:59';
	}

	$where_clause = implode( ' AND ', $where_conditions );

	// Get total count
	$count_sql = "SELECT COUNT(*) FROM $table_name WHERE $where_clause";
	if ( ! empty( $where_values ) ) {
		$count_sql = $wpdb->prepare( $count_sql, $where_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	}
	$total_count = $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	// Get logs with pagination. per_page < 1 means "all rows" (CSV export); LIMIT -1 is invalid SQL.
	if ( (int) $args['per_page'] < 1 ) {
		$args['per_page'] = PHP_INT_MAX;
		$args['page']     = 1;
	}
	$offset  = ( $args['page'] - 1 ) * $args['per_page'];
	$orderby = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] );

	$logs_sql    = "SELECT * FROM $table_name WHERE $where_clause ORDER BY $orderby LIMIT %d OFFSET %d";
	$logs_values = array_merge( $where_values, array( $args['per_page'], $offset ) );

	$logs = $wpdb->get_results( $wpdb->prepare( $logs_sql, $logs_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	return array(
		'logs'  => $logs,
		'total' => $total_count,
		'pages' => ceil( $total_count / $args['per_page'] ),
	);
}

/**
 * Get email log statistics
 *
 * @return array Statistics array
 */
function certificate_generator_get_email_stats() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_logs';

	$stats = array();

	// Total emails sent
	$stats['total_sent'] = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'sent'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	// Total emails failed
	$stats['total_failed'] = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared

	// Emails sent today
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$stats['today'] = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE status = 'sent' AND DATE(sent_at) = %s",
			current_time( 'Y-m-d' )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Emails sent this week
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$stats['this_week'] = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE status = 'sent' AND sent_at >= %s",
			gmdate( 'Y-m-d', strtotime( 'monday this week' ) )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Emails sent this month
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$stats['this_month'] = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM $table_name WHERE status = 'sent' AND MONTH(sent_at) = %d AND YEAR(sent_at) = %d",
			gmdate( 'n' ),
			gmdate( 'Y' )
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Success rate
	$total_emails          = $stats['total_sent'] + $stats['total_failed'];
	$stats['success_rate'] = $total_emails > 0 ? round( ( $stats['total_sent'] / $total_emails ) * 100, 2 ) : 0;

	return $stats;
}

// Note: certificate_generator_email_already_sent() function is now defined in email-functions.php

/**
 * Delete old email logs (cleanup function)
 *
 * @param int $days_to_keep Number of days to keep logs
 * @return int Number of deleted records
 */
function certificate_generator_cleanup_email_logs( $days_to_keep = 90 ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_logs';

	$cutoff_date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days_to_keep} days" ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM $table_name WHERE sent_at < %s",
			$cutoff_date
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return $deleted;
}

// Create table on plugin activation
// Table creation is now handled in the main plugin activation hook

// Schedule cleanup task
add_action( 'wp', 'certificate_generator_schedule_log_cleanup' );
function certificate_generator_schedule_log_cleanup() {
	if ( ! wp_next_scheduled( 'certificate_generator_cleanup_logs' ) ) {
		wp_schedule_event( time(), 'weekly', 'certificate_generator_cleanup_logs' );
	}
}

// Cleanup hook
add_action(
	'certificate_generator_cleanup_logs',
	function () {
		certificate_generator_cleanup_email_logs( 90 ); // Keep logs for 90 days
	}
);
