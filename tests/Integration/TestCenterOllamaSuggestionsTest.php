<?php
/**
 * Coverage for TestCenterPage::generate_test_suggestions() — the Ollama-backed
 * "suggest test cases" helper on the (CG_TESTING_UI-gated) Test Center page.
 * Invoked via reflection since the method is private and the page's own render()
 * guard (self::is_enabled()) is deliberately not testable without CG_TESTING_UI
 * defined — this tests the helper's logic in isolation, network mocked via the
 * pre_http_request filter (no real Ollama instance required to run this suite).
 */
class TestCenterOllamaSuggestionsTest extends WP_UnitTestCase {

	private function call( string $plugin_dir, string $relative_file, string $model = 'llama3.1', string $url = 'http://localhost:11434' ): array {
		$page   = new \CertificateGenerator\Admin\Pages\TestCenterPage();
		$method = new ReflectionMethod( $page, 'generate_test_suggestions' );
		$method->setAccessible( true );
		return $method->invoke( $page, $plugin_dir, $relative_file, $model, $url );
	}

	private function plugin_dir(): string {
		return dirname( __DIR__, 2 );
	}

	/** [BB] an empty file path is rejected before any filesystem or network access. */
	public function test_empty_file_path_returns_error(): void {
		[ $suggestions, $error ] = $this->call( $this->plugin_dir(), '' );

		$this->assertSame( '', $suggestions );
		$this->assertStringContainsString( 'Enter a file path first', $error );
	}

	/**
	 * [WB]+[BB] security boundary: a path that resolves outside the plugin directory
	 * (path traversal) must be rejected, never read.
	 */
	public function test_path_traversal_outside_plugin_dir_is_rejected(): void {
		[ $suggestions, $error ] = $this->call( $this->plugin_dir(), '../../../../../../etc/passwd' );

		$this->assertSame( '', $suggestions );
		$this->assertStringContainsString( 'outside the plugin directory', $error );
	}

	/** [BB] a nonexistent (but in-bounds) file path is rejected with a clear message. */
	public function test_nonexistent_file_returns_error(): void {
		[ $suggestions, $error ] = $this->call( $this->plugin_dir(), 'this-file-does-not-exist.php' );

		$this->assertSame( '', $suggestions );
		$this->assertStringContainsString( 'File not found', $error );
	}

	/** [BB] Ollama being unreachable (connection refused) surfaces a clear error, not a fatal. */
	public function test_unreachable_ollama_returns_clear_error(): void {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
		);

		[ $suggestions, $error ] = $this->call( $this->plugin_dir(), 'tests/feature-registry.php' );

		$this->assertSame( '', $suggestions );
		$this->assertStringContainsString( 'Could not reach Ollama', $error );
	}

	/** [WB]+[BB] a successful Ollama response is passed through as the suggestions text. */
	public function test_successful_response_returns_suggestions_text(): void {
		$captured_body = null;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$captured_body ) {
				if ( strpos( $url, '/api/generate' ) === false ) {
					return $preempt;
				}
				$captured_body = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'response' => 'Suggested test: verify X does Y.' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		[ $suggestions, $error ] = $this->call( $this->plugin_dir(), 'tests/feature-registry.php', 'my-model' );

		$this->assertSame( '', $error ); // [BB]
		$this->assertStringContainsString( 'Suggested test: verify X does Y.', $suggestions ); // [BB]

		$this->assertNotNull( $captured_body ); // [WB]
		$this->assertSame( 'my-model', $captured_body['model'] ); // [WB] the chosen model is actually sent
		$this->assertStringContainsString( 'feature-registry.php', $captured_body['prompt'] ); // [WB]
	}

	/** [BB] an empty "response" field from Ollama (e.g. unpulled model) is reported, not silently blank. */
	public function test_empty_ollama_response_returns_error(): void {
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				if ( strpos( $url, '/api/generate' ) === false ) {
					return $preempt;
				}
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'response' => '' ) ),
					'headers'  => array(),
					'cookies'  => array(),
				);
			},
			10,
			3
		);

		[ $suggestions, $error ] = $this->call( $this->plugin_dir(), 'tests/feature-registry.php' );

		$this->assertSame( '', $suggestions );
		$this->assertStringContainsString( 'no suggestions', $error );
	}
}
