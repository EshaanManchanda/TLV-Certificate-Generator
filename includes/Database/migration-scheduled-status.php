<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Idempotent migration: add 'scheduled' to wp_cg_certificate_templates.status ENUM.
// Runs once on plugins_loaded; skipped if the value already exists.
add_action( 'plugins_loaded', 'cg_migrate_scheduled_status', 20 );

function cg_migrate_scheduled_status() {
	if ( get_option( 'cg_migration_scheduled_status_done' ) ) {
		return;
	}

	if ( ! class_exists( '\CertificateGenerator\Database\CustomTables' ) ) {
		return;
	}

	$tables    = \CertificateGenerator\Database\CustomTables::instance();
	$tpl_table = $tables->get_table( 'certificate_templates' );

	$col = $GLOBALS['wpdb']->get_row(
		$GLOBALS['wpdb']->prepare( 'SHOW COLUMNS FROM ' . $tpl_table . ' LIKE %s', 'status' )
	);

	if ( ! $col ) {
		return;
	}

	// Already has 'scheduled' in the ENUM — mark done and exit.
	if ( strpos( $col->Type, "'scheduled'" ) !== false ) {
		update_option( 'cg_migration_scheduled_status_done', true );
		return;
	}

	$GLOBALS['wpdb']->query(
		"ALTER TABLE $tpl_table
		 MODIFY status ENUM('draft','scheduled','published','archived') DEFAULT 'draft'"
	);

	update_option( 'cg_migration_scheduled_status_done', true );
}
