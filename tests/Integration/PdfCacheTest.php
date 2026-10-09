<?php
/**
 * Deterministic PDF cache in certificate_generator_generate_pdf_impl(): an unchanged certificate is
 * served from disk (no render, no DB writes, no usage); any change to the row,
 * the template or the template image re-renders it. Usage counts new serials only.
 */
class PdfCacheTest extends WP_UnitTestCase {

	private static string $template_image;
	private static string $template_url;
	private int $template_id = 0;
	private int $renders     = 0;

	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		$upload_dir = wp_upload_dir();
		wp_mkdir_p( $upload_dir['basedir'] . '/cg-phpunit-pdfcache' );
		self::$template_image = $upload_dir['basedir'] . '/cg-phpunit-pdfcache/template.png';
		self::$template_url   = $upload_dir['baseurl'] . '/cg-phpunit-pdfcache/template.png';
		self::write_template_image( 255 );
	}

	public static function tear_down_after_class(): void {
		@unlink( self::$template_image );
		@rmdir( dirname( self::$template_image ) );
		parent::tear_down_after_class();
	}

	private static function write_template_image( int $shade ): void {
		$im = imagecreatetruecolor( 800, 600 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, $shade, $shade, $shade ) );
		imagepng( $im, self::$template_image );
		imagedestroy( $im );
		clearstatcache();
	}

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->insert(
			\CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' ),
			array(
				'template_name'    => 'PHPUnit Cache Template',
				'certificate_type' => 'PHPUnitCacheCert',
				'template_url'     => self::$template_url,
				'orientation'      => 'landscape',
				'status'           => 'published',
				'extra_fields'     => wp_json_encode( array( '1_position_x' => 50, '1_position_y' => 50 ) ),
				'created_at'       => current_time( 'mysql' ),
				'updated_at'       => current_time( 'mysql' ),
			)
		);
		$this->template_id = (int) $wpdb->insert_id;

		$this->renders = 0;
		add_action( 'certificate_generator_certificate_generated', array( $this, 'count_render' ) );
	}

	public function count_render(): void {
		++$this->renders;
	}

	private function make_row( string $name, string $serial = 'CACHE-0001' ): array {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		$wpdb->insert(
			$table,
			array(
				'student_name'     => $name,
				'email'            => 'cache@example.test',
				'certificate_type' => 'PHPUnitCacheCert',
				'issue_date'       => current_time( 'Y-m-d' ),
				'serial_number'    => $serial ?: null,
			)
		);
		return $this->reload( (int) $wpdb->insert_id );
	}

	private function reload( int $id ): array {
		global $wpdb;
		$table = \CertificateGenerator\Database\CustomTables::instance()->get_table( 'students' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A );
	}

	private function generate( array $row ): string {
		$url = certificate_generator_generate_certificate_pdf( (int) $row['id'], array(), $row );
		$this->assertNotEmpty( $url, 'PDF generation failed' );
		return str_replace( wp_upload_dir()['baseurl'], wp_upload_dir()['basedir'], $url );
	}

	public function test_unchanged_certificate_is_served_from_cache(): void {
		$row  = $this->make_row( 'ASHA RAO' );
		$path = $this->generate( $row );
		$this->assertSame( 1, $this->renders );
		$this->assertNotSame( '', certificate_generator_pdf_cache_read_key( $path ), 'Rendered PDF must carry its cache key' );

		$mtime = filemtime( $path );
		$again = $this->generate( $row );
		$this->assertSame( $path, $again, 'Same certificate, same URL' );
		$this->assertSame( 1, $this->renders, 'Second request must be a cache hit' );
		clearstatcache();
		$this->assertSame( $mtime, filemtime( $path ), 'A cache hit must not rewrite the file' );
		$this->assertEmpty( glob( dirname( $path ) . '/*.tmp' ), 'No temp files left behind' );
	}

	public function test_changed_row_data_rerenders(): void {
		$row = $this->make_row( 'RAVI RAO' );
		$this->generate( $row );
		$row['student_name'] = 'RAVI K RAO';
		$this->generate( $row );
		$this->assertSame( 2, $this->renders );
	}

	public function test_changed_template_rerenders(): void {
		global $wpdb;
		$row = $this->make_row( 'MEERA RAO' );
		$this->generate( $row );
		$wpdb->update(
			\CertificateGenerator\Database\CustomTables::instance()->get_table( 'certificate_templates' ),
			array( 'extra_fields' => wp_json_encode( array( '1_position_x' => 60, '1_position_y' => 50 ) ) ),
			array( 'id' => $this->template_id )
		);
		wp_cache_flush(); // the template memo is request-scoped; a new request would see the edit
		$this->generate( $row );
		$this->assertSame( 2, $this->renders );
	}

	public function test_replaced_template_image_rerenders(): void {
		$row = $this->make_row( 'KIRAN RAO' );
		$this->generate( $row );
		sleep( 1 ); // mtime has one-second resolution
		self::write_template_image( 200 );
		$this->generate( $row );
		self::write_template_image( 255 );
		$this->assertSame( 2, $this->renders );
	}

	public function test_file_without_marker_is_rerendered(): void {
		$row  = $this->make_row( 'DEV RAO' );
		$path = $this->generate( $row );
		file_put_contents( $path, '%PDF-1.3 truncated' );
		$this->generate( $row );
		$this->assertSame( 2, $this->renders );
		$this->assertNotSame( '', certificate_generator_pdf_cache_read_key( $path ) );
	}

	public function test_cache_can_be_disabled(): void {
		add_filter( 'certificate_generator_pdf_cache_enabled', '__return_false' );
		$row = $this->make_row( 'TARA RAO' );
		$this->generate( $row );
		$this->generate( $row );
		remove_filter( 'certificate_generator_pdf_cache_enabled', '__return_false' );
		$this->assertSame( 2, $this->renders );
	}
}
