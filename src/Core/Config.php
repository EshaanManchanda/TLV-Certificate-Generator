<?php
declare(strict_types=1);

namespace CertificateGenerator\Core;

/**
 * Plugin configuration constants and defaults.
 */
class Config {

	public const VERSION           = '7.6.0';
	public const DB_VERSION_OPTION = 'cg_db_version';
	public const DB_TARGET_VERSION = '008';

	public const SERIAL_PREFIX_DEFAULT       = 'CERT';
	public const SERIAL_LENGTH_DEFAULT       = 8;
	public const SERIAL_SUFFIX_DEFAULT       = '';
	public const SERIAL_RESET_PERIOD_DEFAULT = 'none';

	public const QR_SIZE_DEFAULT             = 15;
	public const QR_POSITION_X_DEFAULT       = 250.0;
	public const QR_POSITION_Y_DEFAULT       = 180.0;
	public const QR_ERROR_CORRECTION_DEFAULT = 'L';

	public const EXPIRATION_UNIT_DEFAULT  = 'never';
	public const EXPIRATION_VALUE_DEFAULT = 0;

	public const EMAIL_RATE_LIMIT_DEFAULT  = 60;
	public const EMAIL_RATE_WINDOW_DEFAULT = 3600;

	public const API_VERIFY_RATE_LIMIT  = 60;
	public const API_VERIFY_RATE_WINDOW = 3600;

	public const QR_CLEANUP_DAYS     = 7;
	public const ARCHIVE_AFTER_YEARS = 5;

	public const CERTIFICATE_GENERATED_ACTION = 'certificate_generated';

	public const CAPABILITY_MANAGE     = 'manage_options';
	public const CAPABILITY_EDIT_POSTS = 'edit_posts';

	// ── Queue processing (mirrors defines in certificate-generator.php) ──────────
	public const QUEUE_BATCH_SIZE     = 50;
	public const QUEUE_STALE_MINUTES  = 10;
	public const QUEUE_MAX_ATTEMPTS   = 3;
	public const QUEUE_RUNTIME_BUDGET = 20; // seconds

	// ── v8 feature flags (default OFF; flip via wp-config define to enable) ──────
	// CG_USE_NEW_PDF        — route generate_certificate_pdf* through PdfGenerator
	// CG_USE_NEW_ZIP        — route cg_build_certificate_zip through ZipService
	// CG_USE_REPOSITORIES   — route DB reads/writes through Repository layer
	// CG_USE_DTO            — wrap CertificateData/EmailData value objects
	// CG_USE_EVENTS         — fire do_action('cg_email_sent') + listeners
	// The integration flags below are read by the Pro add-on (certificate-generator-pro):
	// CG_USE_TUTOR_LMS_INTEGRATION — auto-issue certificates on Tutor LMS course/quiz completion
	// CG_USE_LEARNDASH_INTEGRATION — auto-issue certificates on LearnDash course completion
	// CG_USE_LIFTERLMS_INTEGRATION — auto-issue certificates on LifterLMS course completion
	// CG_USE_LEARNPRESS_INTEGRATION — auto-issue certificates on LearnPress course completion
	// CG_USE_SENSEI_INTEGRATION    — auto-issue certificates on Sensei LMS course completion
	// CG_USE_WOOCOMMERCE_INTEGRATION — auto-issue certificates on WooCommerce order completion
	// CG_USE_BADGES         — generate a companion badge PNG alongside each certificate PDF
	// CG_USE_RENEWAL_REMINDERS — send recipient-facing expiry reminder emails (see CG_Cron_Jobs)
	// ── debug flags (default OFF; flip via wp-config to enable profiling) ─────────
	// CG_DEBUG_PDF_TIME     — log PDF generation time to uploads/cg-debug/
	// CG_DEBUG_QUERY_TIME   — log repository query counts/times
	// CG_DEBUG_SERVICES     — log service dispatch trace

	/**
	 * Check whether a v8 feature flag or debug flag is enabled.
	 *
	 * Flags default to OFF. Enable by adding e.g.:
	 *   define( 'CG_USE_NEW_PDF', true );
	 * in wp-config.php or at the top of certificate-generator.php.
	 *
	 * The Tools tab's "Features & Integrations" UI can also set a flag via the
	 * `cg_feature_toggles` option, keyed by the same constant name — when a
	 * flag has been explicitly toggled there, that value wins over any
	 * wp-config constant (only flags the UI actually exposes are ever present
	 * in that option, so unrelated flags like CG_USE_NEW_PDF are unaffected).
	 *
	 * @param string $name  Flag constant name, e.g. 'CG_USE_NEW_PDF'.
	 */
	public static function flag( string $name ): bool {
		$overrides = get_option( 'cg_feature_toggles', array() );
		if ( is_array( $overrides ) && array_key_exists( $name, $overrides ) ) {
			return (bool) $overrides[ $name ];
		}
		return defined( $name ) && (bool) constant( $name );
	}

	/**
	 * Queue batch size — prefers runtime define, falls back to class constant.
	 */
	public static function queueBatchSize(): int {
		return defined( 'CG_QUEUE_BATCH_SIZE' ) ? (int) CG_QUEUE_BATCH_SIZE : self::QUEUE_BATCH_SIZE;
	}

	/**
	 * Minutes before a 'sending' row is reclaimed as stale.
	 */
	public static function queueStaleMinutes(): int {
		return defined( 'CG_QUEUE_STALE_MINUTES' ) ? (int) CG_QUEUE_STALE_MINUTES : self::QUEUE_STALE_MINUTES;
	}

	/**
	 * Maximum send attempts before a queue row is marked 'failed'.
	 */
	public static function maxAttempts(): int {
		return defined( 'CG_QUEUE_MAX_ATTEMPTS' ) ? (int) CG_QUEUE_MAX_ATTEMPTS : self::QUEUE_MAX_ATTEMPTS;
	}

	/**
	 * Max seconds a single batch run may spend before breaking to let cron resume.
	 */
	public static function runtimeBudget(): int {
		return defined( 'CG_QUEUE_RUNTIME_BUDGET' ) ? (int) CG_QUEUE_RUNTIME_BUDGET : self::QUEUE_RUNTIME_BUDGET;
	}

	public static function get( string $key, $default = null ) {
		$constants = array(
			'version'             => self::VERSION,
			'db_version'          => self::DB_TARGET_VERSION,
			'serial_prefix'       => self::SERIAL_PREFIX_DEFAULT,
			'serial_length'       => self::SERIAL_LENGTH_DEFAULT,
			'qr_size'             => self::QR_SIZE_DEFAULT,
			'qr_error_correction' => self::QR_ERROR_CORRECTION_DEFAULT,
			'email_rate_limit'    => self::EMAIL_RATE_LIMIT_DEFAULT,
			'api_rate_limit'      => self::API_VERIFY_RATE_LIMIT,
		);

		return $constants[ $key ] ?? $default;
	}
}
