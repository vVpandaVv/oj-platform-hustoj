<?php
/**
 * G4-IMP-05-S03 C39/C40 student-note review and approval contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

ob_start();
require __DIR__ . '/student-note-draft-g4-imp-05-s01.php';
ob_end_clean();

use OJAI\Dashboard\Submission\Assessment\Application\Approve_Student_Note;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Student_Note_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Student_Note;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Student_Note_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Student_Note_Revision_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Approval_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 note revision failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_revision_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4-IMP-05-S03 C39/C40 student-note review and approval contract.
 */
final class G4_Student_Note_Revision_Repository implements Student_Note_Review_Repository_Port_Interface, Student_Note_Approval_Repository_Port_Interface {
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
	 * Initialize g4 student note revision repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}

	/**
	 * Find review receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_review_receipt( string $key, string $digest ): ?Student_Note_Revision_Result {
		return $this->receipt( $key, $digest );
	}
	/**
	 * Find approval receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 */
	public function find_approval_receipt( string $key, string $digest ): ?Student_Note_Revision_Result {
		return $this->receipt( $key, $digest );
	}
	/**
	 * Find owned review snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_review_snapshot( int $actor_user_id, string $note_id ): ?array {
		unset( $actor_user_id );
		return $note_id === $this->snapshot['note_root_id'] ? $this->snapshot : null;
	}
	/**
	 * Find owned approval snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $note_id Note id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_approval_snapshot( int $actor_user_id, string $note_id ): ?array {
		return $this->find_owned_review_snapshot( $actor_user_id, $note_id );
	}
	/**
	 * Append review revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_review_revision( array $record ): Student_Note_Revision_Result {
		return $this->append( $record );
	}
	/**
	 * Append approved revision.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	public function append_approved_revision( array $record ): Student_Note_Revision_Result {
		return $this->append( $record );
	}

	/**
	 * Receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Student_Note_Revision_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function receipt( string $key, string $digest ): ?Student_Note_Revision_Result {
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
	 * Append.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return Student_Note_Revision_Result
	 */
	private function append( array $record ): Student_Note_Revision_Result {
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
 * G4 note revision snapshot.
 *
 * @param array<string,mixed> $source Source.
 * @param string|null         $revision_id Revision id.
 * @param string|null         $revision_hash Revision hash.
 * @param int                 $revision_no Revision no.
 * @param string|null         $state State.
 * @param string|null         $source_run_id Source run id.
 * @param string|null         $note_json Note json.
 * @param string|null         $note_hash Note hash.
 * @return array<string,mixed>
 */
function g4_note_revision_snapshot( array $source, ?string $revision_id = null, ?string $revision_hash = null, int $revision_no = 0, ?string $state = null, ?string $source_run_id = null, ?string $note_json = null, ?string $note_hash = null ): array {
	return array(
		'note_root_id'              => $source['evaluation_id'],
		'evaluation_id'             => $source['evaluation_id'],
		'evaluation_lock_version'   => $source['evaluation_lock_version'],
		'evaluation_state'          => $source['evaluation_state'],
		'context_type'              => $source['context_type'],
		'context_id'                => $source['context_id'],
		'context_revision'          => $source['context_revision'],
		'official_review_id'        => $source['official_review_id'],
		'official_review_hash'      => $source['official_review_hash'],
		'latest_revision_id'        => $revision_id,
		'latest_revision_hash'      => $revision_hash,
		'latest_revision_no'        => $revision_no,
		'latest_state'              => $state,
		'latest_include_in_reports' => false,
		'latest_source_run_id'      => $source_run_id,
		'latest_note_json'          => $note_json,
		'latest_note_hash'          => $note_hash,
	);
}

/**
 * G4 note teacher payload.
 *
 * @param array<string,mixed> $gateway_input Gateway input.
 * @return array<string,mixed>
 */
function g4_note_teacher_payload( array $gateway_input ): array {
	$output = g4_note_output( $gateway_input );
	return array(
		'evidence'            => $output['evidence'],
		'strengths'           => $output['strengths'],
		'summary'             => $output['summary'],
		'support_suggestions' => $output['support_suggestions'],
	);
}

/**
 * G4 note review command.
 *
 * @param array<string,mixed>      $source Source.
 * @param array<string,mixed>      $snapshot Snapshot.
 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
 * @param string                   $decision Decision.
 * @param string                   $source_mode Source mode.
 * @param array<string,mixed>|null $note Note.
 * @param string|null              $reason Reason.
 * @param string                   $command_id Command identifier used for idempotency.
 * @param string                   $fingerprint Fingerprint.
 * @return Review_Student_Note_Command
 */
function g4_note_review_command( array $source, array $snapshot, Canonical_Json $canonical, string $decision, string $source_mode, ?array $note, ?string $reason, string $command_id = 'command-g4-note-review', string $fingerprint = '' ): Review_Student_Note_Command {
	$initial = null === $snapshot['latest_revision_id'];
	return new Review_Student_Note_Command(
		42,
		$snapshot['note_root_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_revision_id'],
		$snapshot['latest_revision_hash'],
		$snapshot['latest_revision_no'],
		$snapshot['latest_state'],
		$snapshot['latest_include_in_reports'],
		$decision,
		$source_mode,
		$note,
		$reason,
		'' === $fingerprint ? str_repeat( '7', 64 ) : $fingerprint,
		'correlation-g4-note-review',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		$initial ? $source['official_review_hash'] : $snapshot['latest_revision_hash'],
		$initial ? 'reviewed' : $snapshot['latest_state'],
		'context_staff',
		$canonical
	);
}

/**
 * G4 note approval command.
 *
 * @param array<string,mixed> $source Source.
 * @param array<string,mixed> $snapshot Snapshot.
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $fingerprint Fingerprint.
 * @return Approve_Student_Note_Command
 */
function g4_note_approval_command( array $source, array $snapshot, Canonical_Json $canonical, string $command_id = 'command-g4-note-approval', string $fingerprint = '' ): Approve_Student_Note_Command {
	return new Approve_Student_Note_Command(
		42,
		$snapshot['note_root_id'],
		$command_id,
		$snapshot['official_review_id'],
		$snapshot['official_review_hash'],
		$snapshot['latest_revision_id'],
		$snapshot['latest_revision_hash'],
		$snapshot['latest_revision_no'],
		'teacher_draft',
		false,
		'' === $fingerprint ? str_repeat( '8', 64 ) : $fingerprint,
		'correlation-g4-note-approval',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		$snapshot['latest_revision_hash'],
		'teacher_draft',
		'context_staff',
		$canonical
	);
}

/**
 * G4 note review application.
 *
 * @param array<string,mixed>                 $source Source.
 * @param G4_Student_Note_Revision_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                      $canonical Canonical JSON encoder used for stable digests.
 * @param string                              $time Time.
 * @return Review_Student_Note
 */
function g4_note_review_application( array $source, G4_Student_Note_Revision_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-24 18:00:00' ): Review_Student_Note {
	return new Review_Student_Note(
		new G4_Note_Repository( $source ),
		$repository,
		new Student_Note_Input_Builder( $canonical ),
		new Student_Note_Output_Normalizer( $canonical ),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => $time
	);
}

/**
 * G4 note approval application.
 *
 * @param array<string,mixed>                 $source Source.
 * @param G4_Student_Note_Revision_Repository $repository Persistence boundary used by this operation.
 * @param Canonical_Json                      $canonical Canonical JSON encoder used for stable digests.
 * @param string                              $time Time.
 * @return Approve_Student_Note
 */
function g4_note_approval_application( array $source, G4_Student_Note_Revision_Repository $repository, Canonical_Json $canonical, string $time = '2026-08-24 18:01:00' ): Approve_Student_Note {
	return new Approve_Student_Note(
		new G4_Note_Repository( $source ),
		$repository,
		new Student_Note_Output_Normalizer( $canonical ),
		new Public_Id_Generator(),
		$canonical,
		static fn (): string => $time
	);
}

$revision_canonical = new Canonical_Json();
$revision_source    = g4_note_source( $revision_canonical, 3 );
$revision_gateway   = ( new Student_Note_Input_Builder( $revision_canonical ) )->build( $revision_source )->gateway_input();
$teacher_note       = g4_note_teacher_payload( $revision_gateway );
$initial_snapshot   = g4_note_revision_snapshot( $revision_source );
$direct_repository  = new G4_Student_Note_Revision_Repository( $initial_snapshot );
$direct_application = g4_note_review_application( $revision_source, $direct_repository, $revision_canonical );
$direct_command     = g4_note_review_command( $revision_source, $initial_snapshot, $revision_canonical, 'teacher_draft', 'direct', $teacher_note, null );
$direct             = $direct_application->execute( $direct_command );

g4_note_same( $revision_source['evaluation_id'], $direct->note_id(), 'The first direct C39 write must use the evaluation PublicId as its stable note root.' );
g4_note_same( 'teacher_draft', $direct->state(), 'C39 direct authoring must append a teacher draft.' );
g4_note_same( 1, $direct->revision_no(), 'The first direct C39 write must be revision one.' );
g4_note_same( null, $direct->previous_revision_id(), 'The first direct C39 write cannot have a predecessor.' );
g4_note_same( null, $direct->source_evaluation_run_id(), 'Direct teacher authoring must not invent AI provenance.' );
g4_note_same( false, $direct->include_in_reports(), 'C39 must remain excluded from reports.' );
g4_note_same( Student_Note_Input_Builder::RESULT_SCHEMA, $direct_repository->record['note_schema'] ?? null, 'C39 must store the canonical student-note profile.' );

$direct_replay = $direct_application->execute( $direct_command );
g4_note_same( true, $direct_replay->replayed(), 'An exact C39 retry must replay its durable receipt.' );
g4_note_same( 1, $direct_repository->append_count, 'An exact C39 retry must append no second revision.' );
g4_note_revision_failure(
	static fn () => $direct_application->execute( g4_note_review_command( $revision_source, $initial_snapshot, $revision_canonical, 'teacher_draft', 'direct', $teacher_note, null, 'command-g4-note-review-changed' ) ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'A changed C39 request under one idempotency key must conflict.'
);

$ai_profile       = ( new Student_Note_Output_Normalizer( $revision_canonical ) )->normalize( g4_note_output( $revision_gateway ), $revision_gateway );
$ai_revision_id   = g4_note_id();
$ai_revision_hash = str_repeat( '1', 64 );
$ai_run_id        = g4_note_id();
$ai_snapshot      = g4_note_revision_snapshot( $revision_source, $ai_revision_id, $ai_revision_hash, 1, 'ai_draft', $ai_run_id, $ai_profile->profile_json(), $ai_profile->profile_hash() );
$edit_repository  = new G4_Student_Note_Revision_Repository( $ai_snapshot );
$edited           = g4_note_review_application( $revision_source, $edit_repository, $revision_canonical, '2026-08-24 18:02:00' )->execute(
	g4_note_review_command( $revision_source, $ai_snapshot, $revision_canonical, 'teacher_draft', 'edit', $teacher_note, null, 'command-g4-note-edit', str_repeat( '2', 64 ) )
);
g4_note_same( $ai_run_id, $edited->source_evaluation_run_id(), 'Editing an AI draft must preserve its run provenance.' );
g4_note_same( $ai_revision_id, $edited->previous_revision_id(), 'Editing an AI draft must link to the exact source revision.' );

$rejected_repository = new G4_Student_Note_Revision_Repository( $ai_snapshot );
$rejected            = g4_note_review_application( $revision_source, $rejected_repository, $revision_canonical, '2026-08-24 18:03:00' )->execute(
	g4_note_review_command( $revision_source, $ai_snapshot, $revision_canonical, 'rejected', 'edit', null, 'Review the supporting evidence.', 'command-g4-note-reject', str_repeat( '3', 64 ) )
);
g4_note_same( 'rejected', $rejected->state(), 'C39 rejection must append a rejected revision.' );
g4_note_same( $ai_profile->profile_json(), $rejected_repository->record['note_json'] ?? null, 'C39 rejection must preserve the exact prior profile.' );
g4_note_same( hash( 'sha256', 'Review the supporting evidence.' ), $rejected_repository->record['reason_digest'] ?? null, 'C39 audit storage must retain only a reason digest.' );

$unsafe_note                    = $teacher_note;
$unsafe_note['summary']['text'] = 'The learner has a gifted personality and higher ability than peers.';
$unsafe_repository              = new G4_Student_Note_Revision_Repository( $initial_snapshot );
g4_note_revision_failure(
	static fn () => g4_note_review_application( $revision_source, $unsafe_repository, $revision_canonical )->execute(
		g4_note_review_command( $revision_source, $initial_snapshot, $revision_canonical, 'teacher_draft', 'direct', $unsafe_note, null, 'command-g4-note-unsafe', str_repeat( '4', 64 ) )
	),
	Submission_Evaluation_Failure::STUDENT_NOTE_SCHEMA_INVALID,
	'C39 teacher text must pass the same evidence and safety normalizer as AI output.'
);
g4_note_same( 0, $unsafe_repository->append_count, 'Unsafe C39 text must append zero revisions.' );

$stale_snapshot                         = $initial_snapshot;
$stale_snapshot['official_review_hash'] = str_repeat( '9', 64 );
$stale_repository                       = new G4_Student_Note_Revision_Repository( $stale_snapshot );
g4_note_revision_failure(
	static fn () => g4_note_review_application( $revision_source, $stale_repository, $revision_canonical )->execute(
		g4_note_review_command( $revision_source, $initial_snapshot, $revision_canonical, 'teacher_draft', 'direct', $teacher_note, null, 'command-g4-note-stale', str_repeat( '5', 64 ) )
	),
	Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
	'C39 must reject a replaced official review.'
);

$teacher_snapshot     = g4_note_revision_snapshot(
	$revision_source,
	$direct->revision_id(),
	$direct->revision_hash(),
	$direct->revision_no(),
	$direct->state(),
	$direct->source_evaluation_run_id(),
	$direct_repository->record['note_json'],
	$direct_repository->record['note_hash']
);
$approval_repository  = new G4_Student_Note_Revision_Repository( $teacher_snapshot );
$approval_application = g4_note_approval_application( $revision_source, $approval_repository, $revision_canonical );
$approval_command     = g4_note_approval_command( $revision_source, $teacher_snapshot, $revision_canonical );
$approved             = $approval_application->execute( $approval_command );
g4_note_same( 'approved', $approved->state(), 'C40 must append an approved revision.' );
g4_note_same( 2, $approved->revision_no(), 'C40 must increment the exact teacher draft.' );
g4_note_same( $direct->revision_id(), $approved->previous_revision_id(), 'C40 must link to the exact teacher draft.' );
g4_note_same( $direct_repository->record['note_json'], $approval_repository->record['note_json'] ?? null, 'C40 must copy the canonical teacher draft without a replacement body.' );
g4_note_same( $direct_repository->record['note_hash'], $approval_repository->record['note_hash'] ?? null, 'C40 must preserve the exact teacher profile hash.' );
g4_note_same( false, $approved->include_in_reports(), 'C40 approval must not bypass C41/C42 report inclusion.' );

$approval_replay = $approval_application->execute( $approval_command );
g4_note_same( true, $approval_replay->replayed(), 'An exact C40 retry must replay its durable receipt.' );
g4_note_same( 1, $approval_repository->append_count, 'An exact C40 retry must append no second revision.' );
g4_note_revision_failure(
	static fn () => $approval_application->execute( g4_note_approval_command( $revision_source, $teacher_snapshot, $revision_canonical, 'command-g4-note-approval-changed' ) ),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'A changed C40 request under one idempotency key must conflict.'
);

$wrong_state                 = $teacher_snapshot;
$wrong_state['latest_state'] = 'approved';
$wrong_state_repository      = new G4_Student_Note_Revision_Repository( $wrong_state );
g4_note_revision_failure(
	static fn () => g4_note_approval_application( $revision_source, $wrong_state_repository, $revision_canonical )->execute(
		g4_note_approval_command( $revision_source, $teacher_snapshot, $revision_canonical, 'command-g4-note-state', str_repeat( '6', 64 ) )
	),
	Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
	'C40 must approve only an exact teacher draft.'
);

$corrupt_snapshot                     = $teacher_snapshot;
$corrupt_snapshot['latest_note_json'] = '{}';
$corrupt_snapshot['latest_note_hash'] = hash( 'sha256', '{}' );
$corrupt_repository                   = new G4_Student_Note_Revision_Repository( $corrupt_snapshot );
g4_note_revision_failure(
	static fn () => g4_note_approval_application( $revision_source, $corrupt_repository, $revision_canonical )->execute(
		g4_note_approval_command( $revision_source, $corrupt_snapshot, $revision_canonical, 'command-g4-note-corrupt', str_repeat( 'a', 64 ) )
	),
	Submission_Evaluation_Failure::STORED_DATA_INVALID,
	'C40 must revalidate the stored teacher profile before approval.'
);

$repository_source    = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Review_Repository.php' );
$review_rest_source   = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Review_Rest_Controller.php' );
$approval_rest_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Approval_Rest_Controller.php' );
$root_source          = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
g4_note_assert( str_contains( $repository_source, "'oj_ai_student_note_revisions'" ) && ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'C39/C40 storage must use literal oj_ai_* tables only.' );
g4_note_assert( str_contains( $repository_source, "'student_note.reviewed'" ) && str_contains( $repository_source, "'student_note.approved'" ) && str_contains( $repository_source, "'oj_ai.student_note_audit'" ), 'C39/C40 must append distinct audit and outbox events under the Q19 audit schema.' );
g4_note_assert( str_contains( $review_rest_source, '/commands/review' ) && str_contains( $review_rest_source, 'MANAGE_STUDENT_NOTES' ) && str_contains( $review_rest_source, 'TYPE_STUDENT_NOTE' ), 'C39 REST route and policy evidence drifted.' );
g4_note_assert( str_contains( $approval_rest_source, '/commands/approve' ) && str_contains( $approval_rest_source, 'array() !== $payload' ) && ! str_contains( $approval_rest_source, "['note']" ), 'C40 REST must retain its frozen route and empty payload.' );
g4_note_assert( str_contains( $root_source, 'new Student_Note_Review_Rest_Module' ) && str_contains( $root_source, 'new Student_Note_Approval_Rest_Module' ) && str_contains( $root_source, '$student_note_revision_repository' ), 'Composition Root must activate C39/C40 through the shared immutable revision repository.' );

fwrite( STDOUT, "G4-IMP-05-S03 C39/C40 direct authoring, AI edit, rejection, approval, replay, safety, and stale-source contracts passed.\n" );
