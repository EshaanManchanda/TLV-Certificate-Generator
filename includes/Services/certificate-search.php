<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include FPDF library (add this library in your plugin directory)
require_once CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/fpdf.php';
require_once __DIR__ . '/../Core/font-manager.php';


/**
 * Resolve customizable shortcode text: an explicit shortcode attribute wins,
 * then the admin-configured default (certificate_generator_shortcode_text option), then the
 * hardcoded fallback. Empty values at any level fall through to the next.
 */
function certificate_generator_get_shortcode_text( $key, $atts_value, $default ) {
	if ( ! empty( $atts_value ) ) {
		return $atts_value;
	}
	$saved = get_option( 'certificate_generator_shortcode_text', array() );
	return ! empty( $saved[ $key ] ) ? $saved[ $key ] : $default;
}

// Function to convert hex color to RGB string
function certificate_generator_hex2rgb_str( $hex ) {
	$hex = str_replace( '#', '', $hex );

	if ( strlen( $hex ) == 3 ) {
		$r = hexdec( substr( $hex, 0, 1 ) . substr( $hex, 0, 1 ) );
		$g = hexdec( substr( $hex, 1, 1 ) . substr( $hex, 1, 1 ) );
		$b = hexdec( substr( $hex, 2, 1 ) . substr( $hex, 2, 1 ) );
	} else {
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
	}

	return "$r, $g, $b";
}

// tFPDF (Unicode-capable, needed to embed a custom uploaded .ttf directly —
// see CertificateGenerator_FontManager::create_pdf_instance()) shares FPDF's
// protected internals (_out()/_put()/_newobj()/etc.), so the debug-drawing
// extras below work unmodified on either engine via this trait.
// tFPDF defaults to looking for bundled font definition files (helvetica.php,
// etc.) under lib/tfpdf/font/, which only has the unicode subset — point it at
// the same lib/fpdf/font/ directory the classic engine already uses, so any
// non-custom font requested on a tFPDF instance (e.g. the fallback when a
// custom font's file is missing) can still be found.
if ( ! defined( 'FPDF_FONTPATH' ) ) {
	define( 'FPDF_FONTPATH', CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/font/' );
}
require_once CERTIFICATE_GENERATOR_PATH . 'lib/tfpdf/tfpdf.php';

trait CertificateGenerator_PDF_Debug_Drawing {

	protected $extgstates = array();

	// Method to draw a circle
	function Circle( $x, $y, $r, $style = 'D' ) {
		$this->Ellipse( $x, $y, $r, $r, $style );
	}

	// Method to draw an ellipse
	function Ellipse( $x, $y, $rx, $ry, $style = 'D' ) {
		if ( $style == 'F' ) {
			$op = 'f';
		} elseif ( $style == 'FD' || $style == 'DF' ) {
			$op = 'B';
		} else {
			$op = 'S';
		}

		$lx = 4 / 3 * ( M_SQRT2 - 1 ) * $rx;
		$ly = 4 / 3 * ( M_SQRT2 - 1 ) * $ry;
		$k  = $this->k;
		$h  = $this->h;

		$this->_out(
			sprintf(
				'%.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c',
				( $x + $rx ) * $k,
				( $h - $y ) * $k,
				( $x + $rx ) * $k,
				( $h - ( $y - $ly ) ) * $k,
				( $x + $lx ) * $k,
				( $h - ( $y - $ry ) ) * $k,
				$x * $k,
				( $h - ( $y - $ry ) ) * $k
			)
		);
		$this->_out(
			sprintf(
				'%.2F %.2F %.2F %.2F %.2F %.2F c',
				( $x - $lx ) * $k,
				( $h - ( $y - $ry ) ) * $k,
				( $x - $rx ) * $k,
				( $h - ( $y - $ly ) ) * $k,
				( $x - $rx ) * $k,
				( $h - $y ) * $k
			)
		);
		$this->_out(
			sprintf(
				'%.2F %.2F %.2F %.2F %.2F %.2F c',
				( $x - $rx ) * $k,
				( $h - ( $y + $ly ) ) * $k,
				( $x - $lx ) * $k,
				( $h - ( $y + $ry ) ) * $k,
				$x * $k,
				( $h - ( $y + $ry ) ) * $k
			)
		);
		$this->_out(
			sprintf(
				'%.2F %.2F %.2F %.2F %.2F %.2F c %s',
				( $x + $lx ) * $k,
				( $h - ( $y + $ry ) ) * $k,
				( $x + $rx ) * $k,
				( $h - ( $y + $ly ) ) * $k,
				( $x + $rx ) * $k,
				( $h - $y ) * $k,
				$op
			)
		);
	}

	// Method to set transparency/alpha
	function SetAlpha( $alpha, $bm = 'Normal' ) {
		// Set alpha for stroking and non-stroking operations
		$gs = $this->AddExtGState(
			array(
				'ca' => $alpha,
				'CA' => $alpha,
				'BM' => '/' . $bm,
			)
		);
		$this->SetExtGState( $gs );
	}

	// Add an ExtGState
	function AddExtGState( $parms ) {
		$n                               = count( $this->extgstates ) + 1;
		$this->extgstates[ $n ]['parms'] = $parms;
		return $n;
	}

	// Set an ExtGState
	function SetExtGState( $gs ) {
		$this->_out( sprintf( '/GS%d gs', $gs ) );
	}

	// Initialize extgstates array if needed
	function _enddoc() {
		if ( ! isset( $this->extgstates ) || count( $this->extgstates ) == 0 ) {
			$this->extgstates = array();
		}
		parent::_enddoc();
	}

	// Add ExtGState resources to the PDF
	function _putextgstates() {
		for ( $i = 1; $i <= count( $this->extgstates ); $i++ ) {
			$this->_newobj();
			$this->extgstates[ $i ]['n'] = $this->n;
			$this->_put( '<</Type /ExtGState' );
			$parms = $this->extgstates[ $i ]['parms'];
			$this->_put( sprintf( '/ca %.3F', $parms['ca'] ) );
			$this->_put( sprintf( '/CA %.3F', $parms['CA'] ) );
			$this->_put( '/BM ' . $parms['BM'] );
			$this->_put( '>>' );
			$this->_put( 'endobj' );
		}
	}

	// Override _putresourcedict to include ExtGState resources
	function _putresourcedict() {
		parent::_putresourcedict();
		$this->_put( '/ExtGState <<' );
		foreach ( $this->extgstates as $k => $extgstate ) {
			$this->_put( '/GS' . $k . ' ' . $extgstate['n'] . ' 0 R' );
		}
		$this->_put( '>>' );
	}

	// Override _putresources to include ExtGState resources
	function _putresources() {
		$this->_putextgstates();
		parent::_putresources();
	}
}

class CertificateGenerator_FPDF_Debug extends FPDF {
	use CertificateGenerator_PDF_Debug_Drawing;

	function __construct( $orientation = 'P', $unit = 'mm', $size = 'A4' ) {
		$this->extgstates = array();
		parent::__construct( $orientation, $unit, $size );
	}
}

// Same debug-drawing extras, on the Unicode-capable engine custom fonts require.
class CertificateGenerator_TFPDF_Debug extends tFPDF {
	use CertificateGenerator_PDF_Debug_Drawing;

	function __construct( $orientation = 'P', $unit = 'mm', $size = 'A4' ) {
		$this->extgstates = array();
		parent::__construct( $orientation, $unit, $size );
	}
}

/**
 * FPDF decodes an image on every new document (for an RGBA PNG: split colour from alpha
 * row by row, then re-compress both). A bulk run puts the same template on every PDF, so
 * images registered with share_image() are decoded once per request and reused — the
 * parsed data is exactly what FPDF would compute, so the output is byte-for-byte the same.
 * Per-certificate images (QR codes, photos) are not registered and parse as before.
 */
trait CertificateGenerator_Shared_Image_Parse {

	private static array $cg_shared = array(); // path => true
	private static array $cg_parsed = array(); // path|mtime|size => FPDF image info
	private static int $cg_bytes    = 0;

	public function share_image( string $path ): void {
		self::$cg_shared[ $path ] = true;
	}

	protected function _parsepng( $file ) {
		return $this->cg_parse_shared( $file, 'png' );
	}

	protected function _parsejpg( $file ) {
		return $this->cg_parse_shared( $file, 'jpg' );
	}

	private function cg_parse_shared( string $file, string $type ): array {
		if ( ! isset( self::$cg_shared[ $file ] ) || ! is_file( $file ) ) { // remote URLs parse as before
			return 'png' === $type ? parent::_parsepng( $file ) : parent::_parsejpg( $file );
		}
		$key = $file . '|' . filemtime( $file ) . '|' . filesize( $file );
		if ( isset( self::$cg_parsed[ $key ] ) ) {
			$info = self::$cg_parsed[ $key ];
			if ( isset( $info['smask'] ) ) { // the side effects _parsepngstream() has for alpha images
				$this->WithAlpha = true;
				if ( $this->PDFVersion < '1.4' ) {
					$this->PDFVersion = '1.4';
				}
			}
			return $info;
		}
		$info = 'png' === $type ? parent::_parsepng( $file ) : parent::_parsejpg( $file );
		$size = strlen( $info['data'] ) + strlen( $info['smask'] ?? '' );
		// ponytail: FIFO with a 64 MB ceiling; a run mixing many large templates re-parses some.
		while ( self::$cg_parsed && self::$cg_bytes + $size > 64 * MB_IN_BYTES ) {
			$old              = array_shift( self::$cg_parsed );
			self::$cg_bytes -= strlen( $old['data'] ) + strlen( $old['smask'] ?? '' );
		}
		self::$cg_parsed[ $key ] = $info;
		self::$cg_bytes         += $size;
		return $info;
	}
}

class CertificateGenerator_FPDF extends FPDF {
	use CertificateGenerator_Shared_Image_Parse;
}

class CertificateGenerator_TFPDF extends tFPDF {
	use CertificateGenerator_Shared_Image_Parse;
}

// Function to generate the certificate PDF with custom data
function certificate_generator_validate_template_url( $template_url, $skip_http_check = false ) {
	// Step 1: Check if the URL is non-empty
	if ( ! $template_url ) {
		certificate_generator_debug_log( "Invalid or empty template URL: $template_url" );
		return '<p style="color:red;">Template URL is invalid or missing. Please contact the administrator.</p>';
	}

	// In preview mode skip all remote checks — just confirm a value exists.
	// filter_var(FILTER_VALIDATE_URL) rejects literal apostrophes (RFC 3986),
	// which breaks templates whose filenames contain one (e.g. 'Olympiad-'26-Certificate.png').
	if ( $skip_http_check ) {
		return true;
	}

	// A template on this site is checked on disk: an HTTP request to our own
	// site per certificate is slow and fails when loopback requests are blocked.
	$local_path = certificate_generator_template_url_to_path( $template_url );
	if ( $local_path !== $template_url ) {
		static $valid = array(); // bulk runs validate the same template once per request
		if ( isset( $valid[ $local_path ] ) ) {
			return true;
		}
		if ( false === @getimagesize( $local_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			certificate_generator_debug_log( "Template file is not a valid image: $local_path" );
			return '<p style="color:red;">Template URL is not a valid image file. Please upload a valid image (PNG, JPG, etc.).</p>';
		}
		$valid[ $local_path ] = true;
		return true;
	}

	// Percent-encode characters that are valid in filenames but not in URLs
	// (apostrophe, smart-quotes, spaces) before running strict URL validation.
	$normalized_url = preg_replace_callback(
		"/['\"\x{2018}\x{2019}\x{201C}\x{201D}\s]/u",
		fn( $m ) => rawurlencode( $m[0] ),
		$template_url
	);

	if ( ! filter_var( $normalized_url, FILTER_VALIDATE_URL ) ) {
		certificate_generator_debug_log( "Invalid template URL after normalization: $template_url" );
		return '<p style="color:red;">Template URL is invalid or missing. Please contact the administrator.</p>';
	}

	// Step 2: Check if the URL is accessible (follow redirects)
	$response = wp_remote_get(
		$normalized_url,
		array(
			'timeout'     => 10,
			'redirection' => 5,
		)
	);

	if ( is_wp_error( $response ) ) {
		$error_message = $response->get_error_message();
		certificate_generator_debug_log( "Error accessing template URL: $template_url - $error_message" );
		return '<p style="color:red;">Template URL is inaccessible: ' . esc_html( $error_message ) . '. Please check the URL and try again.</p>';
	}

	// Step 3: Check the final HTTP status code after redirects
	$status_code = wp_remote_retrieve_response_code( $response );
	if ( $status_code !== 200 ) {
		certificate_generator_debug_log( "Template URL returned status code $status_code: $template_url" );

		$status_messages = array(
			301 => 'Template URL is being redirected',
			302 => 'Template URL is being redirected',
			403 => 'Access to the template was forbidden',
			404 => 'Template file was not found',
			500 => 'Server error occurred while accessing template',
		);

		$message = isset( $status_messages[ $status_code ] )
			? $status_messages[ $status_code ]
			: "Template URL is inaccessible (HTTP $status_code)";

		return '<p style="color:red;">' . esc_html( $message ) . '. Please check the file and try again.</p>';
	}

	// Step 4: Validate the content type
	$content_type = wp_remote_retrieve_header( $response, 'content-type' );
	if ( strpos( $content_type, 'image/' ) !== 0 ) {
		certificate_generator_debug_log( "Invalid content type for template URL: $template_url - Content-Type: $content_type" );
		return '<p style="color:red;">Template URL is not a valid image file. Please upload a valid image (PNG, JPG, etc.).</p>';
	}

	return true;
}

/**
 * Calculate X position for text based on alignment
 *
 * @param float  $field_x Original field X position (center point)
 * @param float  $text_width Actual width of the text
 * @param float  $field_width Width of the field boundary
 * @param string $alignment Alignment type (L, C, R)
 * @return float Calculated X position for text placement
 */
function certificate_generator_calculate_x_position( $field_x, $text_width, $field_width, $alignment ) {
	switch ( strtoupper( trim( $alignment ) ) ) {
		case 'L': // Left Align
			return $field_x - ( $field_width / 2 );
		case 'R': // Right Align
			return $field_x + ( $field_width / 2 ) - $text_width;
		case 'C': // Center Align
		default:
			return $field_x - ( $text_width / 2 );
	}
}

/**
 * Convert a WordPress upload URL to a local filesystem path so FPDF can read
 * the image directly without an HTTP round-trip.  Handles apostrophes and other
 * characters that are valid in filenames but break URL validation.
 *
 * Falls back to the original URL when the URL doesn't belong to this site.
 */
function certificate_generator_template_url_to_path( string $url ): string {
	$site_url = site_url();
	// Strip query-string / fragment before path conversion
	$clean_url = strtok( $url, '?#' );
	if ( strpos( $clean_url, $site_url ) === 0 ) {
		$relative = substr( $clean_url, strlen( rtrim( $site_url, '/' ) ) );
		$path     = ABSPATH . ltrim( urldecode( $relative ), '/' );
		if ( file_exists( $path ) ) {
			return $path;
		}
	}
	return $url; // external URL — let FPDF fetch it
}


/**
 * Normalise an alignment value to 'L', 'C', or 'R'.
 * Strips surrounding quote characters that may be present in legacy stored data.
 * Defaults to 'C' for any unrecognised value.
 */
function certificate_generator_sanitize_alignment( string $align ): string {
	$align = strtoupper( trim( $align, " \t\n\r\0\x0B'\"" ) );
	return in_array( $align, array( 'L', 'C', 'R' ), true ) ? $align : 'C';
}

/**
 * Shrink the current font until $text fits $field_width, down to 60% of the template's
 * size (never below 8 pt), and return the size used. Text that still doesn't fit is then
 * wrapped at that size by the caller. A 60-character name in a 100 mm field used to run
 * across the whole page.
 *
 * @param FPDF   $pdf  Font already set at $font_size.
 * @param string $text Text as drawn (already converted for the PDF font).
 */
function certificate_generator_fit_font_size( $pdf, float $font_size, string $text, float $field_width ): float {
	$size = $font_size;
	$min  = max( 8.0, $font_size * 0.6 );
	while ( $field_width > 0 && $size > $min && $pdf->GetStringWidth( $text ) > $field_width ) {
		$size = max( $min, $size - 0.5 );
		$pdf->SetFontSize( $size );
	}
	return $size;
}

/**
 * Wrap text to fit within specified width using actual font metrics
 *
 * @param FPDF   $pdf PDF object with font already set
 * @param string $text Text to wrap
 * @param float  $max_width Maximum width for text
 * @return array Array of text lines
 */
function certificate_generator_wrap_text( $pdf, $text, $max_width ) {
	// (Text arrives already converted for the PDF font; lines are returned as-is.)
	$words        = explode( ' ', $text );
	$lines        = array();
	$current_line = '';

	foreach ( $words as $word ) {
		$test_line  = $current_line . ( $current_line ? ' ' : '' ) . $word;
		$test_width = $pdf->GetStringWidth( $test_line );

		if ( $test_width <= $max_width ) {
			$current_line = $test_line;
		} else {
			// If current line has content, save it and start new line
			if ( $current_line ) {
				$lines[]      = $current_line;
				$current_line = $word;
			} else {
				// Single word is too long, force it anyway
				$lines[]      = $word;
				$current_line = '';
			}
		}
	}

	// Add the last line if it has content
	if ( $current_line ) {
		$lines[] = $current_line;
	}

	return $lines;
}

/**
 * Add debug markers to show text positioning
 *
 * @param FPDF  $pdf PDF object
 * @param float $adjusted_x Calculated text X position
 * @param float $adjusted_y Calculated text Y position
 * @param float $original_x Original field X position
 * @param float $original_y Original field Y position
 * @param bool  $is_primary Whether this is the primary line (for multi-line text)
 */
function certificate_generator_add_debug_markers( $pdf, $adjusted_x, $adjusted_y, $original_x, $original_y, $is_primary = true ) {
	// Green dot for original position (only for primary line)
	if ( $is_primary ) {
		$pdf->SetFillColor( 0, 255, 0 );
		$pdf->Rect( $original_x - 0.5, $original_y - 0.5, 1, 1, 'F' );
	}

	// Blue dot for adjusted position
	$pdf->SetFillColor( 0, 0, 255 );
	$pdf->Rect( $adjusted_x - 0.3, $adjusted_y - 0.3, 0.6, 0.6, 'F' );
}

/**
 * Add debug field boundary visualization
 *
 * @param FPDF  $pdf PDF object
 * @param float $field_x Field center X position
 * @param float $field_y Field center Y position
 * @param float $field_width Field width
 * @param float $field_height Field height
 */
function certificate_generator_add_debug_field_boundary( $pdf, $field_x, $field_y, $field_width, $field_height ) {
	// Save current drawing color
	$pdf->SetDrawColor( 255, 0, 0 );
	$pdf->SetLineWidth( 0.2 );

	// Draw field boundary rectangle
	$rect_x = $field_x - ( $field_width / 2 );
	$rect_y = $field_y - ( $field_height / 2 );
	$pdf->Rect( $rect_x, $rect_y, $field_width, $field_height, 'D' );

	// Reset line width to default
	$pdf->SetLineWidth( 0.2 );
}

/**
 * Absolute path to the cg_certificates upload folder. Created on first call if missing.
 */
function certificate_generator_certificates_dir(): string {
	$dir = wp_upload_dir()['basedir'] . '/cg_certificates';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// No directory listing. (Not a deny-all .htaccess: emails and the verify page link to these PDFs.)
	if ( ! file_exists( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	return $dir;
}

/**
 * File name (no extension) for a certificate's PDF. The id-based key gets a keyed hash
 * suffix so names can't be guessed by counting (certificate_students_row_1000, 1001, …).
 * Stable per key, so the PDF cache and re-downloads still find the same file.
 */
function certificate_generator_certificate_file_stem( string $pdf_key ): string {
	return 'certificate_' . $pdf_key . '_' . substr( hash_hmac( 'sha256', $pdf_key, wp_salt( 'auth' ) ), 0, 12 );
}

/**
 * Public URL to the cg_certificates upload folder.
 */
function certificate_generator_certificates_url(): string {
	return wp_upload_dir()['baseurl'] . '/cg_certificates';
}

// ── PDF cache ────────────────────────────────────────────────────────────────
// A rendered certificate carries "cgk:<md5 of every render input>" in its PDF Keywords.
// When the inputs haven't changed, certificate_generator_generate_pdf_impl() serves the file on disk
// instead of rendering it again. No extra files, no DB column, same URL as before.

// Bump when a change to the render code changes PDF output, so cached PDFs re-render.
if ( ! defined( 'CERTIFICATE_GENERATOR_PDF_RENDER_REV' ) ) {
	define( 'CERTIFICATE_GENERATOR_PDF_RENDER_REV', 3 ); // 2: long text shrinks to fit its field; wrapped accents kept. 3: open-licensed font replacements
}

/** define( 'CERTIFICATE_GENERATOR_DISABLE_PDF_CACHE', true ) in wp-config.php turns the cache off. */
function certificate_generator_pdf_cache_enabled(): bool {
	return (bool) apply_filters( 'certificate_generator_pdf_cache_enabled', ! ( defined( 'CERTIFICATE_GENERATOR_DISABLE_PDF_CACHE' ) && CERTIFICATE_GENERATOR_DISABLE_PDF_CACHE ) );
}

/**
 * Cache key stored in a rendered PDF, or '' when absent/unreadable. FPDF writes the Info
 * dictionary just before the catalog and xref table, so it sits in the file's last bytes.
 */
function certificate_generator_pdf_cache_read_key( string $path ): string {
	$fh = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	if ( ! $fh ) {
		return '';
	}
	if ( 0 !== fseek( $fh, -4096, SEEK_END ) ) {
		rewind( $fh ); // file shorter than 4 KB
	}
	$tail = (string) fread( $fh, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	return preg_match( '/cgk:([a-f0-9]{32})/', $tail, $m ) ? $m[1] : '';
}

/**
 * Hash of everything that shapes a certificate PDF. Image files count by path, mtime and
 * size, so replacing a template image under the same URL re-renders its certificates.
 */
function certificate_generator_pdf_cache_key( string $pdf_key, array $tmpl_meta, string $template_url, array $field_positions, array $post_data, string $serial ): string {
	$images = array();
	foreach ( array_merge( array( $template_url ), array_column( $field_positions, 'image_url' ) ) as $url ) {
		if ( '' !== (string) $url ) {
			$path     = certificate_generator_template_url_to_path( (string) $url );
			$images[] = $path . '|' . ( @filemtime( $path ) ?: 0 ) . '|' . ( @filesize( $path ) ?: 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	$qr = class_exists( 'CertificateGenerator_QR_Code_Generator' ) ? CertificateGenerator_QR_Code_Generator::get_instance()->generate_qr_data( array(), $serial ) : '';
	return md5( (string) wp_json_encode( array( CERTIFICATE_GENERATOR_PDF_RENDER_REV, $pdf_key, $tmpl_meta, $images, $field_positions, $post_data, $serial, $qr ) ) );
}

/**
 * Canonical PDF download filename: {StudentName}_{CertType}_{UniqueId}_certificate.pdf
 * Use this everywhere a certificate file needs a human-readable name (ZIP entries, downloads).
 * Do NOT use for the stored/canonical pdf_path on disk — that uses certificate_{post_id}.pdf.
 */
function certificate_generator_certificate_pdf_filename( string $student_name, string $cert_type, string $unique_id = '' ): string {
	$parts = array_filter(
		array(
			sanitize_file_name( $student_name ),
			sanitize_file_name( $cert_type ),
			$unique_id,
		)
	);
	return implode( '_', $parts ) . '_certificate.pdf';
}

/**
 * Canonical ZIP filename: certificates_{RecipientSlug}_{Timestamp}.zip
 * Use this everywhere multiple certificates are bundled into a ZIP.
 */
function certificate_generator_certificate_zip_filename( string $recipient = '', int $timestamp = 0 ): string {
	$slug = $recipient
		? trim( preg_replace( '/[^a-z0-9]+/', '_', strtolower( $recipient ) ), '_' )
		: 'bulk';
	return 'certificates_' . $slug . '_' . ( $timestamp ?: (int) current_time( 'timestamp' ) ) . '.zip';
}

/**
 * Check whether a known student (in wp_cg_students) has a cert that is pending/scheduled.
 *
 * Returns null  — email unknown (unknown-email path should stay as-is).
 * Returns array — email known but no published cert yet:
 *   [
 *     'students'   => [...rows...],
 *     'state'      => 'scheduled'|'draft'|'none',
 *     'event_date' => 'Y-m-d'|null,
 *   ]
 */
function certificate_generator_get_pending_certificate_info( $email ) {
	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return null;
	}

	$tables    = \CertificateGenerator\Database\CustomTables::instance();
	$stu_table = $tables->get_table( 'students' );
	$tpl_table = $tables->get_table( 'certificate_templates' );

	$stu_exists = $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $stu_table )
	) === $stu_table;

	if ( ! $stu_exists ) {
		return null;
	}

	$students = $GLOBALS['wpdb']->get_results(
		$GLOBALS['wpdb']->prepare( "SELECT * FROM $stu_table WHERE email = %s", $email ),
		ARRAY_A
	);

	if ( empty( $students ) ) {
		return null;
	}

	$tpl_exists = $GLOBALS['wpdb']->get_var(
		$GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $tpl_table )
	) === $tpl_table;

	if ( ! $tpl_exists ) {
		return array(
			'students'   => $students,
			'state'      => 'none',
			'event_date' => null,
		);
	}

	$state      = 'none';
	$event_date = null;

	foreach ( $students as $student ) {
		$cert_type = trim( $student['certificate_type'] ?? '' );
		if ( $cert_type === '' ) {
			continue;
		}

		// Exact match first, then case-insensitive scan.
		$rows = $GLOBALS['wpdb']->get_results(
			$GLOBALS['wpdb']->prepare(
				"SELECT status, event_date FROM $tpl_table
				  WHERE certificate_type = %s
				    AND status IN ('scheduled','draft')
				  ORDER BY status ASC, event_date ASC",
				$cert_type
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			// Case-insensitive fallback.
			$all_pending = $GLOBALS['wpdb']->get_results(
				"SELECT status, event_date, certificate_type FROM $tpl_table
				  WHERE status IN ('scheduled','draft')",
				ARRAY_A
			);
			foreach ( $all_pending as $r ) {
				if ( strtolower( trim( $r['certificate_type'] ) ) === strtolower( $cert_type ) ) {
					$rows[] = $r;
				}
			}
		}

		foreach ( $rows as $r ) {
			$row_state = $r['status'];
			$row_date  = ( ! empty( $r['event_date'] ) && $r['event_date'] !== '0000-00-00' ) ? $r['event_date'] : null;

			// Prefer 'scheduled' over 'draft'.
			if ( $state !== 'scheduled' && $row_state === 'scheduled' ) {
				$state      = 'scheduled';
				$event_date = $row_date;
			} elseif ( $state === 'scheduled' && $row_state === 'scheduled' && $row_date !== null ) {
				// Keep earliest scheduled date.
				if ( $event_date === null || strtotime( $row_date ) < strtotime( $event_date ) ) {
					$event_date = $row_date;
				}
			} elseif ( $state === 'none' && $row_state === 'draft' ) {
				$state = 'draft';
			}
		}
	}

	return array(
		'students'   => $students,
		'state'      => $state,
		'event_date' => $event_date,
	);
}

/**
 * Render the "certificate not ready yet" card for students whose cert is pending.
 */
function certificate_generator_render_pending_certificate_screen( array $pending ): string {
	$options       = get_option( 'certificate_generator_settings_email' );
	$title_color   = $options['title_color'] ?? '#2c3e50';
	$text_color    = $options['text_color'] ?? '#7f8c8d';
	$btn_start     = $options['btn_start'] ?? '#3498db';
	$btn_end       = $options['btn_end'] ?? '#2980b9';
	$border_radius = $options['border_radius'] ?? '12';

	$state      = $pending['state'] ?? 'none';
	$event_date = $pending['event_date'] ?? null;

	// Build body copy based on state.
	if ( $state === 'scheduled' && $event_date !== null ) {
		$today = current_time( 'Y-m-d' );
		if ( $event_date > $today ) {
			$human     = date_i18n( get_option( 'date_format' ), strtotime( $event_date ) );
			$body_html = '<p style="color: ' . $text_color . '; margin-bottom: 15px; line-height: 1.6; font-size: 16px;">'
				. esc_html__( 'We found your registration, but your certificate is not live yet.', 'certificate-generator' )
				. '</p>'
				. '<p style="color: ' . $text_color . '; margin-bottom: 30px; line-height: 1.6; font-size: 16px;">'
				. '<strong>' . esc_html__( 'Expected availability:', 'certificate-generator' ) . '</strong> '
				. esc_html( $human ) . '</p>';
		} else {
			$body_html = '<p style="color: ' . $text_color . '; margin-bottom: 30px; line-height: 1.6; font-size: 16px;">'
				. esc_html__( 'We found your registration. Your certificate is being published — please check back shortly.', 'certificate-generator' )
				. '</p>';
		}
	} else {
		$body_html = '<p style="color: ' . $text_color . '; margin-bottom: 30px; line-height: 1.6; font-size: 16px;">'
			. esc_html__( 'We found your registration, but your certificate is still being prepared. Please check back soon.', 'certificate-generator' )
			. '</p>';
	}

	$output  = '<div class="certificate-not-found" style="max-width: 600px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">';
	$output .= '<div style="padding: 40px; background: #ffffff; border-radius: ' . $border_radius . 'px; '
		. 'box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); text-align: center;">';

	// Clock icon.
	$output .= '<div style="margin-bottom: 25px; animation: pulse 2s infinite;">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" '
		. 'stroke="' . $btn_start . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
		. '<circle cx="12" cy="12" r="10"></circle>'
		. '<polyline points="12 6 12 12 16 14"></polyline>'
		. '</svg></div>';

	$output .= '<h2 style="color: ' . $title_color . '; font-size: 28px; margin: 0 0 15px; font-weight: 700;">'
		. esc_html__( 'Your Certificate Isn\'t Ready Yet', 'certificate-generator' ) . '</h2>';

	$output .= $body_html;

	// Contact-info instructions block (mirrors not-found screen lines 3410–3424).
	$output .= '<p style="color: ' . $text_color . '; margin-bottom: 20px; line-height: 1.6;">'
		. esc_html__( 'If you are unable to find your certificate here, please drop a request email to us on:', 'certificate-generator' ) . ' '
		. certificate_generator_get_contact_email() . ' '
		. esc_html__( 'to resend it over your email.', 'certificate-generator' ) . '</p>
        <div style="margin-bottom: 20px;">
          <p style="font-weight: bold; margin: 0 0 8px; color: ' . $title_color . '; font-size: 16px;">'
		. esc_html__( 'Please include following details in your email:', 'certificate-generator' ) . '</p>
          <ul style="margin: 0; padding-left: 20px; list-style-type: disc; color: ' . $text_color . ';">
            <li style="margin-bottom: 8px;">' . esc_html__( 'Registered Email Id', 'certificate-generator' ) . '</li>
            <li style="margin-bottom: 8px;">' . esc_html__( 'Name', 'certificate-generator' ) . '</li>
            <li style="margin-bottom: 8px;">' . esc_html__( 'Parent Name', 'certificate-generator' ) . '</li>
            <li style="margin-bottom: 8px;">' . esc_html__( 'School', 'certificate-generator' ) . '</li>
            <li style="margin-bottom: 0;">' . esc_html__( 'Grade', 'certificate-generator' ) . '</li>
          </ul>
        </div>';

	// Support section.
	$output .= '<div style="background: linear-gradient(to right, rgba(' . certificate_generator_hex2rgb_str( $btn_start ) . ', 0.05), '
		. 'rgba(' . certificate_generator_hex2rgb_str( $btn_end ) . ', 0.05)); border-radius: ' . $border_radius . 'px; '
		. 'padding: 25px; margin: 25px 0; border-left: 4px solid ' . $btn_start . ';">';
	$output .= '<div style="display: flex; align-items: center; justify-content: center; margin-bottom: 15px;">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" '
		. 'stroke="' . $btn_start . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
		. 'style="margin-right: 10px;">'
		. '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>'
		. '</svg>'
		. '<h3 style="color: ' . $title_color . '; margin: 0; font-size: 18px;">'
		. esc_html__( 'Need Help Finding Your Certificate?', 'certificate-generator' ) . '</h3></div>';
	$output .= '<p style="color: ' . $text_color . '; margin-bottom: 20px; line-height: 1.6;">'
		. esc_html__( 'If you believe this is an error or need assistance, please contact our support team.', 'certificate-generator' ) . '</p>';
	$output .= '<a href="mailto:' . certificate_generator_get_contact_email() . '" '
		. 'style="display: inline-flex; align-items: center; padding: 12px 24px; '
		. 'background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); '
		. 'color: white; text-decoration: none; border-radius: ' . $border_radius . 'px; '
		. 'font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);" '
		. 'onmouseover="this.style.transform=\'translateY(-2px)\'; '
		. 'this.style.boxShadow=\'0 8px 20px rgba(0, 0, 0, 0.15)\'" '
		. 'onmouseout="this.style.transform=\'translateY(0)\'; '
		. 'this.style.boxShadow=\'0 4px 15px rgba(0, 0, 0, 0.1)\'">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" '
		. 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
		. 'stroke-linejoin="round" style="margin-right: 8px;">'
		. '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>'
		. '<polyline points="22,6 12,13 2,6"></polyline>'
		. '</svg>' . esc_html__( 'Contact Support', 'certificate-generator' ) . '</a>';
	$output .= '</div>';

	// Back button.
	$output .= '<a href="' . esc_url( remove_query_arg( 'student_email' ) ) . '" '
		. 'style="display: inline-flex; align-items: center; margin-top: 10px; '
		. 'color: ' . $text_color . '; text-decoration: none; font-weight: 500; transition: color 0.3s ease;" '
		. 'onmouseover="this.style.color=\'' . $btn_start . '\'" '
		. 'onmouseout="this.style.color=\'' . $text_color . '\'">'
		. '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" '
		. 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" '
		. 'stroke-linejoin="round" style="margin-right: 6px;">'
		. '<line x1="19" y1="12" x2="5" y2="12"></line>'
		. '<polyline points="12 19 5 12 12 5"></polyline>'
		. '</svg>' . esc_html__( 'Back to Search', 'certificate-generator' ) . '</a>';

	$output .= '</div>'; // End card.

	$output .= '<style>
        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }
    </style>';

	$output .= '</div>'; // End container.

	return $output;
}

/**
 * Select the best-matching certificate template for a given type and issue date.
 * Uses SQL tables when available, falls back to CPTs.
 *
 * @param string $certificate_type  The certificate type string.
 * @param string $issue_date_iso    Student's issue date in Y-m-d format (optional).
 * @param bool   $strict  Unused, kept for caller compatibility. When an issue date is given the
 *                         template's event_date must match it exactly (or be empty) — never the
 *                         "closest" date, which used to hand out another event's template.
 * @return object|null  Matched template (stdClass with ID, meta), or null if none found.
 */
function certificate_generator_select_certificate_template( $certificate_type, $issue_date_iso = '', $strict = true ) {
	// Try SQL tables first
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables    = \CertificateGenerator\Database\CustomTables::instance();
		$tpl_table = $tables->get_table( 'certificate_templates' );

		if ( $tables->table_exists( 'certificate_templates' ) ) {
			$normalised_issue = '';
			if ( ! empty( $issue_date_iso ) ) {
				$dt = DateTime::createFromFormat( 'Y-m-d', $issue_date_iso );
				if ( $dt ) {
					$normalised_issue = $dt->format( 'Y-m-d' );
				}
			}

			// Get all templates for this type
			certificate_generator_debug_log( "Template query: looking for cert_type='$certificate_type', issue='$normalised_issue'" );

			$candidates = $GLOBALS['wpdb']->get_results(
				$GLOBALS['wpdb']->prepare(
					"SELECT * FROM $tpl_table WHERE certificate_type = %s AND status = 'published' ORDER BY event_date DESC",
					$certificate_type
				),
				ARRAY_A
			);

			certificate_generator_debug_log( 'Found ' . count( $candidates ) . ' templates with exact match' );

			// Case-insensitive fallback
			if ( empty( $candidates ) ) {
				$all = $GLOBALS['wpdb']->get_results( "SELECT * FROM $tpl_table WHERE status = 'published'", ARRAY_A );
				certificate_generator_debug_log( 'Checking ' . count( $all ) . ' templates for case-insensitive match' );
				foreach ( $all as $tpl ) {
					if ( strtolower( trim( $tpl['certificate_type'] ) ) === strtolower( trim( $certificate_type ) ) ) {
						certificate_generator_debug_log( 'Found case-insensitive match: ' . $tpl['certificate_type'] );
						$candidates[] = $tpl;
					}
				}
			}

			if ( empty( $candidates ) ) {
				return null;
			}

			// No issue date to match on — first published template of this type.
			if ( $normalised_issue === '' ) {
				return (object) $candidates[0];
			}

			// certificate_type + event_date must BOTH match. A same-type template for a
			// different event (or one still scheduled/draft — excluded by the status
			// filter above) must never be substituted.
			$undated = null;
			foreach ( $candidates as $tpl ) {
				$ev = substr( trim( $tpl['event_date'] ?? '' ), 0, 10 );
				if ( $ev === $normalised_issue ) {
					return (object) $tpl;
				}
				if ( ( $ev === '' || $ev === '0000-00-00' ) && ! $undated ) {
					$undated = $tpl; // undated template = applies to any event
				}
			}

			return $undated ? (object) $undated : null;
		}
	}

	return null;
}

/**
 * Build raw (un-normalised) per-field position/type data from template meta.
 *
 * Single source of truth for the PDF renderer(s) and the PNG renderer — both
 * read the exact same field slot data (x/y/width/align/visible/type/image_url)
 * from tmpl_meta so they can't drift apart. Values are still raw strings as
 * stored; callers coerce/normalise (and apply their own fallback-position
 * logic, which differs slightly between call sites) afterward.
 *
 * @param array  $fields       Ordered field names to build positions for.
 * @param array  $tmpl_meta    Template meta array (postmeta-shaped: value is an array with [0] holding the stored value).
 * @param bool   $is_sql_table Whether tmpl_meta keys use the SQL-table bare-number format ("1_position_x") vs the CPT format ("field_1_position_x").
 * @param string $photo_url    The student's own photo URL, used when a slot's type is 'photo'.
 * @return array<string,array> field name => array{x:mixed,y:mixed,width:mixed,align:string,visible:mixed,type:string,image_url:string}
 */
/**
 * Resolve the ordered list of field names to render for a template.
 *
 * If the template has explicit per-slot field_{N}_name mappings (set via
 * TemplatesPage.php's entity-scoped field dropdown), use those directly.
 * Otherwise, fall back to the original positional CertificateGenerator_Field_Schema-derived
 * list so templates saved before this feature keep rendering unchanged.
 *
 * @param array<string,array>  $tmpl_meta             Postmeta-shaped template meta.
 * @param string               $cert_type             Certificate type, for the fallback path.
 * @param int                  $template_field_count  Number of slots to resolve.
 * @return string[] Ordered field names, one per slot.
 */
function certificate_generator_resolve_template_fields( array $tmpl_meta, string $cert_type, int $template_field_count ): array {
	$has_explicit_mapping = false;
	for ( $i = 1; $i <= $template_field_count; $i++ ) {
		if ( ! empty( $tmpl_meta[ "field_{$i}_name" ][0] ?? '' ) ) {
			$has_explicit_mapping = true;
			break;
		}
	}

	if ( $has_explicit_mapping ) {
		$fields = array();
		for ( $i = 1; $i <= $template_field_count; $i++ ) {
			$name     = $tmpl_meta[ "field_{$i}_name" ][0] ?? '';
			$fields[] = $name !== '' ? $name : "extra_field_{$i}";
		}
		return $fields;
	}

	// Legacy fallback — byte-compatible with the pre-existing positional behaviour.
	$all_renderable = class_exists( 'CertificateGenerator_Field_Schema' )
		? CertificateGenerator_Field_Schema::get_all_renderable_fields( $cert_type )
		: array( 'student_name', 'school_name', 'teacher_name', 'issue_date' );
	$fields = $all_renderable;
	while ( count( $fields ) < $template_field_count ) {
		$fields[] = 'extra_field_' . ( count( $fields ) + 1 );
	}
	return array_slice( $fields, 0, $template_field_count );
}

function certificate_generator_build_field_positions( array $fields, array $tmpl_meta, bool $is_sql_table, string $photo_url = '' ): array {
	$field_positions = array();

	foreach ( $fields as $index => $field ) {
		$field_key = $index + 1; // 1-indexed

		// SQL table uses "1_position_x", CPT uses "field_1_position_x".
		// TemplatesPage/bulk-import write extra_fields with the "field_N_" prefix even for SQL rows,
		// so fall back to the prefixed key when the bare key is absent.
		if ( $is_sql_table ) {
			$x       = $tmpl_meta[ "{$field_key}_position_x" ][0] ?? $tmpl_meta[ "field_{$field_key}_position_x" ][0] ?? '';
			$y       = $tmpl_meta[ "{$field_key}_position_y" ][0] ?? $tmpl_meta[ "field_{$field_key}_position_y" ][0] ?? '';
			$width   = $tmpl_meta[ "{$field_key}_width" ][0] ?? $tmpl_meta[ "field_{$field_key}_width" ][0] ?? '100';
			$height  = $tmpl_meta[ "{$field_key}_height" ][0] ?? $tmpl_meta[ "field_{$field_key}_height" ][0] ?? '';
			$align   = certificate_generator_sanitize_alignment(
				$tmpl_meta[ "{$field_key}_alignment" ][0] ?? $tmpl_meta[ "field_{$field_key}_alignment" ][0] ?? 'C'
			);
			$visible = $tmpl_meta[ "{$field_key}_visible" ][0] ?? $tmpl_meta[ "field_{$field_key}_visible" ][0] ?? '1';
		} else {
			$x       = $tmpl_meta[ "field_{$field_key}_position_x" ][0] ?? '';
			$y       = $tmpl_meta[ "field_{$field_key}_position_y" ][0] ?? '';
			$width   = $tmpl_meta[ "field_{$field_key}_width" ][0] ?? '100';
			$height  = $tmpl_meta[ "field_{$field_key}_height" ][0] ?? '';
			$align   = certificate_generator_sanitize_alignment( $tmpl_meta[ "field_{$field_key}_alignment" ][0] ?? 'C' );
			$visible = $tmpl_meta[ "field_{$field_key}_visible" ][0] ?? '1';
		}

		// Field type: 'text' (default, driven by per-student data), 'image' (a static
		// per-template image — e.g. an e-signature — independent of student data), or
		// 'photo' (identical to 'image' but sourced from the student's own photo_url).
		$field_type  = $tmpl_meta[ "field_{$field_key}_type" ][0] ?? 'text';
		$field_image = $field_type === 'photo'
			? $photo_url
			: ( $tmpl_meta[ "field_{$field_key}_image_url" ][0] ?? '' );

		$field_positions[ $field ] = array(
			'x'         => $x,
			'y'         => $y,
			'width'     => $width,
			'height'    => $height, // image/photo slots only — '' means "auto, keep aspect ratio"
			'align'     => $align,
			'visible'   => $visible,
			'type'      => in_array( $field_type, array( 'text', 'image', 'photo' ), true ) ? $field_type : 'text',
			'image_url' => $field_image,
		);
	}

	return $field_positions;
}

function certificate_generator_generate_pdf_with_data_impl( $post_data ) {
	// ── Debug flags ───────────────────────────────────────────────────────────
	// Set $debug_mode = true only when actively debugging; false for production.
	$debug_mode = defined( 'WP_DEBUG' ) && WP_DEBUG;
	// Set $visual_debug = true to overlay field-boundary markers on the PDF.
	// MUST be false in production — it draws red boxes / coloured dots on certs.
	// Follows WP_DEBUG: set WP_DEBUG = true in wp-config.php to enable visual markers.
	$visual_debug = true; // overlay markers only in debug mode

	certificate_generator_debug_log( 'Post Data: ' . wp_json_encode( $post_data ) );

	// ── Guard: certificate_type is required ───────────────────────────────────
	if ( empty( $post_data['certificate_type'] ) ) {
		certificate_generator_debug_log( 'Certificate Generator: certificate_generator_generate_certificate_pdf_with_data called without certificate_type.' );
		return false;
	}

	// ── Parse issue_date ONCE ─────────────────────────────────────────────────
	// Derive both the ISO value (Y-m-d, for template matching) and the display
	// value (d-m-Y, for the PDF) from the same DateTime object so neither step
	// misinterprets a date that is already in the correct format.
	$issue_date_iso = '';
	if ( ! empty( $post_data['issue_date'] ) ) {
		$dt = DateTime::createFromFormat( 'd-m-Y', $post_data['issue_date'] )
			?: DateTime::createFromFormat( 'Y-m-d', $post_data['issue_date'] );
		if ( $dt ) {
			$issue_date_iso          = $dt->format( 'Y-m-d' );   // for template matching
			$post_data['issue_date'] = $dt->format( 'd-m-Y' );   // for PDF display
			certificate_generator_debug_log( 'issue_date ISO: ' . $issue_date_iso . ', display: ' . $post_data['issue_date'] );
		}
	}

	// ── Template lookup ───────────────────────────────────────────────────────
	// Preview path: load the specific template by ID so draft templates work
	// and we never hit the status='published' filter or type-matching logic.
	// Real generation: select by certificate_type + issue_date (strict mode).
	$is_preview           = ! empty( $post_data['_preview_post_id'] );
	$certificate_template = null;

	if ( $is_preview && class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tpl_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
		$row       = $GLOBALS['wpdb']->get_row(
			$GLOBALS['wpdb']->prepare( "SELECT * FROM $tpl_table WHERE id = %d", (int) $post_data['_preview_post_id'] ),
			ARRAY_A
		);
		if ( $row ) {
			$certificate_template = (object) $row;
		}
	}

	if ( ! $certificate_template ) {
		$certificate_template = certificate_generator_select_certificate_template(
			$post_data['certificate_type'],
			$issue_date_iso,
			! $is_preview   // strict=false for preview, strict=true for generation
		);
	}

	if ( ! $certificate_template ) {
		$msg = sprintf(
			'No certificate template found for type "%s" with issue_date "%s". '
			. 'Please ensure a template exists whose event_date matches the issue date exactly.',
			$post_data['certificate_type'],
			$post_data['issue_date'] ?? $issue_date_iso
		);
		certificate_generator_debug_log( 'Certificate Generator: ' . $msg );
		return false;
	}
	// One query for all template meta (replaces N individual get_post_meta calls)
	// If template came from SQL table, it already has all data
	if ( isset( $certificate_template->id ) && ! isset( $certificate_template->ID ) ) {
		// SQL table result — data is already flat
		$tmpl_meta = array();
		foreach ( $certificate_template as $key => $value ) {
			if ( $value !== null && $value !== '' ) {
				$tmpl_meta[ $key ] = array( $value );
			}
		}
		// Merge extra_fields JSON if present
		if ( ! empty( $certificate_template->extra_fields ) ) {
			$extra = json_decode( $certificate_template->extra_fields, true );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $k => $v ) {
					$tmpl_meta[ $k ] = array( $v );
				}
			}
		}
	} else {
		// CPT result — use get_post_meta
		$tmpl_meta = get_post_meta( $certificate_template->ID );
	}
	$template_url = $tmpl_meta['template_url'][0] ?? '';
	certificate_generator_log_debug( 'Template URL: ' . $template_url );

	if ( empty( $template_url ) ) {
		$tmpl_id = $certificate_template->id ?? $certificate_template->ID ?? '?';
		certificate_generator_debug_log( 'Certificate Generator: Template URL is missing for template ID: ' . $tmpl_id );
		return false;
	}

	// Fetch dynamic fields
	// SQL table stores 'orientation'; CPT meta used 'template_orientation' — check both.
	$template_orientation = $tmpl_meta['orientation'][0] ?? $tmpl_meta['template_orientation'][0] ?? 'landscape';
	$font_size            = $tmpl_meta['font_size'][0] ?? 12;
	$font_color           = $tmpl_meta['font_color'][0] ?? '#000000';
	$font_style           = $tmpl_meta['font_style'][0] ?? 'helvetica';

	// Initialize FontManager
	$font_manager = CertificateGenerator_FontManager::getInstance();
	certificate_generator_log_debug( "Font requested: {$font_style}" );

	// Validate template URL
	certificate_generator_debug_log( "Validating template URL: {$template_url}" );
	if ( function_exists( 'certificate_generator_validate_template_url' ) ) {
		$validation_result = certificate_generator_validate_template_url( $template_url, $is_preview );
		if ( $validation_result !== true ) {
			certificate_generator_debug_log( 'Certificate Generator: Template URL validation failed: ' . ( is_string( $validation_result ) ? wp_strip_all_tags( $validation_result ) : 'unknown' ) );
			return false;
		}
	}
	certificate_generator_debug_log( 'Template URL validation successful' );

	// Get template field count - limits how many fields to render
	$template_field_count = (int) ( $tmpl_meta['template_field_count'][0] ?? 3 );

	// Resolve the ordered field list — explicit per-slot mapping when the template
	// has one, otherwise the original positional CertificateGenerator_Field_Schema behaviour.
	$cert_type_key = $post_data['certificate_type'];
	$fields        = certificate_generator_resolve_template_fields( $tmpl_meta, $cert_type_key, $template_field_count );

	certificate_generator_debug_log( 'Dynamic Fields (mapped): ' . wp_json_encode( $fields ) );

	// Fetch field positions dynamically from the certificate template.
	// Check if data is from SQL table (has id and template_url keys)
	$is_sql_table    = isset( $certificate_template->id ) && ! isset( $certificate_template->ID );
	$field_positions = certificate_generator_build_field_positions( $fields, $tmpl_meta, $is_sql_table, (string) ( $post_data['photo_url'] ?? '' ) );
	foreach ( $fields as $field_num => $field ) {
		// --- Normalise and provide sensible fallbacks for missing/invalid values ---
		// Template uses A4 units (mm) via FPDF; compute page dims based on orientation
		$template_width  = ( $template_orientation === 'landscape' ) ? 297 : 210;
		$template_height = ( $template_orientation === 'landscape' ) ? 210 : 297;

		// Coerce numeric values where possible
		$pos            =& $field_positions[ $field ];
		$pos['x']       = is_numeric( $pos['x'] ) ? floatval( $pos['x'] ) : '';
		$pos['y']       = is_numeric( $pos['y'] ) ? floatval( $pos['y'] ) : '';
		$pos['width']   = is_numeric( $pos['width'] ) ? floatval( $pos['width'] ) : 100.0;
		$pos['align']   = certificate_generator_sanitize_alignment( (string) ( $pos['align'] ?? 'C' ) );
		$pos['visible'] = ( $pos['visible'] === '0' || $pos['visible'] === 0 || $pos['visible'] === 'false' ) ? '0' : '1';

		// If X/Y are missing or empty, calculate reasonable defaults based on field order
		if ( $pos['x'] === '' || $pos['y'] === '' ) {
			// centre X by default
			if ( $pos['x'] === '' ) {
				$pos['x'] = $template_width / 2.0;
			}

			// Spread Y positions vertically across a central band (25%..75%) using field index
			$slot_index  = intval( $field_num );
			$total_slots = max( 1, count( $fields ) - 1 );
			$fraction    = ( $total_slots === 0 ) ? 0.5 : ( $slot_index / $total_slots );
			// Map fraction into 0.25..0.75 of the page height
			$pos['y'] = $template_height * ( 0.25 + ( $fraction * 0.5 ) );
		}

		certificate_generator_debug_log( "Field position for {$field}: " . wp_json_encode( $field_positions[ $field ] ) );
	}

	// Generate PDF. A custom uploaded font needs the Unicode-capable tFPDF
	// engine to embed the raw .ttf — plain FPDF can only use its 29 bundled
	// fonts and would otherwise silently fall back to Helvetica.
	$page_size = $tmpl_meta['page_size'][0] ?? 'A4';
	$pdf       = $font_manager->is_custom_font( $font_style )
		? new CertificateGenerator_TFPDF_Debug( $template_orientation, 'mm', $page_size )
		: new CertificateGenerator_FPDF_Debug( $template_orientation, 'mm', $page_size );
	$pdf->AddPage();

	// Set text color
	$font_color_rgb = sscanf( $font_color, '#%02x%02x%02x' );
	$pdf->SetTextColor( $font_color_rgb[0], $font_color_rgb[1], $font_color_rgb[2] );

	// Set default font using FontManager
	$used_font = $font_manager->add_font_to_pdf( $pdf, $font_style, '', $font_size );

	// Add template background
	$pdf->Image( certificate_generator_template_url_to_path( $template_url ), 0, 0, $template_orientation === 'landscape' ? 297 : 210, $template_orientation === 'landscape' ? 210 : 297 );

	// Add debug information legend if visual debugging is enabled
	if ( $visual_debug ) {
		// Store current color values before changing them
		$current_color_r = $font_color_rgb[0];
		$current_color_g = $font_color_rgb[1];
		$current_color_b = $font_color_rgb[2];

		// Set up debug info section
		$pdf->SetFont( 'helvetica', 'B', 8 );
		$pdf->SetTextColor( 0, 0, 0 );
		$pdf->SetFillColor( 255, 255, 255 );
		// Note: SetAlpha may not be available in all FPDF versions

		// Draw debug info box
		$box_x      = 5;
		$box_y      = 5;
		$box_width  = 80;
		$box_height = 40;
		$pdf->Rect( $box_x, $box_y, $box_width, $box_height, 'F' );

		// Add title
		$pdf->SetXY( $box_x + 2, $box_y + 3 );
		$pdf->SetTextColor( 0, 0, 0 );
		$pdf->Cell( $box_width - 4, 5, 'CERTIFICATE DEBUG MODE', 0, 1, 'L' );

		// Add legend items
		$pdf->SetFont( 'helvetica', '', 6 );

		// Red box - field boundaries
		$pdf->SetXY( $box_x + 2, $box_y + 10 );
		$pdf->SetDrawColor( 255, 0, 0 );
		$pdf->Rect( $box_x + 2, $box_y + 10, 3, 3, 'D' );
		$pdf->SetXY( $box_x + 6, $box_y + 10 );
		$pdf->SetTextColor( 255, 0, 0 );
		$pdf->Cell( $box_width - 8, 3, 'Red Box: Field Boundaries', 0, 1, 'L' );

		// Green dot - original position
		$pdf->SetXY( $box_x + 2, $box_y + 15 );
		$pdf->SetDrawColor( 0, 255, 0 );
		$pdf->SetFillColor( 0, 255, 0 );
		$pdf->Rect( $box_x + 3, $box_y + 16, 1, 1, 'F' );
		$pdf->SetXY( $box_x + 6, $box_y + 15 );
		$pdf->SetTextColor( 0, 128, 0 );
		$pdf->Cell( $box_width - 8, 3, 'Green Dot: Original Position', 0, 1, 'L' );

		// Blue dot - adjusted position
		$pdf->SetXY( $box_x + 2, $box_y + 20 );
		$pdf->SetDrawColor( 0, 0, 255 );
		$pdf->SetFillColor( 0, 0, 255 );
		$pdf->Rect( $box_x + 3, $box_y + 21, 1, 1, 'F' );
		$pdf->SetXY( $box_x + 6, $box_y + 20 );
		$pdf->SetTextColor( 0, 0, 255 );
		$pdf->Cell( $box_width - 8, 3, 'Blue Dot: Adjusted Position', 0, 1, 'L' );

		// Version info
		$pdf->SetXY( $box_x + 2, $box_y + 30 );
		$pdf->SetTextColor( 100, 100, 100 );
		$pdf->Cell( $box_width - 4, 3, 'Certificate Generator Debug v1.0', 0, 1, 'L' );

		// Restore original settings
		$font_manager->add_font_to_pdf( $pdf, $font_style, '', $font_size );
		$pdf->SetTextColor( $current_color_r, $current_color_g, $current_color_b );
	}

	// **Enhanced Text Positioning and Alignment Logic**
	foreach ( $field_positions as $field => $position ) {
		if ( $position['visible'] == '0' ) {
			if ( $debug_mode ) {
				certificate_generator_debug_log( "Skipping hidden field: {$field}" );
			}
			continue; // Skip hidden fields
		}

		if ( in_array( $position['type'] ?? 'text', array( 'image', 'photo' ), true ) ) {
			if ( ! empty( $position['image_url'] ) && is_numeric( $position['x'] ) && is_numeric( $position['y'] ) ) {
				try {
					$pdf->Image( certificate_generator_template_url_to_path( $position['image_url'] ), $position['x'], $position['y'], floatval( $position['width'] ), is_numeric( $position['height'] ?? '' ) ? floatval( $position['height'] ) : 0 );
				} catch ( Exception $e ) {
					certificate_generator_debug_log( "Error adding image field {$field}: " . $e->getMessage() );
				}
			}
			// No photo uploaded for this student — skip silently, don't error the whole certificate.
			continue;
		}

		if ( empty( $post_data[ $field ] ) || ! is_numeric( $position['x'] ) || ! is_numeric( $position['y'] ) ) {
			if ( $debug_mode ) {
				certificate_generator_debug_log( "Invalid data or position for $field: X={$position['x']}, Y={$position['y']}, Data=" . ( empty( $post_data[ $field ] ) ? 'empty' : 'present' ) );
			}
			continue;
		}

		// **Set Font and Prepare Text**
		$font_manager->add_font_to_pdf( $pdf, $font_style, 'B', $font_size );
		$text = mb_convert_encoding( $post_data[ $field ], 'ISO-8859-1', 'UTF-8' );

		// **Shrink long text to fit, then measure**
		$field_width = floatval( $position['width'] );
		$fit_size    = certificate_generator_fit_font_size( $pdf, (float) $font_size, $text, $field_width );
		$text_width  = $pdf->GetStringWidth( $text );

		// **Calculate proper line height based on font size**
		$line_height = $fit_size * 0.5; // Tighter line spacing for better appearance

		certificate_generator_debug_log( "Processing field {$field}: text_width={$text_width}, field_width={$field_width}, alignment={$position['align']}" );

		// **Determine if text needs wrapping**
		if ( $text_width > $field_width ) {
			// **Multi-line text with word wrapping**
			$lines = certificate_generator_wrap_text( $pdf, $text, $field_width );

			// Calculate total text block height
			$total_text_height = count( $lines ) * $line_height;

			// Calculate starting Y position for vertical centering
			$start_y = $position['y'] - ( $total_text_height / 2 ) + ( $line_height / 2 );

			// Draw each line with proper alignment
			foreach ( $lines as $i => $line ) {
				$line_text  = $line; // already converted with $text above
				$line_width = $pdf->GetStringWidth( $line_text );

				// Calculate X position based on alignment
				$adjusted_x = certificate_generator_calculate_x_position( $position['x'], $line_width, $field_width, $position['align'] );
				$adjusted_y = $start_y + ( $i * $line_height );

				// Output the text
				$pdf->Text( $adjusted_x, $adjusted_y, $line_text );

				// Add debug visualization if enabled
				if ( $visual_debug ) {
					certificate_generator_add_debug_markers( $pdf, $adjusted_x, $adjusted_y, $position['x'], $position['y'], $i === 0 );
				}
			}

			// Add debug visualization for field boundary
			if ( $visual_debug ) {
				certificate_generator_add_debug_field_boundary( $pdf, $position['x'], $position['y'], $field_width, $total_text_height );
			}
		} else {
			// **Single line text**

			// Calculate X position based on alignment
			$adjusted_x = certificate_generator_calculate_x_position( $position['x'], $text_width, $field_width, $position['align'] );

			// Output the text
			$pdf->Text( $adjusted_x, $position['y'], $text );

			// Add debug visualization if enabled
			if ( $visual_debug ) {
				certificate_generator_add_debug_markers( $pdf, $adjusted_x, $position['y'], $position['x'], $position['y'], true );
				certificate_generator_add_debug_field_boundary( $pdf, $position['x'], $position['y'], $field_width, $font_size + 2 );
			}
		}
	}

	// ── QR Code & Serial Number for Preview ─────────────────────────────
	$qr_generator = null;
	if ( class_exists( 'CertificateGenerator_QR_Code_Generator' ) ) {
		$qr_generator = CertificateGenerator_QR_Code_Generator::get_instance();
	}

	$serial_gen    = null;
	$cert_type     = $post_data['certificate_type'] ?? '';
	$student_name  = $post_data['student_name'] ?? $post_data['teacher_name'] ?? $post_data['school_name'] ?? '';

	// The entity row's own serial_number (when the caller supplied one) is
	// authoritative — reuse it before falling back to the fragile name+type match.
	$serial_number = (string) ( $post_data['serial_number'] ?? '' );

	// Previews show a placeholder: a real serial would burn a number and could be
	// written onto a real entity row.
	if ( $is_preview ) {
		$serial_number = 'PREVIEW-SERIAL';
	}

	// Reuse existing serial for the same student + certificate type
	if ( empty( $serial_number ) && $student_name && $cert_type ) {
		$serial_number = certificate_generator_find_existing_serial( $student_name, $cert_type, (string) ( $post_data['issue_date'] ?? '' ) );
	}
	if ( empty( $serial_number ) && class_exists( 'CertificateGenerator_Serial_Number_Generator' ) ) {
		$serial_gen = CertificateGenerator_Serial_Number_Generator::get_instance();
		// Store the serial on the matching entity row (email/name + type + issue date).
		$student_data_for_serial = array(
			'table'        => isset( $post_data['student_name'] ) ? 'students' : ( isset( $post_data['teacher_name'] ) ? 'teachers' : 'schools' ),
			'email'        => $post_data['email'] ?? '',
			'student_name' => $student_name,
			'issue_date'   => $issue_date_iso,
		);
		$serial_number           = $serial_gen->generate( $cert_type, $student_data_for_serial );
	}

	if ( $qr_generator ) {
		$qr_enabled = $tmpl_meta['qr_enabled'][0] ?? '';
		if ( $qr_enabled === '1' ) {
			$qr_size  = (float) ( $tmpl_meta['qr_size'][0] ?? 15 );
			$qr_pos_x = (float) ( $tmpl_meta['qr_position_x'][0] ?? 250 );
			$qr_pos_y = (float) ( $tmpl_meta['qr_position_y'][0] ?? 180 );
			$qr_ec    = $tmpl_meta['qr_error_correction'][0] ?? 'L';

			$cert_data_for_qr = array_merge(
				$post_data,
				array(
					'serial_number' => $serial_number,
					'issued_at'     => current_time( 'mysql' ),
				)
			);

			$qr_data = $qr_generator->generate_qr_data( $cert_data_for_qr, $serial_number );
			$qr_generator->add_qr_to_pdf( $pdf, $qr_data, $qr_pos_x, $qr_pos_y, $qr_size );
		}
	}

	if ( $qr_generator && ! empty( $serial_number ) ) {
		$serial_display = $tmpl_meta['serial_number_display'][0] ?? '';
		if ( $serial_display === '1' ) {
			$sn_pos_x     = (float) ( $tmpl_meta['serial_number_position_x'][0] ?? 105 );
			$sn_pos_y     = (float) ( $tmpl_meta['serial_number_position_y'][0] ?? 200 );
			$sn_font_size = (int) ( $tmpl_meta['serial_number_font_size'][0] ?? 10 );
			$qr_generator->add_serial_to_pdf( $pdf, $serial_number, $sn_pos_x, $sn_pos_y, $sn_font_size, $font_style );
		}
	}

	// Output PDF — previews reuse one file per template; real certificates get a
	// unique name, since callers store this path as the row's pdf_path.
	$upload_dir   = wp_upload_dir();
	$pdf_filename = $is_preview
		? 'certificate_preview_' . intval( $post_data['_preview_post_id'] ) . '.pdf'
		: certificate_generator_certificate_pdf_filename( (string) $student_name, (string) $cert_type, trim( $serial_number . '_' . wp_generate_password( 6, false ), '_' ) );
	$pdf_path       = $upload_dir['path'] . '/' . $pdf_filename;
	$pdf->Output( 'F', $pdf_path );

	// Persist to the certificate_generator table so verification, serial
	// sequencing, and analytics counting all work. Skip for preview-only
	// runs (throwaway PDFs); a certificate without a serial yet still gets
	// logged so analytics doesn't undercount deferred/no-serial issuance.
	if ( empty( $post_data['_preview_post_id'] ) ) {
		certificate_generator_insert_certificate_record( $post_data + array( 'template_id' => $certificate_template->id ?? null ), $serial_number );
	}

	return $upload_dir['url'] . '/' . $pdf_filename;
}

// ── Public shim for certificate_generator_generate_certificate_pdf_with_data ───────────────────────
// When CG_USE_NEW_PDF=true, legacy-shims.php defines this function and routes
// it through PdfGenerator::makeFromArray(). When the flag is off (default),
// this wrapper is used so all 5 call sites continue to work unchanged.
if ( ! class_exists( '\CertificateGenerator\Core\Config' )
	|| ! \CertificateGenerator\Core\Config::flag( 'CG_USE_NEW_PDF' ) ) {
	/**
	 * @deprecated v8 Use \CertificateGenerator\Services\PdfGenerator::makeFromArray() instead.
	 */
	function certificate_generator_generate_certificate_pdf_with_data( $post_data ) {
		return certificate_generator_generate_pdf_with_data_impl( $post_data );
	}
}

/**
 * A re-generated certificate keeps its serial, so the verification records for
 * that serial must follow a corrected name (otherwise /verify keeps showing the
 * misspelling). Only the name and data snapshot change; serial, issue date and
 * status stay as issued.
 */
function certificate_generator_sync_certificate_record_name( array $post_data, string $serial_number ): void {
	$name = '';
	foreach ( array( 'student_name', 'teacher_name', 'school_name' ) as $key ) {
		if ( is_string( $post_data[ $key ] ?? null ) && trim( $post_data[ $key ] ) !== '' ) {
			$name = trim( $post_data[ $key ] );
			break;
		}
	}
	if ( $name === '' ) {
		return;
	}

	global $wpdb;
	$json = wp_json_encode( $post_data );
	$wpdb->update(
		$wpdb->prefix . 'certificate_generator',
		array( 'student_name' => $name, 'certificate_data' => $json ),
		array( 'serial_number' => $serial_number )
	);
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$cert_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificates' );
		if ( $cert_table ) {
			$wpdb->update( $cert_table, array( 'recipient_name' => $name, 'certificate_data' => $json ), array( 'serial_number' => $serial_number ) );
		}
	}
}

/**
 * Insert a row into the wp_certificate_generator table after a certificate
 * has been generated.  This is the record that the public verification
 * shortcode and serial-number sequencer query against.
 */
function certificate_generator_insert_certificate_record( array $post_data, string $serial_number, string $generated_via = 'manual' ): void {
	global $wpdb;
	$table = $wpdb->prefix . 'certificate_generator';

	// Avoid duplicate rows for the same serial. Without a serial there's no
	// reliable natural key to dedupe on, so every call is a legitimate new
	// generation event and gets its own row (this is what feeds analytics).
	if ( ! empty( $serial_number ) ) {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE serial_number = %s LIMIT 1",
				$serial_number
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $exists ) {
			certificate_generator_sync_certificate_record_name( $post_data, $serial_number );
			return;
		}
	}

	$wpdb->insert(
		$table,
		array(
			'student_name'     => $post_data['student_name']
									?? $post_data['teacher_name']
									?? $post_data['school_name']
									?? 'Unknown',
			'certificate_data' => wp_json_encode( $post_data ),
			'issued_at'        => current_time( 'mysql' ),
			'serial_number'    => $serial_number,
			'certificate_type' => $post_data['certificate_type'] ?? '',
			'generated_via'    => $generated_via,
		)
	);

	// Also log to custom SQL tables if available
	if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		static $table_exists_cache = array();
		static $template_id_cache  = array();

		$tables     = \CertificateGenerator\Database\CustomTables::instance();
		$cert_table = $tables->get_table( 'certificates' );

		if ( ! isset( $table_exists_cache[ $cert_table ] ) ) {
			$table_exists_cache[ $cert_table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cert_table ) ) === $cert_table;
		}
		$table_exists = $table_exists_cache[ $cert_table ];

		if ( $table_exists ) {
			$cert_type   = $post_data['certificate_type'] ?? '';
			$template_id = $post_data['template_id'] ?? null; // the template actually rendered, when the caller knows it
			if ( ! $template_id && ! empty( $cert_type ) ) {
				$issue_raw = $post_data['issue_date'] ?? '';
				$issue_dt  = $issue_raw ? ( DateTime::createFromFormat( 'd-m-Y', $issue_raw ) ?: DateTime::createFromFormat( 'Y-m-d', $issue_raw ) ) : false;
				$issue_iso = $issue_dt ? $issue_dt->format( 'Y-m-d' ) : '';
				$cache_key = $cert_type . '|' . $issue_iso;
				if ( ! array_key_exists( $cache_key, $template_id_cache ) ) {
					$tpl                             = certificate_generator_select_certificate_template( $cert_type, $issue_iso );
					$template_id_cache[ $cache_key ] = $tpl->id ?? null;
				}
				$template_id = $template_id_cache[ $cache_key ];
			}

			$wpdb->insert(
				$cert_table,
				array(
					'template_id'      => $template_id,
					'recipient_name'   => $post_data['student_name'] ?? $post_data['teacher_name'] ?? $post_data['school_name'] ?? 'Unknown',
					'recipient_email'  => $post_data['email'] ?? null,
					'recipient_type'   => $post_data['recipient_type'] ?? 'student',
					'certificate_type' => $cert_type,
					'serial_number'    => $serial_number,
					'issued_at'        => current_time( 'mysql' ),
					'generated_via'    => $generated_via,
					'certificate_data' => wp_json_encode( $post_data ),
					'status'           => 'generated',
					'created_at'       => current_time( 'mysql' ),
				)
			);
		}

		// Write serial back to the entity (student/teacher/school) SQL row
		$wp_post_id = (int) ( $post_data['wp_post_id'] ?? 0 );
		if ( $wp_post_id > 0 ) {
			// Post meta — available even without SQL tables
			update_post_meta( $wp_post_id, 'certificate_serial_number', $serial_number );

			// SQL entity row — update serial_number, certificate_type, issue_date
			$recipient_type = $post_data['recipient_type'] ?? get_post_type( $wp_post_id );
			$entity         = in_array( $recipient_type, array( 'students', 'teachers', 'schools' ), true )
				? $recipient_type : 'students';
			$entity_table   = $tables->get_table( $entity );

			if ( ! empty( $entity_table ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entity_table ) ) === $entity_table ) {
				$issue_date_raw    = $post_data['issue_date'] ?? '';
				$issue_date_stored = class_exists( '\CertificateGenerator\Helpers\DateHelper' )
					? ( \CertificateGenerator\Helpers\DateHelper::to_storage( $issue_date_raw ) ?? $issue_date_raw )
					: $issue_date_raw;

				$wpdb->update(
					$entity_table,
					array(
						'serial_number'    => $serial_number,
						'certificate_type' => $post_data['certificate_type'] ?? '',
						'issue_date'       => $issue_date_stored ?: null,
					),
					array( 'wp_post_id' => $wp_post_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}
}

/**
 * Look up an existing serial number for a student + certificate type (+ issue date) combo.
 * With $issue_date, only a serial from the same event is reused — a student with two
 * "Participation" certificates for different events must get two serials.
 * Returns the serial string if found, empty string otherwise.
 */
function certificate_generator_find_existing_serial( string $student_name, string $certificate_type, string $issue_date = '' ): string {
	if ( empty( $student_name ) || empty( $certificate_type ) ) {
		return '';
	}
	global $wpdb;
	$table = $wpdb->prefix . 'certificate_generator';
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT serial_number, certificate_data FROM $table WHERE student_name = %s AND certificate_type = %s AND serial_number IS NOT NULL AND serial_number != '' ORDER BY id DESC",
			$student_name,
			$certificate_type
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( $issue_date === '' ) {
		return $rows[0]['serial_number'] ?? '';
	}
	$to_iso = static function ( $d ) {
		$dt = DateTime::createFromFormat( '!Y-m-d', (string) $d ) ?: DateTime::createFromFormat( '!d-m-Y', (string) $d );
		return $dt ? $dt->format( 'Y-m-d' ) : '';
	};
	$want = $to_iso( $issue_date );
	foreach ( $rows as $row ) {
		$data = json_decode( (string) $row['certificate_data'], true );
		if ( $to_iso( $data['issue_date'] ?? '' ) === $want ) {
			return $row['serial_number'];
		}
	}
	return '';
}

/**
 * Helper: resolve the best-matching certificate template for a given post.
 *
 * Reads `certificate_type` and `issue_date` from post meta, normalises the
 * date to Y-m-d, and delegates to certificate_generator_select_certificate_template().
 *
 * @param  int $post_id  Post ID of a student / teacher / school post.
 * @return WP_Post|false        The matched template post, or false if none found.
 */
function certificate_generator_resolve_student_template( int $post_id ) {
	$certificate_type = get_post_meta( $post_id, 'certificate_type', true );

	// SQL fallback: post meta absent but a SQL row may exist for this wp_post_id
	if ( empty( $certificate_type ) && $post_id > 0
		&& class_exists( '\CertificateGenerator\Database\CustomTables' )
	) {
		global $wpdb;
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		foreach ( array( 'students', 'teachers', 'schools' ) as $_entity ) {
			$_tbl = $tables->get_table( $_entity );
			if ( $_tbl && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $_tbl ) ) === $_tbl ) {
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
				$_row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT certificate_type, issue_date FROM $_tbl WHERE wp_post_id = %d LIMIT 1",
						$post_id
					),
					ARRAY_A
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( ! empty( $_row['certificate_type'] ) ) {
					$certificate_type = $_row['certificate_type'];
					add_filter(
						'certificate_generator_resolve_student_template_issue_date_' . $post_id,
						fn() => $_row['issue_date'] ?? '',
						1
					);
					break;
				}
			}
		}
	}

	if ( ! $certificate_type ) {
		return false;
	}

	// Normalise issue_date to Y-m-d for template matching
	$issue_date_raw = apply_filters(
		'certificate_generator_resolve_student_template_issue_date_' . $post_id,
		get_post_meta( $post_id, 'issue_date', true )
	);
	$issue_date_iso = '';
	if ( $issue_date_raw ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date_raw ) ) {
			$issue_date_iso = $issue_date_raw;
		} else {
			$dt = DateTime::createFromFormat( 'd-m-Y', $issue_date_raw )
				?: date_create( $issue_date_raw );
			if ( $dt ) {
				$issue_date_iso = $dt->format( 'Y-m-d' );
			}
		}
	}

	return certificate_generator_select_certificate_template( $certificate_type, $issue_date_iso );
}

/**
 * Resolve the best-matching certificate template from an entity data array.
 * Prefers SQL row data; falls back to get_post_meta when $entity_data is absent.
 *
 * @param  array|null $entity_data  Must contain 'certificate_type'; optionally 'issue_date'.
 * @param  int        $post_id      Used ONLY as CPT fallback when $entity_data is null/empty.
 * @return object|false
 */
function certificate_generator_resolve_entity_template( ?array $entity_data, int $post_id = 0 ) {
	if ( ! empty( $entity_data['certificate_type'] ) ) {
		$certificate_type = $entity_data['certificate_type'];
		$issue_date_raw   = $entity_data['issue_date'] ?? '';
	} elseif ( $post_id > 0 ) {
		$certificate_type = get_post_meta( $post_id, 'certificate_type', true );
		$issue_date_raw   = get_post_meta( $post_id, 'issue_date', true );
	} else {
		return false;
	}

	if ( ! $certificate_type ) {
		return false;
	}

	$issue_date_iso = '';
	if ( $issue_date_raw ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date_raw ) ) {
			$issue_date_iso = $issue_date_raw;
		} else {
			$dt = DateTime::createFromFormat( 'd-m-Y', $issue_date_raw )
				?: date_create( $issue_date_raw );
			if ( $dt ) {
				$issue_date_iso = $dt->format( 'Y-m-d' );
			}
		}
	}

	return certificate_generator_select_certificate_template( $certificate_type, $issue_date_iso );
}

function certificate_generator_generate_pdf_impl( $post_id, $fields, $student_data = null ) {
	// If student_data is provided (from SQL table), use it instead of post meta
	$use_table_data = is_array( $student_data ) && ! empty( $student_data );

	// Auto SQL-first: silently load entity row when caller did not supply $student_data
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
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			$sql_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $entity_table WHERE wp_post_id = %d LIMIT 1",
					$post_id
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! empty( $sql_row ) ) {
				$student_data   = $sql_row;
				$use_table_data = true;
			}
		}
	}

	// Rows with no CPT post (wp_post_id NULL) arrive with their table id as $post_id.
	// That id belongs to some unrelated post, so never read or write its postmeta, and
	// key the PDF by entity + row id so it can't collide with a real post's certificate_N.pdf.
	$pdf_key         = (string) $post_id;
	$detached_entity = '';
	if ( $use_table_data && array_key_exists( 'wp_post_id', $student_data ) ) {
		$real_post_id = (int) $student_data['wp_post_id'];
		if ( $real_post_id <= 0 || $real_post_id !== (int) $post_id ) {
			$detached_entity = isset( $student_data['student_name'] ) ? 'students' : ( isset( $student_data['teacher_name'] ) ? 'teachers' : 'schools' );
			$pdf_key         = $detached_entity . '_row_' . (int) ( $student_data['id'] ?? $post_id );
			$post_id         = 0;
		}
	}

	// Get the certificate type from current post or student data
	if ( $use_table_data ) {
		$certificate_type = $student_data['certificate_type'] ?? '';
		$issue_date_iso   = $student_data['issue_date'] ?? '';
	} else {
		$certificate_type = get_post_meta( $post_id, 'certificate_type', true );
		$issue_date_iso   = get_post_meta( $post_id, 'issue_date', true );
	}

	// Start error logging
	certificate_generator_debug_log( "Starting certificate generation for post ID: $post_id (table_data: " . ( $use_table_data ? 'yes' : 'no' ) . ')' );
	certificate_generator_debug_log( 'Requested fields: ' . wp_json_encode( $fields ) );

	if ( ! $certificate_type ) {
		certificate_generator_debug_log( 'Certificate Generator: Certificate type is missing for post ID: ' . $post_id );
		return false;
	}

	// ── Usage-limit gate ─────────────────────────────────────────────────
	// The quota counts newly issued serials, so a row that already has its serial is an
	// issued certificate and stays downloadable at the limit.
	$already_issued = $use_table_data && ! empty( $student_data['serial_number'] );
	$proceed        = $already_issued ? true : apply_filters( 'certificate_generator_pre_generate_certificate', true );
	if ( is_wp_error( $proceed ) ) {
		certificate_generator_debug_log( 'Certificate Generator: Blocked by usage limit — ' . $proceed->get_error_message() );
		return false;
	}

	// Normalize issue_date
	if ( $issue_date_iso && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date_iso ) ) {
		$dt = DateTime::createFromFormat( 'd-m-Y', $issue_date_iso );
		if ( $dt ) {
			$issue_date_iso = $dt->format( 'Y-m-d' );
		}
	}

	// Select template. Callers that already know the exact template (LMS/WooCommerce mappings,
	// whose issue_date is the completion date, not the template's event_date) pass _template_id.
	$certificate_template = null;
	if ( $use_table_data && ! empty( $student_data['_template_id'] ) && class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$_tpl_table           = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' );
		$_tpl_row             = $GLOBALS['wpdb']->get_row(
			$GLOBALS['wpdb']->prepare( "SELECT * FROM $_tpl_table WHERE id = %d", (int) $student_data['_template_id'] ),
			ARRAY_A
		);
		$certificate_template = $_tpl_row ? (object) $_tpl_row : null;
	}
	if ( ! $certificate_template ) {
		$certificate_template = certificate_generator_select_certificate_template( $certificate_type, $issue_date_iso );
	}
	if ( ! $certificate_template ) {
		certificate_generator_debug_log( 'Certificate Generator: No certificate template found for type: ' . $certificate_type );
		return false;
	}

	// Get template meta - handle both SQL table and CPT
	if ( isset( $certificate_template->id ) && ! isset( $certificate_template->ID ) ) {
		// SQL table result
		$tmpl_meta = array();
		foreach ( $certificate_template as $key => $value ) {
			if ( $value !== null && $value !== '' ) {
				$tmpl_meta[ $key ] = array( $value );
			}
		}
		// Merge extra_fields JSON
		if ( ! empty( $certificate_template->extra_fields ) ) {
			$extra = json_decode( $certificate_template->extra_fields, true );
			if ( is_array( $extra ) ) {
				foreach ( $extra as $k => $v ) {
					$tmpl_meta[ $k ] = array( $v );
				}
			}
		}
	} else {
		// CPT result
		$tmpl_meta = get_post_meta( $certificate_template->ID );
	}

	$template_url = $tmpl_meta['template_url'][0] ?? '';

	if ( empty( $template_url ) ) {
		certificate_generator_debug_log( 'Certificate Generator: Template URL is missing for template ID: ' . ( $certificate_template->ID ?? $certificate_template->id ?? 'unknown' ) );
		return false;
	}

	// Fetch dynamic fields
	// SQL table stores 'orientation'; CPT meta used 'template_orientation' — check both.
	$template_orientation = $tmpl_meta['orientation'][0] ?? $tmpl_meta['template_orientation'][0] ?? 'landscape';
	$font_size            = $tmpl_meta['font_size'][0] ?? 12;
	$font_color           = $tmpl_meta['font_color'][0] ?? '#000000';
	$font_style           = $tmpl_meta['font_style'][0] ?? 'helvetica';

	// Validate the template URL
	certificate_generator_debug_log( "Validating template URL: {$template_url}" );
	if ( function_exists( 'certificate_generator_validate_template_url' ) ) {
		$validation_result = certificate_generator_validate_template_url( $template_url );
		if ( $validation_result !== true ) {
			certificate_generator_debug_log( 'Certificate Generator: Template URL validation failed: ' . $validation_result );
			return false;
		}
	}
	certificate_generator_debug_log( 'Template URL validation successful' );

	// Get template field count - limits how many fields to render
	$template_field_count = (int) ( $tmpl_meta['template_field_count'][0] ?? 3 );

	// Resolve the ordered field list — explicit per-slot mapping when the template
	// has one, otherwise the original positional CertificateGenerator_Field_Schema behaviour.
	$fields = certificate_generator_resolve_template_fields( $tmpl_meta, $certificate_type, $template_field_count );

	// Flatten extra_fields JSON from the student/entity row into $student_data so that
	// extra field values (e.g. 'team_name' stored in extra_fields) are accessible by key.
	if ( $use_table_data && ! empty( $student_data['extra_fields'] ) ) {
		$entity_extra = json_decode( $student_data['extra_fields'], true );
		if ( is_array( $entity_extra ) ) {
			$student_data = array_merge( $student_data, $entity_extra );
		}
	}

	$missing_positions = array();
	$is_sql_table      = isset( $certificate_template->id ) && ! isset( $certificate_template->ID );
	$field_positions   = certificate_generator_build_field_positions(
		$fields,
		$tmpl_meta,
		$is_sql_table,
		is_array( $student_data ) ? (string) ( $student_data['photo_url'] ?? '' ) : ''
	);
	foreach ( $field_positions as $field => $fp ) {
		certificate_generator_debug_log( "Field position for {$field}: " . wp_json_encode( $fp ) );
	}

	if ( empty( $field_positions ) ) {
		$missing_fields = implode( ', ', $missing_positions );
		certificate_generator_debug_log( 'Certificate generation failed: No valid field positions found. Missing positions for: ' . $missing_fields );
		return false;
	}

	// Normalise field positions: coerce to correct types so render logic never
	// receives raw strings from JSON decode.
	foreach ( $field_positions as &$pos ) {
		$pos['x']       = is_numeric( $pos['x'] ) ? floatval( $pos['x'] ) : '';
		$pos['y']       = is_numeric( $pos['y'] ) ? floatval( $pos['y'] ) : '';
		$pos['width']   = is_numeric( $pos['width'] ) ? floatval( $pos['width'] ) : 100.0;
		$pos['align']   = certificate_generator_sanitize_alignment( (string) ( $pos['align'] ?? 'C' ) );
		$pos['visible'] = ( $pos['visible'] === '0' || $pos['visible'] === 0 || $pos['visible'] === 'false' ) ? '0' : '1';
	}
	unset( $pos );

	// Warn (but do not block) if all positions are zero or all fields are hidden —
	// this produces a blank-looking certificate; admin should set positions via TemplatesPage.
	$has_valid_position = false;
	foreach ( $field_positions as $fp ) {
		if ( $fp['visible'] !== '0'
			&& is_numeric( $fp['x'] ) && is_numeric( $fp['y'] )
			&& ( (float) $fp['x'] > 0 || (float) $fp['y'] > 0 )
		) {
			$has_valid_position = true;
			break;
		}
	}
	if ( ! $has_valid_position ) {
		certificate_generator_debug_log( 'Certificate Generator: All field positions are zero or hidden for this template — the PDF may appear blank. Configure field positions in Templates → Edit Template.' );
	}

	// Fetch post data - from SQL table or post meta
	$post_data    = array();
	$missing_data = array();

	foreach ( $fields as $field_name ) {
		if ( $use_table_data ) {
			$value = $student_data[ $field_name ] ?? '';
		} else {
			$value = get_post_meta( $post_id, $field_name, true );
		}

		if ( empty( $value ) ) {
			$missing_data[] = $field_name;
			certificate_generator_debug_log( "Missing data for field {$field_name}" );
			continue;
		}

		// Format issue_date to dd-mm-yyyy if it exists
		if ( $field_name === 'issue_date' ) {
			$date_obj = DateTime::createFromFormat( 'Y-m-d', $value );
			if ( $date_obj ) {
				$value = $date_obj->format( 'd-m-Y' );
			} else {
				$date_obj = date_create( $value );
				if ( $date_obj ) {
					$value = date_format( $date_obj, 'd-m-Y' );
				}
			}
			certificate_generator_debug_log( 'Formatted issue_date: ' . $value );
		}

		$post_data[ $field_name ] = $value;
	}

	// Check if we have any post data
	if ( empty( $post_data ) ) {
		$missing_fields = implode( ', ', $missing_data );
		certificate_generator_debug_log( 'Certificate generation failed: No valid post data found. Missing data for: ' . $missing_fields );
		return false;
	}

	// Debug: Log post data
	certificate_generator_debug_log( 'Post data for PDF: ' . wp_json_encode( $post_data ) );

	// ── PDF cache ────────────────────────────────────────────────────────
	// An issued certificate whose inputs are unchanged is served from disk: no render,
	// no DB writes, no usage. Rows without a serial render so one gets issued.
	$cache_key = '';
	if ( $already_issued && certificate_generator_pdf_cache_enabled() ) {
		$cache_key = certificate_generator_pdf_cache_key( $pdf_key, $tmpl_meta, $template_url, $field_positions, $post_data, (string) $student_data['serial_number'] );
		if ( certificate_generator_pdf_cache_read_key( certificate_generator_certificates_dir() . '/' . certificate_generator_certificate_file_stem( $pdf_key ) . '.pdf' ) === $cache_key ) {
			certificate_generator_debug_log( "PDF cache hit: $pdf_key" );
			return certificate_generator_certificates_url() . '/' . certificate_generator_certificate_file_stem( $pdf_key ) . '.pdf';
		}
		certificate_generator_debug_log( "PDF cache miss: $pdf_key" );
	}

	try {
		// Generate PDF
		$page_size = $tmpl_meta['page_size'][0] ?? 'A4';
		$pdf       = CertificateGenerator_FontManager::create_pdf_instance( $template_orientation, 'mm', $page_size, $font_style );
		$pdf->AddPage();

		// Add font support using FontManager
		$font_manager = CertificateGenerator_FontManager::getInstance();
		$used_font    = $font_manager->add_font_to_pdf( $pdf, $font_style, '', $font_size );

		// Set font color
		$font_color_rgb = sscanf( $font_color, '#%02x%02x%02x' );
		$pdf->SetTextColor( $font_color_rgb[0], $font_color_rgb[1], $font_color_rgb[2] );

		// Add template background
		try {
			$bg_path = certificate_generator_template_url_to_path( $template_url );
			if ( method_exists( $pdf, 'share_image' ) ) {
				$pdf->share_image( $bg_path );
			}
			$pdf->Image( $bg_path, 0, 0, $template_orientation === 'landscape' ? 297 : 210, $template_orientation === 'landscape' ? 210 : 297 );
		} catch ( Exception $e ) {
			certificate_generator_debug_log( 'Error adding template image: ' . $e->getMessage() );
			return false;
		}

		// **Enhanced Text Positioning and Alignment Logic**
		foreach ( $field_positions as $field => $position ) {
			// Skip if field is not visible
			if ( $position['visible'] == '0' ) {
				certificate_generator_debug_log( "Skipping hidden field: {$field}" );
				continue;
			}

			// Image fields (e.g. an e-signature) are a static per-template image,
			// not per-student data — they never appear in $post_data. Photo fields
			// resolve to the student's own photo_url instead (see field_positions build above).
			if ( in_array( $position['type'] ?? 'text', array( 'image', 'photo' ), true ) ) {
				if ( ! empty( $position['image_url'] ) && is_numeric( $position['x'] ) && is_numeric( $position['y'] ) ) {
					try {
						$img_path = certificate_generator_template_url_to_path( $position['image_url'] );
						if ( 'image' === $position['type'] && method_exists( $pdf, 'share_image' ) ) {
							$pdf->share_image( $img_path ); // static per-template image (e.g. signature); photos differ per student
						}
						$pdf->Image( $img_path, $position['x'], $position['y'], floatval( $position['width'] ), is_numeric( $position['height'] ?? '' ) ? floatval( $position['height'] ) : 0 );
					} catch ( Exception $e ) {
						certificate_generator_debug_log( "Error adding image field {$field}: " . $e->getMessage() );
					}
				}
				// No photo uploaded for this student — skip silently, don't error the whole certificate.
				continue;
			}

			if ( ! isset( $post_data[ $field ] ) ) {
				certificate_generator_debug_log( "Field $field exists in positions but not in post data" );
				continue;
			}

			if ( empty( $post_data[ $field ] ) || ! is_numeric( $position['x'] ) || ! is_numeric( $position['y'] ) ) {
				certificate_generator_debug_log( "Invalid data or position for $field: X={$position['x']}, Y={$position['y']}, Data=" . ( empty( $post_data[ $field ] ) ? 'empty' : 'present' ) );
				continue;
			}

			// **Set Font and Prepare Text**
			$font_manager->add_font_to_pdf( $pdf, $font_style, 'B', $font_size );
			$text = mb_convert_encoding( $post_data[ $field ], 'ISO-8859-1', 'UTF-8' );

			// **Shrink long text to fit, then measure**
			$field_width = floatval( $position['width'] );
			$fit_size    = certificate_generator_fit_font_size( $pdf, (float) $font_size, $text, $field_width );
			$text_width  = $pdf->GetStringWidth( $text );

			// **Calculate proper line height based on font size**
			$line_height = $fit_size * 0.5; // Tighter line spacing for better appearance

			certificate_generator_debug_log( "Processing field {$field}: text_width={$text_width}, field_width={$field_width}, alignment={$position['align']}" );

			// **Determine if text needs wrapping**
			if ( $text_width > $field_width ) {
				// **Multi-line text with word wrapping**
				$lines = certificate_generator_wrap_text( $pdf, $text, $field_width );

				// Calculate total text block height
				$total_text_height = count( $lines ) * $line_height;

				// Calculate starting Y position for vertical centering
				$start_y = $position['y'] - ( $total_text_height / 2 ) + ( $line_height / 2 );

				// Draw each line with proper alignment
				foreach ( $lines as $i => $line ) {
					$line_text  = $line; // already converted with $text above
					$line_width = $pdf->GetStringWidth( $line_text );

					// Calculate X position based on alignment
					$adjusted_x = certificate_generator_calculate_x_position( $position['x'], $line_width, $field_width, $position['align'] );
					$adjusted_y = $start_y + ( $i * $line_height );

					// Output the text
					$pdf->Text( $adjusted_x, $adjusted_y, $line_text );
				}
			} else {
				// **Single line text**

				// Calculate X position based on alignment
				$adjusted_x = certificate_generator_calculate_x_position( $position['x'], $text_width, $field_width, $position['align'] );

				// Output the text
				$pdf->Text( $adjusted_x, $position['y'], $text );
			}
		}

		// ── QR Code & Serial Number Integration ──────────────────────────
		$qr_generator = null;
		if ( class_exists( 'CertificateGenerator_QR_Code_Generator' ) ) {
			$qr_generator = CertificateGenerator_QR_Code_Generator::get_instance();
		}

		$serial_gen       = null;
		$name_for_lookup  = $post_data['student_name'] ?? $post_data['teacher_name'] ?? $post_data['school_name']
							?? get_post_meta( $post_id, 'student_name', true );
		$email_for_lookup = $post_data['email'] ?? '';

		// The entity row's own serial_number is authoritative — reuse it before
		// falling back to the fragile name+type match against the legacy table.
		$serial_number = $use_table_data ? (string) ( $student_data['serial_number'] ?? '' ) : '';

		// Reuse existing serial for the same student + certificate type
		if ( empty( $serial_number ) && $name_for_lookup && $certificate_type ) {
			$serial_number = certificate_generator_find_existing_serial( $name_for_lookup, $certificate_type, (string) $issue_date_iso );
		}
		if ( empty( $serial_number ) && class_exists( 'CertificateGenerator_Serial_Number_Generator' ) ) {
			$serial_gen = CertificateGenerator_Serial_Number_Generator::get_instance();
			// Store the serial on this exact entity row (by id when it came from the SQL table).
			$serial_src              = $use_table_data ? $student_data : $post_data;
			$student_data_for_serial = array(
				'id'           => $use_table_data ? (int) ( $student_data['id'] ?? 0 ) : 0,
				'table'        => isset( $serial_src['student_name'] ) ? 'students' : ( isset( $serial_src['teacher_name'] ) ? 'teachers' : 'schools' ),
				'email'        => $email_for_lookup,
				'student_name' => $name_for_lookup,
				'issue_date'   => (string) $issue_date_iso,
			);
			$serial_number           = $serial_gen->generate( $certificate_type, $student_data_for_serial );
		}

		// Generate and add QR code
		if ( $qr_generator ) {
			$qr_enabled = $tmpl_meta['qr_enabled'][0] ?? '';
			if ( $qr_enabled === '1' ) {
				$qr_size        = (float) ( $tmpl_meta['qr_size'][0] ?? 15 );
				$qr_pos_x       = (float) ( $tmpl_meta['qr_position_x'][0] ?? 250 );
				$qr_pos_y       = (float) ( $tmpl_meta['qr_position_y'][0] ?? 180 );
				$qr_ec          = $tmpl_meta['qr_error_correction'][0] ?? 'L';
				$qr_data_fields = $tmpl_meta['qr_data_fields'][0] ?? '';

				$cert_data_for_qr = array_merge(
					$post_data,
					array(
						'certificate_type' => $certificate_type,
						'serial_number'    => $serial_number,
						'issued_at'        => current_time( 'mysql' ),
					)
				);

				$qr_data = $qr_generator->generate_qr_data( $cert_data_for_qr, $serial_number );
				$qr_generator->add_qr_to_pdf( $pdf, $qr_data, $qr_pos_x, $qr_pos_y, $qr_size );
			}
		}

		// Add serial number text to certificate
		if ( $qr_generator && ! empty( $serial_number ) ) {
			$serial_display = $tmpl_meta['serial_number_display'][0] ?? '';
			if ( $serial_display === '1' ) {
				$sn_pos_x     = (float) ( $tmpl_meta['serial_number_position_x'][0] ?? 105 );
				$sn_pos_y     = (float) ( $tmpl_meta['serial_number_position_y'][0] ?? 200 );
				$sn_font_size = (int) ( $tmpl_meta['serial_number_font_size'][0] ?? 10 );
				$qr_generator->add_serial_to_pdf( $pdf, $serial_number, $sn_pos_x, $sn_pos_y, $sn_font_size, $font_style );
			}
		}

		// Calculate expiration date
		$expires_at = null;
		$exp_unit   = $tmpl_meta['expiration_period_unit'][0] ?? 'never';
		$exp_value  = (int) ( $tmpl_meta['expiration_period_value'][0] ?? 0 );

		if ( $exp_unit !== 'never' && $exp_value > 0 ) {
			$issued_date = new DateTime();
			switch ( $exp_unit ) {
				case 'days':
					$issued_date->modify( "+{$exp_value} days" );
					break;
				case 'months':
					$issued_date->modify( "+{$exp_value} months" );
					break;
				case 'years':
					$issued_date->modify( "+{$exp_value} years" );
					break;
			}
			$expires_at = $issued_date->format( 'Y-m-d H:i:s' );
		}

		// Store serial number and time-based data in post meta
		if ( ! empty( $serial_number ) ) {
			update_post_meta( $post_id, 'certificate_serial_number', $serial_number );
		}
		update_post_meta( $post_id, 'certificate_issued_at', current_time( 'mysql' ) );
		if ( $expires_at ) {
			update_post_meta( $post_id, 'certificate_expires_at', $expires_at );
		}

		// Persist to certificate_generator table for verification, serial
		// sequencing, and analytics counting (even without a serial yet).
		if ( function_exists( 'certificate_generator_insert_certificate_record' ) ) {
			$record_data = array(
				'student_name'     => $post_data['student_name'] ?? get_post_meta( $post_id, 'student_name', true ),
				'teacher_name'     => $post_data['teacher_name'] ?? get_post_meta( $post_id, 'teacher_name', true ),
				'school_name'      => $post_data['school_name'] ?? get_post_meta( $post_id, 'school_name', true ),
				'certificate_type' => $certificate_type,
				'issue_date'       => $post_data['issue_date'] ?? get_post_meta( $post_id, 'issue_date', true ),
				'wp_post_id'       => $post_id,
				'recipient_type'   => $post_data['recipient_type'] ?? ( $detached_entity ?: get_post_type( $post_id ) ),
				'template_id'      => $certificate_template->id ?? null,
			);
			certificate_generator_insert_certificate_record( $record_data, $serial_number );
		}

		// Output PDF into dedicated cg_certificates folder.
		$upload_dir  = wp_upload_dir();
		$upload_path = certificate_generator_certificates_dir();

		$pdf_path = $upload_path . DIRECTORY_SEPARATOR . certificate_generator_certificate_file_stem( $pdf_key ) . '.pdf';
		// The old guessable name (before 7.5.3): remove it now that this certificate has a private one.
		if ( file_exists( $upload_path . DIRECTORY_SEPARATOR . "certificate_$pdf_key.pdf" ) ) {
			wp_delete_file( $upload_path . DIRECTORY_SEPARATOR . "certificate_$pdf_key.pdf" );
		}
		$pdf_path = wp_normalize_path( $pdf_path );

		certificate_generator_debug_log( "PDF file path: $pdf_path" );

		// Check if directory is writable
		if ( ! wp_is_writable( $upload_path ) ) {
			certificate_generator_debug_log( 'Certificate Generator: Upload directory is not writable: ' . $upload_path );
			return false;
		}

		// Stamp the cache key (computed now when a serial was just issued).
		if ( $use_table_data && ! empty( $serial_number ) && certificate_generator_pdf_cache_enabled() ) {
			if ( '' === $cache_key ) {
				$cache_key = certificate_generator_pdf_cache_key( $pdf_key, $tmpl_meta, $template_url, $field_positions, $post_data, (string) $serial_number );
			}
			$pdf->SetKeywords( 'cgk:' . $cache_key );
		}

		// Write to a temp file, then rename over the real one: readers (another request,
		// a ZIP being built) only ever see a complete PDF, never a half-written one.
		$tmp_path = $pdf_path . '.' . wp_generate_password( 8, false ) . '.tmp';
		try {
			$pdf->Output( 'F', $tmp_path );
			if ( ! @rename( $tmp_path, $pdf_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace of a temp file in our uploads dir
				// Windows refuses to replace a file another process holds open.
				$copied = @copy( $tmp_path, $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				wp_delete_file( $tmp_path );
				if ( ! $copied ) {
					throw new Exception( "could not move $tmp_path into place" );
				}
			}
		} catch ( Exception $e ) {
			wp_delete_file( $tmp_path );
			$error_msg = "PDF generation failed for post ID $post_id: " . $e->getMessage();
			certificate_generator_debug_log( 'Certificate Generator: ' . $error_msg );
			certificate_generator_debug_log( 'Template URL: ' . $template_url );
			certificate_generator_debug_log( 'PDF Path: ' . $pdf_path );
			certificate_generator_debug_log( 'Field Positions: ' . wp_json_encode( $field_positions ) );
			certificate_generator_debug_log( 'Post Data: ' . wp_json_encode( $post_data ) );
			return false;
		}

		// Check if file was created successfully
		if ( ! file_exists( $pdf_path ) ) {
			$error_msg = "PDF file not created at: $pdf_path";
			certificate_generator_debug_log( 'Certificate Generator: ' . $error_msg );
			certificate_generator_debug_log( 'Free disk space: ' . disk_free_space( $upload_path ) . ' bytes' );
			return false;
		}

		$file_url = certificate_generator_certificates_url() . '/' . certificate_generator_certificate_file_stem( $pdf_key ) . '.pdf';
		certificate_generator_debug_log( "PDF generated successfully. URL: $file_url" );

		// Store the actual file path in a post meta for easier retrieval
		update_post_meta( $post_id, 'certificate_file_path', $pdf_path );
		update_post_meta( $post_id, 'certificate_file_url', $file_url );

		// Dual-write: update wp_cg_certificates with pdf_path + pdf_url
		if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			global $wpdb;
			$tables     = \CertificateGenerator\Database\CustomTables::instance();
			$cert_table = $tables->get_table( 'certificates' );
			if ( $cert_table && $tables->table_exists( 'certificates' ) ) {
				$cert_row_id = null;
				if ( $post_id > 0 ) {
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
					$cert_row_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM $cert_table WHERE wp_post_id = %d ORDER BY id DESC LIMIT 1",
							$post_id
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				if ( ! $cert_row_id && ! empty( $serial_number ) ) {
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
					$cert_row_id = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM $cert_table WHERE serial_number = %s ORDER BY id DESC LIMIT 1",
							$serial_number
						)
					);
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
				if ( $cert_row_id ) {
					$wpdb->update(
						$cert_table,
						array(
							'pdf_path' => $pdf_path,
							'pdf_url'  => $file_url,
							'status'   => 'generated',
						),
						array( 'id' => (int) $cert_row_id ),
						array( '%s', '%s', '%s' ),
						array( '%d' )
					);
				}
			}
		}

		// Third argument: true only when this render issued a new serial ($serial_gen is set only when generate() ran).
		do_action( 'certificate_generator_certificate_generated', $post_id, $pdf_path, null !== $serial_gen );

		return $file_url;
	} catch ( Exception $e ) {
		certificate_generator_debug_log( 'Exception during PDF generation: ' . $e->getMessage() );
		return false;
	}
}

// ── Public shim for certificate_generator_generate_certificate_pdf ─────────────────────────────────
// When CG_USE_NEW_PDF=true, legacy-shims.php defines this function and routes
// it through PdfGenerator::make(). When the flag is off (default), this wrapper
// is used so all ~11 call sites continue to work unchanged.
if ( ! class_exists( '\CertificateGenerator\Core\Config' )
	|| ! \CertificateGenerator\Core\Config::flag( 'CG_USE_NEW_PDF' ) ) {
	/**
	 * @deprecated v8 Use \CertificateGenerator\Services\PdfGenerator::make() instead.
	 */
	function certificate_generator_generate_certificate_pdf( $post_id, $fields = array(), $student_data = null ) {
		return certificate_generator_generate_pdf_impl( $post_id, $fields, $student_data );
	}
}

/**
 * Generate certificate PDF for email purposes
 *
 * This function wraps certificate_generator_generate_certificate_pdf and returns both the file path and URL
 * to make it compatible with the email system in email-functions.php
 *
 * @param int        $post_id The post ID of the student, teacher, or school
 * @param array      $fields The fields to include in the certificate
 * @param array|null $email_options Optional email options
 * @return array|bool Array containing 'path' and 'url' of the generated PDF, or false on failure
 */
function certificate_generator_generate_certificate_pdf_email( $post_id, $fields, $email_options = null ) {
	// Resolve fields when caller didn't supply them.
	if ( empty( $fields ) ) {
		$post_type = get_post_type( $post_id );
		$cert_type = get_post_meta( $post_id, 'certificate_type', true );
		if ( class_exists( 'CertificateGenerator_Field_Schema' ) && $cert_type ) {
			$fields = CertificateGenerator_Field_Schema::get_all_renderable_fields( $cert_type );
		} else {
			switch ( $post_type ) {
				case 'teachers':
					$fields = array( 'teacher_name', 'school_name', 'issue_date' );
					break;
				case 'schools':
					$fields = array( 'school_name', 'issue_date' );
					break;
				default:
					$fields = array( 'student_name', 'school_name', 'issue_date' );
			}
		}
	}

	// certificate_generator_generate_certificate_pdf() does SQL-first data lookup, writes postmeta, returns URL.
	$certificate_url = certificate_generator_generate_certificate_pdf( $post_id, $fields );
	if ( ! $certificate_url ) {
		certificate_generator_debug_log( "Certificate Generator: PDF generation failed for post ID: {$post_id}" );
		return false;
	}

	// Path was written to postmeta by certificate_generator_generate_certificate_pdf — read it back.
	$certificate_path = wp_normalize_path( (string) get_post_meta( $post_id, 'certificate_file_path', true ) );
	if ( empty( $certificate_path ) || ! file_exists( $certificate_path ) ) {
		certificate_generator_debug_log( "Certificate Generator: PDF file missing after generation for post ID: {$post_id}" );
		return false;
	}

	return array(
		'path' => $certificate_path,
		'url'  => $certificate_url,
	);
}

// Shortcode to search for teacher certificates
add_shortcode( 'certificate_generator_teacher_search', 'certificate_generator_teacher_search_shortcode' );
function certificate_generator_teacher_search_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'title'       => '',
			'subtitle'    => '',
			'button_text' => '',
			'help_text'   => '',
		),
		$atts,
		'certificate_generator_teacher_search'
	);
	if ( isset( $_GET['teacher_email'] ) ) {
		$email = sanitize_email( wp_unslash( $_GET['teacher_email'] ) );

		// SQL-first: query wp_cg_teachers
		$sql_teachers = array();
		if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			$t_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'teachers' );
			if ( $t_table && $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $t_table ) ) === $t_table ) {
				$sql_teachers = $GLOBALS['wpdb']->get_results(
					$GLOBALS['wpdb']->prepare(
						"SELECT * FROM $t_table WHERE email = %s",
						$email
					),
					ARRAY_A
				) ?: array();
			}
		}

		// CPT fallback when SQL table is empty
		$cpt_teacher_posts = array();
		if ( empty( $sql_teachers ) ) {
			$args = array(
				'post_type'      => 'teachers',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'email',
						'value'   => $email,
						'compare' => '=',
					),
				),
			);
			$tq   = new WP_Query( $args );
			if ( $tq->have_posts() ) {
				while ( $tq->have_posts() ) {
					$tq->the_post();
					$tid                 = get_the_ID();
					$cpt_teacher_posts[] = array(
						'wp_post_id'       => $tid,
						'teacher_name'     => get_post_meta( $tid, 'teacher_name', true ) ?: get_the_title(),
						'school_name'      => get_post_meta( $tid, 'school_name', true ),
						'certificate_type' => get_post_meta( $tid, 'certificate_type', true ),
						'issue_date'       => get_post_meta( $tid, 'issue_date', true ),
					);
				}
				wp_reset_postdata();
			}
		}

		$all_teachers = ! empty( $sql_teachers ) ? $sql_teachers : $cpt_teacher_posts;

		$certificates_data           = array();
		$error_count                 = 0;
		$certificates_to_generate_bg = array(); // For background processing

		foreach ( $all_teachers as $_t_row ) {
			$post_id          = (int) ( $_t_row['wp_post_id'] ?? $_t_row['id'] ?? 0 );
			$teacher_name     = $_t_row['teacher_name'] ?? '';
			$school_name      = $_t_row['school_name'] ?? '';
			$certificate_type = $_t_row['certificate_type'] ?? '';
			$issue_date       = $_t_row['issue_date'] ?? '';
			$template_url     = '';  // resolved below by certificate_generator_generate_certificate_pdf / certificate_generator_resolve_student_template
			if ( true ) { // scope wrapper — matches old while($query->have_posts()) body below

				// ── Double-match pre-check: certificate_type + issue_date → event_date ──
				if ( ! certificate_generator_resolve_entity_template( $_t_row, $post_id ) ) {
					certificate_generator_debug_log(
						'Certificate Generator: No matching template for teacher post ' . $post_id
						. ' (type="' . $certificate_type . '", issue_date="' . $issue_date . '")'
					);
					++$error_count;
					$certificates_to_generate_bg[] = array(
						'post_id'          => $post_id,
						'student_name'     => $teacher_name,
						'school_name'      => $school_name,
						'certificate_type' => $certificate_type,
						'issue_date'       => $issue_date,
						'template_url'     => $template_url,
						'post_type'        => 'teachers',
					);
					continue; // no template → skip generation
				}

				$fields   = array( 'teacher_name', 'school_name', 'issue_date' );
				$file_url = certificate_generator_generate_certificate_pdf( $post_id, $fields, $_t_row );

				if ( $file_url ) {
					$certificates_data[] = array(
						'title'   => $teacher_name,
						'school'  => $school_name,
						'type'    => $certificate_type,
						'date'    => $issue_date,
						'url'     => $file_url,
						'post_id' => $post_id,
					);

					// Auto-send email if enabled and not already sent
					if ( function_exists( 'certificate_generator_auto_send_email' ) ) {
						certificate_generator_auto_send_email( $post_id, 'teachers' );
					}
				} else {
					++$error_count;
					$certificates_to_generate_bg[] = array(
						'post_id'          => $post_id,
						'student_name'     => $teacher_name,
						'school_name'      => $school_name,
						'certificate_type' => $certificate_type,
						'issue_date'       => $issue_date,
						'template_url'     => $template_url,
						'post_type'        => 'teachers',
					);
				}
			} // end scope wrapper
		} // end foreach $all_teachers

		// Get styling options with defaults
		$options       = get_option( 'certificate_generator_settings_email' );
		$bg_color      = $options['bg_color'] ?? '#f4f7f600';
		$card_bg       = $options['card_bg'] ?? '#ffffff';
		$title_color   = $options['title_color'] ?? '#2c3e50';
		$text_color    = $options['text_color'] ?? '#7f8c8d';
		$btn_start     = $options['btn_start'] ?? '#3498db';
		$btn_end       = $options['btn_end'] ?? '#2980b9';
		$border_radius = $options['border_radius'] ?? '12';
		$font_family   = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

		if ( ! empty( $certificates_data ) ) {
			$cert_count = count( $certificates_data );
			$output     = '<div class="certificates-container" style="background-color: ' . $bg_color . '; padding: ' . ( $cert_count === 1 ? '50px 20px' : '30px 15px' ) . '; font-family: ' . $font_family . ';">';

			// Title
			$output .= '<h2 style="text-align: center; color: ' . $title_color . '; margin-bottom: ' . ( $cert_count === 1 ? '40px' : '30px' ) . '; font-size: ' . ( $cert_count === 1 ? '32px' : '28px' ) . '; font-weight: 700;">';
			$output .= sprintf( /* translators: %d: number of certificates */ _n( '%d Certificate Found', '%d Certificates Found', $cert_count, 'certificate-generator' ), $cert_count );
			$output .= '</h2>';

			// Grid layout for certificates
			$grid_columns = $cert_count === 1 ? '1fr' : 'repeat(auto-fit, minmax(300px, 1fr))';
			$grid_gap     = $cert_count === 1 ? '0' : '25px';
			$output      .= '<div class="certificates-grid" style="display: grid; grid-template-columns: ' . $grid_columns . '; gap: ' . $grid_gap . '; max-width: ' . ( $cert_count === 1 ? '650px' : '1200px' ) . '; margin: 0 auto;">';

			foreach ( $certificates_data as $certificate ) {
				$output .= '<div class="certificate-card" style="background: ' . $card_bg . '; border-radius: ' . $border_radius . 'px; padding: ' . ( $cert_count === 1 ? '40px' : '30px' ) . '; box-shadow: 0 8px 25px rgba(0,0,0,0.07); transition: transform 0.3s ease, box-shadow 0.3s ease; display: flex; flex-direction: column; justify-content: space-between;" onmouseover="this.style.transform=\'translateY(-5px)\'; this.style.boxShadow=\'0 12px 30px rgba(0,0,0,0.1)\';" onmouseout="this.style.transform=\'translateY(0)\'; this.style.boxShadow=\'0 8px 25px rgba(0,0,0,0.07)\';">';

				// Certificate Title (Teacher Name)
				$output .= '<h3 style="color: ' . $title_color . '; font-size: ' . ( $cert_count === 1 ? '26px' : '22px' ) . '; margin: 0 0 ' . ( $cert_count === 1 ? '25px' : '20px' ) . '; font-weight: 600; line-height: 1.3;">' . esc_html( $certificate['title'] ) . '</h3>';

				// Certificate Details
				$output .= '<div style="margin-bottom: ' . ( $cert_count === 1 ? '30px' : '25px' ) . '; display: flex; flex-direction: column; gap: ' . ( $cert_count === 1 ? '15px' : '12px' ) . ';">';

				// School Name
				$output .= '<div style="display: flex; align-items: center;">' .
					'<div style="min-width: 24px; color: ' . $btn_start . '; margin-right: ' . ( $cert_count === 1 ? '15px' : '10px' ) . ';">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' .
					'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>' .
					'<polyline points="9 22 9 12 15 12 15 22"></polyline>' .
					'</svg></div>' .
					'<div><span style="font-weight: ' . ( $cert_count === 1 ? '600' : '500' ) . '; color: ' . $title_color . ';">School:</span> ' .
					'<span style="color: ' . $text_color . '; font-size: ' . ( $cert_count === 1 ? '15px' : '14px' ) . ';">' . esc_html( $certificate['school'] ) . '</span></div>' .
					'</div>';

				// Certificate Type
				$output .= '<div style="display: flex; align-items: center;">' .
					'<div style="min-width: 24px; color: ' . $btn_start . '; margin-right: ' . ( $cert_count === 1 ? '15px' : '10px' ) . ';">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' .
					'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path>' .
					'<polyline points="13 2 13 9 20 9"></polyline>' .
					'</svg></div>' .
					'<div><span style="font-weight: ' . ( $cert_count === 1 ? '600' : '500' ) . '; color: ' . $title_color . ';">Certificate:</span> ' .
					'<span style="color: ' . $text_color . '; font-size: ' . ( $cert_count === 1 ? '15px' : '14px' ) . ';">' . esc_html( $certificate['type'] ) . '</span></div>' .
					'</div>';

				// Issue Date
				$output .= '<div style="display: flex; align-items: center;">' .
					'<div style="min-width: 24px; color: ' . $btn_start . '; margin-right: ' . ( $cert_count === 1 ? '15px' : '10px' ) . ';">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' .
					'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>' .
					'<line x1="16" y1="2" x2="16" y2="6"></line>' .
					'<line x1="8" y1="2" x2="8" y2="6"></line>' .
					'<line x1="3" y1="10" x2="21" y2="10"></line>' .
					'</svg></div>' .
					'<div><span style="font-weight: ' . ( $cert_count === 1 ? '600' : '500' ) . '; color: ' . $title_color . ';">Issued On:</span> ' .
					'<span style="color: ' . $text_color . '; font-size: ' . ( $cert_count === 1 ? '15px' : '14px' ) . ';">' . esc_html( $certificate['date'] ) . '</span></div>' .
					'</div>';

				$output .= '</div>'; // End of certificate details

				// Download button
				$btn_width           = $cert_count === 1 ? '60%' : '80%';
				$btn_padding         = $cert_count === 1 ? '16px 24px' : '14px 20px';
				$btn_font_size       = $cert_count === 1 ? '16px' : '14px';
				$btn_container_style = $cert_count === 1 ? 'text-align: center; margin-top: 30px;' : 'margin-top: auto;'; // Pushes button to bottom for multi-card

				$output .= '<div style="' . $btn_container_style . '">';
				$output .= '<a href="' . esc_url( $certificate['url'] ) . '" target="_blank" ' .
					'style="display: inline-block; width: ' . $btn_width . '; padding: ' . $btn_padding . '; ' .
					'background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); ' .
					'color: white; text-decoration: none; border-radius: ' . $border_radius . 'px; ' .
					'font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); ' .
					'text-align: center; text-transform: uppercase; letter-spacing: 0.5px; font-size: ' . $btn_font_size . ';" ' .
					'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
					'this.style.boxShadow=\'0 6px 15px rgba(0, 0, 0, 0.15)\';" ' .
					'onmouseout="this.style.transform=\'translateY(0)\'; ' .
					'this.style.boxShadow=\'0 4px 10px rgba(0, 0, 0, 0.1)\';">'
					. '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' .
					'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
					'stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px;">' .
					'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>' .
					'<polyline points="7 10 12 15 17 10"></polyline>' .
					'<line x1="12" y1="15" x2="12" y2="3"></line>' .
					'</svg>Download Certificate</a>';
				$output .= '</div>';

				$output .= '</div>'; // End of card
			}
			$output .= '</div>'; // End of grid
			$output .= '</div>'; // End of container

		} else { // No certificates found or all had errors
			$output  = '<div class="certificate-not-found" style="max-width: 600px; margin: 40px auto; font-family: ' . $font_family . ';">';
			$output .= '<div style="padding: 40px; background: ' . $card_bg . '; border-radius: ' . $border_radius . 'px; ' .
				'box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); text-align: center;">';
			$output .= '<div style="margin-bottom: 25px; animation: pulse 2s infinite;">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" ' .
				'stroke="' . $btn_start . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
				'<circle cx="12" cy="12" r="10"></circle>' .
				'<line x1="12" y1="8" x2="12" y2="12"></line>' .
				'<line x1="12" y1="16" x2="12.01" y2="16"></line>' .
				'</svg></div>';
			$output .= '<h2 style="color: ' . $title_color . '; font-size: 28px; margin: 0 0 15px; font-weight: 700;">' .
				__( 'No Certificate Found', 'certificate-generator' ) . '</h2>';
			$output .= '<p style="color: ' . $text_color . '; margin-bottom: 30px; line-height: 1.6; font-size: 16px;">' .
				__( 'We couldn\'t find any certificates matching the email address you provided for a teacher.', 'certificate-generator' ) . '</p>';
			// ... (rest of the no-found message, similar to student search but adapted for teachers)
			$output .= '<p style="color: ' . $text_color . '; margin-bottom: 20px; line-height: 1.6;">' .
				__( 'If you are unable to find the certificate here, please drop a request email to us on:', 'certificate-generator' ) . ' ' .
				certificate_generator_get_contact_email() . ' ' .
				__( 'to resend it over email.', 'certificate-generator' ) . '</p>';
			$output .= '<div style="margin-bottom: 20px;">';
			$output .= '<p style="font-weight: bold; margin: 0 0 8px; color: ' . $title_color . '; font-size: 16px;">' .
				__( 'Please include following details in your email:', 'certificate-generator' ) . '</p>';
			$output .= '<ul style="margin: 0; padding-left: 20px; list-style-type: disc; color: ' . $text_color . ';">';
			$output .= '<li style="margin-bottom: 8px;">' . __( 'Registered Email Id (Teacher)', 'certificate-generator' ) . '</li>';
			$output .= '<li style="margin-bottom: 8px;">' . __( 'Teacher Name', 'certificate-generator' ) . '</li>';
			$output .= '<li style="margin-bottom: 8px;">' . __( 'School Name', 'certificate-generator' ) . '</li>';
			$output .= '<li style="margin-bottom: 0;">' . __( 'Certificate Type', 'certificate-generator' ) . '</li>';
			$output .= '</ul></div>';

			$output .= '<div style="background: linear-gradient(to right, rgba(' . certificate_generator_hex2rgb_str( $btn_start ) . ', 0.05), ' .
				'rgba(' . certificate_generator_hex2rgb_str( $btn_end ) . ', 0.05)); border-radius: ' . $border_radius . 'px; ' .
				'padding: 25px; margin: 25px 0; border-left: 4px solid ' . $btn_start . ';">';
			$output .= '<div style="display: flex; align-items: center; justify-content: center; margin-bottom: 15px;">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" ' .
				'stroke="' . $btn_start . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' .
				'style="margin-right: 10px;">' .
				'<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>' .
				'</svg>' .
				'<h3 style="color: ' . $title_color . '; margin: 0; font-size: 18px;">' .
				__( 'Need Help Finding Your Certificate?', 'certificate-generator' ) . '</h3></div>';
			$output .= '<p style="color: ' . $text_color . '; margin-bottom: 20px; line-height: 1.6;">' .
				__( 'If you believe this is an error or need assistance, please contact our support team.', 'certificate-generator' ) . '</p>';
			$output .= '<a href="mailto:' . certificate_generator_get_contact_email() . '" ' .
				'style="display: inline-flex; align-items: center; padding: 12px 24px; ' .
				'background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); ' .
				'color: white; text-decoration: none; border-radius: ' . $border_radius . 'px; ' .
				'font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);" ' .
				'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
				'this.style.boxShadow=\'0 8px 20px rgba(0, 0, 0, 0.15)\'" ' .
				'onmouseout="this.style.transform=\'translateY(0)\'; ' .
				'this.style.boxShadow=\'0 4px 15px rgba(0, 0, 0, 0.1)\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" ' .
				'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
				'stroke-linejoin="round" style="margin-right: 8px;">' .
				'<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>' .
				'<polyline points="22,6 12,13 2,6"></polyline>' .
				'</svg>' . __( 'Contact Support', 'certificate-generator' ) . '</a>';
			$output .= '</div>';
			$output .= '<a href="' . esc_url( remove_query_arg( 'teacher_email' ) ) . '" ' .
				'style="display: inline-flex; align-items: center; margin-top: 10px; ' .
				'color: ' . $text_color . '; text-decoration: none; font-weight: 500; transition: color 0.3s ease;" ' .
				'onmouseover="this.style.color=\'' . $btn_start . '\'" ' .
				'onmouseout="this.style.color=\'' . $text_color . '\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' .
				'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
				'stroke-linejoin="round" style="margin-right: 6px;">' .
				'<line x1="19" y1="12" x2="5" y2="12"></line>' .
				'<polyline points="12 19 5 12 12 5"></polyline>' .
				'</svg>' . __( 'Back to Search', 'certificate-generator' ) . '</a>';
			$output .= '</div>'; // End of error card
			$output .= '<style>
                @keyframes pulse {
                    0% { transform: scale(1); opacity: 1; }
                    50% { transform: scale(1.05); opacity: 0.8; }
                    100% { transform: scale(1); opacity: 1; }
                }
            </style>';
			$output .= '</div>'; // End container
		}

		// Trigger background processing for certificates that failed direct generation
		if ( ! empty( $certificates_to_generate_bg ) ) {
			// Ensure the action name matches the one hooked in your plugin
			wp_schedule_single_event( time(), 'certificate_generator_generate_certificates_background_teachers', array( $certificates_to_generate_bg ) );
		}

		// If any certificates had errors, show the error message
		if ( $error_count > 0 ) {
			// Create a well-structured and user-friendly 'Certificate Generation Error' message
			$output = '<div style="max-width: 600px; margin: 30px auto; padding: 25px; background: #ffffff; border-radius: 10px; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); text-align: center;">';

			// Header section with icon and title
			$output .= '<div style="margin-bottom: 20px;">';
			$output .= '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#d32f2f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
			$output .= '<h2 style="color: #d32f2f; font-size: 22px; margin: 15px 0 5px;">Certificate Generation Error</h2>';
			$output .= '<p style="color: #666; font-size: 16px; margin: 0 0 15px;">We found your record but couldn\'t generate your certificate due to a technical issue.</p>';
			$output .= '</div>';

			// Contact information section
			$output .= '<div style="margin-bottom: 25px; padding: 0 15px;">';
			$output .= '<p style="color: #555; font-size: 15px; line-height: 1.5; margin-bottom: 15px;">Please contact us at the email below and we\'ll resolve this issue for you:</p>';
			$output .= '<p style="color: #555; font-size: 15px; line-height: 1.5;">Email: <a href="mailto:support@example.com" style="color: #0073aa; font-weight: 500; text-decoration: none;">support@example.com</a></p>';
			$output .= '</div>';

			// Required information box
			$output .= '<div style="background: #f8f8f8; border-radius: 8px; padding: 20px; margin: 0 auto; text-align: left; border-left: 4px solid #0073aa;">';
			$output .= '<p style="font-weight: bold; margin: 0 0 12px; color: #333; font-size: 16px;">Please include these details in your email:</p>';
			$output .= '<ul style="margin: 0; padding-left: 20px; list-style-type: disc; color: #555;">';
			$output .= '<li style="margin-bottom: 8px;">Your Full Name</li>';
			$output .= '<li style="margin-bottom: 8px;">School Name</li>';
			$output .= '<li style="margin-bottom: 0;">Certificate Type</li>';
			$output .= '</ul>';
			$output .= '</div>';

			$output .= '</div>';
		}
	} else {
		// Get styling options with defaults
		$options       = get_option( 'certificate_generator_settings_email' );
		$title_color   = $options['title_color'] ?? '#2c3e50';
		$text_color    = $options['text_color'] ?? '#7f8c8d';
		$btn_start     = $options['btn_start'] ?? '#3498db';
		$btn_end       = $options['btn_end'] ?? '#2980b9';
		$border_radius = $options['border_radius'] ?? '12';
		$font_family   = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

		// Modern search form with consistent styling
		$output = '<div class="certificate-search-container" style="max-width: 600px; margin: 40px auto; font-family: ' . $font_family . ';">';

		// Form with enhanced styling
		$output .= '<form method="get" style="padding: 35px; background: #ffffff; border-radius: ' . $border_radius . 'px; ' .
			'box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); transition: all 0.3s ease;">';

		// Heading with icon
		$output .= '<div style="text-align: center; margin-bottom: 30px;">' .
			'<h2 style="color: ' . $title_color . '; margin: 0; font-size: 28px; font-weight: 700;">' .
			certificate_generator_get_shortcode_text( 'teacher_title', $atts['title'], __( 'Teacher Certificate Lookup', 'certificate-generator' ) ) . '</h2>' .
			'<p style="color: ' . $text_color . '; margin-top: 10px; font-size: 16px;">' .
			certificate_generator_get_shortcode_text( 'teacher_subtitle', $atts['subtitle'], __( 'Enter teacher email to find their certificates', 'certificate-generator' ) ) . '</p>' .
			'</div>';

		// Input field with floating label effect
		$output .= '<div style="position: relative; margin-bottom: 30px;">';
		$output .= '<label for="teacher_email" style="position: absolute; left: 16px; top: 18px; ' .
			'color: ' . $text_color . '; font-size: 16px; transition: all 0.2s ease; pointer-events: none;">' .
			__( 'Teacher Email Address', 'certificate-generator' ) . '</label>'; // Changed label
		$output .= '<input type="email" id="teacher_email" name="teacher_email" required ' . // Changed id and name
			'placeholder="" style="width: 100%; padding: 18px 16px; padding-top: 26px; padding-bottom: 10px; ' .
			'background: #f8f9fa; border: 2px solid #eaeaea; border-radius: ' . $border_radius . 'px; ' .
			'font-size: 16px; transition: all 0.3s ease; outline: none; box-sizing: border-box;" ' .
			'onfocus="this.style.borderColor=\'' . $btn_start . '\'; ' .
			'this.previousElementSibling.style.top=\'8px\'; ' .
			'this.previousElementSibling.style.fontSize=\'12px\'; ' .
			'this.previousElementSibling.style.color=\'' . $btn_start . '\'" ' .
			'onblur="if(this.value===\'\'){this.style.borderColor=\'#eaeaea\'; ' .
			'this.previousElementSibling.style.top=\'18px\'; ' .
			'this.previousElementSibling.style.fontSize=\'16px\'; ' .
			'this.previousElementSibling.style.color=\'' . $text_color . '\'} ' .
			'else{this.style.borderColor=\'#eaeaea\'; ' .
			'this.previousElementSibling.style.color=\'' . $text_color . '\';}">';
		$output .= '</div>';

		// Submit button with icon and hover effect
		$output .= '<button type="submit" style="display: flex; align-items: center; justify-content: center; ' .
			'width: 100%; padding: 16px; background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); ' .
			'color: #fff; border: none; border-radius: ' . $border_radius . 'px; font-size: 16px; ' .
			'font-weight: 600; cursor: pointer; text-align: center; transition: all 0.3s ease; ' .
			'box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); transform: translateY(0);" ' .
			'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
			'this.style.boxShadow=\'0 8px 20px rgba(0, 0, 0, 0.15)\'" ' .
			'onmouseout="this.style.transform=\'translateY(0)\'; ' .
			'this.style.boxShadow=\'0 4px 15px rgba(0, 0, 0, 0.1)\'">' .
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" ' .
			'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
			'stroke-linejoin="round" style="margin-right: 8px;">' .
			'<circle cx="11" cy="11" r="8"></circle>' .
			'<line x1="21" y1="21" x2="16.65" y2="16.65"></line>' .
			'</svg>' . certificate_generator_get_shortcode_text( 'teacher_button', $atts['button_text'], __( 'Search Certificates', 'certificate-generator' ) ) . '</button>';

		$output .= '</form>';

		// Add help text
		$output .= '<div style="text-align: center; margin-top: 20px; padding: 0 15px;">';
		$output .= '<p style="color: ' . $text_color . '; font-size: 14px;">' .
			certificate_generator_get_shortcode_text( 'teacher_help', $atts['help_text'], __( 'Enter the email address of the teacher to find their certificates.', 'certificate-generator' ) ) .
			'</p>';
		$output .= '</div>';

		$output .= '</div>'; // End container
	}
	return $output;
}

// Shortcode to search for school certificates
add_shortcode( 'certificate_generator_school_search', 'certificate_generator_school_search_shortcode' );
function certificate_generator_school_search_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'title'       => '',
			'subtitle'    => '',
			'button_text' => '',
			'help_text'   => '',
		),
		$atts,
		'certificate_generator_school_search'
	);
	// Check if input parameters are provided
	if ( isset( $_GET['school_name'] ) && isset( $_GET['place'] ) ) {
		$school_name_query = sanitize_text_field( wp_unslash( $_GET['school_name'] ) );
		$place_query       = sanitize_text_field( wp_unslash( $_GET['place'] ) );

		// SQL-first: query wp_cg_schools
		$sql_schools = array();
		if ( class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
			$sc_table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'schools' );
			if ( $sc_table && $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SHOW TABLES LIKE %s', $sc_table ) ) === $sc_table ) {
				$like_name   = '%' . $GLOBALS['wpdb']->esc_like( $school_name_query ) . '%';
				$like_place  = '%' . $GLOBALS['wpdb']->esc_like( $place_query ) . '%';
				$sql_schools = $GLOBALS['wpdb']->get_results(
					$GLOBALS['wpdb']->prepare(
						"SELECT * FROM $sc_table WHERE school_name LIKE %s AND (city LIKE %s OR state LIKE %s OR address LIKE %s)",
						$like_name,
						$like_place,
						$like_place,
						$like_place
					),
					ARRAY_A
				) ?: array();
			}
		}

		// CPT fallback
		if ( empty( $sql_schools ) ) {
			$_sc_cpt_args = array(
				'post_type'      => 'schools',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => 'place',
						'value'   => $place_query,
						'compare' => 'LIKE',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => 'school_name',
							'value'   => $school_name_query,
							'compare' => 'LIKE',
						),
						array(
							'key'     => 'school_abbreviation',
							'value'   => $school_name_query,
							'compare' => 'LIKE',
						),
					),
				),
			);
			$_scq         = new WP_Query( $_sc_cpt_args );
			if ( $_scq->have_posts() ) {
				while ( $_scq->have_posts() ) {
					$_scq->the_post();
					$scid          = get_the_ID();
					$sql_schools[] = array(
						'wp_post_id'       => $scid,
						'school_name'      => get_post_meta( $scid, 'school_name', true ),
						'city'             => get_post_meta( $scid, 'place', true ),
						'issue_date'       => get_post_meta( $scid, 'issue_date', true ),
						'certificate_type' => get_post_meta( $scid, 'certificate_type', true ),
					);
				}
				wp_reset_postdata();
			}
		}

		$certificates_data           = array();
		$certificates_to_generate_bg = array();
		$error_count                 = 0;
		$processed_certificates      = array();
		$total_certificates_found    = count( $sql_schools );

		foreach ( $sql_schools as $_sc_row ) {
			$post_id             = (int) ( $_sc_row['wp_post_id'] ?? $_sc_row['id'] ?? 0 );
			$current_school_name = $_sc_row['school_name'] ?? '';
			$current_place       = $_sc_row['city'] ?? $_sc_row['place'] ?? '';
			$current_issue_date  = $_sc_row['issue_date'] ?? '';
			$current_cert_type   = $_sc_row['certificate_type'] ?? '';
			if ( true ) { // scope wrapper — body continues below

				// Create fields to pass with actual values, not just field names
				$fields_to_pass = array(
					'school_name'      => $current_school_name,
					'place'            => $current_place,
					'issue_date'       => $current_issue_date,
					'certificate_type' => $current_cert_type,
				);
				$fields         = array( 'school_name', 'place', 'issue_date' );

				// ── Double-match pre-check: certificate_type + issue_date → event_date ──
				if ( ! certificate_generator_resolve_entity_template( $_sc_row, $post_id ) ) {
					certificate_generator_debug_log(
						'Certificate Generator: No matching template for school post ' . $post_id
						. ' (type="' . $current_cert_type . '", issue_date="' . $current_issue_date . '")'
					);
					++$error_count;
					$certificates_to_generate_bg[] = array(
						'post_id' => $post_id,
						'fields'  => $fields_to_pass,
					);
					continue; // no template → skip generation
				}

				// Try to generate PDF directly
				$pdf_result = certificate_generator_generate_certificate_pdf( $post_id, $fields, $_sc_row );

				if ( is_wp_error( $pdf_result ) ) {
					++$error_count;
					// Log error or add to a list for background processing
					$certificates_to_generate_bg[] = array(
						'post_id' => $post_id,
						'fields'  => $fields_to_pass,
					);
					// Log detailed error for direct generation failure
					$timestamp   = gmdate( 'Y-m-d H:i:s' );
					$log_message = "[$timestamp] Direct generation error for school certificate (Post ID: $post_id): {$pdf_result->get_error_message()}\n";
					certificate_generator_debug_log( $log_message );
				} elseif ( $pdf_result && ! empty( $pdf_result ) ) {
					// Sanitize file names for ZIP with improved uniqueness
					$s_name_sanitized    = sanitize_file_name( $current_school_name );
					$place_sanitized     = sanitize_file_name( $current_place );
					$cert_type_sanitized = ! empty( $current_cert_type ) ? sanitize_file_name( $current_cert_type ) : 'standard';
					$timestamp           = current_time( 'timestamp' );

					$unique_id       = $post_id . '-' . substr( md5( $s_name_sanitized . $place_sanitized . $timestamp ), 0, 8 );
					$unique_filename = certificate_generator_certificate_pdf_filename( $current_school_name, $current_cert_type, $unique_id );

					// Convert URL to file path
					$upload_dir = wp_upload_dir();
					$file_path  = str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $pdf_result );

					// Only add if this certificate hasn't been processed yet
					$certificate_key = md5( $file_path );
					if ( ! isset( $processed_certificates[ $certificate_key ] ) ) {
						// Create certificate data entry with proper URL and path
						$certificates_data[ $post_id ] = array(
							'url'              => $pdf_result,
							'path'             => $file_path,
							'filename'         => $unique_filename,
							'post_id'          => $post_id,
							'school_name'      => $current_school_name,
							'place'            => $current_place,
							'issue_date'       => $current_issue_date,
							'certificate_type' => $current_cert_type,
						);

						// Mark this certificate as processed
						$processed_certificates[ $certificate_key ] = true;

						// Auto-send email if enabled and not already sent
						if ( function_exists( 'certificate_generator_auto_send_email' ) ) {
							certificate_generator_auto_send_email( $post_id, 'schools' );
						}
					}
				} else {
					++$error_count;
					$certificates_to_generate_bg[] = array(
						'post_id' => $post_id,
						'fields'  => $fields_to_pass,
					);
				}
			} // end scope wrapper
		} // end foreach $sql_schools

			// Prepare ZIP download if multiple certificates exist and were successfully generated
			$bulk_download_link = '';
		if ( count( $certificates_data ) > 1 && function_exists( 'certificate_generator_create_zip_for_email' ) ) {
			$zip_result = certificate_generator_create_zip_for_email(
				array_values( $certificates_data ),
				$school_name_query . ' ' . $place_query
			);
			if ( $zip_result && $zip_result['certificate_count'] > 0 ) {
				$bulk_download_link = $zip_result['zip_url'];
			}
		}

			// Get styling options from plugin settings
			$options       = get_option( 'certificate_generator_settings_email' );
			$card_bg       = $options['card_bg'] ?? '#f9f9f9';
			$title_color   = $options['title_color'] ?? '#2c3e50';
			$text_color    = $options['text_color'] ?? '#7f8c8d';
			$btn_start     = $options['btn_start'] ?? '#3498db';
			$btn_end       = $options['btn_end'] ?? '#2980b9';
			$hover_effect  = $options['hover_effect'] ?? '5'; // Default hover effect in px
			$border_radius = $options['border_radius'] ?? '12';
			$font_family   = $options['font_family'] ?? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

			$output = '<div class="certificate-results-container" style="max-width: 1200px; margin: 20px auto; font-family: ' . esc_attr( $font_family ) . ';">';

			// Results header
			$output                .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
			$actual_certs_generated = count( $certificates_data );
			$output                .= '<h2 style="font-size: 24px; color: ' . esc_attr( $title_color ) . '; margin: 0; font-weight: 600;">' .
				sprintf(
					/* translators: 1: number of certificates, 2: school name */ _n( 'Found %1$s Certificate for %2$s', 'Found %1$s Certificates for %2$s', $actual_certs_generated, 'certificate-generator' ),
					'<span style="color: ' . esc_attr( $btn_start ) . ';">' . $actual_certs_generated . '</span>',
					esc_html( $school_name_query )
				) . '</h2>';

		if ( $bulk_download_link ) {
			$output .= '<div><a href="' . esc_url( $bulk_download_link ) . '" ' .
				'class="bulk-download-button" style="display: inline-block; padding: 12px 24px; ' .
				'background: linear-gradient(to right, ' . esc_attr( $btn_start ) . ', ' . esc_attr( $btn_end ) . '); ' .
				'color: white; text-decoration: none; border-radius: ' . esc_attr( $border_radius ) . 'px; font-weight: 600; ' .
				'transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); text-align: center;" ' .
				'onmouseover="this.style.transform=\'translateY(-2px) scale(1.02)\';this.style.boxShadow=\'0 6px 20px rgba(0,0,0,0.2)\'" ' .
				'onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 4px 15px rgba(0,0,0,0.1)\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>' .
				__( 'Download All Certificates', 'certificate-generator' ) . ' (' . $actual_certs_generated . ')</a></div>';
		}
			$output .= '</div>'; // End of header

			// Grid container
			$cert_count_display = count( $certificates_data );
			$grid_columns       = $cert_count_display === 1 ? 'minmax(300px, 600px)' : 'repeat(auto-fit, minmax(300px, 1fr))';
			$grid_justify       = $cert_count_display === 1 ? 'center' : 'stretch';

			$output .= '<div class="certificate-grid" style="display: grid; grid-template-columns: ' . esc_attr( $grid_columns ) . '; ' .
				'gap: 25px; padding: ' . ( $cert_count_display > 0 ? '25px' : '0' ) . '; background: ' . ( $cert_count_display > 0 ? esc_attr( $card_bg ) : 'transparent' ) . '; border-radius: ' . esc_attr( $border_radius ) . 'px; ' .
				'box-shadow: ' . ( $cert_count_display > 0 ? '0 10px 30px rgba(0, 0, 0, 0.05)' : 'none' ) . '; justify-content: ' . esc_attr( $grid_justify ) . ';">';

		if ( ! empty( $certificates_data ) ) {
			foreach ( $certificates_data as $cert_id => $data ) {
				$output .= '<div class="certificate-card" style="background: #ffffff; border-radius: ' . esc_attr( $border_radius ) . 'px; padding: 20px; box-shadow: 0 5px 15px rgba(0,0,0,0.08); display: flex; flex-direction: column; justify-content: space-between; transition: all 0.3s ease;" ' .
					'onmouseover="this.style.transform=\'translateY(-' . esc_attr( $hover_effect ) . 'px)\';this.style.boxShadow=\'0 ' . ( 5 + (int) $hover_effect ) . 'px ' . ( 15 + (int) $hover_effect * 2 ) . 'px rgba(0,0,0,0.12)\'" ' .
					'onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 5px 15px rgba(0,0,0,0.08)\'">';
				$output .= '<div>'; // Content wrapper
				$output .= '<h3 style="font-size: 18px; color: ' . esc_attr( $title_color ) . '; margin-top: 0; margin-bottom: 12px; font-weight: 600;">' . esc_html( $data['school_name'] ) . '</h3>';

				// School Icon and Name (already in h3)
				// Place
				$output .= '<p style="font-size: 14px; color: ' . esc_attr( $text_color ) . '; margin-bottom: 8px; display: flex; align-items: center;">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px; color: ' . esc_attr( $btn_start ) . ';"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>' .
					esc_html( $data['place'] ) . '</p>';

				// Issue Date
				$output .= '<p style="font-size: 14px; color: ' . esc_attr( $text_color ) . '; margin-bottom: 8px; display: flex; align-items: center;">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px; color: ' . esc_attr( $btn_start ) . ';"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>' .
					esc_html( $data['issue_date'] ) . '</p>';

				// Certificate Type (if available for schools)
				if ( ! empty( $data['certificate_type'] ) ) {
					$output .= '<p style="font-size: 14px; color: ' . esc_attr( $text_color ) . '; margin-bottom: 15px; display: flex; align-items: center;">' .
						'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 8px; color: ' . esc_attr( $btn_start ) . ';"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"></path><polyline points="13 2 13 9 20 9"></polyline></svg>' .
						esc_html( $data['certificate_type'] ) . '</p>';
				} else {
					$output .= '<p style="font-size: 14px; color: ' . esc_attr( $text_color ) . '; margin-bottom: 15px; display: flex; align-items: center;">&nbsp;</p>'; // Placeholder for consistent height if no cert type
				}
				$output .= '</div>'; // End Content wrapper

				// Download Button
				$output .= '<div style="margin-top: auto;">'; // Button wrapper for bottom alignment
				$output .= '<a href="' . esc_url( $data['url'] ) . '" target="_blank" ' .
					'style="display: block; padding: 10px 15px; background: linear-gradient(to right, ' . esc_attr( $btn_start ) . ', ' . esc_attr( $btn_end ) . '); ' .
					'color: white; text-decoration: none; border-radius: ' . esc_attr( $border_radius ) . 'px; font-weight: 600; text-align: center; ' .
					'transition: all 0.3s ease; box-shadow: 0 4px 8px rgba(0,0,0,0.1);" ' .
					'onmouseover="this.style.transform=\'translateY(-2px) scale(1.02)\';this.style.boxShadow=\'0 6px 12px rgba(0,0,0,0.15)\'" ' .
					'onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 4px 8px rgba(0,0,0,0.1)\'">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>' .
					__( 'Download Certificate', 'certificate-generator' ) . '</a>';
				$output .= '</div>'; // End Button wrapper
				$output .= '</div>'; // End certificate-card
			}
		}
			$output .= '</div>'; // End certificate-grid
			$output .= '</div>'; // End certificate-results-container
			// After processing all posts from the query or if query had no posts
		if ( empty( $certificates_data ) && isset( $school_name_query ) ) { // Ensure search was actually performed
			// This block handles cases where:
			// 1. Initial WP_Query found no matching school posts.
			// 2. WP_Query found posts, but all PDF generations failed (direct and none queued for BG).
			$options = get_option( 'certificate_generator_settings_email' );
			// $card_bg = $options['card_bg'] ?? '#f9f9f9'; // Not directly used for error message background
			$title_color   = $options['title_color'] ?? '#2c3e50';
			$text_color    = $options['text_color'] ?? '#7f8c8d';
			$btn_start     = $options['btn_start'] ?? '#3498db';
			$btn_end       = $options['btn_end'] ?? '#2980b9';
			$border_radius = $options['border_radius'] ?? '12';
			$font_family   = $options['font_family'] ?? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';
			$support_email = get_option( 'certificate_generator_support_email', get_option( 'admin_email' ) );

			// Initialize $output if it hasn't been (e.g. if $query->have_posts() was false)
			if ( ! isset( $output ) ) {
				$output = '';
			}

			// If there were errors during generation attempts, but ultimately no certs were successfully generated
			// $total_certificates_found is defined if $query->have_posts() was true.
			// We need to ensure $school_name_query and $place_query are set from the $_GET params.
			if ( $error_count > 0 && isset( $total_certificates_found ) && $total_certificates_found > 0 ) {
				$output  = '<div class="certificate-error-container" style="max-width: 700px; margin: 40px auto; padding: 30px; background: #ffffff; border-radius: ' . esc_attr( $border_radius ) . 'px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); text-align: center; font-family: ' . esc_attr( $font_family ) . ';">';
				$output .= '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $btn_end ) . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 20px; animation: pulseWarn 2s infinite;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
				$output .= '<style>@keyframes pulseWarn { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.7; transform: scale(1.05); } }</style>';
				$output .= '<h2 style="color: ' . esc_attr( $title_color ) . '; font-size: 24px; margin-top: 0; margin-bottom: 15px; font-weight: 600;">' . __( 'Certificate Generation Issue', 'certificate-generator' ) . '</h2>';
				$output .= '<p style="color: ' . esc_attr( $text_color ) . '; font-size: 16px; line-height: 1.6; margin-bottom: 20px;">' . sprintf( /* translators: 1: school name, 2: place */ __( 'We found records for %1$s in %2$s, but encountered an issue while generating the certificate(s). Some certificates might be processed in the background. If you don\'t receive them shortly, please contact support.', 'certificate-generator' ), '<strong>' . esc_html( $school_name_query ) . '</strong>', '<strong>' . esc_html( $place_query ) . '</strong>' ) . '</p>';
				$output .= '<div style="text-align: left; margin-top: 20px; margin-bottom: 25px; padding: 15px; background-color: #f8f9fa; border-radius: ' . esc_attr( $border_radius ) . 'px; border: 1px solid #e9ecef;">';
				$output .= '<h4 style="color: ' . esc_attr( $title_color ) . '; margin-top:0; margin-bottom:10px; font-size: 16px; font-weight: 600;">' . __( 'Please provide the following when contacting support:', 'certificate-generator' ) . '</h4>';
				$output .= '<ul style="list-style-type: disc; margin-left: 20px; padding-left: 0; color: ' . esc_attr( $text_color ) . '; font-size: 14px; line-height: 1.6;">';
				$output .= '<li>' . __( 'School Name Searched:', 'certificate-generator' ) . ' <strong>' . esc_html( $school_name_query ) . '</strong></li>';
				$output .= '<li>' . __( 'Place Searched:', 'certificate-generator' ) . ' <strong>' . esc_html( $place_query ) . '</strong></li>';
				$output .= '<li>' . __( 'Approximate Date/Time of Search:', 'certificate-generator' ) . ' ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), current_time( 'timestamp' ) ) . '</li>';
				$output .= '<li>' . __( 'Any other relevant details or error messages you noticed.', 'certificate-generator' ) . '</li>';
				$output .= '</ul>';
				$output .= '</div>';
			} else { // No school posts found by the query at all, or $query had posts but all failed silently (less likely with current logic)
				$output  = '<div class="certificate-error-container" style="max-width: 700px; margin: 40px auto; padding: 30px; background: #ffffff; border-radius: ' . esc_attr( $border_radius ) . 'px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); text-align: center; font-family: ' . esc_attr( $font_family ) . ';">';
				$output .= '<svg xmlns="http://www.w3.org/2000/svg" width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr( $btn_start ) . '" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 20px; animation: pulseInfo 2s infinite;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>';
				$output .= '<style>@keyframes pulseInfo { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.7; transform: scale(1.05); } }</style>';
				$output .= '<h2 style="color: ' . esc_attr( $title_color ) . '; font-size: 24px; margin-top: 0; margin-bottom: 15px; font-weight: 600;">' . __( 'No Certificates Found', 'certificate-generator' ) . '</h2>';
				$output .= '<p style="color: ' . esc_attr( $text_color ) . '; font-size: 16px; line-height: 1.6; margin-bottom: 25px;">' . sprintf( /* translators: 1: school name, 2: place */ __( 'We couldn\'t find any certificates matching %1$s in %2$s. Please double-check the school name and place, or try a different search.', 'certificate-generator' ), '<strong>' . esc_html( $school_name_query ) . '</strong>', '<strong>' . esc_html( $place_query ) . '</strong>' ) . '</p>';
			}
			// Common part for both error messages (contact support, back to search)
			$output .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0;">';
			$output .= '<p style="color: ' . esc_attr( $text_color ) . '; font-size: 14px; margin-bottom: 15px;">' . __( 'If you believe this is an error or need assistance, please contact our support team.', 'certificate-generator' ) . '</p>';
			$output .= '<a href="mailto:' . esc_attr( $support_email ) . '" style="display: inline-block; padding: 10px 20px; background: linear-gradient(to right, ' . esc_attr( $btn_start ) . ', ' . esc_attr( $btn_end ) . '); color: white; text-decoration: none; border-radius: ' . esc_attr( $border_radius ) . 'px; font-weight: 600; margin-right: 10px; transition: all 0.3s ease; box-shadow: 0 4px 8px rgba(0,0,0,0.1);" onmouseover="this.style.transform=\'translateY(-2px) scale(1.02)\';this.style.boxShadow=\'0 6px 12px rgba(0,0,0,0.15)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 4px 8px rgba(0,0,0,0.1)\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px;"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>' .
				__( 'Contact Support', 'certificate-generator' ) . '</a>';
			$output .= '<a href="' . esc_url( get_permalink() ) . '" style="display: inline-block; padding: 10px 20px; background: #f0f0f0; color: ' . esc_attr( $text_color ) . '; text-decoration: none; border-radius: ' . esc_attr( $border_radius ) . 'px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0,0,0,0.05);" onmouseover="this.style.background=\'#e0e0e0\';this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 4px 12px rgba(0,0,0,0.1)\'" onmouseout="this.style.background=\'#f0f0f0\';this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.05)\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px;"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>' .
				__( 'Back to Search', 'certificate-generator' ) . '</a>';
			$output .= '</div>'; // End contact/back-to-search section
			$output .= '</div>'; // End certificate-error-container
		}

			// Handle background processing for certificates that failed direct generation
			// Ensure $certificates_to_generate_bg is defined and not empty
		if ( ! empty( $certificates_to_generate_bg ) ) {
			foreach ( $certificates_to_generate_bg as $cert_job ) {
				// Ensure post_id and fields are set before scheduling
				if ( isset( $cert_job['post_id'], $cert_job['fields'] ) ) {
					if ( ! wp_next_scheduled( 'certificate_generator_process_single_certificate_hook_school', array( $cert_job['post_id'], $cert_job['fields'], 'schools' ) ) ) {
						wp_schedule_single_event( time() + 10, 'certificate_generator_process_single_certificate_hook_school', array( $cert_job['post_id'], $cert_job['fields'], 'schools' ) );
					}
				}
			}
			// Optionally, add a notice that some certificates are being generated in the background if some were also generated successfully.
			// Ensure $certificates_data and $error_count are defined
			if ( ! empty( $certificates_data ) && isset( $error_count ) && $error_count > 0 ) {
				$options       = get_option( 'certificate_generator_settings_email' ); // Re-fetch options for border-radius
				$border_radius = $options['border_radius'] ?? '12';
				// Ensure $output is initialized before appending
				if ( ! isset( $output ) ) {
					$output = '';
				}
				$output .= '<div style="margin-top: 20px; padding: 15px; background-color: #e3f2fd; border: 1px solid #bbdefb; color: #1e88e5; border-radius: ' . esc_attr( $border_radius ) . 'px; text-align: center;">' . __( 'Some certificates are being generated in the background. They will appear once processed.', 'certificate-generator' ) . '</div>';
			}
		}
		// IMPORTANT: The search form HTML should be outside the if (isset($_GET['school_name'])) block
		// So it's always displayed.
		// The 'else' block below is for the initial display of the search form.
	} else {
		// Get styling options with defaults
		$options       = get_option( 'certificate_generator_settings_email' );
		$title_color   = $options['title_color'] ?? '#2c3e50';
		$text_color    = $options['text_color'] ?? '#7f8c8d';
		$btn_start     = $options['btn_start'] ?? '#3498db';
		$btn_end       = $options['btn_end'] ?? '#2980b9';
		$border_radius = $options['border_radius'] ?? '12';
		$font_family   = $options['font_family'] ?? '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif';

		// Modern search form with consistent styling
		$output = '<div class="certificate-search-container" style="max-width: 600px; margin: 40px auto; font-family: ' . esc_attr( $font_family ) . ';">';

		// Form with enhanced styling
		$output .= '<form method="get" style="padding: 35px; background: #ffffff; border-radius: ' . esc_attr( $border_radius ) . 'px; ' .
		'box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); transition: all 0.3s ease;">';

		// Heading with icon
		$output .= '<div style="text-align: center; margin-bottom: 30px;">' .
		'<h2 style="color: ' . esc_attr( $title_color ) . '; margin: 0; font-size: 28px; font-weight: 700;">' .
		certificate_generator_get_shortcode_text( 'school_title', $atts['title'], __( 'School Certificate Lookup', 'certificate-generator' ) ) . '</h2>' .
		'<p style="color: ' . esc_attr( $text_color ) . '; margin-top: 10px; font-size: 16px;">' .
		certificate_generator_get_shortcode_text( 'school_subtitle', $atts['subtitle'], __( 'Enter school name and place to find certificates', 'certificate-generator' ) ) . '</p>' .
		'</div>';

		// Input field for School Name
		$output .= '<div style="position: relative; margin-bottom: 20px;">';
		$output .= '<label for="school_name" style="position: absolute; left: 16px; top: 18px; ' .
		'color: ' . esc_attr( $text_color ) . '; font-size: 16px; transition: all 0.2s ease; pointer-events: none;">' .
		__( 'School Name', 'certificate-generator' ) . '</label>';
		$output .= '<input type="text" id="school_name" name="school_name" required ' .
		'placeholder="" style="width: 100%; padding: 18px 16px; padding-top: 26px; padding-bottom: 10px; ' .
		'background: #f8f9fa; border: 2px solid #eaeaea; border-radius: ' . esc_attr( $border_radius ) . 'px; ' .
		'font-size: 16px; transition: all 0.3s ease; outline: none; box-sizing: border-box;" ' .
		'onfocus="this.style.borderColor=\'' . esc_js( $btn_start ) . '\'; ' .
		'this.previousElementSibling.style.top=\'8px\'; ' .
		'this.previousElementSibling.style.fontSize=\'12px\'; ' .
		'this.previousElementSibling.style.color=\'' . esc_js( $btn_start ) . '\'" ' .
		'onblur="if(this.value===\'\'){this.style.borderColor=\'#eaeaea\'; ' .
		'this.previousElementSibling.style.top=\'18px\'; ' .
		'this.previousElementSibling.style.fontSize=\'16px\'; ' .
		'this.previousElementSibling.style.color=\'' . esc_js( $text_color ) . '\'} ' .
		'else{this.style.borderColor=\'#eaeaea\'; ' .
		'this.previousElementSibling.style.color=\'' . esc_js( $text_color ) . '\';}">';
		$output .= '</div>';

		// Input field for Place
		$output .= '<div style="position: relative; margin-bottom: 30px;">';
		$output .= '<label for="place" style="position: absolute; left: 16px; top: 18px; ' .
		'color: ' . esc_attr( $text_color ) . '; font-size: 16px; transition: all 0.2s ease; pointer-events: none;">' .
		__( 'Place', 'certificate-generator' ) . '</label>';
		$output .= '<input type="text" id="place" name="place" required ' .
		'placeholder="" style="width: 100%; padding: 18px 16px; padding-top: 26px; padding-bottom: 10px; ' .
		'background: #f8f9fa; border: 2px solid #eaeaea; border-radius: ' . esc_attr( $border_radius ) . 'px; ' .
		'font-size: 16px; transition: all 0.3s ease; outline: none; box-sizing: border-box;" ' .
		'onfocus="this.style.borderColor=\'' . esc_js( $btn_start ) . '\'; ' .
		'this.previousElementSibling.style.top=\'8px\'; ' .
		'this.previousElementSibling.style.fontSize=\'12px\'; ' .
		'this.previousElementSibling.style.color=\'' . esc_js( $btn_start ) . '\'" ' .
		'onblur="if(this.value===\'\'){this.style.borderColor=\'#eaeaea\'; ' .
		'this.previousElementSibling.style.top=\'18px\'; ' .
		'this.previousElementSibling.style.fontSize=\'16px\'; ' .
		'this.previousElementSibling.style.color=\'' . esc_js( $text_color ) . '\'} ' .
		'else{this.style.borderColor=\'#eaeaea\'; ' .
		'this.previousElementSibling.style.color=\'' . esc_js( $text_color ) . '\';}">';
		$output .= '</div>';

		// Submit button with icon and hover effect
		$output .= '<button type="submit" style="display: flex; align-items: center; justify-content: center; ' .
		'width: 100%; padding: 16px; background: linear-gradient(135deg, ' . esc_attr( $btn_start ) . ', ' . esc_attr( $btn_end ) . '); ' .
		'color: #fff; border: none; border-radius: ' . esc_attr( $border_radius ) . 'px; font-size: 16px; ' .
		'font-weight: 600; cursor: pointer; text-align: center; transition: all 0.3s ease; ' .
		'box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); transform: translateY(0);" ' .
		'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
		'this.style.boxShadow=\'0 8px 20px rgba(0, 0, 0, 0.15)\'" ' .
		'onmouseout="this.style.transform=\'translateY(0)\'; ' .
		'this.style.boxShadow=\'0 4px 15px rgba(0, 0, 0, 0.1)\'">' .
		'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" ' .
		'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
		'stroke-linejoin="round" style="margin-right: 8px;">' .
		'<circle cx="11" cy="11" r="8"></circle>' .
		'<line x1="21" y1="21" x2="16.65" y2="16.65"></line>' .
		'</svg>' . certificate_generator_get_shortcode_text( 'school_button', $atts['button_text'], __( 'Search Certificates', 'certificate-generator' ) ) . '</button>';

		$output .= '</form>';

		// Add help text
		$output .= '<div style="text-align: center; margin-top: 20px; padding: 0 15px;">';
		$output .= '<p style="color: ' . esc_attr( $text_color ) . '; font-size: 14px;">' .
		certificate_generator_get_shortcode_text( 'school_help', $atts['help_text'], __( 'Enter the school name and place to locate the certificates.', 'certificate-generator' ) ) .
		'</p>';
		$output .= '</div>';

		$output .= '</div>'; // End container
	}

	// Trigger background processing for certificates
	if ( ! empty( $certificates_to_generate ) ) {
		wp_schedule_single_event( time(), 'certificate_generator_generate_certificates_background', array( $certificates_to_generate ) );
	}

	return $output;
}

/**
 * Certificates for every students-table row with this email. PDFs come from the cache when
 * unchanged. Shared by [certificate_generator_student_search] and its "Download All" ZIP endpoint.
 *
 * @return array{found: int, certificates: array[], no_template: array[]} found = matching rows,
 *         published template or not; no_template = rows still waiting for a published template.
 */
function certificate_generator_student_certificates_for_email( string $email ): array {
	global $wpdb;

	$rows = array();
	if ( '' !== $email && class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		$tables = \CertificateGenerator\Database\CustomTables::instance();
		if ( $tables->table_exists( 'students' ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM ' . $tables->get_table( 'students' ) . ' WHERE email = %s', $email ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				ARRAY_A
			);
		}
	}
	certificate_generator_debug_log( 'Student search: ' . count( $rows ) . ' row(s) for the searched email' );

	$certificates = array();
	$no_template  = array();
	foreach ( $rows as $student ) {
		$post_id          = (int) ( $student['wp_post_id'] ?? $student['id'] ?? 0 );
		$student_name     = $student['student_name'] ?? '';
		$school_name      = $student['school_name'] ?? '';
		$certificate_type = $student['certificate_type'] ?? '';
		$issue_date       = $student['issue_date'] ?? '';

		// Normalize issue_date to Y-m-d for template matching
		$issue_date_iso = '';
		if ( ! empty( $issue_date ) ) {
			$dt = DateTime::createFromFormat( 'Y-m-d', $issue_date )
				?: DateTime::createFromFormat( 'd-m-Y', $issue_date );
			if ( $dt ) {
				$issue_date_iso = $dt->format( 'Y-m-d' );
			}
		}

		// No published template for this type + event date (e.g. still scheduled) — skip;
		// the pending/scheduled screen explains it to the student.
		if ( ! certificate_generator_select_certificate_template( $certificate_type, $issue_date_iso, false ) ) {
			$no_template[] = array(
				'name'             => $student_name,
				'certificate_type' => $certificate_type,
				'issue_date'       => $issue_date,
			);
			certificate_generator_debug_log( 'Certificate Generator: No matching template for student row ' . (int) ( $student['id'] ?? 0 ) . ' (type="' . $certificate_type . '", issue_date="' . $issue_date . '")' );
			continue;
		}

		$fields = class_exists( 'CertificateGenerator_Field_Schema' )
			? CertificateGenerator_Field_Schema::get_all_renderable_fields( $certificate_type )
			: array( 'student_name', 'school_name', 'issue_date' );

		// Use certificate_generator_generate_certificate_pdf with student data from SQL table
		$file_url = certificate_generator_generate_certificate_pdf( $post_id, $fields, $student );

		if ( $file_url ) {
			$s_name  = sanitize_file_name( $student_name );
			$sc_name = sanitize_file_name( $school_name );
			// Stable per certificate, so an unchanged set of certificates reuses its ZIP.
			$unique_id  = $post_id . '-' . substr( md5( $s_name . $sc_name ), 0, 8 );
			$upload_dir = wp_upload_dir();

			$certificates[] = array(
				'url'              => $file_url,
				'path'             => str_replace( $upload_dir['baseurl'], $upload_dir['basedir'], $file_url ),
				'filename'         => certificate_generator_certificate_pdf_filename( $student_name, $certificate_type, $unique_id ),
				'post_id'          => $post_id,
				'student_name'     => $s_name,
				'school_name'      => $sc_name,
				'certificate_type' => $certificate_type,
				'issue_date'       => $issue_date,
			);
		}
	}

	return array(
		'found'        => count( $rows ),
		'certificates' => $certificates,
		'no_template'  => $no_template,
	);
}

/**
 * Build (or reuse) the "Download All" ZIP for an email. False when there is nothing to zip.
 */
function certificate_generator_student_zip_for_email( string $email ) {
	$certificates = certificate_generator_student_certificates_for_email( $email )['certificates'];
	if ( empty( $certificates ) || ! function_exists( 'certificate_generator_create_zip_for_email' ) ) {
		return false;
	}
	return certificate_generator_create_zip_for_email( $certificates, $email );
}

/**
 * "Download All Certificates (ZIP)" from [certificate_generator_student_search]. The ZIP is built when clicked,
 * not on every page view. Same trust model as the page: the email is the lookup key, and
 * everything is re-derived from it here — no path or file name comes from the request.
 * Served from admin-ajax.php: hide-login plugins 404 the rest of /wp-admin/ (admin-post.php
 * included) for visitors, but keep admin-ajax.php open.
 */
add_action( 'wp_ajax_certificate_generator_student_zip', 'certificate_generator_handle_student_zip_download' );
add_action( 'wp_ajax_nopriv_certificate_generator_student_zip', 'certificate_generator_handle_student_zip_download' );
function certificate_generator_handle_student_zip_download(): void {
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'certificate_generator_student_zip' ) ) {
		wp_die( esc_html__( 'This download link has expired. Please search for your certificates again.', 'certificate-generator' ), '', array( 'response' => 403 ) );
	}

	$limit = (int) apply_filters( 'certificate_generator_student_zip_rate_limit', 5 );
	if ( class_exists( 'CertificateGenerator_SecurityHelper' )
		&& ! CertificateGenerator_SecurityHelper::check_rate_limit( 'student_zip', $limit, MINUTE_IN_SECONDS )
	) {
		wp_die( esc_html__( 'Too many downloads. Please wait a minute and try again.', 'certificate-generator' ), '', array( 'response' => 429 ) );
	}

	$zip = certificate_generator_student_zip_for_email( sanitize_email( wp_unslash( $_GET['student_email'] ?? '' ) ) );
	if ( ! $zip || empty( $zip['zip_url'] ) ) {
		wp_die( esc_html__( 'No certificates were found for this email.', 'certificate-generator' ), '', array( 'response' => 404 ) );
	}

	wp_safe_redirect( $zip['zip_url'] );
	exit;
}

// Shortcode to search for student certificates
add_shortcode( 'certificate_generator_student_search', 'certificate_generator_student_search_shortcode' );
function certificate_generator_student_search_shortcode( $atts = array() ) {
	$atts = shortcode_atts(
		array(
			'title'       => '',
			'subtitle'    => '',
			'button_text' => '',
			'help_text'   => '',
		),
		$atts,
		'certificate_generator_student_search'
	);
	if ( isset( $_GET['student_email'] ) ) {
		$email  = sanitize_email( wp_unslash( $_GET['student_email'] ) );
		$result = certificate_generator_student_certificates_for_email( $email );

		// SQL is the primary source. CPT fallback removed.
		$total_certificates = $result['found'];

		if ( $total_certificates > 0 ) {
			$certificates         = $result['certificates'];
			$no_template_students = $result['no_template'];

			// If no PDFs generated but student exists, check for pending/scheduled template.
			if ( empty( $certificates ) ) {
				$pending = certificate_generator_get_pending_certificate_info( $email );
				if ( $pending ) {
					$output = certificate_generator_render_pending_certificate_screen( $pending );
					wp_reset_postdata();
					return $output;
				}
			}

			// "Download All" builds the ZIP when clicked (certificate_generator_handle_student_zip_download), not on every page view.
			$bulk_download_link = '';
			if ( count( $certificates ) > 3 ) {
				$zip_link           = wp_nonce_url(
					add_query_arg(
						array(
							'action'        => 'certificate_generator_student_zip',
							'student_email' => rawurlencode( $email ),
						),
						admin_url( 'admin-ajax.php' )
					),
					'certificate_generator_student_zip'
				);
				$bulk_download_link = '<a href="' . esc_url( $zip_link ) . '" class="bulk-download-button" style="display: inline-block; margin-top: 15px; padding: 10px 20px; background: linear-gradient(to right, #3498db, #2980b9); color: white; text-decoration: none; border-radius: 5px; font-weight: bold;">Download All Certificates (ZIP)</a>';
			}

			// Render certificates in a responsive grid layout with modern UI
			$options = get_option( 'certificate_generator_settings_email' );

			// Get styling options with defaults
			$card_bg       = $options['card_bg'] ?? '#f9f9f9';
			$title_color   = $options['title_color'] ?? '#2c3e50';
			$text_color    = $options['text_color'] ?? '#7f8c8d';
			$btn_start     = $options['btn_start'] ?? '#3498db';
			$btn_end       = $options['btn_end'] ?? '#2980b9';
			$hover_effect  = $options['hover_effect'] ?? '5';
			$border_radius = $options['border_radius'] ?? '12';

			// Container styles
			$output = '<div class="certificate-results-container" style="max-width: 1200px; margin: 20px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">';

			// Results header — use the actual number of generated certs, not the raw query count
			$generated_count = count( $certificates );
			$output         .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
			$output         .= '<h2 style="font-size: 24px; color: ' . $title_color . '; margin: 0; font-weight: 600;">' .
				sprintf(
					/* translators: %s: number of certificates */ _n( 'Found %s Certificate', 'Found %s Certificates', $generated_count, 'certificate-generator' ),
					'<span style="color: ' . $btn_start . ';">' . $generated_count . '</span>'
				) . '</h2>';

			// Add download all button if available
			if ( ! empty( $bulk_download_link ) ) {
				$bulk_download_link = str_replace(
					'class="bulk-download-button" style="..."',
					'class="bulk-download-button" style="display: inline-block; padding: 12px 24px; background: linear-gradient(to right, ' . $btn_start . ', ' . $btn_end . '); ' .
					'color: white; text-decoration: none; border-radius: ' . $border_radius . 'px; font-weight: 500; ' .
					'transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); text-align: center;"',
					$bulk_download_link
				);
				$output            .= '<div>' . $bulk_download_link . '</div>';
			}
			$output .= '</div>'; // End of header

			// Determine layout based on number of certificates
			$cert_count   = count( $certificates );
			$grid_columns = $cert_count === 1 ? 'minmax(300px, 600px)' : 'repeat(auto-fit, minmax(300px, 1fr))';
			$grid_justify = $cert_count === 1 ? 'center' : 'stretch';

			// Grid container with adaptive layout
			$output .= '<div class="certificate-grid" style="display: grid; grid-template-columns: ' . $grid_columns . '; ' .
				'gap: 25px; padding: 25px; background: ' . $card_bg . '; border-radius: ' . $border_radius . 'px; ' .
				' justify-content: ' . $grid_justify . ';">';

			// Certificate cards
			foreach ( $certificates as $certificate ) {
				$student_name = $certificate['student_name'] ?? '';
				$school_name  = $certificate['school_name'] ?? '';
				$cert_type    = $certificate['certificate_type'] ?? '';
				$issue_date   = $certificate['issue_date'] ?? '';

				// Card with hover effect - enhanced for single/multiple certificate layouts
				$card_width   = $cert_count === 1 ? '90%' : 'auto';
				$card_padding = $cert_count === 1 ? '20px' : '30px';
				$output      .= '<div class="certificate-card" style="padding: ' . $card_padding . '; background: #ffffff; ' .
					'border-radius: ' . $border_radius . 'px; box-shadow: 0 8px 25px rgba(0, 0, 0, 0.06); ' .
					'transition: all 0.3s cubic-bezier(0.165, 0.84, 0.44, 1); transform: translateY(0); ' .
					'border-top: 4px solid ' . $btn_start . '; width: ' . $card_width . ';" ' .
					'onmouseover="this.style.transform=\'translateY(-' . $hover_effect . 'px)\'; ' .
					'this.style.boxShadow=\'0 15px 35px rgba(0, 0, 0, 0.1)\';" ' .
					'onmouseout="this.style.transform=\'translateY(0)\'; ' .
					'this.style.boxShadow=\'0 8px 25px rgba(0, 0, 0, 0.06)\';">'

					// Certificate content - enhanced for single/multiple layouts
					. '<h3 style="font-size: ' . ( $cert_count === 1 ? '26px' : '22px' ) . '; color: ' . $title_color . '; margin-top: 0; margin-bottom: 20px; ' .
					'font-weight: 600; border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; ' . ( $cert_count === 1 ? 'text-align: center;' : '' ) . '">' .
					esc_html( $student_name ) . '</h3>'

					// Certificate details with icons - enhanced for single/multiple layouts
					. '<div style="margin-bottom: ' . ( $cert_count === 1 ? '35px' : '25px' ) . '; ' . ( $cert_count === 1 ? 'max-width: 80%; margin-left: auto; margin-right: auto;' : '' ) . '">';

				// School name with icon - enhanced for single/multiple layouts
				$output .= '<div style="display: flex; align-items: flex-start; margin-bottom: ' . ( $cert_count === 1 ? '16px' : '12px' ) . ';">' .
					'<div style="min-width: 24px; color: ' . $btn_start . '; margin-right: ' . ( $cert_count === 1 ? '15px' : '10px' ) . ';">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' .
					'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<path d="M2 22v-4h4"></path><path d="M3.5 17.5L7 14"></path>' .
					'<path d="M22 2v4h-4"></path><path d="M20.5 6.5L17 10"></path>' .
					'<path d="M22 22v-4h-4"></path><path d="M20.5 17.5L17 14"></path>' .
					'<path d="M2 2v4h4"></path><path d="M3.5 6.5L7 10"></path>' .
					'</svg></div>' .
					'<div><span style="font-weight: ' . ( $cert_count === 1 ? '600' : '500' ) . '; color: ' . $title_color . ';">School:</span> ' .
					'<span style="color: ' . $text_color . '; font-size: ' . ( $cert_count === 1 ? '15px' : '14px' ) . ';">' . esc_html( $school_name ) . '</span></div>' .
					'</div>';

				// Certificate type with icon - enhanced for single/multiple layouts
				$output .= '<div style="display: flex; align-items: flex-start; margin-bottom: ' . ( $cert_count === 1 ? '16px' : '12px' ) . ';">' .
					'<div style="min-width: 24px; color: ' . $btn_start . '; margin-right: ' . ( $cert_count === 1 ? '15px' : '10px' ) . ';">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' .
					'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<path d="M22 12h-4l-3 9L9 3l-3 9H2"></path>' .
					'</svg></div>' .
					'<div><span style="font-weight: ' . ( $cert_count === 1 ? '600' : '500' ) . '; color: ' . $title_color . ';">Certificate Type:</span> ' .
					'<span style="color: ' . $text_color . '; font-size: ' . ( $cert_count === 1 ? '15px' : '14px' ) . ';">' . esc_html( $cert_type ) . '</span></div>' .
					'</div>';

				// Issue date with icon - enhanced for single/multiple layouts
				$output .= '<div style="display: flex; align-items: flex-start;">' .
					'<div style="min-width: 24px; color: ' . $btn_start . '; margin-right: ' . ( $cert_count === 1 ? '15px' : '10px' ) . ';">' .
					'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" ' .
					'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
					'<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>' .
					'<line x1="16" y1="2" x2="16" y2="6"></line>' .
					'<line x1="8" y1="2" x2="8" y2="6"></line>' .
					'<line x1="3" y1="10" x2="21" y2="10"></line>' .
					'</svg></div>' .
					'<div><span style="font-weight: ' . ( $cert_count === 1 ? '600' : '500' ) . '; color: ' . $title_color . ';">Issued On:</span> ' .
					'<span style="color: ' . $text_color . '; font-size: ' . ( $cert_count === 1 ? '15px' : '14px' ) . ';">' . esc_html( $issue_date ) . '</span></div>' .
					'</div>';

				$output .= '</div>'; // End of certificate details

				// Download button with hover effect - enhanced for single/multiple layouts
				$btn_width     = $cert_count === 1 ? '60%' : '80%';
				$btn_padding   = $cert_count === 1 ? '16px 24px' : '14px 20px';
				$btn_font_size = $cert_count === 1 ? '16px' : '14px';
				$btn_container = $cert_count === 1 ? 'text-align: center; margin-top: 30px;' : '';

				$output .= '<div style="' . $btn_container . '">';
				$output .= '<a href="' . esc_url( $certificate['url'] ) . '" target="_blank" ' .
					'style="display: inline-block; width: ' . $btn_width . '; padding: ' . $btn_padding . '; ' .
					'background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); ' .
					'color: white; text-decoration: none; border-radius: ' . $border_radius . 'px; ' .
					'font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1); ' .
					'text-align: center; text-transform: uppercase; letter-spacing: 0.5px; font-size: ' . $btn_font_size . ';" ' .
					'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
					'this.style.boxShadow=\'0 6px 15px rgba(0, 0, 0, 0.15)\';" ' .
					'onmouseout="this.style.transform=\'translateY(0)\'; ' .
					'this.style.boxShadow=\'0 4px 10px rgba(0, 0, 0, 0.1)\';">'
					. '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' .
					'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
					'stroke-linejoin="round" style="vertical-align: -3px; margin-right: 8px;">' .
					'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>' .
					'<polyline points="7 10 12 15 17 10"></polyline>' .
					'<line x1="12" y1="15" x2="12" y2="3"></line>' .
					'</svg>Download Certificate</a>';
				$output .= '</div>';

				$output .= '</div>'; // End of card
			}

			$output .= '</div>'; // End of grid layout

			// Some of this student's certificates have no published template yet (e.g. next event still scheduled).
			if ( ! empty( $no_template_students ) ) {
				$pending_items = array();
				foreach ( $no_template_students as $nt ) {
					$pending_items[] = esc_html( $nt['certificate_type'] . ( $nt['issue_date'] ? ' (' . $nt['issue_date'] . ')' : '' ) );
				}
				$output .= '<div style="margin-top: 20px; padding: 15px 20px; background: #fff8e5; border-left: 4px solid #f59e0b; border-radius: ' . $border_radius . 'px; color: ' . $text_color . ';">'
					. esc_html__( 'Not published yet — please check back later:', 'certificate-generator' ) . ' '
					. implode( ', ', array_unique( $pending_items ) ) . '</div>';
			}

			// Add download all button at the bottom if available
			if ( ! empty( $bulk_download_link ) ) {
				$output .= '<div style="margin-top: 25px; text-align: center;">' . $bulk_download_link . '</div>';
			}

			$output .= '</div>'; // End of container
		} else {
			// Get styling options with defaults
			$options       = get_option( 'certificate_generator_settings_email' );
			$title_color   = $options['title_color'] ?? '#2c3e50';
			$text_color    = $options['text_color'] ?? '#7f8c8d';
			$btn_start     = $options['btn_start'] ?? '#3498db';
			$btn_end       = $options['btn_end'] ?? '#2980b9';
			$border_radius = $options['border_radius'] ?? '12';

			// Modern error message with consistent styling
			$output = '<div class="certificate-not-found" style="max-width: 600px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">';

			// Error card with enhanced styling
			$output .= '<div style="padding: 40px; background: #ffffff; border-radius: ' . $border_radius . 'px; ' .
				'box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); text-align: center;">';

			// Error icon with animation
			$output .= '<div style="margin-bottom: 25px; animation: pulse 2s infinite;">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 24 24" fill="none" ' .
				'stroke="' . $btn_start . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' .
				'<circle cx="12" cy="12" r="10"></circle>' .
				'<line x1="12" y1="8" x2="12" y2="12"></line>' .
				'<line x1="12" y1="16" x2="12.01" y2="16"></line>' .
				'</svg></div>';

			// Error message
			$output .= '<h2 style="color: ' . $title_color . '; font-size: 28px; margin: 0 0 15px; font-weight: 700;">' .
				__( 'No Certificate Found', 'certificate-generator' ) . '</h2>';
			$output .= '<p style="color: ' . $text_color . '; margin-bottom: 30px; line-height: 1.6; font-size: 16px;">' .
				__( 'We couldn\'t find any certificates matching the email address you provided.', 'certificate-generator' ) . '</p>
            <p style="color: ' . $text_color . '; margin-bottom: 20px; line-height: 1.6;">' .
				__( 'If you are unable to find your certificate here, please drop a request email to us on:', 'certificate-generator' ) . ' ' .
				certificate_generator_get_contact_email() . ' ' .
				__( 'to resend it over your email.', 'certificate-generator' ) . '</p>
            <div style="margin-bottom: 20px;">
              <p style="font-weight: bold; margin: 0 0 8px; color: ' . $title_color . '; font-size: 16px;">' .
				__( 'Please include following details in your email:', 'certificate-generator' ) . '</p>
              <ul style="margin: 0; padding-left: 20px; list-style-type: disc; color: ' . $text_color . ';">
                <li style="margin-bottom: 8px;">' . __( 'Registered Email Id', 'certificate-generator' ) . '</li>
                <li style="margin-bottom: 8px;">' . __( 'Name', 'certificate-generator' ) . '</li>
                <li style="margin-bottom: 8px;">' . __( 'Parent Name', 'certificate-generator' ) . '</li>
                <li style="margin-bottom: 8px;">' . __( 'School', 'certificate-generator' ) . '</li>
                <li style="margin-bottom: 0;">' . __( 'Grade', 'certificate-generator' ) . '</li>
              </ul>
            </div>';

			// Support section
			$output .= '<div style="background: linear-gradient(to right, rgba(' . certificate_generator_hex2rgb_str( $btn_start ) . ', 0.05), ' .
				'rgba(' . certificate_generator_hex2rgb_str( $btn_end ) . ', 0.05)); border-radius: ' . $border_radius . 'px; ' .
				'padding: 25px; margin: 25px 0; border-left: 4px solid ' . $btn_start . ';">';
			$output .= '<div style="display: flex; align-items: center; justify-content: center; margin-bottom: 15px;">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" ' .
				'stroke="' . $btn_start . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' .
				'style="margin-right: 10px;">' .
				'<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"></path>' .
				'</svg>' .
				'<h3 style="color: ' . $title_color . '; margin: 0; font-size: 18px;">' .
				__( 'Need Help Finding Your Certificate?', 'certificate-generator' ) . '</h3></div>';
			$output .= '<p style="color: ' . $text_color . '; margin-bottom: 20px; line-height: 1.6;">' .
				__( 'If you believe this is an error or need assistance, please contact our support team.', 'certificate-generator' ) . '</p>';
			$output .= '<a href="mailto:' . certificate_generator_get_contact_email() . '" ' .
				'style="display: inline-flex; align-items: center; padding: 12px 24px; ' .
				'background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); ' .
				'color: white; text-decoration: none; border-radius: ' . $border_radius . 'px; ' .
				'font-weight: 600; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);" ' .
				'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
				'this.style.boxShadow=\'0 8px 20px rgba(0, 0, 0, 0.15)\'" ' .
				'onmouseout="this.style.transform=\'translateY(0)\'; ' .
				'this.style.boxShadow=\'0 4px 15px rgba(0, 0, 0, 0.1)\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" ' .
				'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
				'stroke-linejoin="round" style="margin-right: 8px;">' .
				'<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>' .
				'<polyline points="22,6 12,13 2,6"></polyline>' .
				'</svg>' . __( 'Contact Support', 'certificate-generator' ) . '</a>';
			$output .= '</div>';

			// Add back button
			$output .= '<a href="' . esc_url( remove_query_arg( 'student_email' ) ) . '" ' .
				'style="display: inline-flex; align-items: center; margin-top: 10px; ' .
				'color: ' . $text_color . '; text-decoration: none; font-weight: 500; transition: color 0.3s ease;" ' .
				'onmouseover="this.style.color=\'' . $btn_start . '\'" ' .
				'onmouseout="this.style.color=\'' . $text_color . '\'">' .
				'<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' .
				'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
				'stroke-linejoin="round" style="margin-right: 6px;">' .
				'<line x1="19" y1="12" x2="5" y2="12"></line>' .
				'<polyline points="12 19 5 12 12 5"></polyline>' .
				'</svg>' . __( 'Back to Search', 'certificate-generator' ) . '</a>';

			$output .= '</div>'; // End of error card

			// Add CSS animation
			$output .= '<style>
                @keyframes pulse {
                    0% { transform: scale(1); opacity: 1; }
                    50% { transform: scale(1.05); opacity: 0.8; }
                    100% { transform: scale(1); opacity: 1; }
                }
            </style>';

			$output .= '</div>'; // End container

			// Use the existing hex2rgb function with default color if not set
			$hex_color = isset( $hex_color ) ? $hex_color : '#000000';
			$rgb       = certificate_generator_hex2rgb_str( $hex_color );
		}

		wp_reset_postdata();

		return $output;
	} else {
		// Get styling options with defaults
		$options       = get_option( 'certificate_generator_settings_email' );
		$title_color   = $options['title_color'] ?? '#2c3e50';
		$text_color    = $options['text_color'] ?? '#7f8c8d';
		$btn_start     = $options['btn_start'] ?? '#3498db';
		$btn_end       = $options['btn_end'] ?? '#2980b9';
		$border_radius = $options['border_radius'] ?? '12';

		// Modern search form with consistent styling
		$output = '<div class="certificate-search-container" style="max-width: 600px; margin: 40px auto; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen-Sans, Ubuntu, Cantarell, \'Helvetica Neue\', sans-serif;">';

		// Form with enhanced styling
		$output .= '<form method="get" style="padding: 35px; background: #ffffff; border-radius: ' . $border_radius . 'px; ' .
			'box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08); transition: all 0.3s ease;">';

		// Heading with icon
		$output .= '<div style="text-align: center; margin-bottom: 30px;">' .
			'<h2 style="color: ' . $title_color . '; margin: 0; font-size: 28px; font-weight: 700;">' .
			certificate_generator_get_shortcode_text( 'student_title', $atts['title'], __( 'Certificate Lookup', 'certificate-generator' ) ) . '</h2>' .
			'<p style="color: ' . $text_color . '; margin-top: 10px; font-size: 16px;">' .
			certificate_generator_get_shortcode_text( 'student_subtitle', $atts['subtitle'], __( 'Enter your email to find your certificates', 'certificate-generator' ) ) . '</p>' .
			'</div>';

		// Input field with floating label effect
		$output .= '<div style="position: relative; margin-bottom: 30px;">';
		$output .= '<label for="student_email" style="position: absolute; left: 16px; top: 18px; ' .
			'color: ' . $text_color . '; font-size: 16px; transition: all 0.2s ease; pointer-events: none;">' .
			__( 'Your Email Address', 'certificate-generator' ) . '</label>';
		$output .= '<input type="email" id="student_email" name="student_email" required ' .
			'placeholder="" style="width: 100%; padding: 18px 16px; padding-top: 26px; padding-bottom: 10px; ' .
			'background: #f8f9fa; border: 2px solid #eaeaea; border-radius: ' . $border_radius . ' px; ' .
			'font-size: 16px; transition: all 0.3s ease; outline: none; box-sizing: border-box;" ' .
			'onfocus="this.style.borderColor=\'' . $btn_start . '\'; ' .
			'this.previousElementSibling.style.top=\'8px\'; ' .
			'this.previousElementSibling.style.fontSize=\'12px\'; ' .
			'this.previousElementSibling.style.color=\'' . $btn_start . '\'" ' .
			'onblur="if(this.value===\'\'){this.style.borderColor=\'#eaeaea\'; ' .
			'this.previousElementSibling.style.top=\'18px\'; ' .
			'this.previousElementSibling.style.fontSize=\'16px\'; ' .
			'this.previousElementSibling.style.color=\'' . $text_color . '\'} ' .
			'else{this.style.borderColor=\'#eaeaea\'; ' .
			'this.previousElementSibling.style.color=\'' . $text_color . '\';}">';
		$output .= '</div>';

		// Submit button with icon and hover effect
		$output .= '<button type="submit" style="display: flex; align-items: center; justify-content: center; ' .
			'width: 100%; padding: 16px; background: linear-gradient(135deg, ' . $btn_start . ', ' . $btn_end . '); ' .
			'color: #fff; border: none; border-radius: ' . $border_radius . 'px; font-size: 16px; ' .
			'font-weight: 600; cursor: pointer; text-align: center; transition: all 0.3s ease; ' .
			'box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); transform: translateY(0);" ' .
			'onmouseover="this.style.transform=\'translateY(-2px)\'; ' .
			'this.style.boxShadow=\'0 8px 20px rgba(0, 0, 0, 0.15)\'" ' .
			'onmouseout="this.style.transform=\'translateY(0)\'; ' .
			'this.style.boxShadow=\'0 4px 15px rgba(0, 0, 0, 0.1)\'">' .
			'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" ' .
			'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" ' .
			'stroke-linejoin="round" style="margin-right: 8px;">' .
			'<circle cx="11" cy="11" r="8"></circle>' .
			'<line x1="21" y1="21" x2="16.65" y2="16.65"></line>' .
			'</svg>' . certificate_generator_get_shortcode_text( 'student_button', $atts['button_text'], __( 'Search Certificates', 'certificate-generator' ) ) . '</button>';

		$output .= '</form>';

		// Add help text
		$output .= '<div style="text-align: center; margin-top: 20px; padding: 0 15px;">';
		$output .= '<p style="color: ' . $text_color . '; font-size: 14px;">' .
			certificate_generator_get_shortcode_text( 'student_help', $atts['help_text'], __( 'Enter the email address you used during registration to find your certificates.', 'certificate-generator' ) ) .
			'</p>';
		$output .= '</div>';

		$output .= '</div>'; // End container
	}


	return $output;
}
