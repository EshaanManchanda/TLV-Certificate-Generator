<?php
/**
 * Regression test for two font-visibility bugs in CertificateGenerator_FontManager:
 * (1) 'arial' resolved to lib/fpdf/font/arial.php, but the bundled file is
 *     Arial.php (capital A) — silently failed on case-sensitive filesystems.
 * (2) Several bundled fonts (Garamond, Times New Roman variants) were excluded
 *     from the free/pro essential list entirely.
 * See includes/Core/font-manager.php (resolve_font_filename()).
 */
class FontManagerTest extends WP_UnitTestCase {

	public function test_arial_resolves_despite_case_mismatch_with_bundled_filename(): void {
		$fonts = \CertificateGenerator_FontManager::getInstance()->get_font_options( false );

		$this->assertArrayHasKey( 'arial', $fonts, 'Arial must be in the essential (free/pro) font list' );
	}

	public function test_previously_business_only_bundled_fonts_are_now_essential(): void {
		$fonts = \CertificateGenerator_FontManager::getInstance()->get_font_options( false );

		foreach ( array( 'garamond_regular', 'times_new_roman', 'times_new_roman_bold', 'times_new_roman_italic', 'times_new_roman_bold_italic' ) as $key ) {
			$this->assertArrayHasKey( $key, $fonts, "'$key' should now be visible to free/pro plans, not just Business" );
		}
	}

	public function test_essential_font_keys_are_sanitize_key_safe(): void {
		$fonts = \CertificateGenerator_FontManager::getInstance()->get_font_options( false );

		foreach ( array_keys( $fonts ) as $key ) {
			$this->assertSame( $key, sanitize_key( $key ), "Font key '$key' must survive sanitize_key() unchanged, since it's saved as a template's font_style value" );
		}
	}

	public function test_legacy_commercial_font_keys_render_with_open_replacements(): void {
		$fm = \CertificateGenerator_FontManager::getInstance();
		$expect = array( 'arial' => 'arimo', 'times_new_roman' => 'tinos', 'georgia' => 'gelasio', 'comicspans' => 'comicneue', 'garamond_regular' => 'ebgaramond', 'rumblebravescriptitalic' => 'greatvibes', 'verdanab' => 'notosansb' );
		foreach ( $expect as $key => $file ) {
			$this->assertStringEndsWith( "/$file.php", (string) $fm->get_font_file_path( $key ), "saved templates using '$key' must keep rendering" );
		}
		$pdf = new FPDF();
		$pdf->AddPage();
		$this->assertSame( 'Arimo (Arial-compatible)', $fm->add_font_to_pdf( $pdf, 'arial', '', 12 ) );
	}
}
