<?php
declare(strict_types=1);

namespace CertificateGenerator\Database\Migrations;

/**
 * Manages and runs database migrations in order.
 */
class MigrationRunner {

	private string $option_key      = 'certificate_generator_db_version';
	private string $current_version = '010';
	private array $migrations       = array();

	public function __construct() {
		$this->migrations = array(
			'001' => new Migration001_AddTimeColumns(),
			'002' => new Migration002_AddSendEmailColumn(),
			'003' => new Migration003_BackfillYear(),
			'004' => new Migration004_AddImportSourceColumn(),
			'005' => new Migration005_AddBadgeColumns(),
			'006' => new Migration006_AddEventIdColumns(),
			'007' => new Migration007_AddStudentPhotoColumn(),
			'008' => new Migration008_AddTemplateEntityType(),
			'009' => new Migration009_AddLookupIndexes(),
			'010' => new Migration010_PrefixRename(),
		);
	}

	public function run(): void {
		$installed_version = $this->get_current_version();

		foreach ( $this->migrations as $version => $migration ) {
			if ( version_compare( $version, $installed_version, '>' ) ) {
				$migration->up();
				update_option( $this->option_key, $version );
			}
		}
	}

	public function rollback( string $target_version = '000' ): void {
		$installed_version = $this->get_current_version();

		$versions = array_reverse( array_keys( $this->migrations ) );
		foreach ( $versions as $version ) {
			if ( version_compare( $version, $installed_version, '<=' ) && version_compare( $version, $target_version, '>' ) ) {
				$this->migrations[ $version ]->down();
				update_option( $this->option_key, $target_version );
			}
		}
	}

	/** Sites updated from before 7.6.0 still store the version under the old cg_db_version name (renamed by migration 010). */
	public function get_current_version(): string {
		return (string) get_option( $this->option_key, get_option( 'cg_db_version', '000' ) ); // legacy-name: pre-7.6.0 key, renamed by migration 010
	}

	public function get_target_version(): string {
		return $this->current_version;
	}

	public function needs_migration(): bool {
		return version_compare( $this->get_current_version(), $this->current_version, '<' );
	}
}
