<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Developer-only dashboard that runs vendor/bin/phpunit and summarizes the
 * result grouped by tests/feature-registry.php. Never exposed unless
 * CG_TESTING_UI is explicitly defined true (e.g. in a local wp-config.php) —
 * this shells out to a CLI tool and has no place in a shipped build.
 */
class TestCenterPage {

	private string $slug = 'cg-test-center';

	public static function is_enabled(): bool {
		return defined( 'CG_TESTING_UI' ) && CG_TESTING_UI === true;
	}

	public function register(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		\add_submenu_page(
			'cg-dashboard',
			'Test Center',
			'🧪 Test Center',
			'manage_options',
			$this->slug,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! self::is_enabled() || ! \current_user_can( 'manage_options' ) ) {
			\wp_die( 'Insufficient permissions' );
		}

		$plugin_dir = dirname( __DIR__, 3 );
		$phpunit    = $plugin_dir . '/vendor/bin/phpunit';
		$results    = null;
		$run_error  = '';

		// ── AI-assisted test case suggestions (optional, local-only) ──────────
		$ollama_suggestions = '';
		$ollama_error       = '';
		$ollama_file        = '';
		$ollama_model       = 'llama3.1';
		$ollama_url         = 'http://localhost:11434';

		if ( isset( $_POST['cg_ollama_suggest'] ) && \check_admin_referer( 'cg_ollama_suggest' ) ) {
			$ollama_file  = \sanitize_text_field( \wp_unslash( $_POST['ollama_file'] ?? '' ) );
			$ollama_model = \sanitize_text_field( \wp_unslash( $_POST['ollama_model'] ?? '' ) ) ?: $ollama_model;
			$ollama_url   = \untrailingslashit( \sanitize_text_field( \wp_unslash( $_POST['ollama_url'] ?? '' ) ) ) ?: $ollama_url;

			[ $ollama_suggestions, $ollama_error ] = $this->generate_test_suggestions( $plugin_dir, $ollama_file, $ollama_model, $ollama_url );
		}

		if ( isset( $_POST['cg_run_tests'] ) && \check_admin_referer( 'cg_run_tests' ) ) {
			if ( ! file_exists( $phpunit ) ) {
				$run_error = 'vendor/bin/phpunit not found — run composer install first.';
			} elseif ( ! function_exists( 'proc_open' ) ) {
				$run_error = 'proc_open() is disabled on this server — the Test Center cannot run tests here. Use the command line instead.';
			} else {
				$main_run = $this->run_suite( $plugin_dir, $phpunit );
				$unit_run = file_exists( $plugin_dir . '/phpunit.unit.xml.dist' )
					? $this->run_suite( $plugin_dir, $phpunit, '-c', 'phpunit.unit.xml.dist' )
					: array(
						'results' => array(),
						'stdout'  => '',
						'stderr'  => '',
					);

				if ( $main_run['results'] === null || empty( $main_run['results'] ) ) {
					$run_error = 'The test run produced no parseable results — the PHP CLI binary used to shell out is likely wrong for this server (see CG_TESTING_PHP_BINARY below). Raw output:';
					$run_error .= "\n\n" . trim( $main_run['stdout'] . "\n" . $main_run['stderr'] );
				} else {
					$results = array_merge( $main_run['results'], $unit_run['results'] ?? array() );
				}
			}
		}

		echo '<div class="wrap">';
		\cg_ui_page_header( 'Test Center', 'Runs vendor/bin/phpunit against the live codebase and groups results by feature. Dev/staging only.' );

		if ( $run_error ) {
			\cg_ui_notice( 'error', nl2br( \esc_html( $run_error ) ), false );
		}

		\cg_ui_card_open( 'PHPUnit', array( 'icon' => 'yes-alt' ) );
		echo '<form method="post" data-cg-busy>';
		\wp_nonce_field( 'cg_run_tests' );
		echo '<button type="submit" name="cg_run_tests" value="1" class="button button-primary">Run Tests</button>';
		echo '<p class="cg-hint">The page reloads with the results when the run finishes.</p>';
		echo '</form>';

		if ( $results !== null ) {
			$this->render_results( $results );
		}
		\cg_ui_card_close();

		\cg_ui_card_open( 'AI-Assisted Test Case Suggestions (Ollama, optional)', array( 'icon' => 'lightbulb' ) );
		echo '<p>Sends one file\'s contents to a locally-running <a href="https://ollama.com" target="_blank" rel="noopener">Ollama</a> model and asks it to suggest additional PHPUnit test cases in this project\'s [WB]/[BB] style. Nothing is written automatically — review and add anything useful by hand.</p>';
		echo '<form method="post" data-cg-busy>';
		\wp_nonce_field( 'cg_ollama_suggest' );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ollama_file">File to analyze</label></th><td><input type="text" id="ollama_file" name="ollama_file" class="regular-text" placeholder="src/Admin/Pages/SchoolsPage.php" value="' . \esc_attr( $ollama_file ) . '"></td></tr>';
		echo '<tr><th><label for="ollama_model">Ollama model</label></th><td><input type="text" id="ollama_model" name="ollama_model" value="' . \esc_attr( $ollama_model ) . '"><p class="description">Must already be pulled locally (<code>ollama pull ' . \esc_html( $ollama_model ) . '</code>).</p></td></tr>';
		echo '<tr><th><label for="ollama_url">Ollama URL</label></th><td><input type="text" id="ollama_url" name="ollama_url" class="regular-text" value="' . \esc_attr( $ollama_url ) . '"></td></tr>';
		echo '</tbody></table>';
		echo '<button type="submit" name="cg_ollama_suggest" value="1" class="button button-secondary">Suggest Test Cases</button>';
		echo '</form>';

		if ( $ollama_error ) {
			\cg_ui_notice( 'error', \esc_html( $ollama_error ), false, true );
		}
		if ( $ollama_suggestions ) {
			echo '<pre class="cg-pre">' . \esc_html( $ollama_suggestions ) . '</pre>';
		}
		\cg_ui_card_close();

		echo '</div>';
	}

	/**
	 * Asks a local Ollama model for PHPUnit test-case ideas for one file, via
	 * Ollama's REST API (wp_remote_post — no new HTTP client dependency).
	 * Purely a suggestion tool: never writes files, never runs generated code.
	 *
	 * @return array{0: string, 1: string} [suggestions, error] — exactly one is non-empty.
	 */
	private function generate_test_suggestions( string $plugin_dir, string $relative_file, string $model, string $url ): array {
		if ( $relative_file === '' ) {
			return array( '', 'Enter a file path first.' );
		}

		// Path-traversal guard: the resolved path must stay inside the plugin directory,
		// even though this page is already gated behind CG_TESTING_UI + manage_options.
		// A bare strpos() prefix check would also accept a sibling directory whose name
		// happens to start with the same string (e.g. "…-V7.5-backup") — require the
		// plugin dir boundary itself, not just a string prefix.
		$real_plugin_dir = realpath( $plugin_dir );
		$real_target     = realpath( $plugin_dir . '/' . ltrim( $relative_file, '/\\' ) );
		$inside_plugin   = $real_target && $real_plugin_dir && (
			$real_target === $real_plugin_dir
			|| strpos( $real_target, $real_plugin_dir . DIRECTORY_SEPARATOR ) === 0
		);
		if ( ! $inside_plugin ) {
			return array( '', 'File not found (or outside the plugin directory): ' . $relative_file );
		}

		$code = file_get_contents( $real_target );
		if ( $code === false ) {
			return array( '', 'Could not read file: ' . $relative_file );
		}
		// Keep the prompt a sane size for a local model — first ~12k chars covers
		// any of this plugin's admin-page classes with room for the instructions.
		$code = substr( $code, 0, 12000 );

		$prompt = "You are suggesting PHPUnit test cases for a WordPress plugin. "
			. "Tests in this codebase extend WP_UnitTestCase, tag each assertion inline as "
			. "[WB] (white-box: asserts on DB rows/internal state) or [BB] (black-box: asserts "
			. "only on rendered HTML output or a JSON response), and call page-class methods "
			. "(e.g. render_edit(), render_list()) directly rather than over HTTP. "
			. "List concrete additional test case ideas (name + one-line scenario + expected "
			. "result) for edge cases NOT obviously already covered — validation boundaries, "
			. "empty/duplicate input, permission checks, filter/sort interactions. Do not repeat "
			. "generic advice; be specific to this file.\n\nFile: {$relative_file}\n\n```php\n{$code}\n```";

		$response = \wp_remote_post(
			\untrailingslashit( $url ) . '/api/generate',
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => \wp_json_encode(
					array(
						'model'  => $model,
						'prompt' => $prompt,
						'stream' => false,
					)
				),
			)
		);

		if ( \is_wp_error( $response ) ) {
			return array( '', 'Could not reach Ollama at ' . $url . ' — is it running? (' . $response->get_error_message() . ')' );
		}

		$code_status = \wp_remote_retrieve_response_code( $response );
		$body        = json_decode( \wp_remote_retrieve_body( $response ), true );

		if ( $code_status !== 200 || ! is_array( $body ) ) {
			$raw = \wp_remote_retrieve_body( $response );
			return array( '', "Ollama returned HTTP {$code_status}: " . substr( (string) $raw, 0, 500 ) );
		}

		if ( ! isset( $body['response'] ) || $body['response'] === '' ) {
			return array( '', 'Ollama returned no suggestions — check the model name is pulled (`ollama pull ' . $model . '`).' );
		}

		return array( (string) $body['response'], '' );
	}

	/**
	 * ponytail: PHP_BINARY inside a web request is often unusable for CLI work —
	 * under php-fpm it's the fpm binary itself, and even a real CLI php.exe may
	 * load a different php.ini (missing extensions PHPUnit needs, e.g. mbstring).
	 * Define CG_TESTING_PHP_BINARY (and optionally CG_TESTING_PHP_INI) with an
	 * explicit CLI php + ini path if runs come back with 0/0 everywhere.
	 */
	private function run_suite( string $cwd, string $phpunit, string ...$extra_args ): array {
		$php_binary = defined( 'CG_TESTING_PHP_BINARY' ) ? CG_TESTING_PHP_BINARY : PHP_BINARY;
		$junit_path = \wp_tempnam( 'cg-test-results.xml' );

		$command = array( $php_binary );
		if ( defined( 'CG_TESTING_PHP_INI' ) ) {
			$command[] = '-c';
			$command[] = CG_TESTING_PHP_INI;
		}
		$command[] = $phpunit;
		foreach ( $extra_args as $arg ) {
			$command[] = $arg;
		}
		$command[] = '--log-junit';
		$command[] = $junit_path;

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( $command, $descriptors, $pipes, $cwd );
		if ( ! is_resource( $process ) ) {
			return array(
				'results' => null,
				'stdout'  => '',
				'stderr'  => 'proc_open() returned no process resource.',
			);
		}

		fclose( $pipes[0] );
		$stdout = stream_get_contents( $pipes[1] );
		$stderr = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );

		if ( ! file_exists( $junit_path ) ) {
			return array(
				'results' => null,
				'stdout'  => (string) $stdout,
				'stderr'  => (string) $stderr,
			);
		}

		$parsed = $this->parse_junit( $junit_path );
		\wp_delete_file( $junit_path );

		return array(
			'results' => $parsed,
			'stdout'  => (string) $stdout,
			'stderr'  => (string) $stderr,
		);
	}

	private function parse_junit( string $junit_path ): array {
		$xml      = simplexml_load_file( $junit_path );
		$by_class = array();

		if ( $xml !== false ) {
			foreach ( $xml->xpath( '//testcase' ) as $case ) {
				$class = (string) $case['class'];
				if ( $class === '' ) {
					continue;
				}
				if ( ! isset( $by_class[ $class ] ) ) {
					$by_class[ $class ] = array(
						'pass' => 0,
						'fail' => 0,
					);
				}
				if ( isset( $case->failure ) || isset( $case->error ) ) {
					++$by_class[ $class ]['fail'];
				} else {
					++$by_class[ $class ]['pass'];
				}
			}
		}

		return $by_class;
	}

	private function render_results( array $by_class ): void {
		$registry_file = dirname( __DIR__, 3 ) . '/tests/feature-registry.php';
		$registry      = file_exists( $registry_file ) ? require $registry_file : array();

		$total_pass = 0;
		$total_fail = 0;
		foreach ( $by_class as $counts ) {
			$total_pass += $counts['pass'];
			$total_fail += $counts['fail'];
		}

		echo '<div class="cg-stats">';
		\cg_ui_stat( 'Passed', $total_pass, '', 'good' );
		\cg_ui_stat( 'Failed', $total_fail, '', $total_fail ? 'bad' : '' );
		echo '</div>';

		echo '<div class="cg-table-wrap"><table class="widefat striped cg-table"><thead><tr><th>Feature</th><th>Test Classes</th><th>Result</th></tr></thead><tbody>';

		$covered = array();
		foreach ( $registry as $feature => $classes ) {
			$pass = 0;
			$fail = 0;
			foreach ( $classes as $class ) {
				$covered[] = $class;
				if ( isset( $by_class[ $class ] ) ) {
					$pass += $by_class[ $class ]['pass'];
					$fail += $by_class[ $class ]['fail'];
				}
			}
			echo '<tr><td>' . \esc_html( $feature ) . '</td><td>' . \esc_html( implode( ', ', $classes ) ) . '</td>';
			echo '<td>' . \wp_kses_post( \cg_ui_badge( intval( $pass ) . ' / ' . intval( $pass + $fail ), $fail === 0 ? ( $pass ? 'good' : 'muted' ) : 'bad' ) ) . '</td></tr>';
		}

		$uncovered = array_diff( array_keys( $by_class ), $covered );
		if ( ! empty( $uncovered ) ) {
			echo '<tr><td>(Ungrouped)</td><td>' . \esc_html( implode( ', ', $uncovered ) ) . '</td><td>—</td></tr>';
		}

		echo '</tbody></table></div>';
	}
}
