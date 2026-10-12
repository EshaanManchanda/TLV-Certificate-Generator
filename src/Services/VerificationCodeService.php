<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Unguessable verification codes for certificates.
 *
 * Serials are sequential, so verifying by serial alone lets anyone walk the range and
 * collect names. The code is 12 Crockford Base32 characters: 11 taken from an HMAC of
 * the serial (about 55 bits) plus one check character. Being derived from the serial,
 * every render path (QR, PDF cache key, records) gets the same code without passing it
 * around, and old certificates can be backfilled. The secret lives in its own option,
 * not wp_salt(), so rotating WordPress salts never breaks printed QR codes.
 */
final class VerificationCodeService {

	public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	public const LENGTH   = 12;
	private const OPTION  = 'certificate_generator_verification_secret';

	/** Code for a serial ('' when the serial is empty). */
	public static function for_serial( string $serial, ?string $secret = null ): string {
		if ( '' === $serial ) {
			return '';
		}
		$hash = hash_hmac( 'sha256', $serial, $secret ?? self::secret(), true );
		$body = '';
		for ( $i = 0; $i < self::LENGTH - 1; $i++ ) {
			$body .= self::ALPHABET[ ord( $hash[ $i ] ) & 31 ]; // 256 % 32 == 0, so no bias.
		}
		return $body . self::check_char( $body );
	}

	/**
	 * Weighted sum mod 32 with odd weights: odd weights are coprime with 32, so any
	 * single mistyped character changes the check character.
	 */
	public static function check_char( string $body ): string {
		$sum = 0;
		$len = strlen( $body );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += strpos( self::ALPHABET, $body[ $i ] ) * ( 2 * $i + 1 );
		}
		return self::ALPHABET[ $sum % 32 ];
	}

	/** Uppercase, drop spaces/dashes/dots/underscores, map look-alikes (I,L→1, O→0). */
	public static function normalize( string $input ): string {
		$code = strtoupper( (string) preg_replace( '/[\s\-_.]+/', '', $input ) );
		return strtr( $code, 'ILO', '110' );
	}

	/** True when $code (already normalized) has the right length, alphabet and check character. */
	public static function is_well_formed( string $code ): bool {
		if ( strlen( $code ) !== self::LENGTH || strspn( $code, self::ALPHABET ) !== self::LENGTH ) {
			return false;
		}
		return self::check_char( substr( $code, 0, -1 ) ) === substr( $code, -1 );
	}

	/** XXXX-XXXX-XXXX for display. */
	public static function format( string $code ): string {
		return implode( '-', str_split( $code, 4 ) );
	}

	private static function secret(): string {
		$secret = (string) get_option( self::OPTION, '' );
		if ( '' === $secret ) {
			// add_option is a no-op if another request created it first; re-read to use the winner.
			add_option( self::OPTION, wp_generate_password( 64, true, true ) );
			$secret = (string) get_option( self::OPTION );
		}
		return $secret;
	}
}
