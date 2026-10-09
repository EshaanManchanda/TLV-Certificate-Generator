<?php
/**
 * Email Rate Limiter for Certificate Generator
 * Prevents hitting Hostinger's email rate limits
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get rate limit configuration
 *
 * @return array Rate limit settings
 */
function certificate_generator_get_rate_limit_config() {
	// Get custom settings or use defaults
	$config = get_option( 'certificate_generator_rate_limits', array() );

	$defaults = array(
		'emails_per_hour'   => 80,  // Safe limit (Hostinger typically allows 100-300)
		'emails_per_minute' => 10, // Burst limit
		'batch_size'        => 10,        // Emails per batch
		'batch_delay'       => 480,      // Seconds between batches (8 minutes)
		'enabled'           => true,
	);

	return wp_parse_args( $config, $defaults );
}

/**
 * Check if we can send email now (respects rate limits)
 *
 * @return array ['can_send' => bool, 'reason' => string, 'wait_seconds' => int]
 */
function certificate_generator_can_send_email() {
	$config = certificate_generator_get_rate_limit_config();

	if ( ! $config['enabled'] ) {
		return array(
			'can_send'     => true,
			'reason'       => 'Rate limiting disabled',
			'wait_seconds' => 0,
		);
	}

	// Check hourly limit
	$sent_last_hour = certificate_generator_get_sent_count( 3600 ); // 1 hour

	if ( $sent_last_hour >= $config['emails_per_hour'] ) {
		$wait_seconds = certificate_generator_get_wait_time_for_hourly_reset();
		return array(
			'can_send'     => false,
			'reason'       => "Hourly limit reached ($sent_last_hour/{$config['emails_per_hour']})",
			'wait_seconds' => $wait_seconds,
		);
	}

	// Check per-minute limit (burst protection)
	$sent_last_minute = certificate_generator_get_sent_count( 60 ); // 1 minute

	if ( $sent_last_minute >= $config['emails_per_minute'] ) {
		return array(
			'can_send'     => false,
			'reason'       => "Per-minute limit reached ($sent_last_minute/{$config['emails_per_minute']})",
			'wait_seconds' => 60,
		);
	}

	return array(
		'can_send'     => true,
		'reason'       => 'Within rate limits',
		'wait_seconds' => 0,
	);
}

/**
 * Get count of emails sent in last N seconds
 *
 * NOTE: This counts UNIQUE emails (grouped by recipient and send time),
 * not individual certificates. When multiple certificates are sent to
 * the same email address at the same time (in a ZIP), they count as 1 email.
 *
 * @param int $seconds Number of seconds to look back
 * @return int Number of unique emails sent
 */
function certificate_generator_get_sent_count( $seconds = 3600 ) {
	$cache_key = 'cg_sent_count_' . $seconds;
	$cached    = get_transient( $cache_key );
	if ( $cached !== false ) {
		return (int) $cached;
	}

	global $wpdb;
	$table_name = $wpdb->prefix . 'cert_email_logs';
	$since      = gmdate( 'Y-m-d H:i:s', time() - $seconds );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$count = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*)
         FROM (
             SELECT DISTINCT recipient_email, DATE_FORMAT(sent_at, '%%Y-%%m-%%d %%H:%%i') as send_time
             FROM $table_name
             WHERE status = 'sent'
             AND sent_at >= %s
         ) as unique_sends",
			$since
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	set_transient( $cache_key, (int) $count, 30 );
	return (int) $count;
}

/**
 * Get wait time until hourly limit resets
 *
 * @return int Seconds to wait
 */
function certificate_generator_get_wait_time_for_hourly_reset() {
	global $wpdb;

	$table_name   = $wpdb->prefix . 'cert_email_logs';
	$one_hour_ago = gmdate( 'Y-m-d H:i:s', time() - 3600 );

	// Get oldest email sent in the last hour
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$oldest = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT sent_at FROM $table_name
         WHERE status = 'sent'
         AND sent_at >= %s
         ORDER BY sent_at ASC
         LIMIT 1",
			$one_hour_ago
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	if ( ! $oldest ) {
		return 0; // No emails in last hour
	}

	$oldest_time  = strtotime( $oldest );
	$reset_time   = $oldest_time + 3600; // 1 hour after oldest
	$wait_seconds = max( 0, $reset_time - time() );

	return $wait_seconds;
}

/**
 * Record email send for rate limiting
 * (This is called automatically when email is sent via certificate_generator_send_email)
 *
 * @param int $post_id Certificate post ID
 * @return bool Success
 */
function certificate_generator_record_email_send( $post_id ) {
	// This is already handled by certificate_generator_log_email()
	// Just here for clarity/documentation
	return true;
}

/**
 * Get rate limit status and recommendations
 *
 * @return array Status information
 */
function certificate_generator_get_rate_limit_status() {
	$config = certificate_generator_get_rate_limit_config();

	$sent_last_hour   = certificate_generator_get_sent_count( 3600 );
	$sent_last_minute = certificate_generator_get_sent_count( 60 );

	$hourly_remaining  = max( 0, $config['emails_per_hour'] - $sent_last_hour );
	$hourly_percentage = ( $sent_last_hour / $config['emails_per_hour'] ) * 100;

	$status = array(
		'enabled'  => $config['enabled'],
		'limits'   => $config,
		'usage'    => array(
			'last_hour'         => $sent_last_hour,
			'last_minute'       => $sent_last_minute,
			'hourly_limit'      => $config['emails_per_hour'],
			'hourly_remaining'  => $hourly_remaining,
			'hourly_percentage' => round( $hourly_percentage, 1 ),
		),
		'can_send' => certificate_generator_can_send_email(),
	);

	// Add warning if approaching limit
	if ( $hourly_percentage >= 90 ) {
		$status['warning'] = 'Approaching hourly limit';
	} elseif ( $hourly_percentage >= 75 ) {
		$status['warning'] = 'High email volume';
	}

	return $status;
}

/**
 * Calculate estimated time to send N emails
 *
 * @param int $num_emails Number of emails to send
 * @return array Estimation details
 */
function certificate_generator_estimate_send_time( $num_emails ) {
	$config = certificate_generator_get_rate_limit_config();

	$emails_per_hour = $config['emails_per_hour'];
	$hours_needed    = ceil( $num_emails / $emails_per_hour );

	$start_time = time();
	$end_time   = $start_time + ( $hours_needed * 3600 );

	return array(
		'num_emails'                 => $num_emails,
		'emails_per_hour'            => $emails_per_hour,
		'hours_needed'               => $hours_needed,
		'estimated_completion'       => gmdate( 'Y-m-d H:i:s', (int) $end_time ),
		'estimated_completion_human' => human_time_diff( $start_time, $end_time ),
		'start_time'                 => gmdate( 'Y-m-d H:i:s', $start_time ),
	);
}

/**
 * Reset rate limit counters (for testing/emergency use)
 *
 * @return bool Success
 */
function certificate_generator_reset_rate_limits() {
	// Clear transients used for rate limiting
	delete_transient( 'cert_gen_rate_limit_hour' );
	delete_transient( 'cert_gen_rate_limit_minute' );

	return true;
}

/**
 * Update rate limit configuration
 *
 * @param array $config New configuration
 * @return bool Success
 */
function certificate_generator_update_rate_limit_config( $config ) {
	$current = certificate_generator_get_rate_limit_config();
	$updated = wp_parse_args( $config, $current );

	// Validate limits
	$updated['emails_per_hour']   = max( 10, min( 300, (int) $updated['emails_per_hour'] ) );
	$updated['emails_per_minute'] = max( 1, min( 50, (int) $updated['emails_per_minute'] ) );
	$updated['batch_size']        = max( 1, min( 50, (int) $updated['batch_size'] ) );
	$updated['batch_delay']       = max( 10, min( 3600, (int) $updated['batch_delay'] ) );

	$result = update_option( 'certificate_generator_rate_limits', $updated );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		cg_debug_log( 'Rate limit config updated: ' . wp_json_encode( $updated ) );
	}

	return $result;
}

/**
 * Get human-readable time remaining
 *
 * @param int $seconds Seconds remaining
 * @return string Human-readable time
 */
function certificate_generator_format_wait_time( $seconds ) {
	if ( $seconds < 60 ) {
		return sprintf( /* translators: %d: number of seconds */ __( '%d seconds', 'certificate-generator' ), $seconds );
	} elseif ( $seconds < 3600 ) {
		$minutes = ceil( $seconds / 60 );
		return sprintf( /* translators: %d: number of minutes */ __( '%d minutes', 'certificate-generator' ), $minutes );
	} else {
		$hours = ceil( $seconds / 3600 );
		return sprintf( /* translators: %d: number of hours */ __( '%d hours', 'certificate-generator' ), $hours );
	}
}
