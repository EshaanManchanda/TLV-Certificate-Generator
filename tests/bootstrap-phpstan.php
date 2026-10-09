<?php
/**
 * PHPStan-only symbol declarations.
 *
 * These globals are real at runtime (defined in certificate-generator.php,
 * a WP config secret, or bundled PDF libs loaded conditionally) but aren't
 * visible to static analysis of individual files. Guarded so this is a
 * harmless no-op if ever loaded alongside the real definitions.
 */

if ( ! defined( 'CERTIFICATE_GENERATOR_PATH' ) ) {
	define( 'CERTIFICATE_GENERATOR_PATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'CERTIFICATE_GENERATOR_URL' ) ) {
	define( 'CERTIFICATE_GENERATOR_URL', 'https://example.test/wp-content/plugins/certificate-generator/' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
	define( 'SECURE_AUTH_KEY', 'phpstan-placeholder-key' );
}

if ( ! function_exists( 'certificate_generator_format_date' ) ) {
	function certificate_generator_format_date( ?string $date_string, bool $include_time = false ): string {
		return '';
	}
}
if ( ! function_exists( 'certificate_generator_convert_to_bytes' ) ) {
	function certificate_generator_convert_to_bytes( $size_str ) {
		return 0;
	}
}
if ( ! function_exists( 'certificate_generator_format_bytes' ) ) {
	function certificate_generator_format_bytes( $bytes ) {
		return '';
	}
}
if ( ! function_exists( 'certificate_generator_log_debug' ) ) {
	function certificate_generator_log_debug( $message ): void {}
}
