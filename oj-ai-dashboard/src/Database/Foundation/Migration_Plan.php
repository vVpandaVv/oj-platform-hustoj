<?php
/**
 * Frozen initial migration plan.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use LogicException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Splits schema ownership bootstrap from the remaining dependency-safe DDL.
 */
final class Migration_Plan {
	public const LOCK_NAME                     = 'oj_ai_dashboard_schema';
	public const BASELINE_SCHEMA_VERSION       = '1.0.0';
	public const BASELINE_SCHEMA_FINGERPRINT   = 'c55b775563273920fef127142e99e64321a07699e6a3f081579714dfb4a526c1';
	public const PREVIOUS_SCHEMA_VERSION       = '1.0.1';
	public const PREVIOUS_SCHEMA_FINGERPRINT   = '31cee35952177177a1b36bd483f43ea1d6c1880c7d2a1e1ad891f896a41e32b1';
	public const PREVIOUS_2_SCHEMA_VERSION     = '1.0.2';
	public const PREVIOUS_2_SCHEMA_FINGERPRINT = 'e49cb1fbcd726f4aae44fe4cb0ad952eabda49971420a7a2a398eee037fd6ee6';
	public const PREVIOUS_3_SCHEMA_VERSION     = '1.0.3';
	public const PREVIOUS_3_SCHEMA_FINGERPRINT = 'b2e72dc8508407e2530b6629a68d0c092d5f6c17aa01a9a884f22138609a7688';
	public const PROJECT_TABLES                = array( 'oj_ai_assessment_projects', 'oj_ai_assessment_project_revisions', 'oj_ai_assessment_project_worksheets' );

	/**
	 * Catalog.
	 *
	 * @var Schema_Catalog
	 */
	private Schema_Catalog $catalog;

	/**
	 * Migrations.
	 *
	 * @var array<int,Migration_Definition>|null
	 */
	private ?array $migrations = null;

	/**
	 * Baseline tables.
	 *
	 * @var array<string,Table_Definition>|null
	 */
	private ?array $baseline_tables = null;

	/**
	 * Previous tables.
	 *
	 * @var array<string,Table_Definition>|null
	 */
	private ?array $previous_tables = null;

	/**
	 * Previous 2 tables.
	 *
	 * @var array<string,Table_Definition>|null
	 */
	private ?array $previous_2_tables = null;

	/**
	 * Initialize migration plan dependencies and state.
	 *
	 * @param Schema_Catalog $catalog Catalog.
	 */
	public function __construct( Schema_Catalog $catalog ) {
		$this->catalog = $catalog;
	}

	/**
	 * Migrations.
	 *
	 * @return array<int,Migration_Definition>
	 */
	public function migrations(): array {
		if ( null !== $this->migrations ) {
			return $this->migrations;
		}

		$baseline = $this->baseline_tables();
		$ordered  = array_map(
			static fn ( Table_Definition $table ): Table_Definition => $baseline[ $table->name() ],
			array_values( array_filter( $this->catalog->creation_order(), static fn ( Table_Definition $table ): bool => isset( $baseline[ $table->name() ] ) ) )
		);
		$sql      = array();
		foreach ( $ordered as $table ) {
			$sql[ $table->name() ] = $table->create_sql();
		}

		$bootstrap_names  = array( 'oj_ai_installations', 'oj_ai_schema_migrations' );
		$target_names     = array_values( array_diff( array_keys( $sql ), $bootstrap_names ) );
		$this->migrations = array(
			new Migration_Definition(
				'202608140001_bootstrap_schema_ledger',
				'0.0.1',
				self::BASELINE_SCHEMA_FINGERPRINT,
				$bootstrap_names,
				array_map( static fn ( string $name ): string => $sql[ $name ], $bootstrap_names )
			),
			new Migration_Definition(
				'202608140002_create_frozen_schema',
				self::BASELINE_SCHEMA_VERSION,
				self::BASELINE_SCHEMA_FINGERPRINT,
				$target_names,
				array_map( static fn ( string $name ): string => $sql[ $name ], $target_names )
			),
			new Migration_Definition(
				'202608220001_export_job_context_identity',
				self::PREVIOUS_SCHEMA_VERSION,
				self::PREVIOUS_SCHEMA_FINGERPRINT,
				array( 'oj_ai_export_jobs' ),
				array( $this->export_job_amendment_sql() )
			),
			new Migration_Definition(
				'202608230001_expand_submission_prompt_contract',
				self::PREVIOUS_2_SCHEMA_VERSION,
				self::PREVIOUS_2_SCHEMA_FINGERPRINT,
				array( 'oj_ai_submission_evaluations' ),
				array( $this->submission_prompt_contract_amendment_sql() )
			),
			new Migration_Definition(
				'202608300001_add_evaluation_run_last_error_message',
				self::PREVIOUS_3_SCHEMA_VERSION,
				self::PREVIOUS_3_SCHEMA_FINGERPRINT,
				array( 'oj_ai_evaluation_runs' ),
				array( $this->evaluation_run_last_error_message_amendment_sql() )
			),
			new Migration_Definition(
				'202609070001_assessment_projects',
				Schema_Catalog::SCHEMA_VERSION,
				$this->catalog->fingerprint(),
				self::PROJECT_TABLES,
				array_map( fn ( string $name ): string => $this->catalog->table( $name )->create_sql(), self::PROJECT_TABLES )
			),
		);

		$this->assert_complete();

		return $this->migrations;
	}

	/**
	 * Baseline tables.
	 *
	 * @return array<string,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function baseline_tables(): array {
		if ( null !== $this->baseline_tables ) {
			return $this->baseline_tables;
		}
		$definitions = array();
		foreach ( $this->catalog->tables() as $table ) {
			if ( in_array( $table->name(), self::PROJECT_TABLES, true ) ) {
				continue;
			}
			$columns = $table->columns();
			$indexes = $table->indexes();
			if ( 'oj_ai_export_jobs' === $table->name() ) {
				$columns['context_type']     = 'VARCHAR(16) NOT NULL';
				$columns['context_id']       = 'VARCHAR(191) NOT NULL';
				$columns['context_revision'] = 'VARCHAR(191) NOT NULL';
				unset( $columns['active_identity_key'] );
				$indexes = array_values(
					array_filter(
						$indexes,
						static fn ( array $index ): bool => 'uq_export_active_identity' !== $index['name']
					)
				);
			}
			if ( 'oj_ai_submission_evaluations' === $table->name() ) {
				$columns['prompt_contract_version'] = 'VARCHAR(32) NOT NULL';
			}
			if ( 'oj_ai_evaluation_runs' === $table->name() ) {
				unset( $columns['last_error_message'] );
			}
			$definitions[ $table->name() ] = new Table_Definition(
				$table->name(),
				$table->owner(),
				$columns,
				$indexes,
				$table->foreign_keys()
			);
		}
		$payload = array_map(
			static fn ( Table_Definition $table ): array => $table->normalized_definition(),
			array_values( $definitions )
		);
		$json    = json_encode( $payload, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json || ! hash_equals( self::BASELINE_SCHEMA_FINGERPRINT, hash( 'sha256', $json ) ) ) {
			throw new LogicException( 'The immutable 1.0.0 schema baseline changed.' );
		}
		$this->baseline_tables = $definitions;

		return $this->baseline_tables;
	}

	/**
	 * Previous tables.
	 *
	 * @return array<string,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function previous_tables(): array {
		if ( null !== $this->previous_tables ) {
			return $this->previous_tables;
		}
		$definitions                                 = $this->previous_3_tables();
		$table                                       = $definitions['oj_ai_submission_evaluations'];
		$columns                                     = $table->columns();
		$columns['prompt_contract_version']          = 'VARCHAR(32) NOT NULL';
		$definitions['oj_ai_submission_evaluations'] = new Table_Definition(
			$table->name(),
			$table->owner(),
			$columns,
			$table->indexes(),
			$table->foreign_keys()
		);
		$runs_table                                  = $definitions['oj_ai_evaluation_runs'];
		$runs_columns                                = $runs_table->columns();
		unset( $runs_columns['last_error_message'] );
		$definitions['oj_ai_evaluation_runs'] = new Table_Definition(
			$runs_table->name(),
			$runs_table->owner(),
			$runs_columns,
			$runs_table->indexes(),
			$runs_table->foreign_keys()
		);
		$payload                              = array_map(
			static fn ( Table_Definition $definition ): array => $definition->normalized_definition(),
			array_values( $definitions )
		);
		$json                                 = json_encode( $payload, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json || ! hash_equals( self::PREVIOUS_SCHEMA_FINGERPRINT, hash( 'sha256', $json ) ) ) {
			throw new LogicException( 'The immutable 1.0.1 schema changed.' );
		}
		$this->previous_tables = $definitions;

		return $this->previous_tables;
	}

	/**
	 * Previous 2 tables.
	 *
	 * @return array<string,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function previous_2_tables(): array {
		if ( null !== $this->previous_2_tables ) {
			return $this->previous_2_tables;
		}
		$definitions  = $this->previous_3_tables();
		$runs_table   = $definitions['oj_ai_evaluation_runs'];
		$runs_columns = $runs_table->columns();
		unset( $runs_columns['last_error_message'] );
		$definitions['oj_ai_evaluation_runs'] = new Table_Definition(
			$runs_table->name(),
			$runs_table->owner(),
			$runs_columns,
			$runs_table->indexes(),
			$runs_table->foreign_keys()
		);
		$payload                              = array_map(
			static fn ( Table_Definition $definition ): array => $definition->normalized_definition(),
			array_values( $definitions )
		);
		$json                                 = json_encode( $payload, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json || ! hash_equals( self::PREVIOUS_2_SCHEMA_FINGERPRINT, hash( 'sha256', $json ) ) ) {
			throw new LogicException( 'The immutable 1.0.2 schema changed.' );
		}
		$this->previous_2_tables = $definitions;

		return $this->previous_2_tables;
	}

	/**
	 * Postcondition definitions.
	 *
	 * @param Migration_Definition              $migration Migration.
	 * @param array<string,array<string,mixed>> $records Records.
	 * @return array<string,Table_Definition>
	 */
	public function postcondition_definitions( Migration_Definition $migration, array $records = array() ): array {
		$baseline    = $this->baseline_tables();
		$target      = $this->catalog->tables();
		$definitions = array();
		foreach ( $migration->table_names() as $table_name ) {
			$definitions[ $table_name ] = $migration->alters_tables() ? $target[ $table_name ] : ( $baseline[ $table_name ] ?? $target[ $table_name ] );
		}
		$seen = false;
		foreach ( $this->migrations() as $candidate ) {
			if ( $candidate->id() === $migration->id() ) {
				$seen = true;
				continue;
			}
			if ( ! $seen || ! $candidate->alters_tables()
				|| Migration_Ledger_State::APPLIED !== (string) ( $records[ $candidate->id() ]['state'] ?? '' ) ) {
				continue;
			}
			foreach ( array_intersect( $migration->table_names(), $candidate->table_names() ) as $table_name ) {
				$definitions[ $table_name ] = $target[ $table_name ];
			}
		}

		return $definitions;
	}

	/**
	 * Is supported baseline installation.
	 *
	 * @param array<string,mixed>|null $installation Installation.
	 * @return bool
	 */
	public function is_supported_baseline_installation( ?array $installation ): bool {
		return $this->matches_installation(
			$installation,
			self::BASELINE_SCHEMA_VERSION,
			self::BASELINE_SCHEMA_FINGERPRINT
		);
	}

	/**
	 * Is supported previous installation.
	 *
	 * @param array<string,mixed>|null $installation Installation.
	 * @return bool
	 */
	public function is_supported_previous_installation( ?array $installation ): bool {
		return $this->matches_installation(
			$installation,
			self::PREVIOUS_SCHEMA_VERSION,
			self::PREVIOUS_SCHEMA_FINGERPRINT
		);
	}

	/**
	 * Is supported previous 2 installation.
	 *
	 * @param array<string,mixed>|null $installation Installation.
	 * @return bool
	 */
	public function is_supported_previous_2_installation( ?array $installation ): bool {
		return $this->matches_installation(
			$installation,
			self::PREVIOUS_2_SCHEMA_VERSION,
			self::PREVIOUS_2_SCHEMA_FINGERPRINT
		);
	}

	/**
	 * Is supported upgrade installation.
	 *
	 * @param array<string,mixed>|null $installation Installation.
	 * @return bool
	 */
	public function is_supported_upgrade_installation( ?array $installation ): bool {
		return $this->is_supported_baseline_installation( $installation )
			|| $this->is_supported_previous_installation( $installation )
			|| $this->is_supported_previous_2_installation( $installation )
			|| $this->matches_installation( $installation, self::PREVIOUS_3_SCHEMA_VERSION, self::PREVIOUS_3_SCHEMA_FINGERPRINT );
	}

	/**
	 * Upgrade source tables.
	 *
	 * @param array<string,mixed>|null $installation Installation.
	 * @return array<string,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function upgrade_source_tables( ?array $installation ): array {
		if ( $this->matches_installation( $installation, self::PREVIOUS_3_SCHEMA_VERSION, self::PREVIOUS_3_SCHEMA_FINGERPRINT ) ) {
			return $this->previous_3_tables();
		}
		if ( $this->is_supported_baseline_installation( $installation ) ) {
			return $this->baseline_tables();
		}
		if ( $this->is_supported_previous_installation( $installation ) ) {
			return $this->previous_tables();
		}
		if ( $this->is_supported_previous_2_installation( $installation ) ) {
			return $this->previous_2_tables();
		}

		throw new LogicException( 'The schema upgrade source is not supported.' );
	}

	/**
	 * Previous 3 tables.
	 *
	 * @return array<string,Table_Definition>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function previous_3_tables(): array {
		$tables = array_diff_key( $this->catalog->tables(), array_flip( self::PROJECT_TABLES ) );
		$json   = json_encode( array_map( static fn ( Table_Definition $table ): array => $table->normalized_definition(), array_values( $tables ) ), JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json || ! hash_equals( self::PREVIOUS_3_SCHEMA_FINGERPRINT, hash( 'sha256', $json ) ) ) {
			throw new LogicException( 'The immutable 1.0.3 schema changed.' );
		}
		return $tables;
	}

	/**
	 * Matches installation.
	 *
	 * @param array|null $installation Installation.
	 * @param string     $version Version.
	 * @param string     $fingerprint Fingerprint.
	 * @return bool
	 */
	private function matches_installation( ?array $installation, string $version, string $fingerprint ): bool {
		return is_array( $installation )
			&& Schema_Catalog::ADDON_ID === (string) ( $installation['addon_id'] ?? '' )
			&& hash_equals( $this->catalog->owner_marker(), (string) ( $installation['owner_marker'] ?? '' ) )
			&& $version === (string) ( $installation['schema_version'] ?? '' )
			&& hash_equals( $fingerprint, (string) ( $installation['schema_fingerprint'] ?? '' ) )
			&& in_array( (string) ( $installation['state'] ?? '' ), array( Installation_State::REGISTRATION_PENDING, Installation_State::RUNTIME_READY ), true );
	}

	/**
	 * Checksum map.
	 *
	 * @return array<string,string>
	 */
	public function checksum_map(): array {
		$checksums = array();
		foreach ( $this->migrations() as $migration ) {
			$checksums[ $migration->id() ] = $migration->checksum();
		}

		return $checksums;
	}

	/**
	 * Fingerprint.
	 *
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function fingerprint(): string {
		$json = json_encode( $this->checksum_map(), JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain JSON uses explicit flags and error handling; fallback re-encoding would change canonical bytes.
		if ( false === $json ) {
			throw new LogicException( 'Unable to encode the migration plan.' );
		}

		return hash( 'sha256', $json );
	}

	/**
	 * Reject invalid complete.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_complete(): void {
		$covered = array();
		foreach ( $this->migrations as $migration ) {
			if ( $migration->creates_tables() ) {
				$covered = array_merge( $covered, $migration->table_names() );
				continue;
			}
			foreach ( $migration->table_names() as $table_name ) {
				if ( ! in_array( $table_name, $this->catalog->table_names(), true ) ) {
					throw new LogicException( 'Forward migrations may only alter owned literal tables.' );
				}
			}
		}
		if ( count( $covered ) !== count( array_unique( $covered ) ) ) {
			throw new LogicException( 'The initial create migrations repeat a table.' );
		}
		if ( array_diff( $this->catalog->table_names(), $covered ) || array_diff( $covered, $this->catalog->table_names() ) ) {
			throw new LogicException( 'The initial create migrations must cover all 45 owned tables exactly once.' );
		}
	}

	/**
	 * Export job amendment sql.
	 *
	 * @return string
	 */
	private function export_job_amendment_sql(): string {
		return 'ALTER TABLE `oj_ai_export_jobs`
  MODIFY COLUMN `context_type` VARCHAR(16) NULL,
  MODIFY COLUMN `context_id` VARCHAR(191) NULL,
  MODIFY COLUMN `context_revision` VARCHAR(191) NULL,
  ADD COLUMN `active_identity_key` VARCHAR(191) NULL AFTER `idempotency_key`,
  ADD UNIQUE KEY `uq_export_active_identity` (`active_identity_key`)';
	}

	/**
	 * Submission prompt contract amendment sql.
	 *
	 * @return string
	 */
	private function submission_prompt_contract_amendment_sql(): string {
		return 'ALTER TABLE `oj_ai_submission_evaluations`
  MODIFY COLUMN `prompt_contract_version` VARCHAR(64) NOT NULL';
	}

	/**
	 * Evaluation run last error message amendment sql.
	 *
	 * @return string
	 */
	private function evaluation_run_last_error_message_amendment_sql(): string {
		return 'ALTER TABLE `oj_ai_evaluation_runs`
  ADD COLUMN `last_error_message` VARCHAR(500) NULL AFTER `last_error_code`';
	}
}
