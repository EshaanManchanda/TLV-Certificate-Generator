<?php
declare(strict_types=1);

/**
 * Static checks for the WordPress.org plugin guidelines listed in wporg-guidelines.json.
 * Pure PHP (no WordPress): every public method named after a check id returns a list of
 * violations ("file:line message"); an empty list means the check passes.
 */
final class GuidelineScanner {

	private string $root;
	private array $config;
	/** @var array<string,string> relative path => contents, for the files that ship */
	private array $files = array();
	/** @var array<string,array> relative path => tokens */
	private array $tokens = array();

	private const GETTEXT = array(
		'__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1,
		'_x' => 2, '_ex' => 2, 'esc_html_x' => 2, 'esc_attr_x' => 2, '_n' => 3, '_nx' => 4,
	);

	public function __construct( string $root, array $config ) {
		$this->root   = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->config = $config;
		$this->collect_files();
	}

	// ── File set ──────────────────────────────────────────────────────────────

	private function collect_files(): void {
		$ignored = array();
		foreach ( (array) @file( $this->root . '/.gitattributes', FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '#^/?(\S+)\s+export-ignore#', trim( (string) $line ), $m ) ) {
				$ignored[] = trim( $m[1], '/' );
			}
		}
		// Plus local-only folders that are never committed.
		$ignored = array_merge( $ignored, array( '.git', '.local', 'node_modules', 'dist', 'test-results', 'playwright-report', '.playwright-mcp', 'playwright' ) );

		// The release ZIP is built from git, so scan what git tracks (plus new, non-ignored files);
		// fall back to the whole tree when git isn't available.
		$tracked = null;
		$out     = @shell_exec( 'git -C ' . escapeshellarg( $this->root ) . ' ls-files --cached --others --exclude-standard 2>' . ( '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null' ) );
		if ( is_string( $out ) && '' !== trim( $out ) ) {
			$tracked = array_flip( array_map( 'trim', explode( "\n", trim( $out ) ) ) );
		}

		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$rel = ltrim( substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $this->root ) ), '/' );
			if ( null !== $tracked && ! isset( $tracked[ $rel ] ) && ! str_starts_with( $rel, 'vendor/' ) ) {
				continue; // not in git => not in the ZIP (vendor/ is installed by the build)
			}
			foreach ( $ignored as $ig ) {
				if ( $rel === $ig || str_starts_with( $rel, $ig . '/' ) ) {
					continue 2;
				}
			}
			$this->files[ $rel ] = (string) file_get_contents( $file->getPathname() );
		}
		ksort( $this->files );
	}

	/** Shipped PHP files we own (not bundled libraries). */
	private function own_php(): array {
		return array_filter(
			array_keys( $this->files ),
			static fn( $f ) => str_ends_with( $f, '.php' ) && ! str_starts_with( $f, 'lib/' ) && ! str_starts_with( $f, 'vendor/' )
		);
	}

	private function tokens( string $file ): array {
		return $this->tokens[ $file ] ??= token_get_all( $this->files[ $file ] );
	}

	private function readme(): string {
		return $this->files['readme.txt'] ?? '';
	}

	private function main_header(): array {
		$head = substr( $this->files['certificate-generator.php'] ?? '', 0, 2000 );
		preg_match_all( '/^\s*\*?\s*([A-Za-z ]+):\s*(.+)$/m', $head, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $row ) {
			$out[ trim( $row[1] ) ] = trim( $row[2] );
		}
		return $out;
	}

	private function readme_headers(): array {
		preg_match_all( '/^([A-Za-z ]+):\s*(.+)$/m', strstr( $this->readme(), '== Description ==', true ) ?: '', $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $row ) {
			$out[ trim( $row[1] ) ] = trim( $row[2] );
		}
		return $out;
	}

	// ── Token helpers ─────────────────────────────────────────────────────────

	private static function sig( array $tokens, int $i, int $dir = 1 ): ?int {
		for ( $j = $i + $dir; isset( $tokens[ $j ] ); $j += $dir ) {
			if ( ! is_array( $tokens[ $j ] ) || ! in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $j;
			}
		}
		return null;
	}

	private static function literal( $token ): ?string {
		if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
			return stripcslashes( substr( $token[1], 1, -1 ) );
		}
		return null;
	}

	/** Leading string of a double-quoted string ("prefix_{$x}") or a literal. */
	private static function leading_string( array $tokens, int $i ): ?string {
		$lit = self::literal( $tokens[ $i ] );
		if ( null !== $lit ) {
			return $lit;
		}
		if ( '"' === $tokens[ $i ] && isset( $tokens[ $i + 1 ] ) && is_array( $tokens[ $i + 1 ] ) && T_ENCAPSED_AND_WHITESPACE === $tokens[ $i + 1 ][0] ) {
			return $tokens[ $i + 1 ][1];
		}
		return null;
	}

	/**
	 * Call sites of $names: yields [file, line, name, argIndex => first token index of that arg].
	 * Method calls ($x->name()) and definitions (function name()) are skipped.
	 */
	private function calls( array $names, array $files ): iterable {
		foreach ( $files as $file ) {
			$t = $this->tokens( $file );
			foreach ( $t as $i => $tok ) {
				if ( ! is_array( $tok ) || T_STRING !== $tok[0] || ! in_array( strtolower( $tok[1] ), $names, true ) ) {
					continue;
				}
				$prev = self::sig( $t, $i, -1 );
				if ( null !== $prev && is_array( $t[ $prev ] ) && in_array( $t[ $prev ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR ), true ) ) {
					continue;
				}
				$open = self::sig( $t, $i );
				if ( null === $open || '(' !== $t[ $open ] ) {
					continue;
				}
				$args  = array( 0 => self::sig( $t, $open ) );
				$depth = 0;
				for ( $j = $open + 1; isset( $t[ $j ] ); $j++ ) {
					$c = is_array( $t[ $j ] ) ? $t[ $j ][1] : $t[ $j ];
					if ( in_array( $c, array( '(', '[', '{' ), true ) || ( is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
						++$depth;
					} elseif ( in_array( $c, array( ')', ']', '}' ), true ) ) {
						if ( 0 === $depth ) {
							break;
						}
						--$depth;
					} elseif ( ',' === $c && 0 === $depth ) {
						$args[] = self::sig( $t, $j );
					}
				}
				yield array( $file, $tok[2], strtolower( $tok[1] ), $args, $t );
			}
		}
	}

	private function own_files_with( string $ext ): array {
		return array_filter( array_keys( $this->files ), static fn( $f ) => str_ends_with( $f, $ext ) && ! str_starts_with( $f, 'lib/' ) && ! str_starts_with( $f, 'vendor/' ) );
	}

	// ── G1 ────────────────────────────────────────────────────────────────────

	public function gpl_headers(): array {
		$v = array();
		if ( ! preg_match( '/GPL-2\.0-or-later|GPLv2 or later/i', $this->main_header()['License'] ?? '' ) ) {
			$v[] = 'certificate-generator.php: License header is not GPL-2.0-or-later';
		}
		if ( ! preg_match( '/GPLv2 or later|GPL-2\.0-or-later/i', $this->readme_headers()['License'] ?? '' ) ) {
			$v[] = 'readme.txt: License header is not GPLv2 or later';
		}
		if ( ! isset( $this->files['LICENSE.txt'] ) ) {
			$v[] = 'LICENSE.txt missing';
		}
		return $v;
	}

	public function bundled_license_files(): array {
		$v = array();
		foreach ( array( 'lib/fpdf/license.txt', 'lib/tfpdf/license.txt', 'lib/fpdf/font/FONTS-LICENSE.txt', 'assets/vendor/chart.js/LICENSE.md' ) as $f ) {
			if ( ! isset( $this->files[ $f ] ) ) {
				$v[] = "$f missing";
			}
		}
		return $v;
	}

	// ── G3 / G15 / G16 ────────────────────────────────────────────────────────

	public function version_consistency(): array {
		$main   = $this->files['certificate-generator.php'] ?? '';
		$found  = array(
			'header Version'                => $this->main_header()['Version'] ?? null,
			'readme Stable tag'             => $this->readme_headers()['Stable tag'] ?? null,
			'CERTIFICATE_GENERATOR_VERSION' => preg_match( "/define\(\s*'CERTIFICATE_GENERATOR_VERSION',\s*'([^']+)'/", $main, $m ) ? $m[1] : null,
			'Config::VERSION'               => preg_match( "/const VERSION\s*=\s*'([^']+)'/", $this->files['src/Core/Config.php'] ?? '', $m ) ? $m[1] : null,
			'readme changelog'              => preg_match( '/== Changelog ==\s*= ([0-9.]+) =/', $this->readme(), $m ) ? $m[1] : null,
		);
		$distinct = array_unique( $found );
		return 1 === count( $distinct ) && null !== reset( $distinct ) ? array() : array( 'versions differ: ' . json_encode( $found ) );
	}

	public function version_format(): array {
		$ver = $this->main_header()['Version'] ?? '';
		return preg_match( '/^\d+\.\d+(\.\d+)?$/', $ver ) ? array() : array( "Version '$ver' is not numeric x.y.z" );
	}

	public function main_header_complete(): array {
		$h = $this->main_header();
		$v = array();
		foreach ( array( 'Plugin Name', 'Description', 'Version', 'Requires at least', 'Requires PHP', 'Author', 'License', 'Text Domain' ) as $key ) {
			if ( empty( $h[ $key ] ) ) {
				$v[] = "certificate-generator.php: header '$key' missing";
			}
		}
		return $v;
	}

	/** Every local asset the plugin enqueues or registers is part of the shipped files. */
	public function enqueued_assets_ship(): array {
		$v = array();
		foreach ( $this->own_php() as $file ) {
			preg_match_all( "#(?:plugin_dir_url\(\s*__FILE__\s*\)|CERTIFICATE_GENERATOR_URL)\s*\.\s*'([^']+\.(?:js|css))'#", $this->files[ $file ], $m );
			foreach ( $m[1] as $asset ) {
				if ( ! isset( $this->files[ $asset ] ) ) {
					$v[] = "$file: enqueues $asset, which is not in the shipped files";
				}
			}
		}
		return array_values( array_unique( $v ) );
	}

	// ── G4 ────────────────────────────────────────────────────────────────────

	public function no_obfuscation(): array {
		$v = array();
		foreach ( $this->own_php() as $file ) {
			foreach ( $this->tokens( $file ) as $tok ) {
				if ( is_array( $tok ) && T_EVAL === $tok[0] ) {
					$v[] = "$file:{$tok[2]} eval()";
				}
			}
		}
		foreach ( $this->calls( array( 'create_function' ), $this->own_php() ) as [ $file, $line, $name ] ) {
			$v[] = "$file:$line $name()";
		}
		// Decoding is fine for data (encrypted settings, compressed font files); obfuscation is decoding into code.
		foreach ( $this->own_php() as $file ) {
			foreach ( preg_split( '/\R/', $this->files[ $file ] ) as $n => $line ) {
				if ( preg_match( '/\b(eval|assert|include|require)(_once)?\b[^;]*\b(base64_decode|gzinflate|gzuncompress|str_rot13)\s*\(/i', $line )
					|| preg_match( '/[\'"][A-Za-z0-9+\/=]{200,}[\'"]/', $line ) ) {
					$v[] = "$file:" . ( $n + 1 ) . ' decoded/encoded code';
				}
			}
		}
		return $v;
	}

	public function public_source_link(): array {
		return preg_match( '#https://github\.com/[\w.-]+/[\w.-]+#', $this->readme() ) ? array() : array( 'readme.txt has no public source link' );
	}

	public function minified_files_documented(): array {
		$v = array();
		foreach ( array_keys( $this->files ) as $f ) {
			if ( preg_match( '/\.min\.(js|css)$/', $f ) && ! str_starts_with( $f, 'vendor/' ) ) {
				$dir = dirname( $f );
				if ( ! str_starts_with( $f, 'assets/vendor/' ) || ! preg_grep( '#^' . preg_quote( $dir, '#' ) . '/LICENSE#i', array_keys( $this->files ) ) ) {
					$v[] = "$f: minified file without a bundled license/source";
				}
			}
		}
		return $v;
	}

	// ── G5 / G6 / G7 / G8 / G10 ───────────────────────────────────────────────

	public function no_trialware(): array {
		$v = array();
		$patterns = '/CG_License_Manager|\bis_pro\s*\(|\bis_business\s*\(|[\'"]cg_plan[\'"]|(free|trial) (plan|version) limit|upgrade to (unlock|continue|pro to)|monthly (certificate|email) (limit|quota)|trial (has )?(expired|period)|license (key )?required/i';
		foreach ( $this->own_php() as $file ) {
			foreach ( preg_split( '/\R/', $this->files[ $file ] ) as $n => $line ) {
				if ( preg_match( $patterns, $line, $m ) ) {
					$v[] = "$file:" . ( $n + 1 ) . " '{$m[0]}'";
				}
			}
		}
		return $v;
	}

	public function external_services_documented(): array {
		$section = strstr( $this->readme(), '== External services ==' ) ?: '';
		$section = preg_split( '/\n== /', $section )[0] ?? '';
		$v       = array();
		foreach ( $this->own_php() as $file ) {
			if ( ! preg_match( '/wp_(safe_)?remote_(get|post|request|head)\s*\(/', $this->files[ $file ] ) ) {
				continue;
			}
			preg_match_all( "#['\"](https?://([a-z0-9.-]+)[^'\"]*)['\"]#i", $this->files[ $file ], $m, PREG_SET_ORDER );
			foreach ( $m as $url ) {
				$host = strtolower( $url[2] );
				if ( in_array( $host, array( 'www.w3.org', 'localhost' ), true ) ) {
					continue;
				}
				if ( false === stripos( $section, preg_replace( '/^(www|api)\./', '', $host ) ) ) {
					$v[] = "$file: outbound host $host is not listed under == External services == in readme.txt";
				}
			}
		}
		return array_values( array_unique( $v ) );
	}

	public function no_tracking_code(): array {
		$v = array();
		foreach ( $this->own_php() as $file ) {
			if ( preg_match( '/google-analytics\.com|googletagmanager|mixpanel|segment\.io|telemetry|usage_tracking|track_usage|heartbeat_report/i', $this->files[ $file ], $m ) ) {
				$v[] = "$file: tracking reference '{$m[0]}'";
			}
		}
		return $v;
	}

	public function no_remote_assets(): array {
		$v = array();
		foreach ( $this->calls( array( 'wp_enqueue_script', 'wp_enqueue_style', 'wp_register_script', 'wp_register_style' ), $this->own_php() ) as [ $file, $line, $name, $args, $t ] ) {
			$src = isset( $args[1] ) ? self::literal( $t[ $args[1] ] ) : null;
			if ( null !== $src && preg_match( '#^(https?:)?//#', $src ) ) {
				$v[] = "$file:$line $name loads remote $src";
			}
		}
		foreach ( array_merge( $this->own_php(), $this->own_files_with( '.js' ) ) as $file ) {
			if ( preg_match( '#<(script|link)[^>]+(src|href)=["\']https?://(?!\{)#i', $this->files[ $file ], $m ) ) {
				$v[] = "$file: inline tag loads a remote resource";
			}
		}
		return $v;
	}

	public function no_external_updater(): array {
		$v = array();
		foreach ( $this->own_php() as $file ) {
			if ( preg_match( '/pre_set_site_transient_update_plugins|site_transient_update_plugins|plugins_api|Puc_v\d|PucFactory|plugin-update-checker/', $this->files[ $file ], $m ) ) {
				$v[] = "$file: external updater '{$m[0]}'";
			}
		}
		return $v;
	}

	public function no_frontend_credits(): array {
		$v = array();
		foreach ( $this->own_php() as $file ) {
			if ( preg_match( '/powered\s+by/i', $this->files[ $file ] ) ) {
				$v[] = "$file: 'powered by' credit";
			}
		}
		return $v;
	}

	// ── G11 ───────────────────────────────────────────────────────────────────

	public function admin_notices_dismissible(): array {
		$v      = array();
		$bodies = array();
		foreach ( $this->calls( array( 'add_action' ), $this->own_php() ) as [ $file, $line, , $args, $t ] ) {
			if ( ! in_array( self::literal( $t[ $args[0] ] ), array( 'admin_notices', 'all_admin_notices', 'network_admin_notices' ), true ) || ! isset( $args[1] ) ) {
				continue;
			}
			$cb = $t[ $args[1] ];
			if ( null !== ( $name = self::literal( $cb ) ) ) {
				$bodies[] = array( $file, $line, $this->function_body( $name ) );
			} else { // closure: take tokens up to the matching brace
				$bodies[] = array( $file, $line, $this->block_text( $t, $args[1] ) );
			}
		}
		foreach ( $bodies as [ $file, $line, $body ] ) {
			if ( null === $body ) {
				continue;
			}
			$prints_notice = preg_match( '/class=\\\\?["\'][^"\']*\bnotice\b/', $body ) || preg_match( '/\bcg_ui_notice\s*\(|certificate_generator_ui_notice\s*\(/', $body );
			$ui_calls      = preg_match_all( '/(certificate_generator_ui_notice|certificate_generator_ui_notice)\s*\(/', $body );
			$ui_fixed      = preg_match_all( '/(certificate_generator_ui_notice|certificate_generator_ui_notice)\s*\((?:[^;()]|\([^()]*\))*,\s*false\s*[,)]/', $body );
			$dismissible   = str_contains( $body, 'is-dismissible' ) || str_contains( $body, 'notice-dismiss' ) || ( $ui_calls > 0 && 0 === $ui_fixed );
			$scoped        = preg_match( "/get_current_screen|\\\$_GET\[\s*'page'\s*\]|\\\$pagenow|\\\$hook\b|certificate_generator_is_admin_page|certificate_generator_is_admin_page|'page'\s*\]/", $body );
			if ( $prints_notice && ! $dismissible && ! $scoped ) {
				$v[] = "$file:$line admin notice is neither dismissible nor limited to the plugin's screens";
			}
		}
		return $v;
	}

	private function function_body( string $name ): ?string {
		foreach ( $this->own_php() as $file ) {
			$t = $this->tokens( $file );
			foreach ( $t as $i => $tok ) {
				if ( is_array( $tok ) && T_FUNCTION === $tok[0] ) {
					$n = self::sig( $t, $i );
					if ( null !== $n && is_array( $t[ $n ] ) && strtolower( $t[ $n ][1] ) === strtolower( $name ) ) {
						return $this->block_text( $t, $n );
					}
				}
			}
		}
		return null;
	}

	private function block_text( array $t, int $from ): string {
		$out   = '';
		$depth = 0;
		$seen  = false;
		for ( $j = $from; isset( $t[ $j ] ); $j++ ) {
			$c = is_array( $t[ $j ] ) ? $t[ $j ][1] : $t[ $j ];
			if ( '{' === $c || ( is_array( $t[ $j ] ) && in_array( $t[ $j ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
				++$depth;
				$seen = true;
			} elseif ( '}' === $c ) {
				--$depth;
			}
			$out .= $c;
			if ( $seen && 0 === $depth ) {
				break;
			}
		}
		return $out;
	}

	// ── G12 / G13 / G17 ───────────────────────────────────────────────────────

	public function readme_valid(): array {
		$h = $this->readme_headers();
		$v = array();
		foreach ( array( 'Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI' ) as $key ) {
			if ( empty( $h[ $key ] ) ) {
				$v[] = "readme.txt: header '$key' missing";
			}
		}
		if ( count( array_filter( array_map( 'trim', explode( ',', $h['Tags'] ?? '' ) ) ) ) > 5 ) {
			$v[] = 'readme.txt: more than 5 tags';
		}
		$after = preg_split( '/\R\R/', trim( strstr( $this->readme(), 'License URI' ) ?: '' ) );
		$short = trim( $after[1] ?? '' );
		if ( '' === $short || mb_strlen( $short ) > 150 ) {
			$v[] = 'readme.txt: short description missing or longer than 150 characters (' . mb_strlen( $short ) . ')';
		}
		$readme_name = preg_match( '/^=== (.+) ===/m', $this->readme(), $m ) ? $m[1] : '';
		if ( $readme_name !== ( $this->main_header()['Plugin Name'] ?? '' ) ) {
			$v[] = "readme.txt: plugin name '$readme_name' differs from the header";
		}
		return $v;
	}

	public function no_bundled_core_libraries(): array {
		$v = array();
		foreach ( array_keys( $this->files ) as $f ) {
			$base = strtolower( basename( $f ) );
			if ( preg_match( '/^(jquery([.-](ui|migrate))?(-[\d.]+)?(\.min)?\.js|backbone(\.min)?\.js|underscore(\.min)?\.js|moment(\.min)?\.js|lodash(\.min)?\.js|react(-dom)?(\.production)?(\.min)?\.js|class-phpmailer\.php|phpmailer\.php|class-simplepie\.php|simplepie\.php|masonry(\.pkgd)?(\.min)?\.js|imagesloaded(\.pkgd)?(\.min)?\.js)$/', $base ) ) {
				$v[] = "$f: bundles a library WordPress already ships";
			}
		}
		return $v;
	}

	public function slug_not_trademark(): array {
		$slug = strtolower( $this->main_header()['Text Domain'] ?? '' );
		foreach ( array( 'wordpress', 'woocommerce', 'woo', 'google', 'facebook', 'instagram', 'elementor', 'gutenberg', 'jetpack', 'yoast', 'paypal', 'stripe', 'amazon', 'apple', 'microsoft', 'whatsapp', 'twitter', 'youtube', 'tutor', 'learndash', 'canva' ) as $tm ) {
			if ( str_starts_with( $slug, $tm ) ) {
				return array( "slug '$slug' starts with trademark '$tm'" );
			}
		}
		return array();
	}

	// ── R1: prefixes ──────────────────────────────────────────────────────────

	public function prefixed_globals(): array {
		$p = $this->config['prefix'];
		$v = array();
		foreach ( $this->own_php() as $file ) {
			$t          = $this->tokens( $file );
			$namespaced = false;
			$depth      = 0;
			$class_d    = array();
			foreach ( $t as $i => $tok ) {
				$c = is_array( $tok ) ? $tok[1] : $tok;
				if ( is_array( $tok ) && T_NAMESPACE === $tok[0] ) {
					$namespaced = true;
				}
				if ( '{' === $c || ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $c ) {
					--$depth;
					if ( $class_d && end( $class_d ) === $depth ) {
						array_pop( $class_d );
					}
				}
				if ( ! is_array( $tok ) || $namespaced ) {
					continue;
				}
				if ( in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
					$prev = self::sig( $t, $i, -1 );
					$n    = self::sig( $t, $i );
					if ( ( null !== $prev && is_array( $t[ $prev ] ) && T_DOUBLE_COLON === $t[ $prev ][0] ) || null === $n || ! is_array( $t[ $n ] ) || T_STRING !== $t[ $n ][0] ) {
						continue; // Foo::class or anonymous class
					}
					$class_d[] = $depth;
					if ( ! str_starts_with( $t[ $n ][1], $p['classes'] ) ) {
						$v[] = "$file:{$tok[2]} class {$t[$n][1]}";
					}
				} elseif ( T_FUNCTION === $tok[0] && ! $class_d ) {
					$n = self::sig( $t, $i );
					if ( null !== $n && is_array( $t[ $n ] ) && T_STRING === $t[ $n ][0] && ! str_starts_with( $t[ $n ][1], $p['functions'] ) ) {
						$v[] = "$file:{$tok[2]} function {$t[$n][1]}()";
					}
				}
			}
		}
		$v = array_merge( $v, $this->global_variables() );
		foreach ( $this->calls( array( 'define' ), $this->own_php() ) as [ $file, $line, , $args, $t ] ) {
			$name = self::literal( $t[ $args[0] ] );
			if ( null !== $name && ! str_starts_with( $name, $p['constants'] ) && ! in_array( $name, array( 'FPDF_FONTPATH', '_SYSTEM_TTFONTS' ), true ) ) {
				$v[] = "$file:$line constant $name";
			}
		}
		return $v;
	}

	/** Variables assigned at global scope (outside any function or class body) in our own files. */
	private function global_variables(): array {
		$prefix = '$' . $this->config['prefix']['functions'];
		$skip   = array( '$this', '$GLOBALS', '$_GET', '$_POST', '$_REQUEST', '$_SERVER', '$_FILES', '$_COOKIE', '$_SESSION', '$_ENV' );
		$v      = array();
		foreach ( $this->own_php() as $file ) {
			$t       = $this->tokens( $file );
			$depth   = 0;
			$scopes  = array(); // body depths of functions/closures/classes we are inside
			$pending = false;
			$in_as   = false;
			foreach ( $t as $i => $tok ) {
				$c = is_array( $tok ) ? $tok[1] : $tok;
				if ( is_array( $tok ) && in_array( $tok[0], array( T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT ), true ) ) {
					$pending = true;
				} elseif ( '{' === $c || ( is_array( $tok ) && in_array( $tok[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
					if ( $pending ) {
						$scopes[] = $depth;
						$pending  = false;
					}
				} elseif ( '}' === $c ) {
					if ( $scopes && end( $scopes ) === $depth ) {
						array_pop( $scopes );
					}
					--$depth;
				} elseif ( ';' === $c ) {
					$pending = false; // abstract method / interface signature
				}
				if ( is_array( $tok ) && T_AS === $tok[0] ) {
					$in_as = true;
				} elseif ( ')' === $c ) {
					$in_as = false;
				}
				if ( $scopes || $pending || ! is_array( $tok ) || T_VARIABLE !== $tok[0] || in_array( $tok[1], $skip, true ) || str_starts_with( $tok[1], $prefix ) ) {
					continue;
				}
				$next = self::sig( $t, $i );
				if ( $in_as || ( null !== $next && '=' === $t[ $next ] ) ) {
					$v[] = "$file:{$tok[2]} global variable {$tok[1]}";
				}
			}
		}
		return array_values( array_unique( $v ) );
	}

	public function prefixed_names(): array {
		$p   = $this->config['prefix']['names'];
		$v   = array();
		$own = $this->own_php();
		// [ function names, argument index, label, only flag names that look like ours? ]
		$rules = array(
			array( array( 'update_option', 'add_option', 'delete_option', 'register_setting' ), 0, 'option' ),
			array( array( 'set_transient', 'delete_transient', 'set_site_transient', 'delete_site_transient' ), 0, 'transient' ),
			array( array( 'do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array' ), 0, 'hook' ),
			array( array( 'add_shortcode' ), 0, 'shortcode' ),
			array( array( 'wp_schedule_event' ), 2, 'cron hook' ),
			array( array( 'wp_schedule_single_event' ), 1, 'cron hook' ),
		);
		foreach ( $rules as [ $fns, $idx, $label ] ) {
			foreach ( $this->calls( $fns, $own ) as [ $file, $line, $fn, $args, $t ] ) {
				$arg = 'register_setting' === $fn ? 1 : $idx;
				if ( ! isset( $args[ $arg ] ) ) {
					continue;
				}
				$name = self::leading_string( $t, $args[ $arg ] );
				if ( null !== $name && ! str_starts_with( $name, $p ) ) {
					$v[] = "$file:$line $label '$name'";
				}
			}
		}
		// Reads of our own (cg_/cert_) options and transients must be renamed too.
		foreach ( $this->calls( array( 'get_option', 'get_transient', 'get_site_transient' ), $own ) as [ $file, $line, $fn, $args, $t ] ) {
			$name = self::leading_string( $t, $args[0] );
			$src  = preg_split( '/\R/', $this->files[ $file ] )[ $line - 1 ] ?? '';
			if ( str_contains( $src, 'legacy-name:' ) ) {
				continue; // deliberate read of a pre-rename name during migration
			}
			if ( null !== $name && preg_match( '/^(cg_|cert_|certificate_(?!generator_))/', $name ) ) {
				$v[] = "$file:$line $fn '$name'";
			}
		}
		// AJAX actions and admin-post actions.
		foreach ( $this->calls( array( 'add_action' ), $own ) as [ $file, $line, , $args, $t ] ) {
			$hook = self::leading_string( $t, $args[0] );
			if ( null !== $hook && preg_match( '/^(wp_ajax_nopriv_|wp_ajax_|admin_post_nopriv_|admin_post_)(.+)$/', $hook, $m ) && ! str_starts_with( $m[2], $p ) ) {
				$v[] = "$file:$line action '{$m[2]}' ($m[1])";
			}
		}
		return $v;
	}

	// ── R2–R5 ─────────────────────────────────────────────────────────────────

	public function abspath_guard(): array {
		$v = array();
		foreach ( array_filter( array_keys( $this->files ), static fn( $f ) => str_ends_with( $f, '.php' ) && ! str_starts_with( $f, 'vendor/' ) ) as $file ) {
			if ( str_contains( $this->files[ $file ], 'ABSPATH' ) || ! $this->has_top_level_code( $file ) ) {
				continue;
			}
			$v[] = "$file: executable code without an ABSPATH check";
		}
		return $v;
	}

	private function has_top_level_code( string $file ): bool {
		$t    = $this->tokens( $file );
		$skip = array( T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML );
		for ( $i = 0; isset( $t[ $i ] ); $i++ ) {
			$tok = $t[ $i ];
			if ( is_array( $tok ) && in_array( $tok[0], $skip, true ) ) {
				continue;
			}
			if ( is_array( $tok ) && in_array( $tok[0], array( T_NAMESPACE, T_USE, T_DECLARE ), true ) ) {
				while ( isset( $t[ $i ] ) && ';' !== $t[ $i ] ) {
					++$i;
				}
				continue;
			}
			if ( is_array( $tok ) && in_array( $tok[0], array( T_CLASS, T_INTERFACE, T_TRAIT, T_ABSTRACT, T_FINAL, T_FUNCTION, T_READONLY ), true ) ) {
				$depth = 0;
				$seen  = false;
				for ( ; isset( $t[ $i ] ); $i++ ) {
					$c = is_array( $t[ $i ] ) ? $t[ $i ][1] : $t[ $i ];
					if ( '{' === $c || ( is_array( $t[ $i ] ) && in_array( $t[ $i ][0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
						++$depth;
						$seen = true;
					} elseif ( '}' === $c && 0 === --$depth && $seen ) {
						break;
					}
				}
				continue;
			}
			return true;
		}
		return false;
	}

	public function text_domain(): array {
		$domain = $this->config['prefix']['text_domain'];
		$v      = array();
		foreach ( $this->calls( array_keys( self::GETTEXT ), $this->own_php() ) as [ $file, $line, $fn, $args, $t ] ) {
			$idx = self::GETTEXT[ $fn ];
			$got = isset( $args[ $idx ] ) ? self::literal( $t[ $args[ $idx ] ] ) : null;
			if ( $got !== $domain ) {
				$v[] = "$file:$line $fn() text domain " . ( null === $got ? 'missing/not literal' : "'$got'" );
			}
		}
		return $v;
	}

	public function no_core_bootstrap_includes(): array {
		$v = array();
		foreach ( $this->own_php() as $file ) {
			$t = $this->tokens( $file );
			foreach ( $t as $i => $tok ) {
				if ( ! is_array( $tok ) || ! in_array( $tok[0], array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE ), true ) ) {
					continue;
				}
				$expr = '';
				for ( $j = $i + 1; isset( $t[ $j ] ) && ';' !== $t[ $j ]; $j++ ) {
					$expr .= is_array( $t[ $j ] ) ? $t[ $j ][1] : $t[ $j ];
				}
				if ( preg_match( '/wp-(load|config)\.php/', $expr ) ) {
					$v[] = "$file:{$tok[2]} includes" . trim( $expr );
				}
			}
		}
		return $v;
	}

	public function no_debug_output(): array {
		$v = array();
		foreach ( $this->calls( array( 'error_log', 'var_dump', 'print_r', 'debug_print_backtrace' ), $this->own_php() ) as [ $file, $line, $fn, $args, $t ] ) {
			if ( 'error_log' === $fn && str_ends_with( $file, 'includes/Core/debug-log.php' ) ) {
				continue;
			}
			if ( 'print_r' === $fn && isset( $args[1] ) && is_array( $t[ $args[1] ] ) && 'true' === strtolower( $t[ $args[1] ][1] ) ) {
				continue; // returns a string, prints nothing
			}
			$v[] = "$file:$line $fn()";
		}
		return $v;
	}
}
