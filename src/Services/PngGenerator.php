<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Renders the actual certificate design (background + fields + QR + serial)
 * as a PNG, using GD (bundled with PHP, already relied on by BadgeGenerator —
 * no new dependency, no Ghostscript/Imagick delegate requirement that many
 * shared WP hosts disable).
 *
 * Field positions are built via the same certificate_generator_build_field_positions() used by
 * both PDF-generation code paths in certificate-search.php, so a field's
 * placement can't drift between the PDF and PNG output. Template/font/QR
 * resolution here mirrors certificate_generator_generate_pdf_impl()'s setup — that boilerplate
 * (reading tmpl_meta, picking a template) is stable, low-risk WP data access,
 * unlike the position math, so it's duplicated rather than forcing a riskier
 * refactor of the production PDF path just for this.
 *
 * ponytail: FPDF/tFPDF and GD's imagettftext() don't measure/wrap text
 * identically, so output won't be pixel-identical to the PDF at the same
 * field width — same positions, good-enough layout, not pixel-perfect parity.
 * Generated on-demand only, not persisted (no png_path column/tracking).
 */
class PngGenerator {

	private const DPI = 300;

	public static function generate( $post_id, array $fields, $student_data = null ): ?string {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return null; // GD extension not available
		}

		$use_table_data = is_array( $student_data ) && ! empty( $student_data );

		if ( ! $use_table_data && $post_id > 0
			&& class_exists( '\CertificateGenerator\Database\CustomTables' )
		) {
			global $wpdb;
			$tables      = \CertificateGenerator\Database\CustomTables::instance();
			$entity_type = get_post_type( $post_id ) ?: '';
			if ( ! in_array( $entity_type, array( 'students', 'teachers', 'schools' ), true ) ) {
				$entity_type = 'students';
			}
			$entity_table = $tables->get_table( $entity_type );
			if ( $entity_table
				&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entity_table ) ) === $entity_table
			) {
				$sql_row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM $entity_table WHERE wp_post_id = %d LIMIT 1", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					\ARRAY_A
				);
				if ( ! empty( $sql_row ) ) {
					$student_data   = $sql_row;
					$use_table_data = true;
				}
			}
		}

		if ( $use_table_data ) {
			$certificate_type = $student_data['certificate_type'] ?? '';
			$issue_date_iso   = $student_data['issue_date'] ?? '';
		} else {
			$certificate_type = get_post_meta( $post_id, 'certificate_type', true );
			$issue_date_iso   = get_post_meta( $post_id, 'issue_date', true );
		}

		if ( ! $certificate_type ) {
			return null;
		}

		$certificate_template = \certificate_generator_select_certificate_template( $certificate_type, $issue_date_iso, ! $use_table_data );
		if ( ! $certificate_template ) {
			return null;
		}

		$is_sql_table = isset( $certificate_template->id ) && ! isset( $certificate_template->ID );
		if ( $is_sql_table ) {
			$tmpl_meta = array();
			foreach ( $certificate_template as $key => $value ) {
				if ( $value !== null && $value !== '' ) {
					$tmpl_meta[ $key ] = array( $value );
				}
			}
			if ( ! empty( $certificate_template->extra_fields ) ) {
				$extra = json_decode( $certificate_template->extra_fields, true );
				if ( is_array( $extra ) ) {
					foreach ( $extra as $k => $v ) {
						$tmpl_meta[ $k ] = array( $v );
					}
				}
			}
		} else {
			$tmpl_meta = get_post_meta( $certificate_template->ID );
		}

		$template_url = $tmpl_meta['template_url'][0] ?? '';
		if ( ! $template_url ) {
			return null;
		}

		$orientation = $tmpl_meta['orientation'][0] ?? $tmpl_meta['template_orientation'][0] ?? 'landscape';
		$font_size   = (float) ( $tmpl_meta['font_size'][0] ?? 12 );
		$font_color  = $tmpl_meta['font_color'][0] ?? '#000000';
		$font_style  = $tmpl_meta['font_style'][0] ?? 'helvetica';

		$page_w_mm = $orientation === 'landscape' ? 297 : 210;
		$page_h_mm = $orientation === 'landscape' ? 210 : 297;
		$px_per_mm = self::DPI / 25.4;
		$width_px  = (int) round( $page_w_mm * $px_per_mm );
		$height_px = (int) round( $page_h_mm * $px_per_mm );

		$bg_path = \certificate_generator_template_url_to_path( $template_url );
		$canvas  = self::load_image( $bg_path );
		if ( ! $canvas ) {
			return null;
		}
		$canvas = self::resize_to( $canvas, $width_px, $height_px );

		$photo_url = $use_table_data ? (string) ( $student_data['photo_url'] ?? '' ) : '';

		// Resolve the field list ourselves — mirrors certificate_generator_generate_pdf_impl(), which
		// also ignores its caller-supplied $fields — so entity-aware per-slot
		// mapping applies to PNG output the same as PDF, not just whatever list
		// the one caller (bulk-download.php) happened to build independently.
		$template_field_count = (int) ( $tmpl_meta['template_field_count'][0] ?? 3 );
		$fields               = \certificate_generator_resolve_template_fields( $tmpl_meta, $certificate_type, $template_field_count );

		$field_positions = \certificate_generator_build_field_positions( $fields, $tmpl_meta, $is_sql_table, $photo_url );

		// Mirror certificate_generator_generate_pdf_impl()'s $post_data build: per-field text values from
		// the SQL row or post meta, with issue_date reformatted to dd-mm-yyyy.
		$post_data = array();
		foreach ( $fields as $field_name ) {
			$value = $use_table_data ? ( $student_data[ $field_name ] ?? '' ) : get_post_meta( $post_id, $field_name, true );
			if ( $value === '' || $value === null ) {
				continue;
			}
			if ( $field_name === 'issue_date' ) {
				$date_obj = \DateTime::createFromFormat( 'Y-m-d', $value ) ?: date_create( $value );
				if ( $date_obj ) {
					$value = $date_obj->format( 'd-m-Y' );
				}
			}
			$post_data[ $field_name ] = $value;
		}

		$text_color = self::allocate_hex_color( $canvas, $font_color );
		$ttf_path   = \CertificateGenerator_FontManager::getInstance()->get_ttf_path_for_gd( $font_style );

		foreach ( $field_positions as $field => $position ) {
			if ( ( $position['visible'] ?? '1' ) === '0' ) {
				continue;
			}

			$x = is_numeric( $position['x'] ) ? (float) $position['x'] : null;
			$y = is_numeric( $position['y'] ) ? (float) $position['y'] : null;
			if ( $x === null || $y === null ) {
				continue; // no fallback centering here — same ceiling as documented above
			}
			$px_x = $x * $px_per_mm;
			$px_y = $y * $px_per_mm;

			if ( in_array( $position['type'] ?? 'text', array( 'image', 'photo' ), true ) ) {
				if ( ! empty( $position['image_url'] ) ) {
					$overlay = self::load_image( \certificate_generator_template_url_to_path( $position['image_url'] ) );
					if ( $overlay ) {
						$w_px = (float) $position['width'] * $px_per_mm;
						// Explicit height when set (matches the PDF path); else keep the
						// source image's own aspect ratio, as before.
						$h_px = is_numeric( $position['height'] ?? '' ) && (float) $position['height'] > 0
							? (float) $position['height'] * $px_per_mm
							: $w_px * ( imagesy( $overlay ) / max( 1, imagesx( $overlay ) ) );
						imagecopyresampled( $canvas, $overlay, (int) $px_x, (int) $px_y, 0, 0, (int) $w_px, (int) $h_px, imagesx( $overlay ), imagesy( $overlay ) );
						imagedestroy( $overlay );
					}
				}
				continue;
			}

			$text = (string) ( $post_data[ $field ] ?? '' );
			if ( $text === '' || ! $ttf_path ) {
				continue;
			}

			$size_px = $font_size * ( self::DPI / 72 ); // font_size is in points, same unit FPDF uses
			$box     = imagettfbbox( $size_px, 0, $ttf_path, $text );
			$text_w  = abs( $box[2] - $box[0] );

			$align   = strtoupper( (string) ( $position['align'] ?? 'C' ) );
			$field_w = (float) $position['width'] * $px_per_mm;
			$draw_x  = $px_x;
			if ( $align === 'C' ) {
				$draw_x = $px_x + max( 0, ( $field_w - $text_w ) / 2 );
			} elseif ( $align === 'R' ) {
				$draw_x = $px_x + max( 0, $field_w - $text_w );
			}

			imagettftext( $canvas, $size_px, 0, (int) $draw_x, (int) ( $px_y + $size_px ), $text_color, $ttf_path, $text );
		}

		// QR code — reuse the same PNG the PDF path draws, no separate QR system.
		if ( class_exists( 'CertificateGenerator_QR_Code_Generator' ) && ( $tmpl_meta['qr_enabled'][0] ?? '' ) === '1' ) {
			$qr_generator = \CertificateGenerator_QR_Code_Generator::get_instance();
			$serial       = self::resolve_display_serial( $post_id, $student_data, $use_table_data );
			$qr_data      = $qr_generator->generate_qr_data( array_merge( is_array( $student_data ) ? $student_data : array(), array( 'certificate_type' => $certificate_type ) ), $serial );
			$qr_size_px   = (int) round( max( 10, min( 50, (float) ( $tmpl_meta['qr_size'][0] ?? 15 ) ) ) * $px_per_mm );
			$qr_path      = $qr_generator->generate_qr_image( $qr_data, $qr_size_px, $tmpl_meta['qr_error_correction'][0] ?? 'L' );
			if ( $qr_path ) {
				$qr_img = self::load_image( $qr_path );
				if ( $qr_img ) {
					$qr_x = (float) ( $tmpl_meta['qr_position_x'][0] ?? 250 ) * $px_per_mm;
					$qr_y = (float) ( $tmpl_meta['qr_position_y'][0] ?? 180 ) * $px_per_mm;
					imagecopyresampled( $canvas, $qr_img, (int) $qr_x, (int) $qr_y, 0, 0, $qr_size_px, $qr_size_px, imagesx( $qr_img ), imagesy( $qr_img ) );
					imagedestroy( $qr_img );
				}
			}

			if ( $serial && ( $tmpl_meta['serial_number_display'][0] ?? '' ) === '1' && $ttf_path ) {
				$sn_text    = 'Serial: ' . $serial;
				$sn_size_px = (float) ( $tmpl_meta['serial_number_font_size'][0] ?? 10 ) * ( self::DPI / 72 );
				$sn_box     = imagettfbbox( $sn_size_px, 0, $ttf_path, $sn_text );
				$sn_width   = abs( $sn_box[2] - $sn_box[0] );
				$sn_x       = ( (float) ( $tmpl_meta['serial_number_position_x'][0] ?? 105 ) * $px_per_mm ) - ( $sn_width / 2 );
				$sn_y       = (float) ( $tmpl_meta['serial_number_position_y'][0] ?? 200 ) * $px_per_mm;
				imagettftext( $canvas, $sn_size_px, 0, (int) $sn_x, (int) $sn_y, self::allocate_hex_color( $canvas, '#000000' ), $ttf_path, $sn_text );
			}
		}

		$upload_dir = wp_upload_dir();
		$suffix     = $use_table_data && ! empty( $student_data['id'] ) ? $student_data['id'] : ( $post_id ?: uniqid() );
		$filename   = 'certificate_' . sanitize_file_name( (string) $suffix ) . '.png';
		$filepath   = $upload_dir['path'] . '/' . $filename;

		$saved = imagepng( $canvas, $filepath );
		imagedestroy( $canvas );

		if ( ! $saved ) {
			return null;
		}

		return $upload_dir['url'] . '/' . $filename;
	}

	private static function resolve_display_serial( $post_id, $student_data, bool $use_table_data ): string {
		if ( $use_table_data && ! empty( $student_data['serial_number'] ) ) {
			return (string) $student_data['serial_number'];
		}
		if ( ! $use_table_data && $post_id > 0 ) {
			return (string) get_post_meta( $post_id, 'serial_number', true );
		}
		return '';
	}

	/** @return \GdImage|resource|false */
	private static function load_image( string $path ) {
		if ( ! $path || ! file_exists( $path ) ) {
			return false;
		}
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'png':
				return @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			case 'jpg':
			case 'jpeg':
				return @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			case 'gif':
				return @imagecreatefromgif( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			case 'webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			default:
				return false;
		}
	}

	/** @param \GdImage|resource $image @return \GdImage|resource */
	private static function resize_to( $image, int $width, int $height ) {
		$resized = imagecreatetruecolor( $width, $height );
		imagecopyresampled( $resized, $image, 0, 0, 0, 0, $width, $height, imagesx( $image ), imagesy( $image ) );
		imagedestroy( $image );
		return $resized;
	}

	/** @param \GdImage|resource $image */
	private static function allocate_hex_color( $image, string $hex ): int {
		$rgb = sscanf( $hex, '#%02x%02x%02x' );
		if ( ! $rgb || count( $rgb ) < 3 ) {
			$rgb = array( 0, 0, 0 );
		}
		return imagecolorallocate( $image, $rgb[0], $rgb[1], $rgb[2] );
	}
}
