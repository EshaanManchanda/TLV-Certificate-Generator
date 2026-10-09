<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

/**
 * Base admin page class.
 */
abstract class Page {

	protected string $slug;
	protected string $title;
	protected string $parent_slug = 'cg-dashboard';
	protected string $capability  = 'manage_options';

	public function __construct( string $slug, string $title ) {
		$this->slug  = $slug;
		$this->title = $title;
	}

	public function register(): void {
		add_submenu_page(
			$this->parent_slug,
			$this->title,
			$this->title,
			$this->capability,
			$this->slug,
			array( $this, 'render' )
		);
	}

	public function check_permission(): bool {
		return current_user_can( $this->capability );
	}

	abstract public function render(): void;
}
