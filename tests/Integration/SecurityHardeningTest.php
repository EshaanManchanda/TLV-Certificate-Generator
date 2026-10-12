<?php
/**
 * Security audit 2026-10-10: rate-limit IP spoofing, CSV formula injection,
 * server-side fetches of template image URLs, and the font-metrics cache file.
 */
class SecurityHardeningTest extends WP_UnitTestCase {

	private array $server;

	public function set_up(): void {
		parent::set_up();
		$this->server = $_SERVER;
	}

	public function tear_down(): void {
		$_SERVER = $this->server;
		parent::tear_down();
	}

	public function test_client_written_forwarded_hop_does_not_reset_the_limit(): void {
		$action                 = 'cg_hardening_test_' . wp_rand();
		$_SERVER['REMOTE_ADDR'] = '10.0.0.5'; // our proxy
		foreach ( array( '198.51.100.1', '198.51.100.2' ) as $fake ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $fake . ', 203.0.113.20'; // client-forged first hop, real visitor last
			CertificateGenerator_SecurityHelper::check_rate_limit( $action, 2, 60 );
		}
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.3, 203.0.113.20';
		$this->assertFalse( CertificateGenerator_SecurityHelper::check_rate_limit( $action, 2, 60 ) );
	}

	public function test_csv_cells_that_look_like_formulas_are_neutralised(): void {
		$fh = fopen( 'php://temp', 'w+' );
		certificate_generator_fputcsv( $fh, array( '=HYPERLINK("http://x")', '+1', '-2', '@SUM(A1)', 'Ann', 7 ) );
		rewind( $fh );
		$line = stream_get_contents( $fh );
		$this->assertSame( "\"'=HYPERLINK(\"\"http://x\"\")\",'+1,'-2,'@SUM(A1),Ann,7\n", $line );
	}

	public function test_template_image_url_cannot_reach_private_hosts_or_escape_wordpress(): void {
		$this->assertSame( '', certificate_generator_template_url_to_path( 'http://169.254.169.254/latest/meta-data/x.png' ) );
		$this->assertSame( '', certificate_generator_template_url_to_path( 'http://127.0.0.1/x.png' ) );

		// "../" past ABSPATH must not resolve to a local file (it falls back to the URL, which FPDF can't read as a file).
		$escape = certificate_generator_template_url_to_path( site_url( '/wp-content/uploads/../../../../../../../../windows/win.ini' ) );
		$this->assertFalse( is_file( $escape ) );
	}

	public function test_font_metrics_cache_quotes_values_from_the_font_file(): void {
		$src = (string) file_get_contents( CERTIFICATE_GENERATOR_PATH . 'lib/tfpdf/tfpdf.php' );
		$this->assertStringContainsString( "'\$name='.var_export(\$name,true)", $src );
		$this->assertStringNotContainsString( "'\$name=\\''.\$name", $src );
	}
}
