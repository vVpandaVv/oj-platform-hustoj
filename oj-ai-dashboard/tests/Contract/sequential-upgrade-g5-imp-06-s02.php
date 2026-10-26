<?php
/**
 * G5-IMP-06-S02 sequential schema upgrade contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Activation_Workflow;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Migration_Ledger_State;
use OJAI\Dashboard\Database\Foundation\Migration_Plan;
use OJAI\Dashboard\Database\Foundation\Port\Schema_Installer_Port_Interface;
use OJAI\Dashboard\Database\Foundation\Schema_Catalog;
use OJAI\Dashboard\Database\Foundation\Schema_Installation_Result;
use OJAI\Dashboard\Database\Foundation\Schema_Metadata_Contract;

$addon_root = dirname( __DIR__, 2 );

ob_start();
require $addon_root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Contract'
	. DIRECTORY_SEPARATOR . 'database-infrastructure.php';
$foundation_output = (string) ob_get_clean();

/**
 * G5 s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @throws RuntimeException When a contract fails.
 * @return void
 */
function g5_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
		);
	}
}

/**
 * G5 s02 store.
 *
 * @param Schema_Catalog                                                    $catalog Catalog.
 * @param array<string,OJAI\Dashboard\Database\Foundation\Table_Definition> $definitions Physical source schema.
 * @param string                                                            $schema_version Schema version.
 * @param string                                                            $schema_fingerprint Schema fingerprint.
 * @param int                                                               $applied_migration_count Applied migration count.
 * @param string                                                            $state State.
 * @return Database_Infrastructure_Test_Store
 */
function g5_s02_store(
	Schema_Catalog $catalog,
	array $definitions,
	string $schema_version,
	string $schema_fingerprint,
	int $applied_migration_count,
	string $state = Installation_State::RUNTIME_READY
): Database_Infrastructure_Test_Store {
	$store = new Database_Infrastructure_Test_Store( $catalog );
	foreach ( $definitions as $table_name => $definition ) {
		$store->tables[ $table_name ] = Schema_Metadata_Contract::expected( $definition );
	}
	$store->installation = array(
		'addon_id'             => Schema_Catalog::ADDON_ID,
		'owner_marker'         => $catalog->owner_marker(),
		'schema_version'       => $schema_version,
		'schema_fingerprint'   => $schema_fingerprint,
		'state'                => $state,
		'lock_version'         => 3,
		'created_by_user_id'   => 19,
		'created_at_utc'       => '2026-08-20 09:00:00',
		'updated_by_user_id'   => 19,
		'updated_at_utc'       => '2026-08-20 09:00:00',
		'last_verified_at_utc' => '2026-08-20 09:00:00',
	);
	$plan                = new Migration_Plan( $catalog );
	foreach ( array_slice( $plan->migrations(), 0, $applied_migration_count ) as $migration ) {
		$record                                = database_infrastructure_applied_record( $migration, 'fixture:sequential-applied' );
		$record['source_evidence']             = 'preserve-me';
		$store->migrations[ $migration->id() ] = $record;
	}

	return $store;
}

/**
 * G5 s02 assert blocked without writes.
 *
 * @param Schema_Catalog                     $catalog Catalog.
 * @param Database_Infrastructure_Test_Store $store Store.
 * @param string                             $correlation_id Request correlation identifier for diagnostics.
 * @param string                             $expected_code Expected code.
 * @return void
 */
function g5_s02_assert_blocked_without_writes(
	Schema_Catalog $catalog,
	Database_Infrastructure_Test_Store $store,
	string $correlation_id,
	string $expected_code
): void {
	$installation_before = $store->installation;
	$migrations_before   = $store->migrations;
	[ $runner, $lock ]   = database_infrastructure_runner( $catalog, $store );
	$result              = $runner->install( 19, $correlation_id );

	g5_s02_same( Schema_Installation_Result::BLOCKED, $result->status(), 'An invalid upgrade source was not blocked.' );
	g5_s02_same( $expected_code, $result->code(), 'The blocked upgrade code changed.' );
	g5_s02_same( 0, $lock->acquisitions, 'A blocked upgrade acquired the DDL lock.' );
	g5_s02_same( array(), $store->statements, 'A blocked upgrade executed DDL.' );
	g5_s02_same( $installation_before, $store->installation, 'A blocked upgrade changed the installation row.' );
	g5_s02_same( $migrations_before, $store->migrations, 'A blocked upgrade changed the migration ledger.' );
}

/**
 * G5-IMP-06-S02 sequential schema upgrade contract.
 */
final class G5_S02_Installer implements Schema_Installer_Port_Interface {
	/**
	 * Events.
	 *
	 * @var array<int,string>
	 */
	private array $events;

	/**
	 * Initialize g5 s02 installer dependencies and state.
	 *
	 * @param Schema_Installation_Result $result Result.
	 * @param array<int,string>          &$events Events.
	 */
	public function __construct( private Schema_Installation_Result $result, array &$events ) {
		$this->events =& $events;
	}

	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result {
		g5_s02_same( 19, $actor_user_id, 'The upgrade actor changed.' );
		g5_s02_same( 'upgrade:activation-20260825', $correlation_id, 'The upgrade correlation ID changed.' );
		$this->events[] = 'schema';

		return $this->result;
	}
}

g5_s02_assert(
	str_contains( $foundation_output, 'fresh 1.0.4 install, supported upgrades through 1.0.3' ),
	'The constituent database infrastructure contract did not run.'
);

$catalog    = new Schema_Catalog();
$plan       = new Migration_Plan( $catalog );
$migrations = $plan->migrations();
g5_s02_same( '1.0.4', Schema_Catalog::SCHEMA_VERSION, 'The target schema version changed without an S02 contract update.' );
g5_s02_same( 6, count( $migrations ), 'The immutable migration count changed.' );
g5_s02_same(
	array(
		'202608140001_bootstrap_schema_ledger',
		'202608140002_create_frozen_schema',
		'202608220001_export_job_context_identity',
		'202608230001_expand_submission_prompt_contract',
		'202608300001_add_evaluation_run_last_error_message',
		'202609070001_assessment_projects',
	),
	array_map( static fn ( $migration ): string => $migration->id(), $migrations ),
	'The sequential migration order changed.'
);

$baseline_store                      = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$baseline_history                    = $baseline_store->migrations;
[ $baseline_runner, $baseline_lock ] = database_infrastructure_runner( $catalog, $baseline_store );
$baseline_result                     = $baseline_runner->install( 19, 'upgrade:1.0.0-20260825' );
g5_s02_same( Schema_Installation_Result::UPGRADED, $baseline_result->status(), 'The exact 1.0.0 source did not upgrade.' );
g5_s02_same(
	array( 'oj_ai_export_jobs', 'oj_ai_submission_evaluations', 'oj_ai_evaluation_runs', 'oj_ai_assessment_projects', 'oj_ai_assessment_project_revisions', 'oj_ai_assessment_project_worksheets' ),
	$baseline_result->completed_tables(),
	'The 1.0.0 upgrade completed the wrong physical targets.'
);
g5_s02_same( 6, count( $baseline_store->statements ), 'The 1.0.0 source must execute three ALTER and three CREATE statements.' );
g5_s02_assert( str_starts_with( $baseline_store->statements[0], 'ALTER TABLE `oj_ai_export_jobs`' ), 'The 1.0.1 delta order changed.' );
g5_s02_assert( str_starts_with( $baseline_store->statements[1], 'ALTER TABLE `oj_ai_submission_evaluations`' ), 'The 1.0.2 delta order changed.' );
g5_s02_assert( str_starts_with( $baseline_store->statements[2], 'ALTER TABLE `oj_ai_evaluation_runs`' ), 'The 1.0.3 delta order changed.' );
g5_s02_same( $baseline_history, array_slice( $baseline_store->migrations, 0, 2, true ), 'The 1.0.0 applied history was rewritten.' );
g5_s02_same( 1, $baseline_lock->acquisitions, 'The 1.0.0 upgrade must acquire one named lock.' );
g5_s02_same( 1, $baseline_lock->releases, 'The 1.0.0 upgrade must release its named lock.' );
g5_s02_same( Schema_Catalog::SCHEMA_VERSION, $baseline_store->installation['schema_version'], 'The target version was not stored.' );
g5_s02_same( $catalog->fingerprint(), $baseline_store->installation['schema_fingerprint'], 'The target fingerprint was not stored.' );
g5_s02_same( Installation_State::REGISTRATION_PENDING, $baseline_store->installation['state'], 'Upgrade must stop at registration_pending.' );
g5_s02_same( 6, count( $baseline_store->migrations ), 'The 1.0.0 upgrade did not finish the six-record ledger.' );

$baseline_statement_count = count( $baseline_store->statements );
$baseline_ready           = $baseline_runner->install( 19, 'upgrade:1.0.2-ready-20260825' );
g5_s02_same( Schema_Installation_Result::ALREADY_READY, $baseline_ready->status(), 'The target schema was not idempotent.' );
g5_s02_same( $baseline_statement_count, count( $baseline_store->statements ), 'Target reactivation executed DDL.' );
g5_s02_same( 1, $baseline_lock->acquisitions, 'Target reactivation acquired the DDL lock.' );

$previous_store                      = g5_s02_store(
	$catalog,
	$plan->previous_tables(),
	Migration_Plan::PREVIOUS_SCHEMA_VERSION,
	Migration_Plan::PREVIOUS_SCHEMA_FINGERPRINT,
	3,
	Installation_State::REGISTRATION_PENDING
);
$previous_history                    = $previous_store->migrations;
[ $previous_runner, $previous_lock ] = database_infrastructure_runner( $catalog, $previous_store );
$previous_result                     = $previous_runner->install( 19, 'upgrade:1.0.1-20260825' );
g5_s02_same( Schema_Installation_Result::UPGRADED, $previous_result->status(), 'The exact 1.0.1 source did not upgrade.' );
g5_s02_same( array( 'oj_ai_submission_evaluations', 'oj_ai_evaluation_runs', 'oj_ai_assessment_projects', 'oj_ai_assessment_project_revisions', 'oj_ai_assessment_project_worksheets' ), $previous_result->completed_tables(), 'The 1.0.1 source applied more than its minimum delta.' );
g5_s02_same( 5, count( $previous_store->statements ), 'The 1.0.1 source must execute two ALTER and three CREATE statements.' );
g5_s02_assert( str_starts_with( $previous_store->statements[0], 'ALTER TABLE `oj_ai_submission_evaluations`' ), 'The 1.0.1 source targeted the wrong table.' );
g5_s02_assert( str_starts_with( $previous_store->statements[1], 'ALTER TABLE `oj_ai_evaluation_runs`' ), 'The 1.0.1 source targeted the wrong table for the error message delta.' );
g5_s02_same( $previous_history, array_slice( $previous_store->migrations, 0, 3, true ), 'The 1.0.1 applied history was rewritten.' );
g5_s02_same( 1, $previous_lock->acquisitions, 'The 1.0.1 upgrade must acquire one named lock.' );
g5_s02_same( 1, $previous_lock->releases, 'The 1.0.1 upgrade must release its named lock.' );

$future_store = g5_s02_store( $catalog, $catalog->tables(), '1.0.5', $catalog->fingerprint(), 6 );
g5_s02_assert_blocked_without_writes( $catalog, $future_store, 'upgrade:future-20260825', 'schema_preflight_blocked' );

$unknown_store = g5_s02_store( $catalog, $plan->baseline_tables(), '0.9.9', Migration_Plan::BASELINE_SCHEMA_FINGERPRINT, 2 );
g5_s02_assert_blocked_without_writes( $catalog, $unknown_store, 'upgrade:unknown-20260825', 'schema_preflight_blocked' );

$fingerprint_store = g5_s02_store( $catalog, $plan->baseline_tables(), '1.0.0', str_repeat( '0', 64 ), 2 );
g5_s02_assert_blocked_without_writes( $catalog, $fingerprint_store, 'upgrade:fingerprint-20260825', 'schema_preflight_blocked' );

$owner_store                               = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$owner_store->installation['owner_marker'] = str_repeat( 'f', 64 );
g5_s02_assert_blocked_without_writes( $catalog, $owner_store, 'upgrade:owner-20260825', 'schema_preflight_blocked' );

$missing_ledger_store = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	1
);
g5_s02_assert_blocked_without_writes( $catalog, $missing_ledger_store, 'upgrade:ledger-missing-20260825', 'schema_migration_ledger_invalid' );

$checksum_store = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$checksum_store->migrations[ $migrations[1]->id() ]['checksum'] = str_repeat( '0', 64 );
g5_s02_assert_blocked_without_writes( $catalog, $checksum_store, 'upgrade:checksum-20260825', 'schema_migration_ledger_invalid' );

$unexpected_forward_store = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$unexpected_forward       = $migrations[2];
$unexpected_forward_store->migrations[ $unexpected_forward->id() ] = database_infrastructure_applied_record( $unexpected_forward, 'fixture:unexpected-forward' );
g5_s02_assert_blocked_without_writes( $catalog, $unexpected_forward_store, 'upgrade:forward-record-20260825', 'schema_migration_ledger_invalid' );

$unknown_migration_store = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$unknown_migration_store->migrations['202608240001_unknown_future_migration'] = array(
	'migration_id'  => '202608240001_unknown_future_migration',
	'checksum'      => str_repeat( 'a', 64 ),
	'state'         => Migration_Ledger_State::APPLIED,
	'attempt_count' => 1,
);
g5_s02_assert_blocked_without_writes( $catalog, $unknown_migration_store, 'upgrade:unknown-record-20260825', 'schema_migration_ledger_invalid' );

$physical_drift_store                                        = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$physical_drift_store->tables['oj_ai_export_jobs']['engine'] = 'MyISAM';
g5_s02_assert_blocked_without_writes( $catalog, $physical_drift_store, 'upgrade:physical-drift-20260825', 'schema_catalog_drift' );

$unexpected_table_store                                    = g5_s02_store(
	$catalog,
	$plan->baseline_tables(),
	Migration_Plan::BASELINE_SCHEMA_VERSION,
	Migration_Plan::BASELINE_SCHEMA_FINGERPRINT,
	2
);
$unexpected_table_store->tables['oj_ai_unknown_extension'] = $unexpected_table_store->tables['oj_ai_installations'];
g5_s02_assert_blocked_without_writes( $catalog, $unexpected_table_store, 'upgrade:unknown-table-20260825', 'schema_preflight_blocked' );

$target_ledger_store = g5_s02_store(
	$catalog,
	$catalog->tables(),
	Schema_Catalog::SCHEMA_VERSION,
	$catalog->fingerprint(),
	3
);
g5_s02_assert_blocked_without_writes( $catalog, $target_ledger_store, 'upgrade:target-ledger-20260825', 'schema_explicit_repair_required' );

$events   = array();
$workflow = new Activation_Workflow(
	new G5_S02_Installer(
		new Schema_Installation_Result(
			Schema_Installation_Result::UPGRADED,
			'schema_upgraded',
			'upgrade:activation-20260825'
		),
		$events
	),
	static function ( string $version ) use ( &$events ): bool {
		$events[] = 'version';
		return '0.1.7' === $version;
	},
	static function ( int $actor_user_id, string $correlation_id ) use ( &$events ): bool {
		$events[] = 'configuration';
		return 19 === $actor_user_id && 'upgrade:activation-20260825' === $correlation_id;
	}
);
g5_s02_same( null, $workflow->run( 19, 'upgrade:activation-20260825', '0.1.7' ), 'Activation rejected a verified sequential upgrade.' );
g5_s02_same( array( 'schema', 'configuration', 'version' ), $events, 'Upgrade activation order changed.' );

$upgrade_sources = '';
foreach ( array(
	$addon_root . '/src/Database/Foundation/Migration_Plan.php',
	$addon_root . '/src/Database/Foundation/Schema_Installation_Runner.php',
	$addon_root . '/src/Database/Foundation/Infrastructure/WordPress_Schema_Inspector.php',
	$addon_root . '/src/Database/Foundation/Infrastructure/WordPress_Schema_Ledger.php',
	$addon_root . '/src/Database/Foundation/Infrastructure/WordPress_Schema_Statement_Executor.php',
) as $source_file ) {
	$upgrade_sources .= (string) file_get_contents( $source_file );
}
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_' ) as $forbidden ) {
	g5_s02_assert( ! str_contains( $upgrade_sources, $forbidden ), 'Sequential upgrade source crossed the literal-table boundary: ' . $forbidden );
}
g5_s02_assert( ! str_contains( $upgrade_sources, 'OJ\\Platform\\' ), 'Sequential upgrade source depends on a platform implementation namespace.' );

echo "G5-IMP-06-S02 sequential upgrade contract passed.\n";
echo "- supported 1.0.0 through 1.0.3 sources -> 1.0.4 paths\n";
echo "- target no-op and unsupported source, ledger, checksum, table, and physical drift fail closed\n";
echo "- literal oj_ai_* DDL only, immutable applied history, ordered activation, and no platform writes\n";
