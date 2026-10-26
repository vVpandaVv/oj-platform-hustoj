<?php
/**
 * G5-IMP-03-S05 C53 queued cancellation and C54 failed retry contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\Export\Application\Cancel_Export_Job;
use OJAI\Dashboard\Reporting\Export\Application\Cancel_Export_Job_Command;
use OJAI\Dashboard\Reporting\Export\Application\Export_Control_Result;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Retry_Export_Job;
use OJAI\Dashboard\Reporting\Export\Application\Retry_Export_Job_Command;
use OJAI\Dashboard\Reporting\Export\Infrastructure\WordPress_Export_Control_Repository;
use OJAI\Dashboard\Reporting\Export\Port\Export_Control_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Reporting\Export\Presentation\Export_Control_Rest_Controller;
use OJAI\Dashboard\Reporting\ReportExport\Domain\Report_Export_Request_Snapshot;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

ob_start();
require __DIR__ . '/report-json-package-enqueue-g5-imp-03-s03.php';
ob_end_clean();

/**
 * G5s05 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s05_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G5s05 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s05_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G5s05 failure.
 *
 * @param callable $operation Operation.
 * @param string   $reason Reason.
 * @param string   $message Message.
 * @return Export_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g5s05_failure( callable $operation, string $reason, string $message ): Export_Failure {
	try {
		$operation(); } catch ( Export_Failure $failure ) {
		g5s05_same( $reason, $failure->reason(), $message );
		return $failure;
		}
		throw new RuntimeException( $message );
}

/**
 * G5s05 job.
 *
 * @param array       $record Record.
 * @param string      $job_id Job id.
 * @param string      $state State.
 * @param string|null $error_code Error code.
 * @param string|null $active_identity Active identity.
 * @return array<string,mixed>
 */
function g5s05_job( array $record, string $job_id, string $state, ?string $error_code = null, ?string $active_identity = null ): array {
	$terminal = in_array( $state, array( 'failed', 'cancelled', 'ready', 'expired' ), true );
	$running  = 'running' === $state;
	return array(
		'id'                       => 1,
		'public_id'                => $job_id,
		'lock_version'             => 0,
		'created_by_user_id'       => 77,
		'created_at_utc'           => '2026-08-25 18:00:00',
		'updated_by_user_id'       => 77,
		'updated_at_utc'           => '2026-08-25 18:00:00',
		'context_type'             => $record['context_type'],
		'context_id'               => $record['context_id'],
		'context_revision'         => $record['context_revision'],
		'report_snapshot_id'       => 7,
		'artifact_kind'            => $record['artifact_kind'],
		'source_type'              => 'report_snapshot',
		'source_public_id'         => $record['source_public_id'],
		'source_hash'              => $record['source_hash'],
		'request_contract_version' => Report_Export_Request_Snapshot::VERSION,
		'request_snapshot_schema'  => Report_Export_Request_Snapshot::SCHEMA,
		'request_snapshot_version' => Report_Export_Request_Snapshot::DATABASE_VERSION,
		'request_snapshot_json'    => $record['snapshot_json'],
		'request_snapshot_hash'    => $record['snapshot_hash'],
		'request_hash'             => $record['snapshot_hash'],
		'state'                    => $state,
		'requested_by_user_id'     => 77,
		'idempotency_key'          => 'fixture-job:' . hash( 'sha256', $job_id ),
		'active_identity_key'      => $active_identity,
		'print_profile_version'    => $record['profile_version'],
		'available_at_utc'         => '2026-08-25 18:00:00',
		'queued_at_utc'            => '2026-08-25 18:00:00',
		'lease_token'              => $running ? '11111111-1111-4111-8111-111111111111' : null,
		'lease_expires_at_utc'     => $running ? '2026-08-25 18:05:00' : null,
		'started_at_utc'           => ( $running || $terminal ) ? '2026-08-25 18:00:01' : null,
		'finished_at_utc'          => $terminal ? '2026-08-25 18:00:02' : null,
		'result_file_public_id'    => null,
		'error_code'               => $error_code,
	);
}

/**
 * G5-IMP-03-S05 C53 queued cancellation and C54 failed retry contract.
 */
final class G5S05_Control_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Fail insert table.
	 *
	 * @var string|null
	 */
	public ?string $fail_insert_table = null;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;
	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids;
	/**
	 * Transaction.
	 *
	 * @var array|null
	 */
	private ?array $transaction = null;

	/**
	 * Initialize g5 s05 control database dependencies and state.
	 *
	 * @param array<int,array<string,mixed>> $jobs Jobs.
	 */
	public function __construct( array $jobs ) {
		$this->tables = array(
			'oj_ai_export_jobs'   => array(),
			'oj_ai_audit_events'  => array(),
			'oj_ai_outbox_events' => array(),
		);
		$next         = 1;
		foreach ( $jobs as $job ) {
			$job['id']                           = $next++;
			$this->tables['oj_ai_export_jobs'][] = $job;
		}
		$this->next_ids = array(
			'oj_ai_export_jobs'   => $next,
			'oj_ai_audit_events'  => 1,
			'oj_ai_outbox_events' => 1,
		);
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'G5S05:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array|null
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		$this->last_error    = '';
		[ $sql, $arguments ] = $this->statement( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( (string) $row['idempotency_key'] === (string) $arguments[0] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_export_jobs WHERE public_id' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as $row ) {
				if ( (string) $row['public_id'] === (string) $arguments[0] && (int) $row['requested_by_user_id'] === (int) $arguments[1] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_export_jobs WHERE active_identity_key' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as $row ) {
				if ( (string) $row['active_identity_key'] === (string) $arguments[0] && (int) $row['requested_by_user_id'] === (int) $arguments[1]
					&& in_array( $row['state'], array( $arguments[2], $arguments[3] ), true ) ) {
					return $row;
				}
			}
			return null;
		}
		$this->last_error = 'unexpected select';
		return null;
	}

	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ): mixed {
		$this->last_error = '';
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$this->last_error = 'unexpected scalar';
			return null;
		}
		$rows = $this->tables['oj_ai_audit_events'];
		return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
	}

	/**
	 * Insert.
	 *
	 * @param string $table Table.
	 * @param array  $data Data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		$this->last_error = '';
		if ( ! isset( $this->tables[ $table ] ) || $this->fail_insert_table === $table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( isset( $data['public_id'] ) && ( $row['public_id'] ?? null ) === $data['public_id'] ) {
				$this->last_error = 'duplicate public id';
				return false;
			}
			if ( 'oj_ai_outbox_events' === $table && ( $row['idempotency_key'] ?? null ) === ( $data['idempotency_key'] ?? null ) ) {
				$this->last_error = 'duplicate command key';
				return false;
			}
			if ( 'oj_ai_export_jobs' === $table && null !== ( $data['active_identity_key'] ?? null ) && ( $row['active_identity_key'] ?? null ) === $data['active_identity_key'] ) {
				$this->last_error = 'duplicate active identity';
				return false;
			}
		}
		$data['id']               = $this->next_ids[ $table ]++;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	/**
	 * Query.
	 *
	 * @param string $prepared Prepared.
	 * @return int|false
	 */
	public function query( string $prepared ): int|false {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $prepared ) {
			$this->transaction = unserialize( serialize( array( $this->tables, $this->next_ids ) ) );
			return 1;
		}
		if ( 'COMMIT' === $prepared ) {
			$this->transaction = null;
			return 1;
		}
		if ( 'ROLLBACK' === $prepared ) {
			if ( null !== $this->transaction ) {
				[$this->tables, $this->next_ids] = $this->transaction;
			}$this->transaction = null;
			return 1;
		}
		[ $sql, $arguments ] = $this->statement( $prepared );
		if ( str_starts_with( $sql, 'UPDATE oj_ai_export_jobs SET state' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as &$row ) {
				if ( (int) $row['id'] === (int) $arguments[4] && (int) $row['lock_version'] === (int) $arguments[5]
					&& $row['state'] === $arguments[6] && $row['source_hash'] === $arguments[7] && null === $row['result_file_public_id'] ) {
					$row['state']                = $arguments[0];
					$row['finished_at_utc']      = $arguments[1];
					$row['active_identity_key']  = null;
					$row['lease_token']          = null;
					$row['lease_expires_at_utc'] = null;
					++$row['lock_version'];
					$row['updated_by_user_id'] = $arguments[2];
					$row['updated_at_utc']     = $arguments[3];
					unset( $row );
					return 1;
				}
			}
			unset( $row );
			return 0;
		}
		$this->last_error = 'unexpected query';
		return false;
	}

	/**
	 * Statement.
	 *
	 * @param string $prepared Prepared.
	 * @return array
	 */
	private function statement( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G5S05:' ) ) {
			return array( $prepared, array() );
		}
		$value = unserialize( base64_decode( substr( $prepared, 6 ), true ), array( 'allowed_classes' => false ) );
		return array( $value[0], array_values( $value[1] ) );
	}
}

/**
 * G5-IMP-03-S05 C53 queued cancellation and C54 failed retry contract.
 */
final class G5S05_Queue implements Export_Control_Queue_Wakeup_Port_Interface {
	/**
	 * Kinds.
	 *
	 * @var array<int,string>
	 */
	public array $kinds = array();
	/**
	 * Wake.
	 *
	 * @param string $artifact_kind Artifact kind.
	 * @return void
	 */
	public function wake( string $artifact_kind ): void {
		$this->kinds[] = $artifact_kind;
	}
}

$g5s05_canonical = $json_canonical;
$g5s05_ids       = new Public_Id_Generator();
$g5s05_active    = 'report-export-active:' . $json_record['semantic_hash'];

$cancel_job        = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'queued', null, $g5s05_active );
$cancel_database   = new G5S05_Control_Database( array( $cancel_job ) );
$cancel_repository = new WordPress_Export_Control_Repository( $g5s05_canonical, $cancel_database );
$cancel            = new Cancel_Export_Job( $cancel_repository, $g5s05_ids, static fn(): string=>'2026-08-25 18:10:00' );
$cancel_command    = new Cancel_Export_Job_Command(
	77,
	$cancel_job['public_id'],
	'command-g5-s05-cancel',
	'queued',
	$cancel_job['source_hash'],
	'사용자가 대기 작업을 취소했습니다.',
	hash( 'sha256', 'g5-s05-cancel-key' ),
	'correlation-g5-s05-cancel',
	$g5s05_canonical
);
$cancel_result     = $cancel->execute( $cancel_command );
g5s05_same( 'cancelled', $cancel_result->state(), 'C53 did not cancel a queued export.' );
g5s05_same( $cancel_job['public_id'], $cancel_result->job_id(), 'C53 changed the cancelled job identity.' );
g5s05_same( 'cancelled', $cancel_database->tables['oj_ai_export_jobs'][0]['state'], 'C53 storage state did not change.' );
g5s05_same( null, $cancel_database->tables['oj_ai_export_jobs'][0]['active_identity_key'], 'C53 did not release the active identity.' );
g5s05_same( 1, count( $cancel_database->tables['oj_ai_audit_events'] ), 'C53 did not append one audit event.' );
g5s05_same( 1, count( $cancel_database->tables['oj_ai_outbox_events'] ), 'C53 did not append one durable receipt.' );
$cancel_replay = $cancel->execute( $cancel_command );
g5s05_assert( $cancel_replay->replayed(), 'C53 durable replay was not marked.' );
g5s05_same( 1, count( $cancel_database->tables['oj_ai_outbox_events'] ), 'C53 replay duplicated its receipt.' );

g5s05_failure(
	static fn()=>$cancel->execute(
		new Cancel_Export_Job_Command(
			77,
			$cancel_job['public_id'],
			'command-g5-s05-cancel-conflict',
			'queued',
			$cancel_job['source_hash'],
			'다른 취소 사유입니다.',
			hash( 'sha256', 'g5-s05-cancel-key' ),
			'correlation-g5-s05-cancel-conflict',
			$g5s05_canonical
		)
	),
	Export_Failure::IDEMPOTENCY_CONFLICT,
	'C53 accepted different content for the same Idempotency-Key.'
);

$running_job        = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'running', null, $g5s05_active );
$running_repository = new WordPress_Export_Control_Repository( $g5s05_canonical, new G5S05_Control_Database( array( $running_job ) ) );
$running_cancel     = new Cancel_Export_Job( $running_repository, $g5s05_ids, static fn(): string=>'2026-08-25 18:11:00' );
$running_failure    = g5s05_failure(
	static fn()=>$running_cancel->execute(
		new Cancel_Export_Job_Command(
			77,
			$running_job['public_id'],
			'command-g5-s05-running',
			'queued',
			$running_job['source_hash'],
			'실행 중인 작업 취소를 요청했습니다.',
			hash( 'sha256', 'g5-s05-running-key' ),
			'correlation-g5-s05-running',
			$g5s05_canonical
		)
	),
	Export_Failure::STATE_CONFLICT,
	'C53 cancelled a running export.'
);
g5s05_same( 'running', $running_failure->safe_context()['state'], 'C53 conflict did not return safe current state.' );

$failed_job       = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'failed', 'report_export_archive_failed', null );
$retry_database   = new G5S05_Control_Database( array( $failed_job ) );
$retry_repository = new WordPress_Export_Control_Repository( $g5s05_canonical, $retry_database );
$retry_queue      = new G5S05_Queue();
$retry            = new Retry_Export_Job( $retry_repository, $retry_queue, $g5s05_ids, static fn(): string=>'2026-08-25 18:12:00' );
$retry_command    = new Retry_Export_Job_Command(
	77,
	$failed_job['public_id'],
	'command-g5-s05-retry',
	'failed',
	$failed_job['source_hash'],
	$failed_job['error_code'],
	'일시적인 압축 오류로 다시 실행합니다.',
	hash( 'sha256', 'g5-s05-retry-key' ),
	'correlation-g5-s05-retry',
	$g5s05_canonical
);
$retry_result     = $retry->execute( $retry_command );
g5s05_same( 'queued', $retry_result->state(), 'C54 did not create a queued retry.' );
g5s05_same( 2, count( $retry_database->tables['oj_ai_export_jobs'] ), 'C54 did not append exactly one new job.' );
g5s05_same( 'failed', $retry_database->tables['oj_ai_export_jobs'][0]['state'], 'C54 mutated the failed source row.' );
$retried_job = $retry_database->tables['oj_ai_export_jobs'][1];
g5s05_same( $failed_job['request_snapshot_json'], $retried_job['request_snapshot_json'], 'C54 changed immutable request bytes.' );
g5s05_same( $g5s05_active, $retried_job['active_identity_key'], 'C54 did not restore the exact semantic active identity.' );
g5s05_same( array( 'report_json_package' ), $retry_queue->kinds, 'C54 woke the wrong worker.' );
$retry_replay = $retry->execute( $retry_command );
g5s05_assert( $retry_replay->replayed(), 'C54 durable replay was not marked.' );
g5s05_same( $retry_result->job_id(), $retry_replay->job_id(), 'C54 replay changed the retry job identity.' );
g5s05_same( 2, count( $retry_database->tables['oj_ai_export_jobs'] ), 'C54 replay duplicated the retry job.' );
g5s05_same( 2, count( $retry_queue->kinds ), 'C54 replay did not wake the existing queued work.' );

$nonretry_job = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'failed', 'report_export_source_stale', null );
$nonretry_db  = new G5S05_Control_Database( array( $nonretry_job ) );
$nonretry     = new Retry_Export_Job( new WordPress_Export_Control_Repository( $g5s05_canonical, $nonretry_db ), new G5S05_Queue(), $g5s05_ids, static fn(): string=>'2026-08-25 18:13:00' );
g5s05_failure(
	static fn()=>$nonretry->execute(
		new Retry_Export_Job_Command(
			77,
			$nonretry_job['public_id'],
			'command-g5-s05-no-retry',
			'failed',
			$nonretry_job['source_hash'],
			$nonretry_job['error_code'],
			'원본 변경 오류의 재시도를 요청했습니다.',
			hash( 'sha256', 'g5-s05-no-retry-key' ),
			'correlation-g5-s05-no-retry',
			$g5s05_canonical
		)
	),
	Export_Failure::NOT_RETRYABLE,
	'C54 retried a stale-source failure.'
);
g5s05_same( 1, count( $nonretry_db->tables['oj_ai_export_jobs'] ), 'C54 non-retryable failure appended a job.' );

$semantic_failed = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'failed', 'report_export_archive_failed', null );
$semantic_active = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'queued', null, $g5s05_active );
$semantic_db     = new G5S05_Control_Database( array( $semantic_failed, $semantic_active ) );
$semantic_queue  = new G5S05_Queue();
$semantic_retry  = new Retry_Export_Job( new WordPress_Export_Control_Repository( $g5s05_canonical, $semantic_db ), $semantic_queue, $g5s05_ids, static fn(): string=>'2026-08-25 18:14:00' );
$semantic_result = $semantic_retry->execute(
	new Retry_Export_Job_Command(
		77,
		$semantic_failed['public_id'],
		'command-g5-s05-semantic',
		'failed',
		$semantic_failed['source_hash'],
		$semantic_failed['error_code'],
		'동일 요청이 이미 대기 중인지 확인합니다.',
		hash( 'sha256', 'g5-s05-semantic-key' ),
		'correlation-g5-s05-semantic',
		$g5s05_canonical
	)
);
g5s05_same( $semantic_active['public_id'], $semantic_result->job_id(), 'C54 did not reuse the existing semantic job.' );
g5s05_assert( $semantic_result->replayed(), 'C54 semantic reuse was not identified.' );
g5s05_same( 2, count( $semantic_db->tables['oj_ai_export_jobs'] ), 'C54 semantic reuse appended duplicate work.' );
g5s05_same( 'export_job.retry_reused', $semantic_db->tables['oj_ai_outbox_events'][0]['event_type'], 'C54 semantic reuse receipt drifted.' );

$rollback_job                   = g5s05_job( $json_record, $g5s05_ids->create()->value(), 'failed', 'report_export_archive_failed', null );
$rollback_db                    = new G5S05_Control_Database( array( $rollback_job ) );
$rollback_db->fail_insert_table = 'oj_ai_outbox_events';
$rollback_retry                 = new Retry_Export_Job( new WordPress_Export_Control_Repository( $g5s05_canonical, $rollback_db ), new G5S05_Queue(), $g5s05_ids, static fn(): string=>'2026-08-25 18:15:00' );
g5s05_failure(
	static fn()=>$rollback_retry->execute(
		new Retry_Export_Job_Command(
			77,
			$rollback_job['public_id'],
			'command-g5-s05-rollback',
			'failed',
			$rollback_job['source_hash'],
			$rollback_job['error_code'],
			'트랜잭션 롤백을 확인합니다.',
			hash( 'sha256', 'g5-s05-rollback-key' ),
			'correlation-g5-s05-rollback',
			$g5s05_canonical
		)
	),
	Export_Failure::STORAGE_UNAVAILABLE,
	'C54 did not surface an outbox transaction failure.'
);
g5s05_same( 1, count( $rollback_db->tables['oj_ai_export_jobs'] ), 'C54 rollback left a partial retry job.' );
g5s05_same( 0, count( $rollback_db->tables['oj_ai_audit_events'] ), 'C54 rollback left a partial audit event.' );

$controller_reflection = new ReflectionClass( Export_Control_Rest_Controller::class );
$controller            = $controller_reflection->newInstanceWithoutConstructor();
$input                 = $controller_reflection->getMethod( 'input' );
$cancel_request        = new class($cancel_job){
	/**
	 * Initialize report-export-control-g5-imp-03-s05 dependencies and state.
	 *
	 * @param array $job Job.
	 */
	public function __construct( private array $job ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return array(
			'command_id' => 'command-g5-s05-rest-cancel',
			'expected'   => array(
				'expected_source_hash' => $this->job['source_hash'],
				'expected_state'       => 'queued',
			),
			'payload'    => array( 'reason' => '대기 작업을 취소합니다.' ),
		);
	}
};
$cancel_input          = $input->invoke( $controller, $cancel_request, Export_Control_Result::OPERATION_CANCEL );
g5s05_same( '', $cancel_input['expected_error_code'], 'C53 parser invented an error code.' );
$retry_request = new class($failed_job){
	/**
	 * Initialize report-export-control-g5-imp-03-s05 dependencies and state.
	 *
	 * @param array $job Job.
	 */
	public function __construct( private array $job ) {
	}
	/**
	 * Get json params.
	 *
	 * @return array
	 */
	public function get_json_params(): array {
		return array(
			'command_id' => 'command-g5-s05-rest-retry',
			'expected'   => array(
				'expected_error_code'  => $this->job['error_code'],
				'expected_source_hash' => $this->job['source_hash'],
				'expected_state'       => 'failed',
			),
			'payload'    => array( 'reason' => '실패 작업을 다시 실행합니다.' ),
		);
	}
};
$retry_input   = $input->invoke( $controller, $retry_request, Export_Control_Result::OPERATION_RETRY );
g5s05_same( 'report_export_archive_failed', $retry_input['expected_error_code'], 'C54 parser lost exact failure evidence.' );
try {
	$input->invoke( $controller, $cancel_request, Export_Control_Result::OPERATION_RETRY );
	throw new RuntimeException( 'C54 accepted a C53 body.' );} catch ( ReflectionException | InvalidArgumentException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Preserve the documented recovery or optional-operation outcome; the catch body explains the fallback.
	// The fixture intentionally contains this expected or cleanup failure.
 // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The fixture intentionally contains this expected or cleanup failure.
	}
	$registered = $controller_reflection->getProperty( 'registered' );
	$registered->setValue( $controller, false );
	$GLOBALS['g5_snapshot_q22_routes'] = array();
	$controller->register_routes();
	g5s05_same( 2, count( $GLOBALS['g5_snapshot_q22_routes'] ), 'C53/C54 route count drifted.' );
	g5s05_same( Export_Control_Rest_Controller::ROUTE_CANCEL, $GLOBALS['g5_snapshot_q22_routes'][0][1], 'C53 route drifted.' );
	g5s05_same( Export_Control_Rest_Controller::ROUTE_RETRY, $GLOBALS['g5_snapshot_q22_routes'][1][1], 'C54 route drifted.' );

	$runtime_files  = array(
		dirname( __DIR__, 2 ) . '/src/Reporting/Export/Application/Cancel_Export_Job.php',
		dirname( __DIR__, 2 ) . '/src/Reporting/Export/Application/Retry_Export_Job.php',
		dirname( __DIR__, 2 ) . '/src/Reporting/Export/Infrastructure/WordPress_Export_Control_Repository.php',
		dirname( __DIR__, 2 ) . '/src/Reporting/Export/Presentation/Export_Control_Rest_Controller.php',
		dirname( __DIR__, 2 ) . '/src/Core/Composition_Root.php',
	);
	$runtime_source = '';
	foreach ( $runtime_files as $runtime_file ) {
		$runtime_source .= (string) file_get_contents( $runtime_file );
	}
	foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
		g5s05_assert( ! str_contains( $runtime_source, $forbidden ), 'S05 runtime contains a forbidden boundary marker: ' . $forbidden );
	}
	foreach ( array( "'oj_ai_export_jobs'", "'oj_ai_audit_events'", "'oj_ai_outbox_events'", 'START TRANSACTION', 'FOR UPDATE', 'export_job.cancelled', 'export_job.retry_queued', 'new Export_Control_Rest_Module' ) as $marker ) {
		g5s05_assert( str_contains( $runtime_source, $marker ), 'S05 runtime marker is missing: ' . $marker );
	}

	fwrite( STDOUT, "G5-IMP-03-S05 passed: C53 queued-only cancellation, C54 retryable failed-job append, semantic reuse, durable idempotency, atomic audit/outbox, and strict REST routes are frozen.\n" );
