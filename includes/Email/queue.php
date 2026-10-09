<?php
/**
 * Email Queue Management for Certificate Generator
 * Handles bulk email sending with rate limiting
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create email queue table
 */
function certificate_generator_create_email_queue_table() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'cert_email_queue';
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        certificate_id bigint(20) NOT NULL,
        recipient_email varchar(255) NOT NULL,
        recipient_name varchar(255) NOT NULL,
        post_type varchar(50) NOT NULL,
        certificate_type varchar(100),
        scope longtext DEFAULT NULL,
        status varchar(20) DEFAULT 'pending',
        attempts int(11) DEFAULT 0,
        scheduled_time datetime DEFAULT NULL,
        sent_at datetime DEFAULT NULL,
        error_message text,
        priority int(11) DEFAULT 5,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY certificate_id (certificate_id),
        KEY status (status),
        KEY scheduled_time (scheduled_time),
        KEY recipient_email (recipient_email)
    ) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}

// Table is now created during plugin activation in certificate-generator.php
// Keeping function available for manual calls if needed

/**
 * Add a wp_certificate_generator row to the email queue.
 *
 * @param int    $cg_id           Row ID in wp_certificate_generator.
 * @param string $recipient_email Recipient email address.
 * @param array  $options         Optional: scheduled_time, priority, recipient_name,
 *                                scope ({entity: [row ids]} — only these certificates are attached;
 *                                omitted = every certificate for the address).
 * @return int|false Queue ID or false on failure.
 */
function certificate_generator_queue_email( $cg_id, $recipient_email, $options = array() ) {
	global $wpdb;

	$queue_table = $wpdb->prefix . 'cert_email_queue';
	$cg_table    = $wpdb->prefix . 'certificate_generator';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$row = $wpdb->get_row(
		$wpdb->prepare( "SELECT id, student_name, certificate_type FROM $cg_table WHERE id = %d LIMIT 1", $cg_id ),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! $row ) {
		certificate_generator_email_debug_log( "Cannot queue: no cg record for ID $cg_id" );
		return false;
	}

	$certificate_type = $row['certificate_type'] ?? '';

	// SQL tables are authoritative for name; legacy row is last resort.
	$recipient_name = (string) ( $options['recipient_name'] ?? '' );
	if ( ! $recipient_name && class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		foreach ( array(
			array( 'students', 'student_name' ),
			array( 'teachers', 'teacher_name' ),
			array( 'schools', 'school_name' ),
		) as [$ent, $col] ) {
			$tbl = $tables->get_table( $ent );
			if ( ! $tbl ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$name = $wpdb->get_var( $wpdb->prepare( "SELECT $col FROM $tbl WHERE email = %s LIMIT 1", $recipient_email ) );
			if ( $name ) {
				$recipient_name = $name;
				break;
			}
		}
	}
	if ( ! $recipient_name ) {
		$recipient_name = $row['student_name'] ?? '';
	}

	$scope = isset( $options['scope'] ) && is_array( $options['scope'] ) ? $options['scope'] : null;

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$existing = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT id, scope FROM $queue_table WHERE certificate_id = %d AND recipient_email = %s AND status IN ('pending','sending')",
			$cg_id,
			$recipient_email
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $existing ) {
		// Still waiting: widen its scope rather than queue a second email. A NULL scope
		// already means "every certificate", so there is nothing to add.
		if ( $scope && null !== $existing->scope ) {
			$merged = json_decode( (string) $existing->scope, true ) ?: array();
			foreach ( $scope as $entity => $ids ) {
				$merged[ $entity ] = array_values( array_unique( array_merge( $merged[ $entity ] ?? array(), $ids ) ) );
			}
			$wpdb->update( $queue_table, array( 'scope' => wp_json_encode( $merged ) ), array( 'id' => $existing->id ) );
		}
		return (int) $existing->id;
	}

	$scheduled_time = $options['scheduled_time'] ?? current_time( 'mysql' );
	$priority       = $options['priority'] ?? 5;

	$result = $wpdb->insert(
		$queue_table,
		array(
			'certificate_id'   => $cg_id,
			'recipient_email'  => $recipient_email,
			'recipient_name'   => $recipient_name,
			'post_type'        => '',
			'certificate_type' => $certificate_type,
			'scope'            => $scope ? wp_json_encode( $scope ) : null,
			'status'           => 'pending',
			'scheduled_time'   => $scheduled_time,
			'priority'         => $priority,
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
	);

	if ( $result ) {
		certificate_generator_email_debug_log( "Queued cg_id $cg_id → $recipient_email (queue #{$wpdb->insert_id})" );
		return $wpdb->insert_id;
	}

	certificate_generator_email_debug_log( "Failed to queue cg_id $cg_id" );
	return false;
}

/**
 * Get next batch of emails to send
 *
 * @param int $limit Number of emails to get
 * @return array Array of queue items
 */
function certificate_generator_get_next_batch( $limit = 10 ) {
	global $wpdb;

	$table_name   = $wpdb->prefix . 'cert_email_queue';
	$current_time = current_time( 'mysql' );

	$max_attempts = defined( 'CERTIFICATE_GENERATOR_QUEUE_MAX_ATTEMPTS' ) ? (int) CERTIFICATE_GENERATOR_QUEUE_MAX_ATTEMPTS : 3;
	$emails       = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM $table_name
         WHERE status = 'pending'
         AND scheduled_time <= %s
         AND attempts < %d
         ORDER BY priority DESC, scheduled_time ASC, id ASC
         LIMIT %d",
			$current_time,
			$max_attempts,
			$limit
		)
	);

	return $emails;
}

/**
 * Update queue item status
 *
 * @param int      $queue_id Queue item ID
 * @param string   $status New status (pending/sending/sent/failed)
 * @param string   $error_message Optional error message
 * @param int|null $retry_delay_seconds When re-queuing to 'pending' after a failed
 *                 send, push scheduled_time this many seconds into the future
 *                 instead of leaving it immediately eligible again (backoff).
 * @return bool Success
 */
function certificate_generator_update_queue_status( $queue_id, $status, $error_message = null, $retry_delay_seconds = null ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_queue';

	$data   = array( 'status' => $status );
	$format = array( '%s' );

	if ( $status === 'pending' && $retry_delay_seconds !== null ) {
		$data['scheduled_time'] = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $retry_delay_seconds ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$format[]               = '%s';
	}

	if ( $status === 'sent' ) {
		$data['sent_at'] = current_time( 'mysql' );
		$format[]        = '%s';
	}

	if ( $error_message !== null ) {
		$data['error_message'] = $error_message;
		$format[]              = '%s';
	}

	// Record last attempt timestamp on any active transition.
	if ( in_array( $status, array( 'sending', 'sent', 'failed' ), true ) ) {
		$data['last_attempt_at'] = current_time( 'mysql' );
		$format[]                = '%s';
	}

	// Increment attempts if sending or failed.
	if ( in_array( $status, array( 'sending', 'failed' ), true ) ) {
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE $table_name SET attempts = attempts + 1 WHERE id = %d",
				$queue_id
			)
		);
	}

	$result = $wpdb->update(
		$table_name,
		$data,
		array( 'id' => $queue_id ),
		$format,
		array( '%d' )
	);

	return $result !== false;
}

/**
 * Get queue statistics
 *
 * @return array Queue stats
 */
function certificate_generator_get_queue_stats() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_queue';

	$stats = array(
		'total'          => 0,
		'pending'        => 0,
		'sending'        => 0,
		'sent'           => 0,
		'failed'         => 0,
		'oldest_pending' => null,
		'newest_sent'    => null,
	);

	// Get counts by status
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$counts = $wpdb->get_results(
		"SELECT status, COUNT(*) as count FROM $table_name GROUP BY status",
		OBJECT_K
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	foreach ( $counts as $status => $row ) {
		$stats[ $status ] = (int) $row->count;
		$stats['total']  += (int) $row->count;
	}

	// Get oldest pending
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$oldest_pending = $wpdb->get_var(
		"SELECT created_at FROM $table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $oldest_pending ) {
		$stats['oldest_pending'] = $oldest_pending;
	}

	// Get newest sent
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$newest_sent = $wpdb->get_var(
		"SELECT sent_at FROM $table_name WHERE status = 'sent' ORDER BY sent_at DESC LIMIT 1"
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $newest_sent ) {
		$stats['newest_sent'] = $newest_sent;
	}

	return $stats;
}

/**
 * Clear completed queue items older than specified days
 *
 * @param int $days Number of days to keep
 * @return int Number of items deleted
 */
function certificate_generator_cleanup_queue( $days = 30 ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_queue';

	$date = gmdate( 'Y-m-d H:i:s', strtotime( "-$days days" ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM $table_name WHERE status = 'sent' AND sent_at < %s",
			$date
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return $deleted;
}

/**
 * Retry failed emails
 *
 * @param int $max_attempts Maximum attempts before giving up
 * @return int Number of emails reset to pending
 */
function certificate_generator_retry_failed_emails( $max_attempts = 3 ) {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_queue';

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$reset = $wpdb->query(
		$wpdb->prepare(
			"UPDATE $table_name
         SET status = 'pending', error_message = NULL, scheduled_time = %s
         WHERE status = 'failed'
         AND attempts < %d",
			current_time( 'mysql' ),
			$max_attempts
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	return $reset;
}

/**
 * Add bulk emails to queue
 * NOW WITH EMAIL GROUPING: Groups certificates by email before queuing
 *
 * @param string $post_type Post type (students/teachers/schools)
 * @param array $post_ids Optional specific post IDs
 * @param bool $skip_already_sent Whether to skip certificates that were already sent
 * @return array Result array with counts
 */
/**
 * Queue one email per unique recipient in wp_certificate_generator.
 *
 * @param string $post_type        Ignored — kept for backward-compat call sites.
 * @param array  $cg_ids           Optional subset of wp_certificate_generator IDs to process.
 * @param bool   $skip_already_sent Skip cg_ids already logged as sent.
 * @return array{queued:int,skipped:int,errors:string[],unique_emails:int,grouped_emails:int}
 */
function certificate_generator_bulk_queue_emails( $post_type = '', $cg_ids = array(), $skip_already_sent = false ) {
	global $wpdb;

	$results  = array(
		'queued'         => 0,
		'skipped'        => 0,
		'errors'         => array(),
		'unique_emails'  => 0,
		'grouped_emails' => 0,
	);
	$cg_table = $wpdb->prefix . 'certificate_generator';

	if ( ! empty( $cg_ids ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $cg_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, email FROM $cg_table WHERE email != '' AND id IN ($placeholders) ORDER BY id ASC",
				array_map( 'intval', $cg_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} else {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$rows = $wpdb->get_results(
			"SELECT id, email FROM $cg_table WHERE email != '' ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	if ( empty( $rows ) ) {
		$results['errors'][] = 'No certificate records with email found';
		return $results;
	}

	// Group by email.
	$by_email = array();
	foreach ( $rows as $row ) {
		if ( ! is_email( $row['email'] ) ) {
			++$results['skipped'];
			$results['errors'][] = "CG #{$row['id']} skipped: invalid email '{$row['email']}'";
			continue;
		}
		if ( $skip_already_sent && certificate_generator_email_already_sent( (int) $row['id'], $row['email'] ) ) {
			++$results['skipped'];
			continue;
		}
		$by_email[ $row['email'] ][] = (int) $row['id'];
	}

	// One queue entry per unique email (first cg_id as anchor; send_email will group all).
	foreach ( $by_email as $email => $ids ) {
		$queue_id = certificate_generator_queue_email( $ids[0], $email );
		if ( $queue_id ) {
			$results['queued'] += count( $ids );
			++$results['unique_emails'];
			if ( count( $ids ) > 1 ) {
				++$results['grouped_emails'];
				certificate_generator_email_debug_log( 'Grouped ' . count( $ids ) . " certs for $email → queue #$queue_id" );
			}
		} else {
			$results['errors'][] = "Failed to queue for $email (IDs: " . implode( ',', $ids ) . ')';
		}
	}

	certificate_generator_email_debug_log( "Bulk queued {$results['queued']} via {$results['unique_emails']} unique emails ({$results['grouped_emails']} grouped), skipped {$results['skipped']}" );
	return $results;
}
