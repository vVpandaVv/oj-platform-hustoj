<?php
/**
 * G4-IMP-04-S01 student submission feedback draft contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/Support/Php_Source_Contract.php';

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . '/src/Core/Autoloader.php';

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 feedback assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 feedback same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 feedback invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_feedback_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 feedback id.
 *
 * @return string
 */
function g4_feedback_id(): string {
	static $counter = 44000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_feedback_assert( Public_Id::is_valid( $id ), 'Feedback fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 feedback source.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g4_feedback_source( Canonical_Json $canonical ): array {
	$response_id = g4_feedback_id();
	$first_key   = str_repeat( 'a', 64 );
	$second_key  = str_repeat( 'b', 64 );
	$payload     = array(
		'bindings'  => array(
			array(
				'binding_key'    => $first_key,
				'criterion_id'   => g4_feedback_id(),
				'label'          => 'Clarity of explanation',
				'maximum_points' => '6',
				'position'       => 0,
				'response_id'    => $response_id,
				'scope'          => 'response',
				'correct_answer' => 'Must never enter student feedback input',
				'example_answer' => 'Teacher-only example answer',
			),
			array(
				'binding_key'    => $second_key,
				'criterion_id'   => g4_feedback_id(),
				'label'          => 'Overall structure',
				'maximum_points' => '4',
				'position'       => 1,
				'response_id'    => null,
				'scope'          => 'submission',
			),
		),
		'responses' => array(
			array(
				'answer'       => array( 'text' => 'The response explains the observation with evidence.' ),
				'kind'         => 'long_text',
				'label'        => 'Written response',
				'position'     => 0,
				'response_id'  => $response_id,
				'student_name' => 'Must be removed from stored input',
				'email'        => 'removed@example.test',
			),
		),
		'subject'   => array(
			'locale'       => 'ko-KR',
			'provider_key' => 'must-not-pass',
			'model_key'    => 'must-not-pass',
		),
	);
	$score_input = array(
		'operation'          => Submission_Score_Input_Builder::OPERATION,
		'operation_contract' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
		'operation_payload'  => $payload,
		'operation_schema'   => Submission_Score_Input_Builder::OPERATION_SCHEMA,
		'payload_hash'       => hash( 'sha256', $canonical->encode( $payload ) ),
	);
	return array(
		'evaluation_pk'            => 11,
		'evaluation_id'            => g4_feedback_id(),
		'evaluation_lock_version'  => 5,
		'evaluation_state'         => 'reviewed',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-feedback',
		'context_revision'         => 'class-g4-feedback-r1',
		'submission_pk'            => 12,
		'submission_id'            => g4_feedback_id(),
		'submission_lock_version'  => 7,
		'submission_revision_id'   => g4_feedback_id(),
		'submission_revision_hash' => str_repeat( 'c', 64 ),
		'official_review_pk'       => 13,
		'official_review_id'       => g4_feedback_id(),
		'official_review_hash'     => str_repeat( 'd', 64 ),
		'result_pk'                => 14,
		'result_id'                => g4_feedback_id(),
		'result_hash'              => str_repeat( 'e', 64 ),
		'score_input'              => $score_input,
		'score_decisions'          => array(
			array(
				'binding_key'     => $first_key,
				'maximum_points'  => '6.0000',
				'official_points' => '5.0000',
				'position'        => 0,
				'reason_code'     => 'manual_assessment',
				'reason_note'     => 'Teacher-only assessment rationale',
				'response_id'     => $response_id,
				'scope'           => 'response',
				'source'          => 'teacher_assigned',
			),
			array(
				'binding_key'     => $second_key,
				'maximum_points'  => '4.0000',
				'official_points' => '3.0000',
				'position'        => 1,
				'reason_code'     => null,
				'reason_note'     => null,
				'response_id'     => null,
				'scope'           => 'submission',
				'source'          => 'ai_accepted',
			),
		),
	);
}

/**
 * G4 feedback output.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>
 */
function g4_feedback_output( array $input ): array {
	$payload = $input['operation_payload'];
	$keys    = array_column( $payload['criteria'], 'binding_key' );
	return array(
		'criterion_feedback' => array(
			array(
				'binding_key' => $keys[1],
				'next_step'   => 'Add one transition between the paragraphs.',
				'strength'    => 'The overall flow is clear.',
			),
			array(
				'binding_key' => $keys[0],
				'next_step'   => 'Explain how the evidence supports the conclusion.',
				'strength'    => 'The evidence matches the observation.',
			),
		),
		'feedback_ref'       => $payload['subject']['feedback_ref'],
		'next_steps'         => array( 'Connect the evidence and conclusion in one sentence.', 'Read the completed response aloud.' ),
		'overall_feedback'   => 'The evidence is clear and the response has a logical flow.',
		'safety_flags'       => array(),
		'schema_id'          => Submission_Feedback_Input_Builder::OUTPUT_SCHEMA,
		'schema_version'     => Submission_Feedback_Input_Builder::OUTPUT_VERSION,
		'source_hash'        => $payload['subject']['source_hash'],
	);
}

/**
 * G4-IMP-04-S01 student submission feedback draft contract.
 */
final class G4_Feedback_Repository implements Submission_Feedback_Repository_Port_Interface {
	/**
	 * Source.
	 *
	 * @var array<string,mixed>
	 */
	public array $source;
	/**
	 * Record.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $record = null;
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Generate_Submission_Feedback_Result}>
	 */
	private array $receipts = array();
	/**
	 * Initialize g4 feedback repository dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 */
	public function __construct( array $source ) {
		$this->source = $source;
	}
	/**
	 * Find command receipt.
	 *
	 * @param string $key Key.
	 * @param string $digest Digest.
	 * @return Generate_Submission_Feedback_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Generate_Submission_Feedback_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$stored = $this->receipts[ $key ]['result'];
		return new Generate_Submission_Feedback_Result( $stored->evaluation_id(), $stored->official_review_id(), $stored->official_review_hash(), $stored->run_id(), $stored->run_no(), $stored->run_state(), $stored->input_digest(), $stored->queued_at_utc(), true );
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
	 */
	public function enqueue( array $record ): Generate_Submission_Feedback_Result {
		$this->record = $record;
		$result       = new Generate_Submission_Feedback_Result( $record['evaluation_public_id'], $record['official_review_id'], $record['official_review_hash'], $record['run_public_id'], 2, 'queued', $record['input_digest'], $record['queued_at_utc'], false );
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G4-IMP-04-S01 student submission feedback draft contract.
 */
final class G4_Feedback_Routing implements Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Initialize g4 feedback routing dependencies and state.
	 *
	 * @param Submission_Evaluation_Routing_Snapshot $route Route.
	 */
	public function __construct( private Submission_Evaluation_Routing_Snapshot $route ) {
	}
	/**
	 * Plan.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 */
	public function plan(): Submission_Evaluation_Routing_Snapshot {
		return $this->route;
	}
}

/**
 * G4-IMP-04-S01 student submission feedback draft contract.
 */
final class G4_Feedback_Queue implements Submission_Evaluation_Queue_Wakeup_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void {
		++$this->calls;
	}
}

/**
 * G4 feedback command.
 *
 * @param array          $source Source.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $command_id Command identifier used for idempotency.
 * @return Generate_Submission_Feedback_Command
 */
function g4_feedback_command( array $source, Canonical_Json $canonical, string $command_id = 'command-g4-feedback' ): Generate_Submission_Feedback_Command {
	return new Generate_Submission_Feedback_Command(
		42,
		$source['evaluation_id'],
		$command_id,
		$source['official_review_id'],
		$source['official_review_hash'],
		str_repeat( 'f', 64 ),
		'correlation-g4-feedback',
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		(string) $source['evaluation_lock_version'],
		$source['evaluation_state'],
		'context_staff',
		$canonical
	);
}

$canonical     = new Canonical_Json();
$source        = g4_feedback_source( $canonical );
$builder       = new Submission_Feedback_Input_Builder( $canonical );
$built         = $builder->build( $source );
$built_again   = $builder->build( $source );
$gateway_input = $built->gateway_input();
$gateway_json  = $built->gateway_input_json();
g4_feedback_same( Submission_Feedback_Input_Builder::OPERATION, $gateway_input['operation'], 'Feedback operation drifted.' );
g4_feedback_same( $built->gateway_input_hash(), $built_again->gateway_input_hash(), 'Feedback input must be deterministic.' );
g4_feedback_same( 2, count( $gateway_input['operation_payload']['criteria'] ), 'Every official score binding must become one feedback criterion.' );
foreach ( array( 'correct_answer', 'example_answer', 'student_name', 'email', 'reason_code', 'reason_note', 'provider_key', 'model_key', 'rubric' ) as $forbidden ) {
	g4_feedback_assert( ! str_contains( $gateway_json, $forbidden ), 'Feedback input leaked forbidden field: ' . $forbidden );
}
g4_feedback_assert( str_contains( $gateway_json, 'The response explains the observation with evidence.' ), 'Safe student response excerpt must be available for feedback.' );
g4_feedback_same( Submission_Feedback_Input_Builder::PROMPT_CONTENT_HASH, $gateway_input['operation_payload']['instruction']['content_hash'], 'Frozen feedback prompt hash drifted.' );

$normalizer = new Submission_Feedback_Output_Normalizer( $canonical );
$output     = g4_feedback_output( $gateway_input );
$normalized = $normalizer->normalize( $output, $gateway_input );
g4_feedback_same( Submission_Feedback_Input_Builder::RESULT_SCHEMA, $normalized->profile()['schema_id'], 'Stored feedback schema drifted.' );
g4_feedback_same( array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ), array_column( $normalized->profile()['criterion_feedback'], 'binding_key' ), 'Feedback items must normalize by binding key.' );
g4_feedback_same( false, $normalized->safety_flagged(), 'Safe feedback must not be flagged.' );
g4_feedback_assert( ! str_contains( $normalized->profile_json(), 'candidate_score' ) && ! str_contains( $normalized->profile_json(), 'official_score' ), 'Feedback result must not carry scoring fields.' );

$invalid          = $output;
$invalid['score'] = '8.0000';
g4_feedback_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Unknown or score-bearing output keys must fail closed.' );
$invalid                          = $output;
$invalid['criterion_feedback'][1] = $invalid['criterion_feedback'][0];
g4_feedback_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Duplicate feedback bindings must fail closed.' );
$invalid = $output;
array_pop( $invalid['criterion_feedback'] );
g4_feedback_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Missing feedback bindings must fail closed.' );
$invalid                                       = $output;
$invalid['criterion_feedback'][0]['strength']  = str_repeat( 'x', 500 );
$invalid['criterion_feedback'][0]['next_step'] = str_repeat( 'y', 101 );
g4_feedback_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Combined criterion feedback limit must fail closed.' );
$invalid                 = $output;
$invalid['safety_flags'] = array( 'free_form_flag' );
g4_feedback_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Unknown safety flags must fail closed.' );

$route       = new Submission_Evaluation_Routing_Snapshot( Addon_Manifest::TASK_STUDENT_ASSESSMENT, 'openai', 'gpt-5-mini', str_repeat( '9', 64 ), 'routing-g4-feedback-v1' );
$repository  = new G4_Feedback_Repository( $source );
$queue       = new G4_Feedback_Queue();
$application = new Generate_Submission_Feedback( $repository, new G4_Feedback_Routing( $route ), $queue, $builder, new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 10:00:00' );
$command     = g4_feedback_command( $source, $canonical );
$queued      = $application->execute( $command );
g4_feedback_same( 'queued', $queued->run_state(), 'Feedback command must enqueue a run.' );
g4_feedback_same( $built->gateway_input_hash(), $repository->record['input_digest'] ?? null, 'Enqueued run must bind the exact rebuilt feedback input.' );
g4_feedback_same( 1, $queue->calls, 'Initial enqueue must wake the worker once.' );
$replayed = $application->execute( $command );
g4_feedback_same( true, $replayed->replayed(), 'Exact command replay must use the durable receipt.' );
g4_feedback_same( $queued->run_id(), $replayed->run_id(), 'Exact replay must keep the original run.' );
g4_feedback_same( 2, $queue->calls, 'Receipt replay must still wake a potentially pending run.' );
g4_feedback_failure( static fn () => $application->execute( g4_feedback_command( $source, $canonical, 'command-g4-feedback-conflict' ) ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Same idempotency fingerprint with a changed request must conflict.' );

$stale_source                         = $source;
$stale_source['official_review_hash'] = str_repeat( '1', 64 );
$stale_repository                     = new G4_Feedback_Repository( $stale_source );
$stale_application                    = new Generate_Submission_Feedback( $stale_repository, new G4_Feedback_Routing( $route ), new G4_Feedback_Queue(), $builder, new Public_Id_Generator(), $canonical );
g4_feedback_failure( static fn () => $stale_application->execute( $command ), Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, 'Changed official review must reject feedback enqueue.' );

$repository_source   = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Feedback_Repository.php' );
$rest_source         = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Feedback_Rest_Controller.php' );
$root_source         = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
$deactivation_source = (string) file_get_contents( $addon_root . '/src/Core/Deactivation.php' );
$score_worker_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Work_Repository.php' );
g4_feedback_assert( str_contains( $repository_source, "'oj_ai_submission_feedback_revisions'" ), 'Feedback repository must use the literal feedback table.' );
g4_feedback_assert( ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'Feedback storage must never use WordPress-prefixed tables.' );
g4_feedback_assert( str_contains( $repository_source, "'START TRANSACTION'" ) && str_contains( $repository_source, 'FOR UPDATE' ), 'Feedback enqueue and append must be transactional and locked.' );
g4_feedback_assert( Ojai_Php_Source_Contract::contains( $repository_source, "'state' => 'ai_draft'" ) && str_contains( $repository_source, "'source_evaluation_run_public_id'" ) && str_contains( $repository_source, "'previous_revision_id'" ), 'Feedback success must append an immutable AI draft with source and chain provenance.' );
foreach ( array( 'TABLE_FEEDBACK', 'TABLE_REVIEWS', 'TABLE_RESULTS', 'TABLE_EVALUATIONS' ) as $table ) {
	g4_feedback_assert( ! str_contains( $repository_source, "'UPDATE ' . self::" . $table ) && ! str_contains( $repository_source, "'DELETE FROM ' . self::" . $table ), 'Feedback worker must not mutate immutable or score root table: ' . $table );
}
g4_feedback_assert( ! str_contains( $repository_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'Feedback repository must not use platform internals.' );
g4_feedback_assert( str_contains( $rest_source, 'REVIEW_ASSESSMENTS' ) && str_contains( $rest_source, 'OPERATION_EVALUATE' ) && str_contains( $rest_source, "'review'" ), 'Feedback REST must retain capability, context, and evaluation-object authorization.' );
g4_feedback_assert( str_contains( $root_source, 'new Submission_Feedback_Rest_Module' ) && str_contains( $root_source, '$submission_feedback_worker' ), 'Composition Root must activate feedback REST and worker modules.' );
g4_feedback_assert( str_contains( $deactivation_source, 'Submission_Feedback_Worker_Module::clear()' ), 'Deactivation must clear the feedback worker sweep.' );
g4_feedback_assert( str_contains( $score_worker_source, 'r.operation_key = %s' ) && str_contains( $score_worker_source, 'Submission_Score_Input_Builder::OPERATION' ), 'Score worker must claim score runs only.' );

fwrite( STDOUT, "G4-IMP-04-S01 feedback input, output, enqueue, source, authorization, and immutable storage contracts passed.\n" );
