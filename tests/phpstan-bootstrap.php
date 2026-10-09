<?php
/**
 * PHPStan-only bootstrap: the plugin's path constants, which certificate-generator.php
 * defines at runtime via plugin_dir_path()/plugin_dir_url().
 */

define( 'CERTIFICATE_GENERATOR_VERSION', '7.6.0' );
define( 'CERTIFICATE_GENERATOR_PATH', __DIR__ . '/../' );
define( 'CERTIFICATE_GENERATOR_URL', 'https://example.com/wp-content/plugins/certificate-generator/' );
