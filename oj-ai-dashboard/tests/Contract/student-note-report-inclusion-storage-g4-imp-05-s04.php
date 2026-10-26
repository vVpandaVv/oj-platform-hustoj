<?php
/**
 * G4-IMP-05-S04 C41/C42 literal-table transaction fixture.
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
 * G4 note storage publish latest.
 *
 * @param G4_Student_Note_Review_Database $database Database.
 * @param array<string,mixed>             $snapshot Snapshot.
 * @param array<string,mixed>             $record Record.
 * @param array<string,mixed>             $previous Previous.
 * @param int                             $approved_by Approved by.
 * @param string                          $approved_at Approved at.
 * @return void
 */
function g4_note_storage_publish_latest( G4_Student_Note_Review_Database $database, array $snapshot, array $record, array $previous, int $approved_by, string $approved_at ): void {
	$row                                        = $database->tables['oj_ai_student_note_revisions'][ array_key_last( $database->tables['oj_ai_student_note_revisions'] ) ];
	$database->snapshot['note_count']           = (string) count( $database->tables['oj_ai_student_note_revisions'] );
	$database->snapshot['latest_revision_pk']   = (string) $row['id'];
	$database->snapshot['latest_revision_id']   = $record['revision_public_id'];
	$database->snapshot['latest_revision_hash'] = $record['content_hash'];
	$database->snapshot['latest_note_root_id']  = $record['note_root_id'];
	$database->snapshot['latest_revision_no']   = (string) $record['revision_no'];
	$database->snapshot['latest_state']         = 'approved';
	$database->snapshot['latest_include_in_reports'] = $record['include_in_reports'] ? '1' : '0';
	$database->snapshot['latest_note_schema']        = $record['note_schema'];
	$database->snapshot['latest_note_version']       = '1';
	$database->snapshot['latest_note_json']          = $record['note_json'];
	$database->snapshot['latest_note_hash']          = $record['note_hash'];
	$database->snapshot['latest_source_run_id']      = $record['source_evaluation_run_public_id'];
	$database->snapshot['latest_approved_by']        = (string) $approved_by;
	$database->snapshot['latest_approved_at']        = $approved_at;
	$database->snapshot['latest_review_id']          = $snapshot['official_review_id'];
	$database->snapshot['latest_review_hash']        = $snapshot['official_review_hash'];
	$database->snapshot['previous_revision_id']      = $previous['revision_public_id'];
	$database->snapshot['previous_revision_hash']    = $previous['content_hash'];
	$database->snapshot['previous_revision_no']      = (string) $previous['revision_no'];
	$database->snapshot['previous_note_root_id']     = $previous['note_root_id'];
}

/**
 * G4 note storage report record.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $current Current.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param bool                $target Target.
 * @param string              $key Key.
 * @param string              $digest Digest.
 * @param string              $time Time.
 * @return array<string,mixed>
 */
function g4_note_storage_report_record( array $snapshot, array $current, Canonical_Json $canonical, bool $target, string $key, string $digest, string $time ): array {
	$record                                = $current;
	$record['actor_user_id']               = 42;
	$record['audit_public_id']             = g4_note_storage_id();
	$record['command_id']                  = $target ? 'command-g4-note-storage-include' : 'command-g4-note-storage-exclude';
	$record['command_storage_key']         = $key;
	$record['expected_include_in_reports'] = ! $target;
	$record['expected_note_state']         = 'approved';
	$record['expected_revision_hash']      = $current['content_hash'];
	$record['expected_revision_id']        = $current['revision_public_id'];
	$record['expected_revision_no']        = $current['revision_no'];
	$record['expected_source_run_id']      = $current['source_evaluation_run_public_id'];
	$record['include_in_reports']          = $target;
	$record['occurred_at_utc']             = $time;
	$record['outbox_public_id']            = g4_note_storage_id();
	$record['previous_revision_id']        = $current['revision_public_id'];
	$record['reason_digest']               = null;
	$record['request_digest']              = $digest;
	$record['revision_no']                 = $current['revision_no'] + 1;
	$record['revision_public_id']          = g4_note_storage_id();
	$record['source_mode']                 = $target ? 'include_in_reports' : 'exclude_from_reports';
	$record['state']                       = 'approved';
	$record['content_hash']                = hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'        => $snapshot['evaluation_id'],
				'include_in_reports'   => $target,
				'note_hash'            => $record['note_hash'],
				'note_root_id'         => $record['note_root_id'],
				'previous_revision_id' => $current['revision_public_id'],
				'review_id'            => $snapshot['official_review_id'],
				'revision_no'          => $record['revision_no'],
				'source_run_id'        => $record['source_evaluation_run_public_id'],
				'state'                => 'approved',
			)
		)
	);
	return $record;
}

/**
 * G4 note storage approved fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array{database:G4_Student_Note_Review_Database,repository:WordPress_Student_Note_Review_Repository,snapshot:array<string,mixed>,approval:array<string,mixed>,review:array<string,mixed>}
 */
function g4_note_storage_approved_fixture( Canonical_Json $canonical ): array {
	$snapshot   = g4_note_storage_snapshot();
	$profile    = g4_note_storage_profile( $canonical, $snapshot['official_review_hash'] );
	$database   = new G4_Student_Note_Review_Database( $snapshot );
	$repository = new WordPress_Student_Note_Review_Repository( $canonical, new Student_Note_Output_Normalizer( $canonical ), $database );
	$review     = g4_note_storage_review_record( $snapshot, $profile, $canonical, 'command:' . str_repeat( '4', 64 ), str_repeat( '5', 64 ) );
	$repository->append_review_revision( $review );
	g4_note_storage_publish_review( $database, $snapshot, $review );
	$approval                  = g4_note_storage_approval_record( $snapshot, $review, $canonical, 'command:' . str_repeat( '6', 64 ), str_repeat( '7', 64 ) );
	$approval['actor_user_id'] = 17;
	$repository->append_approved_revision( $approval );
	g4_note_storage_publish_latest( $database, $snapshot, $approval, $review, 17, $approval['occurred_at_utc'] );
	return array(
		'database'   => $database,
		'repository' => $repository,
		'snapshot'   => $snapshot,
		'approval'   => $approval,
		'review'     => $review,
	);
}

$report_storage_canonical  = new Canonical_Json();
$fixture                   = g4_note_storage_approved_fixture( $report_storage_canonical );
$report_storage_database   = $fixture['database'];
$report_storage_repository = $fixture['repository'];
$report_storage_snapshot   = $fixture['snapshot'];
$approval_record           = $fixture['approval'];

$loaded_approval = $report_storage_repository->find_owned_report_inclusion_snapshot( 42, $report_storage_snapshot['evaluation_id'] );
g4_note_storage_same( 'approved', $loaded_approval['latest_state'] ?? null, 'C41 source must be an approved revision.' );
g4_note_storage_same( false, $loaded_approval['latest_include_in_reports'] ?? null, 'C41 source must be currently excluded.' );
g4_note_storage_same( 17, $loaded_approval['latest_approved_by'] ?? null, 'C41 snapshot must expose the original approving educator.' );
g4_note_storage_same( $approval_record['occurred_at_utc'], $loaded_approval['latest_approved_at'] ?? null, 'C41 snapshot must expose the original approval time.' );

$include_record = g4_note_storage_report_record( $report_storage_snapshot, $approval_record, $report_storage_canonical, true, 'command:' . str_repeat( '8', 64 ), str_repeat( '9', 64 ), '2026-08-24 19:02:00' );
$included       = $report_storage_repository->append_report_inclusion_revision( $include_record );
g4_note_storage_same( true, $included->include_in_reports(), 'C41 storage must append an included revision.' );
g4_note_storage_same( 3, count( $report_storage_database->tables['oj_ai_student_note_revisions'] ), 'C41 must append exactly one immutable row.' );
$included_row = $report_storage_database->tables['oj_ai_student_note_revisions'][2];
g4_note_storage_same( 1, $included_row['include_in_reports'], 'C41 stored include flag drifted.' );
g4_note_storage_same( 17, $included_row['approved_by_user_id'], 'C41 must preserve the original approving educator, not the inclusion actor.' );
g4_note_storage_same( $approval_record['occurred_at_utc'], $included_row['approved_at_utc'], 'C41 must preserve the original approval time.' );
g4_note_storage_same( 'student_note.included_in_reports', $report_storage_database->tables['oj_ai_audit_events'][2]['action_key'], 'C41 audit action drifted.' );
g4_note_storage_same( 'student_note.included_in_reports', $report_storage_database->tables['oj_ai_outbox_events'][2]['event_type'], 'C41 outbox event drifted.' );
$include_payload = (string) $report_storage_database->tables['oj_ai_outbox_events'][2]['payload_json'];
g4_note_storage_assert( ! str_contains( $include_payload, 'support_suggestions' ) && ! str_contains( $include_payload, 'summary' ), 'C41 receipt must not contain note body text.' );

$include_replay = $report_storage_repository->append_report_inclusion_revision( $include_record );
g4_note_storage_same( true, $include_replay->replayed(), 'Exact C41 storage retry must replay its receipt.' );
g4_note_storage_same( 3, count( $report_storage_database->tables['oj_ai_student_note_revisions'] ), 'C41 replay must append no row.' );

g4_note_storage_publish_latest( $report_storage_database, $report_storage_snapshot, $include_record, $approval_record, 17, $approval_record['occurred_at_utc'] );
$exclude_record = g4_note_storage_report_record( $report_storage_snapshot, $include_record, $report_storage_canonical, false, 'command:' . str_repeat( 'a', 64 ), str_repeat( 'b', 64 ), '2026-08-24 19:03:00' );
$excluded       = $report_storage_repository->append_report_inclusion_revision( $exclude_record );
g4_note_storage_same( false, $excluded->include_in_reports(), 'C42 storage must append an excluded revision.' );
g4_note_storage_same( 4, count( $report_storage_database->tables['oj_ai_student_note_revisions'] ), 'C42 must append exactly one immutable row.' );
$excluded_row = $report_storage_database->tables['oj_ai_student_note_revisions'][3];
g4_note_storage_same( 0, $excluded_row['include_in_reports'], 'C42 stored include flag drifted.' );
g4_note_storage_same( 17, $excluded_row['approved_by_user_id'], 'C42 must preserve the original approving educator.' );
g4_note_storage_same( 'student_note.excluded_from_reports', $report_storage_database->tables['oj_ai_audit_events'][3]['action_key'], 'C42 audit action drifted.' );

$failure_fixture                     = g4_note_storage_approved_fixture( $report_storage_canonical );
$failure_database                    = $failure_fixture['database'];
$failure_repository                  = $failure_fixture['repository'];
$failure_record                      = g4_note_storage_report_record( $failure_fixture['snapshot'], $failure_fixture['approval'], $report_storage_canonical, true, 'command:' . str_repeat( 'c', 64 ), str_repeat( 'd', 64 ), '2026-08-24 19:04:00' );
$failure_database->fail_insert_table = 'oj_ai_outbox_events';
g4_note_storage_failure( static fn () => $failure_repository->append_report_inclusion_revision( $failure_record ), Submission_Evaluation_Failure::STORAGE_UNAVAILABLE, 'C41 outbox failure must fail the whole transaction.' );
g4_note_storage_same( 2, count( $failure_database->tables['oj_ai_student_note_revisions'] ), 'Failed C41 transaction must roll back its appended note row.' );
g4_note_storage_same( 2, count( $failure_database->tables['oj_ai_audit_events'] ), 'Failed C41 transaction must roll back its audit row.' );
g4_note_storage_same( 2, count( $failure_database->tables['oj_ai_outbox_events'] ), 'Failed C41 transaction must leave prior receipts only.' );
g4_note_storage_same( 'ROLLBACK', $failure_database->transactions[ array_key_last( $failure_database->transactions ) ] ?? null, 'Failed C41 transaction must roll back.' );

fwrite( STDOUT, "G4-IMP-05-S04 C41/C42 transaction, immutable toggle, approval metadata preservation, replay, privacy, and rollback contracts passed.\n" );
