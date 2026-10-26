<?php
/**
 * G4-IMP-04-S03 C37 approval storage transaction fixture.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-review-storage-g4-imp-04-s02.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Feedback_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G4 feedback approval storage failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_approval_storage_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_storage_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback approval storage record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $source Source.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_key Command key.
 * @param string              $request_digest Request digest.
 * @return array
 */
function g4_feedback_approval_storage_record( array $snapshot, array $source, Canonical_Json $canonical, string $command_key, string $request_digest ): array {
	$record                                    = $source;
	$record['audit_public_id']                 = g4_feedback_storage_id();
	$record['command_id']                      = 'command-g4-feedback-approval-storage';
	$record['command_storage_key']             = $command_key;
	$record['content_hash']                    = hash(
		'sha256',
		$canonical->encode(
			array(
				'audience'             => 'student',
				'evaluation_id'        => $snapshot['evaluation_id'],
				'feedback_hash'        => $source['feedback_hash'],
				'previous_revision_id' => $source['revision_public_id'],
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => 2,
				'source_run_id'        => $source['source_evaluation_run_public_id'],
				'state'                => 'approved',
			)
		)
	);
	$record['correlation_id']                  = 'correlation-g4-feedback-approval-storage';
	$record['expected_feedback_revision_hash'] = $source['content_hash'];
	$record['expected_feedback_revision_id']   = $source['revision_public_id'];
	$record['expected_feedback_revision_no']   = 1;
	$record['expected_feedback_review_id']     = $snapshot['official_review_id'];
	$record['expected_feedback_source_run_id'] = $source['source_evaluation_run_public_id'];
	$record['expected_feedback_state']         = 'teacher_draft';
	$record['occurred_at_utc']                 = '2026-08-24 16:00:00';
	$record['outbox_public_id']                = g4_feedback_storage_id();
	$record['previous_feedback_revision_id']   = $source['revision_public_id'];
	$record['reason_digest']                   = null;
	$record['request_digest']                  = $request_digest;
	$record['revision_no']                     = 2;
	$record['revision_public_id']              = g4_feedback_storage_id();
	$record['source_mode']                     = 'approve';
	$record['state']                           = 'approved';
	return $record;
}

/**
 * G4 feedback approval storage fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $suffix Suffix.
 * @return array{snapshot:array<string,mixed>,database:G4_Feedback_Review_Database,repository:WordPress_Submission_Feedback_Review_Repository,source:array<string,mixed>,approval:array<string,mixed>}
 */
function g4_feedback_approval_storage_fixture( Canonical_Json $canonical, string $suffix ): array {
	$snapshot   = g4_feedback_storage_snapshot();
	$database   = new G4_Feedback_Review_Database( $snapshot );
	$repository = new WordPress_Submission_Feedback_Review_Repository( $canonical, $database );
	$source     = g4_feedback_storage_record( $snapshot, $canonical, 'command:' . hash( 'sha256', 'source-' . $suffix ), hash( 'sha256', 'source-request-' . $suffix ) );
	$repository->append_revision( $source );
	g4_feedback_storage_publish_latest( $database, $source );
	$approval = g4_feedback_approval_storage_record( $database->snapshot, $source, $canonical, 'command:' . hash( 'sha256', 'approval-' . $suffix ), hash( 'sha256', 'approval-request-' . $suffix ) );
	return array(
		'snapshot'   => $snapshot,
		'database'   => $database,
		'repository' => $repository,
		'source'     => $source,
		'approval'   => $approval,
	);
}

$approval_storage_canonical  = new Canonical_Json();
$approval_storage_fixture    = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'success' );
$approval_storage_database   = $approval_storage_fixture['database'];
$approval_storage_repository = $approval_storage_fixture['repository'];
$approval_storage_source     = $approval_storage_fixture['source'];
$approval_storage_record     = $approval_storage_fixture['approval'];

$loaded_approval = $approval_storage_repository->find_owned_approval_snapshot( 42, $approval_storage_fixture['snapshot']['evaluation_id'] );
g4_feedback_storage_same( 'teacher_draft', $loaded_approval['latest_feedback_state'] ?? null, 'C37 source query must expose the exact teacher draft state.' );
g4_feedback_storage_same( $approval_storage_source['feedback_json'], $loaded_approval['latest_feedback_json'] ?? null, 'C37 source query must expose the canonical teacher draft profile.' );
g4_feedback_storage_same( $approval_storage_source['feedback_hash'], $loaded_approval['latest_feedback_profile_hash'] ?? null, 'C37 source query must expose the canonical teacher draft profile hash.' );

$transactions_before = count( $approval_storage_database->transactions );
$approved_storage    = $approval_storage_repository->append_approved_revision( $approval_storage_record );
g4_feedback_storage_same( 'approved', $approved_storage->state(), 'C37 storage must append an approved revision.' );
g4_feedback_storage_same( $transactions_before + 2, count( $approval_storage_database->transactions ), 'C37 approval must add exactly one transaction start and commit.' );
g4_feedback_storage_same( array( 'START TRANSACTION', 'COMMIT' ), array_slice( $approval_storage_database->transactions, -2 ), 'Successful C37 approval must commit one transaction.' );
g4_feedback_storage_same( 2, count( $approval_storage_database->tables['oj_ai_submission_feedback_revisions'] ), 'C37 must preserve the teacher draft and append one approved row.' );
g4_feedback_storage_same( 2, count( $approval_storage_database->tables['oj_ai_audit_events'] ), 'C37 must append one approval audit after the source review audit.' );
g4_feedback_storage_same( 2, count( $approval_storage_database->tables['oj_ai_outbox_events'] ), 'C37 must append one approval receipt after the source review receipt.' );
$approved_row = $approval_storage_database->tables['oj_ai_submission_feedback_revisions'][1];
g4_feedback_storage_same( 701, $approved_row['previous_revision_id'], 'C37 approved row must link to the exact teacher draft database row.' );
g4_feedback_storage_same( $approval_storage_source['feedback_json'], $approved_row['feedback_json'], 'C37 approved row must copy the exact teacher draft JSON.' );
g4_feedback_storage_same( $approval_storage_source['feedback_hash'], $approved_row['feedback_hash'], 'C37 approved row must copy the exact teacher draft profile hash.' );
$approval_audit  = $approval_storage_database->tables['oj_ai_audit_events'][1];
$approval_outbox = $approval_storage_database->tables['oj_ai_outbox_events'][1];
g4_feedback_storage_same( 'submission_feedback.approved', $approval_audit['action_key'], 'C37 audit action must be distinct from C36 review.' );
g4_feedback_storage_same( 'oj_ai.submission_feedback_approval_audit', $approval_audit['metadata_schema'], 'C37 audit schema drifted.' );
g4_feedback_storage_same( 'submission_feedback.approved', $approval_outbox['event_type'], 'C37 outbox event must be distinct from C36 review.' );
g4_feedback_storage_same( 'oj_ai.submission_feedback_approval_event', $approval_outbox['payload_schema'], 'C37 outbox schema drifted.' );
g4_feedback_storage_assert( ! str_contains( (string) $approval_outbox['payload_json'], 'The response is clear.' ) && ! str_contains( (string) $approval_outbox['payload_json'], 'criterion_feedback' ), 'C37 outbox receipt must not contain feedback body text.' );

$approval_storage_replay = $approval_storage_repository->append_approved_revision( $approval_storage_record );
g4_feedback_storage_same( true, $approval_storage_replay->replayed(), 'Exact C37 storage retry must use the approval receipt.' );
g4_feedback_storage_same( 2, count( $approval_storage_database->tables['oj_ai_submission_feedback_revisions'] ), 'Exact C37 retry must append zero additional feedback rows.' );
$approval_storage_conflict                   = $approval_storage_record;
$approval_storage_conflict['request_digest'] = str_repeat( 'f', 64 );
g4_feedback_approval_storage_failure(
	static fn () => $approval_storage_repository->append_approved_revision( $approval_storage_conflict ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Changed C37 request under one command key must conflict.'
);

$profile_stale_fixture               = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'profile-stale' );
$changed_profile                     = json_decode( $profile_stale_fixture['database']->snapshot['latest_feedback_json'], true, 64, JSON_THROW_ON_ERROR );
$changed_profile['overall_feedback'] = 'The teacher draft changed before approval.';
$changed_json                        = $approval_storage_canonical->encode( $changed_profile );
$changed_hash                        = hash( 'sha256', $changed_json );
$profile_stale_fixture['database']->snapshot['latest_feedback_json'] = $changed_json;
$profile_stale_fixture['database']->snapshot['latest_profile_hash']  = $changed_hash;
$profile_stale_fixture['database']->snapshot['latest_feedback_hash'] = hash(
	'sha256',
	$approval_storage_canonical->encode(
		array(
			'audience'             => 'student',
			'evaluation_id'        => $profile_stale_fixture['snapshot']['evaluation_id'],
			'feedback_hash'        => $changed_hash,
			'previous_revision_id' => null,
			'review_id'            => $profile_stale_fixture['snapshot']['official_review_id'],
			'revision_no'          => 1,
			'source_run_id'        => null,
			'state'                => 'teacher_draft',
		)
	)
);
g4_feedback_approval_storage_failure(
	static fn () => $profile_stale_fixture['repository']->append_approved_revision( $profile_stale_fixture['approval'] ),
	Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
	'C37 locked transaction must reject a changed teacher draft profile.'
);
g4_feedback_storage_same( 1, count( $profile_stale_fixture['database']->tables['oj_ai_submission_feedback_revisions'] ), 'Changed C37 source must append zero approved rows.' );
g4_feedback_storage_same( 'ROLLBACK', $profile_stale_fixture['database']->transactions[ array_key_last( $profile_stale_fixture['database']->transactions ) ] ?? null, 'Changed C37 source must roll back.' );

$cas_stale_fixture = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'cas-stale' );
$cas_stale_fixture['database']->snapshot['evaluation_lock_version'] = '8';
g4_feedback_approval_storage_failure(
	static fn () => $cas_stale_fixture['repository']->append_approved_revision( $cas_stale_fixture['approval'] ),
	Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
	'C37 locked transaction must reject a changed evaluation CAS.'
);
g4_feedback_storage_same( 1, count( $cas_stale_fixture['database']->tables['oj_ai_submission_feedback_revisions'] ), 'Stale C37 CAS must append zero approved rows.' );

$failure_fixture                                = g4_feedback_approval_storage_fixture( $approval_storage_canonical, 'outbox-failure' );
$failure_fixture['database']->fail_insert_table = 'oj_ai_outbox_events';
g4_feedback_approval_storage_failure(
	static fn () => $failure_fixture['repository']->append_approved_revision( $failure_fixture['approval'] ),
	Submission_Evaluation_Failure::STORAGE_UNAVAILABLE,
	'C37 outbox failure must fail the whole approval transaction.'
);
g4_feedback_storage_same( 1, count( $failure_fixture['database']->tables['oj_ai_submission_feedback_revisions'] ), 'Failed C37 transaction must preserve only the source teacher draft.' );
g4_feedback_storage_same( 1, count( $failure_fixture['database']->tables['oj_ai_audit_events'] ), 'Failed C37 transaction must roll back the approval audit.' );
g4_feedback_storage_same( 1, count( $failure_fixture['database']->tables['oj_ai_outbox_events'] ), 'Failed C37 transaction must roll back the approval receipt.' );
g4_feedback_storage_same( 'ROLLBACK', $failure_fixture['database']->transactions[ array_key_last( $failure_fixture['database']->transactions ) ] ?? null, 'Failed C37 transaction must roll back.' );

fwrite( STDOUT, "G4-IMP-04-S03 C37 transaction, exact-copy, CAS, durable replay, privacy, and rollback contracts passed.\n" );
