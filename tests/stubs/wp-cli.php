<?php
// phpcs:ignoreFile
/** The parts of WP-CLI that includes/Cli/commands.php uses, for PHPStan (scanned, never loaded). */

namespace {
	class WP_CLI {
		/** @return never */
		public static function error( $message ) {}
		/** @return never */
		public static function halt( int $code ) {}
		public static function success( string $message ): void {}
		public static function warning( string $message ): void {}
		public static function log( string $message ): void {}
		public static function add_command( string $name, $callable ): bool {}
	}
}

namespace WP_CLI\Utils {
	/** @return \cli\progress\Bar */
	function make_progress_bar( string $message, int $count ) {}
	function format_items( string $format, array $items, $fields ): void {}
}

namespace cli\progress {
	class Bar {
		public function tick( int $increment = 1 ): void {}
		public function finish(): void {}
	}
}
