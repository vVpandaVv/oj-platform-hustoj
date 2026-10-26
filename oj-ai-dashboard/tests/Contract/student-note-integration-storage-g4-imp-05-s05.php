<?php
/**
 * G4-IMP-05-S05 C39-C42 literal-table single-winner integration freeze.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-report-inclusion-storage-g4-imp-05-s04.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Student_Note_Review_Repository;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

/**
 * G4 s05 storage stale.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return Submission_Evaluation_Failure
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s05_storage_stale( callable $callback, string $message ): Submission_Evaluation_Failure {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_storage_same( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $failure->reason(), $message );
		return $failure;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s05 storage counts.
 *
 * @param G4_Student_Note_Review_Database $database Database.
 * @return array{notes:int,audit:int,outbox:int}
 */
function g4_s05_storage_counts( G4_Student_Note_Review_Database $database ): array {
	return array(
		'notes'  => count( $database->tables['oj_ai_student_note_revisions'] ),
		'audit'  => count( $database->tables['oj_ai_audit_events'] ),
		'outbox' => count( $database->tables['oj_ai_outbox_events'] ),
	);
}

/**
 * G4 s05 storage unchanged.
 *
 * @param array{notes:int,audit:int,outbox:int} $expected Expected.
 * @param G4_Student_Note_Review_Database       $database Database.
 * @param string                                $message Message.
 * @return void
 */
function g4_s05_storage_unchanged( array $expected, G4_Student_Note_Review_Database $database, string $message ): void {
	g4_note_storage_same( $expected, g4_s05_storage_counts( $database ), $message );
	g4_note_storage_same( 'ROLLBACK', $database->transactions[ array_key_last( $database->transactions ) ] ?? null, $message . ' Transaction must roll back.' );
}

$s05_storage_canonical  = new Canonical_Json();
$s05_storage_snapshot   = g4_note_storage_snapshot();
$s05_storage_profile    = g4_note_storage_profile( $s05_storage_canonical, $s05_storage_snapshot['official_review_hash'] );
$s05_storage_database   = new G4_Student_Note_Review_Database( $s05_storage_snapshot );
$s05_storage_repository = new WordPress_Student_Note_Review_Repository(
	$s05_storage_canonical,
	new Student_Note_Output_Normalizer( $s05_storage_canonical ),
	$s05_storage_database
);

$s05_review_winner = g4_note_storage_review_record(
	$s05_storage_snapshot,
	$s05_storage_profile,
	$s05_storage_canonical,
	'command:' . str_repeat( '1', 64 ),
	str_repeat( 'a', 64 )
);
$s05_review_loser  = g4_note_storage_review_record(
	$s05_storage_snapshot,
	$s05_storage_profile,
	$s05_storage_canonical,
	'command:' . str_repeat( '2', 64 ),
	str_repeat( 'b', 64 )
);
$s05_review_result = $s05_storage_repository->append_review_revision( $s05_review_winner );
g4_note_storage_same( false, $s05_review_result->include_in_reports(), 'C39 winner must remain excluded from reports.' );
g4_note_storage_publish_review( $s05_storage_database, $s05_storage_snapshot, $s05_review_winner );
$s05_counts       = g4_s05_storage_counts( $s05_storage_database );
$s05_review_stale = g4_s05_storage_stale(
	static fn () => $s05_storage_repository->append_review_revision( $s05_review_loser ),
	'C39 concurrent loser must fail with student_note_source_stale.'
);
g4_s05_storage_unchanged( $s05_counts, $s05_storage_database, 'C39 stale loser must append no history.' );
g4_note_storage_same( $s05_review_winner['revision_public_id'], $s05_review_stale->safe_context()['current_revision_id'] ?? null, 'C39 stale recovery must expose the safe current revision ID.' );

$s05_approval_winner                  = g4_note_storage_approval_record(
	$s05_storage_snapshot,
	$s05_review_winner,
	$s05_storage_canonical,
	'command:' . str_repeat( '3', 64 ),
	str_repeat( 'c', 64 )
);
$s05_approval_winner['actor_user_id'] = 17;
$s05_approval_loser                   = g4_note_storage_approval_record(
	$s05_storage_snapshot,
	$s05_review_winner,
	$s05_storage_canonical,
	'command:' . str_repeat( '4', 64 ),
	str_repeat( 'd', 64 )
);
$s05_approval_loser['actor_user_id']  = 18;
$s05_approval_result                  = $s05_storage_repository->append_approved_revision( $s05_approval_winner );
g4_note_storage_same( false, $s05_approval_result->include_in_reports(), 'C40 winner must preserve report default exclusion.' );
g4_note_storage_publish_latest(
	$s05_storage_database,
	$s05_storage_snapshot,
	$s05_approval_winner,
	$s05_review_winner,
	17,
	$s05_approval_winner['occurred_at_utc']
);
$s05_counts         = g4_s05_storage_counts( $s05_storage_database );
$s05_approval_stale = g4_s05_storage_stale(
	static fn () => $s05_storage_repository->append_approved_revision( $s05_approval_loser ),
	'C40 concurrent loser must fail with student_note_source_stale.'
);
g4_s05_storage_unchanged( $s05_counts, $s05_storage_database, 'C40 stale loser must append no history.' );
g4_note_storage_same( 'approved', $s05_approval_stale->safe_context()['current_state'] ?? null, 'C40 stale recovery must expose the approved winner.' );

$s05_include_winner                  = g4_note_storage_report_record(
	$s05_storage_snapshot,
	$s05_approval_winner,
	$s05_storage_canonical,
	true,
	'command:' . str_repeat( '5', 64 ),
	str_repeat( 'e', 64 ),
	'2026-08-24 23:02:00'
);
$s05_include_winner['actor_user_id'] = 42;
$s05_include_loser                   = g4_note_storage_report_record(
	$s05_storage_snapshot,
	$s05_approval_winner,
	$s05_storage_canonical,
	true,
	'command:' . str_repeat( '6', 64 ),
	str_repeat( 'f', 64 ),
	'2026-08-24 23:02:01'
);
$s05_include_loser['actor_user_id']  = 43;
$s05_include_result                  = $s05_storage_repository->append_report_inclusion_revision( $s05_include_winner );
g4_note_storage_same( true, $s05_include_result->include_in_reports(), 'C41 winner must append the only included revision.' );
g4_note_storage_publish_latest(
	$s05_storage_database,
	$s05_storage_snapshot,
	$s05_include_winner,
	$s05_approval_winner,
	17,
	$s05_approval_winner['occurred_at_utc']
);
$s05_counts        = g4_s05_storage_counts( $s05_storage_database );
$s05_include_stale = g4_s05_storage_stale(
	static fn () => $s05_storage_repository->append_report_inclusion_revision( $s05_include_loser ),
	'C41 concurrent loser must fail with student_note_source_stale.'
);
g4_s05_storage_unchanged( $s05_counts, $s05_storage_database, 'C41 stale loser must append no history.' );
g4_note_storage_same( 1, $s05_include_stale->safe_context()['current_include_in_reports'] ?? null, 'C41 stale recovery must expose the included winner.' );

$s05_history_before_exclude          = array(
	'notes'  => array_slice( $s05_storage_database->tables['oj_ai_student_note_revisions'], 0, 3 ),
	'audit'  => array_slice( $s05_storage_database->tables['oj_ai_audit_events'], 0, 3 ),
	'outbox' => array_slice( $s05_storage_database->tables['oj_ai_outbox_events'], 0, 3 ),
);
$s05_exclude_winner                  = g4_note_storage_report_record(
	$s05_storage_snapshot,
	$s05_include_winner,
	$s05_storage_canonical,
	false,
	'command:' . str_repeat( '7', 64 ),
	str_repeat( '1', 64 ),
	'2026-08-24 23:03:00'
);
$s05_exclude_winner['actor_user_id'] = 43;
$s05_exclude_loser                   = g4_note_storage_report_record(
	$s05_storage_snapshot,
	$s05_include_winner,
	$s05_storage_canonical,
	false,
	'command:' . str_repeat( '8', 64 ),
	str_repeat( '2', 64 ),
	'2026-08-24 23:03:01'
);
$s05_exclude_loser['actor_user_id']  = 44;
$s05_exclude_result                  = $s05_storage_repository->append_report_inclusion_revision( $s05_exclude_winner );
g4_note_storage_same( false, $s05_exclude_result->include_in_reports(), 'C42 winner must append a new excluded revision.' );
g4_note_storage_same( $s05_history_before_exclude['notes'], array_slice( $s05_storage_database->tables['oj_ai_student_note_revisions'], 0, 3 ), 'C42 must not rewrite historical note revisions.' );
g4_note_storage_same( $s05_history_before_exclude['audit'], array_slice( $s05_storage_database->tables['oj_ai_audit_events'], 0, 3 ), 'C42 must not rewrite historical audit events.' );
g4_note_storage_same( $s05_history_before_exclude['outbox'], array_slice( $s05_storage_database->tables['oj_ai_outbox_events'], 0, 3 ), 'C42 must not rewrite historical receipts.' );
g4_note_storage_publish_latest(
	$s05_storage_database,
	$s05_storage_snapshot,
	$s05_exclude_winner,
	$s05_include_winner,
	17,
	$s05_approval_winner['occurred_at_utc']
);
$s05_counts        = g4_s05_storage_counts( $s05_storage_database );
$s05_exclude_stale = g4_s05_storage_stale(
	static fn () => $s05_storage_repository->append_report_inclusion_revision( $s05_exclude_loser ),
	'C42 concurrent loser must fail with student_note_source_stale.'
);
g4_s05_storage_unchanged( $s05_counts, $s05_storage_database, 'C42 stale loser must append no history.' );
g4_note_storage_same( 0, $s05_exclude_stale->safe_context()['current_include_in_reports'] ?? null, 'C42 stale recovery must expose the excluded winner.' );

g4_note_storage_same(
	array(
		'notes'  => 4,
		'audit'  => 4,
		'outbox' => 4,
	),
	g4_s05_storage_counts( $s05_storage_database ),
	'C39-C42 must produce exactly one immutable row per winner.'
);
g4_note_storage_same(
	array( 'student_note.reviewed', 'student_note.approved', 'student_note.included_in_reports', 'student_note.excluded_from_reports' ),
	array_column( $s05_storage_database->tables['oj_ai_audit_events'], 'action_key' ),
	'C39-C42 audit event order drifted.'
);
g4_note_storage_same( 17, $s05_storage_database->tables['oj_ai_student_note_revisions'][1]['approved_by_user_id'], 'C40 must store the original approving educator.' );
g4_note_storage_same( 17, $s05_storage_database->tables['oj_ai_student_note_revisions'][2]['approved_by_user_id'], 'C41 must preserve the original approving educator.' );
g4_note_storage_same( 17, $s05_storage_database->tables['oj_ai_student_note_revisions'][3]['approved_by_user_id'], 'C42 must preserve the original approving educator.' );
g4_note_storage_same( 42, $s05_storage_database->tables['oj_ai_audit_events'][2]['actor_user_id'], 'C41 audit must record the inclusion actor.' );
g4_note_storage_same( 43, $s05_storage_database->tables['oj_ai_audit_events'][3]['actor_user_id'], 'C42 audit must record the exclusion actor.' );

$s05_before_replays = g4_s05_storage_counts( $s05_storage_database );
foreach (
	array(
		$s05_storage_repository->append_review_revision( $s05_review_winner ),
		$s05_storage_repository->append_approved_revision( $s05_approval_winner ),
		$s05_storage_repository->append_report_inclusion_revision( $s05_include_winner ),
		$s05_storage_repository->append_report_inclusion_revision( $s05_exclude_winner ),
	) as $replay
) {
	g4_note_storage_assert( $replay->replayed(), 'Exact C39-C42 storage retries must use durable receipts.' );
}
g4_note_storage_same( $s05_before_replays, g4_s05_storage_counts( $s05_storage_database ), 'Durable replays must append zero rows after later revisions exist.' );

fwrite( STDOUT, "G4-IMP-05-S05 storage integration passed: C39-C42 single winners, stale losers, rollback, durable replay, original approval metadata, and immutable history are frozen.\n" );
