<?php
declare(strict_types=1);

namespace CertificateGenerator\Services;

/**
 * mPDF behind the slice of FPDF's API the certificate renderers call, for certificates with text
 * FPDF can't draw: FPDF/tFPDF print characters outside Latin-1 as "?" and draw Indic and Arabic
 * letter by letter (no conjuncts, matras in the wrong place, no joining). mPDF shapes them.
 *
 * Coordinates, Text() baselines and GetStringWidth() keep FPDF's meaning (mm, baseline at y), so
 * field positions, fit-to-width and wrapping in certificate-search.php work unchanged. Each Text()
 * is split into script runs: Latin runs use the field's own font (its TTF from
 * FontManager::get_ttf_path_for_gd()), every other script a bundled font in assets/fonts/unicode.
 * Other methods (Rect, SetTextColor, SetXY, Cell, …) have the same signature in mPDF and pass through.
 */
class MpdfCanvas {

	/**
	 * Font per script run. Checked with mPDF 8.3: today's Noto builds use OpenType lookups it can't
	 * read (GPOS type 5 format 3, MarkGlyphSets), so these are the ones that load and shape correctly.
	 */
	private const SCRIPT_FONTS = array(
		'deva' => 'FreeSerif.ttf',
		'beng' => 'FreeSerif.ttf',
		'guru' => 'FreeSerif.ttf',
		'gujr' => 'FreeSerif.ttf',
		'taml' => 'FreeSerif.ttf',
		'mlym' => 'FreeSerif.ttf',
		'arab' => 'FreeSerif.ttf',
		'uni'  => 'FreeSerif.ttf', // any other non-Latin-1 letter: Latin Extended, Greek, Cyrillic, ’ “ ” …
		'orya' => 'NotoSansOriya-Regular.ttf',
		'telu' => 'Lohit-Telugu.ttf',
		'knda' => 'Lohit-Kannada.ttf',
	);

	/** Unicode block start => script, for U+0900–U+0D7F (128 code points each). */
	private const INDIC_BLOCKS = array(
		0x0900 => 'deva',
		0x0980 => 'beng',
		0x0A00 => 'guru',
		0x0A80 => 'gujr',
		0x0B00 => 'orya',
		0x0B80 => 'taml',
		0x0C00 => 'telu',
		0x0C80 => 'knda',
		0x0D00 => 'mlym',
	);

	private \Mpdf\Mpdf $mpdf;
	private string $font = '';   // mPDF key of the field font
	private float $size  = 12.0;

	public function __construct( $orientation = 'P', $unit = 'mm', $size = 'A4' ) {
		if ( 'mm' !== $unit ) {
			throw new \InvalidArgumentException( 'MpdfCanvas works in mm, as the certificate renderers do.' );
		}
		$this->mpdf = new \Mpdf\Mpdf(
			array(
				'format'        => is_array( $size ) ? $size : ( (string) $size ?: 'A4' ),
				'orientation'   => 'L' === strtoupper( substr( (string) $orientation, 0, 1 ) ) ? 'L' : 'P',
				'margin_left'   => 0,
				'margin_right'  => 0,
				'margin_top'    => 0,
				'margin_bottom' => 0,
				'margin_header' => 0,
				'margin_footer' => 0,
				'autoPageBreak' => false,
			) + self::config()
		);
		$this->font = self::key( 'FreeSerif.ttf' );
	}

	/** mPDF settings for the bundled Unicode fonts, shared with other PDFs (e.g. the certificate register). */
	public static function config(): array {
		$fontdata = array();
		foreach ( array_unique( self::SCRIPT_FONTS ) as $file ) {
			$fontdata[ self::key( $file ) ] = array(
				'R'          => $file,
				'useOTL'     => 0xFF,
				'useKashida' => 75,
			);
		}
		return array(
			'mode'         => 'utf-8',
			'tempDir'      => self::temp_dir(),
			'fontDir'      => array( CERTIFICATE_GENERATOR_PATH . 'assets/fonts/unicode' ),
			'fontdata'     => $fontdata,
			'default_font' => self::key( 'FreeSerif.ttf' ),
		);
	}

	/** Escaped HTML for mPDF's WriteHTML(): each non-Latin run in a span with its script's font. */
	public static function html( string $text ): string {
		$default = self::key( 'FreeSerif.ttf' );
		$html    = '';
		foreach ( self::split_runs( $text, $default ) as list( $font, $run ) ) {
			$html .= $font === $default ? esc_html( $run ) : '<span style="font-family:' . esc_attr( $font ) . '">' . esc_html( $run ) . '</span>';
		}
		return $html;
	}

	/** True when any of these strings has a character FPDF's Latin-1 fonts can't draw. */
	public static function needed_for( array $texts ): bool {
		foreach ( $texts as $text ) {
			if ( is_string( $text ) && preg_match( '/[^\x{0000}-\x{00FF}]/u', $text ) ) {
				return true;
			}
		}
		return false;
	}

	/** True when any of these strings is in a script that must be shaped: Indic, Arabic, Hebrew. */
	public static function needs_shaping( array $texts ): bool {
		foreach ( $texts as $text ) {
			if ( is_string( $text ) && preg_match( '/[\x{0590}-\x{08FF}\x{0900}-\x{0D7F}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text ) ) {
				return true;
			}
		}
		return false;
	}

	/** Field font: the bundled or uploaded font's TTF, registered with mPDF on first use. Returns the name used. */
	public function use_font( string $name, float $size ): string {
		$fm  = class_exists( '\CertificateGenerator_FontManager' ) ? \CertificateGenerator_FontManager::getInstance() : null;
		$ttf = $fm ? ( $fm->get_ttf_path_for_gd( $name ) ?: $fm->get_ttf_path_for_gd( 'helvetica' ) ) : null;
		if ( $ttf && is_readable( $ttf ) ) {
			$key = 'cg' . substr( md5( $ttf ), 0, 12 );
			if ( ! isset( $this->mpdf->fontdata[ $key ] ) ) {
				$this->mpdf->AddFontDirectory( dirname( $ttf ) );
				$this->mpdf->fontdata[ $key ]   = array( 'R' => basename( $ttf ) );
				$this->mpdf->available_unifonts[] = $key; // mPDF lists fonts once, at construction; SetFont() falls back to the default without this
			}
			$this->font = $key;
		} else {
			$name       = 'FreeSerif';
			$this->font = self::key( 'FreeSerif.ttf' );
		}
		$this->size = $size;
		$this->mpdf->SetFont( $this->font, '', $size );
		return $name;
	}

	// ── The FPDF methods the renderers use ────────────────────────────────────

	public function AddPage(): void {
		$this->mpdf->AddPage();
	}

	public function SetFont( $family, $style = '', $size = 0 ): void {
		$this->use_font( strtolower( (string) $family ), (float) ( $size ?: $this->size ) );
	}

	public function SetFontSize( $size ): void {
		$this->size = (float) $size;
		$this->mpdf->SetFontSize( $this->size );
	}

	public function Image( $file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '' ) {
		return $this->mpdf->Image( $file, $x, $y, $w, $h, $type, $link, true, false ); // false: not shrunk into the page margins
	}

	public function GetStringWidth( $text ): float {
		$width = 0.0;
		foreach ( $this->runs( (string) $text ) as list( $font, $run ) ) {
			$this->mpdf->SetFont( $font, '', $this->size );
			$width += $this->shaped_width( $run );
		}
		$this->mpdf->SetFont( $this->font, '', $this->size );
		return $width;
	}

	/** Text with its baseline at $y, as FPDF's Text(). */
	public function Text( $x, $y, $text ): void {
		$x = (float) $x;
		foreach ( $this->runs( (string) $text ) as list( $font, $run ) ) {
			$this->mpdf->SetFont( $font, '', $this->size );
			$width = $this->shaped_width( $run );
			$this->mpdf->WriteText( $x, (float) $y, $run );
			$x += $width;
		}
		$this->mpdf->SetFont( $this->font, '', $this->size );
	}

	/** FPDF's Output( $dest, $name ) (either order, as FPDF allows) → mPDF's Output( $name, $dest ). */
	public function Output( $dest = '', $name = '' ) {
		if ( strlen( (string) $dest ) > 1 ) {
			list( $dest, $name ) = array( $name, $dest );
		}
		return $this->mpdf->Output( (string) $name, strtoupper( (string) $dest ) ?: 'I' );
	}

	public function __call( string $method, array $args ) {
		return $this->mpdf->$method( ...$args );
	}

	// ── Internals ─────────────────────────────────────────────────────────────

	/**
	 * [font key, text] runs. Spaces, digits and punctuation join their script when they sit
	 * between two characters of it (so an Urdu name stays one right-to-left run) and use the
	 * field font otherwise.
	 * ponytail: runs are laid out left to right; a mostly-Arabic line with Latin words inside would need full bidi reordering.
	 */
	private function runs( string $text ): array {
		return self::split_runs( $text, $this->font );
	}

	/** runs() with $latin_font for Latin-1 letters. */
	private static function split_runs( string $text, string $latin_font ): array {
		$chars   = mb_str_split( $text );
		$scripts = array_map( array( self::class, 'script_of' ), $chars );
		$count   = count( $chars );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( '' !== $scripts[ $i ] ) {
				continue;
			}
			$j = $i;
			while ( $j < $count && '' === $scripts[ $j ] ) {
				++$j;
			}
			$before = $i > 0 ? $scripts[ $i - 1 ] : 'latn';
			$after  = $j < $count ? $scripts[ $j ] : 'latn';
			$fill   = $before === $after ? $before : 'latn';
			for ( $k = $i; $k < $j; $k++ ) {
				$scripts[ $k ] = $fill;
			}
			$i = $j - 1;
		}

		$runs = array();
		foreach ( $chars as $i => $char ) {
			$font = 'latn' === $scripts[ $i ] ? $latin_font : self::key( self::SCRIPT_FONTS[ $scripts[ $i ] ] );
			$last = count( $runs ) - 1;
			if ( $last >= 0 && $runs[ $last ][0] === $font ) {
				$runs[ $last ][1] .= $char;
			} else {
				$runs[] = array( $font, $char );
			}
		}
		return $runs;
	}

	/** 'latn' (Latin-1 letters), a SCRIPT_FONTS key, or '' for neutral characters. */
	private static function script_of( string $char ): string {
		$code = mb_ord( $char );
		if ( $code >= 0x0900 && $code < 0x0D80 ) {
			return self::INDIC_BLOCKS[ $code & ~0x7F ];
		}
		if ( ( $code >= 0x0600 && $code < 0x0780 ) || ( $code >= 0x08A0 && $code < 0x0900 ) || ( $code >= 0xFB50 && $code < 0xFE00 ) || ( $code >= 0xFE70 && $code < 0xFF00 ) ) {
			return 'arab';
		}
		if ( 0x200C === $code || 0x200D === $code || ! preg_match( '/[\p{L}\p{M}]/u', $char ) ) {
			return ''; // ZWNJ/ZWJ, spaces, digits, punctuation
		}
		return $code <= 0xFF ? 'latn' : 'uni';
	}

	/**
	 * Width of a run after shaping (conjuncts, ligatures), as WriteText() will draw it.
	 * ponytail: reads mPDF's private OTL engine through a bound closure; re-check on mPDF major upgrades.
	 */
	private function shaped_width( string $text ): float {
		$measure = \Closure::bind(
			function ( string $text ): float {
				$otl = false;
				if ( ! empty( $this->CurrentFont['useOTL'] ) ) {
					$text = $this->otl->applyOTL( $text, $this->CurrentFont['useOTL'] );
					$otl  = $this->otl->OTLdata;
				}
				return (float) $this->GetStringWidth( $text, false, $otl );
			},
			$this->mpdf,
			\Mpdf\Mpdf::class
		);
		return $measure( $text );
	}

	private static function key( string $file ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', pathinfo( $file, PATHINFO_FILENAME ) ) );
	}

	/** uploads/cg-mpdf-tmp: mPDF's font-metric cache and temp files. Not web-listable. */
	private static function temp_dir(): string {
		$dir = wp_upload_dir()['basedir'] . '/cg-mpdf-tmp';
		if ( ! file_exists( $dir . '/index.php' ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}
}
