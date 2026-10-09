<?php
/**
 * Security Helper Class
 *
 * Provides security utilities and hardening measures for the Certificate Generator plugin
 *
 * @package Certificate Generator
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate Generator Security Helper
 */
class CertificateGenerator_SecurityHelper {

	/**
	 * Initialize security measures
	 */
	public static function init() {
		// Add file upload security
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'secure_file_uploads' ) );
	}

	/**
	 * Secure file upload validation
	 */
	public static function secure_file_uploads( $file ) {
		// Only process if this is a certificate-related upload
		if ( ! isset( $_POST['action'] ) || strpos( sanitize_key( wp_unslash( $_POST['action'] ) ), 'certificate' ) === false ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the upload handler checks its own nonce
			return $file;
		}

		// Check file size (max 10MB)
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			$file['error'] = 'File size too large. Maximum 10MB allowed.';
			return $file;
		}

		// Validate file type
		$allowed_types  = array( 'csv', 'txt', 'pdf', 'jpg', 'jpeg', 'png' );
		$file_extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_extension, $allowed_types ) ) {
			$file['error'] = 'Invalid file type. Only CSV, TXT, PDF, and image files are allowed.';
			return $file;
		}

		// Check for suspicious content
		if ( self::contains_suspicious_content( $file['tmp_name'] ) ) {
			$file['error'] = 'File contains suspicious content and was rejected.';
			return $file;
		}

		return $file;
	}

	/**
	 * Check for suspicious content in uploaded files
	 */
	private static function contains_suspicious_content( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return true;
		}

		// Read first 1KB of file to check for suspicious patterns
		$content = file_get_contents( $file_path, false, null, 0, 1024 );

		// Check for PHP tags, script tags, and other suspicious patterns
		$suspicious_patterns = array(
			'/<\?php/i',
			'/<script/i',
			'/eval\s*\(/i',
			'/exec\s*\(/i',
			'/system\s*\(/i',
			'/shell_exec/i',
			'/base64_decode/i',
		);

		foreach ( $suspicious_patterns as $pattern ) {
			if ( preg_match( $pattern, $content ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Rate limiting for API endpoints.
	 *
	 * Backed by a transient (survives across requests), not the PHP-process-local
	 * static array this used to use — that reset on every request and never
	 * actually limited anything since nothing called it. Mirrors the transient
	 * pattern already proven correct in includes/Email/rate-limiter.php.
	 */
	public static function check_rate_limit( $action, $limit = 10, $window = 60 ) {
		$user_id    = get_current_user_id();
		$ip_address = self::get_client_ip();
		$key        = 'cg_rl_' . md5( $action . '_' . $user_id . '_' . $ip_address );

		$current_time = time();
		$hits         = get_transient( $key );
		$hits         = is_array( $hits ) ? $hits : array();

		// Drop entries outside the current window.
		$hits = array_values(
			array_filter(
				$hits,
				function ( $timestamp ) use ( $current_time, $window ) {
					return ( $current_time - $timestamp ) < $window;
				}
			)
		);

		if ( count( $hits ) >= $limit ) {
			set_transient( $key, $hits, $window );
			return false;
		}

		$hits[] = $current_time;
		set_transient( $key, $hits, $window );

		return true;
	}

	/**
	 * Get client IP address safely
	 */
	private static function get_client_ip() {
		$remote = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		$ip     = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '127.0.0.1';

		// Forwarded headers are set by whoever sends the request, so trusting them let anyone
		// dodge the rate limit with a fake X-Forwarded-For. Only trust them when the request
		// comes from a proxy on a private network (a load balancer in front of this server).
		$from_private_proxy = ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( $from_private_proxy ) {
			foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
				$first = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ?? '' ) ) )[0] );
				if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
					$ip = $first;
					break;
				}
			}
		}

		/**
		 * Sites behind a public proxy such as Cloudflare (without the server restoring the
		 * visitor IP) can return the real visitor IP here, e.g. from CF-Connecting-IP.
		 */
		return (string) apply_filters( 'cg_client_ip', $ip );
	}

	/**
	 * Secure log function with sanitization
	 */
	public static function secure_log( $message, $level = 'INFO' ) {
		if ( ! is_string( $message ) ) {
			$message = wp_json_encode( $message );
		}

		// Sanitize log message
		$message = sanitize_text_field( str_replace( "\0", '', $message ) );

		// Limit log message length
		if ( strlen( $message ) > 1000 ) {
			$message = substr( $message, 0, 1000 ) . '... [truncated]';
		}

		$log_entry = sprintf(
			'[%s] Certificate Generator - %s: %s',
			gmdate( 'Y-m-d H:i:s' ),
			$level,
			$message
		);

		cg_debug_log( $log_entry );
	}
}

