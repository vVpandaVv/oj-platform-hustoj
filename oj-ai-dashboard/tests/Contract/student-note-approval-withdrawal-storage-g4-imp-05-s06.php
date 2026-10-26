<?php
/**
 * G4-IMP-05-S06 student-note approval-withdrawal literal-table transaction fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-review-approval-storage-g4-imp-05-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G4 note withdrawal storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_withdrawal_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 note storage publish approval.
 *
 * @param G4_Student_Note_Review_Database $database Database.
 * @param array<string,mixed>             $review_record Review record.
 * @param array<string,mixed>             $approval_record Approval record.
 * @return void
 */
function g4_note_storage_publish_approval( G4_Student_Note_Review_Database $database, array $review_record, array $approval_record ): void {
	$row                                        = $database->tables['oj_ai_student_note_revisions'][1];
	$database->snapshot['note_count']           = '2';
	$database->snapshot['latest_revision_pk']   = (string) $row['id'];
	$database->snapshot['latest_revision_id']   = $approval_record['revision_public_id'];
	$database->snapshot['latest_revision_hash'] = $approval_record['content_hash'];
	$database->snapshot['latest_note_root_id']  = $approval_record['note_root_id'];
	$database->snapshot['latest_revision_no']   = '2';
	$database->snapshot['latest_state']         = 'approved';
	$database->snapshot['latest_include_in_reports'] = '0';
	$database->snapshot['latest_note_schema']        = $approval_record['note_schema'];
	$database->snapshot['latest_note_version']       = '1';
	$database->snapshot['latest_note_json']          = $approval_record['note_json'];
	$database->snapshot['latest_note_hash']          = $approval_record['note_hash'];
	$database->snapshot['latest_source_run_id']      = null;
	$database->snapshot['latest_approved_by']        = $approval_record['actor_user_id'];
	$database->snapshot['latest_approved_at']        = $approval_record['occurred_at_utc'];
	$database->snapshot['latest_review_id']          = $database->snapshot['official_review_id'];
	$database->snapshot['latest_review_hash']        = $database->snapshot['official_review_hash'];
	$database->snapshot['previous_revision_id']      = $review_record['revision_public_id'];
	$database->snapshot['previous_revision_hash']    = $review_record['content_hash'];
	$database->snapshot['previous_revision_no']      = '1';
	$database->snapshot['previous_note_root_id']     = $review_record['note_root_id'];
}

/**
 * G4 note withdrawal fixture.
 *
 * @param string $suffix Suffix.
 * @return array{canonical:Canonical_Json,snapshot:array<string,mixed>,database:G4_Student_Note_Review_Database,repository:WordPress_Student_Note_Review_Repository,review:array<string,mixed>,approval:array<string,mixed>}
 */
function g4_note_withdrawal_fixture( string $suffix ): array {
	$canonical     = new Canonical_Json();
	$snapshot      = g4_note_storage_snapshot();
	$profile       = g4_note_storage_profile( $canonical, $snapshot['official_review_hash'] );
	$database      = new G4_Student_Note_Review_Database( $snapshot );
	$repository    = new WordPress_Student_Note_Review_Repository( $canonical, new Student_Note_Output_Normalizer( $canonical ), $database );
	$review_record = g4_note_storage_review_record( $snapshot, $profile, $canonical, 'command:' . hash( 'sha256', 'review-' . $suffix ), hash( 'sha256', 'review-request-' . $suffix ) );
	$repository->append_review_revision( $review_record );
	g4_note_storage_publish_review( $database, $snapshot, $review_record );
	$approval_record = g4_note_storage_approval_record( $snapshot, $review_record, $canonical, 'command:' . hash( 'sha256', 'approval-' . $suffix ), hash( 'sha256', 'approval-request-' . $suffix ) );
	$repository->append_approved_revision( $approval_record );
	g4_note_storage_publish_approval( $database, $review_record, $approval_record );
	return array(
		'canonical'  => $canonical,
		'snapshot'   => $snapshot,
		'database'   => $database,
		'repository' => $repository,
		'review'     => $review_record,
		'approval'   => $approval_record,
	);
}

/**
 * G4 note storage withdrawal record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $source_record Source record.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_key Command key.
 * @param string              $request_digest Request digest.
 * @return array<string,mixed>
 */
function g4_note_storage_withdrawal_record( array $snapshot, array $source_record, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$record                                = $source_record;
	$next_no                               = $source_record['revision_no'] + 1;
	$record['audit_public_id']             = g4_note_storage_id();
	$record['command_id']                  = 'command-g4-note-storage-withdrawal';
	$record['command_storage_key']         = $command_key;
	$record['content_hash']                = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'        => $snapshot['evaluation_id'],
				'include_in_reports'   => false,
				'note_hash'            => $source_record['note_hash'],
				'note_root_id'         => $snapshot['evaluation_id'],
				'previous_revision_id' => $source_record['revision_public_id'],
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => $next_no,
				'source_run_id'        => null,
				'state'                => 'teacher_draft',
			)
		)
	);
	$record['correlation_id']              = 'correlation-g4-note-storage-withdrawal';
	$record['expected_include_in_reports'] = false;
	$record['expected_note_state']         = 'approved';
	$record['expected_revision_hash']      = $source_record['content_hash'];
	$record['expected_revision_id']        = $source_record['revision_public_id'];
	$record['expected_revision_no']        = $source_record['revision_no'];
	$record['expected_source_run_id']      = null;
	$record['occurred_at_utc']             = '2026-08-24 19:02:00';
	$record['outbox_public_id']            = g4_note_storage_id();
	$record['previous_revision_id']        = $source_record['revision_public_id'];
	$record['reason_digest']               = null;
	$record['request_digest']              = $request_digest;
	$record['revision_no']                 = $next_no;
	$record['revision_public_id']          = g4_note_storage_id();
	$record['source_mode']                 = 'withdraw_approval';
	$record['state']                       = 'teacher_draft';
	return $record;
}

$withdrawal_fixture       = g4_note_withdrawal_fixture( 'success' );
$withdrawal_before_rows   = $withdrawal_fixture['database']->tables['oj_ai_student_note_revisions'];
$withdrawal_audit_before  = count( $withdrawal_fixture['database']->tables['oj_ai_audit_events'] );
$withdrawal_outbox_before = count( $withdrawal_fixture['database']->tables['oj_ai_outbox_events'] );
$withdrawal_record        = g4_note_storage_withdrawal_record( $withdrawal_fixture['snapshot'], $withdrawal_fixture['approval'], $withdrawal_fixture['canonical'], 'command:' . hash( 'sha256', 'withdrawal-success' ), hash( 'sha256', 'withdrawal-request-success' ) );

$withdrawal_transactions_before = count( $withdrawal_fixture['database']->transactions );
$withdrawn_storage              = $withdrawal_fixture['repository']->append_withdrawn_revision( $withdrawal_record );
g4_note_storage_same( 'teacher_draft', $withdrawn_storage->state(), 'Withdrawal storage must append a teacher_draft revision.' );
g4_note_storage_same( 3, $withdrawn_storage->revision_no(), 'Withdrawal storage must be revision 3.' );
g4_note_storage_same( $withdrawal_fixture['approval']['revision_public_id'], $withdrawn_storage->previous_revision_id(), 'Withdrawal storage must link to the exact approved row.' );
g4_note_storage_same( false, $withdrawn_storage->include_in_reports(), 'Withdrawal storage must not resurrect report inclusion.' );
g4_note_storage_same( $withdrawal_transactions_before + 2, count( $withdrawal_fixture['database']->transactions ), 'Withdrawal must add exactly one transaction start and commit.' );
g4_note_storage_same( array( 'START TRANSACTION', 'COMMIT' ), array_slice( $withdrawal_fixture['database']->transactions, -2 ), 'Successful withdrawal must commit one transaction.' );
g4_note_storage_same( 3, count( $withdrawal_fixture['database']->tables['oj_ai_student_note_revisions'] ), 'Withdrawal must append exactly one new note row.' );
foreach ( $withdrawal_before_rows as $index => $before_row ) {
	g4_note_storage_same( $before_row, $withdrawal_fixture['database']->tables['oj_ai_student_note_revisions'][ $index ], 'Withdrawal must never mutate a prior note revision row (index ' . $index . ').' );
}
$withdrawn_row = $withdrawal_fixture['database']->tables['oj_ai_student_note_revisions'][2];
g4_note_storage_same( $withdrawal_fixture['approval']['note_json'], $withdrawn_row['note_json'], 'Withdrawal row must copy the exact approved profile JSON unchanged.' );
g4_note_storage_same( $withdrawal_fixture['approval']['note_hash'], $withdrawn_row['note_hash'], 'Withdrawal row must copy the exact approved profile hash unchanged.' );
g4_note_storage_same( 0, $withdrawn_row['include_in_reports'], 'Withdrawal row must never enable report inclusion.' );
g4_note_storage_same( null, $withdrawn_row['approved_by_user_id'], 'Withdrawal row must not record a new approver.' );
g4_note_storage_same( null, $withdrawn_row['approved_at_utc'], 'Withdrawal row must not record a new approval time.' );
g4_note_storage_same( $withdrawal_audit_before + 1, count( $withdrawal_fixture['database']->tables['oj_ai_audit_events'] ), 'Withdrawal must append exactly one more audit event than review+approval already had.' );
g4_note_storage_same( $withdrawal_outbox_before + 1, count( $withdrawal_fixture['database']->tables['oj_ai_outbox_events'] ), 'Withdrawal must append exactly one more outbox receipt than review+approval already had.' );
$withdrawal_audit  = $withdrawal_fixture['database']->tables['oj_ai_audit_events'][ array_key_last( $withdrawal_fixture['database']->tables['oj_ai_audit_events'] ) ];
$withdrawal_outbox = $withdrawal_fixture['database']->tables['oj_ai_outbox_events'][ array_key_last( $withdrawal_fixture['database']->tables['oj_ai_outbox_events'] ) ];
g4_note_storage_same( 'student_note.approval_withdrawn', $withdrawal_audit['action_key'], 'Withdrawal audit action must be distinct from review/approval.' );
g4_note_storage_same( 'oj_ai.student_note_audit', $withdrawal_audit['metadata_schema'], 'Withdrawal audit must reuse the shared student-note audit schema.' );
g4_note_storage_same( 'student_note.approval_withdrawn', $withdrawal_outbox['event_type'], 'Withdrawal outbox event must be distinct from review/approval.' );
g4_note_storage_same( 'oj_ai.student_note_revision_event', $withdrawal_outbox['payload_schema'], 'Withdrawal outbox must reuse the shared student-note outbox schema.' );
g4_note_storage_assert( ! str_contains( (string) $withdrawal_outbox['payload_json'], 'support_suggestions' ), 'Withdrawal outbox receipt must not contain student-note body text.' );

$withdrawal_storage_replay = $withdrawal_fixture['repository']->append_withdrawn_revision( $withdrawal_record );
g4_note_storage_same( true, $withdrawal_storage_replay->replayed(), 'Exact withdrawal storage retry must use the durable receipt.' );
g4_note_storage_same( 3, count( $withdrawal_fixture['database']->tables['oj_ai_student_note_revisions'] ), 'Exact withdrawal retry must append zero additional note rows.' );
$withdrawal_conflict                   = $withdrawal_record;
$withdrawal_conflict['request_digest'] = str_repeat( '9', 64 );
g4_note_withdrawal_storage_failure(
	static fn () => $withdrawal_fixture['repository']->append_withdrawn_revision( $withdrawal_conflict ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Changed withdrawal request under one command key must conflict.'
);

$not_yet_approved_canonical  = new Canonical_Json();
$not_yet_approved_snapshot   = g4_note_storage_snapshot();
$not_yet_approved_profile    = g4_note_storage_profile( $not_yet_approved_canonical, $not_yet_approved_snapshot['official_review_hash'] );
$not_yet_approved_database   = new G4_Student_Note_Review_Database( $not_yet_approved_snapshot );
$not_yet_approved_repository = new WordPress_Student_Note_Review_Repository( $not_yet_approved_canonical, new Student_Note_Output_Normalizer( $not_yet_approved_canonical ), $not_yet_approved_database );
$not_yet_approved_review     = g4_note_storage_review_record( $not_yet_approved_snapshot, $not_yet_approved_profile, $not_yet_approved_canonical, 'command:' . hash( 'sha256', 'review-not-approved' ), hash( 'sha256', 'review-request-not-approved' ) );
$not_yet_approved_repository->append_review_revision( $not_yet_approved_review );
g4_note_storage_publish_review( $not_yet_approved_database, $not_yet_approved_snapshot, $not_yet_approved_review );
$not_yet_approved_withdrawal = g4_note_storage_withdrawal_record( $not_yet_approved_snapshot, $not_yet_approved_review, $not_yet_approved_canonical, 'command:' . hash( 'sha256', 'withdrawal-not-approved' ), hash( 'sha256', 'withdrawal-not-approved-request' ) );
g4_note_withdrawal_storage_failure(
	static fn () => $not_yet_approved_repository->append_withdrawn_revision( $not_yet_approved_withdrawal ),
	Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
	'Withdrawal must reject a note revision that was never approved (still teacher_draft).'
);
g4_note_storage_same( 1, count( $not_yet_approved_database->tables['oj_ai_student_note_revisions'] ), 'Rejected non-approved withdrawal must append zero rows.' );

$cas_stale_fixture = g4_note_withdrawal_fixture( 'cas-stale' );
$cas_stale_fixture['database']->snapshot['evaluation_lock_version'] = '9';
$cas_stale_record = g4_note_storage_withdrawal_record( $cas_stale_fixture['snapshot'], $cas_stale_fixture['approval'], $cas_stale_fixture['canonical'], 'command:' . hash( 'sha256', 'withdrawal-cas-stale' ), hash( 'sha256', 'withdrawal-cas-stale-request' ) );
g4_note_withdrawal_storage_failure(
	static fn () => $cas_stale_fixture['repository']->append_withdrawn_revision( $cas_stale_record ),
	Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
	'Withdrawal must reject a changed evaluation CAS.'
);
g4_note_storage_same( 2, count( $cas_stale_fixture['database']->tables['oj_ai_student_note_revisions'] ), 'Stale CAS withdrawal must append zero additional rows.' );

$outbox_failure_fixture                                = g4_note_withdrawal_fixture( 'outbox-failure' );
$outbox_failure_record                                 = g4_note_storage_withdrawal_record( $outbox_failure_fixture['snapshot'], $outbox_failure_fixture['approval'], $outbox_failure_fixture['canonical'], 'command:' . hash( 'sha256', 'withdrawal-outbox-failure' ), hash( 'sha256', 'withdrawal-outbox-failure-request' ) );
$outbox_failure_fixture['database']->fail_insert_table = 'oj_ai_outbox_events';
g4_note_withdrawal_storage_failure(
	static fn () => $outbox_failure_fixture['repository']->append_withdrawn_revision( $outbox_failure_record ),
	Submission_Evaluation_Failure::STORAGE_UNAVAILABLE,
	'Withdrawal outbox failure must fail the whole transaction.'
);
g4_note_storage_same( 2, count( $outbox_failure_fixture['database']->tables['oj_ai_student_note_revisions'] ), 'Failed withdrawal transaction must preserve only the review and approved rows.' );
g4_note_storage_same( 'ROLLBACK', $outbox_failure_fixture['database']->transactions[ array_key_last( $outbox_failure_fixture['database']->transactions ) ] ?? null, 'Failed withdrawal transaction must roll back.' );

fwrite( STDOUT, "G4-IMP-05-S06 student-note approval-withdrawal transaction, append-only preservation, CAS, durable replay, and rollback contracts passed.\n" );
