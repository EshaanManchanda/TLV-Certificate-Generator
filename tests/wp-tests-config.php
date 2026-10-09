<?php
/**
 * WP test-suite DB config. Points at the dedicated `wordpress_test` database
 * on the same Local by Flywheel MySQL instance the site itself uses, and at
 * the site's real WordPress core (ABSPATH) so tests run against the actual
 * WP version this plugin targets.
 */

define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'root' );
define( 'DB_PASSWORD', 'root' );
define( 'DB_HOST', '127.0.0.1:10006' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'gema-ecosystem.local' );
define( 'WP_TESTS_EMAIL', 'admin@gema-ecosystem.local' );
define( 'WP_TESTS_TITLE', 'Certificate Generator Test Suite' );

// Override with the WP_PHP_BINARY env var when Local's run-id changes or the site is stopped.
define(
	'WP_PHP_BINARY',
	getenv( 'WP_PHP_BINARY' ) ?: '"C:/Users/eshaa/AppData/Roaming/Local/lightning-services/php-8.2.29+0/bin/win64/php.exe" -c "C:/Users/eshaa/AppData/Roaming/Local/run/6QexFw-dH/conf/php/php.ini"'
);
define( 'WPLANG', '' );

define( 'ABSPATH', 'C:/Users/eshaa/Local Sites/gema-ecosystem/app/public/' );
