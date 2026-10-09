<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CertificateGenerator_FontManager {


	private static $instance = null;
	private $font_path;
	private $available_fonts        = array();
	private $essential_fonts_loaded = false;
	private $all_fonts_loaded       = false;
	private $font_cache             = array();
	private $memory_usage_threshold = 32; // MB
	private $custom_fonts_cache     = null; // null = not yet loaded from DB; array once loaded

	/**
	 * Saved templates keep their old font keys; the commercial fonts behind them
	 * were replaced by open-licensed equivalents (OFL / Apache 2.0, see
	 * lib/fpdf/font/FONTS-LICENSE.txt). Arimo and Tinos match Arial and Times
	 * New Roman metrics, and Gelasio matches Georgia, so layouts don't shift.
	 */
	const FONT_FILE_ALIASES = array(
		'arial'                       => 'arimo',
		'times_new_roman'             => 'tinos',
		'times_new_roman_bold'        => 'tinosb',
		'times_new_roman_italic'      => 'tinosi',
		'times_new_roman_bold_italic' => 'tinosbi',
		'georgia'                     => 'gelasio',
		'verdanab'                    => 'notosansb',
		'comicspans'                  => 'comicneue',
		'garamond_regular'            => 'ebgaramond',
		'rumblebravescriptitalic'     => 'greatvibes',
	);

	public static function getInstance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->font_path = CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/font/';

		// Only load essential fonts during construction to avoid memory issues
		$this->load_essential_fonts();
	}

	/**
	 * Load only essential fonts to reduce memory footprint during activation
	 */
	private function load_essential_fonts() {
		// Define essential fonts that should always be available
		// Updated for optimized 200-font collection
		$essential_fonts = array(
			// User-requested fonts (top priority)
			'arial'                   => 'Arimo (Arial-compatible)',
			'times'                   => 'Times',
			'timesb'                  => 'Times Bold',
			'timesi'                  => 'Times Italic',
			'timesbi'                 => 'Times Bold Italic',
			'rumblebravescriptitalic' => 'Great Vibes (script)',

			// Core system fonts
			'helvetica'               => 'Helvetica',
			'helveticab'              => 'Helvetica Bold',
			'helveticai'              => 'Helvetica Italic',
			'helveticabi'             => 'Helvetica Bold Italic',
			'courier'                 => 'Courier',
			'courierb'                => 'Courier Bold',
			'courieri'                => 'Courier Italic',
			'courierbi'               => 'Courier Bold Italic',
			'georgia'                 => 'Gelasio (Georgia-compatible)',
			'verdanab'                => 'Noto Sans Bold',

			// Popular certificate fonts
			'opensans'                => 'Open Sans',
			'lato'                    => 'Lato',
			'pacifico'                => 'Pacifico',
			'lobster'                 => 'Lobster',
			'poppins'                 => 'Poppins',
			'comicspans'              => 'Comic Neue',

			// Bundled but previously excluded from the free/pro list — these
			// files exist in lib/fpdf/font/ but only ever surfaced through the
			// Business-gated "show all" path.
			'garamond_regular'           => 'EB Garamond',
			'times_new_roman'            => 'Tinos (Times New Roman-compatible)',
			'times_new_roman_bold'       => 'Tinos Bold',
			'times_new_roman_italic'     => 'Tinos Italic',
			'times_new_roman_bold_italic' => 'Tinos Bold Italic',
		);

		foreach ( $essential_fonts as $font_key => $display_name ) {
			$resolved_file = $this->resolve_font_filename( $font_key );
			if ( $resolved_file === null ) {
				continue;
			}
			$this->available_fonts[ $font_key ] = array(
				'file'         => $resolved_file,
				'display_name' => $display_name,
				'path'         => $this->font_path . $resolved_file . '.php',
				'essential'    => true,
			);
		}

		$this->essential_fonts_loaded = true;
	}

	/**
	 * Resolve a font identifier to its actual on-disk filename (no extension).
	 *
	 * Font keys must stay sanitize_key()-safe (lowercase, digits, _/-) since
	 * they're saved as a template's font_style value, but some bundled files
	 * use mixed case and/or spaces (e.g. "Arial.php", "Times New Roman Bold.php").
	 * Tries an exact-case match first, then falls back to a case- and
	 * separator-insensitive scan so lookups work regardless of the host
	 * filesystem's case sensitivity.
	 *
	 * @return string|null Actual filename (no extension), or null if not found.
	 */
	private function resolve_font_filename( $font_key ) {
		$font_key   = self::FONT_FILE_ALIASES[ strtolower( $font_key ) ] ?? $font_key;
		$direct_php = $this->font_path . $font_key . '.php';
		if ( file_exists( $direct_php ) && file_exists( $this->font_path . $font_key . '.z' ) && filesize( $direct_php ) > 100 ) {
			return $font_key;
		}

		$normalized_key = strtolower( str_replace( array( '_', '-' ), ' ', $font_key ) );
		foreach ( glob( $this->font_path . '*.php' ) ?: array() as $candidate ) {
			$base = basename( $candidate, '.php' );
			if ( strtolower( $base ) === $normalized_key
				&& file_exists( $this->font_path . $base . '.z' )
				&& filesize( $candidate ) > 100
			) {
				return $base;
			}
		}
		return null;
	}

	/**
	 * Lazy load all available fonts (only when needed)
	 * Optimized for 200-font collection
	 */
	private function discover_all_fonts() {
		// Skip if we only want essential fonts or already loaded
		if ( ! is_dir( $this->font_path ) ) {
			return;
		}

		// Get font files - with optimized collection, we can load all safely
		$font_files = glob( $this->font_path . '*.php' );

		foreach ( $font_files as $font_file ) {
			$filename = basename( $font_file, '.php' );

			// Skip if already loaded or system files
			if (
				isset( $this->available_fonts[ $filename ] ) ||
				in_array( $filename, array( 'symbol', 'zapfdingbats' ) ) ||
				in_array( $filename, self::FONT_FILE_ALIASES, true ) // already listed under its legacy key
			) {
				continue;
			}

			// Validate font files
			$z_file   = $this->font_path . $filename . '.z';
			$php_size = filesize( $font_file );

			if ( ! file_exists( $z_file ) || $php_size < 100 ) {
				continue;
			}

			// Get human-readable font name
			$display_name = $this->get_font_display_name( $filename );

			$this->available_fonts[ $filename ] = array(
				'file'         => $filename,
				'display_name' => $display_name,
				'path'         => $font_file,
				'essential'    => false,
			);
		}

		// Sort by display name
		uasort(
			$this->available_fonts,
			function ( $a, $b ) {
				return strcmp( $a['display_name'], $b['display_name'] );
			}
		);
	}

	/**
	 * Convert font filename to human-readable display name
	 */
	private function get_font_display_name( $filename ) {
		// Handle common font name patterns
		$name_mappings = array(
			'helvetica'   => 'Helvetica',
			'helveticab'  => 'Helvetica Bold',
			'helveticabi' => 'Helvetica Bold Italic',
			'helveticai'  => 'Helvetica Italic',
			'times'       => 'Times',
			'timesb'      => 'Times Bold',
			'timesbi'     => 'Times Bold Italic',
			'timesi'      => 'Times Italic',
			'cour'        => 'Courier',
			'courierb'    => 'Courier Bold',
			'courierbi'   => 'Courier Bold Italic',
			'courieri'    => 'Courier Italic',
			'courier'     => 'Courier',
			'garmond'     => 'Garamond',
			'georgia'     => 'Georgia',
		);

		$filename_lower = strtolower( $filename );

		if ( isset( $name_mappings[ $filename_lower ] ) ) {
			return $name_mappings[ $filename_lower ];
		}

		// Clean up filename for display
		$display_name = str_replace( array( '-', '_' ), ' ', $filename );
		$display_name = ucwords( $display_name );

		return $display_name;
	}

	/**
	 * Get all available fonts (with lazy loading option)
	 * Optimized for 200-font collection
	 */
	public function get_available_fonts( $load_all = false ) {
		if ( $load_all && ! $this->all_fonts_loaded ) {
			// Load all fonts since we now have only 200 optimized fonts
			$this->discover_all_fonts();
			$this->all_fonts_loaded = true;
		}
		return $this->available_fonts;
	}

	/**
	 * Get only essential fonts (fast, memory-efficient)
	 */
	public function get_essential_fonts() {
		$essential = array();
		foreach ( $this->available_fonts as $key => $font ) {
			if ( isset( $font['essential'] ) && $font['essential'] ) {
				$essential[ $key ] = $font;
			}
		}
		return $essential;
	}

	/**
	 * Get font names for dropdown/select options
	 * Optimized for 200-font collection
	 */
	public function get_font_options( $include_all = false ) {
		$fonts_to_use = $include_all ? $this->get_available_fonts( true ) : $this->get_essential_fonts();

		$options = array();
		foreach ( $fonts_to_use as $font_key => $font_data ) {
			$options[ $font_key ] = $font_data['display_name'];
		}

		if ( $include_all ) {
			foreach ( $this->get_custom_fonts() as $font_name => $file_path ) {
				if ( ! isset( $options[ $font_name ] ) ) {
					$options[ $font_name ] = $font_name . ' (custom)';
				}
			}
		}

		return $options;
	}

	/**
	 * Business-tier custom-uploaded TTF fonts (wp_custom_fonts, the same table
	 * FontsPage.php uploads into via CustomTables::get_table('custom_fonts')),
	 * keyed by font_name → absolute .ttf path. Loaded once per request.
	 */
	private function get_custom_fonts() {
		if ( $this->custom_fonts_cache === null ) {
			$this->custom_fonts_cache = array();
			global $wpdb;
			$table = class_exists( '\CertificateGenerator\Database\CustomTables' )
				? \CertificateGenerator\Database\CustomTables::instance()->get_table( 'custom_fonts' )
				: $wpdb->prefix . 'custom_fonts';
			if ( $table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$rows = $wpdb->get_results( "SELECT font_name, file_path FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $rows as $row ) {
					$this->custom_fonts_cache[ $row->font_name ] = $row->file_path;
				}
			}
		}
		return $this->custom_fonts_cache;
	}

	public function is_custom_font( $font_name ) {
		return isset( $this->get_custom_fonts()[ $font_name ] );
	}

	public function get_custom_font_path( $font_name ) {
		$fonts = $this->get_custom_fonts();
		return $fonts[ $font_name ] ?? null;
	}

	/**
	 * TTF files can carry an OS/2.fsType flag that forbids embedding in
	 * documents (common on "personal use" display fonts). tFPDF's own check
	 * for this calls die() directly — uncatchable, crashes the whole request —
	 * so this replicates the same check (same TTFontFile, same byte offsets)
	 * ahead of time so callers can reject/fall back gracefully instead.
	 */
	public static function is_font_embeddable( string $ttf_path ): bool {
		// Bulk jobs (ZIP export, background processor) call this once per
		// certificate but the same font's file never changes mid-request —
		// re-parsing the TTF for every cert is pure waste. Cache by path+mtime.
		static $cache = array();
		$cache_key = $ttf_path . '|' . ( @filemtime( $ttf_path ) ?: 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		if ( ! class_exists( 'TTFontFile' ) ) {
			require_once CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/font/unifont/ttfonts.php';
		}

		$fh = @fopen( $ttf_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- stream read; WP_Filesystem has no stream API
		if ( ! $fh ) {
			return $cache[ $cache_key ] = false;
		}

		try {
			$ttf       = new \TTFontFile();
			$ttf->fh   = $fh;
			$ttf->_pos = 0;

			$version = $ttf->read_ulong();
			if ( ! in_array( $version, array( 0x00010000, 0x74727565 ), true ) ) {
				return $cache[ $cache_key ] = false; // Not a TrueType font at all.
			}

			$ttf->readTableDirectory();
			if ( ! isset( $ttf->tables['OS/2'] ) ) {
				return $cache[ $cache_key ] = true; // No OS/2 table — nothing restricts embedding.
			}

			$ttf->seek_table( 'OS/2' );
			$ttf->read_ushort(); // version
			$ttf->skip( 2 );     // xAvgCharWidth
			$ttf->read_ushort(); // usWeightClass
			$ttf->skip( 2 );     // usWidthClass
			$fs_type = $ttf->read_ushort();

			return $cache[ $cache_key ] = ! ( $fs_type === 0x0002 || ( $fs_type & 0x0300 ) !== 0 );
		} catch ( \Throwable $e ) {
			return $cache[ $cache_key ] = false;
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes a php://output / php://temp stream
		}
	}

	/**
	 * Custom TTF fonts require tFPDF (Unicode-capable, embeds a raw .ttf directly) —
	 * the classic FPDF engine used for the 29 bundled fonts can't do this without a
	 * pre-converted .php/.z pair. Returns a tFPDF instance only when $font_name is a
	 * custom upload; every bundled/essential font keeps using plain FPDF, untouched.
	 */
	public static function create_pdf_instance( $orientation, $unit, $size, $font_name = '' ) {
		$instance = self::getInstance();
		if ( $font_name && $instance->is_custom_font( $font_name ) ) {
			$tfpdf_file = CERTIFICATE_GENERATOR_PATH . 'lib/tfpdf/tfpdf.php';
			if ( file_exists( $tfpdf_file ) ) {
				require_once $tfpdf_file;
				if ( class_exists( 'CertificateGenerator_TFPDF' ) ) {
					return new CertificateGenerator_TFPDF( $orientation, $unit, $size );
				}
				if ( class_exists( 'tFPDF' ) ) {
					return new tFPDF( $orientation, $unit, $size );
				}
			}
			certificate_generator_debug_log( "FontManager: custom font '$font_name' selected but tFPDF is unavailable — falling back to FPDF (custom font will not render)." );
		}
		// CertificateGenerator_FPDF / CertificateGenerator_TFPDF (certificate-search.php) reuse decoded template images across a bulk run.
		return class_exists( 'CertificateGenerator_FPDF' ) ? new CertificateGenerator_FPDF( $orientation, $unit, $size ) : new FPDF( $orientation, $unit, $size );
	}

	private function add_custom_font_to_pdf( $pdf, $font_name, $style, $size ) {
		$ttf_path = $this->get_custom_font_path( $font_name );
		if ( ! $ttf_path || ! file_exists( $ttf_path ) || ! ( $pdf instanceof tFPDF ) ) {
			certificate_generator_debug_log( "FontManager: custom font '$font_name' unavailable — falling back to Helvetica." );
			$pdf->SetFont( 'Helvetica', $style, $size );
			return 'Helvetica';
		}

		// tFPDF's own embedding-permission check calls die() directly, which
		// would crash the whole request — verify it ourselves first.
		if ( ! self::is_font_embeddable( $ttf_path ) ) {
			certificate_generator_debug_log( "FontManager: custom font '$font_name' cannot be embedded (font license restricts embedding) — falling back to Helvetica." );
			$pdf->SetFont( 'Helvetica', $style, $size );
			return 'Helvetica';
		}

		try {
			// This bundled tFPDF has its require_once() for ttfonts.php commented
			// out, so TTFontFile (needed for $uni=true) must be loaded explicitly.
			if ( ! class_exists( 'TTFontFile' ) ) {
				require_once CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/font/unifont/ttfonts.php';
			}

			// tFPDF's AddFont() always resolves a Unicode $file relative to
			// {fontpath}/unifont/ (or _SYSTEM_TTFONTS if set) — it does NOT accept
			// an arbitrary absolute path. Point _SYSTEM_TTFONTS at the uploaded
			// font's own directory (wp-uploads/.../) and pass just the filename.
			if ( ! defined( '_SYSTEM_TTFONTS' ) ) {
				define( '_SYSTEM_TTFONTS', trailingslashit( dirname( $ttf_path ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- constant name required by tFPDF
			}

			// A custom upload has exactly one weight/style available. tFPDF's
			// AddFont() caches parsed metrics on disk keyed only by filename (not
			// by style), so registering the same file a second time under a
			// different style reads back the FIRST style's cached fontkey and
			// silently registers under that instead — the second style's SetFont()
			// then fails with "Undefined font". Always use the single real style.
			$pdf->AddFont( $font_name, '', basename( $ttf_path ), true );
			$pdf->SetFont( $font_name, '', $size );
			return $font_name;
		} catch ( \Throwable $e ) {
			certificate_generator_debug_log( 'Custom font loading error: ' . $e->getMessage() );
			$pdf->SetFont( 'Helvetica', $style, $size );
			return 'Helvetica';
		}
	}

	/**
	 * Add font to PDF instance
	 */
	public function add_font_to_pdf( $pdf, $font_name, $style = '', $size = 12 ) {
		if ( $this->is_custom_font( $font_name ) ) {
			return $this->add_custom_font_to_pdf( $pdf, $font_name, $style, $size );
		}

		if ( ! isset( $this->available_fonts[ $font_name ] ) ) {
			// Fallback to default font
			$font_name = 'helvetica';
			if ( ! isset( $this->available_fonts[ $font_name ] ) ) {
				// Ultimate fallback — Helvetica is always built into FPDF
				$pdf->SetFont( 'Helvetica', $style, $size );
				return 'Helvetica';
			}
		}

		$font_data = $this->available_fonts[ $font_name ];

		// Double-check font files exist before using
		$php_file = $this->font_path . $font_data['file'] . '.php';
		$z_file   = $this->font_path . $font_data['file'] . '.z';

		if ( ! file_exists( $php_file ) || ! file_exists( $z_file ) ) {
			certificate_generator_debug_log( "FontManager: Font files missing for $font_name, falling back to Helvetica" );
			$pdf->SetFont( 'Helvetica', $style, $size );
			return 'Helvetica';
		}

		// Check for unsupported TrueTypeUnicode
		// This prevents "FPDF error: Unsupported font type: TrueTypeUnicode"
		$font_content = file_get_contents( $php_file, false, null, 0, 100 );
		if ( $font_content !== false && strpos( $font_content, "'TrueTypeUnicode'" ) !== false ) {
			certificate_generator_debug_log( "FontManager: Unsupported TrueTypeUnicode font detected ($font_name). Falling back to Helvetica to prevent crash." );
			$pdf->SetFont( 'Helvetica', $style, $size );
			return 'Helvetica';
		}

		try {
			// Add the font if not already added
			$pdf->AddFont( $font_data['display_name'], $style, $font_data['file'] . '.php' );
			$pdf->SetFont( $font_data['display_name'], $style, $size );
			return $font_data['display_name'];
		} catch ( \Throwable $e ) {
			unset( $this->available_fonts[ $font_name ] );  // don't retry this broken font
			certificate_generator_debug_log( 'Font loading error: ' . $e->getMessage() );
			$pdf->SetFont( 'Helvetica', $style, $size );
			return 'Helvetica';
		}
	}

	/**
	 * Get font file path for a given font name
	 */
	public function get_font_file_path( $font_name ) {
		if ( isset( $this->available_fonts[ $font_name ] ) ) {
			return $this->available_fonts[ $font_name ]['path'];
		}
		return null;
	}

	/**
	 * Resolve a font name to a raw .ttf file usable by GD's imagettftext()
	 * (for the PNG certificate renderer), giving it the same font as the PDF path.
	 *
	 * Custom (user-uploaded) fonts already store a raw .ttf — returned directly.
	 * Bundled fonts only ship as an FPDF font-definition .php + a gzip-compressed
	 * font program (.z, referenced by the .php's $file var) — there is no separate
	 * .ttf on disk. The .z is gzuncompress()-able back into the original TTF bytes
	 * (verified: FPDF's MakeFont just gzcompress()es the font file), so this
	 * decompresses once and caches the result in uploads for GD to read.
	 *
	 * @return string|null Absolute filesystem path to a .ttf file, or null if unavailable.
	 */
	public function get_ttf_path_for_gd( $font_name ) {
		if ( $this->is_custom_font( $font_name ) ) {
			return $this->get_custom_font_path( $font_name );
		}

		$php_path = $this->get_font_file_path( $font_name );
		if ( ! $php_path || ! file_exists( $php_path ) ) {
			return null;
		}

		$cache_dir  = trailingslashit( wp_upload_dir()['basedir'] ) . 'cg-ttf-cache';
		$cache_path = $cache_dir . '/' . sanitize_file_name( $font_name ) . '.ttf';
		if ( file_exists( $cache_path ) && filemtime( $cache_path ) >= filemtime( $php_path ) ) {
			return $cache_path;
		}

		$definition = file_get_contents( $php_path );
		if ( $definition === false || ! preg_match( "/\\\$file\\s*=\\s*'([^']+)';/", $definition, $m ) ) {
			return null;
		}

		$z_path = dirname( $php_path ) . '/' . $m[1];
		if ( ! file_exists( $z_path ) ) {
			return null;
		}

		$ttf_bytes = @gzuncompress( file_get_contents( $z_path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $ttf_bytes === false ) {
			return null;
		}

		wp_mkdir_p( $cache_dir );
		if ( @file_put_contents( $cache_path, $ttf_bytes ) === false ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return null;
		}

		return $cache_path;
	}

	/**
	 * Check if a font exists
	 */
	public function font_exists( $font_name ) {
		return isset( $this->available_fonts[ $font_name ] );
	}

	/**
	 * Get display name for a font
	 */
	public function get_font_display_name_by_key( $font_name ) {
		if ( isset( $this->available_fonts[ $font_name ] ) ) {
			return $this->available_fonts[ $font_name ]['display_name'];
		}
		return $font_name;
	}

	/**
	 * Refresh font list (useful after adding new fonts)
	 */
	public function refresh_fonts( $essential_only = true ) {
		$this->available_fonts = array();
		if ( $essential_only ) {
			$this->load_essential_fonts();
		} else {
			$this->load_essential_fonts();
			$this->discover_all_fonts();
		}
	}

	/**
	 * Check if we're in memory-safe mode
	 */
	public function is_memory_safe_mode() {
		$memory_limit       = ini_get( 'memory_limit' );
		$memory_limit_bytes = certificate_generator_convert_to_bytes( $memory_limit );

		// If memory limit is less than 128MB, use memory-safe mode
		return $memory_limit_bytes < ( 128 * 1024 * 1024 );
	}



	/**
	 * Get default font
	 */
	public function get_default_font() {
		if ( ! empty( $this->available_fonts ) ) {
			$first_font = reset( $this->available_fonts );
			return $first_font['file'];
		}
		return 'helvetica';
	}

	/**
	 * Monitor memory usage and clear cache if needed
	 */
	private function monitor_memory_usage() {
		if ( ! function_exists( 'memory_get_usage' ) ) {
			return;
		}

		$current_memory = memory_get_usage( true ) / 1024 / 1024; // Convert to MB

		if ( $current_memory > $this->memory_usage_threshold ) {
			$this->clear_font_cache();

			// Log memory optimization
			if ( function_exists( 'error_log' ) ) {
				certificate_generator_debug_log(
					sprintf(
						'Certificate Generator: Memory optimization triggered at %.2f MB, cache cleared',
						$current_memory
					)
				);
			}
		}
	}

	/**
	 * Clear font cache to free memory
	 */
	public function clear_font_cache() {
		$this->font_cache = array();

		// Keep only essential fonts in memory
		$essential_font_keys = array(
			'Arial',
			'helvetica',
			'times',
			'timesb',
			'courier',
			'opensans',
			'lato',
			'pacifico',
		);

		$filtered_fonts = array();
		foreach ( $this->available_fonts as $key => $font ) {
			if ( in_array( $key, $essential_font_keys ) ) {
				$filtered_fonts[ $key ] = $font;
			}
		}

		$this->available_fonts = $filtered_fonts;
	}

	/**
	 * Get memory usage statistics
	 */
	public function get_memory_stats() {
		$stats = array(
			'current_usage_mb' => 0,
			'cache_size'       => count( $this->font_cache ),
			'fonts_loaded'     => count( $this->available_fonts ),
			'memory_limit'     => ini_get( 'memory_limit' ),
		);

		if ( function_exists( 'memory_get_usage' ) ) {
			$stats['current_usage_mb'] = round( memory_get_usage( true ) / 1024 / 1024, 2 );
		}

		return $stats;
	}

	/**
	 * Optimize font loading for performance
	 */
	public function optimize_for_performance() {
		// Clear any unnecessary font data
		$this->clear_font_cache();

		// Load only the most commonly used fonts
		$priority_fonts = array( 'Arial', 'helvetica', 'times', 'opensans' );

		foreach ( $priority_fonts as $font ) {
			if ( ! isset( $this->available_fonts[ $font ] ) ) {
				$font_path = $this->font_path . $font . '.php';
				if ( file_exists( $font_path ) ) {
					$this->available_fonts[ $font ] = array(
						'file'      => $font,
						'name'      => $this->get_font_display_name( $font ),
						'loaded_at' => time(),
					);
				}
			}
		}

		return true;
	}
}
