<?php
/**
 * G4-IMP-04-S03 C37 exact teacher feedback approval contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/submission-feedback-review-g4-imp-04-s02.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Approve_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Submission_Feedback_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Feedback_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 feedback approval failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_approval_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_review_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4-IMP-04-S03 C37 exact teacher feedback approval contract.
 */
final class G4_Feedback_Approval_Repository implements Submission_Feedback_Approval_Repository_Port_Interface {
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
	 * Initialize g4 feedback approval repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find approval command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_approval_command_receipt( string $key, string $digest ): ?Submission_Feedback_Review_Result {
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
	 * Find owned approval snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_approval_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		unset( $actor_user_id, $evaluation_id );
		return $this->snapshot;
	}

	/**
	 * Append approved revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Submission_Feedback_Review_Result
	 */
	public function append_approved_revision( array $record ): Submission_Feedback_Review_Result {
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
 * G4 feedback approval fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param bool           $ai_origin Ai origin.
 * @return array{source:array<string,mixed>,snapshot:array<string,mixed>}
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_approval_fixture( Canonical_Json $canonical, bool $ai_origin = true ): array {
	$source      = g4_feedback_review_source( $canonical );
	$source_port = new G4_Feedback_Review_Source( $source );
	if ( $ai_origin ) {
		$prior_id        = g4_feedback_review_id();
		$prior_hash      = str_repeat( '1', 64 );
		$source_run_id   = g4_feedback_review_id();
		$review_snapshot = g4_feedback_review_snapshot( $source, $prior_id, $prior_hash, 1, 'ai_draft', $source['official_review_id'], $source_run_id );
		$source_mode     = 'edit';
	} else {
		$prior_id        = null;
		$prior_hash      = null;
		$source_run_id   = null;
		$review_snapshot = g4_feedback_review_snapshot( $source );
		$source_mode     = 'direct';
	}
	$review_repository = new G4_Feedback_Review_Repository( $review_snapshot );
	$review            = new Review_Submission_Feedback(
		$source_port,
		$review_repository,
		new Submission_Feedback_Input_Builder( $canonical ),
		new Submission_Feedback_Output_Normalizer( $canonical ),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => '2026-08-24 15:00:00'
	);
	$created           = $review->execute(
		g4_feedback_review_command(
			$source,
			$canonical,
			'teacher_draft',
			$source_mode,
			$prior_id,
			$prior_hash,
			$ai_origin ? 1 : 0,
			g4_feedback_review_payload(),
			null,
			$ai_origin ? 'command-g4-feedback-approval-source-ai' : 'command-g4-feedback-approval-source-direct',
			$ai_origin ? str_repeat( '2', 64 ) : str_repeat( '3', 64 )
		)
	);
	$record            = $review_repository->record;
	if ( null === $record ) {
		throw new RuntimeException( 'C37 source draft fixture was not recorded.' );
	}
	$snapshot                                 = g4_feedback_review_snapshot(
		$source,
		$created->feedback_revision_id(),
		$created->feedback_revision_hash(),
		$created->feedback_revision_no(),
		$created->state(),
		$created->official_review_id(),
		$created->source_evaluation_run_id()
	);
	$snapshot['latest_feedback_schema']       = $record['feedback_schema'];
	$snapshot['latest_feedback_version']      = $record['feedback_version'];
	$snapshot['latest_feedback_json']         = $record['feedback_json'];
	$snapshot['latest_feedback_profile_hash'] = $record['feedback_hash'];
	return array(
		'source'   => $source,
		'snapshot' => $snapshot,
	);
}

/**
 * G4 feedback approval command.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Approve_Submission_Feedback_Command
 */
function g4_feedback_approval_command( array $source, array $snapshot, Canonical_Json $canonical, string $command_id = 'command-g4-feedback-approval', string $fingerprint = '' ): Approve_Submission_Feedback_Command {
	return new Approve_Submission_Feedback_Command(
		42,
		$source['evaluation_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_feedback_revision_id'],
		$snapshot['latest_feedback_revision_hash'],
		$snapshot['latest_feedback_revision_no'],
		'' === $fingerprint ? str_repeat( '4', 64 ) : $fingerprint,
		'correlation-g4-feedback-approval',
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
 * G4 feedback approval application.
 *
 * @param array<string,mixed>             $source Source.
 * @param G4_Feedback_Approval_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                  $canonical Canonical JSON encoder used for stable digests.
 * @param string                          $time Time.
 * @return Approve_Submission_Feedback
 */
function g4_feedback_approval_application( array $source, G4_Feedback_Approval_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-24 15:01:00' ): Approve_Submission_Feedback {
	return new Approve_Submission_Feedback(
		new G4_Feedback_Review_Source( $source ),
		$repository,
		new Submission_Feedback_Input_Builder( $canonical ),
		new Submission_Feedback_Output_Normalizer( $canonical ),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => $time
	);
}

$approval_canonical   = new Canonical_Json();
$approval_fixture     = g4_feedback_approval_fixture( $approval_canonical, true );
$approval_source      = $approval_fixture['source'];
$approval_snapshot    = $approval_fixture['snapshot'];
$approval_repository  = new G4_Feedback_Approval_Repository( $approval_snapshot );
$approval_application = g4_feedback_approval_application( $approval_source, $approval_repository, $approval_canonical );
$approval_command     = g4_feedback_approval_command( $approval_source, $approval_snapshot, $approval_canonical );
$approved             = $approval_application->execute( $approval_command );

g4_feedback_review_same( 'approved', $approved->state(), 'C37 must append an approved revision.' );
g4_feedback_review_same( $approval_snapshot['latest_feedback_revision_no'] + 1, $approved->feedback_revision_no(), 'C37 must increment the exact teacher draft revision.' );
g4_feedback_review_same( $approval_snapshot['latest_feedback_revision_id'], $approved->previous_revision_id(), 'C37 must link to the exact teacher draft.' );
g4_feedback_review_same( $approval_snapshot['latest_feedback_source_run_id'], $approved->source_evaluation_run_id(), 'C37 must preserve AI source provenance.' );
g4_feedback_review_same( $approval_snapshot['latest_feedback_json'], $approval_repository->record['feedback_json'] ?? null, 'C37 must copy the canonical teacher draft profile without accepting a replacement body.' );
g4_feedback_review_same( $approval_snapshot['latest_feedback_profile_hash'], $approval_repository->record['feedback_hash'] ?? null, 'C37 must copy the exact teacher draft profile hash.' );
g4_feedback_review_same( 'approve', $approval_repository->record['source_mode'] ?? null, 'C37 storage record must identify the approval transition.' );

$approval_replay = $approval_application->execute( $approval_command );
g4_feedback_review_same( true, $approval_replay->replayed(), 'Exact C37 retry must use the durable receipt.' );
g4_feedback_review_same( 1, $approval_repository->append_count, 'Exact C37 retry must append zero additional revisions.' );
g4_feedback_approval_failure(
	static fn () => $approval_application->execute( g4_feedback_approval_command( $approval_source, $approval_snapshot, $approval_canonical, 'command-g4-feedback-approval-changed' ) ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'Changed C37 request under one idempotency key must conflict.'
);

$direct_fixture    = g4_feedback_approval_fixture( $approval_canonical, false );
$direct_repository = new G4_Feedback_Approval_Repository( $direct_fixture['snapshot'] );
$direct_approved   = g4_feedback_approval_application( $direct_fixture['source'], $direct_repository, $approval_canonical, '2026-08-24 15:02:00' )->execute(
	g4_feedback_approval_command( $direct_fixture['source'], $direct_fixture['snapshot'], $approval_canonical, 'command-g4-feedback-approval-direct', str_repeat( '5', 64 ) )
);
g4_feedback_review_same( null, $direct_approved->source_evaluation_run_id(), 'C37 must preserve null provenance for a directly authored teacher draft.' );

foreach ( array( 'ai_draft', 'rejected', 'approved', 'stale' ) as $non_approvable_state ) {
	$changed                          = $approval_snapshot;
	$changed['latest_feedback_state'] = $non_approvable_state;
	$changed_repository               = new G4_Feedback_Approval_Repository( $changed );
	g4_feedback_approval_failure(
		static fn () => g4_feedback_approval_application( $approval_source, $changed_repository, $approval_canonical )->execute(
			g4_feedback_approval_command( $approval_source, $approval_snapshot, $approval_canonical, 'command-g4-feedback-approval-state-' . $non_approvable_state, hash( 'sha256', $non_approvable_state ) )
		),
		Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
		'C37 must reject non-teacher-draft state ' . $non_approvable_state . '.'
	);
	g4_feedback_review_same( 0, $changed_repository->append_count, 'Rejected C37 state must append zero revisions.' );
}

$stale_review                         = $approval_snapshot;
$stale_review['official_review_hash'] = str_repeat( '9', 64 );
$stale_repository                     = new G4_Feedback_Approval_Repository( $stale_review );
g4_feedback_approval_failure(
	static fn () => g4_feedback_approval_application( $approval_source, $stale_repository, $approval_canonical )->execute(
		g4_feedback_approval_command( $approval_source, $approval_snapshot, $approval_canonical, 'command-g4-feedback-approval-stale-review', str_repeat( '6', 64 ) )
	),
	Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE,
	'C37 must reject a replaced official review.'
);

$corrupt_snapshot                                 = $approval_snapshot;
$corrupt_snapshot['latest_feedback_json']         = '{}';
$corrupt_snapshot['latest_feedback_profile_hash'] = hash( 'sha256', '{}' );
$corrupt_repository                               = new G4_Feedback_Approval_Repository( $corrupt_snapshot );
g4_feedback_approval_failure(
	static fn () => g4_feedback_approval_application( $approval_source, $corrupt_repository, $approval_canonical )->execute(
		g4_feedback_approval_command( $approval_source, $approval_snapshot, $approval_canonical, 'command-g4-feedback-approval-corrupt', str_repeat( '7', 64 ) )
	),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'C37 must revalidate the stored teacher draft profile before approval.'
);
g4_feedback_review_same( 0, $corrupt_repository->append_count, 'Corrupt C37 source must append zero revisions.' );

$approval_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Feedback_Review_Repository.php' );
$approval_rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Feedback_Approval_Rest_Controller.php' );
$approval_root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
g4_feedback_review_assert( str_contains( $approval_repository_source, "'submission_feedback.approved'" ) && str_contains( $approval_repository_source, "'oj_ai.submission_feedback_approval_event'" ), 'C37 must use a distinct approval event and outbox schema.' );
g4_feedback_review_assert( str_contains( $approval_repository_source, 'Submission_Feedback_Approval_Repository_Port_Interface' ) && str_contains( $approval_repository_source, 'append_approved_revision' ), 'The immutable revision repository must explicitly implement the C37 port.' );
g4_feedback_review_assert( ! str_contains( $approval_repository_source, '$wpdb->prefix' ) && ! str_contains( $approval_repository_source, 'wp_oj_ai_' ), 'C37 production storage must use literal oj_ai_* table names only.' );
g4_feedback_review_assert( str_contains( $approval_rest_source, '/commands/approve-feedback' ) && str_contains( $approval_rest_source, 'REVIEW_ASSESSMENTS' ) && str_contains( $approval_rest_source, 'array() !== $payload' ), 'C37 REST must retain its frozen route, capability, and empty payload.' );
g4_feedback_review_assert( ! str_contains( $approval_rest_source, "['feedback']" ) && ! str_contains( $approval_rest_source, "['reason']" ), 'C37 REST must not accept mutable feedback or reason fields.' );
g4_feedback_review_assert( str_contains( $approval_root_source, 'new Submission_Feedback_Approval_Rest_Module' ) && str_contains( $approval_root_source, '$submission_feedback_revision_repository' ), 'Composition Root must activate C37 through the shared immutable revision repository.' );

fwrite( STDOUT, "G4-IMP-04-S03 C37 exact teacher draft approval, provenance, replay, stale-state, and stored-profile contracts passed.\n" );
