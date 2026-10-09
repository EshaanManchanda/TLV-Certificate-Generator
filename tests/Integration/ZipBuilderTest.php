<?php
/**
 * certificate_generator_create_zip_impl(): stored (not re-deflated) PDFs, in-memory entries, path safety,
 * duplicate names, private admin ZIPs, and the legacy wrapper / ZipService pass-through.
 */
class ZipBuilderTest extends WP_UnitTestCase {

	private array $cleanup = array();

	public function tear_down(): void {
		foreach ( $this->cleanup as $f ) {
			@unlink( $f );
		}
		parent::tear_down();
	}

	private function pdf( string $name, string $body = '' ): string {
		$path = certificate_generator_certificates_dir() . '/' . $name;
		file_put_contents( $path, $body ?: str_repeat( '%PDF-1.3 ' . $name, 200 ) );
		$this->cleanup[] = $path;
		return $path;
	}

	private function zip( array $data, string $label, array $args = array() ): array {
		$res = certificate_generator_create_zip_impl( $data, $label, $args );
		$this->assertIsArray( $res );
		$this->cleanup[] = $res['zip_path'];
		return $res;
	}

	private function entries( string $zip_path ): array {
		$zip = new ZipArchive();
		$zip->open( $zip_path );
		$out = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$s                  = $zip->statIndex( $i );
			$out[ $s['name'] ] = array(
				'method'  => $s['comp_method'],
				'content' => $zip->getFromIndex( $i ),
			);
		}
		$zip->close();
		return $out;
	}

	public function test_single_and_multiple_entries(): void {
		$one = $this->zip( array( array( 'path' => $this->pdf( 'zb-one.pdf' ), 'filename' => 'one.pdf' ) ), 'zb-single@example.test' );
		$this->assertSame( 1, $one['certificate_count'] );

		$many = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$many[] = array( 'path' => $this->pdf( "zb-many-$i.pdf" ), 'filename' => "many-$i.pdf" );
		}
		$res = $this->zip( $many, 'zb-many@example.test' );
		$this->assertSame( 25, $res['certificate_count'] );
		$this->assertCount( 25, $this->entries( $res['zip_path'] ) );
	}

	public function test_pdfs_are_stored_and_manifest_is_deflated_from_string(): void {
		// Realistic size: libzip stores a tiny entry when deflate would not shrink it.
		$csv = "name,email\n" . str_repeat( "Asha Rao,asha@example.test\n", 50 );
		$res = $this->zip(
			array(
				array( 'path' => $this->pdf( 'zb-store.pdf' ), 'filename' => 'store.pdf' ),
				array( 'content' => $csv, 'filename' => 'manifest.csv' ),
			),
			'zb-store@example.test'
		);
		$e = $this->entries( $res['zip_path'] );
		$this->assertSame( ZipArchive::CM_STORE, $e['store.pdf']['method'], 'PDFs are already compressed' );
		$this->assertSame( ZipArchive::CM_DEFLATE, $e['manifest.csv']['method'] );
		$this->assertSame( $csv, $e['manifest.csv']['content'] );
		$this->assertSame( 1, $res['certificate_count'], 'manifest is not a certificate' );
	}

	public function test_entry_names_cannot_escape_the_archive(): void {
		$res = $this->zip(
			array(
				array( 'path' => $this->pdf( 'zb-trav.pdf' ), 'filename' => '../../wp-config.pdf' ),
				array( 'path' => $this->pdf( 'zb-trav2.pdf' ), 'filename' => '..\\..\\evil.pdf' ),
			),
			'zb-trav@example.test'
		);
		$names = array_keys( $this->entries( $res['zip_path'] ) );
		sort( $names );
		$this->assertSame( array( 'evil.pdf', 'wp-config.pdf' ), $names );
	}

	public function test_sources_outside_uploads_are_rejected(): void {
		$res = $this->zip(
			array(
				array( 'path' => ABSPATH . 'wp-config.php', 'filename' => 'config.pdf' ),
				array( 'path' => certificate_generator_certificates_dir() . '/../../../wp-config.php', 'filename' => 'config2.pdf' ),
				array( 'path' => $this->pdf( 'zb-ok.pdf' ), 'filename' => 'ok.pdf' ),
			),
			'zb-outside@example.test'
		);
		$this->assertSame( array( 'ok.pdf' ), array_keys( $this->entries( $res['zip_path'] ) ) );
		$this->assertSame( 2, $res['failed_count'] );
	}

	public function test_missing_file_is_reported_not_fatal(): void {
		$res = $this->zip(
			array(
				array( 'path' => certificate_generator_certificates_dir() . '/zb-does-not-exist.pdf', 'filename' => 'gone.pdf' ),
				array( 'path' => $this->pdf( 'zb-here.pdf' ), 'filename' => 'here.pdf' ),
			),
			'zb-missing@example.test'
		);
		$this->assertSame( 1, $res['certificate_count'] );
		$this->assertSame( 1, $res['failed_count'] );
	}

	public function test_duplicate_entry_names_are_kept_apart(): void {
		$res = $this->zip(
			array(
				array( 'path' => $this->pdf( 'zb-dup1.pdf', 'first' . str_repeat( 'x', 200 ) ), 'filename' => 'same.pdf' ),
				array( 'path' => $this->pdf( 'zb-dup2.pdf', 'second' . str_repeat( 'y', 200 ) ), 'filename' => 'same.pdf' ),
			),
			'zb-dup@example.test'
		);
		$e = $this->entries( $res['zip_path'] );
		$this->assertCount( 2, $e );
		$this->assertArrayHasKey( 'same.pdf', $e );
		$this->assertArrayHasKey( 'same-2.pdf', $e );
	}

	public function test_private_zip_is_hidden_unguessable_and_reused(): void {
		$data  = array( array( 'path' => $this->pdf( 'zb-priv.pdf' ), 'filename' => 'priv.pdf' ) );
		$first = $this->zip( $data, 'admin_students_2026-10-03', array( 'private' => true ) );

		$dir = wp_normalize_path( certificate_generator_certificates_dir() . '/private' );
		$this->assertSame( $dir, wp_normalize_path( dirname( $first['zip_path'] ) ) );
		$this->assertFileExists( $dir . '/.htaccess' );
		$this->assertFileExists( $dir . '/index.php' );
		$this->assertMatchesRegularExpression( '/^certificates_admin_students_2026_10_03_[A-Za-z0-9]{20}\.zip$/', basename( $first['zip_path'] ) );
		$this->assertArrayNotHasKey( 'zip_url', array_filter( $first ), 'A private ZIP has no public URL' );

		$second = $this->zip( $data, 'admin_students_2026-10-03', array( 'private' => true ) );
		$this->assertSame( $first['zip_path'], $second['zip_path'], 'Identical content reuses the ZIP' );
	}

	public function test_wrapper_and_zip_service_pass_args_through(): void {
		$data = array( array( 'path' => $this->pdf( 'zb-svc.pdf' ), 'filename' => 'svc.pdf' ) );

		$legacy = certificate_generator_create_zip_for_email( $data, 'zb-svc', array( 'private' => true ) );
		$this->cleanup[] = $legacy['zip_path'];
		$this->assertStringContainsString( '/private/', wp_normalize_path( $legacy['zip_path'] ) );

		$service = \CertificateGenerator\Services\ZipService::make( $data, 'zb-svc2', array( 'private' => true ) );
		$this->cleanup[] = $service['zip_path'];
		$this->assertStringContainsString( '/private/', wp_normalize_path( $service['zip_path'] ) );

		$public = \CertificateGenerator\Services\ZipService::make( $data, 'zb-svc3@example.test' );
		$this->cleanup[] = $public['zip_path'];
		$this->assertStringNotContainsString( '/private/', wp_normalize_path( $public['zip_path'] ) );
		$this->assertNotEmpty( $public['zip_url'] );
	}
}
