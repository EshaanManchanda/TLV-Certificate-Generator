<?php
declare(strict_types=1);

namespace CertificateGenerator\Admin\Pages;

use CertificateGenerator\Database\CustomTables;
use CertificateGenerator\Database\CptToSqlMigration;
use CertificateGenerator\Database\Migrations\MigrationRunner;

/**
 * Complete CPT → SQL Migration admin page.
 * One-click migration with verification and rollback options.
 */
class MigrationPage {

	private string $slug  = 'cg-sql-migration';
	private string $title = 'CPT → SQL Migration';

	public function register(): void {
		add_submenu_page(
			'cg-dashboard',
			$this->title,
			'SQL Migration',
			'manage_options',
			$this->slug,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions', 'certificate-generator' ) );
		}

		$tables       = CustomTables::instance();
		$message      = '';
		$message_type = '';

		// Handle "Fix Database Now" — runs both the versioned column migrations
		// (MigrationRunner) and the dbDelta-based table/column sync (CustomTables).
		// One button for sites running an older copy of this plugin whose DB schema
		// is behind (e.g. missing a column added in a later release).
		if ( isset( $_POST['cg_run_db_fix'] ) && check_admin_referer( 'cg_run_db_fix' ) ) {
			$runner       = new MigrationRunner();
			$before       = $runner->get_current_version();
			$runner->run();
			$tables->create_all();
			$after        = $runner->get_current_version();
			$message      = "Database check complete. Migrations: {$before} → {$after}. Tables verified/created.";
			$message_type = 'success';
		}

		// Handle CPT → SQL migration
		if ( isset( $_POST['cg_run_cpt_to_sql'] ) && check_admin_referer( 'cg_run_cpt_to_sql' ) ) {
			$migrator     = new CptToSqlMigration();
			$stats        = $migrator->run();
			$message      = 'CPT → SQL migration completed!';
			$message_type = 'success';
			foreach ( $stats as $entity => $count ) {
				$message .= '<br>' . ucfirst( $entity ) . ': ' . number_format( $count ) . ' records migrated';
			}
		}

		// Handle rollback
		if ( isset( $_POST['cg_rollback_migration'] ) && check_admin_referer( 'cg_rollback_migration' ) ) {
			$this->rollback_migration( $tables );
			$message      = 'Rollback complete. SQL tables cleared. CPT data is intact. You can re-run migration at any time.';
			$message_type = 'warning';
		}

		// Handle table verification
		if ( isset( $_POST['cg_verify_tables'] ) && check_admin_referer( 'cg_verify_tables' ) ) {
			$tables->create_all();
			$message      = 'All tables verified/created successfully!';
			$message_type = 'success';
		}

		// Handle issue_date normalization (one-time fix for d-m-Y → Y-m-d)
		if ( isset( $_POST['cg_fix_issue_dates'] ) && check_admin_referer( 'cg_fix_issue_dates' ) ) {
			$fixed        = $this->fix_issue_dates();
			$message      = "Issue date normalization complete. <strong>{$fixed}</strong> records updated to Y-m-d storage format.";
			$message_type = $fixed > 0 ? 'success' : 'info';
		}

		// Handle CPT cleanup (after verification)
		if ( isset( $_POST['cg_cleanup_cpts'] ) && check_admin_referer( 'cg_cleanup_cpts' ) ) {
			$this->cleanup_cpts();
			$message      = 'CPT data cleaned up. Plugin now uses SQL tables exclusively.';
			$message_type = 'success';
		}

		// Get status
		$table_status = array();
		foreach ( array_keys( $tables->get_all_tables() ) as $name ) {
			$table_status[ $name ] = $tables->table_exists( $name );
		}
		$all_tables_exist = ! in_array( false, $table_status, true );

		global $wpdb;

		$migration_completed = get_option( 'certificate_generator_cpt_to_sql_migration_completed', false );
		$migration_stats     = get_option( 'certificate_generator_cpt_to_sql_migration_stats', array() );
		$cpts_cleaned        = get_option( 'certificate_generator_cpts_cleaned_up', false );

		// Count remaining legacy CPT posts directly from DB (CPTs are no longer registered).
		$legacy_cpt_types = array( 'students', 'teachers', 'schools', 'certificates' );
		$placeholders     = implode( ',', array_fill( 0, count( $legacy_cpt_types ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$remaining_rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_type IN ($placeholders) GROUP BY post_type",
				...$legacy_cpt_types
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$remaining_cpt_counts = array_fill_keys( $legacy_cpt_types, 0 );
		foreach ( $remaining_rows as $row ) {
			$remaining_cpt_counts[ $row->post_type ] = (int) $row->cnt;
		}
		$total_remaining_cpts = array_sum( $remaining_cpt_counts );

		// Reset the cleaned flag if CPT posts crept back.
		if ( $cpts_cleaned && $total_remaining_cpts > 0 ) {
			delete_option( 'certificate_generator_cpts_cleaned_up' );
			$cpts_cleaned = false;
		}

		$cpt_counts = array(
			'students'  => $remaining_cpt_counts['students'],
			'teachers'  => $remaining_cpt_counts['teachers'],
			'schools'   => $remaining_cpt_counts['schools'],
			'templates' => $remaining_cpt_counts['certificates'],
		);

		// Count SQL records
		$sql_counts = array();
		foreach ( $tables->get_all_tables() as $name => $table ) {
			if ( in_array( $name, array( 'students', 'teachers', 'schools', 'certificate_templates', 'certificates', 'email_logs' ) ) ) {
				$sql_counts[ $name ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
			}
		}

		?>
		<div class="wrap cg-migration">
			<?php
			certificate_generator_ui_page_header( $this->title, 'Database upkeep: bring tables up to date, move legacy post data into the plugin\'s SQL tables, and clean up afterwards. Work through the steps in order.' );
			if ( $message ) {
				certificate_generator_ui_notice( $message_type, $message );
			}
			?>

			<!-- Step 0: Fix Database (for sites upgrading from an older plugin version) -->
			<?php
			$runner_status  = new MigrationRunner();
			$db_version     = $runner_status->get_current_version();
			$target_version = $runner_status->get_target_version();
			$db_behind      = $runner_status->needs_migration() || $tables->needs_upgrade();
			?>
			<?php certificate_generator_ui_card_open( 'Step 0: Fix Database (Upgrading from an Older Version)', array( 'icon' => 'database', 'class' => $db_behind ? 'cg-card--warn' : '' ) ); ?>
				<p>
					Database version: <strong><?php echo esc_html( $db_version ); ?></strong> —
					latest known: <strong><?php echo esc_html( $target_version ); ?></strong>
					<?php echo $db_behind ? certificate_generator_ui_badge( 'behind — click below to update', 'warn' ) : certificate_generator_ui_badge( 'up to date', 'good' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</p>
				<p class="description">
					If this site was running an older copy of the plugin, its database tables may be missing columns
					added in later versions (e.g. "Unknown column" errors when saving). This safely adds any missing
					columns/indexes without touching existing data — safe to run any time, including when already up to date.
				</p>
				<form method="post" data-cg-busy>
					<?php wp_nonce_field( 'cg_run_db_fix' ); ?>
					<button type="submit" name="cg_run_db_fix" class="button button-primary button-hero">Fix Database Now</button>
				</form>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Step 1: Table Status -->
			<?php certificate_generator_ui_card_open( 'Step 1: Custom Tables Status' ); ?>
				<div class="cg-table-wrap"><table class="widefat cg-table">
					<thead><tr><th>Table</th><th>Status</th><th>Records</th></tr></thead>
					<tbody>
						<?php foreach ( $table_status as $name => $exists ) : ?>
							<tr>
								<td><code><?php echo esc_html( $name ); ?></code></td>
								<td><?php echo $exists ? certificate_generator_ui_badge( 'Exists', 'good' ) : certificate_generator_ui_badge( 'Missing', 'bad' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo isset( $sql_counts[ $name ] ) ? number_format( $sql_counts[ $name ] ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table></div>
				<form method="post" data-cg-busy>
					<?php wp_nonce_field( 'cg_verify_tables' ); ?>
					<button type="submit" name="cg_verify_tables" class="button">Verify / Create Tables</button>
				</form>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Step 2: CPT → SQL Migration -->
			<?php certificate_generator_ui_card_open( 'Step 2: Migrate CPT Data to SQL Tables' ); ?>

				<?php if ( $migration_completed ) : ?>
					<?php certificate_generator_ui_notice( 'success', 'Migration completed: <strong>' . esc_html( $migration_completed ) . '</strong>', false, true ); ?>
					<div class="cg-table-wrap"><table class="widefat cg-table">
						<thead><tr><th>Entity</th><th>CPT Records</th><th>SQL Records</th><th>Status</th></tr></thead>
						<tbody>
							<?php
							$mapping = array(
								'schools'   => array( 'schools', 'schools' ),
								'students'  => array( 'students', 'students' ),
								'teachers'  => array( 'teachers', 'teachers' ),
								'templates' => array( 'certificates', 'certificate_templates' ),
							);
							foreach ( $mapping as $label => [$cpt_key, $sql_key] ) :
								$cpt = $cpt_counts[ $cpt_key ] ?? 0;
								$sql = $sql_counts[ $sql_key ] ?? 0;
								if ( $cpt === 0 && $sql > 0 && $cpts_cleaned ) {
									$status = certificate_generator_ui_badge( 'Migrated', 'good' );
								} elseif ( $cpt === $sql ) {
									$status = certificate_generator_ui_badge( 'Match', 'good' );
								} else {
									$status = certificate_generator_ui_badge( 'Mismatch', 'warn' );
								}
								?>
								<tr>
									<td><?php echo esc_html( ucfirst( $label ) ); ?></td>
									<td><?php echo number_format( $cpt ); ?></td>
									<td><?php echo number_format( $sql ); ?></td>
									<td><?php echo esc_html( $status ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table></div>
				<?php else : ?>
					<?php certificate_generator_ui_notice( 'warning', 'Migration has not been run yet.', false, true ); ?>
				<?php endif; ?>

				<p>
					This copies all data from WordPress Custom Post Types to custom SQL tables.
					<strong>CPT data is NOT deleted.</strong> You can verify and clean up later.
				</p>

				<div class="cg-actions">
				<form method="post" data-cg-busy>
					<?php wp_nonce_field( 'cg_run_cpt_to_sql' ); ?>
					<button type="submit" name="cg_run_cpt_to_sql" class="button button-primary button-hero"
							data-cg-confirm-label="Run migration" data-cg-confirm="Copy all legacy post data into the SQL tables? Nothing is deleted.">
						Run CPT → SQL Migration
					</button>
				</form>

				<?php if ( $migration_completed && ! $cpts_cleaned ) : ?>
				<form method="post" data-cg-busy>
					<?php wp_nonce_field( 'cg_rollback_migration' ); ?>
					<button type="submit" name="cg_rollback_migration" class="button cg-button-danger"
							data-cg-danger data-cg-confirm-label="Roll back" data-cg-confirm="Clear all SQL tables? Legacy post data is not deleted, so you can run the migration again.">
						↩ Rollback Migration
					</button>
				</form>
				<?php endif; ?>
				</div>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Step 2b: Fix Issue Dates (one-time normalization) -->
			<?php certificate_generator_ui_card_open( 'Step 2b: Normalize Issue Dates (One-Time Fix)' ); ?>
				<p>
					Converts any <code>issue_date</code> values stored in <code>d-m-Y</code> (e.g. <code>23-03-2026</code>)
					to the correct <code>Y-m-d</code> storage format (<code>2026-03-23</code>).
					This fixes <em>"No matching template"</em> errors caused by date format mismatches during template selection.
					<strong>Safe to run multiple times.</strong>
				</p>
				<form method="post" data-cg-busy>
					<?php wp_nonce_field( 'cg_fix_issue_dates' ); ?>
					<button type="submit" name="cg_fix_issue_dates" class="button button-primary"
							data-cg-confirm-label="Fix dates" data-cg-confirm="Rewrite issue_date values in d-m-Y format to Y-m-d across all student, teacher and school posts? Safe to run more than once.">
						Fix Issue Dates (d-m-Y → Y-m-d)
					</button>
				</form>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Step 3: Cleanup CPTs (Optional) -->
			<?php certificate_generator_ui_card_open( 'Step 3: Clean Up Legacy CPT Data' ); ?>

				<?php if ( $cpts_cleaned && $total_remaining_cpts === 0 ) : ?>
					<?php certificate_generator_ui_notice( 'success', 'All legacy CPT data cleaned up. Plugin uses SQL tables exclusively.', false, true ); ?>
				<?php elseif ( $migration_completed || $total_remaining_cpts > 0 ) : ?>
					<?php if ( $total_remaining_cpts > 0 ) : ?>
						<?php certificate_generator_ui_notice( 'warning', '<strong>' . number_format_i18n( $total_remaining_cpts ) . '</strong> legacy CPT post(s) still in <code>wp_posts</code>.<br>These generate public URLs like <code>/students/name-school/</code> that expose data and return broken pages. Clean up to remove them.', false, true ); ?>
						<div class="cg-table-wrap"><table class="widefat cg-table">
							<thead><tr><th>Post Type</th><th>Remaining Posts</th><th>Public URL Pattern</th></tr></thead>
							<tbody>
								<?php foreach ( $remaining_cpt_counts as $pt => $count ) : ?>
									<?php if ( $count > 0 ) : ?>
									<tr>
										<td><code><?php echo esc_html( $pt ); ?></code></td>
										<td><strong><?php echo number_format( $count ); ?></strong></td>
										<td><code>/<?php echo esc_html( $pt ); ?>/post-slug/</code></td>
									</tr>
									<?php endif; ?>
								<?php endforeach; ?>
							</tbody>
						</table></div>
					<?php else : ?>
						<?php certificate_generator_ui_notice( 'info', 'After verifying all data migrated correctly, click below to remove CPT data.', false, true ); ?>
					<?php endif; ?>
					<form method="post" data-cg-busy>
						<?php wp_nonce_field( 'cg_cleanup_cpts' ); ?>
						<button type="submit" name="cg_cleanup_cpts" class="button button-primary"
								data-cg-danger data-cg-confirm-label="Delete legacy posts" data-cg-confirm="Permanently delete all legacy posts (students, teachers, schools, certificates) from wp_posts? SQL table data is not affected. This cannot be undone.">
							Clean Up All Legacy CPT Data
						</button>
					</form>
				<?php else : ?>
					<p class="description">Complete Step 2 first.</p>
				<?php endif; ?>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Step 4: Public URL Protection -->
			<?php certificate_generator_ui_card_open( 'Step 4: Public URL Protection' ); ?>
				<?php if ( $total_remaining_cpts === 0 ) : ?>
					<?php certificate_generator_ui_notice( 'success', 'No legacy CPT posts remain — no public URLs to worry about.', false, true ); ?>
				<?php else : ?>
					<?php certificate_generator_ui_notice( 'info', 'While legacy CPT posts exist, the plugin blocks public access to <code>/students/</code>, <code>/teachers/</code>, and <code>/schools/</code> URLs with a 404 response. Clean up CPT data (Step 3) to remove these URLs permanently.', false, true ); ?>
				<?php endif; ?>
			<?php certificate_generator_ui_card_close(); ?>

			<!-- Orphan Report -->
			<?php if ( $total_remaining_cpts > 0 ) : ?>
			<?php certificate_generator_ui_card_open( 'Orphan Report' ); ?>
				<p class="description">CPT posts in <code>wp_posts</code> with no matching row in the SQL table (by email for students/teachers, school_name for schools, certificate_type+event_date for templates).</p>
				<?php $orphans = $this->detect_orphans( $tables ); ?>
				<?php if ( array_sum( array_column( $orphans, 'cpt_only' ) ) === 0 && array_sum( array_column( $orphans, 'sql_only' ) ) === 0 ) : ?>
					<?php certificate_generator_ui_notice( 'success', 'No orphans detected — all CPT posts have matching SQL rows.', false, true ); ?>
				<?php else : ?>
					<div class="cg-table-wrap"><table class="widefat cg-table">
						<thead><tr><th>Entity</th><th>CPT-only (no SQL match)</th><th>SQL-only (no CPT match)</th></tr></thead>
						<tbody>
							<?php foreach ( $orphans as $entity => $counts ) : ?>
								<tr>
									<td><?php echo esc_html( ucfirst( $entity ) ); ?></td>
									<td><?php echo wp_kses_post( $counts['cpt_only'] > 0 ? certificate_generator_ui_badge( number_format_i18n( $counts['cpt_only'] ), 'bad' ) : '0' ); ?></td>
									<td><?php echo wp_kses_post( $counts['sql_only'] > 0 ? certificate_generator_ui_badge( number_format_i18n( $counts['sql_only'] ), 'warn' ) : '0' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table></div>
					<p class="cg-hint">CPT-only orphans are safe to delete via "Clean Up" above. SQL-only records are expected after cleanup.</p>
				<?php endif; ?>
			<?php certificate_generator_ui_card_close(); ?>
			<?php endif; ?>

			<?php certificate_generator_ui_card_open( 'Migration Process' ); ?>
				<ol>
					<li><strong>Tables Created:</strong> Custom SQL tables are created automatically on activation.</li>
					<li><strong>Data Copied:</strong> All CPT data is copied to SQL tables (CPT data preserved).</li>
					<li><strong>Verify:</strong> Check record counts match between CPT and SQL.</li>
					<li><strong>Clean Up:</strong> Remove legacy CPT posts from <code>wp_posts</code> — eliminates public URLs and orphaned data.</li>
					<li><strong>URL Protection:</strong> While legacy posts exist, public access is blocked with a 404 response.</li>
				</ol>
			<?php certificate_generator_ui_card_close(); ?>
		</div>
		<?php
	}

	private function rollback_migration( CustomTables $tables ): void {
		global $wpdb;
		$truncate = array( 'students', 'teachers', 'schools', 'certificate_templates', 'certificates' );
		foreach ( $truncate as $name ) {
			$table = $tables->get_table( $name );
			if ( $table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$wpdb->query( "TRUNCATE TABLE $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		delete_option( 'certificate_generator_cpt_to_sql_migration_completed' );
		delete_option( 'certificate_generator_cpt_to_sql_migration_stats' );
		delete_option( 'certificate_generator_cpt_to_sql_migration_timestamp' );
	}

	/**
	 * Normalize all issue_date values to Y-m-d storage format.
	 * Fixes records imported via CSV that stored dates as d-m-Y.
	 * Fixes both CPT postmeta AND SQL tables.
	 */
	private function fix_issue_dates(): int {
		global $wpdb;
		$fixed = 0;

		// Fix CPT postmeta
		$rows = $wpdb->get_results(
			"SELECT pm.post_id, pm.meta_value
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = 'issue_date'
               AND pm.meta_value != ''
               AND p.post_type IN ('students','teachers','schools')"
		);

		foreach ( $rows as $row ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $row->meta_value ) ) {
				continue;
			}

			$stored = null;
			foreach ( array( 'd-m-Y', 'm/d/Y', 'Y/m/d', 'd/m/Y' ) as $fmt ) {
				$dt = \DateTime::createFromFormat( $fmt, $row->meta_value );
				if ( $dt && $dt->format( $fmt ) === $row->meta_value ) {
					$stored = $dt->format( 'Y-m-d' );
					break;
				}
			}
			if ( ! $stored ) {
				$ts = strtotime( $row->meta_value );
				if ( $ts ) {
					$stored = gmdate( 'Y-m-d', $ts );
				}
			}

			if ( $stored && $stored !== $row->meta_value ) {
				update_post_meta( (int) $row->post_id, 'issue_date', $stored );
				++$fixed;
			}
		}

		// Fix SQL tables using the migration class
		if ( class_exists( 'CertificateGenerator\Database\CptToSqlMigration' ) ) {
			$sql_fixed = \CertificateGenerator\Database\CptToSqlMigration::normalize_issue_dates();
			foreach ( $sql_fixed as $entity => $count ) {
				$fixed += $count;
			}
		}

		return $fixed;
	}

	private function detect_orphans( CustomTables $tables ): array {
		global $wpdb;

		$results = array(
			'students'  => array( 'cpt_only' => 0, 'sql_only' => 0 ),
			'teachers'  => array( 'cpt_only' => 0, 'sql_only' => 0 ),
			'schools'   => array( 'cpt_only' => 0, 'sql_only' => 0 ),
			'templates' => array( 'cpt_only' => 0, 'sql_only' => 0 ),
		);

		$checks = array(
			'students'  => array( 'cpt' => 'students', 'sql' => 'students', 'meta_key' => 'email', 'sql_col' => 'email' ),
			'teachers'  => array( 'cpt' => 'teachers', 'sql' => 'teachers', 'meta_key' => 'email', 'sql_col' => 'email' ),
			'schools'   => array( 'cpt' => 'schools', 'sql' => 'schools', 'meta_key' => 'school_name', 'sql_col' => 'school_name' ),
			'templates' => array( 'cpt' => 'certificates', 'sql' => 'certificate_templates', 'meta_key' => 'certificate_type', 'sql_col' => 'certificate_type' ),
		);

		foreach ( $checks as $entity => $cfg ) {
			$sql_table = $tables->get_table( $cfg['sql'] );
			if ( ! $sql_table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sql_table ) ) !== $sql_table ) {
				continue;
			}

			$cpt_values = $wpdb->get_col( $wpdb->prepare(
				"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_type = %s AND pm.meta_key = %s AND pm.meta_value != ''",
				$cfg['cpt'],
				$cfg['meta_key']
			) );

			$sql_values = $wpdb->get_col( "SELECT {$cfg['sql_col']} FROM $sql_table WHERE {$cfg['sql_col']} != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$cpt_set = array_flip( $cpt_values );
			$sql_set = array_flip( $sql_values );

			$results[ $entity ]['cpt_only'] = count( array_diff_key( $cpt_set, $sql_set ) );
			$results[ $entity ]['sql_only'] = count( array_diff_key( $sql_set, $cpt_set ) );
		}

		return $results;
	}

	private function cleanup_cpts(): void {
		global $wpdb;

		$post_types   = array( 'students', 'teachers', 'schools', 'certificates' );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from $wpdb->prefix; other interpolated parts are whitelisted, cast or prepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders)",
				...$post_types
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! empty( $post_ids ) ) {
			$id_list = implode( ',', array_map( 'absint', $post_ids ) );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($id_list)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($id_list)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		update_option( 'certificate_generator_cpts_cleaned_up', true );
	}
}
