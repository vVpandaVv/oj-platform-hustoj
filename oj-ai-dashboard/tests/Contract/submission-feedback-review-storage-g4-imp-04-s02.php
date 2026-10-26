<?php
/**
 * G4-IMP-04-S02 C36 storage transaction fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $addon_root . '/src/Core/Autoloader.php';

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 feedback storage assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_storage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 feedback storage same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_storage_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 feedback storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback storage id.
 *
 * @return string
 */
function g4_feedback_storage_id(): string {
	static $counter = 48000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_feedback_storage_assert( Public_Id::is_valid( $id ), 'Storage fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4-IMP-04-S02 C36 storage transaction fixture.
 */
final class G4_Feedback_Review_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Prepared.
	 *
	 * @var array<string,array{sql:string,args:array<int,mixed>}>
	 */
	private array $prepared = array();
	/**
	 * Prepared counter.
	 *
	 * @var int
	 */
	private int $prepared_counter = 0;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables = array(
		'oj_ai_submission_feedback_revisions' => array(),
		'oj_ai_audit_events'                  => array(),
		'oj_ai_outbox_events'                 => array(),
	);
	/**
	 * Transactions.
	 *
	 * @var array<int,string>
	 */
	public array $transactions = array();
	/**
	 * Fail insert table.
	 *
	 * @var string|null
	 */
	public ?string $fail_insert_table = null;
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Backup.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $backup = null;

	/**
	 * Initialize g4 feedback review database dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( string $sql, ...$args ): string {
		$key                    = '__prepared_' . ( ++$this->prepared_counter );
		$this->prepared[ $key ] = array(
			'sql'  => $sql,
			'args' => $args,
		);
		return $key;
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_row( string $prepared, string $format ): ?array {
		unset( $format );
		[ $sql, $args ] = $this->resolved( $prepared );
		if ( str_contains( $sql, 'FROM oj_ai_outbox_events' ) && str_contains( $sql, 'idempotency_key = %s' ) ) {
			foreach ( $this->tables['oj_ai_outbox_events'] as $row ) {
				if ( $row['idempotency_key'] === $args[0] && $row['event_type'] === $args[1] ) {
					return array(
						'payload_json' => $row['payload_json'],
						'payload_hash' => $row['payload_hash'],
					);
				}
			}
			return null;
		}
		if ( str_contains( $sql, 'SELECT e.id AS evaluation_pk' ) && str_contains( $sql, 'latest_feedback_pk' ) ) {
			return $args[0] === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
		}
		if ( str_contains( $sql, 'WHERE feedback.public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_submission_feedback_revisions'] as $row ) {
				if ( $row['public_id'] !== $args[0] ) {
					continue;
				}
				$previous_id = null;
				if ( null !== $row['previous_revision_id'] ) {
					foreach ( $this->tables['oj_ai_submission_feedback_revisions'] as $candidate ) {
						if ( ( $candidate['id'] ?? null ) === $row['previous_revision_id'] ) {
							$previous_id = $candidate['public_id'];
						}
					}
				}
				return array(
					'revision_no'                     => $row['revision_no'],
					'state'                           => $row['state'],
					'content_hash'                    => $row['content_hash'],
					'feedback_schema'                 => $row['feedback_schema'],
					'feedback_version'                => $row['feedback_version'],
					'feedback_hash'                   => $row['feedback_hash'],
					'source_evaluation_run_public_id' => $row['source_evaluation_run_public_id'],
					'review_id'                       => $this->snapshot['official_review_id'],
					'previous_id'                     => $previous_id,
					'feedback_count'                  => count( $this->tables['oj_ai_submission_feedback_revisions'] ),
				);
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C36 fixture row query: ' . $sql );
	}

	/**
	 * Get var.
	 *
	 * @param string $prepared Prepared.
	 * @return mixed
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function get_var( string $prepared ) {
		[ $sql ] = $this->resolved( $prepared );
		if ( str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$rows = $this->tables['oj_ai_audit_events'];
			return array() === $rows ? null : $rows[ array_key_last( $rows ) ]['event_hash'];
		}
		throw new RuntimeException( 'Unexpected C36 fixture scalar query: ' . $sql );
	}

	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function query( string $sql ): int {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $sql ) {
			$this->backup         = array(
				'tables'   => $this->tables,
				'snapshot' => $this->snapshot,
			);
			$this->transactions[] = 'START TRANSACTION';
			return 0;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup         = null;
			$this->transactions[] = 'COMMIT';
			return 0;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->tables   = $this->backup['tables'];
				$this->snapshot = $this->backup['snapshot'];
			}
			$this->backup         = null;
			$this->transactions[] = 'ROLLBACK';
			return 0;
		}
		throw new RuntimeException( 'C36 repository must not issue mutable SQL outside its append tables: ' . $sql );
	}

	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int|false
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function insert( string $table, array $data ): int|false {
		if ( $this->fail_insert_table === $table ) {
			$this->last_error = 'forced_insert_failure';
			return false;
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			throw new RuntimeException( 'Unexpected C36 fixture insert table: ' . $table );
		}
		if ( 'oj_ai_submission_feedback_revisions' === $table ) {
			$data['id'] = count( $this->tables[ $table ] ) + 701;
		}
		$this->tables[ $table ][] = $data;
		return 1;
	}

	/**
	 * Resolved.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	private function resolved( string $value ): array {
		if ( isset( $this->prepared[ $value ] ) ) {
			return array( $this->prepared[ $value ]['sql'], $this->prepared[ $value ]['args'] );
		}
		return array( $value, array() );
	}
}

/**
 * G4 feedback storage snapshot.
 *
 * @return array<string,mixed>
 */
function g4_feedback_storage_snapshot(): array {
	$evaluation_id = g4_feedback_storage_id();
	$revision_id   = g4_feedback_storage_id();
	$review_id     = g4_feedback_storage_id();
	return array(
		'evaluation_pk'                 => '51',
		'evaluation_id'                 => $evaluation_id,
		'evaluation_lock_version'       => '7',
		'evaluation_state'              => 'reviewed',
		'submission_revision_id'        => $revision_id,
		'submission_revision_hash'      => str_repeat( 'c', 64 ),
		'joined_revision_hash'          => str_repeat( 'c', 64 ),
		'revision_state'                => 'saved',
		'submission_state'              => 'submitted',
		'official_revision_public_id'   => $revision_id,
		'official_review_id'            => $review_id,
		'official_review_hash'          => str_repeat( 'd', 64 ),
		'context_type'                  => 'class',
		'context_id'                    => 'class-g4-feedback-storage',
		'context_revision'              => 'class-g4-feedback-storage-r1',
		'assignment_state'              => 'open',
		'target_state'                  => 'active',
		'official_review_pk'            => '52',
		'joined_review_id'              => $review_id,
		'joined_review_hash'            => str_repeat( 'd', 64 ),
		'official_review_decision'      => 'accepted',
		'feedback_count'                => '0',
		'latest_feedback_pk'            => null,
		'latest_feedback_id'            => null,
		'latest_feedback_hash'          => null,
		'latest_feedback_no'            => null,
		'latest_feedback_audience'      => null,
		'latest_feedback_schema'        => null,
		'latest_feedback_version'       => null,
		'latest_feedback_json'          => null,
		'latest_profile_hash'           => null,
		'latest_feedback_state'         => null,
		'latest_feedback_source_run_id' => null,
		'latest_feedback_review_id'     => null,
		'latest_feedback_review_hash'   => null,
		'latest_previous_feedback_id'   => null,
		'latest_previous_feedback_hash' => null,
		'latest_previous_feedback_no'   => null,
	);
}

/**
 * G4 feedback storage record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_key Command key.
 * @param string              $request_digest Request digest.
 * @return array<string,mixed>
 */
function g4_feedback_storage_record( array $snapshot, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$profile      = array(
		'criterion_feedback' => array(),
		'feedback_ref'       => str_repeat( 'a', 35 ),
		'next_steps'         => array( 'Add one concrete example.' ),
		'overall_feedback'   => 'The response is clear.',
		'safety_flags'       => array(),
		'schema_id'          => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
		'schema_version'     => Submission_Feedback_Input_Builder::RESULT_VERSION,
		'source_hash'        => $snapshot['official_review_hash'],
	);
	$profile_json = $canonical->encode( $profile );
	$profile_hash = hash( 'sha256', $profile_json );
	$revision_id  = g4_feedback_storage_id();
	$content_hash = hash(
		'sha256',
		$canonical->encode(
			array(
				'audience'             => 'student',
				'evaluation_id'        => $snapshot['evaluation_id'],
				'feedback_hash'        => $profile_hash,
				'previous_revision_id' => null,
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => 1,
				'source_run_id'        => null,
				'state'                => 'teacher_draft',
			)
		)
	);
	return array(
		'actor_user_id'                    => 42,
		'audit_public_id'                  => g4_feedback_storage_id(),
		'command_id'                       => 'command-g4-feedback-storage',
		'command_storage_key'              => $command_key,
		'content_hash'                     => $content_hash,
		'context_id'                       => $snapshot['context_id'],
		'context_revision'                 => $snapshot['context_revision'],
		'context_type'                     => $snapshot['context_type'],
		'correlation_id'                   => 'correlation-g4-feedback-storage',
		'evaluation_public_id'             => $snapshot['evaluation_id'],
		'expected_evaluation_lock_version' => 7,
		'expected_evaluation_state'        => 'reviewed',
		'expected_feedback_revision_hash'  => null,
		'expected_feedback_revision_id'    => null,
		'expected_feedback_revision_no'    => 0,
		'expected_feedback_review_id'      => null,
		'expected_feedback_source_run_id'  => null,
		'expected_feedback_state'          => null,
		'expected_official_review_hash'    => $snapshot['official_review_hash'],
		'expected_official_review_id'      => $snapshot['official_review_id'],
		'feedback_json'                    => $profile_json,
		'feedback_hash'                    => $profile_hash,
		'feedback_schema'                  => Submission_Feedback_Input_Builder::RESULT_SCHEMA,
		'feedback_version'                 => Review_Submission_Feedback::PROFILE_VERSION,
		'occurred_at_utc'                  => '2026-08-24 13:00:00',
		'outbox_public_id'                 => g4_feedback_storage_id(),
		'previous_feedback_revision_id'    => null,
		'reason_digest'                    => null,
		'request_digest'                   => $request_digest,
		'revision_no'                      => 1,
		'revision_public_id'               => $revision_id,
		'source_evaluation_run_public_id'  => null,
		'source_mode'                      => 'direct',
		'state'                            => 'teacher_draft',
	);
}

/**
 * G4 feedback storage rejection record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $source Source.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_key Command key.
 * @param string              $request_digest Request digest.
 * @return array<string,mixed>
 */
function g4_feedback_storage_rejection_record( array $snapshot, array $source, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$reason                                    = 'The feedback must be revised before it can be shown to the learner.';
	$profile                                   = array(
		'reason'               => $reason,
		'schema_id'            => Review_Submission_Feedback::REJECTION_SCHEMA,
		'schema_version'       => '1.0.0',
		'source_feedback_hash' => $source['content_hash'],
		'source_feedback_ref'  => $source['revision_public_id'],
		'source_hash'          => $snapshot['official_review_hash'],
	);
	$profile_json                              = $canonical->encode( $profile );
	$profile_hash                              = hash( 'sha256', $profile_json );
	$record                                    = g4_feedback_storage_record( $snapshot, $canonical, $command_key, $request_digest );
	$record['command_id']                      = 'command-g4-feedback-rejection';
	$record['content_hash']                    = hash(
		'sha256',
		$canonical->encode(
			array(
				'audience'             => 'student',
				'evaluation_id'        => $snapshot['evaluation_id'],
				'feedback_hash'        => $profile_hash,
				'previous_revision_id' => $source['revision_public_id'],
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => 2,
				'source_run_id'        => null,
				'state'                => 'rejected',
			)
		)
	);
	$record['expected_feedback_revision_hash'] = $source['content_hash'];
	$record['expected_feedback_revision_id']   = $source['revision_public_id'];
	$record['expected_feedback_revision_no']   = 1;
	$record['expected_feedback_review_id']     = $snapshot['official_review_id'];
	$record['expected_feedback_source_run_id'] = null;
	$record['expected_feedback_state']         = 'teacher_draft';
	$record['feedback_json']                   = $profile_json;
	$record['feedback_hash']                   = $profile_hash;
	$record['feedback_schema']                 = Review_Submission_Feedback::REJECTION_SCHEMA;
	$record['previous_feedback_revision_id']   = $source['revision_public_id'];
	$record['reason_digest']                   = hash( 'sha256', $reason );
	$record['revision_no']                     = 2;
	$record['source_mode']                     = 'edit';
	$record['state']                           = 'rejected';
	return $record;
}

/**
 * G4 feedback storage publish latest.
 *
 * @param G4_Feedback_Review_Database $database Database.
 * @param array<string,mixed>         $record Record.
 * @return void
 */
function g4_feedback_storage_publish_latest( G4_Feedback_Review_Database $database, array $record ): void {
	$row                                        = $database->tables['oj_ai_submission_feedback_revisions'][0];
	$database->snapshot['feedback_count']       = '1';
	$database->snapshot['latest_feedback_pk']   = (string) $row['id'];
	$database->snapshot['latest_feedback_id']   = $record['revision_public_id'];
	$database->snapshot['latest_feedback_hash'] = $record['content_hash'];
	$database->snapshot['latest_feedback_no']   = '1';
	$database->snapshot['latest_feedback_audience']      = 'student';
	$database->snapshot['latest_feedback_schema']        = $record['feedback_schema'];
	$database->snapshot['latest_feedback_version']       = (string) $record['feedback_version'];
	$database->snapshot['latest_feedback_json']          = $record['feedback_json'];
	$database->snapshot['latest_profile_hash']           = $record['feedback_hash'];
	$database->snapshot['latest_feedback_state']         = $record['state'];
	$database->snapshot['latest_feedback_source_run_id'] = $record['source_evaluation_run_public_id'];
	$database->snapshot['latest_feedback_review_id']     = $record['expected_official_review_id'];
	$database->snapshot['latest_feedback_review_hash']   = $record['expected_official_review_hash'];
	$database->snapshot['latest_previous_feedback_id']   = null;
	$database->snapshot['latest_previous_feedback_hash'] = null;
	$database->snapshot['latest_previous_feedback_no']   = null;
}

$canonical  = new Canonical_Json();
$snapshot   = g4_feedback_storage_snapshot();
$database   = new G4_Feedback_Review_Database( $snapshot );
$repository = new WordPress_Submission_Feedback_Review_Repository( $canonical, $database );
$loaded     = $repository->find_owned_snapshot( 42, $snapshot['evaluation_id'] );
g4_feedback_storage_same( 0, $loaded['latest_feedback_revision_no'] ?? null, 'Fresh C36 source must expose an empty feedback pointer.' );
$command_key    = 'command:' . str_repeat( 'a', 64 );
$request_digest = str_repeat( 'b', 64 );
$record         = g4_feedback_storage_record( $snapshot, $canonical, $command_key, $request_digest );
$result         = $repository->append_revision( $record );
g4_feedback_storage_same( 'teacher_draft', $result->state(), 'C36 storage must append the teacher draft.' );
g4_feedback_storage_same( array( 'START TRANSACTION', 'COMMIT' ), $database->transactions, 'Successful C36 append must commit one transaction.' );
g4_feedback_storage_same( 1, count( $database->tables['oj_ai_submission_feedback_revisions'] ), 'Successful C36 command must append one feedback row.' );
g4_feedback_storage_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'Successful C36 command must append one audit row.' );
g4_feedback_storage_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'Successful C36 command must append one outbox receipt.' );
$outbox_json = (string) $database->tables['oj_ai_outbox_events'][0]['payload_json'];
g4_feedback_storage_assert( ! str_contains( $outbox_json, 'The response is clear.' ) && ! str_contains( $outbox_json, 'criterion_feedback' ), 'C36 outbox receipt must not contain feedback body text.' );
$replayed = $repository->append_revision( $record );
g4_feedback_storage_same( true, $replayed->replayed(), 'Exact C36 storage replay must return the durable receipt.' );
g4_feedback_storage_same( 1, count( $database->tables['oj_ai_submission_feedback_revisions'] ), 'Exact replay must append zero feedback rows.' );
$conflict                   = $record;
$conflict['request_digest'] = str_repeat( 'f', 64 );
g4_feedback_storage_failure( static fn () => $repository->append_revision( $conflict ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Changed request under the same command key must conflict.' );

g4_feedback_storage_publish_latest( $database, $record );
$rejection                                = g4_feedback_storage_rejection_record( $database->snapshot, $record, $canonical, 'command:' . str_repeat( '2', 64 ), str_repeat( '3', 64 ) );
$tampered_rejection                       = $rejection;
$tampered_profile                         = json_decode( $tampered_rejection['feedback_json'], true, 32, JSON_THROW_ON_ERROR );
$tampered_profile['source_feedback_hash'] = str_repeat( '9', 64 );
$tampered_rejection['feedback_json']      = $canonical->encode( $tampered_profile );
$tampered_rejection['feedback_hash']      = hash( 'sha256', $tampered_rejection['feedback_json'] );
$tampered_rejection['content_hash']       = hash(
	'sha256',
	$canonical->encode(
		array(
			'audience'             => 'student',
			'evaluation_id'        => $snapshot['evaluation_id'],
			'feedback_hash'        => $tampered_rejection['feedback_hash'],
			'previous_revision_id' => $record['revision_public_id'],
			'review_id'            => $snapshot['official_review_id'],
			'revision_no'          => 2,
			'source_run_id'        => null,
			'state'                => 'rejected',
		)
	)
);
$tampered_rejected                        = false;
try {
	$repository->append_revision( $tampered_rejection ); } catch ( Submission_Evaluation_Failure $failure ) {
	$tampered_rejected = Submission_Evaluation_Failure::STORED_DATA_INVALID === $failure->reason();
	}
	g4_feedback_storage_assert( $tampered_rejected, 'C36 storage must reject a rejection profile whose source hash does not match the expected latest feedback hash.' );
	g4_feedback_storage_same( 1, count( $database->tables['oj_ai_submission_feedback_revisions'] ), 'Tampered rejection must append zero feedback rows.' );
	$rejected = $repository->append_revision( $rejection );
	g4_feedback_storage_same( 'rejected', $rejected->state(), 'C36 storage must append an immutable rejection revision.' );
	g4_feedback_storage_same( 2, count( $database->tables['oj_ai_submission_feedback_revisions'] ), 'Accepted rejection must append exactly one additional feedback row.' );
	g4_feedback_storage_same( 701, $database->tables['oj_ai_submission_feedback_revisions'][1]['previous_revision_id'], 'Rejected revision must link to the exact prior feedback row.' );
	g4_feedback_storage_assert( ! str_contains( (string) $database->tables['oj_ai_outbox_events'][1]['payload_json'], 'must be revised' ), 'C36 rejection reason must not be copied into the outbox receipt.' );

	$stale_snapshot                                      = g4_feedback_storage_snapshot();
	$stale_database                                      = new G4_Feedback_Review_Database( $stale_snapshot );
	$stale_record                                        = g4_feedback_storage_record( $stale_snapshot, $canonical, 'command:' . str_repeat( 'c', 64 ), str_repeat( 'd', 64 ) );
	$stale_database->snapshot['evaluation_lock_version'] = '8';
	$stale_repository                                    = new WordPress_Submission_Feedback_Review_Repository( $canonical, $stale_database );
	g4_feedback_storage_failure( static fn () => $stale_repository->append_revision( $stale_record ), Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, 'C36 transaction must reject a changed evaluation CAS.' );
	g4_feedback_storage_same( 0, count( $stale_database->tables['oj_ai_submission_feedback_revisions'] ), 'Stale C36 command must append zero feedback rows.' );
	g4_feedback_storage_same( 'ROLLBACK', $stale_database->transactions[ array_key_last( $stale_database->transactions ) ] ?? null, 'Stale C36 command must roll back.' );

	$failure_snapshot                    = g4_feedback_storage_snapshot();
	$failure_database                    = new G4_Feedback_Review_Database( $failure_snapshot );
	$failure_database->fail_insert_table = 'oj_ai_outbox_events';
	$failure_record                      = g4_feedback_storage_record( $failure_snapshot, $canonical, 'command:' . str_repeat( 'e', 64 ), str_repeat( '1', 64 ) );
	$failure_repository                  = new WordPress_Submission_Feedback_Review_Repository( $canonical, $failure_database );
	g4_feedback_storage_failure( static fn () => $failure_repository->append_revision( $failure_record ), Submission_Evaluation_Failure::STORAGE_UNAVAILABLE, 'C36 outbox failure must fail the whole transaction.' );
	g4_feedback_storage_same( 0, count( $failure_database->tables['oj_ai_submission_feedback_revisions'] ), 'Failed C36 transaction must roll back the feedback row.' );
	g4_feedback_storage_same( 0, count( $failure_database->tables['oj_ai_audit_events'] ), 'Failed C36 transaction must roll back the audit row.' );
	g4_feedback_storage_same( 0, count( $failure_database->tables['oj_ai_outbox_events'] ), 'Failed C36 transaction must keep the outbox empty.' );
	g4_feedback_storage_same( 'ROLLBACK', $failure_database->transactions[ array_key_last( $failure_database->transactions ) ] ?? null, 'Failed C36 transaction must roll back.' );

	fwrite( STDOUT, "G4-IMP-04-S02 C36 transaction, CAS, source-hash, durable replay, privacy, and rollback contracts passed.\n" );
