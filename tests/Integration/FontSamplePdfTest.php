<?php
/**
 * Fonts page: built-in font list + "Download sample PDF" (FontsPage).
 */

use CertificateGenerator\Admin\Pages\FontsPage;

class FontSamplePdfTest extends WP_UnitTestCase {

	public function test_builtin_list_matches_template_dropdown(): void {
		$fonts   = FontsPage::builtin_fonts();
		$options = \CertificateGenerator_FontManager::getInstance()->get_font_options();

		$this->assertGreaterThan( 20, count( $fonts ) );
		$this->assertSame( array_keys( $options ), array_keys( $fonts ) );
		$this->assertFalse( $fonts['georgia']['fallback'], 'georgia now maps to Gelasio, an FPDF-format file' );
		$this->assertFalse( $fonts['helvetica']['fallback'] );
	}

	public function test_sample_pdf_is_a_pdf(): void {
		$this->assertStringStartsWith( '%PDF', FontsPage::build_sample_pdf() );
	}

	public function test_handler_rejects_users_without_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		FontsPage::stream_sample_pdf();
	}

	public function test_handler_rejects_bad_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'bad';
		$this->expectException( WPDieException::class );
		try {
			FontsPage::stream_sample_pdf();
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}
}
