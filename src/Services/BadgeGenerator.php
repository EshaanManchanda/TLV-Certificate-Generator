<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * Renders a shareable badge PNG for a certificate, using GD (bundled with PHP —
 * no new dependency) to overlay the recipient's name onto a template's optional
 * badge background image.
 *
 * ponytail: recipient name is drawn with GD's built-in bitmap font (imagestring),
 * not a real typeface — upgrade to imagettftext() with a bundled TTF if nicer
 * badge typography is ever needed.
 */
class BadgeGenerator {

	public static function generate( array $cert, string $badge_template_url ): ?string {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return null; // GD extension not available
		}

		$template_path = certificate_generator_template_url_to_path( $badge_template_url );
		if ( ! file_exists( $template_path ) ) {
			return null;
		}

		$image = self::load_image( $template_path );
		if ( ! $image ) {
			return null;
		}

		$width      = imagesx( $image );
		$height     = imagesy( $image );
		$text_color = imagecolorallocate( $image, 255, 255, 255 );

		$name        = (string) ( $cert['recipient_name'] ?? '' );
		$font_size   = 5; // GD built-in font, sizes 1-5
		$font_width  = imagefontwidth( $font_size );
		$font_height = imagefontheight( $font_size );
		$text_x      = max( 0, (int) ( ( $width - strlen( $name ) * $font_width ) / 2 ) );
		$text_y      = max( 0, (int) ( $height * 0.75 - $font_height / 2 ) );
		imagestring( $image, $font_size, $text_x, $text_y, $name, $text_color );

		$upload_dir = wp_upload_dir();
		$badge_dir  = $upload_dir['basedir'] . '/cg-badges';
		wp_mkdir_p( $badge_dir );

		$identifier = $cert['serial_number'] ?: ( 'cert-' . ( $cert['id'] ?? uniqid() ) );
		$filename   = 'badge_' . sanitize_file_name( (string) $identifier ) . '.png';
		$filepath   = $badge_dir . '/' . $filename;

		$saved = imagepng( $image, $filepath );
		imagedestroy( $image );

		if ( ! $saved ) {
			return null;
		}

		return $upload_dir['baseurl'] . '/cg-badges/' . $filename;
	}

	/** @return \GdImage|resource|false */
	private static function load_image( string $path ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'png':
				return @imagecreatefrompng( $path );
			case 'jpg':
			case 'jpeg':
				return @imagecreatefromjpeg( $path );
			case 'gif':
				return @imagecreatefromgif( $path );
			default:
				return false;
		}
	}
}
