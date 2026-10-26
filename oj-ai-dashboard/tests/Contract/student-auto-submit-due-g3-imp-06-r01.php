<?php
/**
 * G3-IMP-06-R01 deadline auto-submit recovery contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require __DIR__ . '/student-submission-submit-g3-imp-06-s01.php';

use OJAI\Dashboard\Submission\Student\Application\Run_Auto_Submit_Due;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Application\Run_Auto_Submit_Due_Result;
use OJAI\Dashboard\Submission\Student\Domain\Auto_Submit_Assignment_Work_Item;
use OJAI\Dashboard\Submission\Student\Infrastructure\WordPress_Student_Submission_Commit_Repository;
use OJAI\Dashboard\Submission\Student\Port\Auto_Submit_Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G3-IMP-06-R01 deadline auto-submit recovery contract.
 */
final class Auto_Submit_Test_Assignments implements Auto_Submit_Assignment_Repository_Port_Interface {
	/**
	 * Ready.
	 *
	 * @var bool
	 */
	private bool $ready = false;
	/**
	 * Claimed.
	 *
	 * @var bool
	 */
	private bool $claimed = false;
	/**
	 * Last error.
	 *
	 * @var string|null
	 */
	public ?string $last_error = null;

	/**
	 * Initialize auto submit test assignments dependencies and state.
	 *
	 * @param C26_Database $database Database.
	 * @param array        $fixture Fixture.
	 */
	public function __construct( private C26_Database $database, private array $fixture ) {
	}

	/**
	 * Compensate due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $outbox_public_id Outbox public id.
	 * @return bool
	 */
	public function compensate_due( string $now_utc, string $outbox_public_id ): bool {
		unset( $now_utc, $outbox_public_id );
		$this->ready = true;
		return true;
	}

	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Auto_Submit_Assignment_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Auto_Submit_Assignment_Work_Item {
		unset( $now_utc, $lease_expires_at_utc );
		if ( ! $this->ready || $this->claimed ) {
			return null;
		}
		$this->claimed = true;
		return new Auto_Submit_Assignment_Work_Item(
			900,
			1,
			10,
			$this->fixture['assignment_id'],
			99,
			'class',
			'class-21',
			'target-r1',
			1,
			$this->fixture['schedule_hash'],
			'2026-08-22 11:00:00',
			'2026-08-22 12:00:00',
			$lease_token
		);
	}

	/**
	 * Candidates.
	 *
	 * @param Auto_Submit_Assignment_Work_Item $item Item.
	 * @param int                              $limit Maximum number of entries requested.
	 * @return array
	 */
	public function candidates( Auto_Submit_Assignment_Work_Item $item, int $limit ): array {
		unset( $item, $limit );
		$row = $this->database->tables['oj_ai_submissions'][0] ?? null;
		if ( ! is_array( $row ) || ! in_array( $row['state'], array( 'draft', 'reopened', 'conflict' ), true ) ) {
			return array();
		}
		return array(
			array(
				'submission_id'       => $row['public_id'],
				'learner_user_id'     => $row['learner_user_id'],
				'state'               => $row['state'],
				'lock_version'        => $row['lock_version'],
				'current_revision_id' => $row['current_revision_public_id'],
			),
		);
	}

	/**
	 * Finish.
	 *
	 * @param Auto_Submit_Assignment_Work_Item $item Item.
	 * @param string                           $now_utc Now utc.
	 * @param string                           $retry_at_utc Retry at utc.
	 * @param string|null                      $error_code Error code.
	 * @return string
	 */
	public function finish( Auto_Submit_Assignment_Work_Item $item, string $now_utc, string $retry_at_utc, ?string $error_code ): string {
		unset( $item, $now_utc, $retry_at_utc );
		$this->last_error = $error_code;
		$state            = $this->database->tables['oj_ai_submissions'][0]['state'] ?? '';
		return in_array( $state, array( 'draft', 'reopened', 'conflict' ), true ) ? 'retry_wait' : 'completed';
	}
}

/**
 * Auto submit ids.
 *
 * @return Public_Id_Generator
 */
function auto_submit_ids(): Public_Id_Generator {
	$seed = 110;
	return new Public_Id_Generator(
		static function ( int $length ) use ( &$seed ): string {
			return str_repeat( chr( $seed++ ), $length );
		}
	);
}

$fixture     = c26_fixture();
$assignments = new Auto_Submit_Test_Assignments( $fixture['database'], $fixture );
$runner      = new Run_Auto_Submit_Due(
	$assignments,
	new WordPress_Student_Submission_Commit_Repository( $fixture['database'], $fixture['canonical'] ),
	auto_submit_ids(),
	static fn (): string => '2026-08-22 12:00:30',
	static fn (): string => 'aaaaaaaa-aaaa-4aaa-aaaa-aaaaaaaaaaaa'
);
$result      = $runner->run_once();
g3_same( Run_Auto_Submit_Due_Result::COMPLETED, $result->state(), 'A18 due assignment must complete after its valid draft is finalized.' );
g3_same( 1, $result->submitted_count(), 'A18 must auto-submit exactly one started valid draft.' );
g3_same( 'auto_submitted', $fixture['database']->tables['oj_ai_submissions'][0]['state'], 'A18 root state must become auto_submitted.' );
g3_same( $fixture['revision_id'], $fixture['database']->tables['oj_ai_submissions'][0]['official_revision_public_id'], 'A18 must promote the last saved revision exactly.' );
g3_same( '2026-08-22 12:00:00', $fixture['database']->tables['oj_ai_submissions'][0]['submitted_at_utc'], 'Delayed cron must preserve the effective close time.' );
g3_same( 1, count( $fixture['database']->tables['oj_ai_submission_receipts'] ), 'A18 must append one immutable receipt.' );
$receipt = $fixture['database']->tables['oj_ai_submission_receipts'][0];
g3_same( 'auto_submit', $receipt['action'], 'A18 receipt action drifted.' );
g3_same( 'system', $receipt['actor_type'], 'A18 receipt actor must be system.' );
g3_same( null, $receipt['actor_user_id'], 'A18 system receipt must not impersonate the learner.' );
g3_same( 'late', $receipt['timeliness'], 'Effective close after due must be recorded as late.' );
g3_same( 1, count( $fixture['database']->tables['oj_ai_audit_events'] ), 'A18 must append a system audit event.' );
g3_same( 'submission.auto_submitted', $fixture['database']->tables['oj_ai_audit_events'][0]['action_key'], 'A18 audit action drifted.' );

$record = array(
	'assignment_public_id'         => $fixture['assignment_id'],
	'learner_user_id'              => 7,
	'submission_public_id'         => $fixture['submission_id'],
	'receipt_public_id'            => c26_id( 80 ),
	'audit_public_id'              => c26_id( 81 ),
	'outbox_public_id'             => c26_id( 82 ),
	'command_id'                   => 'auto-submit-replay-command',
	'correlation_id'               => 'auto-submit-replay-correlation',
	'idempotency_storage_key'      => 'submission:' . hash( 'sha256', $fixture['submission_id'] . '|' . $fixture['schedule_hash'] ),
	'occurred_at_utc'              => '2026-08-22 12:01:00',
	'effective_received_at_utc'    => '2026-08-22 12:00:00',
	'expected_state'               => 'draft',
	'expected_lock_version'        => 1,
	'expected_current_revision_id' => $fixture['revision_id'],
	'expected_schedule_version'    => 1,
	'expected_schedule_hash'       => $fixture['schedule_hash'],
	'authorized_context_revision'  => 'target-r1',
);
$commit = new WordPress_Student_Submission_Commit_Repository( $fixture['database'], $fixture['canonical'] );
g3_same( 'stale', $commit->auto_submit( $record ), 'A19 replay must observe the existing official root as stale.' );
g3_same( 1, count( $fixture['database']->tables['oj_ai_submission_receipts'] ), 'A19 replay must not duplicate a receipt.' );

$extra        = c26_fixture( 'reopened', 2 );
$extra_before = $extra['database']->tables;
$extra_record = array_replace( $record, array( 'expected_state' => 'reopened' ) );
$extra_commit = new WordPress_Student_Submission_Commit_Repository( $extra['database'], $extra['canonical'] );
g3_same( 'auto_submitted', $extra_commit->auto_submit( $extra_record ), 'Automatic submission must honor a teacher allowance beyond the default limit.' );
g3_same( 3, $extra['database']->tables['oj_ai_submissions'][0]['official_attempt_no'], 'Automatic submission must consume exactly the third attempt.' );
g3_same( $extra_before['oj_ai_assignments'], $extra['database']->tables['oj_ai_assignments'], 'Auto-submit must not change the assignment policy.' );
$extra_after = $extra['database']->tables;
g3_same( 'stale', $extra_commit->auto_submit( $extra_record ), 'Repeated automatic submission must not consume another allowance.' );
g3_same( $extra_after, $extra['database']->tables, 'Repeated automatic submission must preserve history.' );

foreach ( array( false, true ) as $same_revision ) {
	$resubmission    = c26_reviewed_fixture( $same_revision );
	$before          = $resubmission['database']->tables;
	$resubmit_record = array_replace( $record, array( 'expected_state' => 'reopened' ) );
	$resubmit_commit = new WordPress_Student_Submission_Commit_Repository( $resubmission['database'], $resubmission['canonical'] );
	if ( $same_revision ) {
		g3_failure( static fn () => $resubmit_commit->auto_submit( $resubmit_record ), Assignment_Failure::STATE_CONFLICT, 'Auto-submit must reject the unchanged official answer.' );
		g3_same( $before, $resubmission['database']->tables, 'Rejected unchanged auto-submit must preserve results.' );
		continue;
	}
	g3_same( 'auto_submitted', $resubmit_commit->auto_submit( $resubmit_record ), 'Reopened answer must auto-submit.' );
	c26_assert_result_history( $before, $resubmission['database']->tables, false );
	$after = $resubmission['database']->tables;
	g3_same( $resubmission['revision_id'], $after['oj_ai_submissions'][0]['official_revision_public_id'], 'Auto-submit must promote the new answer.' );
	g3_same( 'stale', $resubmit_commit->auto_submit( $resubmit_record ), 'Auto-submit replay must be stale.' );
	g3_same( $after, $resubmission['database']->tables, 'Auto-submit replay must preserve committed history.' );
}

foreach ( array( 'audit', 'outbox', 'cas', 'official_review_public_id', 'official_review_hash', 'current_release_public_id', 'current_release_hash' ) as $fault ) {
	$failed                       = c26_reviewed_fixture();
	$failed_db                    = $failed['database'];
	$before                       = $failed_db->tables;
	$failed_db->fail_insert_table = match ( $fault ) {
		'audit' => 'oj_ai_audit_events', 'outbox' => 'oj_ai_outbox_events', default => null };
	$failed_db->fail_update            = 'cas' === $fault;
	$failed_db->ignored_update_columns = str_contains( $fault, '_' ) ? array( $fault ) : array();
	$expected_failure                  = match ( $fault ) {
		'audit', 'outbox' => Assignment_Failure::STORAGE_UNAVAILABLE,
		'cas' => Assignment_Failure::SUBMISSION_REVISION_CONFLICT,
		default => Assignment_Failure::SUBMIT_POSTCONDITION_MISMATCH,
	};
	$failed_commit = new WordPress_Student_Submission_Commit_Repository( $failed_db, $failed['canonical'] );
	$failed_record = array_replace( $record, array( 'expected_state' => 'reopened' ) );
	g3_failure( static fn () => $failed_commit->auto_submit( $failed_record ), $expected_failure, 'Failed auto-submit must be rejected: ' . $fault );
	g3_same( $before, $failed_db->tables, 'Failed auto-submit must restore answers, results, receipts and events: ' . $fault );
	g3_same( array( 'START TRANSACTION', 'ROLLBACK' ), $failed_db->queries, 'Failed auto-submit must roll back: ' . $fault );
}

$pending = c26_fixture();
$pending['database']->tables['oj_ai_private_files'][0]['scan_state'] = 'pending';
$pending_assignments = new Auto_Submit_Test_Assignments( $pending['database'], $pending );
$pending_runner      = new Run_Auto_Submit_Due(
	$pending_assignments,
	new WordPress_Student_Submission_Commit_Repository( $pending['database'], $pending['canonical'] ),
	auto_submit_ids(),
	static fn (): string => '2026-08-22 12:00:30',
	static fn (): string => 'bbbbbbbb-bbbb-4bbb-abbb-bbbbbbbbbbbb'
);
g3_same( Run_Auto_Submit_Due_Result::RETRY_WAIT, $pending_runner->run_once()->state(), 'Pending attachments must remain recoverable instead of becoming official.' );
g3_same( 'draft', $pending['database']->tables['oj_ai_submissions'][0]['state'], 'Pending attachment failure changed the draft root.' );
g3_same( 'attachment_not_ready', $pending_assignments->last_error, 'Pending attachment failure reason was not retained.' );

$empty = c26_fixture();
$empty['database']->tables['oj_ai_submissions'] = array();
$empty_assignments                              = new Auto_Submit_Test_Assignments( $empty['database'], $empty );
$empty_runner                                   = new Run_Auto_Submit_Due(
	$empty_assignments,
	new WordPress_Student_Submission_Commit_Repository( $empty['database'], $empty['canonical'] ),
	auto_submit_ids(),
	static fn (): string => '2026-08-22 12:00:30',
	static fn (): string => 'cccccccc-cccc-4ccc-accc-cccccccccccc'
);
g3_same( Run_Auto_Submit_Due_Result::COMPLETED, $empty_runner->run_once()->state(), 'Not-started learners must not create empty submissions.' );
g3_same( 0, count( $empty['database']->tables['oj_ai_submission_receipts'] ), 'Not-started learners received an empty auto-submit receipt.' );

$addon_root = dirname( __DIR__, 2 );
$sources    = array(
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/WordPress_Auto_Submit_Assignment_Repository.php' ),
	(string) file_get_contents( $addon_root . '/src/Submission/Student/Infrastructure/Auto_Submit_Due_Worker_Module.php' ),
	(string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' ),
);
foreach ( $sources as $source ) {
	g3_assert( ! str_contains( $source, '$wpdb->prefix' ) && ! str_contains( $source, 'wp_oj_ai_' ), 'R01 must use literal add-on tables only.' );
	g3_assert( ! str_contains( $source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'R01 must not depend on OJ Platform internals.' );
}
foreach ( array( 'assignment.auto_submit_due', 'auto_submit_completed_at_utc', 'lease_token', 'attempt_count', 'last_error_code' ) as $marker ) {
	g3_assert( str_contains( $sources[0], $marker ), 'R01 durable queue marker is missing: ' . $marker );
}
g3_assert( str_contains( $sources[1], "'interval' => 60" ), 'R01 worker must keep the frozen 60-second trigger.' );
g3_assert( str_contains( $sources[2], 'Auto_Submit_Due_Worker_Module' ), 'R01 Composition Root worker wiring is missing.' );

echo "G3-IMP-06-R01 passed: deadline compensation, valid-draft auto-submit, system receipt, effective close, retry, idempotency, and no empty submission.\n";
