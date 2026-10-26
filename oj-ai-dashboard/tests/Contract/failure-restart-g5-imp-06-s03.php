<?php
/**
 * G5-IMP-06-S03 schema failure state and deterministic restart contract.
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
use OJAI\Dashboard\Database\Foundation\Schema_Migration_Checkpoint;
use OJAI\Dashboard\Database\Foundation\Schema_Postcondition_Verifier;
use OJAI\Dashboard\Database\Foundation\Schema_Preflight;
use OJAI\Dashboard\Database\Foundation\Schema_Runtime_Readiness_Verifier;

$addon_root = dirname( __DIR__, 2 );

ob_start();
require $addon_root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Contract'
	. DIRECTORY_SEPARATOR . 'database-infrastructure.php';
$foundation_output = (string) ob_get_clean();

/**
 * G5 s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @throws RuntimeException When a contract fails.
 * @return void
 */
function g5_s03_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . '.'
		);
	}
}

/**
 * G5 s03 issue codes.
 *
 * @param Schema_Installation_Result $result Result.
 * @return array<int,string>
 */
function g5_s03_issue_codes( Schema_Installation_Result $result ): array {
	$codes = array();
	foreach ( $result->issues() as $issue ) {
		if ( isset( $issue['code'] ) && is_string( $issue['code'] ) ) {
			$codes[] = $issue['code'];
		}
	}

	return array_values( array_unique( $codes ) );
}

/**
 * G5 s03 create failure fixture.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @param int            $fail_at_attempt Fail at attempt.
 * @return array{0:Database_Infrastructure_Test_Store,1:Schema_Installation_Runner,2:Database_Infrastructure_Test_Lock}
 */
function g5_s03_create_failure_fixture( Schema_Catalog $catalog, int $fail_at_attempt = 7 ): array {
	$store                  = new Database_Infrastructure_Test_Store( $catalog );
	$store->fail_at_attempt = $fail_at_attempt;
	[ $runner, $lock ]      = database_infrastructure_runner( $catalog, $store );

	return array( $store, $runner, $lock );
}

/**
 * G5 s03 assert checkpoint valid.
 *
 * @param mixed               $migration Migration.
 * @param array<string,mixed> $record Record.
 * @param string              $message Message.
 * @return void
 */
function g5_s03_assert_checkpoint_valid( $migration, array $record, string $message ): void {
	g5_s03_same( array(), Schema_Migration_Checkpoint::issues( $migration, $record ), $message );
}

/**
 * G5 s03 assert tamper blocked.
 *
 * @param Schema_Catalog $catalog Catalog.
 * @param callable       $mutator Record mutator.
 * @phpstan-param callable(array<string,mixed>&):void $mutator
 * @param string         $expected_issue Expected issue.
 * @param string         $correlation_id Request correlation identifier for diagnostics.
 * @return void
 */
function g5_s03_assert_tamper_blocked(
	Schema_Catalog $catalog,
	callable $mutator,
	string $expected_issue,
	string $correlation_id
): void {
	[ $store, $runner, $lock ] = g5_s03_create_failure_fixture( $catalog );
	$runner->install( 23, 'install:tamper-seed-' . $correlation_id );
	$migration = ( new Migration_Plan( $catalog ) )->migrations()[1];
	$mutator( $store->migrations[ $migration->id() ] );
	$statements_before = $store->statements;
	$ledger_before     = $store->migrations;
	$locks_before      = $lock->acquisitions;

	$result = $runner->repair(
		database_infrastructure_authorization( $catalog, 'repair:tamper-' . $correlation_id )
	);
	g5_s03_same( Schema_Installation_Result::BLOCKED, $result->status(), 'Tampered checkpoint was not blocked.' );
	g5_s03_same( 'schema_migration_ledger_invalid', $result->code(), 'Tampered checkpoint returned the wrong stable code.' );
	g5_s03_assert( in_array( $expected_issue, g5_s03_issue_codes( $result ), true ), 'Checkpoint diagnostic is missing: ' . $expected_issue );
	g5_s03_same( $locks_before, $lock->acquisitions, 'Tampered checkpoint acquired the DDL lock.' );
	g5_s03_same( $statements_before, $store->statements, 'Tampered checkpoint executed DDL.' );
	g5_s03_same( $ledger_before, $store->migrations, 'Tampered checkpoint rewrote the migration ledger.' );
}

/**
 * G5-IMP-06-S03 schema failure state and deterministic restart contract.
 */
final class G5_S03_Fixed_Installer implements Schema_Installer_Port_Interface {
	/**
	 * Initialize g5 s03 fixed installer dependencies and state.
	 *
	 * @param Schema_Installation_Result $result Result.
	 */
	public function __construct( private Schema_Installation_Result $result ) {
	}

	/**
	 * Install.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return Schema_Installation_Result
	 */
	public function install( int $actor_user_id, string $correlation_id ): Schema_Installation_Result {
		return $this->result;
	}
}

g5_s03_assert(
	str_contains( $foundation_output, 'partial failure, explicit repair' ),
	'The constituent database infrastructure contract did not run.'
);

$catalog = new Schema_Catalog();
$plan    = new Migration_Plan( $catalog );

[ $restart_store, $restart_runner, $restart_lock ] = g5_s03_create_failure_fixture( $catalog );
$initial_failure                                   = $restart_runner->install( 23, 'install:restart-20260825' );
$restart_migration                                 = $plan->migrations()[1];
$restart_record                                    = $restart_store->migrations[ $restart_migration->id() ];
g5_s03_same( Schema_Installation_Result::REPAIR_REQUIRED, $initial_failure->status(), 'Partial CREATE failure must require repair.' );
g5_s03_same( 'simulated_schema_failure', $initial_failure->code(), 'Partial CREATE failure code changed.' );
g5_s03_same( Installation_State::MAINTENANCE_REQUIRED, $restart_store->installation['state'], 'Partial CREATE failure did not freeze maintenance.' );
g5_s03_same( Migration_Ledger_State::REPAIR_REQUIRED, $restart_record['state'], 'Partial CREATE failure did not freeze repair_required.' );
g5_s03_same( 1, $restart_record['attempt_count'], 'Initial failed attempt count changed.' );
g5_s03_assert_checkpoint_valid( $restart_migration, $restart_record, 'Initial failed checkpoint is invalid.' );
g5_s03_same( 1, $restart_lock->acquisitions, 'Initial failure must acquire one named lock.' );
g5_s03_same( 1, $restart_lock->releases, 'Initial failure must release its named lock.' );

$runtime_before_repair = new Schema_Runtime_Readiness_Verifier(
	$catalog,
	$plan,
	new Schema_Preflight( $catalog ),
	$restart_store,
	$restart_store,
	new Schema_Postcondition_Verifier( $catalog, $restart_store )
);
g5_s03_assert( ! $runtime_before_repair->verify()->is_ready(), 'Partial schema exposed Runtime readiness.' );

$before_normal_retry = $restart_store->statements;
$normal_retry        = $restart_runner->install( 23, 'install:normal-retry-20260825' );
g5_s03_same( Schema_Installation_Result::BLOCKED, $normal_retry->status(), 'Normal activation auto-repaired a partial schema.' );
g5_s03_same( 1, $restart_lock->acquisitions, 'Normal retry acquired the DDL lock.' );
g5_s03_same( $before_normal_retry, $restart_store->statements, 'Normal retry executed DDL.' );
g5_s03_assert(
	in_array( 'initial_resume_chain_invalid', array_column( $normal_retry->issues(), 'code' ), true ),
	'Normal retry did not return its safe resume rejection diagnostic.'
);

$activation_events  = array();
$blocked_workflow   = new Activation_Workflow(
	new G5_S03_Fixed_Installer( $initial_failure ),
	static function ( string $version ) use ( &$activation_events ): bool {
		$activation_events[] = 'version';
		return true;
	},
	static function ( int $actor_user_id, string $correlation_id ) use ( &$activation_events ): bool {
		$activation_events[] = 'configuration';
		return true;
	}
);
$activation_problem = $blocked_workflow->run( 23, 'activation:blocked-20260825', '0.1.7' );
g5_s03_same( array(), $activation_events, 'Failed schema continued into configuration or version storage.' );
g5_s03_same( 'simulated_schema_failure', $activation_problem['code'] ?? '', 'Activation hid the stable schema failure code.' );

$restart_store->fail_at_attempt = $restart_store->attempt_count + 1;
$failed_repair                  = $restart_runner->repair(
	database_infrastructure_authorization( $catalog, 'repair:retry-fails-20260825' )
);
g5_s03_same( Schema_Installation_Result::REPAIR_REQUIRED, $failed_repair->status(), 'A failed repair attempt lost repair_required state.' );
$failed_repair_record = $restart_store->migrations[ $restart_migration->id() ];
g5_s03_same( 2, $failed_repair_record['attempt_count'], 'A failed repair did not increment attempt exactly once.' );
g5_s03_assert_checkpoint_valid( $restart_migration, $failed_repair_record, 'Failed repair checkpoint is invalid.' );
g5_s03_assert( true === $failed_repair_record['checkpoint']['repair'], 'Failed repair checkpoint lost repair mode.' );
g5_s03_same( 2, $restart_lock->acquisitions, 'Failed repair acquired the wrong lock count.' );
g5_s03_same( 2, $restart_lock->releases, 'Failed repair did not release the named lock.' );

$restart_store->fail_at_attempt = null;
$repaired                       = $restart_runner->repair(
	database_infrastructure_authorization( $catalog, 'repair:restart-success-20260825' )
);
g5_s03_same( Schema_Installation_Result::REPAIRED, $repaired->status(), 'Authorized restart did not complete.' );
g5_s03_same( 3, $restart_store->migrations[ $restart_migration->id() ]['attempt_count'], 'Successful restart did not increment attempt exactly once.' );
g5_s03_same( 51, count( $restart_store->statements ), 'Restart did not finish the exact 48 CREATE and three ALTER statements.' );
g5_s03_same( 51, count( array_unique( $restart_store->statements ) ), 'Restart repeated a forward DDL statement.' );
g5_s03_same( 3, $restart_lock->acquisitions, 'Restart acquired the wrong number of named locks.' );
g5_s03_same( 3, $restart_lock->releases, 'Restart did not release every named lock.' );
g5_s03_same( Installation_State::REGISTRATION_PENDING, $restart_store->installation['state'], 'Restart did not return to registration_pending.' );
foreach ( $plan->migrations() as $migration ) {
	g5_s03_assert_checkpoint_valid( $migration, $restart_store->migrations[ $migration->id() ], 'Applied restart checkpoint is invalid.' );
}
g5_s03_assert( $runtime_before_repair->verify()->is_ready(), 'Repaired exact schema did not become Runtime ready.' );

$tamper_cases = array(
	array(
		'schema_checkpoint_missing',
		'missing-20260825',
		static function ( array &$record ): void {
			unset( $record['checkpoint_json'] );
		},
	),
	array(
		'schema_checkpoint_schema_invalid',
		'schema-20260825',
		static function ( array &$record ): void {
			$record['checkpoint_schema'] = 'oj_ai.unknown_checkpoint';
		},
	),
	array(
		'schema_checkpoint_version_invalid',
		'version-20260825',
		static function ( array &$record ): void {
			$record['checkpoint_version'] = 2;
		},
	),
	array(
		'schema_checkpoint_hash_mismatch',
		'hash-20260825',
		static function ( array &$record ): void {
			$record['checkpoint_hash'] = str_repeat( '0', 64 );
		},
	),
	array(
		'schema_checkpoint_json_invalid',
		'json-20260825',
		static function ( array &$record ): void {
			$record['checkpoint_json'] = '{';
			$record['checkpoint_hash'] = hash( 'sha256', '{' );
		},
	),
	array(
		'schema_checkpoint_json_noncanonical',
		'canonical-20260825',
		static function ( array &$record ): void {
			$record['checkpoint_json'] = ' ' . $record['checkpoint_json'];
			$record['checkpoint_hash'] = hash( 'sha256', $record['checkpoint_json'] );
		},
	),
	array(
		'schema_checkpoint_payload_invalid',
		'payload-20260825',
		static function ( array &$record ): void {
			$checkpoint = json_decode( (string) $record['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR );
			$checkpoint['cursor'] = 999;
			foreach ( Schema_Migration_Checkpoint::columns( $checkpoint ) as $field => $value ) {
				$record[ $field ] = $value;
			}
		},
	),
);
foreach ( $tamper_cases as [ $expected_issue, $correlation_id, $mutator ] ) {
	g5_s03_assert_tamper_blocked( $catalog, $mutator, $expected_issue, $correlation_id );
}

foreach ( array( 'save_checkpoint', 'finish_migration', 'update_installation' ) as $operation ) {
	$operation_store                         = new Database_Infrastructure_Test_Store( $catalog );
	$operation_store->fail_once_at_operation = $operation;
	[ $operation_runner, $operation_lock ]   = database_infrastructure_runner( $catalog, $operation_store );
	$operation_failure                       = $operation_runner->install( 23, 'install:' . $operation . '-20260825' );
	g5_s03_same( Schema_Installation_Result::REPAIR_REQUIRED, $operation_failure->status(), 'Failure point did not require repair: ' . $operation );
	g5_s03_same( 'simulated_' . $operation . '_failure', $operation_failure->code(), 'Failure point code changed: ' . $operation );
	g5_s03_same( Installation_State::MAINTENANCE_REQUIRED, $operation_store->installation['state'], 'Failure point did not freeze maintenance: ' . $operation );
	g5_s03_same( 1, $operation_lock->acquisitions, 'Failure point acquired the wrong lock count: ' . $operation );
	g5_s03_same( 1, $operation_lock->releases, 'Failure point did not release the lock: ' . $operation );

	$operation_repaired = $operation_runner->repair(
		database_infrastructure_authorization( $catalog, 'repair:' . $operation . '-20260825' )
	);
	g5_s03_same( Schema_Installation_Result::REPAIRED, $operation_repaired->status(), 'Failure point did not resume: ' . $operation );
	g5_s03_same( 51, count( $operation_store->statements ), 'Failure point did not finish exact forward DDL: ' . $operation );
	g5_s03_same( 51, count( array_unique( $operation_store->statements ) ), 'Failure point repeated DDL: ' . $operation );
	g5_s03_same( 2, $operation_lock->acquisitions, 'Repair acquired the wrong lock count: ' . $operation );
	g5_s03_same( 2, $operation_lock->releases, 'Repair did not release the lock: ' . $operation );
}

[ $alter_store, $alter_runner, $alter_lock ] = g5_s03_create_failure_fixture( $catalog, 46 );
$alter_failure                               = $alter_runner->install( 23, 'install:alter-failure-20260825' );
g5_s03_same( Schema_Installation_Result::REPAIR_REQUIRED, $alter_failure->status(), 'ALTER failure did not require repair.' );
$alter_migration = $plan->migrations()[2];
g5_s03_same( Migration_Ledger_State::REPAIR_REQUIRED, $alter_store->migrations[ $alter_migration->id() ]['state'], 'ALTER failure did not retain repair_required.' );
$alter_store->fail_at_attempt = null;
$alter_repaired               = $alter_runner->repair( database_infrastructure_authorization( $catalog, 'repair:alter-failure-20260825' ) );
g5_s03_same( Schema_Installation_Result::REPAIRED, $alter_repaired->status(), 'ALTER failure did not resume.' );
g5_s03_same( 51, count( array_unique( $alter_store->statements ) ), 'ALTER restart repeated DDL.' );
g5_s03_same( 2, $alter_lock->releases, 'ALTER restart did not release both locks.' );

$postcondition_store                           = new Database_Infrastructure_Test_Store( $catalog );
$postcondition_store->drift_after_attempt      = 7;
[ $postcondition_runner, $postcondition_lock ] = database_infrastructure_runner( $catalog, $postcondition_store );
$postcondition_failure                         = $postcondition_runner->install( 23, 'install:postcondition-failure-20260825' );
g5_s03_same( Schema_Installation_Result::REPAIR_REQUIRED, $postcondition_failure->status(), 'Postcondition failure did not require repair.' );
g5_s03_same( 'schema_postcondition_failed', $postcondition_failure->code(), 'Postcondition failure code changed.' );
$postcondition_repair = $postcondition_runner->repair(
	database_infrastructure_authorization( $catalog, 'repair:postcondition-failure-20260825' )
);
g5_s03_same( Schema_Installation_Result::BLOCKED, $postcondition_repair->status(), 'Drifted postcondition was auto-adopted.' );
g5_s03_same( 1, $postcondition_lock->acquisitions, 'Drifted postcondition acquired a repair lock.' );

$compatibility_store                           = new Database_Infrastructure_Test_Store( $catalog );
$compatibility_store->drift_after_attempt      = 7;
[ $compatibility_runner, $compatibility_lock ] = database_infrastructure_runner( $catalog, $compatibility_store );
$compatibility_failure                         = $compatibility_runner->install( 23, 'install:known-postcondition-20260826' );
g5_s03_same( 'schema_postcondition_failed', $compatibility_failure->code(), 'Compatibility fixture missed the known postcondition failure.' );
$compatibility_blocked = $compatibility_runner->install( 23, 'activation:known-postcondition-diagnostic-20260826' );
g5_s03_same( Schema_Installation_Result::BLOCKED, $compatibility_blocked->status(), 'Drifted compatibility retry was not blocked.' );
g5_s03_assert(
	in_array( 'table_engine_mismatch', array_column( $compatibility_blocked->issues(), 'code' ), true ),
	'Compatibility retry did not expose the safe physical mismatch diagnostic.'
);

$missing_marker_store                            = new Database_Infrastructure_Test_Store( $catalog );
$missing_marker_store->drift_after_attempt       = 1;
[ $missing_marker_runner, $missing_marker_lock ] = database_infrastructure_runner( $catalog, $missing_marker_store );
$missing_marker_failure                          = $missing_marker_runner->install( 23, 'install:missing-marker-seed-20260827' );
g5_s03_same( Schema_Installation_Result::REPAIR_REQUIRED, $missing_marker_failure->status(), 'Missing-marker seed did not retain the physical table.' );
g5_s03_same( 'schema_postcondition_failed', $missing_marker_failure->code(), 'Missing-marker seed returned the wrong stable code.' );
g5_s03_same( null, $missing_marker_store->installation, 'Failed first-table verification claimed ownership.' );
g5_s03_same( array( 'oj_ai_installations' ), $missing_marker_store->literal_table_names(), 'Missing-marker seed created more than the first table.' );

$missing_marker_drift = $missing_marker_runner->install( 23, 'activation:missing-marker-drift-20260827' );
g5_s03_same( Schema_Installation_Result::BLOCKED, $missing_marker_drift->status(), 'Drifted ownerless table was adopted.' );
g5_s03_assert(
	in_array( 'table_engine_mismatch', g5_s03_issue_codes( $missing_marker_drift ), true ),
	'Drifted ownerless table did not expose its physical mismatch.'
);
g5_s03_same( 1, $missing_marker_lock->acquisitions, 'Drifted ownerless table acquired another DDL lock.' );

$missing_marker_store->tables['oj_ai_installations']['engine'] = 'InnoDB';
$missing_marker_resume = $missing_marker_runner->install( 23, 'activation:missing-marker-resume-20260827' );
g5_s03_same( Schema_Installation_Result::INSTALLED, $missing_marker_resume->status(), 'Verified empty initial ledger did not resume.' );
g5_s03_same( 51, count( $missing_marker_store->statements ), 'Missing-marker resume did not finish exact forward DDL.' );
g5_s03_same( 51, count( array_unique( $missing_marker_store->statements ) ), 'Missing-marker resume repeated DDL.' );
g5_s03_same( 2, $missing_marker_lock->acquisitions, 'Missing-marker resume used the wrong lock count.' );
g5_s03_same( Installation_State::REGISTRATION_PENDING, $missing_marker_store->installation['state'], 'Missing-marker resume did not finish registration_pending.' );

$occupied_marker_store                                = new Database_Infrastructure_Test_Store( $catalog );
$occupied_marker_store->tables['oj_ai_installations'] = Schema_Metadata_Contract::expected(
	$plan->baseline_tables()['oj_ai_installations']
);
$occupied_marker_store->foreign_installation_rows     = 1;
[ $occupied_marker_runner, $occupied_marker_lock ]    = database_infrastructure_runner( $catalog, $occupied_marker_store );
$occupied_marker_result                               = $occupied_marker_runner->install( 23, 'activation:occupied-marker-20260827' );
g5_s03_same( Schema_Installation_Result::BLOCKED, $occupied_marker_result->status(), 'Occupied ownerless ledger was adopted.' );
g5_s03_assert(
	in_array( 'initial_resume_installation_rows_present', g5_s03_issue_codes( $occupied_marker_result ), true ),
	'Occupied ownerless ledger did not expose its row diagnostic.'
);
g5_s03_same( 0, $occupied_marker_lock->acquisitions, 'Occupied ownerless ledger acquired the DDL lock.' );
g5_s03_same( null, $occupied_marker_store->installation, 'Occupied ownerless ledger was rewritten.' );

$multiple_orphan_store = new Database_Infrastructure_Test_Store( $catalog );
foreach ( array( 'oj_ai_installations', 'oj_ai_schema_migrations' ) as $table_name ) {
	$multiple_orphan_store->tables[ $table_name ] = Schema_Metadata_Contract::expected(
		$plan->baseline_tables()[ $table_name ]
	);
}
[ $multiple_orphan_runner, $multiple_orphan_lock ] = database_infrastructure_runner( $catalog, $multiple_orphan_store );
$multiple_orphan_result                            = $multiple_orphan_runner->install( 23, 'activation:multiple-orphan-20260827' );
g5_s03_same( Schema_Installation_Result::BLOCKED, $multiple_orphan_result->status(), 'Multiple ownerless tables were adopted.' );
g5_s03_assert(
	in_array( 'initial_resume_installation_missing', g5_s03_issue_codes( $multiple_orphan_result ), true ),
	'Multiple ownerless tables lost the missing-installation diagnostic.'
);
g5_s03_same( 0, $multiple_orphan_lock->acquisitions, 'Multiple ownerless tables acquired the DDL lock.' );

$installation_mismatch_store                                   = new Database_Infrastructure_Test_Store( $catalog );
$installation_mismatch_store->drift_after_attempt              = 7;
[ $installation_mismatch_runner, $installation_mismatch_lock ] = database_infrastructure_runner( $catalog, $installation_mismatch_store );
$installation_mismatch_runner->install( 23, 'install:known-installation-mismatch-20260826' );
$installation_mismatch_store->installation['state'] = Installation_State::REGISTRATION_PENDING;
$installation_mismatch                              = $installation_mismatch_runner->install( 23, 'activation:known-installation-mismatch-20260826' );
g5_s03_same( Schema_Installation_Result::BLOCKED, $installation_mismatch->status(), 'Contradictory installation state was not blocked.' );
g5_s03_assert(
	in_array( 'initial_resume_installation_state_mismatch', array_column( $installation_mismatch->issues(), 'code' ), true ),
	'Installation mismatch did not expose the safe state diagnostic.'
);
g5_s03_same( 1, $installation_mismatch_lock->acquisitions, 'Contradictory installation state acquired the DDL lock.' );
foreach ( $compatibility_store->tables as &$compatibility_metadata ) {
	if ( 'MyISAM' === ( $compatibility_metadata['engine'] ?? null ) ) {
		$compatibility_metadata['engine'] = 'InnoDB';
	}
}
unset( $compatibility_metadata );
$compatibility_migration                    = $plan->migrations()[1];
$compatibility_store->installation['state'] = Installation_State::INSTALLING;
unset( $compatibility_store->migrations[ $compatibility_migration->id() ]['checkpoint'] );
$compatibility_resume = $compatibility_runner->install( 23, 'activation:known-postcondition-resume-20260826' );
g5_s03_same( Schema_Installation_Result::INSTALLED, $compatibility_resume->status(), 'Verified initial CREATE postcondition failure did not resume.' );
g5_s03_same( 51, count( $compatibility_store->statements ), 'Compatibility resume did not finish the exact forward DDL.' );
g5_s03_same( 51, count( array_unique( $compatibility_store->statements ) ), 'Compatibility resume repeated a DDL statement.' );
g5_s03_same( 2, $compatibility_lock->acquisitions, 'Compatibility resume used the wrong lock count.' );
g5_s03_same( Installation_State::REGISTRATION_PENDING, $compatibility_store->installation['state'], 'Compatibility resume did not restore registration_pending.' );
g5_s03_assert( isset( $compatibility_store->migrations[ $compatibility_migration->id() ]['checkpoint_json'] ), 'Compatibility resume did not use the persisted checkpoint envelope.' );

$restart_sources = '';
foreach ( array(
	$addon_root . '/src/Database/Foundation/Schema_Migration_Checkpoint.php',
	$addon_root . '/src/Database/Foundation/Schema_Installation_Runner.php',
	$addon_root . '/src/Database/Foundation/Schema_Runtime_Readiness_Verifier.php',
	$addon_root . '/src/Database/Foundation/Infrastructure/WordPress_Schema_Ledger.php',
	$addon_root . '/src/Database/Foundation/Infrastructure/WordPress_Schema_Statement_Executor.php',
) as $source_file ) {
	$restart_sources .= (string) file_get_contents( $source_file );
}
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE' ) as $forbidden ) {
	g5_s03_assert( ! str_contains( $restart_sources, $forbidden ), 'Restart source crossed the forward-only literal table boundary: ' . $forbidden );
}
g5_s03_assert( ! str_contains( $restart_sources, 'OJ\\Platform\\' ), 'Restart source depends on a platform implementation namespace.' );

echo "G5-IMP-06-S03 failure and restart contract passed.\n";
echo "- CREATE, ALTER, postcondition, checkpoint write, ledger finish, and final state failures\n";
echo "- maintenance freeze, general activation block, verified maintenance/installing CREATE-postcondition resume, explicit deterministic repair\n";
echo "- checkpoint schema, version, canonical JSON, SHA-256, payload, state, and cursor integrity\n";
echo "- no duplicate or down DDL, no WordPress prefix, no platform implementation dependency\n";
