<?php
declare(strict_types=1);

namespace CertificateGenerator\Core;

/**
 * Ready-made certificate background designs shipped with the plugin, so an
 * admin can pick a starting design instead of only uploading their own image.
 *
 * A plain static list, not a DB table — this only changes on plugin updates,
 * same instinct as a bundled-fonts constant. Design assets here are
 * placeholders; swap the files under assets/templates/ for real designs.
 */
class BundledTemplates {

	/**
	 * @return array<int,array{id:string,label:string,file:string,orientation:string}>
	 */
	public static function all(): array {
		return array(
			array(
				'id'          => 'classic-gold',
				'label'       => 'Classic Gold',
				'file'        => 'classic-gold.jpg',
				'orientation' => 'landscape',
			),
			array(
				'id'          => 'modern-blue',
				'label'       => 'Modern Blue',
				'file'        => 'modern-blue.jpg',
				'orientation' => 'portrait',
			),
		);
	}

	public static function find( string $id ): ?array {
		foreach ( self::all() as $tpl ) {
			if ( $tpl['id'] === $id ) {
				return $tpl;
			}
		}
		return null;
	}

	public static function url_for( string $id ): ?string {
		$tpl = self::find( $id );
		return $tpl ? CERTIFICATE_GENERATOR_URL . 'assets/templates/' . $tpl['file'] : null;
	}
}
