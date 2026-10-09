<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write a debug message to the PHP error log, only when WP_DEBUG is on, so live sites
 * never log recipient data. Every log line in the plugin goes through here.
 */
function cg_debug_log( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'Certificate Generator Debug - ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- gated on WP_DEBUG
	}
}
