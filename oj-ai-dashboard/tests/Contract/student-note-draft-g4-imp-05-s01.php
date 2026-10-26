<?php
/**
 * G4-IMP-05-S01 student special-note draft contract.
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
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Student_Note_Draft;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Student_Note_Draft_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Student_Note_Draft_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 note assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 note same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 note invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 note failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_note_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 note id.
 *
 * @return string
 */
function g4_note_id(): string {
	static $counter = 51000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_note_assert( Public_Id::is_valid( $id ), 'Student-note fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 note source.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param int            $criterion_count Criterion count.
 * @return array<string,mixed>
 */
function g4_note_source( Canonical_Json $canonical, int $criterion_count = 22 ): array {
	$response_id = g4_note_id();
	$bindings    = array();
	$decisions   = array();
	for ( $index = 0; $index < $criterion_count; ++$index ) {
		$key         = hash( 'sha256', 'g4-note-binding-' . $index );
		$bindings[]  = array(
			'binding_key'    => $key,
			'criterion_id'   => g4_note_id(),
			'label'          => 'Criterion ' . str_pad( (string) $index, 2, '0', STR_PAD_LEFT ),
			'maximum_points' => '4',
			'position'       => $index,
			'response_id'    => 0 === $index ? $response_id : null,
			'scope'          => 0 === $index ? 'response' : 'submission',
			'correct_answer' => 'teacher-only answer',
			'class_name'     => 'private class label',
		);
		$decisions[] = array(
			'binding_key'     => $key,
			'maximum_points'  => '4.0000',
			'official_points' => 0 === $index ? '4.0000' : '3.0000',
			'position'        => $index,
			'reason_code'     => 'private_reason',
			'reason_note'     => 'teacher-only rationale',
			'response_id'     => 0 === $index ? $response_id : null,
			'scope'           => 0 === $index ? 'response' : 'submission',
			'source'          => 'teacher_assigned',
		);
	}
	$payload     = array(
		'bindings'  => $bindings,
		'responses' => array(
			array(
				'answer'       => array( 'text' => '<b>Observation</b> sent to learner@example.test from https://private.test or 010-1234-5678.' ),
				'kind'         => 'long_text',
				'label'        => 'Written response',
				'position'     => 0,
				'response_id'  => $response_id,
				'student_name' => 'Private learner name',
			),
		),
		'subject'   => array(
			'locale'       => 'ko-KR',
			'provider_key' => 'must-not-pass',
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
		'evaluation_pk'            => 51,
		'evaluation_id'            => g4_note_id(),
		'evaluation_lock_version'  => 5,
		'evaluation_state'         => 'reviewed',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-note',
		'context_revision'         => 'class-g4-note-r1',
		'submission_pk'            => 52,
		'submission_id'            => g4_note_id(),
		'submission_lock_version'  => 7,
		'submission_revision_id'   => g4_note_id(),
		'submission_revision_hash' => str_repeat( 'c', 64 ),
		'official_review_pk'       => 53,
		'official_review_id'       => g4_note_id(),
		'official_review_hash'     => str_repeat( 'd', 64 ),
		'result_pk'                => 54,
		'result_id'                => g4_note_id(),
		'result_hash'              => str_repeat( 'e', 64 ),
		'score_input'              => $score_input,
		'score_decisions'          => $decisions,
	);
}

/**
 * G4 note output.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>
 */
function g4_note_output( array $input ): array {
	$payload = $input['operation_payload'];
	$first   = $payload['evidence'][0];
	$second  = $payload['evidence'][1];
	return array(
		'evidence'            => array(
			array(
				'evidence_key' => $second['evidence_key'],
				'observation'  => $second['known_observation'],
			),
			array(
				'evidence_key' => $first['evidence_key'],
				'observation'  => $first['known_observation'],
			),
		),
		'note_ref'            => $payload['subject']['note_ref'],
		'safety_flags'        => array(),
		'schema_id'           => Student_Note_Input_Builder::OUTPUT_SCHEMA,
		'schema_version'      => Student_Note_Input_Builder::OUTPUT_VERSION,
		'source_hash'         => $payload['subject']['source_hash'],
		'strengths'           => array(
			array(
				'evidence_keys' => array( $first['evidence_key'] ),
				'text'          => 'The response links an observation to its explanation.',
			),
		),
		'summary'             => array(
			'evidence_keys' => array( $first['evidence_key'], $second['evidence_key'] ),
			'text'          => 'The official evidence shows a clear explanation supported by an observation.',
		),
		'support_suggestions' => array(
			array(
				'evidence_keys' => array( $second['evidence_key'] ),
				'text'          => 'Ask for one more sentence connecting the observation and conclusion.',
			),
		),
	);
}

/**
 * G4-IMP-05-S01 student special-note draft contract.
 */
final class G4_Note_Repository implements Student_Note_Repository_Port_Interface {
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
	 * @var array<string,array{digest:string,result:Generate_Student_Note_Draft_Result}>
	 */
	private array $receipts = array();
	/**
	 * Initialize g4 note repository dependencies and state.
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
	 * @return Generate_Student_Note_Draft_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Generate_Student_Note_Draft_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		if ( ! hash_equals( $this->receipts[ $key ]['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$stored = $this->receipts[ $key ]['result'];
		return new Generate_Student_Note_Draft_Result( $stored->evaluation_id(), $stored->official_review_id(), $stored->official_review_hash(), $stored->run_id(), $stored->run_no(), $stored->run_state(), $stored->input_digest(), $stored->queued_at_utc(), true );
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
	 * @return Generate_Student_Note_Draft_Result
	 */
	public function enqueue( array $record ): Generate_Student_Note_Draft_Result {
		$this->record = $record;
		$result       = new Generate_Student_Note_Draft_Result( $record['evaluation_public_id'], $record['official_review_id'], $record['official_review_hash'], $record['run_public_id'], 2, 'queued', $record['input_digest'], $record['queued_at_utc'], false );
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}
}

/**
 * G4-IMP-05-S01 student special-note draft contract.
 */
final class G4_Note_Routing implements Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Initialize g4 note routing dependencies and state.
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
 * G4-IMP-05-S01 student special-note draft contract.
 */
final class G4_Note_Queue implements Submission_Evaluation_Queue_Wakeup_Port_Interface {
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
 * G4 note command.
 *
 * @param array          $source Source.
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param string         $command_id Command identifier used for idempotency.
 * @return Generate_Student_Note_Draft_Command
 */
function g4_note_command( array $source, Canonical_Json $canonical, string $command_id = 'command-g4-note' ): Generate_Student_Note_Draft_Command {
	return new Generate_Student_Note_Draft_Command(
		42,
		$source['evaluation_id'],
		$command_id,
		$source['official_review_id'],
		$source['official_review_hash'],
		str_repeat( 'f', 64 ),
		'correlation-g4-note',
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
$source        = g4_note_source( $canonical );
$builder       = new Student_Note_Input_Builder( $canonical );
$built         = $builder->build( $source );
$built_again   = $builder->build( $source );
$gateway_input = $built->gateway_input();
$gateway_json  = $built->gateway_input_json();

g4_note_same( 'special_note_draft', $gateway_input['operation'], 'Student-note operation drifted.' );
g4_note_same( 'student_special_note.v1', $gateway_input['operation_contract'], 'Student-note inner contract drifted.' );
g4_note_same( $built->gateway_input_hash(), $built_again->gateway_input_hash(), 'Student-note input must be deterministic.' );
g4_note_same( 20, count( $gateway_input['operation_payload']['evidence'] ), 'Student-note input must cap evidence at twenty items.' );
g4_note_assert( $built->byte_size() <= Student_Note_Input_Builder::MAXIMUM_INPUT_BYTES, 'Student-note input exceeded 32 KiB.' );
g4_note_same( false, $gateway_input['operation_payload']['policy']['include_in_reports'], 'AI draft must never request report inclusion.' );
foreach ( array( 'correct_answer', 'class_name', 'student_name', 'reason_code', 'reason_note', 'provider_key', 'official_points', 'maximum_points', '"score":', '"rank":', 'learner@example.test', 'https://private.test', '010-1234-5678', '<b>' ) as $forbidden ) {
	g4_note_assert( ! str_contains( $gateway_json, $forbidden ), 'Student-note input leaked forbidden data: ' . $forbidden );
}
g4_note_assert( str_contains( $gateway_json, '[email removed]' ) && str_contains( $gateway_json, '[link removed]' ) && str_contains( $gateway_json, '[phone removed]' ), 'Student response excerpts must redact common direct identifiers.' );
g4_note_same( Student_Note_Input_Builder::PROMPT_SECTION, $gateway_input['operation_payload']['instruction']['section_contract'], 'Frozen student-note prompt section drifted.' );

$normalizer = new Student_Note_Output_Normalizer( $canonical );
$output     = g4_note_output( $gateway_input );
$normalized = $normalizer->normalize( $output, $gateway_input );
g4_note_same( Student_Note_Input_Builder::RESULT_SCHEMA, $normalized->profile()['schema_id'], 'Stored student-note schema drifted.' );
g4_note_same( false, $normalized->safety_flagged(), 'Accepted student note cannot retain a safety flag.' );
g4_note_same( array( $gateway_input['operation_payload']['evidence'][0]['evidence_key'], $gateway_input['operation_payload']['evidence'][1]['evidence_key'] ), array_column( $normalized->profile()['evidence'], 'evidence_key' ), 'Student-note evidence must normalize by key.' );
g4_note_assert( strlen( $normalized->profile_json() ) <= Student_Note_Output_Normalizer::MAXIMUM_OUTPUT_BYTES, 'Normalized student note exceeded 16 KiB.' );

$invalid                               = $output;
$invalid['evidence'][0]['observation'] = 'A newly invented observation.';
g4_note_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Invented evidence observations must fail closed.' );
$invalid                             = $output;
$invalid['summary']['evidence_keys'] = array( str_repeat( '0', 64 ) );
g4_note_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Unknown evidence references must fail closed.' );
$invalid                 = $output;
$invalid['safety_flags'] = array( 'personality_inference' );
g4_note_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Any safety flag must reject the whole student note.' );
$invalid                    = $output;
$invalid['summary']['text'] = 'The learner has a gifted personality and higher ability than peers.';
g4_note_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Ability, personality, and peer comparison claims must fail closed.' );
$invalid                                   = $output;
$invalid['support_suggestions'][0]['text'] = 'See https://private.test for details.';
g4_note_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'URLs must fail closed.' );
$invalid                   = $output;
$invalid['official_score'] = '4.0000';
g4_note_invalid( static fn () => $normalizer->normalize( $invalid, $gateway_input ), 'Unknown score-bearing output keys must fail closed.' );

$route       = new Submission_Evaluation_Routing_Snapshot( Addon_Manifest::TASK_STUDENT_ASSESSMENT, 'openai', 'gpt-5-mini', str_repeat( '9', 64 ), 'routing-g4-note-v1' );
$repository  = new G4_Note_Repository( $source );
$queue       = new G4_Note_Queue();
$application = new Generate_Student_Note_Draft( $repository, new G4_Note_Routing( $route ), $queue, $builder, new Public_Id_Generator(), $canonical, static fn (): string => '2026-08-24 10:00:00' );
$command     = g4_note_command( $source, $canonical );
$queued      = $application->execute( $command );
g4_note_same( 'queued', $queued->run_state(), 'Student-note command must enqueue a run.' );
g4_note_same( $built->gateway_input_hash(), $repository->record['input_digest'] ?? null, 'Enqueued run must bind the exact rebuilt student-note input.' );
g4_note_same( 1, $queue->calls, 'Initial student-note enqueue must wake the worker once.' );
$replayed = $application->execute( $command );
g4_note_same( true, $replayed->replayed(), 'Exact student-note command replay must use the durable receipt.' );
g4_note_same( $queued->run_id(), $replayed->run_id(), 'Exact replay must keep the original run.' );
g4_note_same( 2, $queue->calls, 'Receipt replay must wake a potentially pending student-note run.' );
g4_note_failure( static fn () => $application->execute( g4_note_command( $source, $canonical, 'command-g4-note-conflict' ) ), Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, 'Changed command under the same idempotency fingerprint must conflict.' );

$stale_source                         = $source;
$stale_source['official_review_hash'] = str_repeat( '1', 64 );
$stale_application                    = new Generate_Student_Note_Draft( new G4_Note_Repository( $stale_source ), new G4_Note_Routing( $route ), new G4_Note_Queue(), $builder, new Public_Id_Generator(), $canonical );
g4_note_failure( static fn () => $stale_application->execute( $command ), Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, 'Changed official review must reject student-note enqueue.' );

$repository_source          = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Student_Note_Repository.php' );
$rest_source                = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Student_Note_Rest_Controller.php' );
$root_source                = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
$deactivation_source        = (string) file_get_contents( $addon_root . '/src/Core/Deactivation.php' );
$score_worker_source        = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Work_Repository.php' );
$feedback_repository_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Feedback_Repository.php' );

g4_note_assert( str_contains( $repository_source, "'oj_ai_student_note_revisions'" ), 'Student-note repository must use the literal table.' );
g4_note_assert( ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'Student-note storage must never use WordPress-prefixed tables.' );
g4_note_assert( str_contains( $repository_source, "'student_note_public_id'" ) && Ojai_Php_Source_Contract::contains( $repository_source, "'include_in_reports' => 0" ) && Ojai_Php_Source_Contract::contains( $repository_source, "'approved_by_user_id' => null" ), 'AI draft insert must set root, default exclusion, and no approval.' );
g4_note_assert( Ojai_Php_Source_Contract::contains( $repository_source, "'state' => 'ai_draft'" ) && str_contains( $repository_source, "'source_evaluation_run_public_id'" ) && str_contains( $repository_source, "'previous_revision_id'" ), 'Student-note success must append an immutable AI draft with provenance.' );
foreach ( array( 'TABLE_NOTES', 'TABLE_REVIEWS', 'TABLE_RESULTS', 'TABLE_EVALUATIONS' ) as $table ) {
	g4_note_assert( ! str_contains( $repository_source, "'UPDATE ' . self::" . $table ) && ! str_contains( $repository_source, "'DELETE FROM ' . self::" . $table ), 'Student-note worker must not mutate immutable or score source table: ' . $table );
}
g4_note_assert( str_contains( $rest_source, 'MANAGE_STUDENT_NOTES' ) && str_contains( $rest_source, 'OPERATION_REPORT' ) && str_contains( $rest_source, "'report'" ), 'C38 REST must require student-note capability, report Context, and evaluation object.' );
g4_note_assert( str_contains( $root_source, 'new Student_Note_Rest_Module' ) && str_contains( $root_source, '$student_note_worker' ), 'Composition Root must activate C38 REST and worker modules.' );
g4_note_assert( str_contains( $deactivation_source, 'Student_Note_Worker_Module::clear()' ), 'Deactivation must clear the student-note sweep.' );
g4_note_assert( str_contains( $score_worker_source, 'Submission_Score_Input_Builder::OPERATION' ) && str_contains( $feedback_repository_source, 'Submission_Feedback_Input_Builder::OPERATION' ), 'Score and feedback workers must retain operation-isolated claims.' );
g4_note_assert( ! str_contains( $repository_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'Student-note repository must not use platform internals.' );

fwrite( STDOUT, "G4-IMP-05-S01 student-note input, output, enqueue, authorization, privacy, and immutable storage contracts passed.\n" );
