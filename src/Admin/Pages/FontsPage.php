<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Database\CustomTables;

/**
 * Fonts page: lists the built-in font library and any uploaded custom fonts.
 * Uploading a .ttf comes from the Pro add-on. Uploaded fonts render via tFPDF (see
 * CertificateGenerator_FontManager::create_pdf_instance()) and show up in the
 * Templates font dropdown alongside the bundled fonts.
 */
class FontsPage extends Page {

	public function __construct() {
		parent::__construct( 'cg-fonts', 'Fonts' );
	}

	public function render(): void {
		if ( ! $this->check_permission() ) {
			wp_die( esc_html__( 'Insufficient permissions', 'certificate-generator' ) );
		}


		global $wpdb;
		$table = CustomTables::instance()->get_table( 'custom_fonts' );

		// The Pro add-on handles uploads here and returns its notice.
		$message = (string) apply_filters( 'certificate_generator_fonts_page_message', '' );

		if ( isset( $_GET['cg_font_delete'] ) && check_admin_referer( 'cg_font_delete' ) ) {
			$wpdb->delete( $table, array( 'id' => absint( $_GET['cg_font_delete'] ) ) );
			$message = 'Font removed.';
		}

		$fonts        = $wpdb->get_results( "SELECT * FROM $table ORDER BY font_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$builtin      = self::builtin_fonts();
		$upload_dir   = wp_upload_dir();
		$templates_tb = CustomTables::instance()->get_table( 'certificate_templates' );
		?>
		<style>
			<?php foreach ( $fonts as $font ) : ?>
			@font-face {
				font-family: 'cg-preview-<?php echo (int) $font->id; ?>';
				src: url('<?php echo esc_url( str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $font->file_path ) ); ?>') format('truetype');
			}
			.cg-font-preview-<?php echo (int) $font->id; ?> { font-family: 'cg-preview-<?php echo (int) $font->id; ?>', sans-serif; font-size: 16px; }
			<?php endforeach; ?>
		</style>
		<div class="wrap">
			<?php
			certificate_generator_ui_page_header(
				$this->title,
				__( 'Fonts available to your certificate templates. Pick one in a template\'s Font Style field.', 'certificate-generator' ),
				'<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=certificate_generator_font_sample' ), 'certificate_generator_font_sample' ) ) . '" class="button" target="_blank" rel="noopener">' . esc_html__( 'Download sample PDF', 'certificate-generator' ) . '</a>'
			);
			if ( $message ) {
				$is_error = stripos( $message, 'fail' ) !== false || stripos( $message, "can't" ) !== false || stripos( $message, 'please' ) !== false;
				certificate_generator_ui_notice( $is_error ? 'error' : 'success', esc_html( $message ) );
			}
			?>

			<?php do_action( 'certificate_generator_fonts_page_upload_card' ); ?>

			<?php certificate_generator_ui_card_open( __( 'Built-in fonts', 'certificate-generator' ), array( 'icon' => 'editor-textcolor' ) ); ?>
			<p class="cg-hint"><?php esc_html_e( 'Included with the plugin. Download the sample PDF to see each font exactly as it prints on a certificate.', 'certificate-generator' ); ?></p>
			<div class="cg-table-wrap">
			<table class="widefat striped cg-table" id="cg-builtin-fonts">
				<thead><tr><th><?php esc_html_e( 'Name', 'certificate-generator' ); ?></th><th><?php esc_html_e( 'Font key', 'certificate-generator' ); ?></th><th><?php esc_html_e( 'Used by', 'certificate-generator' ); ?></th><th><?php esc_html_e( 'Notes', 'certificate-generator' ); ?></th></tr></thead>
				<tbody>
					<?php if ( empty( $builtin ) ) : ?>
						<?php echo certificate_generator_ui_empty_row( 4, __( 'No built-in fonts found in lib/fpdf/font/. Reinstall the plugin to restore them.', 'certificate-generator' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
					<?php endif; ?>
					<?php foreach ( $builtin as $font_key => $builtin_font ) : ?>
						<?php
						$usage_count = $templates_tb
							? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $templates_tb WHERE font_style = %s", $font_key ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							: 0;
						?>
						<tr>
							<td><?php echo esc_html( $builtin_font['name'] ); ?></td>
							<td><code><?php echo esc_html( $font_key ); ?></code></td>
							<td><?php echo esc_html( sprintf( /* translators: %d: number of templates using this font */ _n( '%d template', '%d templates', $usage_count, 'certificate-generator' ), $usage_count ) ); ?></td>
							<td><?php echo $builtin_font['fallback'] ? esc_html__( 'Prints as Helvetica (font file not supported)', 'certificate-generator' ) : ''; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php certificate_generator_ui_card_close(); ?>

			<?php certificate_generator_ui_card_open( __( 'Uploaded fonts', 'certificate-generator' ), array( 'icon' => 'upload' ) ); ?>
			<div class="cg-table-wrap">
			<table class="widefat striped cg-table">
				<thead><tr><th>Name</th><th>Preview</th><th>File</th><th>Used by</th><th></th></tr></thead>
				<tbody>
					<?php if ( empty( $fonts ) ) : ?>
						<?php echo certificate_generator_ui_empty_row( 5, 'No custom fonts uploaded yet.' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
					<?php endif; ?>
					<?php foreach ( $fonts as $font ) : ?>
						<?php
						$usage_count = $templates_tb
							? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $templates_tb WHERE font_style = %s", $font->font_name ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							: 0;
						?>
						<tr>
							<td><?php echo esc_html( $font->font_name ); ?></td>
							<td class="cg-font-preview-<?php echo (int) $font->id; ?>">The quick brown fox 123</td>
							<td><?php echo esc_html( basename( $font->file_path ) ); ?></td>
							<td><?php echo esc_html( sprintf( /* translators: %d: number of templates using this font */ _n( '%d template', '%d templates', $usage_count, 'certificate-generator' ), $usage_count ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=cg-fonts&cg_font_delete=' . $font->id ), 'cg_font_delete' ) ); ?>"
									class="cg-link-delete" data-cg-danger data-cg-confirm-label="Remove" data-cg-confirm="<?php echo esc_attr( 'Remove this font?' . ( $usage_count ? " It is still used by {$usage_count} template(s); pick another font for them." : '' ) ); ?>">Remove</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<?php certificate_generator_ui_card_close(); ?>
		</div>
		<?php
	}

	/**
	 * Built-in (bundled FPDF) fonts, keyed by the font_style value templates save.
	 * Same list every plan sees in the template editor (get_font_options()).
	 *
	 * @return array<string,array{name:string,fallback:bool}>
	 */
	public static function builtin_fonts(): array {
		if ( ! class_exists( 'CertificateGenerator_FontManager' ) ) {
			return array();
		}
		$manager = \CertificateGenerator_FontManager::getInstance();
		$all     = $manager->get_available_fonts();
		$fonts   = array();
		foreach ( $manager->get_font_options() as $key => $name ) {
			$path = $all[ $key ]['path'] ?? '';
			// Mirrors add_font_to_pdf(): TCPDF-format files can't load in FPDF and print as Helvetica.
			$head          = $path && is_readable( $path ) ? (string) file_get_contents( $path, false, null, 0, 100 ) : '';
			$fonts[ $key ] = array(
				'name'     => (string) $name,
				'fallback' => strpos( $head, "'TrueTypeUnicode'" ) !== false,
			);
		}
		return $fonts;
	}

	/** One A4 page, one line per built-in font, rendered the way certificates are. Returns the PDF bytes. */
	public static function build_sample_pdf(): string {
		if ( ! class_exists( 'FPDF' ) ) {
			require_once CERTIFICATE_GENERATOR_PATH . 'lib/fpdf/fpdf.php';
		}
		$manager = \CertificateGenerator_FontManager::getInstance();
		$pdf     = new \FPDF( 'P', 'mm', 'A4' );
		$pdf->SetAutoPageBreak( true, 12 );
		$pdf->AddPage();
		$pdf->SetFont( 'Helvetica', 'B', 14 );
		$pdf->Cell( 0, 10, 'Certificate Generator - built-in fonts', 0, 1 );

		foreach ( self::builtin_fonts() as $key => $font ) {
			$pdf->SetFont( 'Helvetica', '', 8 );
			$pdf->Cell( 55, 9, $font['name'] . ( $font['fallback'] ? ' (prints as Helvetica)' : '' ), 0, 0 );
			$manager->add_font_to_pdf( $pdf, $key, '', 15 );
			$pdf->Cell( 0, 9, 'Priya Sharma - The quick brown fox 0123', 0, 1 );
		}

		return $pdf->Output( 'S' );
	}

	/** Handler for admin-post.php?action=certificate_generator_font_sample (registered in Plugin::register_hooks()). */
	public static function stream_sample_pdf(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'certificate-generator' ), 403 );
		}
		check_admin_referer( 'certificate_generator_font_sample' );

		$pdf = self::build_sample_pdf();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="certificate-fonts-sample.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF
		exit;
	}
}
