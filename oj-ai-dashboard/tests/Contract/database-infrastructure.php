<?php
/**
 * G1 database infrastructure adapter contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Lock;
use OJAI\Dashboard\Database\Foundation\Infrastructure\WordPress_Schema_Statement_Executor;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Migration_Definition;
use OJAI\Dashboard\Database\Foundation\Migration_Ledger_State;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Inspector_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Ledger_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Lock_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Statement_Executor_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Runner;
use OJAI\Dashboard\Database\Foundation\Schema_Metadata_Contract;
use OJAI\Dashboard\Database\Foundation\Schema_Migration_Checkpoint;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;
use OJAI\Dashboard\Database\Foundation\Schema_Repair_Authorization;

$addon_root = dirname( __DIR__, 2 );

defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Database infrastructure assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @throws RuntimeException When a contract fails.
 * @return void
 */
function database_infrastructure_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * In-memory physical schema and ledger used to exercise crash recovery.
 */
final class Database_Infrastructure_Test_Store implements
	Schema_Inspector_Port_Interface,
	Schema_Ledger_Port_Interface,
	Schema_Statement_Executor_Port_Interface {
	/**
	 * Catalog.
	 *
	 * @var Schema_Catalog
	 */
	private Schema_Catalog $catalog;
	/**
	 * Plan.
	 *
	 * @var Migration_Plan
	 */
	private Migration_Plan $plan;

	/**
	 * Tables.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $tables = array();

	/**
	 * Foreign installation rows.
	 *
	 * @var int
	 */
	public int $foreign_installation_rows = 0;

	/**
	 * Installation.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $installation = null;

	/**
	 * Migrations.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $migrations = array();

	/**
	 * Statements.
	 *
	 * @var array<int,string>
	 */
	public array $statements = array();

	/**
	 * Fail at attempt.
	 *
	 * @var int|null
	 */
	public ?int $fail_at_attempt = null;
	/**
	 * Attempt count.
	 *
	 * @var int
	 */
	public int $attempt_count = 0;
	/**
	 * Drift after attempt.
	 *
	 * @var int|null
	 */
	public ?int $drift_after_attempt = null;
	/**
	 * Fail once at operation.
	 *
	 * @var string|null
	 */
	public ?string $fail_once_at_operation = null;

	/**
	 * Initialize database infrastructure test store dependencies and state.
	 *
	 * @param Schema_Catalog $catalog Catalog.
	 */
	public function __construct( Schema_Catalog $catalog ) {
		$this->catalog = $catalog;
		$this->plan    = new Migration_Plan( $catalog );
	}

	/**
	 * Literal table names.
	 *
	 * @return array<int,string>
	 */
	public function literal_table_names(): array {
		$names = array_keys( $this->tables );
		sort( $names, SORT_STRING );

		return $names;
	}

	/**
	 * Metadata.
	 *
	 * @param array<int,string> $table_names Table names.
	 * @return array<string,array<string,mixed>>
	 */
	public function metadata( array $table_names ): array {
		$metadata = array();
		foreach ( $table_names as $table_name ) {
			if ( isset( $this->tables[ $table_name ] ) ) {
				$metadata[ $table_name ] = $this->tables[ $table_name ];
			}
		}

		return $metadata;
	}

	/**
	 * Execute database infrastructure test store.
	 *
	 * @param string $statement Statement.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( string $statement ): void {
		++$this->attempt_count;
		if ( null !== $this->fail_at_attempt && $this->attempt_count === $this->fail_at_attempt ) {
			throw new RuntimeException( 'simulated_schema_failure' );
		}
		if ( 1 === preg_match( '/^CREATE TABLE `(oj_ai_[a-z0-9_]+)`/', $statement, $matches ) ) {
			$table_name = $matches[1];
			if ( isset( $this->tables[ $table_name ] ) ) {
				throw new RuntimeException( 'test_executor_duplicate_table' );
			}
			$definitions                 = $this->plan->baseline_tables() + $this->catalog->tables();
			$this->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definitions[ $table_name ] );
			$this->statements[]          = $statement;
			$this->drift_metadata_if_requested( $table_name );
			return;
		}
		if ( 1 === preg_match( '/^ALTER TABLE `(oj_ai_[a-z0-9_]+)`/', $statement, $matches ) ) {
			$table_name = $matches[1];
			if ( ! in_array( $table_name, array( 'oj_ai_export_jobs', 'oj_ai_submission_evaluations', 'oj_ai_evaluation_runs' ), true )
				|| ! isset( $this->tables[ $table_name ] ) ) {
				throw new RuntimeException( 'test_executor_invalid_alter_target' );
			}
			$definitions                 = $this->catalog->tables();
			$this->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definitions[ $table_name ] );
			$this->statements[]          = $statement;
			$this->drift_metadata_if_requested( $table_name );
			return;
		}

		throw new RuntimeException( 'test_executor_rejected_statement' );
	}

	/**
	 * Find installation.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_installation(): ?array {
		return $this->installation;
	}

	/**
	 * Installation row count.
	 *
	 * @return int
	 */
	public function installation_row_count(): int {
		return $this->foreign_installation_rows + ( null === $this->installation ? 0 : 1 );
	}

	/**
	 * All migrations.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function all_migrations(): array {
		return $this->migrations;
	}

	/**
	 * Create installation.
	 *
	 * @param string $owner_marker Owner marker.
	 * @param string $schema_version Schema version.
	 * @param string $schema_fingerprint Schema fingerprint.
	 * @param string $state State.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function create_installation(
		string $owner_marker,
		string $schema_version,
		string $schema_fingerprint,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc
	): void {
		if ( null !== $this->installation ) {
			throw new RuntimeException( 'test_duplicate_installation' );
		}
		$this->installation = array(
			'addon_id'             => Schema_Catalog::ADDON_ID,
			'owner_marker'         => $owner_marker,
			'schema_version'       => $schema_version,
			'schema_fingerprint'   => $schema_fingerprint,
			'state'                => $state,
			'lock_version'         => 0,
			'created_by_user_id'   => $actor_user_id,
			'created_at_utc'       => $occurred_at_utc,
			'updated_by_user_id'   => $actor_user_id,
			'updated_at_utc'       => $occurred_at_utc,
			'last_verified_at_utc' => null,
		);
	}

	/**
	 * Update installation.
	 *
	 * @param string $schema_version Schema version.
	 * @param string $schema_fingerprint Schema fingerprint.
	 * @param string $state State.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $occurred_at_utc Occurred at utc.
	 * @param bool   $verified Verified.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function update_installation(
		string $schema_version,
		string $schema_fingerprint,
		string $state,
		int $actor_user_id,
		string $occurred_at_utc,
		bool $verified
	): void {
		$this->fail_once_if_requested( 'update_installation' );
		if ( null === $this->installation ) {
			throw new RuntimeException( 'test_missing_installation' );
		}
		$this->installation['schema_version']     = $schema_version;
		$this->installation['schema_fingerprint'] = $schema_fingerprint;
		$this->installation['state']              = $state;
		$this->installation['updated_by_user_id'] = $actor_user_id;
		$this->installation['updated_at_utc']     = $occurred_at_utc;
		$this->installation['lock_version']       = (int) $this->installation['lock_version'] + 1;
		if ( $verified ) {
			$this->installation['last_verified_at_utc'] = $occurred_at_utc;
		}
	}

	/**
	 * Start migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @param string               $started_at_utc Started at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function start_migration(
		Migration_Definition $migration,
		array $checkpoint,
		string $started_at_utc
	): void {
		$this->fail_once_if_requested( 'start_migration' );
		if ( isset( $this->migrations[ $migration->id() ] ) ) {
			throw new RuntimeException( 'test_duplicate_migration' );
		}
		$this->migrations[ $migration->id() ] = array_merge(
			array(
				'migration_id'     => $migration->id(),
				'checksum'         => $migration->checksum(),
				'state'            => Migration_Ledger_State::RUNNING,
				'attempt_count'    => 1,
				'started_at_utc'   => $started_at_utc,
				'completed_at_utc' => null,
				'error_code'       => null,
				'checkpoint'       => $checkpoint,
			),
			Schema_Migration_Checkpoint::columns( $checkpoint )
		);
	}

	/**
	 * Resume migration.
	 *
	 * @param Migration_Definition $migration Migration.
	 * @param int                  $attempt_count Attempt count.
	 * @param array<string,mixed>  $checkpoint Checkpoint.
	 * @param string               $started_at_utc Started at utc.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function resume_migration(
		Migration_Definition $migration,
		int $attempt_count,
		array $checkpoint,
		string $started_at_utc
	): void {
		$this->fail_once_if_requested( 'resume_migration' );
		if ( ! isset( $this->migrations[ $migration->id() ] ) ) {
			throw new RuntimeException( 'test_missing_migration' );
		}
		$this->migrations[ $migration->id() ]['state']            = Migration_Ledger_State::RUNNING;
		$this->migrations[ $migration->id() ]['attempt_count']    = $attempt_count;
		$this->migrations[ $migration->id() ]['started_at_utc']   = $started_at_utc;
		$this->migrations[ $migration->id() ]['completed_at_utc'] = null;
		$this->migrations[ $migration->id() ]['error_code']       = null;
		$this->set_checkpoint( $migration->id(), $checkpoint );
	}

	/**
	 * Save checkpoint.
	 *
	 * @param string              $migration_id Migration id.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return void
	 */
	public function save_checkpoint( string $migration_id, array $checkpoint ): void {
		$this->fail_once_if_requested( 'save_checkpoint' );
		$this->set_checkpoint( $migration_id, $checkpoint );
	}

	/**
	 * Finish migration.
	 *
	 * @param string              $migration_id Migration id.
	 * @param string              $state State.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string|null         $error_code Error code.
	 * @param string              $completed_at_utc Completed at utc.
	 * @return void
	 */
	public function finish_migration(
		string $migration_id,
		string $state,
		array $checkpoint,
		?string $error_code,
		string $completed_at_utc
	): void {
		$this->fail_once_if_requested( 'finish_migration' );
		$this->migrations[ $migration_id ]['state']            = $state;
		$this->migrations[ $migration_id ]['error_code']       = $error_code;
		$this->migrations[ $migration_id ]['completed_at_utc'] = $completed_at_utc;
		$this->set_checkpoint( $migration_id, $checkpoint );
	}

	/**
	 * Set checkpoint.
	 *
	 * @param string              $migration_id Migration id.
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @return void
	 */
	private function set_checkpoint( string $migration_id, array $checkpoint ): void {
		$this->migrations[ $migration_id ]['checkpoint'] = $checkpoint;
		foreach ( Schema_Migration_Checkpoint::columns( $checkpoint ) as $field => $value ) {
			$this->migrations[ $migration_id ][ $field ] = $value;
		}
	}

	/**
	 * Fail once if requested.
	 *
	 * @param string $operation Operation.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function fail_once_if_requested( string $operation ): void {
		if ( $operation !== $this->fail_once_at_operation ) {
			return;
		}
		$this->fail_once_at_operation = null;
		throw new RuntimeException( 'simulated_' . $operation . '_failure' );
	}

	/**
	 * Drift metadata if requested.
	 *
	 * @param string $table_name Table name.
	 * @return void
	 */
	private function drift_metadata_if_requested( string $table_name ): void {
		if ( null === $this->drift_after_attempt || $this->attempt_count !== $this->drift_after_attempt ) {
			return;
		}
		$this->tables[ $table_name ]['engine'] = 'MyISAM';
	}
}

/**
 * G1 database infrastructure adapter contract.
 */
final class Database_Infrastructure_Test_Lock implements Schema_Lock_Port_Interface {
	/**
	 * Available.
	 *
	 * @var bool
	 */
	public bool $available = true;
	/**
	 * Acquisitions.
	 *
	 * @var int
	 */
	public int $acquisitions = 0;
	/**
	 * Releases.
	 *
	 * @var int
	 */
	public int $releases = 0;

	/**
	 * Acquire.
	 *
	 * @param string $name Name.
	 * @param int    $timeout_seconds Timeout seconds.
	 * @return bool
	 */
	public function acquire( string $name, int $timeout_seconds ): bool {
		database_infrastructure_assert( $name === Migration_Plan::LOCK_NAME, 'The runner used the wrong named lock.' );
		database_infrastructure_assert( $timeout_seconds > 0, 'The schema lock timeout must be bounded and positive.' );
		++$this->acquisitions;

		return $this->available;
	}

	/**
	 * Release.
	 *
	 * @param string $name Name.
	 * @return void
	 */
	public function release( string $name ): void {
		database_infrastructure_assert( $name === Migration_Plan::LOCK_NAME, 'The runner released the wrong named lock.' );
		++$this->releases;
	}
}

/**
 * G1 database infrastructure adapter contract.
 */
final class Database_Infrastructure_Test_Wpdb {
	/**
	 * Prepared.
	 *
	 * @var array<int,array{sql:string,args:array<int,mixed>}>
	 */
	public array $prepared = array();

	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$args ): string {
		$this->prepared[] = array(
			'sql'  => $sql,
			'args' => $args,
		);

		return $sql . ' /* prepared */';
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function get_var( string $sql ): int {
		$this->queries[] = $sql;

		return 1;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 */
	public function query( string $sql ): int {
		$this->queries[] = $sql;

		return 1;
	}
}

/**
 * Database infrastructure runner.
 *
 * @param Schema_Catalog                     $catalog Catalog.
 * @param Database_Infrastructure_Test_Store $store Store.
 * @return array{0:Schema_Installation_Runner,1:Database_Infrastructure_Test_Lock}
 */
function database_infrastructure_runner(
	Schema_Catalog $catalog,
	Database_Infrastructure_Test_Store $store
): array {
	$plan     = new Migration_Plan( $catalog );
	$lock     = new Database_Infrastructure_Test_Lock();
	$verifier = new Schema_Postcondition_Verifier( $catalog, $store );
	$clock    = static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-08-14 12:00:00', new DateTimeZone( 'UTC' ) );

	return array(
		new Schema_Installation_Runner(
			$catalog,
			$plan,
			new Schema_Preflight( $catalog ),
			$store,
			$lock,
			$store,
			$store,
			$verifier,
			$clock
		),
		$lock,
	);
}

/**
 * Database infrastructure authorization.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @param string         $correlation_id Request correlation identifier for diagnostics.
 * @return Schema_Repair_Authorization
 */
function database_infrastructure_authorization( Schema_Catalog $catalog, string $correlation_id ): Schema_Repair_Authorization {
	return new Schema_Repair_Authorization(
		7,
		$correlation_id,
		hash( 'sha256', 'approval' ),
		hash( 'sha256', 'backup-evidence' ),
		$catalog->fingerprint(),
		new DateTimeImmutable( '2026-08-14 11:00:00', new DateTimeZone( 'UTC' ) )
	);
}

/**
 * Database infrastructure applied record.
 *
 * @param Migration_Definition $migration Migration.
 * @param string               $correlation_id Request correlation identifier for diagnostics.
 * @return array<string,mixed>
 */
function database_infrastructure_applied_record(
	Migration_Definition $migration,
	string $correlation_id
): array {
	$checkpoint = array(
		'phase'            => 'completed',
		'cursor'           => count( $migration->table_names() ),
		'completed_tables' => $migration->table_names(),
		'next_table'       => null,
		'postcondition'    => 'passed',
		'correlation_id'   => $correlation_id,
		'repair'           => false,
	);

	return array_merge(
		array(
			'migration_id'  => $migration->id(),
			'checksum'      => $migration->checksum(),
			'state'         => Migration_Ledger_State::APPLIED,
			'attempt_count' => 1,
			'checkpoint'    => $checkpoint,
		),
		Schema_Migration_Checkpoint::columns( $checkpoint )
	);
}

$catalog = new Schema_Catalog();
foreach ( $catalog->tables() as $table ) {
	$metadata = Schema_Metadata_Contract::expected( $table );
	database_infrastructure_assert( isset( $metadata['columns']['id'] ), 'Every expected metadata contract requires its PK.' );
}

$fresh_store                   = new Database_Infrastructure_Test_Store( $catalog );
[ $fresh_runner, $fresh_lock ] = database_infrastructure_runner( $catalog, $fresh_store );
$installed                     = $fresh_runner->install( 7, 'install-20260814-001' );
database_infrastructure_assert( $installed->status() === Schema_Installation_Result::INSTALLED, 'Fresh install did not complete.' );
database_infrastructure_assert( 48 === ( count( $fresh_store->tables ) ), 'Fresh install must create all 48 tables.' );
database_infrastructure_assert( 51 === ( count( $fresh_store->statements ) ), 'Fresh install must execute 48 CREATE statements and three ALTER statements.' );
database_infrastructure_assert( 51 === ( count( array_unique( $fresh_store->statements ) ) ), 'Fresh install repeated a DDL statement.' );
database_infrastructure_assert(
	$fresh_store->installation['state'] === Installation_State::REGISTRATION_PENDING,
	'Installation must stop at registration_pending.'
);
database_infrastructure_assert( 1 === ( $fresh_lock->acquisitions ) && 1 === ( $fresh_lock->releases ), 'Fresh install must release its lock.' );
foreach ( ( new Migration_Plan( $catalog ) )->migrations() as $migration ) {
	database_infrastructure_assert(
		$fresh_store->migrations[ $migration->id() ]['state'] === Migration_Ledger_State::APPLIED,
		'Every migration must finish applied.'
	);
}

$statement_count = count( $fresh_store->statements );
$already_ready   = $fresh_runner->install( 7, 'install-20260814-002' );
database_infrastructure_assert( $already_ready->status() === Schema_Installation_Result::ALREADY_READY, 'Ready schema must be idempotent.' );
database_infrastructure_assert( count( $fresh_store->statements ) === $statement_count, 'Ready schema must not execute DDL.' );
database_infrastructure_assert( 1 === ( $fresh_lock->acquisitions ), 'Read-only ready detection should not acquire the DDL lock.' );

$baseline_store = new Database_Infrastructure_Test_Store( $catalog );
$baseline_plan  = new Migration_Plan( $catalog );
foreach ( $baseline_plan->baseline_tables() as $table_name => $definition ) {
	$baseline_store->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definition );
}
$baseline_store->installation = array(
	'addon_id'             => Schema_Catalog::ADDON_ID,
	'owner_marker'         => $catalog->owner_marker(),
	'schema_version'       => Migration_Plan::BASELINE_SCHEMA_VERSION,
	'schema_fingerprint'   => Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	'state'                => Installation_State::RUNTIME_READY,
	'lock_version'         => 1,
	'created_by_user_id'   => 7,
	'created_at_utc'       => '2026-08-14 12:00:00',
	'updated_by_user_id'   => 7,
	'updated_at_utc'       => '2026-08-14 12:00:00',
	'last_verified_at_utc' => '2026-08-14 12:00:00',
);
foreach ( array_slice( $baseline_plan->migrations(), 0, 2 ) as $migration ) {
	$baseline_store->migrations[ $migration->id() ] = database_infrastructure_applied_record( $migration, 'fixture:baseline-applied' );
}
[ $baseline_runner ] = database_infrastructure_runner( $catalog, $baseline_store );
$upgraded            = $baseline_runner->install( 7, 'upgrade-20260822-001' );
database_infrastructure_assert( $upgraded->status() === Schema_Installation_Result::UPGRADED, 'The owned 1.0.0 baseline must upgrade normally.' );
database_infrastructure_assert( $upgraded->succeeded(), 'The upgraded result must be successful.' );
database_infrastructure_assert( 6 === ( count( $baseline_store->statements ) ), 'Baseline upgrade must execute three ALTER and three CREATE statements.' );
database_infrastructure_assert( str_starts_with( $baseline_store->statements[0], 'ALTER TABLE `oj_ai_export_jobs`' ), 'The 1.0.1 baseline delta targeted the wrong table.' );
database_infrastructure_assert( str_starts_with( $baseline_store->statements[1], 'ALTER TABLE `oj_ai_submission_evaluations`' ), 'The 1.0.2 baseline delta targeted the wrong table.' );
database_infrastructure_assert( str_starts_with( $baseline_store->statements[2], 'ALTER TABLE `oj_ai_evaluation_runs`' ), 'The 1.0.3 baseline delta targeted the wrong table.' );
database_infrastructure_assert( $baseline_store->installation['schema_version'] === Schema_Catalog::SCHEMA_VERSION, 'Upgrade did not store the target version.' );
database_infrastructure_assert( $baseline_store->installation['schema_fingerprint'] === $catalog->fingerprint(), 'Upgrade did not store the target fingerprint.' );
$forward_migration  = $baseline_plan->migrations()[2];
$latest_migration   = $baseline_plan->migrations()[3];
$latest_migration_2 = $baseline_plan->migrations()[4];
database_infrastructure_assert( $baseline_store->migrations[ $forward_migration->id() ]['state'] === Migration_Ledger_State::APPLIED, 'The 1.0.1 migration did not finish applied.' );
database_infrastructure_assert( $baseline_store->migrations[ $latest_migration->id() ]['state'] === Migration_Ledger_State::APPLIED, 'The 1.0.2 migration did not finish applied.' );
database_infrastructure_assert( $baseline_store->migrations[ $latest_migration_2->id() ]['state'] === Migration_Ledger_State::APPLIED, 'The 1.0.3 migration did not finish applied.' );

$previous_store = new Database_Infrastructure_Test_Store( $catalog );
foreach ( $baseline_plan->previous_tables() as $table_name => $definition ) {
	$previous_store->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definition );
}
$previous_store->installation = array(
	'addon_id'             => Schema_Catalog::ADDON_ID,
	'owner_marker'         => $catalog->owner_marker(),
	'schema_version'       => Migration_Plan::PREVIOUS_SCHEMA_VERSION,
	'schema_fingerprint'   => Migration_Plan::PREVIOUS_SCHEMA_FINGERPRINT,
	'state'                => Installation_State::RUNTIME_READY,
	'lock_version'         => 1,
	'created_by_user_id'   => 7,
	'created_at_utc'       => '2026-08-22 12:00:00',
	'updated_by_user_id'   => 7,
	'updated_at_utc'       => '2026-08-22 12:00:00',
	'last_verified_at_utc' => '2026-08-22 12:00:00',
);
foreach ( array_slice( $baseline_plan->migrations(), 0, 3 ) as $migration ) {
	$previous_store->migrations[ $migration->id() ] = database_infrastructure_applied_record( $migration, 'fixture:previous-applied' );
}
[ $previous_runner ] = database_infrastructure_runner( $catalog, $previous_store );
$previous_upgraded   = $previous_runner->install( 7, 'upgrade-20260823-001' );
database_infrastructure_assert( $previous_upgraded->status() === Schema_Installation_Result::UPGRADED, 'The owned 1.0.1 schema must upgrade normally.' );
database_infrastructure_assert( 5 === ( count( $previous_store->statements ) ), 'The 1.0.1 upgrade must execute two ALTER and three CREATE statements.' );
database_infrastructure_assert( str_starts_with( $previous_store->statements[0], 'ALTER TABLE `oj_ai_submission_evaluations`' ), 'The 1.0.1 upgrade targeted the wrong table.' );
database_infrastructure_assert( str_starts_with( $previous_store->statements[1], 'ALTER TABLE `oj_ai_evaluation_runs`' ), 'The 1.0.1 upgrade targeted the wrong table for the error message delta.' );
database_infrastructure_assert( $previous_store->installation['schema_version'] === Schema_Catalog::SCHEMA_VERSION, 'The 1.0.1 upgrade did not store the target version.' );
database_infrastructure_assert( $previous_store->migrations[ $latest_migration->id() ]['state'] === Migration_Ledger_State::APPLIED, 'The latest migration did not finish applied from 1.0.1.' );
database_infrastructure_assert( $previous_store->migrations[ $latest_migration_2->id() ]['state'] === Migration_Ledger_State::APPLIED, 'The newest migration did not finish applied from 1.0.1.' );

$previous_2_store = new Database_Infrastructure_Test_Store( $catalog );
foreach ( $baseline_plan->previous_2_tables() as $table_name => $definition ) {
	$previous_2_store->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definition );
}
$previous_2_store->installation = array(
	'addon_id'             => Schema_Catalog::ADDON_ID,
	'owner_marker'         => $catalog->owner_marker(),
	'schema_version'       => Migration_Plan::PREVIOUS_2_SCHEMA_VERSION,
	'schema_fingerprint'   => Migration_Plan::PREVIOUS_2_SCHEMA_FINGERPRINT,
	'state'                => Installation_State::RUNTIME_READY,
	'lock_version'         => 1,
	'created_by_user_id'   => 7,
	'created_at_utc'       => '2026-08-29 12:00:00',
	'updated_by_user_id'   => 7,
	'updated_at_utc'       => '2026-08-29 12:00:00',
	'last_verified_at_utc' => '2026-08-29 12:00:00',
);
foreach ( array_slice( $baseline_plan->migrations(), 0, 4 ) as $migration ) {
	$previous_2_store->migrations[ $migration->id() ] = database_infrastructure_applied_record( $migration, 'fixture:previous-2-applied' );
}
[ $previous_2_runner ] = database_infrastructure_runner( $catalog, $previous_2_store );
$previous_2_upgraded   = $previous_2_runner->install( 7, 'upgrade-20260829-001' );
database_infrastructure_assert( $previous_2_upgraded->status() === Schema_Installation_Result::UPGRADED, 'The owned 1.0.2 schema must upgrade normally.' );
database_infrastructure_assert( 4 === ( count( $previous_2_store->statements ) ), 'The 1.0.2 upgrade must execute one ALTER and three CREATE statements.' );
database_infrastructure_assert( str_starts_with( $previous_2_store->statements[0], 'ALTER TABLE `oj_ai_evaluation_runs`' ), 'The 1.0.2 upgrade targeted the wrong table.' );
database_infrastructure_assert( $previous_2_store->installation['schema_version'] === Schema_Catalog::SCHEMA_VERSION, 'The 1.0.2 upgrade did not store the target version.' );
database_infrastructure_assert( $previous_2_store->migrations[ $latest_migration_2->id() ]['state'] === Migration_Ledger_State::APPLIED, 'The newest migration did not finish applied from 1.0.2.' );

$previous_3_store = new Database_Infrastructure_Test_Store( $catalog );
foreach ( $baseline_plan->previous_3_tables() as $name => $definition ) {
	$previous_3_store->tables[ $name ] = Schema_Metadata_Contract::expected( $definition );
}
$previous_3_store->installation = array_replace(
	$previous_2_store->installation,
	array(
		'schema_version'     => Migration_Plan::PREVIOUS_3_SCHEMA_VERSION,
		'schema_fingerprint' => Migration_Plan::PREVIOUS_3_SCHEMA_FINGERPRINT,
		'state'              => Installation_State::RUNTIME_READY,
	)
);
foreach ( array_slice( $baseline_plan->migrations(), 0, 5 ) as $migration ) {
	$previous_3_store->migrations[ $migration->id() ] = database_infrastructure_applied_record( $migration, 'fixture:1.0.3' );
}
$old_ledger            = $previous_3_store->migrations;
[ $previous_3_runner ] = database_infrastructure_runner( $catalog, $previous_3_store );
$previous_3_upgraded   = $previous_3_runner->install( 7, 'upgrade-assessment-projects' );
database_infrastructure_assert( $previous_3_upgraded->status() === Schema_Installation_Result::UPGRADED, 'The owned 1.0.3 schema must upgrade normally.' );
database_infrastructure_assert( 3 === ( count( $previous_3_store->statements ) ), 'The 1.0.3 upgrade must create only three new tables.' );
foreach ( $previous_3_store->statements as $sql ) {
	database_infrastructure_assert( str_starts_with( $sql, 'CREATE TABLE `oj_ai_assessment_project' ), 'The upgrade must not rewrite existing tables.' );
}
database_infrastructure_assert( array_intersect_key( $previous_3_store->migrations, $old_ledger ) === $old_ledger, 'Existing migration receipts changed.' );

$failed_store                    = new Database_Infrastructure_Test_Store( $catalog );
$failed_store->fail_at_attempt   = 7;
[ $failed_runner, $failed_lock ] = database_infrastructure_runner( $catalog, $failed_store );
$failed                          = $failed_runner->install( 7, 'install-20260814-003' );
database_infrastructure_assert( $failed->status() === Schema_Installation_Result::REPAIR_REQUIRED, 'Partial DDL must require repair.' );
database_infrastructure_assert(
	$failed_store->installation['state'] === Installation_State::MAINTENANCE_REQUIRED,
	'Partial DDL must put the installation in maintenance.'
);
$target_migration = ( new Migration_Plan( $catalog ) )->migrations()[1];
database_infrastructure_assert(
	$failed_store->migrations[ $target_migration->id() ]['state'] === Migration_Ledger_State::REPAIR_REQUIRED,
	'Failed migration must retain repair_required state.'
);
database_infrastructure_assert( 1 === ( $failed_lock->acquisitions ) && 1 === ( $failed_lock->releases ), 'Failure must release the named lock.' );

$successful_before_retry = count( $failed_store->statements );
$normal_retry            = $failed_runner->install( 7, 'install-20260814-004' );
database_infrastructure_assert( $normal_retry->status() === Schema_Installation_Result::BLOCKED, 'Normal retry must not auto-repair.' );
database_infrastructure_assert( count( $failed_store->statements ) === $successful_before_retry, 'Blocked retry must not execute DDL.' );

$stale_authorization = new Schema_Repair_Authorization(
	7,
	'repair-20260814-stale',
	hash( 'sha256', 'stale-approval' ),
	hash( 'sha256', 'stale-backup' ),
	$catalog->fingerprint(),
	new DateTimeImmutable( '2026-08-12 11:00:00', new DateTimeZone( 'UTC' ) )
);
$stale               = $failed_runner->repair( $stale_authorization );
database_infrastructure_assert( $stale->status() === Schema_Installation_Result::BLOCKED, 'Stale backup evidence must block repair.' );

$failed_store->fail_at_attempt = null;
$repaired                      = $failed_runner->repair( database_infrastructure_authorization( $catalog, 'repair-20260814-001' ) );
database_infrastructure_assert( $repaired->status() === Schema_Installation_Result::REPAIRED, 'Approved repair did not complete.' );
database_infrastructure_assert( 48 === ( count( $failed_store->tables ) ), 'Repair must finish all missing tables.' );
database_infrastructure_assert( 51 === ( count( $failed_store->statements ) ), 'Repair must finish missing CREATE statements and all forward ALTER statements once.' );
database_infrastructure_assert(
	51 === ( count( array_unique( $failed_store->statements ) ) ),
	'Repair must execute each successful forward statement at most once.'
);
database_infrastructure_assert(
	$failed_store->installation['state'] === Installation_State::REGISTRATION_PENDING,
	'Repair must return to registration_pending.'
);
$repair_checkpoint = $failed_store->migrations[ $target_migration->id() ]['checkpoint'];
database_infrastructure_assert( true === ( $repair_checkpoint['repair'] ), 'Repair checkpoint must identify repair mode.' );
database_infrastructure_assert(
	$repair_checkpoint['backup_evidence_hash'] === hash( 'sha256', 'backup-evidence' ),
	'Repair checkpoint must retain only the backup evidence digest.'
);

$drift_store                  = new Database_Infrastructure_Test_Store( $catalog );
$drift_store->fail_at_attempt = 7;
[ $drift_runner ]             = database_infrastructure_runner( $catalog, $drift_store );
$drift_runner->install( 7, 'install-20260814-005' );
$drift_table                                   = array_keys( $drift_store->tables )[0];
$drift_store->tables[ $drift_table ]['engine'] = 'MyISAM';
$drift_store->fail_at_attempt                  = null;
$drift_repair                                  = $drift_runner->repair( database_infrastructure_authorization( $catalog, 'repair-20260814-002' ) );
database_infrastructure_assert( $drift_repair->status() === Schema_Installation_Result::BLOCKED, 'Drifted existing tables must not be adopted.' );

$checksum_store      = new Database_Infrastructure_Test_Store( $catalog );
[ $checksum_runner ] = database_infrastructure_runner( $catalog, $checksum_store );
$checksum_runner->install( 7, 'install-20260814-006' );
$first_migration_id = ( new Migration_Plan( $catalog ) )->migrations()[0]->id();
$checksum_store->migrations[ $first_migration_id ]['checksum'] = str_repeat( '0', 64 );
$checksum_result = $checksum_runner->install( 7, 'install-20260814-007' );
database_infrastructure_assert( $checksum_result->status() === Schema_Installation_Result::BLOCKED, 'Checksum drift must fail closed.' );
database_infrastructure_assert( 'schema_explicit_repair_required' === ( $checksum_result->code() ), 'Checksum drift needs a stable repair gate.' );

$locked_store                         = new Database_Infrastructure_Test_Store( $catalog );
[ $locked_runner, $unavailable_lock ] = database_infrastructure_runner( $catalog, $locked_store );
$unavailable_lock->available          = false;
$locked                               = $locked_runner->install( 7, 'install-20260814-008' );
database_infrastructure_assert( $locked->status() === Schema_Installation_Result::LOCK_UNAVAILABLE, 'Lock timeout must not start DDL.' );
database_infrastructure_assert( array() === $locked_store->statements, 'Unavailable lock must leave the schema untouched.' );

$first_failure_store                  = new Database_Infrastructure_Test_Store( $catalog );
$first_failure_store->fail_at_attempt = 1;
[ $first_failure_runner ]             = database_infrastructure_runner( $catalog, $first_failure_store );
$first_failure                        = $first_failure_runner->install( 7, 'install-20260814-009' );
database_infrastructure_assert( $first_failure->status() === Schema_Installation_Result::FAILED, 'A failure before any DDL should remain retryable.' );
database_infrastructure_assert( array() === $first_failure_store->tables, 'A first-statement failure must not claim ownership.' );

$wpdb       = new Database_Infrastructure_Test_Wpdb();
$named_lock = new WordPress_Schema_Lock( $wpdb );
database_infrastructure_assert( $named_lock->acquire( Migration_Plan::LOCK_NAME, 5 ), 'WordPress named lock adapter failed.' );
$named_lock->release( Migration_Plan::LOCK_NAME );
database_infrastructure_assert( str_contains( $wpdb->prepared[0]['sql'], 'GET_LOCK' ), 'Named lock must use GET_LOCK.' );
database_infrastructure_assert( $wpdb->prepared[0]['args'][0] === Migration_Plan::LOCK_NAME, 'Named lock value changed.' );
database_infrastructure_assert( str_contains( $wpdb->prepared[1]['sql'], 'RELEASE_LOCK' ), 'Named lock must be explicitly released.' );

$statement_executor = new WordPress_Schema_Statement_Executor( $wpdb );
$first_definition   = array_values( $catalog->tables() )[0];
$statement_executor->execute( $first_definition->create_sql() );
$rejected_prefixed = false;
try {
	$statement_executor->execute( 'CREATE TABLE `wp_oj_ai_forbidden` (`id` BIGINT NOT NULL)' );
} catch ( InvalidArgumentException ) {
	$rejected_prefixed = true;
}
database_infrastructure_assert( $rejected_prefixed, 'The statement executor accepted a prefixed table.' );
$rejected_destructive = false;
try {
	$statement_executor->execute( 'ALTER TABLE `oj_ai_installations` DROP COLUMN `state`' );
} catch ( InvalidArgumentException ) {
	$rejected_destructive = true;
}
database_infrastructure_assert( $rejected_destructive, 'The statement executor accepted destructive DDL.' );

$infrastructure_root = $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Database'
	. DIRECTORY_SEPARATOR . 'Foundation' . DIRECTORY_SEPARATOR . 'Infrastructure';
$source              = '';
foreach ( (array) glob( $infrastructure_root . DIRECTORY_SEPARATOR . '*.php' ) as $file ) {
	$contents = file_get_contents( $file );
	$source  .= is_string( $contents ) ? $contents : '';
}
database_infrastructure_assert( ! str_contains( $source, 'base_prefix' ), 'Infrastructure adapters may not use a site base prefix.' );
database_infrastructure_assert( ! str_contains( $source, 'dbDelta(' ), 'Infrastructure adapters may not use dbDelta as completion evidence.' );
database_infrastructure_assert( ! str_contains( $source, 'wp_oj_ai_' ), 'Infrastructure adapters may not contain prefixed target candidates.' );

echo 'G1 database infrastructure contract passed: fresh 1.0.4 install, supported upgrades through 1.0.3, lock, ledger, partial failure, explicit repair.' . PHP_EOL;
