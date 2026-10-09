<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Bulk Send dry run: problems worth seeing before anything is queued.
 *
 *  - invalid_email:  an address is present but is_email() rejects it, so the send step drops it.
 *  - duplicate_rows: extra copies of the same certificate (same address, name, type and date).
 *  - long_names:     names that don't fit their field even at the smallest size the
 *                    generator uses (certificate_generator_fit_font_size(): 60%), so they will wrap.
 *
 * Measures with the same template, font, size and field width as PDF generation.
 */
class PreSendCheck {

	private const NAME_FIELD = array(
		'students' => 'student_name',
		'teachers' => 'teacher_name',
		'schools'  => 'school_name',
	);

	/** @var array<string,?array{pdf:object,font:string,size:float,width:float}> */
	private static array $specs = array();

	/**
	 * @param array<int,array<string,mixed>> $rows Recipient-set rows (name, email, certificate_type, issue_date, post_type).
	 * @return array{invalid_email:int,duplicate_rows:int,long_names:int}
	 */
	public static function summarize( array $rows ): array {
		$out  = array( 'invalid_email' => 0, 'duplicate_rows' => 0, 'long_names' => 0 );
		$seen = array();
		foreach ( $rows as $r ) {
			$email = trim( (string) ( $r['email'] ?? '' ) );
			$name  = (string) ( $r['name'] ?? '' );
			$type  = (string) ( $r['certificate_type'] ?? '' );

			if ( '' !== $email && ! is_email( $email ) ) {
				++$out['invalid_email'];
			}

			$key = strtolower( $email ) . '|' . strtolower( trim( $name ) ) . '|' . strtolower( $type ) . '|' . ( $r['issue_date'] ?? '' );
			if ( isset( $seen[ $key ] ) ) {
				++$out['duplicate_rows'];
			}
			$seen[ $key ] = true;

			if ( '' !== $name && self::name_too_long( $name, $type, (string) ( $r['issue_date'] ?? '' ), (string) ( $r['post_type'] ?? 'students' ) ) ) {
				++$out['long_names'];
			}
		}
		return $out;
	}

	public static function name_too_long( string $name, string $type, string $issue_date, string $entity ): bool {
		$spec = self::spec( $type, $issue_date, self::NAME_FIELD[ $entity ] ?? 'student_name' );
		if ( ! $spec ) {
			return false; // no template / field: generation reports that itself
		}
		$text = mb_convert_encoding( $name, 'ISO-8859-1', 'UTF-8' ); // as drawn
		return $spec['pdf']->GetStringWidth( $text ) > $spec['width'];
	}

	/** Template font, floor size and field width for one type + date, measured once per request. */
	private static function spec( string $type, string $issue_date, string $field ): ?array {
		$cache_key = $type . '|' . $issue_date . '|' . $field;
		if ( array_key_exists( $cache_key, self::$specs ) ) {
			return self::$specs[ $cache_key ];
		}
		self::$specs[ $cache_key ] = null;

		if ( ! function_exists( 'certificate_generator_select_certificate_template' ) || ! class_exists( '\CertificateGenerator_FontManager' ) ) {
			return null;
		}
		$tpl = certificate_generator_select_certificate_template( $type, $issue_date );
		if ( ! $tpl || ! isset( $tpl->id ) ) {
			return null;
		}

		// Same meta shape certificate_generator_generate_pdf_impl() builds: row columns + extra_fields JSON.
		$meta = array();
		foreach ( (array) $tpl as $k => $v ) {
			if ( null !== $v && '' !== $v ) {
				$meta[ $k ] = array( $v );
			}
		}
		foreach ( (array) json_decode( (string) ( $tpl->extra_fields ?? '' ), true ) as $k => $v ) {
			$meta[ $k ] = array( $v );
		}

		$fields    = certificate_generator_resolve_template_fields( $meta, $type, (int) ( $meta['template_field_count'][0] ?? 3 ) );
		$positions = certificate_generator_build_field_positions( $fields, $meta, true );
		if ( empty( $positions[ $field ] ) || ( $positions[ $field ]['type'] ?? 'text' ) !== 'text' ) {
			return null;
		}

		$font  = (string) ( $meta['font_style'][0] ?? 'helvetica' );
		$floor = max( 8.0, (float) ( $meta['font_size'][0] ?? 12 ) * 0.6 ); // certificate_generator_fit_font_size()'s floor
		$pdf   = \CertificateGenerator_FontManager::create_pdf_instance( 'L', 'mm', 'A4', $font );
		\CertificateGenerator_FontManager::getInstance()->add_font_to_pdf( $pdf, $font, 'B', $floor );

		return self::$specs[ $cache_key ] = array(
			'pdf'   => $pdf,
			'font'  => $font,
			'size'  => $floor,
			'width' => (float) $positions[ $field ]['width'],
		);
	}
}
