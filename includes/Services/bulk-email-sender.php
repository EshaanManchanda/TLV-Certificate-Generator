<?php
/**
 * Bulk Email Sender with Rate Limiting for Certificate Generator
 * Processes email queue with WP-Cron
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Schedule WP-Cron event for processing queue
add_action( 'init', 'certificate_generator_schedule_queue_processor' );
add_action( 'certificate_generator_process_email_queue', 'certificate_generator_process_queue_batch' );

/**
 * Schedule recurring queue processor
 */
function certificate_generator_schedule_queue_processor() {
	if ( ! wp_next_scheduled( 'certificate_generator_process_email_queue' ) ) {
		// Schedule to run every 5 minutes
		wp_schedule_event( time(), 'certificate_generator_5min', 'certificate_generator_process_email_queue' );
	}
}

// Add custom cron schedule
add_filter( 'cron_schedules', 'certificate_generator_add_cron_schedules' );
function certificate_generator_add_cron_schedules( $schedules ) {
	$schedules['certificate_generator_5min'] = array(
		'interval' => 300, // 5 minutes
		'display'  => __( 'Every 5 Minutes (Certificate Generator)', 'certificate-generator' ),
	);
	return $schedules;
}

/**
 * Process a batch of emails from the queue
 * This runs automatically via WP-Cron every 5 minutes
 *
 * @return array Results
 */
function certificate_generator_process_queue_batch() {
	global $wpdb;

	$results = array(
		'processed' => 0,
		'sent'      => 0,
		'failed'    => 0,
		'skipped'   => 0,
		'errors'    => array(),
	);

	// A paused queue must not process anything until resumed — previously
	// this flag was only read by the progress display, not enforced here.
	if ( certificate_generator_is_queue_paused() ) {
		return $results;
	}

	// Run-lock: prevent cron + inline trigger + concurrent request from double-processing.
	if ( get_transient( 'cg_queue_lock' ) ) {
		return $results;
	}
	set_transient( 'cg_queue_lock', 1, 60 );

	try {
		// Stale-row reclaim: rows stuck in 'sending' longer than CG_QUEUE_STALE_MINUTES
		// are reset to 'pending' with attempts incremented so they eventually land on 'failed'.
		$stale_minutes = defined( 'CG_QUEUE_STALE_MINUTES' ) ? (int) CG_QUEUE_STALE_MINUTES : 10;
		$max_attempts  = defined( 'CG_QUEUE_MAX_ATTEMPTS' ) ? (int) CG_QUEUE_MAX_ATTEMPTS : 3;
		$queue_table   = $wpdb->prefix . 'cert_email_queue';

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE $queue_table
				 SET status = CASE WHEN attempts + 1 >= %d THEN 'failed' ELSE 'pending' END,
				     attempts = attempts + 1,
				     error_message = 'reclaimed: stale sending'
				 WHERE status = 'sending'
				   AND attempts < %d
				   AND updated_at < DATE_SUB(NOW(), INTERVAL %d MINUTE)",
				$max_attempts,
				$max_attempts,
				$stale_minutes
			)
		);

		// Check if rate limiting allows sending.
		$rate_check = certificate_generator_can_send_email();
		if ( ! $rate_check['can_send'] ) {
			return $results;
		}

		$batch_size = defined( 'CG_QUEUE_BATCH_SIZE' ) ? (int) CG_QUEUE_BATCH_SIZE : 50;
		$emails     = certificate_generator_get_next_batch( $batch_size );

		if ( empty( $emails ) ) {
			return $results;
		}

		$start  = microtime( true );
		$budget = defined( 'CG_QUEUE_RUNTIME_BUDGET' ) ? (int) CG_QUEUE_RUNTIME_BUDGET : 20;

		foreach ( $emails as $queue_item ) {
			// Runtime budget: stop before PHP timeout so cron can resume next tick.
			if ( microtime( true ) - $start > $budget ) {
				break;
			}

			++$results['processed'];

			$rate_check = certificate_generator_can_send_email();
			if ( ! $rate_check['can_send'] ) {
				break;
			}

			certificate_generator_update_queue_status( $queue_item->id, 'sending' );

			$scope = ! empty( $queue_item->scope ) ? json_decode( $queue_item->scope, true ) : null;
			$sent  = certificate_generator_send_email( $queue_item->certificate_id, true, is_array( $scope ) ? $scope : null );

			if ( $sent ) {
				certificate_generator_update_queue_status( $queue_item->id, 'sent' );
				++$results['sent'];
			} else {
				$error_msg = 'Failed to send certificate email';
				if ( $queue_item->attempts + 1 >= $max_attempts ) {
					certificate_generator_update_queue_status( $queue_item->id, 'failed', $error_msg );
				} else {
					// Exponential-ish backoff so a failing item doesn't immediately
					// re-compete with fresh items in the next batch pull.
					$backoff_seconds = ( $queue_item->attempts + 1 ) * 5 * MINUTE_IN_SECONDS;
					certificate_generator_update_queue_status( $queue_item->id, 'pending', $error_msg, $backoff_seconds );
				}
				++$results['failed'];
				$results['errors'][] = "Certificate {$queue_item->certificate_id}: $error_msg";
			}

			sleep( 2 );
		}
	} finally {
		delete_transient( 'cg_queue_lock' );
	}

	return $results;
}

/**
 * Manually trigger queue processing (for immediate sending)
 *
 * @param int $num_batches Number of batches to process
 * @return array Combined results
 */
function certificate_generator_process_queue_now( $num_batches = 1 ) {
	$total_results = array(
		'processed' => 0,
		'sent'      => 0,
		'failed'    => 0,
		'skipped'   => 0,
		'errors'    => array(),
		'batches'   => 0,
	);

	for ( $i = 0; $i < $num_batches; $i++ ) {
		$batch_results = certificate_generator_process_queue_batch();

		// Combine results
		$total_results['processed'] += $batch_results['processed'];
		$total_results['sent']      += $batch_results['sent'];
		$total_results['failed']    += $batch_results['failed'];
		$total_results['skipped']   += $batch_results['skipped'];
		$total_results['errors']     = array_merge( $total_results['errors'], $batch_results['errors'] );
		++$total_results['batches'];

		// If no emails were processed, stop
		if ( $batch_results['processed'] === 0 ) {
			break;
		}

		// Delay between batches
		if ( $i < $num_batches - 1 ) {
			$config = certificate_generator_get_rate_limit_config();
			sleep( $config['batch_delay'] );
		}
	}

	return $total_results;
}

/**
 * Start bulk sending process
 *
 * @param string $post_type Post type (students/teachers/schools)
 * @param array  $post_ids Optional specific post IDs
 * @return array Results
 */
function certificate_generator_start_bulk_send( $post_type, $post_ids = array() ) {
	cg_debug_log( "Certificate Generator: Starting bulk send for post_type: $post_type" );

	// Add all emails to queue
	$queue_results = certificate_generator_bulk_queue_emails( $post_type, $post_ids );

	if ( $queue_results['queued'] === 0 ) {
		return array(
			'success' => false,
			'message' => 'No emails were queued',
			'details' => $queue_results,
		);
	}

	// Calculate estimate
	$estimate = certificate_generator_estimate_send_time( $queue_results['queued'] );

	// Trigger immediate processing of first batch
	$process_results = certificate_generator_process_queue_batch();

	return array(
		'success'          => true,
		'message'          => sprintf(
			'%d emails queued successfully. %d sent immediately.',
			$queue_results['queued'],
			$process_results['sent']
		),
		'queued'           => $queue_results['queued'],
		'skipped'          => $queue_results['skipped'],
		'sent_immediately' => $process_results['sent'],
		'estimate'         => $estimate,
	);
}

/**
 * Pause queue processing
 */
function certificate_generator_pause_queue() {
	update_option( 'certificate_generator_queue_paused', true );
	cg_debug_log( 'Certificate Generator: Queue processing paused' );
	return true;
}

/**
 * Resume queue processing
 */
function certificate_generator_resume_queue() {
	update_option( 'certificate_generator_queue_paused', false );
	cg_debug_log( 'Certificate Generator: Queue processing resumed' );
	return true;
}

/**
 * Check if queue is paused
 *
 * @return bool
 */
function certificate_generator_is_queue_paused() {
	return get_option( 'certificate_generator_queue_paused', false );
}

/**
 * Get queue progress for display
 *
 * @return array Progress information
 */
function certificate_generator_get_queue_progress() {
	$stats       = certificate_generator_get_queue_stats();
	$rate_status = certificate_generator_get_rate_limit_status();

	$total     = $stats['pending'] + $stats['sent'] + $stats['failed'];
	$completed = $stats['sent'] + $stats['failed'];

	$progress_percentage = $total > 0 ? ( $completed / $total ) * 100 : 0;

	// Calculate ETA
	$emails_remaining = $stats['pending'];
	$eta              = null;

	if ( $emails_remaining > 0 && ! certificate_generator_is_queue_paused() ) {
		$estimate = certificate_generator_estimate_send_time( $emails_remaining );
		$eta      = $estimate['estimated_completion'];
	}

	return array(
		'stats'               => $stats,
		'rate_status'         => $rate_status,
		'progress_percentage' => round( $progress_percentage, 1 ),
		'total'               => $total,
		'completed'           => $completed,
		'remaining'           => $emails_remaining,
		'is_paused'           => certificate_generator_is_queue_paused(),
		'eta'                 => $eta,
		'eta_human'           => $eta ? human_time_diff( strtotime( $eta ), time() ) : null,
	);
}

/**
 * Clear entire queue (emergency stop)
 *
 * @return int Number of items cleared
 */
function certificate_generator_clear_queue() {
	global $wpdb;

	$table_name = $wpdb->prefix . 'cert_email_queue';

	$cleared = $wpdb->query( 'DELETE FROM `' . esc_sql( $table_name ) . "` WHERE status = 'pending'" );

	cg_debug_log( "Certificate Generator: Cleared $cleared pending emails from queue" );

	return $cleared;
}

/**
 * Admin notice for queue status
 */
add_action( 'admin_notices', 'certificate_generator_queue_status_notice' );
function certificate_generator_queue_status_notice() {
	// Only show on certificate-related pages
	$screen = get_current_screen();
	if ( ! $screen || (
		strpos( $screen->id, 'certificate' ) === false &&
		strpos( $screen->id, 'students' ) === false &&
		strpos( $screen->id, 'teachers' ) === false &&
		strpos( $screen->id, 'schools' ) === false
	) ) {
		return;
	}

	$stats = certificate_generator_get_queue_stats();

	// Show notice if there are pending emails
	if ( $stats['pending'] > 0 ) {
		$progress = certificate_generator_get_queue_progress();

		?>
		<div class="notice notice-info is-dismissible">
			<h3>📧 Certificate Email Queue Status</h3>
			<p>
				<strong><?php echo number_format( $stats['pending'] ); ?></strong> emails pending |
				<strong><?php echo number_format( $stats['sent'] ); ?></strong> sent |
				<strong><?php echo number_format( $stats['failed'] ); ?></strong> failed
			</p>
			<?php if ( $progress['eta'] ) : ?>
				<p>
					<strong>Estimated completion:</strong> <?php echo esc_html( $progress['eta_human'] ); ?>
					(<?php echo esc_html( $progress['progress_percentage'] ); ?>% complete)
				</p>
			<?php endif; ?>
			<?php if ( certificate_generator_is_queue_paused() ) : ?>
				<p style="color: #d63638;">
					<strong>⚠️ Queue is PAUSED</strong> - No emails are being sent
				</p>
			<?php endif; ?>
			<?php if ( ! $progress['rate_status']['can_send']['can_send'] ) : ?>
				<p style="color: #d63638;">
					<strong>⚠️ Rate limit reached:</strong> <?php echo esc_html( $progress['rate_status']['can_send']['reason'] ); ?>
					- Will resume automatically in <?php echo esc_html( certificate_generator_format_wait_time( $progress['rate_status']['can_send']['wait_seconds'] ) ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
?>
