<?php
/**
 * G4-IMP-05-S03 C39/C40 literal-table transaction fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-draft-g4-imp-05-s01.php';
ob_end_clean();
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Student_Note_Query;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

/**
 * G4 note storage assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_storage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
/**
 * G4 note storage same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_storage_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}
/**
 * G4 note storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 note storage id.
 *
 * @return string
 */
function g4_note_storage_id(): string {
	static $counter = 59000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_note_storage_assert( Public_Id::is_valid( $id ), 'Student-note storage ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4-IMP-05-S03 C39/C40 literal-table transaction fixture.
 */
final class G4_Student_Note_Review_Database {
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
		'oj_ai_student_note_revisions' => array(),
		'oj_ai_audit_events'           => array(),
		'oj_ai_outbox_events'          => array(),
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
	 * Initialize g4 student note review database dependencies and state.
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
		if ( str_contains( $sql, 'SELECT note.content_hash' ) ) {
			return null;
		}
		if ( str_contains( $sql, 'SELECT e.public_id AS evaluation_id' ) && str_contains( $sql, 'review_decision' ) ) {
			return array(
				'evaluation_id'                 => $this->snapshot['evaluation_id'],
				'evaluation_state'              => $this->snapshot['evaluation_state'],
				'submission_revision_public_id' => $this->snapshot['submission_revision_id'],
				'submission_revision_hash'      => $this->snapshot['submission_revision_hash'],
				'source_revision_hash'          => $this->snapshot['joined_revision_hash'],
				'official_revision_public_id'   => $this->snapshot['official_revision_public_id'],
				'official_review_id'            => $this->snapshot['official_review_id'],
				'official_review_hash'          => $this->snapshot['official_review_hash'],
				'joined_review_id'              => $this->snapshot['joined_review_id'],
				'joined_review_hash'            => $this->snapshot['joined_review_hash'],
				'review_decision'               => $this->snapshot['official_review_decision'],
				'context_type'                  => $this->snapshot['context_type'],
				'context_id'                    => $this->snapshot['context_id'],
				'context_revision'              => $this->snapshot['context_revision'],
			);
		}
		if ( str_contains( $sql, 'SELECT e.id AS evaluation_pk' ) && str_contains( $sql, 'latest_note_root_id' ) ) {
			return $args[0] === $this->snapshot['evaluation_id'] || $args[1] === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
		}
		if ( str_contains( $sql, 'WHERE note.public_id = %s' ) ) {
			foreach ( $this->tables['oj_ai_student_note_revisions'] as $row ) {
				if ( $row['public_id'] !== $args[0] ) {
					continue;
				}
				$previous_id = null;
				if ( null !== $row['previous_revision_id'] ) {
					foreach ( $this->tables['oj_ai_student_note_revisions'] as $candidate ) {
						if ( ( $candidate['id'] ?? null ) === $row['previous_revision_id'] ) {
							$previous_id = $candidate['public_id'];
						}
					}
				}
				return array(
					'revision_no'                     => $row['revision_no'],
					'state'                           => $row['state'],
					'content_hash'                    => $row['content_hash'],
					'student_note_public_id'          => $row['student_note_public_id'],
					'include_in_reports'              => $row['include_in_reports'],
					'note_schema'                     => $row['note_schema'],
					'note_version'                    => $row['note_version'],
					'note_hash'                       => $row['note_hash'],
					'source_evaluation_run_public_id' => $row['source_evaluation_run_public_id'],
					'approved_by_user_id'             => $row['approved_by_user_id'],
					'approved_at_utc'                 => $row['approved_at_utc'],
					'review_id'                       => $this->snapshot['official_review_id'],
					'previous_id'                     => $previous_id,
					'note_count'                      => count( $this->tables['oj_ai_student_note_revisions'] ),
				);
			}
			return null;
		}
		throw new RuntimeException( 'Unexpected C39/C40 fixture row query: ' . $sql );
	}

	/**
	 * Get results.
	 *
	 * @param string $prepared Prepared.
	 * @param string $format Format.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( string $prepared, string $format ): array {
		unset( $prepared, $format );
		return array();
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
		throw new RuntimeException( 'Unexpected C39/C40 fixture scalar query: ' . $sql );
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
			$this->transactions[] = $sql;
			return 0;
		}
		if ( 'COMMIT' === $sql ) {
			$this->backup         = null;
			$this->transactions[] = $sql;
			return 0;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( is_array( $this->backup ) ) {
				$this->tables   = $this->backup['tables'];
				$this->snapshot = $this->backup['snapshot'];
			}
			$this->backup         = null;
			$this->transactions[] = $sql;
			return 0;
		}
		throw new RuntimeException( 'C39/C40 repository issued forbidden mutable SQL: ' . $sql );
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
			throw new RuntimeException( 'Unexpected C39/C40 insert table: ' . $table );
		}
		if ( 'oj_ai_student_note_revisions' === $table ) {
			$data['id'] = count( $this->tables[ $table ] ) + 801;
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
		return isset( $this->prepared[ $value ] ) ? array( $this->prepared[ $value ]['sql'], $this->prepared[ $value ]['args'] ) : array( $value, array() );
	}
}

/**
 * G4 note storage snapshot.
 *
 * @return array<string,mixed>
 */
function g4_note_storage_snapshot(): array {
	$evaluation_id = g4_note_storage_id();
	$revision_id   = g4_note_storage_id();
	$review_id     = g4_note_storage_id();
	$snapshot      = array(
		'evaluation_pk'               => '61',
		'evaluation_id'               => $evaluation_id,
		'evaluation_lock_version'     => '7',
		'evaluation_state'            => 'reviewed',
		'submission_revision_id'      => $revision_id,
		'submission_revision_hash'    => str_repeat( 'c', 64 ),
		'joined_revision_hash'        => str_repeat( 'c', 64 ),
		'revision_state'              => 'saved',
		'submission_state'            => 'submitted',
		'official_revision_public_id' => $revision_id,
		'official_review_id'          => $review_id,
		'official_review_hash'        => str_repeat( 'd', 64 ),
		'context_type'                => 'class',
		'context_id'                  => 'class-g4-note-storage',
		'context_revision'            => 'class-g4-note-storage-r1',
		'assignment_state'            => 'open',
		'target_state'                => 'active',
		'official_review_pk'          => '62',
		'joined_review_id'            => $review_id,
		'joined_review_hash'          => str_repeat( 'd', 64 ),
		'official_review_decision'    => 'accepted',
		'note_count'                  => '0',
	);
	foreach ( array( 'latest_revision_pk', 'latest_revision_id', 'latest_revision_hash', 'latest_note_root_id', 'latest_revision_no', 'latest_state', 'latest_include_in_reports', 'latest_note_schema', 'latest_note_version', 'latest_note_json', 'latest_note_hash', 'latest_source_run_id', 'latest_approved_by', 'latest_approved_at', 'latest_review_id', 'latest_review_hash', 'previous_revision_id', 'previous_revision_hash', 'previous_revision_no', 'previous_note_root_id', 'joined_source_run_id', 'source_run_subject_type', 'source_run_evaluation_id', 'source_run_operation', 'source_run_state', 'source_run_result_type', 'source_run_result_id' ) as $key ) {
		$snapshot[ $key ] = null;
	}
	return $snapshot;
}

/**
 * G4 note storage profile.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $review_hash Review hash.
 * @return array{json:string,hash:string}
 */
function g4_note_storage_profile( Canonical_Json $canonical, string $review_hash ): array {
	$source                         = g4_note_source( $canonical, 3 );
	$source['official_review_hash'] = $review_hash;
	$gateway                        = ( new Student_Note_Input_Builder( $canonical ) )->build( $source )->gateway_input();
	$normalized                     = ( new Student_Note_Output_Normalizer( $canonical ) )->normalize( g4_note_output( $gateway ), $gateway );
	return array(
		'json' => $normalized->profile_json(),
		'hash' => $normalized->profile_hash(),
	);
}

/**
 * G4 note storage review record.
 *
 * @param array<string,mixed>            $snapshot Snapshot.
 * @param array{json:string,hash:string} $profile Profile.
 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
 * @param string                         $command_key Command key.
 * @param string                         $request_digest Request digest.
 * @return array<string,mixed>
 */
function g4_note_storage_review_record( array $snapshot, array $profile, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$revision_id  = g4_note_storage_id();
	$content_hash = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'        => $snapshot['evaluation_id'],
				'include_in_reports'   => false,
				'note_hash'            => $profile['hash'],
				'note_root_id'         => $snapshot['evaluation_id'],
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
		'audit_public_id'                  => g4_note_storage_id(),
		'command_id'                       => 'command-g4-note-storage-review',
		'command_storage_key'              => $command_key,
		'content_hash'                     => $content_hash,
		'context_id'                       => $snapshot['context_id'],
		'context_revision'                 => $snapshot['context_revision'],
		'context_type'                     => $snapshot['context_type'],
		'correlation_id'                   => 'correlation-g4-note-storage',
		'evaluation_public_id'             => $snapshot['evaluation_id'],
		'expected_evaluation_lock_version' => 7,
		'expected_evaluation_state'        => 'reviewed',
		'expected_include_in_reports'      => false,
		'expected_note_state'              => null,
		'expected_official_review_hash'    => $snapshot['official_review_hash'],
		'expected_official_review_id'      => $snapshot['official_review_id'],
		'expected_revision_hash'           => null,
		'expected_revision_id'             => null,
		'expected_revision_no'             => 0,
		'expected_source_run_id'           => null,
		'include_in_reports'               => false,
		'note_hash'                        => $profile['hash'],
		'note_json'                        => $profile['json'],
		'note_root_id'                     => $snapshot['evaluation_id'],
		'note_schema'                      => Student_Note_Input_Builder::RESULT_SCHEMA,
		'note_version'                     => 1,
		'occurred_at_utc'                  => '2026-08-24 19:00:00',
		'outbox_public_id'                 => g4_note_storage_id(),
		'previous_revision_id'             => null,
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
 * G4 note storage publish review.
 *
 * @param G4_Student_Note_Review_Database $database Database.
 * @param array<string,mixed>             $snapshot Snapshot.
 * @param array<string,mixed>             $review Review.
 * @return void
 */
function g4_note_storage_publish_review( G4_Student_Note_Review_Database $database, array $snapshot, array $review ): void {
	$row                                        = $database->tables['oj_ai_student_note_revisions'][0];
	$database->snapshot['note_count']           = '1';
	$database->snapshot['latest_revision_pk']   = (string) $row['id'];
	$database->snapshot['latest_revision_id']   = $review['revision_public_id'];
	$database->snapshot['latest_revision_hash'] = $review['content_hash'];
	$database->snapshot['latest_note_root_id']  = $review['note_root_id'];
	$database->snapshot['latest_revision_no']   = '1';
	$database->snapshot['latest_state']         = 'teacher_draft';
	$database->snapshot['latest_include_in_reports'] = '0';
	$database->snapshot['latest_note_schema']        = $review['note_schema'];
	$database->snapshot['latest_note_version']       = '1';
	$database->snapshot['latest_note_json']          = $review['note_json'];
	$database->snapshot['latest_note_hash']          = $review['note_hash'];
	$database->snapshot['latest_source_run_id']      = null;
	$database->snapshot['latest_approved_by']        = null;
	$database->snapshot['latest_approved_at']        = null;
	$database->snapshot['latest_review_id']          = $snapshot['official_review_id'];
	$database->snapshot['latest_review_hash']        = $snapshot['official_review_hash'];
}

/**
 * G4 note storage approval record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $review Review.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_key Command key.
 * @param string              $request_digest Request digest.
 * @return array<string,mixed>
 */
function g4_note_storage_approval_record( array $snapshot, array $review, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$record                           = g4_note_storage_review_record(
		$snapshot,
		array(
			'json' => $review['note_json'],
			'hash' => $review['note_hash'],
		),
		$canonical,
		$command_key,
		$request_digest
	);
	$record['audit_public_id']        = g4_note_storage_id();
	$record['command_id']             = 'command-g4-note-storage-approval';
	$record['expected_note_state']    = 'teacher_draft';
	$record['expected_revision_hash'] = $review['content_hash'];
	$record['expected_revision_id']   = $review['revision_public_id'];
	$record['expected_revision_no']   = 1;
	$record['occurred_at_utc']        = '2026-08-24 19:01:00';
	$record['outbox_public_id']       = g4_note_storage_id();
	$record['previous_revision_id']   = $review['revision_public_id'];
	$record['revision_no']            = 2;
	$record['revision_public_id']     = g4_note_storage_id();
	$record['source_mode']            = 'approve';
	$record['state']                  = 'approved';
	$record['content_hash']           = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'        => $snapshot['evaluation_id'],
				'include_in_reports'   => false,
				'note_hash'            => $review['note_hash'],
				'note_root_id'         => $snapshot['evaluation_id'],
				'previous_revision_id' => $review['revision_public_id'],
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => 2,
				'source_run_id'        => null,
				'state'                => 'approved',
			)
		)
	);
	return $record;
}

$storage_canonical  = new Canonical_Json();
$storage_snapshot   = g4_note_storage_snapshot();
$storage_profile    = g4_note_storage_profile( $storage_canonical, $storage_snapshot['official_review_hash'] );
$storage_database   = new G4_Student_Note_Review_Database( $storage_snapshot );
$storage_repository = new WordPress_Student_Note_Review_Repository( $storage_canonical, new Student_Note_Output_Normalizer( $storage_canonical ), $storage_database );
$loaded             = $storage_repository->find_owned_review_snapshot( 42, $storage_snapshot['evaluation_id'] );
g4_note_storage_same( 0, $loaded['latest_revision_no'] ?? null, 'A fresh C39 source must expose an empty revision pointer.' );
g4_note_storage_same( $storage_snapshot['evaluation_id'], $loaded['note_root_id'] ?? null, 'A fresh C39 source must use the evaluation PublicId as its note root.' );
$authorization_query   = new WordPress_Student_Note_Query( $storage_canonical, new Student_Note_Output_Normalizer( $storage_canonical ), null, $storage_database );
$initial_authorization = $authorization_query->authorization_snapshot( $storage_snapshot['evaluation_id'], $storage_snapshot['context_type'], $storage_snapshot['context_id'], $storage_snapshot['context_revision'] );
g4_note_storage_same( $storage_snapshot['official_review_hash'], $initial_authorization['current_revision'] ?? null, 'Initial C39 authorization must use the official review hash as its CAS revision.' );
g4_note_storage_same( 'reviewed', $initial_authorization['state'] ?? null, 'Initial C39 authorization must expose the reviewed evaluation state.' );
g4_note_storage_same( 'context_staff', $initial_authorization['relation'] ?? null, 'Initial C39 authorization must remain educator-only.' );

$review_key    = 'command:' . str_repeat( 'a', 64 );
$review_digest = str_repeat( 'b', 64 );
$review_record = g4_note_storage_review_record( $storage_snapshot, $storage_profile, $storage_canonical, $review_key, $review_digest );
$stored_review = $storage_repository->append_review_revision( $review_record );
g4_note_storage_same( 'teacher_draft', $stored_review->state(), 'C39 storage must append a teacher draft.' );
g4_note_storage_same( array( 'START TRANSACTION', 'COMMIT' ), $storage_database->transactions, 'Successful C39 storage must commit one transaction.' );
g4_note_storage_same( 1, count( $storage_database->tables['oj_ai_student_note_revisions'] ), 'C39 must append one immutable note row.' );
g4_note_storage_same( 1, count( $storage_database->tables['oj_ai_audit_events'] ), 'C39 must append one audit row.' );
g4_note_storage_same( 1, count( $storage_database->tables['oj_ai_outbox_events'] ), 'C39 must append one durable outbox receipt.' );
$review_outbox = (string) $storage_database->tables['oj_ai_outbox_events'][0]['payload_json'];
g4_note_storage_assert( ! str_contains( $review_outbox, 'overall_feedback' ) && ! str_contains( $review_outbox, 'support_suggestions' ), 'C39 outbox receipt must not contain student-note body text.' );

$replayed = $storage_repository->append_review_revision( $review_record );
g4_note_storage_same( true, $replayed->replayed(), 'Exact C39 storage retry must replay the durable receipt.' );
g4_note_storage_same( 1, count( $storage_database->tables['oj_ai_student_note_revisions'] ), 'C39 replay must append no second row.' );
$conflict                   = $review_record;
$conflict['request_digest'] = str_repeat( 'f', 64 );
g4_note_storage_failure( static fn () => $storage_repository->append_review_revision( $conflict ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Changed C39 request under one command key must conflict.' );

g4_note_storage_publish_review( $storage_database, $storage_snapshot, $review_record );
$approval_record = g4_note_storage_approval_record( $storage_snapshot, $review_record, $storage_canonical, 'command:' . str_repeat( '2', 64 ), str_repeat( '3', 64 ) );
$stored_approval = $storage_repository->append_approved_revision( $approval_record );
g4_note_storage_same( 'approved', $stored_approval->state(), 'C40 storage must append an approved revision.' );
g4_note_storage_same( 2, count( $storage_database->tables['oj_ai_student_note_revisions'] ), 'C40 must append exactly one additional immutable row.' );
g4_note_storage_same( 801, $storage_database->tables['oj_ai_student_note_revisions'][1]['previous_revision_id'], 'C40 must link to the exact teacher draft database row.' );
g4_note_storage_same( 42, $storage_database->tables['oj_ai_student_note_revisions'][1]['approved_by_user_id'], 'C40 must record the approving educator.' );
g4_note_storage_same( '2026-08-24 19:01:00', $storage_database->tables['oj_ai_student_note_revisions'][1]['approved_at_utc'], 'C40 must record the approval time.' );
g4_note_storage_same( 0, $storage_database->tables['oj_ai_student_note_revisions'][1]['include_in_reports'], 'C40 must not enable report inclusion.' );

$stale_snapshot                                      = g4_note_storage_snapshot();
$stale_profile                                       = g4_note_storage_profile( $storage_canonical, $stale_snapshot['official_review_hash'] );
$stale_record                                        = g4_note_storage_review_record( $stale_snapshot, $stale_profile, $storage_canonical, 'command:' . str_repeat( 'c', 64 ), str_repeat( 'd', 64 ) );
$stale_database                                      = new G4_Student_Note_Review_Database( $stale_snapshot );
$stale_database->snapshot['evaluation_lock_version'] = '8';
$stale_repository                                    = new WordPress_Student_Note_Review_Repository( $storage_canonical, new Student_Note_Output_Normalizer( $storage_canonical ), $stale_database );
g4_note_storage_failure( static fn () => $stale_repository->append_review_revision( $stale_record ), Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, 'C39 transaction must reject a changed evaluation CAS.' );
g4_note_storage_same( 0, count( $stale_database->tables['oj_ai_student_note_revisions'] ), 'Stale C39 command must append zero rows.' );
g4_note_storage_same( 'ROLLBACK', $stale_database->transactions[ array_key_last( $stale_database->transactions ) ] ?? null, 'Stale C39 command must roll back.' );

$failure_snapshot                    = g4_note_storage_snapshot();
$failure_profile                     = g4_note_storage_profile( $storage_canonical, $failure_snapshot['official_review_hash'] );
$failure_record                      = g4_note_storage_review_record( $failure_snapshot, $failure_profile, $storage_canonical, 'command:' . str_repeat( 'e', 64 ), str_repeat( '1', 64 ) );
$failure_database                    = new G4_Student_Note_Review_Database( $failure_snapshot );
$failure_database->fail_insert_table = 'oj_ai_outbox_events';
$failure_repository                  = new WordPress_Student_Note_Review_Repository( $storage_canonical, new Student_Note_Output_Normalizer( $storage_canonical ), $failure_database );
g4_note_storage_failure( static fn () => $failure_repository->append_review_revision( $failure_record ), Submission_Evaluation_Failure::STORAGE_UNAVAILABLE, 'C39 outbox failure must fail the whole transaction.' );
g4_note_storage_same( 0, count( $failure_database->tables['oj_ai_student_note_revisions'] ), 'Failed C39 transaction must roll back its note row.' );
g4_note_storage_same( 0, count( $failure_database->tables['oj_ai_audit_events'] ), 'Failed C39 transaction must roll back its audit row.' );
g4_note_storage_same( 0, count( $failure_database->tables['oj_ai_outbox_events'] ), 'Failed C39 transaction must leave no outbox receipt.' );
g4_note_storage_same( 'ROLLBACK', $failure_database->transactions[ array_key_last( $failure_database->transactions ) ] ?? null, 'Failed C39 transaction must roll back.' );

fwrite( STDOUT, "G4-IMP-05-S03 C39/C40 transaction, CAS, immutable append, durable replay, privacy, approval, and rollback contracts passed.\n" );
