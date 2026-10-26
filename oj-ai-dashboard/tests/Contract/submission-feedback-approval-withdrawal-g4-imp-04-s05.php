<?php
/**
 * G4-IMP-04-S05 teacher feedback approval withdrawal contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-approval-g4-imp-04-s03.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Feedback_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Submission_Feedback_Approval;
use OJAI\Dashboard\Submission\Assessment\Application\Withdraw_Submission_Feedback_Approval_Command;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 feedback withdrawal failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_withdrawal_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_review_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4-IMP-04-S05 teacher feedback approval withdrawal contract.
 */
final class G4_Feedback_Withdrawal_Repository implements Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface {
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
	 * @var array<string,array{digest:string,result:Submission_Feedback_Review_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 feedback withdrawal repository dependencies and state.
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
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_withdrawal_receipt( string $key, string $digest ): ?Submission_Feedback_Review_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$stored = $this->receipts[ $key ]['result'];
		return new Submission_Feedback_Review_Result(
			$stored->evaluation_id(),
			$stored->official_review_id(),
			$stored->official_review_hash(),
			$stored->feedback_revision_id(),
			$stored->feedback_revision_hash(),
			$stored->feedback_revision_no(),
			$stored->state(),
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
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_withdrawal_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		unset( $actor_user_id, $evaluation_id );
		return $this->snapshot;
	}

	/**
	 * Append withdrawn revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Feedback_Review_Result
	 */
	public function append_withdrawn_revision( array $record ): Submission_Feedback_Review_Result {
		$this->record = $record;
		++$this->append_count;
		$result = new Submission_Feedback_Review_Result(
			$record['evaluation_public_id'],
			$record['expected_official_review_id'],
			$record['expected_official_review_hash'],
			$record['revision_public_id'],
			$record['content_hash'],
			$record['revision_no'],
			$record['state'],
			$record['previous_feedback_revision_id'],
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
 * G4 feedback withdrawal command.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Withdraw_Submission_Feedback_Approval_Command
 */
function g4_feedback_withdrawal_command( array $source, array $snapshot, Canonical_Json $canonical, string $command_id = 'command-g4-feedback-withdrawal', string $fingerprint = '' ): Withdraw_Submission_Feedback_Approval_Command {
	return new Withdraw_Submission_Feedback_Approval_Command(
		42,
		$source['evaluation_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_feedback_revision_id'],
		$snapshot['latest_feedback_revision_hash'],
		$snapshot['latest_feedback_revision_no'],
		'' === $fingerprint ? str_repeat( 'b', 64 ) : $fingerprint,
		'correlation-g4-feedback-withdrawal',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		(string) $source['evaluation_lock_version'],
		'reviewed',
		'context_staff',
		$canonical
	);
}

/**
 * G4 feedback withdrawal application.
 *
 * @param G4_Feedback_Withdrawal_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                    $canonical Canonical JSON encoder used for stable digests.
 * @param string                            $time Time.
 * @return Withdraw_Submission_Feedback_Approval
 */
function g4_feedback_withdrawal_application( G4_Feedback_Withdrawal_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-24 15:05:00' ): Withdraw_Submission_Feedback_Approval {
	return new Withdraw_Submission_Feedback_Approval( $repository, new Public_Id_Generator(), $canonical, static fn (): string => $time );
}

/**
 * G4 feedback withdrawal approved snapshot.
 *
 * @param array<string,mixed>               $source Source.
 * @param Submission_Feedback_Review_Result $approved Approved.
 * @param array                             $approved_record Approved record.
 * @return array<string,mixed>
 */
function g4_feedback_withdrawal_approved_snapshot( array $source, Submission_Feedback_Review_Result $approved, array $approved_record ): array {
	$snapshot                                 = g4_feedback_review_snapshot(
		$source,
		$approved->feedback_revision_id(),
		$approved->feedback_revision_hash(),
		$approved->feedback_revision_no(),
		$approved->state(),
		$approved->official_review_id(),
		$approved->source_evaluation_run_id()
	);
	$snapshot['latest_feedback_schema']       = $approved_record['feedback_schema'];
	$snapshot['latest_feedback_version']      = $approved_record['feedback_version'];
	$snapshot['latest_feedback_json']         = $approved_record['feedback_json'];
	$snapshot['latest_feedback_profile_hash'] = $approved_record['feedback_hash'];
	return $snapshot;
}

$withdrawal_approved_snapshot = g4_feedback_withdrawal_approved_snapshot( $approval_source, $approved, $approval_repository->record );
$withdrawal_repository        = new G4_Feedback_Withdrawal_Repository( $withdrawal_approved_snapshot );
$withdrawal_application       = g4_feedback_withdrawal_application( $withdrawal_repository, $approval_canonical );
$withdrawal_command           = g4_feedback_withdrawal_command( $approval_source, $withdrawal_approved_snapshot, $approval_canonical );
$withdrawn                    = $withdrawal_application->execute( $withdrawal_command );

g4_feedback_review_same( 'teacher_draft', $withdrawn->state(), 'Withdrawal must append a teacher_draft revision.' );
g4_feedback_review_same( $withdrawal_approved_snapshot['latest_feedback_revision_no'] + 1, $withdrawn->feedback_revision_no(), 'Withdrawal must increment the exact approved revision.' );
g4_feedback_review_same( $withdrawal_approved_snapshot['latest_feedback_revision_id'], $withdrawn->previous_revision_id(), 'Withdrawal must link to the exact approved revision.' );
g4_feedback_review_same( $withdrawal_approved_snapshot['latest_feedback_source_run_id'], $withdrawn->source_evaluation_run_id(), 'Withdrawal must preserve AI source provenance.' );
g4_feedback_review_same( $withdrawal_approved_snapshot['latest_feedback_json'], $withdrawal_repository->record['feedback_json'] ?? null, 'Withdrawal must copy the exact approved profile without mutating it.' );
g4_feedback_review_same( $withdrawal_approved_snapshot['latest_feedback_profile_hash'], $withdrawal_repository->record['feedback_hash'] ?? null, 'Withdrawal must copy the exact approved profile hash.' );
g4_feedback_review_same( 'withdraw_approval', $withdrawal_repository->record['source_mode'] ?? null, 'Withdrawal storage record must identify the withdrawal transition.' );
g4_feedback_review_same( true, array_key_exists( 'reason_digest', $withdrawal_repository->record ?? array() ), 'Withdrawal record must include a reason_digest key.' );
g4_feedback_review_same( null, $withdrawal_repository->record['reason_digest'], 'Withdrawal must not require a reason.' );

$withdrawal_replay = $withdrawal_application->execute( $withdrawal_command );
g4_feedback_review_same( true, $withdrawal_replay->replayed(), 'Exact withdrawal retry must use the durable receipt.' );
g4_feedback_review_same( 1, $withdrawal_repository->append_count, 'Exact withdrawal retry must append zero additional revisions.' );
g4_feedback_withdrawal_failure(
	static fn () => $withdrawal_application->execute( g4_feedback_withdrawal_command( $approval_source, $withdrawal_approved_snapshot, $approval_canonical, 'command-g4-feedback-withdrawal-changed' ) ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Changed withdrawal request under one idempotency key must conflict.'
);

foreach ( array( 'ai_draft', 'teacher_draft', 'rejected', 'stale' ) as $non_withdrawable_state ) {
	$changed                          = $withdrawal_approved_snapshot;
	$changed['latest_feedback_state'] = $non_withdrawable_state;
	$changed_repository               = new G4_Feedback_Withdrawal_Repository( $changed );
	g4_feedback_withdrawal_failure(
		static fn () => g4_feedback_withdrawal_application( $changed_repository, $approval_canonical )->execute(
			g4_feedback_withdrawal_command( $approval_source, $withdrawal_approved_snapshot, $approval_canonical, 'command-g4-feedback-withdrawal-state-' . $non_withdrawable_state, hash( 'sha256', 'withdrawal-' . $non_withdrawable_state ) )
		),
		Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
		'Withdrawal must reject non-approved state ' . $non_withdrawable_state . '.'
	);
	g4_feedback_review_same( 0, $changed_repository->append_count, 'Rejected withdrawal state must append zero revisions.' );
}

$stale_review                         = $withdrawal_approved_snapshot;
$stale_review['official_review_hash'] = str_repeat( '8', 64 );
$stale_repository                     = new G4_Feedback_Withdrawal_Repository( $stale_review );
g4_feedback_withdrawal_failure(
	static fn () => g4_feedback_withdrawal_application( $stale_repository, $approval_canonical )->execute(
		g4_feedback_withdrawal_command( $approval_source, $withdrawal_approved_snapshot, $approval_canonical, 'command-g4-feedback-withdrawal-stale-review', str_repeat( '7', 64 ) )
	),
	Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
	'Withdrawal must reject a replaced official review.'
);

$corrupt_snapshot                                 = $withdrawal_approved_snapshot;
$corrupt_snapshot['latest_feedback_json']         = '{}';
$corrupt_snapshot['latest_feedback_profile_hash'] = hash( 'sha256', '{}' );
$corrupt_repository                               = new G4_Feedback_Withdrawal_Repository( $corrupt_snapshot );
g4_feedback_withdrawal_failure(
	static fn () => g4_feedback_withdrawal_application( $corrupt_repository, $approval_canonical )->execute(
		g4_feedback_withdrawal_command( $approval_source, $withdrawal_approved_snapshot, $approval_canonical, 'command-g4-feedback-withdrawal-corrupt', str_repeat( '6', 64 ) )
	),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'Withdrawal must revalidate the stored approved profile before withdrawing it.'
);
g4_feedback_review_same( 0, $corrupt_repository->append_count, 'Corrupt withdrawal source must append zero revisions.' );

$withdrawal_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Feedback_Review_Repository.php' );
$withdrawal_rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Feedback_Approval_Withdrawal_Rest_Controller.php' );
$withdrawal_root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
g4_feedback_review_assert( str_contains( $withdrawal_repository_source, "'submission_feedback.approval_withdrawn'" ) && str_contains( $withdrawal_repository_source, "'oj_ai.submission_feedback_approval_withdrawal_event'" ), 'Withdrawal must use a distinct event and outbox schema.' );
g4_feedback_review_assert( str_contains( $withdrawal_repository_source, 'Submission_Feedback_Approval_Withdrawal_Repository_Port_Interface' ) && str_contains( $withdrawal_repository_source, 'append_withdrawn_revision' ), 'The immutable revision repository must explicitly implement the withdrawal port.' );
g4_feedback_review_assert( ! str_contains( $withdrawal_repository_source, '$wpdb->prefix' ) && ! str_contains( $withdrawal_repository_source, 'wp_oj_ai_' ), 'Withdrawal storage must use literal oj_ai_* table names only.' );
g4_feedback_review_assert( str_contains( $withdrawal_rest_source, '/commands/withdraw-feedback-approval' ) && str_contains( $withdrawal_rest_source, 'REVIEW_ASSESSMENTS' ) && str_contains( $withdrawal_rest_source, 'array() !== $payload' ), 'Withdrawal REST must reuse the approval capability and route under /commands.' );
g4_feedback_review_assert( ! str_contains( $withdrawal_rest_source, "['feedback']" ) && ! str_contains( $withdrawal_rest_source, "['reason']" ), 'Withdrawal REST must not accept mutable feedback or reason fields.' );
g4_feedback_review_assert( str_contains( $withdrawal_root_source, 'new Submission_Feedback_Approval_Withdrawal_Rest_Module' ) && str_contains( $withdrawal_root_source, '$submission_feedback_revision_repository' ), 'Composition Root must activate the withdrawal route through the shared immutable revision repository.' );

fwrite( STDOUT, "G4-IMP-04-S05 teacher feedback approval-withdrawal provenance, replay, stale-state, and stored-profile contracts passed.\n" );
