<?php
/**
 * G4-IMP-07-S02 WordPress repository transaction, lease, and replay contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Reporting\ResultPdf\Application\Build_Result_Print_Projection;
use OJAI\Dashboard\Reporting\ResultPdf\Application\Result_Pdf_Failure;
use OJAI\Dashboard\Reporting\ResultPdf\Infrastructure\WordPress_Result_Pdf_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

require __DIR__ . '/result-pdf-job-g4-imp-07-s02.php';
require_once dirname( __DIR__, 2 ) . '/src/Reporting/ResultPdf/Infrastructure/WordPress_Result_Pdf_Repository.php';

/**
 * G4-IMP-07-S02 WordPress repository transaction, lease, and replay contract.
 */
final class G4_Result_Pdf_Storage_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
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
	public array $tables = array(
		'oj_ai_export_jobs'   => array(),
		'oj_ai_pdf_artifacts' => array(),
		'oj_ai_audit_events'  => array(),
		'oj_ai_outbox_events' => array(),
	);
	/**
	 * Next ids.
	 *
	 * @var array<string,int>
	 */
	private array $next_ids = array(
		'oj_ai_export_jobs'   => 1,
		'oj_ai_pdf_artifacts' => 1,
		'oj_ai_audit_events'  => 1,
		'oj_ai_outbox_events' => 1,
	);
	/**
	 * Snapshot.
	 *
	 * @var array{tables:array<string,array<int,array<string,mixed>>>,next_ids:array<string,int>,insert_id:int}|null
	 */
	private ?array $snapshot = null;

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'G4RPDF:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		$this->last_error    = '';
		[ $sql, $arguments ] = $this->statement( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) && str_contains( $sql, 'idempotency_key = %s' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( (string) $row['idempotency_key'] === (string) $arguments[0] ) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'FROM oj_ai_export_jobs' ) && str_contains( $sql, 'active_identity_key = %s' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as $row ) {
				if (
					(string) $row['active_identity_key'] === (string) $arguments[0]
					&& (string) $row['artifact_kind'] === (string) $arguments[1]
					&& in_array( $row['state'], array( $arguments[2], $arguments[3] ), true )
				) {
					return $row;
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'ORDER BY available_at_utc ASC, id ASC LIMIT 1 FOR UPDATE' ) ) {
			$now        = (string) $arguments[2];
			$candidates = array_values(
				array_filter(
					$this->tables['oj_ai_export_jobs'],
					static fn ( array $row ): bool => (string) $row['artifact_kind'] === (string) $arguments[0] && (
					( (string) $row['state'] === (string) $arguments[1] && (string) $row['available_at_utc'] <= $now )
					|| ( (string) $row['state'] === (string) $arguments[3] && null !== $row['lease_expires_at_utc'] && (string) $row['lease_expires_at_utc'] <= (string) $arguments[4] )
					)
				)
			);
			usort( $candidates, static fn ( array $left, array $right ): int => array( $left['available_at_utc'], $left['id'] ) <=> array( $right['available_at_utc'], $right['id'] ) );
			return $candidates[0] ?? null;
		}
		if ( str_contains( $sql, 'SELECT id FROM oj_ai_export_jobs' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as $row ) {
				if ( (int) $row['id'] === (int) $arguments[0] && (int) $row['lock_version'] === (int) $arguments[1] && (string) $row['state'] === (string) $arguments[2] && (string) $row['lease_token'] === (string) $arguments[3] ) {
					return array( 'id' => $row['id'] );
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'SELECT public_id FROM oj_ai_pdf_artifacts' ) ) {
			foreach ( $this->tables['oj_ai_pdf_artifacts'] as $row ) {
				if ( (int) $row['export_job_id'] === (int) $arguments[0] ) {
					return array( 'public_id' => $row['public_id'] );
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
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		$this->last_error = '';
		if ( ! isset( $this->tables[ $table ] ) || $table === $this->fail_insert_table ) {
			$this->last_error = 'forced or unknown insert';
			return false;
		}
		foreach ( $this->tables[ $table ] as $row ) {
			if ( isset( $data['public_id'], $row['public_id'] ) && $data['public_id'] === $row['public_id'] ) {
				$this->last_error = 'duplicate public id';
				return false;
			}
			if ( 'oj_ai_outbox_events' === $table && null !== ( $data['idempotency_key'] ?? null ) && $data['idempotency_key'] === ( $row['idempotency_key'] ?? null ) ) {
				$this->last_error = 'duplicate idempotency key';
				return false;
			}
		}
		$data['id']               = $this->next_ids[ $table ]++;
		$this->insert_id          = $data['id'];
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
			if ( null !== $this->snapshot ) {
				$this->last_error = 'nested transaction';
				return false;
			}
			$this->snapshot = array(
				'tables'    => unserialize( serialize( $this->tables ) ),
				'next_ids'  => $this->next_ids,
				'insert_id' => $this->insert_id,
			);
			return 1;
		}
		if ( 'COMMIT' === $prepared ) {
			if ( null === $this->snapshot ) {
				$this->last_error = 'missing transaction';
				return false;
			}
			$this->snapshot = null;
			return 1;
		}
		if ( 'ROLLBACK' === $prepared ) {
			if ( null !== $this->snapshot ) {
				$this->tables    = $this->snapshot['tables'];
				$this->next_ids  = $this->snapshot['next_ids'];
				$this->insert_id = $this->snapshot['insert_id'];
			}
			$this->snapshot = null;
			return 1;
		}
		[ $sql, $arguments ] = $this->statement( $prepared );
		if ( str_contains( $sql, 'started_at_utc = COALESCE' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as &$row ) {
				if ( (int) $row['id'] === (int) $arguments[5] && (int) $row['lock_version'] === (int) $arguments[6] && (string) $row['state'] === (string) $arguments[7] ) {
					$row['state']                = $arguments[0];
					$row['lease_token']          = $arguments[1];
					$row['lease_expires_at_utc'] = $arguments[2];
					$row['started_at_utc']     ??= $arguments[3];
					$row['lock_version']         = (int) $row['lock_version'] + 1;
					$row['updated_at_utc']       = $arguments[4];
					unset( $row );
					return 1;
				}
			}
			unset( $row );
			return 0;
		}
		if ( str_contains( $sql, 'error_code = %s' ) && str_contains( $sql, 'active_identity_key = NULL' ) ) {
			foreach ( $this->tables['oj_ai_export_jobs'] as &$row ) {
				if ( (int) $row['id'] === (int) $arguments[4] && (int) $row['lock_version'] === (int) $arguments[5] && (string) $row['state'] === (string) $arguments[6] && (string) $row['lease_token'] === (string) $arguments[7] ) {
					$row['state']                = $arguments[0];
					$row['error_code']           = $arguments[1];
					$row['finished_at_utc']      = $arguments[2];
					$row['lease_token']          = null;
					$row['lease_expires_at_utc'] = null;
					$row['active_identity_key']  = null;
					$row['lock_version']         = (int) $row['lock_version'] + 1;
					$row['updated_at_utc']       = $arguments[3];
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
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function statement( string $prepared ): array {
		if ( ! str_starts_with( $prepared, 'G4RPDF:' ) ) {
			return array( $prepared, array() );
		}
		$value = unserialize( base64_decode( substr( $prepared, 7 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $value ) || 2 !== count( $value ) || ! is_string( $value[0] ) || ! is_array( $value[1] ) ) {
			$this->last_error = 'invalid prepared statement';
			return array( '', array() );
		}
		return array( $value[0], array_values( $value[1] ) );
	}
}

/**
 * G4 result pdf storage record.
 *
 * @param string $suffix Suffix.
 * @return array<string,mixed>
 */
function g4_result_pdf_storage_record( string $suffix ): array {
	global $result_pdf_snapshot, $result_pdf_context;
	$ids = new Public_Id_Generator();
	return array(
		'actor_user_id'         => 42,
		'audit_public_id'       => $ids->create()->value(),
		'command_id'            => 'result-pdf-storage-' . $suffix,
		'command_storage_key'   => 'result-pdf-command:' . hash( 'sha256', 'command-' . $suffix ),
		'context_id'            => $result_pdf_context['id'],
		'context_revision'      => $result_pdf_context['revision'],
		'context_type'          => $result_pdf_context['type'],
		'correlation_id'        => 'result-pdf-storage-' . $suffix,
		'job_idempotency_key'   => 'result-pdf-job:' . hash( 'sha256', 'job-' . $suffix ),
		'job_public_id'         => $ids->create()->value(),
		'outbox_public_id'      => $ids->create()->value(),
		'print_profile_version' => Build_Result_Print_Projection::ARTIFACT_PROFILE_VERSION,
		'queued_at_utc'         => '2026-08-24 12:00:00',
		'release_state'         => $result_pdf_snapshot->source_state(),
		'request_digest'        => hash( 'sha256', 'request-' . $suffix ),
		'semantic_hash'         => hash( 'sha256', 'semantic-' . $suffix ),
		'snapshot_hash'         => $result_pdf_snapshot->hash(),
		'snapshot_json'         => $result_pdf_snapshot->json(),
		'source_hash'           => $result_pdf_snapshot->source_hash(),
		'source_public_id'      => $result_pdf_snapshot->source_id(),
	);
}

$storage_database   = new G4_Result_Pdf_Storage_Database();
$storage_repository = new WordPress_Result_Pdf_Repository( $result_pdf_canonical, null, $storage_database );
$storage_record     = g4_result_pdf_storage_record( 'primary' );
$storage_receipt    = $storage_repository->enqueue( $storage_record );
g4_result_pdf_same( $storage_record['job_public_id'], $storage_receipt->job_id(), 'C52 storage lost the queued job ID.' );
g4_result_pdf_same( 1, count( $storage_database->tables['oj_ai_export_jobs'] ), 'C52 storage did not create one job.' );
g4_result_pdf_same( 1, count( $storage_database->tables['oj_ai_audit_events'] ), 'C52 storage did not create one audit event.' );
g4_result_pdf_same( 1, count( $storage_database->tables['oj_ai_outbox_events'] ), 'C52 storage did not create one outbox receipt.' );
g4_result_pdf_same( $result_pdf_snapshot->json(), $storage_database->tables['oj_ai_export_jobs'][0]['request_snapshot_json'], 'C52 storage changed snapshot bytes.' );

$storage_replay = $storage_repository->find_command_receipt( $storage_record['command_storage_key'], $storage_record['request_digest'] );
g4_result_pdf_assert( null !== $storage_replay && $storage_replay->replayed(), 'C52 durable command replay was not restored.' );
g4_result_pdf_same( $storage_receipt->job_id(), $storage_replay->job_id(), 'C52 replay changed the job ID.' );
g4_result_pdf_failure(
	static fn () => $storage_repository->find_command_receipt( $storage_record['command_storage_key'], hash( 'sha256', 'different-request' ) ),
	Result_Pdf_Failure::IDEMPOTENCY_CONFLICT,
	'C52 storage accepted a reused command key with a different digest.'
);

$first_lease = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$first_work  = $storage_repository->claim_due( '2026-08-24 12:00:00', $first_lease, '2026-08-24 12:05:00' );
g4_result_pdf_assert( null !== $first_work, 'C52 storage did not claim a due job.' );
g4_result_pdf_same( 1, $first_work->lock_version(), 'C52 first lease lock version drifted.' );
g4_result_pdf_same( null, $storage_repository->claim_due( '2026-08-24 12:04:59', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', '2026-08-24 12:09:59' ), 'C52 storage reclaimed an unexpired lease.' );
$second_lease = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$second_work  = $storage_repository->claim_due( '2026-08-24 12:05:00', $second_lease, '2026-08-24 12:10:00' );
g4_result_pdf_assert( null !== $second_work, 'C52 storage did not reclaim the lease at exact expiry.' );
g4_result_pdf_same( 2, $second_work->lock_version(), 'C52 reclaimed lease lock version drifted.' );
g4_result_pdf_same( false, $storage_repository->complete_failure( $first_work, Result_Pdf_Failure::RENDER_FAILED, ( new Public_Id_Generator() )->create()->value(), ( new Public_Id_Generator() )->create()->value(), 'stale-result-pdf', '2026-08-24 12:05:01' ), 'C52 stale lease completed a job.' );
g4_result_pdf_same( true, $storage_repository->complete_failure( $second_work, Result_Pdf_Failure::RENDER_FAILED, ( new Public_Id_Generator() )->create()->value(), ( new Public_Id_Generator() )->create()->value(), 'current-result-pdf', '2026-08-24 12:05:02' ), 'C52 current lease could not complete failure.' );
g4_result_pdf_same( 'failed', $storage_database->tables['oj_ai_export_jobs'][0]['state'], 'C52 failed completion did not close the job.' );
g4_result_pdf_same( null, $storage_database->tables['oj_ai_export_jobs'][0]['active_identity_key'], 'C52 failed completion retained the active semantic identity.' );
g4_result_pdf_same( 2, count( $storage_database->tables['oj_ai_audit_events'] ), 'C52 failed completion audit count drifted.' );
g4_result_pdf_same( 2, count( $storage_database->tables['oj_ai_outbox_events'] ), 'C52 failed completion outbox count drifted.' );

$rollback_database                    = new G4_Result_Pdf_Storage_Database();
$rollback_database->fail_insert_table = 'oj_ai_audit_events';
$rollback_repository                  = new WordPress_Result_Pdf_Repository( $result_pdf_canonical, null, $rollback_database );
g4_result_pdf_failure(
	static fn () => $rollback_repository->enqueue( g4_result_pdf_storage_record( 'rollback' ) ),
	Result_Pdf_Failure::STORAGE_UNAVAILABLE,
	'C52 storage did not surface a transactional insert failure.'
);
g4_result_pdf_same( 0, count( $rollback_database->tables['oj_ai_export_jobs'] ), 'C52 storage left a job after rollback.' );
g4_result_pdf_same( 0, count( $rollback_database->tables['oj_ai_audit_events'] ), 'C52 storage left audit data after rollback.' );
g4_result_pdf_same( 0, count( $rollback_database->tables['oj_ai_outbox_events'] ), 'C52 storage left outbox data after rollback.' );

fwrite( STDOUT, "G4-IMP-07-S02 storage passed: atomic enqueue, durable replay, conflict rejection, exact 300-second lease expiry, stale-token CAS, failed completion, and rollback are frozen.\n" );
