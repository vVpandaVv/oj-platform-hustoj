<?php
/**
 * G4-IMP-04-S05 teacher feedback approval-withdrawal storage transaction fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-approval-storage-g4-imp-04-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G4 feedback withdrawal storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_withdrawal_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback withdrawal storage publish approved.
 *
 * @param G4_Feedback_Review_Database $database Database.
 * @param array<string,mixed>         $draft_record Draft record.
 * @param array<string,mixed>         $approval_record Approval record.
 * @return void
 */
function g4_feedback_withdrawal_storage_publish_approved( G4_Feedback_Review_Database $database, array $draft_record, array $approval_record ): void {
	$row                                        = $database->tables['oj_ai_submission_feedback_revisions'][1];
	$database->snapshot['feedback_count']       = '2';
	$database->snapshot['latest_feedback_pk']   = (string) $row['id'];
	$database->snapshot['latest_feedback_id']   = $approval_record['revision_public_id'];
	$database->snapshot['latest_feedback_hash'] = $approval_record['content_hash'];
	$database->snapshot['latest_feedback_no']   = '2';
	$database->snapshot['latest_feedback_audience']      = 'student';
	$database->snapshot['latest_feedback_schema']        = $approval_record['feedback_schema'];
	$database->snapshot['latest_feedback_version']       = (string) $approval_record['feedback_version'];
	$database->snapshot['latest_feedback_json']          = $approval_record['feedback_json'];
	$database->snapshot['latest_profile_hash']           = $approval_record['feedback_hash'];
	$database->snapshot['latest_feedback_state']         = $approval_record['state'];
	$database->snapshot['latest_feedback_source_run_id'] = $approval_record['source_evaluation_run_public_id'];
	$database->snapshot['latest_feedback_review_id']     = $approval_record['expected_feedback_review_id'];
	$database->snapshot['latest_feedback_review_hash']   = $database->snapshot['official_review_hash'];
	$database->snapshot['latest_previous_feedback_id']   = $draft_record['revision_public_id'];
	$database->snapshot['latest_previous_feedback_hash'] = $draft_record['content_hash'];
	$database->snapshot['latest_previous_feedback_no']   = '1';
}

/**
 * G4 feedback withdrawal storage record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $approval_record Approval record.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_key Command key.
 * @param string              $request_digest Request digest.
 * @return array
 */
function g4_feedback_withdrawal_storage_record( array $snapshot, array $approval_record, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$record                                    = $approval_record;
	$record['audit_public_id']                 = g4_feedback_storage_id();
	$record['command_id']                      = 'command-g4-feedback-withdrawal-storage';
	$record['command_storage_key']             = $command_key;
	$record['content_hash']                    = hash(
		'sha256',
		$canonical->encode(
			array(
				'audience'             => 'student',
				'evaluation_id'        => $snapshot['evaluation_id'],
				'feedback_hash'        => $approval_record['feedback_hash'],
				'previous_revision_id' => $approval_record['revision_public_id'],
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => 3,
				'source_run_id'        => $approval_record['source_evaluation_run_public_id'],
				'state'                => 'teacher_draft',
			)
		)
	);
	$record['correlation_id']                  = 'correlation-g4-feedback-withdrawal-storage';
	$record['expected_feedback_revision_hash'] = $approval_record['content_hash'];
	$record['expected_feedback_revision_id']   = $approval_record['revision_public_id'];
	$record['expected_feedback_revision_no']   = 2;
	$record['expected_feedback_review_id']     = $snapshot['official_review_id'];
	$record['expected_feedback_source_run_id'] = $approval_record['source_evaluation_run_public_id'];
	$record['expected_feedback_state']         = 'approved';
	$record['occurred_at_utc']                 = '2026-08-24 17:00:00';
	$record['outbox_public_id']                = g4_feedback_storage_id();
	$record['previous_feedback_revision_id']   = $approval_record['revision_public_id'];
	$record['reason_digest']                   = null;
	$record['request_digest']                  = $request_digest;
	$record['revision_no']                     = 3;
	$record['revision_public_id']              = g4_feedback_storage_id();
	$record['source_mode']                     = 'withdraw_approval';
	$record['state']                           = 'teacher_draft';
	return $record;
}

g4_feedback_withdrawal_storage_publish_approved( $approval_storage_database, $approval_storage_source, $approval_storage_record );
$withdrawal_storage_before_rows = $approval_storage_database->tables['oj_ai_submission_feedback_revisions'];
$withdrawal_storage_record      = g4_feedback_withdrawal_storage_record( $approval_storage_fixture['snapshot'], $approval_storage_record, $approval_storage_canonical, 'command:' . hash( 'sha256', 'withdrawal-success' ), hash( 'sha256', 'withdrawal-request-success' ) );

$withdrawal_storage_transactions_before = count( $approval_storage_database->transactions );
$withdrawn_storage                      = $approval_storage_repository->append_withdrawn_revision( $withdrawal_storage_record );
g4_feedback_storage_same( 'teacher_draft', $withdrawn_storage->state(), 'Withdrawal storage must append a teacher_draft revision.' );
g4_feedback_storage_same( 3, $withdrawn_storage->feedback_revision_no(), 'Withdrawal storage must be revision 3.' );
g4_feedback_storage_same( $approval_storage_record['revision_public_id'], $withdrawn_storage->previous_revision_id(), 'Withdrawal storage must link to the exact approved row.' );
g4_feedback_storage_same( $withdrawal_storage_transactions_before + 2, count( $approval_storage_database->transactions ), 'Withdrawal must add exactly one transaction start and commit.' );
g4_feedback_storage_same( array( 'START TRANSACTION', 'COMMIT' ), array_slice( $approval_storage_database->transactions, -2 ), 'Successful withdrawal must commit one transaction.' );
g4_feedback_storage_same( 3, count( $approval_storage_database->tables['oj_ai_submission_feedback_revisions'] ), 'Withdrawal must append exactly one new feedback row.' );
foreach ( $withdrawal_storage_before_rows as $index => $before_row ) {
	g4_feedback_storage_same( $before_row, $approval_storage_database->tables['oj_ai_submission_feedback_revisions'][ $index ], 'Withdrawal must never mutate a prior feedback revision row (index ' . $index . ').' );
}
$withdrawn_row = $approval_storage_database->tables['oj_ai_submission_feedback_revisions'][2];
g4_feedback_storage_same( $approval_storage_record['feedback_json'], $withdrawn_row['feedback_json'], 'Withdrawal row must copy the exact approved profile JSON unchanged.' );
g4_feedback_storage_same( $approval_storage_record['feedback_hash'], $withdrawn_row['feedback_hash'], 'Withdrawal row must copy the exact approved profile hash unchanged.' );
g4_feedback_storage_same( 2, count( $approval_storage_database->tables['oj_ai_audit_events'] ) - 1, 'Withdrawal must append exactly one more audit event than the approval already had.' );
$withdrawal_audit  = $approval_storage_database->tables['oj_ai_audit_events'][ array_key_last( $approval_storage_database->tables['oj_ai_audit_events'] ) ];
$withdrawal_outbox = $approval_storage_database->tables['oj_ai_outbox_events'][ array_key_last( $approval_storage_database->tables['oj_ai_outbox_events'] ) ];
g4_feedback_storage_same( 'submission_feedback.approval_withdrawn', $withdrawal_audit['action_key'], 'Withdrawal audit action must be distinct from approval/review.' );
g4_feedback_storage_same( 'oj_ai.submission_feedback_approval_withdrawal_audit', $withdrawal_audit['metadata_schema'], 'Withdrawal audit schema must be distinct.' );
g4_feedback_storage_same( 'submission_feedback.approval_withdrawn', $withdrawal_outbox['event_type'], 'Withdrawal outbox event must be distinct from approval/review.' );
g4_feedback_storage_same( 'oj_ai.submission_feedback_approval_withdrawal_event', $withdrawal_outbox['payload_schema'], 'Withdrawal outbox schema must be distinct.' );
g4_feedback_storage_assert( ! str_contains( (string) $withdrawal_outbox['payload_json'], 'The response is clear.' ), 'Withdrawal outbox receipt must not contain feedback body text.' );

$withdrawal_storage_replay = $approval_storage_repository->append_withdrawn_revision( $withdrawal_storage_record );
g4_feedback_storage_same( true, $withdrawal_storage_replay->replayed(), 'Exact withdrawal storage retry must use the durable receipt.' );
g4_feedback_storage_same( 3, count( $approval_storage_database->tables['oj_ai_submission_feedback_revisions'] ), 'Exact withdrawal retry must append zero additional feedback rows.' );
$withdrawal_storage_conflict                   = $withdrawal_storage_record;
$withdrawal_storage_conflict['request_digest'] = str_repeat( '9', 64 );
g4_feedback_withdrawal_storage_failure(
	static fn () => $approval_storage_repository->append_withdrawn_revision( $withdrawal_storage_conflict ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Changed withdrawal request under one command key must conflict.'
);

$not_yet_approved_fixture    = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'withdrawal-not-approved' );
$not_yet_approved_withdrawal = g4_feedback_withdrawal_storage_record( $not_yet_approved_fixture['snapshot'], $not_yet_approved_fixture['approval'], $approval_storage_canonical, 'command:' . hash( 'sha256', 'withdrawal-not-approved' ), hash( 'sha256', 'withdrawal-not-approved-request' ) );
g4_feedback_withdrawal_storage_failure(
	static fn () => $not_yet_approved_fixture['repository']->append_withdrawn_revision( $not_yet_approved_withdrawal ),
	Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
	'Withdrawal must reject a feedback revision that was never approved (still teacher_draft).'
);
g4_feedback_storage_same( 1, count( $not_yet_approved_fixture['database']->tables['oj_ai_submission_feedback_revisions'] ), 'Rejected non-approved withdrawal must append zero rows.' );

$cas_stale_withdrawal_fixture = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'withdrawal-cas-stale' );
$cas_stale_withdrawal_fixture['repository']->append_approved_revision( $cas_stale_withdrawal_fixture['approval'] );
g4_feedback_withdrawal_storage_publish_approved( $cas_stale_withdrawal_fixture['database'], $cas_stale_withdrawal_fixture['source'], $cas_stale_withdrawal_fixture['approval'] );
$cas_stale_withdrawal_record = g4_feedback_withdrawal_storage_record( $cas_stale_withdrawal_fixture['snapshot'], $cas_stale_withdrawal_fixture['approval'], $approval_storage_canonical, 'command:' . hash( 'sha256', 'withdrawal-cas-stale' ), hash( 'sha256', 'withdrawal-cas-stale-request' ) );
$cas_stale_withdrawal_fixture['database']->snapshot['evaluation_lock_version'] = '9';
g4_feedback_withdrawal_storage_failure(
	static fn () => $cas_stale_withdrawal_fixture['repository']->append_withdrawn_revision( $cas_stale_withdrawal_record ),
	Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
	'Withdrawal must reject a changed evaluation CAS.'
);
g4_feedback_storage_same( 2, count( $cas_stale_withdrawal_fixture['database']->tables['oj_ai_submission_feedback_revisions'] ), 'Stale CAS withdrawal must append zero additional rows.' );

$withdrawal_failure_fixture = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'withdrawal-outbox-failure' );
$withdrawal_failure_fixture['repository']->append_approved_revision( $withdrawal_failure_fixture['approval'] );
g4_feedback_withdrawal_storage_publish_approved( $withdrawal_failure_fixture['database'], $withdrawal_failure_fixture['source'], $withdrawal_failure_fixture['approval'] );
$withdrawal_failure_record                                 = g4_feedback_withdrawal_storage_record( $withdrawal_failure_fixture['snapshot'], $withdrawal_failure_fixture['approval'], $approval_storage_canonical, 'command:' . hash( 'sha256', 'withdrawal-outbox-failure' ), hash( 'sha256', 'withdrawal-outbox-failure-request' ) );
$withdrawal_failure_fixture['database']->fail_insert_table = 'oj_ai_outbox_events';
g4_feedback_withdrawal_storage_failure(
	static fn () => $withdrawal_failure_fixture['repository']->append_withdrawn_revision( $withdrawal_failure_record ),
	Submission_Evaluation_Failure::STORAGE_UNAVAILABLE,
	'Withdrawal outbox failure must fail the whole transaction.'
);
g4_feedback_storage_same( 2, count( $withdrawal_failure_fixture['database']->tables['oj_ai_submission_feedback_revisions'] ), 'Failed withdrawal transaction must preserve only the draft and approved rows.' );
g4_feedback_storage_same( 'ROLLBACK', $withdrawal_failure_fixture['database']->transactions[ array_key_last( $withdrawal_failure_fixture['database']->transactions ) ] ?? null, 'Failed withdrawal transaction must roll back.' );

fwrite( STDOUT, "G4-IMP-04-S05 teacher feedback approval-withdrawal transaction, append-only preservation, CAS, durable replay, and rollback contracts passed.\n" );
