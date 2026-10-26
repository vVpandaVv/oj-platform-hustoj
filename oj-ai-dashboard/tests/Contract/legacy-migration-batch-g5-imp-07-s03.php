<?php
/**
 * G5-IMP-07-S03 atomic legacy migration batch contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Target_Transformation_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Item_Batch;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Target_Write_Result;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Batch_Repository;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Target_Writer_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s03b assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03b_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5 s03b same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03b_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03b failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03b_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s03b_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s03b id.
 *
 * @return string
 */
function g5_s03b_id(): string {
	static $ids = null;
	$ids      ??= new Public_Id_Generator();
	return $ids->create()->value();
}

/**
 * G5-IMP-07-S03 atomic legacy migration batch contract.
 */
final class G5_S03B_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail table.
	 *
	 * @var string|null
	 */
	public ?string $fail_table = null;
	/**
	 * Queries.
	 *
	 * @var array<int,string>
	 */
	public array $queries = array();
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_import_runs'   => array(),
		'oj_ai_import_items'  => array(),
		'oj_ai_audit_events'  => array(),
		'oj_ai_outbox_events' => array(),
		'oj_ai_curriculums'   => array(),
	);
	/**
	 * Backup.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $backup = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		$this->queries[] = $sql;
		return 'G5S03B:' . base64_encode( serialize( array( $sql, $values ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $output Output.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $output ): ?array {
		list( $sql, $values ) = $this->decode( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_import_runs'] as $row ) {
				if ( (string) ( $row['public_id'] ?? '' ) === (string) ( $values[0] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_items WHERE import_run_id = %d' ) ) {
			foreach ( $this->tables['oj_ai_import_items'] as $row ) {
				if (
					(string) ( $row['import_run_id'] ?? '' ) === (string) ( $values[0] ?? '' )
					&& (string) ( $row['source_type'] ?? '' ) === (string) ( $values[1] ?? '' )
					&& (string) ( $row['source_key'] ?? '' ) === (string) ( $values[2] ?? '' )
				) {
					return $row;
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected batch row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			throw new RuntimeException( 'Unexpected batch scalar query: ' . $sql );
		}
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? (string) $last['event_hash'] : null;
	}

	/**
	 * Update g5 s03 b database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where ): int|false {
		if ( $this->fail_table === $table || ! isset( $this->tables[ $table ] ) ) {
			$this->last_error = 'forced update failure';
			return false;
		}
		foreach ( $this->tables[ $table ] as $index => $row ) {
			$matches = true;
			foreach ( $where as $key => $value ) {
				if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				$this->tables[ $table ][ $index ] = array_merge( $row, $data );
				return 1;
			}
		}
		return 0;
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return bool
	 */
	public function insert( string $table, array $data ): bool {
		if ( $this->fail_table === $table || ! array_key_exists( $table, $this->tables ) ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) ( $row['idempotency_key'] ?? '' ) === (string) ( $data['idempotency_key'] ?? '' ) ) {
					$this->last_error = 'duplicate outbox idempotency';
					return false;
				}
			}
		}
		$this->tables[ $table ][] = $data;
		return true;
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup = unserialize( serialize( $this->tables ), array( 'allowed_classes' => false ) );
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup = null;
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->tables = $this->backup;
			}
			$this->backup     = null;
			$this->last_error = '';
			return 1;
		}
		throw new RuntimeException( 'Unexpected batch transaction query: ' . $sql );
	}

	/**
	 * Decode g5 s03 b database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S03B:' ) ) {
			throw new RuntimeException( 'Batch query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || ! is_string( $value[0] ?? null ) || ! is_array( $value[1] ?? null ) ) {
			throw new RuntimeException( 'Batch prepared query is invalid.' );
		}
		return array( $value[0], $value[1] );
	}

	/**
	 * Counts.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		return array_map( 'count', $this->tables );
	}
}

/**
 * G5-IMP-07-S03 atomic legacy migration batch contract.
 */
final class G5_S03B_Targets implements Legacy_Target_Writer_Port_Interface {
	/**
	 * Transform failure source id.
	 *
	 * @var int|null
	 */
	public ?int $transform_failure_source_id = null;
	/**
	 * Ids.
	 *
	 * @var Public_Id_Generator
	 */
	private Public_Id_Generator $ids;
	/**
	 * Initialize g5 s03 b targets dependencies and state.
	 *
	 * @param G5_S03B_Database $database Database.
	 */
	public function __construct( private G5_S03B_Database $database ) {
		$this->ids = new Public_Id_Generator();
	}
	/**
	 * Write.
	 *
	 * @param int                $run_database_id Run database id.
	 * @param Legacy_Source_Item $item Item.
	 * @param int                $actor_user_id Authenticated actor from the access context.
	 * @param string             $created_at_utc Created at utc.
	 * @return Legacy_Target_Write_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function write( int $run_database_id, Legacy_Source_Item $item, int $actor_user_id, string $created_at_utc ): Legacy_Target_Write_Result {
		if ( $item->source_id() === $this->transform_failure_source_id ) {
			throw Legacy_Target_Transformation_Failure::because( Legacy_Target_Transformation_Failure::SOURCE_INVALID );
		}
		$public_id = $this->ids->create()->value();
		if ( false === $this->database->insert(
			'oj_ai_curriculums',
			array(
				'public_id'          => $public_id,
				'import_run_id'      => $run_database_id,
				'source_key'         => $item->source_key(),
				'created_by_user_id' => $actor_user_id,
				'created_at_utc'     => $created_at_utc,
			)
		) ) {
			throw new RuntimeException( 'Target insert failed.' );
		}
		return new Legacy_Target_Write_Result( 'curriculum', $public_id, $item->source_checksum() );
	}
}

/**
 * G5 s03b source plan.
 *
 * @param int            $item_total Item total.
 * @param string         $seed Seed.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{0:Legacy_Source_Snapshot,1:array<string,mixed>}
 */
function g5_s03b_source_plan( int $item_total, string $seed, Canonical_Json $canonical ): array {
	$source = new Legacy_Source_Snapshot(
		Legacy_Source_Profile_0_1_2::PROFILE,
		Legacy_Source_Profile_0_1_2::VERSION,
		hash( 'sha256', $seed ),
		array(
			array(
				'logical_table' => 'curriculums',
				'source_table'  => 'wp_oj_ai_curriculums',
				'disposition'   => 'migrate',
				'rows'          => $item_total,
			),
		),
		0,
		0,
		array(
			'context' => 0,
			'user'    => 0,
		),
		array(),
		array()
	);
	return array( $source, ( new Legacy_Migration_Plan( $canonical ) )->build( '7', $source ) );
}

/**
 * G5 s03b running row.
 *
 * @param int            $database_id Database id.
 * @param int            $item_total Item total.
 * @param string         $seed Seed.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g5_s03b_running_row( int $database_id, int $item_total, string $seed, Canonical_Json $canonical ): array {
	list( $source, $plan ) = g5_s03b_source_plan( $item_total, $seed, $canonical );
	$preview_json          = $canonical->encode(
		array(
			'profile' => Approve_Legacy_Migration::PREVIEW_PROFILE,
			'state'   => 'approved',
			'plan'    => $plan,
		)
	);
	$checkpoint            = array(
		'profile'    => Execute_Legacy_Migration::CHECKPOINT_PROFILE,
		'phase'      => 'running',
		'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
		'source'     => array(
			'profile'     => $source->profile(),
			'fingerprint' => $source->source_fingerprint(),
		),
		'plan_hash'  => $plan['plan_hash'],
		'cursor'     => array(
			'table_index'     => 0,
			'after_source_id' => 0,
			'next_item_no'    => 1,
		),
		'totals'     => array(
			'success' => 0,
			'failure' => 0,
			'skipped' => 0,
		),
	);
	$checkpoint_json       = $canonical->encode( $checkpoint );
	return array(
		'id'                 => $database_id,
		'public_id'          => g5_s03b_id(),
		'lock_version'       => 1,
		'updated_by_user_id' => 77,
		'source_kind'        => 'oj_ai_dashboard_wpdb_prefixed',
		'source_version'     => Legacy_Source_Profile_0_1_2::VERSION,
		'source_fingerprint' => $source->source_fingerprint(),
		'state'              => 'running',
		'preview_schema'     => Approve_Legacy_Migration::PREVIEW_SCHEMA,
		'preview_version'    => Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION,
		'preview_json'       => $preview_json,
		'preview_hash'       => hash( 'sha256', $preview_json ),
		'checkpoint_schema'  => Execute_Legacy_Migration::CHECKPOINT_SCHEMA,
		'checkpoint_version' => Execute_Legacy_Migration::CHECKPOINT_VERSION,
		'checkpoint_json'    => $checkpoint_json,
		'checkpoint_hash'    => hash( 'sha256', $checkpoint_json ),
		'item_total'         => $item_total,
		'success_total'      => 0,
		'failure_total'      => 0,
		'skipped_total'      => 0,
		'error_code'         => null,
	);
}

/**
 * G5 s03b items.
 *
 * @param int $first_item_no First item no.
 * @param int $first_source_id First source id.
 * @param int $count Count.
 * @return array<int,Legacy_Source_Item>
 */
function g5_s03b_items( int $first_item_no, int $first_source_id, int $count ): array {
	$items = array();
	for ( $offset = 0; $offset < $count; ++$offset ) {
		$source_id = $first_source_id + $offset;
		$record    = array(
			'id'           => (string) $source_id,
			'secret_token' => 'must-not-persist-' . $source_id,
		);
		$items[]   = new Legacy_Source_Item(
			$first_item_no + $offset,
			'curriculums',
			$source_id,
			hash( 'sha256', 'curriculum:' . $source_id ),
			$record
		);
	}
	return $items;
}

$canonical  = new Canonical_Json();
$database   = new G5_S03B_Database();
$targets    = new G5_S03B_Targets( $database );
$repository = new WordPress_Legacy_Migration_Batch_Repository( $targets, $canonical, $database );

$empty_receipt = new Legacy_Target_Write_Result( 'curriculum', g5_s03b_id(), str_repeat( 'a', 64 ) );
g5_s03b_same( array(), $empty_receipt->metadata(), 'An empty safe target receipt was rejected.' );
$unsafe_metadata_rejected = false;
try {
	new Legacy_Target_Write_Result( 'curriculum', g5_s03b_id(), str_repeat( 'a', 64 ), array( 'title' => 'student name' ) );
} catch ( InvalidArgumentException ) {
	$unsafe_metadata_rejected = true;
}
g5_s03b_assert( $unsafe_metadata_rejected, 'Target receipt accepted a non-allowlisted text field.' );

$database->tables['oj_ai_import_runs'][] = g5_s03b_running_row( 1, 251, 'batch-main', $canonical );
$main_run_id                             = (string) $database->tables['oj_ai_import_runs'][0]['public_id'];
$targets->transform_failure_source_id    = 2;
$first_run                               = $repository->running_run( $main_run_id );
$first_batch                             = new Legacy_Source_Item_Batch( g5_s03b_items( 1, 1, 250 ), 0, 250, 251, false );
$first_result                            = $repository->commit_batch( $first_run, $first_batch, '2026-08-25 13:00:00' );
g5_s03b_same( 'running', $first_result->state(), 'A nonterminal 250-item batch became terminal.' );
g5_s03b_same( 250, $first_result->batch_item_count(), 'The frozen 250-item batch count drifted.' );
g5_s03b_same( 249, $first_result->success_total(), 'Successful item total drifted after the first batch.' );
g5_s03b_same( 1, $first_result->failure_total(), 'A safe transform failure did not become one failed item.' );
g5_s03b_same( 250, count( $database->tables['oj_ai_import_items'] ), 'The first batch did not persist exactly 250 item receipts.' );
g5_s03b_same( 249, count( $database->tables['oj_ai_curriculums'] ), 'Transform failure produced a target row or a success was lost.' );
g5_s03b_same( 0, count( $database->tables['oj_ai_audit_events'] ), 'A nonterminal batch emitted a terminal audit event.' );
g5_s03b_same( 0, count( $database->tables['oj_ai_outbox_events'] ), 'A nonterminal batch emitted a terminal outbox event.' );
g5_s03b_same( 'oj_ai.legacy_item_payload', $database->tables['oj_ai_import_items'][0]['payload_schema'], 'Item payload schema drifted from A-DS15-DBA01.' );
foreach ( $database->tables['oj_ai_import_items'] as $item_row ) {
	g5_s03b_assert( ! str_contains( (string) $item_row['payload_json'], 'must-not-persist-' ), 'Import item persisted a raw source record.' );
}

$targets->transform_failure_source_id = null;
$second_run                           = $repository->running_run( $main_run_id );
$second_batch                         = new Legacy_Source_Item_Batch( g5_s03b_items( 251, 251, 1 ), 35, 0, 252, true );
$second_result                        = $repository->commit_batch( $second_run, $second_batch, '2026-08-25 13:01:00' );
g5_s03b_same( 'completed_with_errors', $second_result->state(), 'Final batch ignored a committed item failure.' );
g5_s03b_same( 250, $second_result->success_total(), 'Final success total is incorrect.' );
g5_s03b_same( 1, $second_result->failure_total(), 'Final failure total is incorrect.' );
g5_s03b_same( 251, count( $database->tables['oj_ai_import_items'] ), 'Final batch item receipt was not committed.' );
g5_s03b_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'Terminal batch did not append exactly one audit event.' );
g5_s03b_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'Terminal batch did not append exactly one outbox event.' );
g5_s03b_same( 'legacy_migration.execution_completed_with_errors', $database->tables['oj_ai_outbox_events'][0]['event_type'], 'Terminal event did not preserve the completed-with-errors state.' );
g5_s03b_same( 'completed_with_errors', $database->tables['oj_ai_import_runs'][0]['state'], 'Terminal run state was not committed.' );
g5_s03b_same( 3, $database->tables['oj_ai_import_runs'][0]['lock_version'], 'Each committed batch must advance lock_version once.' );

$database->tables['oj_ai_import_runs'][] = g5_s03b_running_row( 2, 2, 'batch-rollback', $canonical );
$rollback_run_id                         = (string) $database->tables['oj_ai_import_runs'][1]['public_id'];
$rollback_run                            = $repository->running_run( $rollback_run_id );
$rollback_batch                          = new Legacy_Source_Item_Batch( g5_s03b_items( 1, 1001, 2 ), 35, 0, 3, true );
$before_rollback                         = $database->counts();
$before_rollback_row                     = $database->tables['oj_ai_import_runs'][1];
$database->fail_table                    = 'oj_ai_audit_events';
g5_s03b_failure(
	static fn () => $repository->commit_batch( $rollback_run, $rollback_batch, '2026-08-25 13:02:00' ),
	Legacy_Migration_Failure::STORAGE_UNAVAILABLE,
	'Terminal audit insertion failure did not fail the whole batch.'
);
$database->fail_table = null;
g5_s03b_same( $before_rollback, $database->counts(), 'A failed terminal event left target or item rows behind.' );
g5_s03b_same( $before_rollback_row, $database->tables['oj_ai_import_runs'][1], 'A failed terminal event changed the run checkpoint.' );
$retry_result = $repository->commit_batch( $rollback_run, $rollback_batch, '2026-08-25 13:03:00' );
g5_s03b_same( 'completed', $retry_result->state(), 'Retry after full rollback did not complete.' );

$database->tables['oj_ai_import_runs'][] = g5_s03b_running_row( 3, 2, 'batch-concurrency', $canonical );
$concurrent_run_id                       = (string) $database->tables['oj_ai_import_runs'][2]['public_id'];
$worker_a                                = $repository->running_run( $concurrent_run_id );
$worker_b                                = $repository->running_run( $concurrent_run_id );
$concurrent_batch                        = new Legacy_Source_Item_Batch( g5_s03b_items( 1, 2001, 1 ), 0, 2001, 2, false );
$repository->commit_batch( $worker_a, $concurrent_batch, '2026-08-25 13:04:00' );
$before_stale = $database->counts();
g5_s03b_failure(
	static fn () => $repository->commit_batch( $worker_b, $concurrent_batch, '2026-08-25 13:04:01' ),
	Legacy_Migration_Failure::RUN_STATE_CONFLICT,
	'A stale concurrent worker was allowed to commit the same cursor.'
);
g5_s03b_same( $before_stale, $database->counts(), 'A stale concurrent worker created duplicate target or item rows.' );

foreach ( $database->queries as $sql ) {
	foreach ( array( 'wp_oj_ai_', '$wpdb->prefix', '$wpdb->base_prefix', 'CodingPanda\\OjPlatform\\Kernel\\', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE' ) as $forbidden ) {
		g5_s03b_assert( ! str_contains( $sql, $forbidden ), 'Batch SQL crossed a frozen target boundary: ' . $forbidden );
	}
}

$runtime_files = array(
	'src/Operations/LegacyMigration/Application/Process_Legacy_Migration_Batch.php',
	'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Batch_Repository.php',
);
foreach ( $runtime_files as $relative ) {
	$source_code = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_s03b_assert( is_string( $source_code ), 'Batch runtime source could not be read: ' . $relative );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s03b_assert( ! str_contains( $source_code, $forbidden ), 'Batch runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}

$repository_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Batch_Repository.php' );
foreach ( array( "'oj_ai_import_runs'", "'oj_ai_import_items'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'" ) as $literal_table ) {
	g5_s03b_assert( str_contains( (string) $repository_source, $literal_table ), 'Batch repository lost a literal target table: ' . $literal_table );
}

fwrite( STDOUT, "G5-IMP-07-S03 batch passed: 250-item atomic target/item/checkpoint commits, safe item failures, terminal audit/outbox, rollback, restart, and stale-worker exclusion are frozen.\n" );
