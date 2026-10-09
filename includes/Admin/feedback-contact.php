<?php
/**
 * Feedback & Contact links (external Google Forms)
 *
 * @package Certificate Generator
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cg_render_feedback_page() {
	cg_render_external_form_page(
		'Feedback',
		'Have feedback on Certificate Generator — a bug, a rough edge, or an idea? Let us know.',
		'https://docs.google.com/forms/d/e/1FAIpQLSc4ep5uDjqxaFkvGANZ0m9Ui9mYM2CPT6MHER_O2QNne4MfRw/viewform',
		'Open Feedback Form'
	);
}

function cg_render_contact_page() {
	cg_render_external_form_page(
		'Contact Us',
		'Need help or want to get in touch with the Certificate Generator team?',
		'https://docs.google.com/forms/d/e/1FAIpQLSdxXpNIb93HEVh3aB3dq39GXG3XcxUQRSM9EQYajD309ti39A/viewform',
		'Open Contact Form'
	);
}

function cg_render_external_form_page( $title, $description, $form_url, $button_label ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'certificate-generator' ) );
	}
	?>
	<div class="wrap">
		<?php
		cg_ui_page_header( $title, $description );
		cg_ui_card_open( '', array( 'class' => 'cg-narrow' ) );
		?>
			<p><?php esc_html_e( 'The form opens in a new tab (Google Forms).', 'certificate-generator' ); ?></p>
			<p>
				<a href="<?php echo esc_url( $form_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary">
					<?php echo esc_html( $button_label ); ?> &#8599;
				</a>
			</p>
		<?php cg_ui_card_close(); ?>
	</div>
	<?php
}
