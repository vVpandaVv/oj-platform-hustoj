<?php
/**
 * G4-IMP-04-S02 C36 teacher feedback review contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . '/src/Core/Autoloader.php';

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Feedback_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Review_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 feedback review assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_review_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 feedback review same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_review_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 feedback review invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_review_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback review failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_review_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_review_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback review id.
 *
 * @return string
 */
function g4_feedback_review_id(): string {
	static $counter = 46000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_feedback_review_assert( Public_Id::is_valid( $id ), 'C36 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 feedback review source.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g4_feedback_review_source( Canonical_Json $canonical ): array {
	$response_id = g4_feedback_review_id();
	$first_key   = str_repeat( 'a', 64 );
	$second_key  = str_repeat( 'b', 64 );
	$payload     = array(
		'bindings'  => array(
			array(
				'binding_key'    => $first_key,
				'criterion_id'   => g4_feedback_review_id(),
				'label'          => 'Explanation',
				'maximum_points' => '6',
				'position'       => 0,
				'response_id'    => $response_id,
				'scope'          => 'response',
			),
			array(
				'binding_key'    => $second_key,
				'criterion_id'   => g4_feedback_review_id(),
				'label'          => 'Structure',
				'maximum_points' => '4',
				'position'       => 1,
				'response_id'    => null,
				'scope'          => 'submission',
			),
		),
		'responses' => array(
			array(
				'answer'      => array( 'text' => 'The response uses evidence.' ),
				'kind'        => 'long_text',
				'label'       => 'Written response',
				'position'    => 0,
				'response_id' => $response_id,
			),
		),
		'subject'   => array( 'locale' => 'ko-KR' ),
	);
	$score_input = array(
		'operation'          => Submission_Score_Input_Builder::OPERATION,
		'operation_contract' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
		'operation_payload'  => $payload,
		'operation_schema'   => Submission_Score_Input_Builder::OPERATION_SCHEMA,
		'payload_hash'       => hash( 'sha256', $canonical->encode( $payload ) ),
	);
	return array(
		'evaluation_pk'            => 31,
		'evaluation_id'            => g4_feedback_review_id(),
		'evaluation_lock_version'  => 8,
		'evaluation_state'         => 'reviewed',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-feedback-review',
		'context_revision'         => 'class-g4-feedback-review-r1',
		'submission_pk'            => 32,
		'submission_id'            => g4_feedback_review_id(),
		'submission_lock_version'  => 4,
		'submission_revision_id'   => g4_feedback_review_id(),
		'submission_revision_hash' => str_repeat( 'c', 64 ),
		'official_review_pk'       => 33,
		'official_review_id'       => g4_feedback_review_id(),
		'official_review_hash'     => str_repeat( 'd', 64 ),
		'result_pk'                => 34,
		'result_id'                => g4_feedback_review_id(),
		'result_hash'              => str_repeat( 'e', 64 ),
		'score_input'              => $score_input,
		'score_decisions'          => array(
			array(
				'binding_key'     => $first_key,
				'maximum_points'  => '6.0000',
				'official_points' => '5.0000',
				'position'        => 0,
				'reason_code'     => null,
				'reason_note'     => null,
				'response_id'     => $response_id,
				'scope'           => 'response',
				'source'          => 'ai_accepted',
			),
			array(
				'binding_key'     => $second_key,
				'maximum_points'  => '4.0000',
				'official_points' => '3.0000',
				'position'        => 1,
				'reason_code'     => 'manual_assessment',
				'reason_note'     => 'Teacher-only reason',
				'response_id'     => null,
				'scope'           => 'submission',
				'source'          => 'teacher_assigned',
			),
		),
	);
}

/**
 * G4 feedback review payload.
 *
 * @return array<string,mixed>
 */
function g4_feedback_review_payload(): array {
	return array(
		'criterion_feedback' => array(
			array(
				'binding_key' => str_repeat( 'b', 64 ),
				'next_step'   => 'Add one transition.',
				'strength'    => 'The structure is easy to follow.',
			),
			array(
				'binding_key' => str_repeat( 'a', 64 ),
				'next_step'   => 'Explain why the evidence supports the conclusion.',
				'strength'    => 'The evidence is relevant.',
			),
		),
		'next_steps'         => array( 'Connect the evidence and conclusion.', 'Read the response aloud.' ),
		'overall_feedback'   => 'The response is clear and uses relevant evidence.',
	);
}

/**
 * G4 feedback review snapshot.
 *
 * @param array<string,mixed> $source Source.
 * @param string|null         $latest_id Latest id.
 * @param string|null         $latest_hash Latest hash.
 * @param int                 $latest_no Latest no.
 * @param string|null         $latest_state Latest state.
 * @param string|null         $latest_review_id Latest review id.
 * @param string|null         $source_run_id Source run id.
 * @return array<string,mixed>
 */
function g4_feedback_review_snapshot( array $source, ?string $latest_id = null, ?string $latest_hash = null, int $latest_no = 0, ?string $latest_state = null, ?string $latest_review_id = null, ?string $source_run_id = null ): array {
	return array(
		'evaluation_lock_version'       => $source['evaluation_lock_version'],
		'evaluation_state'              => 'reviewed',
		'context_type'                  => $source['context_type'],
		'context_id'                    => $source['context_id'],
		'context_revision'              => $source['context_revision'],
		'official_review_id'            => $source['official_review_id'],
		'official_review_hash'          => $source['official_review_hash'],
		'latest_feedback_revision_id'   => $latest_id,
		'latest_feedback_revision_hash' => $latest_hash,
		'latest_feedback_revision_no'   => $latest_no,
		'latest_feedback_state'         => $latest_state,
		'latest_feedback_review_id'     => $latest_review_id,
		'latest_feedback_source_run_id' => $source_run_id,
	);
}

/**
 * G4-IMP-04-S02 C36 teacher feedback review contract.
 */
final class G4_Feedback_Review_Source implements Submission_Feedback_Repository_Port_Interface {
	/**
	 * Initialize g4 feedback review source dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 */
	public function __construct( public array $source ) {
	}
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Generate_Submission_Feedback_Result|null
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Generate_Submission_Feedback_Result {
		unset( $command_storage_key, $request_digest );
		return null;
	}
	/**
	 * Find owned source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned_source( int $actor_user_id, string $evaluation_id ): ?array {
		unset( $actor_user_id );
		return $evaluation_id === $this->source['evaluation_id'] ? $this->source : null;
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Generate_Submission_Feedback_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Generate_Submission_Feedback_Result {
		unset( $record );
		throw new RuntimeException( 'C36 must not enqueue AI work.' );
	}
}

/**
 * G4-IMP-04-S02 C36 teacher feedback review contract.
 */
final class G4_Feedback_Review_Repository implements Submission_Feedback_Review_Repository_Port_Interface {
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
	 * Initialize g4 feedback review repository dependencies and state.
	 *
	 * @param array<string,mixed> $snapshot Snapshot.
	 */
	public function __construct( array $snapshot ) {
		$this->snapshot = $snapshot;
	}
	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Submission_Feedback_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Submission_Feedback_Review_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$stored = $this->receipts[ $key ]['result'];
		return new Submission_Feedback_Review_Result( $stored->evaluation_id(), $stored->official_review_id(), $stored->official_review_hash(), $stored->feedback_revision_id(), $stored->feedback_revision_hash(), $stored->feedback_revision_no(), $stored->state(), $stored->previous_revision_id(), $stored->source_evaluation_run_id(), $stored->occurred_at_utc(), true );
	}
	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		unset( $actor_user_id, $evaluation_id );
		return $this->snapshot;
	}
	/**
	 * Append revision.
	 *
	 * @param array $record Record.
	 * @return Submission_Feedback_Review_Result
	 */
	public function append_revision( array $record ): Submission_Feedback_Review_Result {
		$this->record = $record;
		++$this->append_count;
		$result = new Submission_Feedback_Review_Result( $record['evaluation_public_id'], $record['expected_official_review_id'], $record['expected_official_review_hash'], $record['revision_public_id'], $record['content_hash'], $record['revision_no'], $record['state'], $record['previous_feedback_revision_id'], $record['source_evaluation_run_public_id'], $record['occurred_at_utc'], false );
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G4 feedback review command.
 *
 * @param array<string,mixed>      $source Source.
 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
 * @param string                   $decision Decision.
 * @param string                   $source_mode Source mode.
 * @param string|null              $latest_id Latest id.
 * @param string|null              $latest_hash Latest hash.
 * @param int                      $latest_no Latest no.
 * @param array<string,mixed>|null $feedback Feedback.
 * @param string|null              $reason Reason.
 * @param string                   $command_id Command identifier used for idempotency.
 * @param string                   $fingerprint Fingerprint.
 * @return Review_Submission_Feedback_Command
 */
function g4_feedback_review_command( array $source, Canonical_Json $canonical, string $decision, string $source_mode, ?string $latest_id, ?string $latest_hash, int $latest_no, ?array $feedback, ?string $reason, string $command_id = 'command-g4-feedback-review', string $fingerprint = '' ): Review_Submission_Feedback_Command {
	return new Review_Submission_Feedback_Command( 42, $source['evaluation_id'], $command_id, $source['official_review_id'], $source['official_review_hash'], $latest_id, $latest_hash, $latest_no, $decision, $source_mode, $feedback, $reason, '' === $fingerprint ? str_repeat( 'f', 64 ) : $fingerprint, 'correlation-g4-feedback-review', $source['context_type'], $source['context_id'], $source['context_revision'], (string) $source['evaluation_lock_version'], 'reviewed', 'context_staff', $canonical );
}

$canonical      = new Canonical_Json();
$source         = g4_feedback_review_source( $canonical );
$empty_snapshot = g4_feedback_review_snapshot( $source );
$source_port    = new G4_Feedback_Review_Source( $source );
$repository     = new G4_Feedback_Review_Repository( $empty_snapshot );
$application    = new Review_Submission_Feedback( $source_port, $repository, new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 12:00:00' );
$direct         = g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'direct', null, null, 0, g4_feedback_review_payload(), null );
$created        = $application->execute( $direct );
g4_feedback_review_same( 'teacher_draft', $created->state(), 'Direct writing must append a teacher draft.' );
g4_feedback_review_same( 1, $created->feedback_revision_no(), 'First direct draft must be revision 1.' );
g4_feedback_review_same( null, $created->source_evaluation_run_id(), 'Direct writing must not claim AI provenance.' );
g4_feedback_review_same( Submission_Feedback_Input_Builder::RESULT_SCHEMA, $repository->record['feedback_schema'] ?? null, 'Teacher draft must use the student feedback result profile.' );
$teacher_profile = json_decode( (string) $repository->record['feedback_json'], true, 128, JSON_THROW_ON_ERROR );
g4_feedback_review_same( array(), $teacher_profile['safety_flags'] ?? null, 'Teacher-authored feedback must reset AI safety flags before separate approval.' );
g4_feedback_review_assert( ! array_key_exists( 'reason', $teacher_profile ), 'Teacher draft profile must not carry an internal rejection reason.' );
$replayed = $application->execute( $direct );
g4_feedback_review_same( true, $replayed->replayed(), 'Exact C36 replay must use the durable receipt.' );
g4_feedback_review_same( 1, $repository->append_count, 'Exact replay must not append a second revision.' );
g4_feedback_review_failure( static fn () => $application->execute( g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'direct', null, null, 0, g4_feedback_review_payload(), null, 'command-g4-feedback-review-changed' ) ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Same actor/object idempotency key with a changed request must conflict.' );

$ai_id            = g4_feedback_review_id();
$ai_hash          = str_repeat( '1', 64 );
$ai_run           = g4_feedback_review_id();
$edit_snapshot    = g4_feedback_review_snapshot( $source, $ai_id, $ai_hash, 1, 'ai_draft', $source['official_review_id'], $ai_run );
$edit_repository  = new G4_Feedback_Review_Repository( $edit_snapshot );
$edit_application = new Review_Submission_Feedback( $source_port, $edit_repository, new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 12:01:00' );
$edited           = $edit_application->execute( g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'edit', $ai_id, $ai_hash, 1, g4_feedback_review_payload(), null, 'command-g4-feedback-edit', str_repeat( '2', 64 ) ) );
g4_feedback_review_same( $ai_id, $edited->previous_revision_id(), 'Edited draft must point to the exact AI draft.' );
g4_feedback_review_same( $ai_run, $edited->source_evaluation_run_id(), 'AI edit must retain the source run provenance.' );

$reject_repository  = new G4_Feedback_Review_Repository( $edit_snapshot );
$reject_application = new Review_Submission_Feedback( $source_port, $reject_repository, new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 12:02:00' );
$rejected           = $reject_application->execute( g4_feedback_review_command( $source, $canonical, 'rejected', 'edit', $ai_id, $ai_hash, 1, null, 'Rewrite this feedback before showing it to the student.', 'command-g4-feedback-reject', str_repeat( '3', 64 ) ) );
g4_feedback_review_same( 'rejected', $rejected->state(), 'C36 rejection must append a rejected revision.' );
g4_feedback_review_same( Review_Submission_Feedback::REJECTION_SCHEMA, $reject_repository->record['feedback_schema'] ?? null, 'Rejected revision must use the rejection profile.' );
$rejection_profile = json_decode( (string) $reject_repository->record['feedback_json'], true, 128, JSON_THROW_ON_ERROR );
g4_feedback_review_same( $ai_id, $rejection_profile['source_feedback_ref'] ?? null, 'Rejection profile must pin the exact rejected draft.' );
g4_feedback_review_same( $ai_hash, $rejection_profile['source_feedback_hash'] ?? null, 'Rejection profile must pin the exact rejected draft hash.' );
g4_feedback_review_same( 'Rewrite this feedback before showing it to the student.', $rejection_profile['reason'] ?? null, 'Rejection reason must be preserved in the immutable revision.' );

g4_feedback_review_invalid( static fn () => g4_feedback_review_command( $source, $canonical, 'rejected', 'direct', $ai_id, $ai_hash, 1, null, 'Reject', 'command-g4-feedback-invalid', str_repeat( '4', 64 ) ), 'Rejected revision must require edit mode.' );
g4_feedback_review_invalid( static fn () => g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'edit', null, null, 0, g4_feedback_review_payload(), null, 'command-g4-feedback-no-source', str_repeat( '5', 64 ) ), 'Edit mode must require an exact feedback source.' );
$incomplete_feedback = g4_feedback_review_payload();
array_pop( $incomplete_feedback['criterion_feedback'] );
$incomplete_repository  = new G4_Feedback_Review_Repository( $empty_snapshot );
$incomplete_application = new Review_Submission_Feedback( $source_port, $incomplete_repository, new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical );
g4_feedback_review_failure( static fn () => $incomplete_application->execute( g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'direct', null, null, 0, $incomplete_feedback, null, 'command-g4-feedback-incomplete', str_repeat( '6', 64 ) ) ), Submission_Evaluation_Failure::FEEDBACK_SCHEMA_INVALID, 'Teacher draft must cover the exact official criterion binding set.' );

$stale_snapshot                         = $empty_snapshot;
$stale_snapshot['official_review_hash'] = str_repeat( '9', 64 );
$stale_application                      = new Review_Submission_Feedback( $source_port, new G4_Feedback_Review_Repository( $stale_snapshot ), new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical );
g4_feedback_review_failure( static fn () => $stale_application->execute( g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'direct', null, null, 0, g4_feedback_review_payload(), null, 'command-g4-feedback-stale', str_repeat( '7', 64 ) ) ), Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, 'Changed official review must reject C36 without an append.' );

$wrong_review_snapshot                              = $edit_snapshot;
$wrong_review_snapshot['latest_feedback_review_id'] = g4_feedback_review_id();
$wrong_review_application                           = new Review_Submission_Feedback( $source_port, new G4_Feedback_Review_Repository( $wrong_review_snapshot ), new Submission_Feedback_Input_Builder( $canonical ), new Submission_Feedback_Output_Normalizer( $canonical ), new Public_Id_Generator(), $canonical );
g4_feedback_review_failure( static fn () => $wrong_review_application->execute( g4_feedback_review_command( $source, $canonical, 'teacher_draft', 'edit', $ai_id, $ai_hash, 1, g4_feedback_review_payload(), null, 'command-g4-feedback-old-review', str_repeat( '8', 64 ) ) ), Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, 'Edit mode must reject a draft from a replaced official review.' );

$repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Feedback_Review_Repository.php' );
$rest_source       = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Feedback_Review_Rest_Controller.php' );
$root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
$projector_source  = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Domain/Submission_Evaluation_Query_Projector.php' );
g4_feedback_review_assert( str_contains( $repository_source, "'oj_ai_submission_feedback_revisions'" ) && ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'C36 storage must use only the literal feedback table.' );
g4_feedback_review_assert( str_contains( $repository_source, "'START TRANSACTION'" ) && str_contains( $repository_source, 'FOR UPDATE' ) && str_contains( $repository_source, "'COMMIT'" ), 'C36 append, audit, outbox, and receipt must share one locked transaction.' );
g4_feedback_review_assert( ! str_contains( $repository_source, "'UPDATE ' . self::TABLE_FEEDBACK" ) && ! str_contains( $repository_source, "'DELETE FROM ' . self::TABLE_FEEDBACK" ), 'C36 must never update or delete a feedback revision.' );
g4_feedback_review_assert( str_contains( $repository_source, "'previous_revision_id'" ) && str_contains( $repository_source, "'source_evaluation_run_public_id'" ), 'C36 must preserve immutable chain and AI provenance fields.' );
g4_feedback_review_assert( str_contains( $rest_source, 'ROUTE_REVIEW' ) && str_contains( $rest_source, '/commands/review-feedback' ) && str_contains( $rest_source, 'REVIEW_ASSESSMENTS' ) && str_contains( $rest_source, 'OPERATION_EVALUATE' ) && str_contains( $rest_source, "'review'" ), 'C36 REST must retain route, capability, Context, and object review authorization.' );
g4_feedback_review_assert( str_contains( $root_source, 'new Submission_Feedback_Review_Rest_Module' ) && str_contains( $root_source, 'new WordPress_Submission_Feedback_Review_Repository' ), 'Composition Root must activate C36 without replacing C35.' );
g4_feedback_review_assert( str_contains( $projector_source, "'hash'           => \$content_hash" ), 'Q17 feedback history must expose the exact hash required by C36 expected.' );
g4_feedback_review_assert( ! str_contains( $repository_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C36 repository must not use platform internals.' );

fwrite( STDOUT, "G4-IMP-04-S02 direct, edit, reject, stale, idempotency, REST, and immutable feedback contracts passed.\n" );
