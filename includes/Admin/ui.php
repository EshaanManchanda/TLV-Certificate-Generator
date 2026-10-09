<?php
/**
 * Shared admin UI helpers. Styles: assets/css/cg-ui.css, behaviour: assets/js/cg-ui.js.
 *
 * Text arguments are escaped here. Arguments named *_html are trusted markup
 * and must already be escaped by the caller.
 *
 * @package CertificateGenerator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page title + one-line explanation + optional action buttons.
 */
function cg_ui_page_header( string $title, string $lead = '', string $actions_html = '' ): void {
	echo '<div class="cg-page-header"><div>';
	echo '<h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>';
	if ( '' !== $lead ) {
		echo '<p class="cg-page-header__lead">' . esc_html( $lead ) . '</p>';
	}
	echo '</div>';
	if ( '' !== $actions_html ) {
		echo '<div class="cg-page-header__actions">' . $actions_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted *_html.
	}
	echo '</div><hr class="wp-header-end">';
}

/**
 * WP admin notice. $message allows basic inline markup (a, strong, em, code, br).
 *
 * @param string $type   success|error|warning|info.
 * @param bool   $inline Keep it where it is printed (e.g. inside a card). Without it WP's
 *                       common.js moves the notice up under the page header.
 */
function cg_ui_notice( string $type, string $message, bool $dismissible = true, bool $inline = false ): void {
	$type    = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info';
	$allowed = array(
		'a'      => array(
			'href'   => true,
			'target' => true,
			'rel'    => true,
			'class'  => true,
		),
		'strong' => array(),
		'em'     => array(),
		'code'   => array(),
		'br'     => array(),
	);
	printf(
		'<div class="notice notice-%s%s%s"><p>%s</p></div>',
		esc_attr( $type ),
		$dismissible ? ' is-dismissible' : '',
		$inline ? ' inline' : '',
		wp_kses( $message, $allowed )
	);
}

/**
 * Open a card. Close it with cg_ui_card_close().
 *
 * @param array{icon?:string,actions_html?:string,class?:string,id?:string} $args
 */
function cg_ui_card_open( string $title = '', array $args = array() ): void {
	$class = trim( 'cg-card ' . ( $args['class'] ?? '' ) );
	$id    = isset( $args['id'] ) ? ' id="' . esc_attr( $args['id'] ) . '"' : '';
	echo '<section class="' . esc_attr( $class ) . '"' . $id . '>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	if ( '' !== $title ) {
		echo '<div class="cg-card__head"><h2>';
		if ( ! empty( $args['icon'] ) ) {
			echo '<span class="dashicons dashicons-' . esc_attr( $args['icon'] ) . '" aria-hidden="true"></span> ';
		}
		echo esc_html( $title ) . '</h2>';
		if ( ! empty( $args['actions_html'] ) ) {
			echo '<div class="cg-actions">' . $args['actions_html'] . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted *_html.
		}
		echo '</div>';
	}
	echo '<div class="cg-card__body">';
}

/**
 * Close a card, optionally with a footer.
 */
function cg_ui_card_close( string $footer_html = '' ): void {
	echo '</div>';
	if ( '' !== $footer_html ) {
		echo '<div class="cg-card__foot">' . $footer_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted *_html.
	}
	echo '</section>';
}

/**
 * One stat tile. Wrap several in <div class="cg-stats">.
 *
 * @param string $variant ''|good|warn|bad.
 */
function cg_ui_stat( string $label, $value, string $hint = '', string $variant = '' ): void {
	$class = 'cg-stat' . ( '' !== $variant ? ' cg-stat--' . sanitize_html_class( $variant ) : '' );
	echo '<div class="' . esc_attr( $class ) . '">';
	echo '<div class="cg-stat__label">' . esc_html( $label ) . '</div>';
	echo '<div class="cg-stat__value">' . esc_html( is_numeric( $value ) ? number_format_i18n( (float) $value ) : (string) $value ) . '</div>';
	if ( '' !== $hint ) {
		echo '<div class="cg-stat__hint">' . esc_html( $hint ) . '</div>';
	}
	echo '</div>';
}

/**
 * Status pill.
 *
 * @param string $variant good|warn|bad|info|muted.
 */
function cg_ui_badge( string $text, string $variant = 'muted', string $title = '', string $class = '' ): string {
	return '<span class="' . esc_attr( trim( 'cg-badge cg-badge--' . sanitize_html_class( $variant ) . ' ' . $class ) ) . '"'
		. ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . '>' . esc_html( $text ) . '</span>';
}

/**
 * Empty state with an optional next-step button.
 */
function cg_ui_empty( string $message, string $cta_url = '', string $cta_label = '', string $icon = 'info-outline' ): string {
	$html  = '<div class="cg-empty">';
	$html .= '<span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
	$html .= '<p class="cg-empty__msg">' . esc_html( $message ) . '</p>';
	if ( '' !== $cta_url && '' !== $cta_label ) {
		$html .= '<a class="button button-primary" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_label ) . '</a>';
	}
	return $html . '</div>';
}

/**
 * Empty state as a full-width table row.
 */
function cg_ui_empty_row( int $colspan, string $message, string $cta_url = '', string $cta_label = '' ): string {
	return '<tr class="no-items"><td class="cg-empty-cell" colspan="' . (int) $colspan . '">'
		. cg_ui_empty( $message, $cta_url, $cta_label ) . '</td></tr>';
}

/**
 * Progress bar driven by CGUI.progress(). Hidden until JS shows it, unless $visible.
 * Pass $total to render a static (server-side) value.
 */
function cg_ui_progress( string $id, string $label = '', bool $visible = false, int $done = 0, int $total = 0 ): void {
	$count = '';
	$class = 'cg-progress';
	if ( $total > 0 ) {
		$count = sprintf( '%d / %d · %d%%', $done, $total, (int) round( $done / $total * 100 ) );
		$class .= $done >= $total ? ' is-done' : '';
	}
	printf(
		'<div class="%7$s" id="%1$s"%2$s><progress max="%4$d" value="%5$d" aria-labelledby="%1$s-text"></progress>'
		. '<div class="cg-progress__label"><span class="cg-progress__text" id="%1$s-text" aria-live="polite">%3$s</span>'
		. '<span class="cg-progress__count">%6$s</span></div></div>',
		esc_attr( $id ),
		$visible ? '' : ' hidden',
		esc_html( $label ),
		(int) ( max( 1, $total ) ),
		(int) $done,
		esc_html( $count ),
		esc_attr( $class )
	);
}

/**
 * Inline spinner.
 */
function cg_ui_spinner( bool $small = false, string $id = '' ): string {
	return '<span class="cg-spinner' . ( $small ? ' cg-spinner--sm' : '' ) . '"'
		. ( '' !== $id ? ' id="' . esc_attr( $id ) . '" hidden' : '' )
		. ' role="status" aria-label="' . esc_attr__( 'Loading', 'certificate-generator' ) . '"></span>';
}

/**
 * Native <dialog>. Open with CGUI.modal(id).open(); any [data-cg-close] closes it.
 */
function cg_ui_dialog_open( string $id, string $title ): void {
	printf(
		'<dialog class="cg-dialog" id="%1$s" aria-labelledby="%1$s-title"><div class="cg-dialog__head"><h2 id="%1$s-title">%2$s</h2>'
		. '<button type="button" class="cg-dialog__close" data-cg-close aria-label="%3$s"><span class="dashicons dashicons-no-alt"></span></button></div>'
		. '<div class="cg-dialog__body">',
		esc_attr( $id ),
		esc_html( $title ),
		esc_attr__( 'Close', 'certificate-generator' )
	);
}

function cg_ui_dialog_close( string $actions_html = '' ): void {
	echo '</div>';
	if ( '' !== $actions_html ) {
		echo '<div class="cg-dialog__actions">' . $actions_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted *_html.
	}
	echo '</dialog>';
}

/**
 * WP nav tabs.
 *
 * @param array<string,string|array{0:string,1?:string,2?:string}> $tabs slug => label, or
 *        slug => [ label, badge text, badge variant ] to show a status pill after the label.
 * @param string $base_url URL the `tab` query arg is added to.
 */
function cg_ui_tabs( array $tabs, string $current, string $base_url, string $arg = 'tab' ): void {
	echo '<nav class="nav-tab-wrapper wp-clearfix">';
	foreach ( $tabs as $slug => $tab ) {
		$tab = (array) $tab;
		printf(
			'<a href="%s" class="nav-tab%s"%s>%s%s</a>',
			esc_url( add_query_arg( $arg, $slug, $base_url ) ),
			$slug === $current ? ' nav-tab-active' : '',
			$slug === $current ? ' aria-current="page"' : '',
			esc_html( $tab[0] ),
			! empty( $tab[1] ) ? ' ' . cg_ui_badge( $tab[1], $tab[2] ?? 'muted' ) : '' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside
		);
	}
	echo '</nav>';
}
