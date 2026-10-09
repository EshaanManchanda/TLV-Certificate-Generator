<?php
declare(strict_types=1);

namespace CertificateGenerator\Core;

/**
 * Simple dependency injection container.
 */
class Container {
	private array $bindings  = array();
	private array $instances = array();

	public function bind( string $abstract, callable $concrete ): void {
		$this->bindings[ $abstract ] = $concrete;
	}

	public function singleton( string $abstract, callable $concrete ): void {
		$this->bindings[ $abstract ]                = $concrete;
		$this->bindings[ $abstract . ':singleton' ] = true;
	}

	public function instance( string $abstract, $instance ): void {
		$this->instances[ $abstract ] = $instance;
	}

	public function make( string $abstract ) {
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}

		if ( ! isset( $this->bindings[ $abstract ] ) ) {
			throw new \InvalidArgumentException( esc_html( "Binding [{$abstract}] not found in container." ) );
		}

		$instance = call_user_func( $this->bindings[ $abstract ], $this );

		if ( ! empty( $this->bindings[ $abstract . ':singleton' ] ) ) {
			$this->instances[ $abstract ] = $instance;
		}

		return $instance;
	}

	public function has( string $abstract ): bool {
		return isset( $this->bindings[ $abstract ] ) || isset( $this->instances[ $abstract ] );
	}
}
