<?php
/**
 * G5-IMP-07-S03 C62 approved-to-running start contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Migration_Plan;
use OJAI\Dashboard\Operations\LegacyMigration\Domain\Legacy_Source_Snapshot;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\Legacy_Source_Profile_0_1_2;
use OJAI\Dashboard\Operations\LegacyMigration\Infrastructure\WordPress_Legacy_Migration_Execution_Repository;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Migration_Site_Port_Interface;
use OJAI\Dashboard\Operations\LegacyMigration\Port\Legacy_Source_Inspector_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
( new Autoloader( $root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * G5 s03 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
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
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5 s03 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5_s03_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Legacy_Migration_Failure $failure ) {
		g5_s03_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message . ': no failure was raised.' );
}

/**
 * G5 s03 id.
 *
 * @return string
 */
function g5_s03_id(): string {
	static $ids = null;
	$ids      ??= new Public_Id_Generator();
	return $ids->create()->value();
}

/**
 * G5-IMP-07-S03 C62 approved-to-running start contract.
 */
final class G5_S03_Source implements Legacy_Source_Inspector_Port_Interface {
	/**
	 * Reads.
	 *
	 * @var int
	 */
	public int $reads = 0;
	/**
	 * Initialize g5 s03 source dependencies and state.
	 *
	 * @param Legacy_Source_Snapshot $snapshot Snapshot.
	 */
	public function __construct( public Legacy_Source_Snapshot $snapshot ) {
	}
	/**
	 * Inspect.
	 *
	 * @param string $source_profile Source profile.
	 * @return Legacy_Source_Snapshot
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function inspect( string $source_profile ): Legacy_Source_Snapshot {
		++$this->reads;
		if ( $source_profile !== $this->snapshot->profile() ) {
			throw Legacy_Migration_Failure::because( Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED );
		}
		return $this->snapshot;
	}
}

/**
 * G5-IMP-07-S03 C62 approved-to-running start contract.
 */
final class G5_S03_Site implements Legacy_Migration_Site_Port_Interface {
	/**
	 * Site id.
	 *
	 * @return string
	 */
	public function site_id(): string {
		return '7';
	}
}

/**
 * G5-IMP-07-S03 C62 approved-to-running start contract.
 */
final class G5_S03_Queue implements Legacy_Migration_Queue_Wakeup_Port_Interface {
	/**
	 * Runs.
	 *
	 * @var array<int,string>
	 */
	public array $runs = array();
	/**
	 * Fail.
	 *
	 * @var bool
	 */
	public bool $fail = false;
	/**
	 * Wake.
	 *
	 * @param string $run_id Run id.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function wake( string $run_id ): void {
		$this->runs[] = $run_id;
		if ( $this->fail ) {
			throw new RuntimeException( 'forced wake failure' );
		}
	}
}

/**
 * G5-IMP-07-S03 C62 approved-to-running start contract.
 */
final class G5_S03_Database {
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
	 * Fail update.
	 *
	 * @var bool
	 */
	public bool $fail_update = false;
	/**
	 * Lock available.
	 *
	 * @var bool
	 */
	public bool $lock_available = true;
	/**
	 * Lock held.
	 *
	 * @var bool
	 */
	public bool $lock_held = false;
	/**
	 * Lock events.
	 *
	 * @var array<int,array<int,int|string>>
	 */
	public array $lock_events = array();
	/**
	 * Prepared sql.
	 *
	 * @var array<int,string>
	 */
	public array $prepared_sql = array();
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
		'oj_ai_worksheets'    => array( array( 'public_id' => 'protected-existing-business-row' ) ),
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
		$this->prepared_sql[] = $sql;
		return 'G5S03:' . base64_encode( serialize( array( $sql, $values ) ) );
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
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events WHERE idempotency_key = %s' ) ) {
			$key = (string) ( $values[0] ?? '' );
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $key === (string) ( $row['idempotency_key'] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE public_id = %s' ) ) {
			$public_id = (string) ( $values[0] ?? '' );
			foreach ( $this->tables['oj_ai_import_runs'] as $row ) {
				if ( $public_id === (string) ( $row['public_id'] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_import_runs WHERE state = %s AND public_id <> %s' ) ) {
			$state    = (string) ( $values[0] ?? '' );
			$excluded = (string) ( $values[1] ?? '' );
			foreach ( $this->tables['oj_ai_import_runs'] as $row ) {
				if ( $state === (string) ( $row['state'] ?? '' ) && $excluded !== (string) ( $row['public_id'] ?? '' ) ) {
					return array( 'public_id' => (string) $row['public_id'] );
				}
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C62 row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $sql ) {
		if ( str_starts_with( $sql, 'G5S03:' ) ) {
			list( $statement, $values ) = $this->decode( $sql );
			if ( str_contains( $statement, 'GET_LOCK' ) ) {
				$name                = (string) ( $values[0] ?? '' );
				$timeout             = (int) ( $values[1] ?? -1 );
				$this->lock_events[] = array( 'acquire', $name, $timeout );
				if ( ! $this->lock_available || $this->lock_held ) {
					return '0';
				}
				$this->lock_held = true;
				return '1';
			}
			if ( str_contains( $statement, 'RELEASE_LOCK' ) ) {
				$name                = (string) ( $values[0] ?? '' );
				$this->lock_events[] = array( 'release', $name );
				$this->lock_held     = false;
				return '1';
			}
			throw new RuntimeException( 'Unexpected C62 prepared scalar query: ' . $statement );
		}
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			throw new RuntimeException( 'Unexpected C62 scalar query: ' . $sql );
		}
		$last = end( $this->tables['oj_ai_audit_events'] );
		return is_array( $last ) ? (string) $last['event_hash'] : null;
	}

	/**
	 * Update g5 s03 database.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @param array<string,mixed> $where Where.
	 * @return int|false
	 */
	public function update( string $table, array $data, array $where ): int|false {
		if ( $this->fail_update || ! isset( $this->tables[ $table ] ) ) {
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
		if ( ! array_key_exists( $table, $this->tables ) || $this->fail_table === $table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		if ( 'oj_ai_outbox_events' === $table ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( (string) $row['idempotency_key'] === (string) $data['idempotency_key'] ) {
					$this->last_error = 'duplicate idempotency key';
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
		throw new RuntimeException( 'Unexpected C62 transaction query: ' . $sql );
	}

	/**
	 * Decode g5 s03 database.
	 *
	 * @param string $prepared Prepared.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function decode( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S03:' ) ) {
			throw new RuntimeException( 'C62 query was not prepared.' );
		}
		$value = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_string( $value[0] ) || ! is_array( $value[1] ) ) {
			throw new RuntimeException( 'C62 prepared query is invalid.' );
		}
		return $value;
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
 * G5 s03 snapshot.
 *
 * @param string $seed Seed.
 * @param int    $mapping Mapping.
 * @param int    $conflicts Conflicts.
 * @return Legacy_Source_Snapshot
 */
function g5_s03_snapshot( string $seed = 'source-v1', int $mapping = 0, int $conflicts = 0 ): Legacy_Source_Snapshot {
	$tables           = array(
		array(
			'logical_table' => 'classes',
			'source_table'  => 'wp_oj_ai_classes',
			'disposition'   => 'mapping',
			'rows'          => $mapping,
		),
		array(
			'logical_table' => 'worksheet_templates',
			'source_table'  => 'wp_oj_ai_worksheet_templates',
			'disposition'   => 'migrate',
			'rows'          => 2,
		),
	);
	$exclusions       = $mapping > 0 ? array(
		array(
			'code'  => 'local_academic_master_not_imported',
			'count' => $mapping,
		),
	) : array();
	$source_conflicts = $conflicts > 0 ? array(
		array(
			'code'  => 'legacy_blob_missing',
			'count' => $conflicts,
		),
	) : array();
	$manifest         = array();
	for ( $index = 1; $index <= $mapping; ++$index ) {
		$manifest[] = array(
			'kind'        => 'context',
			'source_ref'  => hash( 'sha256', $seed . ':context:' . $index ),
			'source_type' => 'class',
		);
	}
	return new Legacy_Source_Snapshot(
		Legacy_Source_Profile_0_1_2::PROFILE,
		Legacy_Source_Profile_0_1_2::VERSION,
		hash( 'sha256', $seed ),
		$tables,
		0,
		0,
		array(
			'context' => $mapping,
			'user'    => 0,
		),
		$exclusions,
		$source_conflicts,
		$manifest
	);
}

/**
 * G5 s03 approved row.
 *
 * @param Legacy_Source_Snapshot $snapshot Snapshot.
 * @param Canonical_Json         $canonical Canonical JSON encoder used for stable digests.
 * @param int                    $database_id Database id.
 * @return array<string,mixed>
 */
function g5_s03_approved_row( Legacy_Source_Snapshot $snapshot, Canonical_Json $canonical, int $database_id ): array {
	$plan         = ( new Legacy_Migration_Plan( $canonical ) )->build( '7', $snapshot );
	$preview_json = $canonical->encode(
		array(
			'profile' => Approve_Legacy_Migration::PREVIEW_PROFILE,
			'state'   => 'approved',
			'plan'    => $plan,
		)
	);
	return array(
		'id'                  => $database_id,
		'public_id'           => g5_s03_id(),
		'lock_version'        => 0,
		'created_by_user_id'  => 77,
		'created_at_utc'      => '2026-08-25 12:00:00',
		'updated_by_user_id'  => 77,
		'updated_at_utc'      => '2026-08-25 12:00:00',
		'source_kind'         => 'oj_ai_dashboard_wpdb_prefixed',
		'source_version'      => Legacy_Source_Profile_0_1_2::VERSION,
		'source_fingerprint'  => $snapshot->source_fingerprint(),
		'state'               => 'approved',
		'preview_schema'      => Approve_Legacy_Migration::PREVIEW_SCHEMA,
		'preview_version'     => Approve_Legacy_Migration::PREVIEW_DATABASE_VERSION,
		'preview_json'        => $preview_json,
		'preview_hash'        => hash( 'sha256', $preview_json ),
		'approved_by_user_id' => 77,
		'approved_at_utc'     => '2026-08-25 12:00:00',
		'idempotency_key'     => 'command:' . hash( 'sha256', 'approval-' . $database_id ),
		'checkpoint_schema'   => null,
		'checkpoint_version'  => null,
		'checkpoint_json'     => null,
		'checkpoint_hash'     => null,
		'item_total'          => $snapshot->counts()['migration_candidates'],
		'success_total'       => 0,
		'failure_total'       => 0,
		'skipped_total'       => 0,
		'error_code'          => null,
	);
}

/**
 * G5 s03 service.
 *
 * @param G5_S03_Database        $database Database.
 * @param Legacy_Source_Snapshot $snapshot Snapshot.
 * @param Canonical_Json         $canonical Canonical JSON encoder used for stable digests.
 * @return array{0:Execute_Legacy_Migration,1:G5_S03_Source,2:G5_S03_Queue}
 */
function g5_s03_service( G5_S03_Database $database, Legacy_Source_Snapshot $snapshot, Canonical_Json $canonical ): array {
	$source  = new G5_S03_Source( $snapshot );
	$queue   = new G5_S03_Queue();
	$service = new Execute_Legacy_Migration(
		$source,
		new G5_S03_Site(),
		new WordPress_Legacy_Migration_Execution_Repository( $canonical, $database ),
		$queue,
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => '2026-08-25 12:10:00'
	);
	return array( $service, $source, $queue );
}

/**
 * G5 s03 command.
 *
 * @param array          $run Run.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $raw_key Raw key.
 * @param string|null    $plan_hash Plan hash.
 * @param string|null    $command_id Command identifier used for idempotency.
 * @return Execute_Legacy_Migration_Command
 */
function g5_s03_command( array $run, Canonical_Json $canonical, string $raw_key, ?string $plan_hash = null, ?string $command_id = null ): Execute_Legacy_Migration_Command {
	$preview = json_decode( (string) $run['preview_json'], true, 64, JSON_THROW_ON_ERROR );
	return new Execute_Legacy_Migration_Command(
		77,
		(string) $run['public_id'],
		$command_id ?? g5_s03_id(),
		'approved',
		(string) $run['source_fingerprint'],
		$plan_hash ?? (string) $preview['plan']['plan_hash'],
		hash( 'sha256', $raw_key ),
		'corr-g5-imp07-s03-execute',
		$canonical
	);
}

$canonical                               = new Canonical_Json();
$database                                = new G5_S03_Database();
$snapshot                                = g5_s03_snapshot();
$database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $snapshot, $canonical, 1 );
$run                                     = $database->tables['oj_ai_import_runs'][0];
list( $service, $source, $queue )        = g5_s03_service( $database, $snapshot, $canonical );

$invalid_raised = false;
try {
	new Execute_Legacy_Migration_Command( 77, 'bad-run', g5_s03_id(), 'approved', str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), str_repeat( 'c', 64 ), 'corr-g5-imp07-s03-invalid', $canonical );
} catch ( InvalidArgumentException ) {
	$invalid_raised = true;
}
g5_s03_assert( $invalid_raised, 'C62 command accepted an invalid public run ID.' );

$command = g5_s03_command( $run, $canonical, 'execute-key-0001' );
$first   = $service->execute( $command );
g5_s03_same( 'running', $first->state(), 'C62 did not transition the approved run to running.' );
g5_s03_same( false, $first->replayed(), 'First C62 result was marked replay.' );
g5_s03_same( 250, $first->checkpoint()['batch_size'], 'C62 batch size drifted from A-DS15-D19.' );
g5_s03_same( 1, $first->checkpoint()['cursor']['next_item_no'], 'C62 initial item cursor drifted.' );
g5_s03_same( 1, $first->mutation_count(), 'C62 start must report one ledger transition.' );
g5_s03_same( 0, $first->business_mutation_count(), 'C62 start wrote business data before a worker batch.' );
g5_s03_same( 0, $first->platform_write_count(), 'C62 start reported a platform write.' );
g5_s03_same( 1, $source->reads, 'C62 did not re-inspect the source exactly once.' );
g5_s03_same( array( $first->run_id() ), $queue->runs, 'C62 did not request one worker wakeup.' );
g5_s03_same( 'running', $database->tables['oj_ai_import_runs'][0]['state'], 'C62 run state was not persisted.' );
g5_s03_same( 1, $database->tables['oj_ai_import_runs'][0]['lock_version'], 'C62 did not advance lock_version once.' );
g5_s03_same( Execute_Legacy_Migration::CHECKPOINT_SCHEMA, $database->tables['oj_ai_import_runs'][0]['checkpoint_schema'], 'C62 checkpoint schema drifted.' );
g5_s03_same( hash( 'sha256', $database->tables['oj_ai_import_runs'][0]['checkpoint_json'] ), $database->tables['oj_ai_import_runs'][0]['checkpoint_hash'], 'C62 checkpoint hash does not cover canonical JSON.' );
g5_s03_same( 0, count( $database->tables['oj_ai_import_items'] ), 'C62 start created import items before a worker batch.' );
g5_s03_same( array( array( 'public_id' => 'protected-existing-business-row' ) ), $database->tables['oj_ai_worksheets'], 'C62 start changed business data.' );
g5_s03_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'C62 did not append one audit event.' );
g5_s03_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'C62 did not append one durable receipt.' );
g5_s03_same(
	array( array( 'acquire', 'oj_ai_dashboard_legacy_migration_7', 0 ), array( 'release', 'oj_ai_dashboard_legacy_migration_7' ) ),
	$database->lock_events,
	'C62 did not acquire and release the exact site migration lock.'
);

$counts_after_first = $database->counts();
$replay             = $service->execute( $command );
g5_s03_same( true, $replay->replayed(), 'Same C62 command was not replayed.' );
g5_s03_same( $first->run_id(), $replay->run_id(), 'C62 replay changed run ID.' );
g5_s03_same( 0, $replay->mutation_count(), 'C62 replay reported a mutation.' );
g5_s03_same( 1, $source->reads, 'C62 replay unnecessarily re-read a now-changing source.' );
g5_s03_same( $counts_after_first, $database->counts(), 'C62 replay appended duplicate rows.' );
g5_s03_same( array( $first->run_id() ), $queue->runs, 'C62 replay scheduled a duplicate immediate wakeup.' );
g5_s03_same( 2, count( $database->lock_events ), 'C62 replay unnecessarily reacquired the site migration lock.' );

$changed_command = g5_s03_command( $run, $canonical, 'execute-key-0001', null, g5_s03_id() );
g5_s03_failure( static fn () => $service->execute( $changed_command ), Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT, 'C62 accepted a changed command under the same key.' );
g5_s03_same( $counts_after_first, $database->counts(), 'C62 idempotency conflict mutated storage.' );

$drift_snapshot                          = g5_s03_snapshot( 'drift-approved' );
$database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $drift_snapshot, $canonical, 2 );
$drift_run                               = $database->tables['oj_ai_import_runs'][1];
list( $drift_service )                   = g5_s03_service( $database, g5_s03_snapshot( 'drift-current' ), $canonical );
g5_s03_failure( static fn () => $drift_service->execute( g5_s03_command( $drift_run, $canonical, 'drift-key' ) ), Legacy_Migration_Failure::SOURCE_STALE, 'C62 accepted a changed source fingerprint.' );
g5_s03_same( 'approved', $database->tables['oj_ai_import_runs'][1]['state'], 'Source drift changed the approved run.' );

$mapping_snapshot                        = g5_s03_snapshot( 'mapping', 1 );
$database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $mapping_snapshot, $canonical, 3 );
$mapping_run                             = $database->tables['oj_ai_import_runs'][2];
list( $mapping_service )                 = g5_s03_service( $database, $mapping_snapshot, $canonical );
g5_s03_failure( static fn () => $mapping_service->execute( g5_s03_command( $mapping_run, $canonical, 'mapping-key' ) ), Legacy_Migration_Failure::MAPPING_REQUIRED, 'C62 started before explicit academic/user mapping was resolved.' );
g5_s03_same( 'approved', $database->tables['oj_ai_import_runs'][2]['state'], 'Mapping gate changed the approved run.' );

$conflict_snapshot                       = g5_s03_snapshot( 'conflict', 0, 1 );
$database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $conflict_snapshot, $canonical, 4 );
$conflict_run                            = $database->tables['oj_ai_import_runs'][3];
list( $conflict_service )                = g5_s03_service( $database, $conflict_snapshot, $canonical );
g5_s03_failure( static fn () => $conflict_service->execute( g5_s03_command( $conflict_run, $canonical, 'conflict-key' ) ), Legacy_Migration_Failure::SOURCE_CONFLICT, 'C62 started with an unresolved source conflict.' );
g5_s03_same( 'approved', $database->tables['oj_ai_import_runs'][3]['state'], 'Conflict gate changed the approved run.' );

$expected_snapshot                       = g5_s03_snapshot( 'expected' );
$database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $expected_snapshot, $canonical, 5 );
$expected_run                            = $database->tables['oj_ai_import_runs'][4];
list( $expected_service )                = g5_s03_service( $database, $expected_snapshot, $canonical );
g5_s03_failure( static fn () => $expected_service->execute( g5_s03_command( $expected_run, $canonical, 'expected-key', str_repeat( 'f', 64 ) ) ), Legacy_Migration_Failure::RUN_STATE_CONFLICT, 'C62 accepted a stale expected plan hash.' );
g5_s03_same( 'approved', $database->tables['oj_ai_import_runs'][4]['state'], 'Expected-state conflict changed the run.' );

$database->tables['oj_ai_import_runs'][0]['state'] = 'completed';
$rollback_snapshot                                 = g5_s03_snapshot( 'rollback' );
$database->tables['oj_ai_import_runs'][]           = g5_s03_approved_row( $rollback_snapshot, $canonical, 6 );
$rollback_run                                      = $database->tables['oj_ai_import_runs'][5];
list( $rollback_service, , $rollback_queue )       = g5_s03_service( $database, $rollback_snapshot, $canonical );
$rollback_command                                  = g5_s03_command( $rollback_run, $canonical, 'rollback-key' );
$counts_before_rollback                            = $database->counts();
$database->fail_table                              = 'oj_ai_audit_events';
g5_s03_failure( static fn () => $rollback_service->execute( $rollback_command ), Legacy_Migration_Failure::STORAGE_UNAVAILABLE, 'C62 did not surface an atomic audit failure.' );
$database->fail_table = null;
g5_s03_same( $counts_before_rollback, $database->counts(), 'C62 left partial rows after audit failure.' );
g5_s03_same( 'approved', $database->tables['oj_ai_import_runs'][5]['state'], 'C62 rollback did not restore approved state.' );
$rollback_queue->fail = true;
$retry                = $rollback_service->execute( $rollback_command );
g5_s03_same( 'running', $retry->state(), 'C62 retry after rollback did not start.' );
g5_s03_same( 'running', $database->tables['oj_ai_import_runs'][5]['state'], 'Queue wake failure undid a durable C62 transition.' );
g5_s03_same( 1, count( $rollback_queue->runs ), 'C62 retry did not attempt one queue wakeup.' );

$concurrency_database                                = new G5_S03_Database();
$concurrency_snapshot                                = g5_s03_snapshot( 'site-concurrency' );
$concurrency_database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $concurrency_snapshot, $canonical, 1 );
$concurrency_database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $concurrency_snapshot, $canonical, 2 );
$concurrency_first_run                               = $concurrency_database->tables['oj_ai_import_runs'][0];
$concurrency_second_run                              = $concurrency_database->tables['oj_ai_import_runs'][1];
list( $concurrency_service, , $concurrency_queue )   = g5_s03_service( $concurrency_database, $concurrency_snapshot, $canonical );
$concurrency_service->execute( g5_s03_command( $concurrency_first_run, $canonical, 'site-concurrency-key-1' ) );
$before_competitor = $concurrency_database->counts();
$second_command    = g5_s03_command( $concurrency_second_run, $canonical, 'site-concurrency-key-2' );
g5_s03_failure(
	static fn () => $concurrency_service->execute( $second_command ),
	Legacy_Migration_Failure::RUN_STATE_CONFLICT,
	'C62 allowed two different migration runs to execute on one site.'
);
g5_s03_same( $before_competitor, $concurrency_database->counts(), 'Site concurrency conflict left partial ledger or business rows.' );
g5_s03_same( 'approved', $concurrency_database->tables['oj_ai_import_runs'][1]['state'], 'Site concurrency conflict changed the losing run.' );
g5_s03_same( array( (string) $concurrency_first_run['public_id'] ), $concurrency_queue->runs, 'Site concurrency conflict scheduled the losing run.' );
g5_s03_assert(
	count( array_filter( $concurrency_database->prepared_sql, static fn ( string $sql ): bool => str_contains( $sql, 'WHERE state = %s AND public_id <> %s' ) && str_ends_with( $sql, 'FOR UPDATE' ) ) ) >= 2,
	'C62 site concurrency guard is not a locking read.'
);
$concurrency_database->tables['oj_ai_import_runs'][0]['state'] = 'completed';
$second_started = $concurrency_service->execute( $second_command );
g5_s03_same( 'running', $second_started->state(), 'C62 did not start the waiting run after the active run completed.' );
g5_s03_same( array( (string) $concurrency_first_run['public_id'], (string) $concurrency_second_run['public_id'] ), $concurrency_queue->runs, 'C62 restart scheduled the wrong run.' );

$busy_database                                = new G5_S03_Database();
$busy_snapshot                                = g5_s03_snapshot( 'site-lock-busy' );
$busy_database->tables['oj_ai_import_runs'][] = g5_s03_approved_row( $busy_snapshot, $canonical, 1 );
$busy_run                                     = $busy_database->tables['oj_ai_import_runs'][0];
$busy_database->lock_available                = false;
list( $busy_service, , $busy_queue )          = g5_s03_service( $busy_database, $busy_snapshot, $canonical );
$busy_before                                  = $busy_database->counts();
g5_s03_failure(
	static fn () => $busy_service->execute( g5_s03_command( $busy_run, $canonical, 'site-lock-busy-key' ) ),
	Legacy_Migration_Failure::RUN_STATE_CONFLICT,
	'C62 did not reject a busy site migration lock.'
);
g5_s03_same( $busy_before, $busy_database->counts(), 'Busy site lock changed the approved run or ledger.' );
g5_s03_same( array(), $busy_queue->runs, 'Busy site lock scheduled a worker.' );
g5_s03_same( array( array( 'acquire', 'oj_ai_dashboard_legacy_migration_7', 0 ) ), $busy_database->lock_events, 'Busy site lock was released without ownership or used the wrong name.' );

$runtime_files = array(
	'src/Operations/LegacyMigration/Application/Execute_Legacy_Migration.php',
	'src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Execution_Repository.php',
);
foreach ( $runtime_files as $relative ) {
	$source_code = file_get_contents( $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative ) );
	g5_s03_assert( is_string( $source_code ), 'C62 runtime source could not be read: ' . $relative );
	foreach ( array( '$wpdb->prefix', '$wpdb->base_prefix', 'wp_oj_ai_', 'DROP TABLE', 'RENAME TABLE', 'TRUNCATE TABLE', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5_s03_assert( ! str_contains( $source_code, $forbidden ), 'C62 runtime crossed a frozen boundary: ' . $relative . ' -> ' . $forbidden );
	}
}
$repository_source = file_get_contents( $root . '/src/Operations/LegacyMigration/Infrastructure/WordPress_Legacy_Migration_Execution_Repository.php' );
foreach ( array( "'oj_ai_import_runs'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'" ) as $literal_table ) {
	g5_s03_assert( str_contains( (string) $repository_source, $literal_table ), 'C62 lost literal target table: ' . $literal_table );
}
g5_s03_assert( ! str_contains( (string) $repository_source, "'oj_ai_import_items'" ), 'C62 start coupled acceptance to worker item creation.' );
foreach ( array( 'WordPress_Schema_Lock', 'oj_ai_dashboard_legacy_migration_', 'WHERE state = %s AND public_id <> %s', 'FOR UPDATE' ) as $marker ) {
	g5_s03_assert( str_contains( (string) $repository_source, $marker ), 'C62 site concurrency marker is missing: ' . $marker );
}

fwrite( STDOUT, "G5-IMP-07-S03 start passed: strict C62 command, source/plan revalidation, mapping/conflict gates, site-wide single-run lock, atomic running checkpoint, durable replay, and zero platform writes are frozen.\n" );
