<?php
declare(strict_types=1);

namespace CertificateGenerator\Listeners;

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Services\BadgeGenerator;

/**
 * Renders a companion badge PNG alongside a certificate PDF, for templates
 * that have an optional badge_template_url configured. Hooked to the existing
 * certificate_generator_certificate_generated action ($post_id, $pdf_path) — no new trigger
 * plumbing needed.
 *
 * Gated by CG_USE_BADGES (off by default), registered in Plugin::register_hooks().
 */
class BadgeGenerationListener {

	public function handle( $post_id, $pdf_path ): void {
		global $wpdb;
		$cert_table = CustomTables::instance()->get_table( 'certificates' );
		if ( ! $cert_table || empty( $pdf_path ) ) {
			return;
		}

		// $post_id/$pdf_path is all certificate_generator_certificate_generated carries — correlate back
		// to the row certificate_generator_generate_pdf_impl() just wrote via the pdf_path it returned.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$cert = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $cert_table WHERE pdf_path = %s ORDER BY id DESC LIMIT 1", $pdf_path ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $cert || ! empty( $cert['badge_path'] ) ) {
			return; // no matching row, or a badge was already generated for it
		}

		$tpl_table = CustomTables::instance()->get_table( 'certificate_templates' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$badge_url = ( $tpl_table && ! empty( $cert['template_id'] ) )
			? $wpdb->get_var( $wpdb->prepare( "SELECT badge_template_url FROM $tpl_table WHERE id = %d", $cert['template_id'] ) )
			: '';
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $badge_url ) ) {
			return; // this template has no badge configured
		}

		$badge_path = BadgeGenerator::generate( $cert, (string) $badge_url );
		if ( $badge_path ) {
			$wpdb->update( $cert_table, array( 'badge_path' => $badge_path ), array( 'id' => $cert['id'] ) );
		}
	}
}
