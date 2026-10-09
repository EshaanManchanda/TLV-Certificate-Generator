<?php
declare(strict_types=1);

namespace CertificateGenerator\Helpers;

/**
 * Central date conversion utility.
 *
 * Rule:
 *  - Storage  → always Y-m-d   (ISO 8601, required by MySQL DATE columns + correct ORDER BY)
 *  - Display  → always d-m-Y   (user-facing: admin lists, emails, certificates, CSV export)
 *  - HTML5    → always Y-m-d   (browser <input type="date"> requires this exact format)
 */
class DateHelper {

	const DISPLAY_FORMAT = 'd-m-Y';
	const STORAGE_FORMAT = 'Y-m-d';

	/** Ordered list of formats accepted on input (admin form, CSV, API). */
	const INPUT_FORMATS = array( 'Y-m-d', 'd-m-Y', 'm/d/Y', 'Y/m/d', 'd/m/Y' );

	/**
	 * Parse any common date string to Y-m-d for DB storage.
	 * Returns null if the value is empty or cannot be parsed.
	 */
	public static function to_storage( ?string $date ): ?string {
		if ( empty( $date ) ) {
			return null;
		}

		foreach ( self::INPUT_FORMATS as $fmt ) {
			$dt = \DateTime::createFromFormat( $fmt, $date );
			// Strict check: the round-trip must reproduce the original string
			if ( $dt && $dt->format( $fmt ) === $date ) {
				return $dt->format( self::STORAGE_FORMAT );
			}
		}

		// Fallback: PHP's general date parser (handles "March 23, 2026" etc.)
		$ts = strtotime( $date );
		return $ts !== false ? gmdate( self::STORAGE_FORMAT, $ts ) : null;
	}

	/**
	 * Convert a stored Y-m-d date to d-m-Y for display.
	 * Passes through non-Y-m-d strings unchanged so existing data never silently breaks.
	 */
	public static function to_display( ?string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}
		$dt = \DateTime::createFromFormat( self::STORAGE_FORMAT, $date );
		return $dt ? $dt->format( self::DISPLAY_FORMAT ) : $date;
	}

	/**
	 * Convert a stored Y-m-d date to Y-m-d for HTML5 <input type="date">.
	 * Returns '' for empty/invalid so the field renders blank, not broken.
	 */
	public static function to_html5( ?string $date ): string {
		if ( empty( $date ) ) {
			return '';
		}
		// If already Y-m-d pass through; otherwise attempt to_storage first
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		return self::to_storage( $date ) ?? '';
	}
}
