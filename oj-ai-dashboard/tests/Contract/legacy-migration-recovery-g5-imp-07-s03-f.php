<?php
/**
 * G5-IMP-07-S03-F migration recovery and concurrency integration contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Process_Legacy_Migration_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Item_Reader_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

require_once $root . '/tests/Contract/legacy-migration-runtime-g5-imp-07-s03-e.php';
require_once $root . '/tests/Contract/legacy-migration-batch-g5-imp-07-s03.php';

/**
 * G5 s03f assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03f_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03f same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03f_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03f failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03f_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s03f_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5-IMP-07-S03-F migration recovery and concurrency integration contract.
 */
final class G5_S03F_Reader implements Legacy_Source_Item_Reader_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;

	/**
	 * Initialize g5 s03 f reader dependencies and state.
	 *
	 * @param Legacy_Source_Item_Batch $batch Batch.
	 */
	public function __construct( private Legacy_Source_Item_Batch $batch ) {
	}

	/**
	 * Read g5 s03 f reader.
	 *
	 * @param string $source_profile Source profile.
	 * @param int    $table_index Table index.
	 * @param int    $after_source_id After source id.
	 * @param int    $next_item_no Next item no.
	 * @param int    $limit Maximum number of entries requested.
	 * @return Legacy_Source_Item_Batch
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function read(
		string $source_profile,
		int $table_index,
		int $after_source_id,
		int $next_item_no,
		int $limit
	): Legacy_Source_Item_Batch {
		++$this->reads;
		if (
			$source_profile !== OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2::PROFILE
			|| 0 !== $table_index
			|| 0 !== $after_source_id
			|| 1 !== $next_item_no
			|| 250 !== $limit
		) {
			throw new RuntimeException( 'S03-F reader received a stale checkpoint.' );
		}
		return $this->batch;
	}
}

$canonical = new Canonical_Json();

$drift_database                                = new G5_S03B_Database();
$drift_database->tables['oj_ai_import_runs'][] = g5_s03b_running_row( 1, 1, 'worker-source-approved', $canonical );
$drift_targets                                 = new G5_S03B_Targets( $drift_database );
$drift_repository                              = new OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository(
	$drift_targets,
	$canonical,
	$drift_database
);
list( $drift_source )                          = g5_s03b_source_plan( 1, 'worker-source-current', $canonical );
$drift_reader                                  = new G5_S03F_Reader(
	new Legacy_Source_Item_Batch( g5_s03b_items( 1, 1, 1 ), 35, 0, 2, true )
);
$drift_queue                                   = new G5_S03_Queue();
$drift_processor                               = new Process_Legacy_Migration_Batch(
	new G5_S03_Source( $drift_source ),
	new G5_S03_Site(),
	$drift_reader,
	$drift_repository,
	$drift_queue,
	$canonical,
	static fn (): string => '2026-08-26 11:00:00'
);
$drift_run_id                                  = (string) $drift_database->tables['oj_ai_import_runs'][0]['public_id'];
$drift_before_counts                           = $drift_database->counts();
$drift_before_run                              = $drift_database->tables['oj_ai_import_runs'][0];
g5_s03f_failure(
	static fn () => $drift_processor->execute( $drift_run_id ),
	Legacy_Migration_Failure::SOURCE_STALE,
	'Worker source drift did not fail before the target transaction.'
);
g5_s03f_same( 0, $drift_reader->reads, 'Worker source drift read migration items.' );
g5_s03f_same( array(), $drift_queue->runs, 'Worker source drift scheduled another pass.' );
g5_s03f_same( $drift_before_counts, $drift_database->counts(), 'Worker source drift changed target, item, or ledger rows.' );
g5_s03f_same( $drift_before_run, $drift_database->tables['oj_ai_import_runs'][0], 'Worker source drift advanced the checkpoint.' );

$retry_database                                = new G5_S03B_Database();
$retry_database->tables['oj_ai_import_runs'][] = g5_s03b_running_row( 1, 1, 'worker-retry', $canonical );
$retry_targets                                 = new G5_S03B_Targets( $retry_database );
$retry_repository                              = new OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository(
	$retry_targets,
	$canonical,
	$retry_database
);
list( $retry_source )                          = g5_s03b_source_plan( 1, 'worker-retry', $canonical );
$retry_reader                                  = new G5_S03F_Reader(
	new Legacy_Source_Item_Batch( g5_s03b_items( 1, 501, 1 ), 35, 0, 2, true )
);
$retry_queue                                   = new G5_S03_Queue();
$retry_processor                               = new Process_Legacy_Migration_Batch(
	new G5_S03_Source( $retry_source ),
	new G5_S03_Site(),
	$retry_reader,
	$retry_repository,
	$retry_queue,
	$canonical,
	static fn (): string => '2026-08-26 11:05:00'
);
$retry_run_id                                  = (string) $retry_database->tables['oj_ai_import_runs'][0]['public_id'];
$retry_before_counts                           = $retry_database->counts();
$retry_before_run                              = $retry_database->tables['oj_ai_import_runs'][0];
$retry_database->fail_table                    = 'oj_ai_curriculums';
g5_s03f_failure(
	static fn () => $retry_processor->execute( $retry_run_id ),
	Legacy_Migration_Failure::STORAGE_UNAVAILABLE,
	'Mid-batch SQL failure did not abort the production worker pass.'
);
$retry_database->fail_table = null;
g5_s03f_same( $retry_before_counts, $retry_database->counts(), 'Mid-batch SQL failure left a partial target or receipt.' );
g5_s03f_same( $retry_before_run, $retry_database->tables['oj_ai_import_runs'][0], 'Mid-batch SQL failure advanced the checkpoint.' );
$retry_result = $retry_processor->execute( $retry_run_id );
g5_s03f_same( 'completed', $retry_result->state(), 'Retry from the durable checkpoint did not complete.' );
g5_s03f_same( 2, $retry_reader->reads, 'Retry did not reread the same checkpoint exactly once.' );
g5_s03f_same( 1, count( $retry_database->tables['oj_ai_curriculums'] ), 'Retry created the wrong target count.' );
g5_s03f_same( 1, count( $retry_database->tables['oj_ai_import_items'] ), 'Retry created the wrong item receipt count.' );
g5_s03f_same( 1, count( $retry_database->tables['oj_ai_audit_events'] ), 'Retry created the wrong terminal audit count.' );
g5_s03f_same( 1, count( $retry_database->tables['oj_ai_outbox_events'] ), 'Retry created the wrong terminal outbox count.' );
g5_s03f_same( array(), $retry_queue->runs, 'Terminal retry scheduled another worker pass.' );
$retry_after_counts = $retry_database->counts();
g5_s03f_failure(
	static fn () => $retry_processor->execute( $retry_run_id ),
	Legacy_Migration_Failure::RUN_STATE_CONFLICT,
	'Completed run was processed again.'
);
g5_s03f_same( $retry_after_counts, $retry_database->counts(), 'Completed-run replay created duplicate targets or receipts.' );

$execution_source = (string) file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Execution_Repository.php' );
foreach ( array( 'WordPress_Schema_Lock', 'oj_ai_dashboard_legacy_migration_', 'WHERE state = %s AND public_id <> %s', 'FOR UPDATE' ) as $marker ) {
	g5_s03f_assert( str_contains( $execution_source, $marker ), 'Site concurrency implementation marker is missing: ' . $marker );
}
foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE' ) as $forbidden ) {
	g5_s03f_assert( ! str_contains( $execution_source, $forbidden ), 'S03-F execution repository crossed a frozen boundary: ' . $forbidden );
}

fwrite( STDOUT, "G5-IMP-07-S03-F passed: worker source drift, site-wide single-run start, mapping gate, atomic SQL rollback, durable checkpoint retry, stale-worker exclusion, and zero duplicate/platform writes are frozen.\n" );
