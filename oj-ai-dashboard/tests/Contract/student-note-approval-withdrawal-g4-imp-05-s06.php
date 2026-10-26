<?php
/**
 * G4-IMP-05-S06 student-note approval withdrawal contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-review-approval-g4-imp-05-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Student_Note_Revision_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Student_Note_Approval;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Student_Note_Approval_Command;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Approval_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 note withdrawal failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_withdrawal_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4-IMP-05-S06 student-note approval withdrawal contract.
 */
final class G4_Student_Note_Withdrawal_Repository implements Student_Note_Approval_Withdrawal_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Append count.
	 *
	 * @var int
	 */
	public int $append_count = 0;
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Student_Note_Revision_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 student note withdrawal repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find withdrawal receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_withdrawal_receipt( string $key, string $digest ): ?Student_Note_Revision_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$stored = $this->receipts[ $key ]['result'];
		return new Student_Note_Revision_Result(
			$stored->note_id(),
			$stored->evaluation_id(),
			$stored->official_review_id(),
			$stored->official_review_hash(),
			$stored->revision_id(),
			$stored->revision_hash(),
			$stored->revision_no(),
			$stored->state(),
			$stored->include_in_reports(),
			$stored->previous_revision_id(),
			$stored->source_evaluation_run_id(),
			$stored->occurred_at_utc(),
			true
		);
	}

	/**
	 * Find owned withdrawal snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $note_id ): ?array {
		unset( $actor_user_id, $note_id );
		return $this->snapshot;
	}

	/**
	 * Append withdrawn revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_withdrawn_revision( array $record ): Student_Note_Revision_Result {
		$this->record = $record;
		++$this->append_count;
		$result = new Student_Note_Revision_Result(
			$record['note_root_id'],
			$record['evaluation_public_id'],
			$record['expected_official_review_id'],
			$record['expected_official_review_hash'],
			$record['revision_public_id'],
			$record['content_hash'],
			$record['revision_no'],
			$record['state'],
			$record['include_in_reports'],
			$record['previous_revision_id'],
			$record['source_evaluation_run_public_id'],
			$record['occurred_at_utc'],
			false
		);
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G4 note withdrawal command.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Withdraw_Student_Note_Approval_Command
 */
function g4_note_withdrawal_command( array $source, array $snapshot, Canonical_Json $canonical, string $command_id = 'command-g4-note-withdrawal', string $fingerprint = '' ): Withdraw_Student_Note_Approval_Command {
	return new Withdraw_Student_Note_Approval_Command(
		42,
		$snapshot['note_root_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_revision_id'],
		$snapshot['latest_revision_hash'],
		$snapshot['latest_revision_no'],
		'' === $fingerprint ? str_repeat( 'b', 64 ) : $fingerprint,
		'correlation-g4-note-withdrawal',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		$snapshot['latest_revision_hash'],
		'approved',
		'context_staff',
		$canonical
	);
}

/**
 * G4 note withdrawal application.
 *
 * @param G4_Student_Note_Withdrawal_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                        $canonical Canonical JSON encoder used for stable digests.
 * @param string                                $time Time.
 * @return Withdraw_Student_Note_Approval
 */
function g4_note_withdrawal_application( G4_Student_Note_Withdrawal_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-24 18:05:00' ): Withdraw_Student_Note_Approval {
	return new Withdraw_Student_Note_Approval( $repository, new Student_Note_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => $time );
}

/**
 * G4 note withdrawal approved snapshot.
 *
 * @param array<string,mixed>          $source Source.
 * @param Student_Note_Revision_Result $approved Approved.
 * @param array                        $approved_record Approved record.
 * @return array
 */
function g4_note_withdrawal_approved_snapshot( array $source, Student_Note_Revision_Result $approved, array $approved_record ): array {
	return g4_note_revision_snapshot(
		$source,
		$approved->revision_id(),
		$approved->revision_hash(),
		$approved->revision_no(),
		$approved->state(),
		$approved->source_evaluation_run_id(),
		$approved_record['note_json'],
		$approved_record['note_hash']
	);
}

$withdrawal_approved_snapshot = g4_note_withdrawal_approved_snapshot( $revision_source, $approved, $approval_repository->record );
$withdrawal_repository        = new G4_Student_Note_Withdrawal_Repository( $withdrawal_approved_snapshot );
$withdrawal_application       = g4_note_withdrawal_application( $withdrawal_repository, $revision_canonical );
$withdrawal_command           = g4_note_withdrawal_command( $revision_source, $withdrawal_approved_snapshot, $revision_canonical );
$withdrawn                    = $withdrawal_application->execute( $withdrawal_command );

g4_note_same( 'teacher_draft', $withdrawn->state(), 'Withdrawal must append a teacher_draft revision.' );
g4_note_same( $withdrawal_approved_snapshot['latest_revision_no'] + 1, $withdrawn->revision_no(), 'Withdrawal must increment the exact approved revision.' );
g4_note_same( $withdrawal_approved_snapshot['latest_revision_id'], $withdrawn->previous_revision_id(), 'Withdrawal must link to the exact approved revision.' );
g4_note_same( $withdrawal_approved_snapshot['latest_source_run_id'], $withdrawn->source_evaluation_run_id(), 'Withdrawal must preserve AI source provenance.' );
g4_note_same( false, $withdrawn->include_in_reports(), 'Withdrawal must not resurrect report inclusion.' );
g4_note_same( $withdrawal_approved_snapshot['latest_note_json'], $withdrawal_repository->record['note_json'] ?? null, 'Withdrawal must copy the exact approved profile without mutating it.' );
g4_note_same( $withdrawal_approved_snapshot['latest_note_hash'], $withdrawal_repository->record['note_hash'] ?? null, 'Withdrawal must copy the exact approved profile hash.' );
g4_note_same( 'withdraw_approval', $withdrawal_repository->record['source_mode'] ?? null, 'Withdrawal storage record must identify the withdrawal transition.' );
g4_note_same( true, array_key_exists( 'reason_digest', $withdrawal_repository->record ?? array() ), 'Withdrawal record must include a reason_digest key.' );
g4_note_same( null, $withdrawal_repository->record['reason_digest'], 'Withdrawal must not require a reason.' );
g4_note_same( false, $withdrawal_repository->record['include_in_reports'] ?? null, 'Withdrawal must never enable report inclusion.' );

$withdrawal_replay = $withdrawal_application->execute( $withdrawal_command );
g4_note_same( true, $withdrawal_replay->replayed(), 'Exact withdrawal retry must use the durable receipt.' );
g4_note_same( 1, $withdrawal_repository->append_count, 'Exact withdrawal retry must append zero additional revisions.' );
g4_note_withdrawal_failure(
	static fn () => $withdrawal_application->execute( g4_note_withdrawal_command( $revision_source, $withdrawal_approved_snapshot, $revision_canonical, 'command-g4-note-withdrawal-changed' ) ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Changed withdrawal request under one idempotency key must conflict.'
);

foreach ( array( 'ai_draft', 'teacher_draft', 'rejected', 'superseded' ) as $non_withdrawable_state ) {
	$changed                 = $withdrawal_approved_snapshot;
	$changed['latest_state'] = $non_withdrawable_state;
	$changed_repository      = new G4_Student_Note_Withdrawal_Repository( $changed );
	g4_note_withdrawal_failure(
		static fn () => g4_note_withdrawal_application( $changed_repository, $revision_canonical )->execute(
			g4_note_withdrawal_command( $revision_source, $withdrawal_approved_snapshot, $revision_canonical, 'command-g4-note-withdrawal-state-' . $non_withdrawable_state, hash( 'sha256', 'note-withdrawal-' . $non_withdrawable_state ) )
		),
		Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
		'Withdrawal must reject non-approved state ' . $non_withdrawable_state . '.'
	);
	g4_note_same( 0, $changed_repository->append_count, 'Rejected withdrawal state must append zero revisions.' );
}

$stale_review                         = $withdrawal_approved_snapshot;
$stale_review['official_review_hash'] = str_repeat( '8', 64 );
$stale_repository                     = new G4_Student_Note_Withdrawal_Repository( $stale_review );
g4_note_withdrawal_failure(
	static fn () => g4_note_withdrawal_application( $stale_repository, $revision_canonical )->execute(
		g4_note_withdrawal_command( $revision_source, $withdrawal_approved_snapshot, $revision_canonical, 'command-g4-note-withdrawal-stale-review', str_repeat( '7', 64 ) )
	),
	Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
	'Withdrawal must reject a replaced official review.'
);

$corrupt_snapshot                     = $withdrawal_approved_snapshot;
$corrupt_snapshot['latest_note_json'] = '{}';
$corrupt_snapshot['latest_note_hash'] = hash( 'sha256', '{}' );
$corrupt_repository                   = new G4_Student_Note_Withdrawal_Repository( $corrupt_snapshot );
g4_note_withdrawal_failure(
	static fn () => g4_note_withdrawal_application( $corrupt_repository, $revision_canonical )->execute(
		g4_note_withdrawal_command( $revision_source, $withdrawal_approved_snapshot, $revision_canonical, 'command-g4-note-withdrawal-corrupt', str_repeat( '6', 64 ) )
	),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'Withdrawal must revalidate the stored approved profile before withdrawing it.'
);
g4_note_same( 0, $corrupt_repository->append_count, 'Corrupt withdrawal source must append zero revisions.' );

$note_withdrawal_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Review_Repository.php' );
$note_withdrawal_rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Approval_Withdrawal_Rest_Controller.php' );
$note_withdrawal_root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
g4_note_assert( str_contains( $note_withdrawal_repository_source, "'student_note.approval_withdrawn'" ), 'Withdrawal must use a distinct event name.' );
g4_note_assert( str_contains( $note_withdrawal_repository_source, 'Student_Note_Approval_Withdrawal_Repository_Port_Interface' ) && str_contains( $note_withdrawal_repository_source, 'append_withdrawn_revision' ), 'The immutable revision repository must explicitly implement the withdrawal port.' );
g4_note_assert( ! str_contains( $note_withdrawal_repository_source, '$wpdb->prefix' ) && ! str_contains( $note_withdrawal_repository_source, 'wp_oj_ai_' ), 'Withdrawal storage must use literal oj_ai_* table names only.' );
g4_note_assert( str_contains( $note_withdrawal_rest_source, '/commands/withdraw-approval' ) && str_contains( $note_withdrawal_rest_source, 'MANAGE_STUDENT_NOTES' ) && str_contains( $note_withdrawal_rest_source, 'array() !== $payload' ), 'Withdrawal REST must reuse the note-management capability and route under /commands.' );
g4_note_assert( ! str_contains( $note_withdrawal_rest_source, "['note']" ) && ! str_contains( $note_withdrawal_rest_source, "['reason']" ), 'Withdrawal REST must not accept mutable note or reason fields.' );
g4_note_assert( str_contains( $note_withdrawal_root_source, 'new Student_Note_Approval_Withdrawal_Rest_Module' ) && str_contains( $note_withdrawal_root_source, '$student_note_revision_repository' ), 'Composition Root must activate the withdrawal route through the shared immutable revision repository.' );

fwrite( STDOUT, "G4-IMP-05-S06 student-note approval-withdrawal provenance, replay, stale-state, and stored-profile contracts passed.\n" );
