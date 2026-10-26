<?php
/**
 * G4-IMP-05-S01 student special-note worker contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );
require_once $addon_root . '/src/Core/Autoloader.php';

use OJAI\Dashboard\Core\Autoloader as Addon_Autoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Student_Note_Draft_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Student_Note_Draft;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Student_Note_Draft_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Execution_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 note worker assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_worker_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 note worker same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_note_worker_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 note worker id.
 *
 * @return string
 */
function g4_note_worker_id(): string {
	static $counter = 52000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_note_worker_assert( Public_Id::is_valid( $id ), 'Worker fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 note worker ids.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function g4_note_worker_ids( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * G4 note worker source.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g4_note_worker_source( Canonical_Json $canonical ): array {
	$response_id = g4_note_worker_id();
	$binding_key = str_repeat( 'a', 64 );
	$payload     = array(
		'bindings'  => array(
			array(
				'binding_key'    => $binding_key,
				'criterion_id'   => g4_note_worker_id(),
				'label'          => 'Use of evidence',
				'maximum_points' => '10',
				'position'       => 0,
				'response_id'    => $response_id,
				'scope'          => 'response',
			),
		),
		'responses' => array(
			array(
				'answer'      => array( 'text' => 'The response cites two observations.' ),
				'kind'        => 'long_text',
				'label'       => 'Response',
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
		'evaluation_pk'            => 61,
		'evaluation_id'            => g4_note_worker_id(),
		'evaluation_lock_version'  => 5,
		'evaluation_state'         => 'reviewed',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-note-worker',
		'context_revision'         => 'class-g4-note-worker-r1',
		'submission_pk'            => 62,
		'submission_id'            => g4_note_worker_id(),
		'submission_lock_version'  => 7,
		'submission_revision_id'   => g4_note_worker_id(),
		'submission_revision_hash' => str_repeat( 'b', 64 ),
		'official_review_pk'       => 63,
		'official_review_id'       => g4_note_worker_id(),
		'official_review_hash'     => str_repeat( 'c', 64 ),
		'result_pk'                => 64,
		'result_id'                => g4_note_worker_id(),
		'result_hash'              => str_repeat( 'd', 64 ),
		'score_input'              => $score_input,
		'score_decisions'          => array(
			array(
				'binding_key'     => $binding_key,
				'maximum_points'  => '10.0000',
				'official_points' => '8.0000',
				'position'        => 0,
				'reason_code'     => null,
				'reason_note'     => null,
				'response_id'     => $response_id,
				'scope'           => 'response',
				'source'          => 'ai_accepted',
			),
		),
	);
}

/**
 * G4 note worker route.
 *
 * @param string $revision Revision.
 * @return Submission_Evaluation_Routing_Snapshot
 */
function g4_note_worker_route( string $revision = 'routing-g4-note-worker-v1' ): Submission_Evaluation_Routing_Snapshot {
	return new Submission_Evaluation_Routing_Snapshot( Addon_Manifest::TASK_STUDENT_ASSESSMENT, 'openai', 'gpt-5-mini', str_repeat( '8', 64 ), $revision );
}

/**
 * G4 note worker output.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>
 */
function g4_note_worker_output( array $input ): array {
	$payload  = $input['operation_payload'];
	$evidence = $payload['evidence'][0];
	return array(
		'evidence'            => array(
			array(
				'evidence_key' => $evidence['evidence_key'],
				'observation'  => $evidence['known_observation'],
			),
		),
		'note_ref'            => $payload['subject']['note_ref'],
		'safety_flags'        => array(),
		'schema_id'           => Student_Note_Input_Builder::OUTPUT_SCHEMA,
		'schema_version'      => Student_Note_Input_Builder::OUTPUT_VERSION,
		'source_hash'         => $payload['subject']['source_hash'],
		'strengths'           => array(
			array(
				'evidence_keys' => array( $evidence['evidence_key'] ),
				'text'          => 'The response uses a relevant observation.',
			),
		),
		'summary'             => array(
			'evidence_keys' => array( $evidence['evidence_key'] ),
			'text'          => 'The official evidence shows a clear connection between observation and explanation.',
		),
		'support_suggestions' => array(
			array(
				'evidence_keys' => array( $evidence['evidence_key'] ),
				'text'          => 'Ask for one sentence connecting the observation and conclusion.',
			),
		),
	);
}

/**
 * G4 note worker item.
 *
 * @param array                                  $source Source.
 * @param Student_Note_Input_Builder             $builder Builder.
 * @param Submission_Evaluation_Routing_Snapshot $route Route.
 * @return Student_Note_Work_Item
 */
function g4_note_worker_item( array $source, Student_Note_Input_Builder $builder, Submission_Evaluation_Routing_Snapshot $route ): Student_Note_Work_Item {
	$input = $builder->build( $source );
	return new Student_Note_Work_Item(
		$source['evaluation_pk'],
		71,
		$source['evaluation_id'],
		g4_note_worker_id(),
		42,
		$source['submission_id'],
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		$source['evaluation_lock_version'],
		$source['evaluation_state'],
		Student_Note_Input_Builder::OUTER_INPUT_CONTRACT,
		$input->gateway_input_hash(),
		'run:' . str_repeat( '7', 64 ),
		$route,
		'123e4567-e89b-42d3-a456-426614174000',
		1,
		0,
		0,
		'leased',
		'2026-08-24 09:00:00'
	);
}

/**
 * G4-IMP-05-S01 student special-note worker contract.
 */
final class G4_Note_Work_Repository implements Student_Note_Work_Repository_Port_Interface {
	/**
	 * Success.
	 *
	 * @var Student_Note_Normalized_Result|null
	 */
	public ?Student_Note_Normalized_Result $success = null;
	/**
	 * Failure.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $failure = null;
	/**
	 * Start calls.
	 *
	 * @var int
	 */
	public int $start_calls = 0;
	/**
	 * Success status.
	 *
	 * @var string
	 */
	public string $success_status = self::COMMIT_SUCCEEDED;
	/**
	 * Initialize g4 note work repository dependencies and state.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 */
	public function __construct( public Student_Note_Work_Item $item ) {
	}
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Student_Note_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Student_Note_Work_Item {
		unset( $now_utc, $lease_token, $lease_expires_at_utc );
		return $this->item;
	}
	/**
	 * Start attempt.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @param string                 $attempt_public_id Attempt public id.
	 * @param string                 $started_at_utc Started at utc.
	 * @param string                 $lease_expires_at_utc Lease expires at utc.
	 * @return Student_Note_Work_Item|null
	 */
	public function start_attempt( Student_Note_Work_Item $item, string $attempt_public_id, string $started_at_utc, string $lease_expires_at_utc ): ?Student_Note_Work_Item {
		unset( $attempt_public_id, $started_at_utc, $lease_expires_at_utc );
		++$this->start_calls;
		$this->item = $item->started( $item->attempt_count() + 1, $item->lock_version() + 1 );
		return $this->item;
	}
	/**
	 * Complete success.
	 *
	 * @param Student_Note_Work_Item               $item Item.
	 * @param Submission_Evaluation_Gateway_Result $gateway Gateway.
	 * @param Student_Note_Normalized_Result       $result Result.
	 * @param string                               $note_revision_public_id Note revision public id.
	 * @param string                               $audit_public_id Audit public id.
	 * @param string                               $outbox_public_id Outbox public id.
	 * @param string                               $finished_at_utc Finished at utc.
	 * @return string
	 */
	public function complete_success( Student_Note_Work_Item $item, Submission_Evaluation_Gateway_Result $gateway, Student_Note_Normalized_Result $result, string $note_revision_public_id, string $audit_public_id, string $outbox_public_id, string $finished_at_utc ): string {
		unset( $item, $gateway, $note_revision_public_id, $audit_public_id, $outbox_public_id, $finished_at_utc );
		$this->success = $result;
		return $this->success_status;
	}
	/**
	 * Complete failure.
	 *
	 * @param Student_Note_Work_Item                      $item Item.
	 * @param string                                      $attempt_state Attempt state.
	 * @param string                                      $error_code Error code.
	 * @param string|null                                 $gateway_correlation_id Gateway correlation id.
	 * @param string|null                                 $provider_output_digest Provider output digest.
	 * @param int|null                                    $duration_ms Duration ms.
	 * @param Submission_Evaluation_Routing_Snapshot|null $actual_routing Actual routing.
	 * @param bool                                        $retry Retry.
	 * @param string                                      $available_at_utc Available at utc.
	 * @param string                                      $audit_public_id Audit public id.
	 * @param string                                      $outbox_public_id Outbox public id.
	 * @param string                                      $finished_at_utc Finished at utc.
	 * @param string|null                                 $error_message Error message.
	 * @return bool
	 */
	public function complete_failure( Student_Note_Work_Item $item, string $attempt_state, string $error_code, ?string $gateway_correlation_id, ?string $provider_output_digest, ?int $duration_ms, ?Submission_Evaluation_Routing_Snapshot $actual_routing, bool $retry, string $available_at_utc, string $audit_public_id, string $outbox_public_id, string $finished_at_utc, ?string $error_message = null ): bool {
		unset( $audit_public_id, $outbox_public_id );
		g4_note_worker_same( $this->item->lock_version(), $item->lock_version(), 'Failure must use the current claimed run version.' );
		g4_note_worker_same( $this->item->state(), $item->state(), 'Failure must use the current run state.' );
		$this->failure = compact( 'item', 'attempt_state', 'error_code', 'gateway_correlation_id', 'provider_output_digest', 'duration_ms', 'actual_routing', 'retry', 'available_at_utc', 'finished_at_utc', 'error_message' );
		return true;
	}
}

/**
 * G4-IMP-05-S01 student special-note worker contract.
 */
final class G4_Note_Source_Repository implements Student_Note_Repository_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 note source repository dependencies and state.
	 *
	 * @param array<int,array<string,mixed>|null> $sources Sources.
	 */
	public function __construct( private array $sources ) {
	}
	/**
	 * Find command receipt.
	 *
	 * @param string $command_storage_key Command storage key.
	 * @param string $request_digest Request digest.
	 * @return Generate_Student_Note_Draft_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Generate_Student_Note_Draft_Result {
		unset( $command_storage_key, $request_digest );
		throw new LogicException( 'Worker must not read command receipts.' );
	}
	/**
	 * Find owned source.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned_source( int $actor_user_id, string $evaluation_id ): ?array {
		unset( $actor_user_id, $evaluation_id );
		++$this->calls;
		return array_shift( $this->sources );
	}
	/**
	 * Enqueue.
	 *
	 * @param array $record Record.
	 * @return Generate_Student_Note_Draft_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Generate_Student_Note_Draft_Result {
		unset( $record );
		throw new LogicException( 'Worker must not enqueue runs.' );
	}
}

/**
 * G4-IMP-05-S01 student special-note worker contract.
 */
final class G4_Note_Execution implements Student_Note_Execution_Port_Interface {
	/**
	 * Exception.
	 *
	 * @var Throwable|null
	 */
	public ?Throwable $exception = null;
	/**
	 * Snapshot calls.
	 *
	 * @var int
	 */
	public int $snapshot_calls = 0;
	/**
	 * Execute calls.
	 *
	 * @var int
	 */
	public int $execute_calls = 0;
	/**
	 * Initialize g4 note execution dependencies and state.
	 *
	 * @param array<int,Submission_Evaluation_Routing_Snapshot|null> $snapshots Snapshots.
	 * @param Submission_Evaluation_Gateway_Result                   $gateway Gateway.
	 */
	public function __construct( private array $snapshots, private Submission_Evaluation_Gateway_Result $gateway ) {
	}
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Submission_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Submission_Evaluation_Routing_Snapshot {
		g4_note_worker_same( Addon_Manifest::TASK_STUDENT_ASSESSMENT, $task_key, 'Student-note task key drifted.' );
		++$this->snapshot_calls;
		return array_shift( $this->snapshots );
	}
	/**
	 * Execute g4 note execution.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return Submission_Evaluation_Gateway_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Student_Note_Work_Item $item ): Submission_Evaluation_Gateway_Result {
		g4_note_worker_same( Student_Note_Input_Builder::OPERATION, $item->input()['operation'], 'Student-note worker sent the wrong operation.' );
		++$this->execute_calls;
		if ( null !== $this->exception ) {
			throw $this->exception;
		}
		return $this->gateway;
	}
}

/**
 * G4-IMP-05-S01 student special-note worker contract.
 */
final class G4_Note_Consent implements Student_Note_Execution_Consent_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 note consent dependencies and state.
	 *
	 * @param array<int,bool> $decisions Decisions.
	 */
	public function __construct( private array $decisions = array( true, true ) ) {
	}
	/**
	 * Allows.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Student_Note_Work_Item $item ): bool {
		unset( $item );
		++$this->calls;
		return array() === $this->decisions ? true : (bool) array_shift( $this->decisions );
	}
}

/**
 * G4-IMP-05-S01 student special-note worker contract.
 */
final class G4_Note_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		g4_note_worker_same( Run_Next_Student_Note_Draft::ACTION_EXECUTE, $request->action_key(), 'Student-note worker action drifted.' );
		g4_note_worker_same( array( Addon_Capabilities::MANAGE_STUDENT_NOTES ), $request->required_capabilities(), 'Student-note worker capability drifted.' );
		g4_note_worker_same( Context_Scope::OPERATION_REPORT, $request->context()?->operation(), 'Student-note worker Context operation drifted.' );
		g4_note_worker_same( 'report', $request->object()?->action(), 'Student-note worker object action drifted.' );
		return Access_Policy_Decision::allowed( $request->correlation_id(), null, Object_Authorization_Result::allowed( '5', 'reviewed', 'context_staff' ) );
	}
}

/**
 * G4 note worker scenario.
 *
 * @param Canonical_Json                                              $canonical Canonical JSON encoder used for stable digests.
 * @param array                                                       $source Source.
 * @param Submission_Evaluation_Gateway_Result                        $gateway Gateway.
 * @param array<int,array<string,mixed>|null>|null                    $sources Sources.
 * @param array<int,bool>                                             $consent Consent.
 * @param array<int,Submission_Evaluation_Routing_Snapshot|null>|null $snapshots Snapshots.
 * @return array<string,mixed>
 */
function g4_note_worker_scenario( Canonical_Json $canonical, array $source, Submission_Evaluation_Gateway_Result $gateway, ?array $sources = null, array $consent = array( true, true ), ?array $snapshots = null ): array {
	$route             = g4_note_worker_route();
	$builder           = new Student_Note_Input_Builder( $canonical );
	$work              = new G4_Note_Work_Repository( g4_note_worker_item( $source, $builder, $route ) );
	$source_repository = new G4_Note_Source_Repository( $sources ?? array( $source, $source ) );
	$execution         = new G4_Note_Execution( $snapshots ?? array( $route, $route ), $gateway );
	$consent_port      = new G4_Note_Consent( $consent );
	$policy            = new G4_Note_Policy();
	$worker            = new Run_Next_Student_Note_Draft(
		$work,
		$source_repository,
		$execution,
		$consent_port,
		$policy,
		$builder,
		new Student_Note_Output_Normalizer( $canonical ),
		g4_note_worker_ids( 1100 ),
		static fn (): string => '2026-08-24 10:00:00',
		static fn (): string => '123e4567-e89b-42d3-a456-426614174001'
	);
	return compact( 'work', 'source_repository', 'execution', 'consent_port', 'policy', 'worker' );
}

$canonical     = new Canonical_Json();
$source        = g4_note_worker_source( $canonical );
$input         = ( new Student_Note_Input_Builder( $canonical ) )->build( $source )->gateway_input();
$route         = g4_note_worker_route();
$valid_gateway = Submission_Evaluation_Gateway_Result::success( g4_note_worker_output( $input ), $route, 'gateway-correlation-g4-note', 120 );

foreach ( array(
	new RuntimeException( 'Authorization: Bearer secret-fixture-token' ),
	new InvalidArgumentException( 'student-private-fixture@example.invalid' ),
	new Error( '/private/fixture-path.php: secret fixture' ),
) as $exception ) {
	$scenario                         = g4_note_worker_scenario( $canonical, $source, $valid_gateway );
	$scenario['execution']->exception = $exception;
	g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Unexpected execution error must complete the claimed run.' );
	g4_note_worker_same( 'worker_exception', $scenario['work']->failure['error_code'], 'Unexpected errors require a stable code.' );
	g4_note_worker_same( null, $scenario['work']->failure['error_message'], 'No exception content may be persisted for the UI.' );
	g4_note_worker_same( 'gateway_failed', $scenario['work']->failure['attempt_state'], 'The started attempt must also become terminal.' );
	g4_note_worker_assert( str_starts_with( $scenario['work']->failure['gateway_correlation_id'], 'worker:' ), 'A content-free correlation must remain available.' );
	g4_note_worker_assert( is_int( $scenario['work']->failure['duration_ms'] ) && $scenario['work']->failure['duration_ms'] >= 0, 'Elapsed attempt duration must be recorded.' );
}

$scenario = g4_note_worker_scenario( $canonical, $source, $valid_gateway );
$result   = $scenario['worker']->run_once();
g4_note_worker_same( Run_Student_Note_Draft_Result::SUCCEEDED, $result->state(), 'Valid student-note run must succeed.' );
g4_note_worker_same( 1, $scenario['execution']->execute_calls, 'Student-note Gateway must execute exactly once.' );
g4_note_worker_same( 2, $scenario['execution']->snapshot_calls, 'Routing must be checked before and after student-note execution.' );
g4_note_worker_same( 2, $scenario['source_repository']->calls, 'Official review source must be rebuilt before and after execution.' );
g4_note_worker_same( 2, $scenario['policy']->calls, 'Student-note authorization must be checked before and after execution.' );
g4_note_worker_same( 2, $scenario['consent_port']->calls, 'External processing consent must be checked before and after execution.' );
g4_note_worker_assert( $scenario['work']->success instanceof Student_Note_Normalized_Result, 'Successful worker must commit one normalized student-note draft.' );
g4_note_worker_same( null, $scenario['work']->failure, 'Successful worker must not record a failure.' );

$changed_source                         = $source;
$changed_source['official_review_hash'] = str_repeat( 'e', 64 );
$scenario                               = g4_note_worker_scenario( $canonical, $source, $valid_gateway, array( $source, $changed_source ) );
g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Changed official review after Gateway must discard output.' );
g4_note_worker_same( Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, $scenario['work']->failure['error_code'] ?? null, 'Changed official review must use the stable student-note source code.' );
g4_note_worker_same( null, $scenario['work']->success, 'Changed official review must append no student-note draft.' );
g4_note_worker_assert( is_string( $scenario['work']->failure['provider_output_digest'] ?? null ), 'Discarded output must retain only its digest.' );

$invalid_output                   = g4_note_worker_output( $input );
$invalid_output['official_score'] = '8.0000';
$invalid_gateway                  = Submission_Evaluation_Gateway_Result::success( $invalid_output, $route, 'gateway-correlation-g4-note-invalid', 90 );
$scenario                         = g4_note_worker_scenario( $canonical, $source, $invalid_gateway );
g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Schema-invalid provider output must fail closed.' );
g4_note_worker_same( Submission_Evaluation_Failure::STUDENT_NOTE_SCHEMA_INVALID, $scenario['work']->failure['error_code'] ?? null, 'Student-note schema failure reason drifted.' );
g4_note_worker_same( null, $scenario['work']->success, 'Schema-invalid output must append no draft.' );

$flagged_output                 = g4_note_worker_output( $input );
$flagged_output['safety_flags'] = array( 'unsupported_claim' );
$flagged_gateway                = Submission_Evaluation_Gateway_Result::success( $flagged_output, $route, 'gateway-correlation-g4-note-flagged', 90 );
$scenario                       = g4_note_worker_scenario( $canonical, $source, $flagged_gateway );
g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Safety-flagged output must fail closed.' );
g4_note_worker_same( null, $scenario['work']->success, 'Safety-flagged output must append no draft.' );

$scenario = g4_note_worker_scenario( $canonical, $source, $valid_gateway, null, array( false ) );
g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Missing consent must fail closed.' );
g4_note_worker_same( 0, $scenario['execution']->execute_calls, 'Missing consent must prevent Gateway execution.' );
g4_note_worker_same( 'external_processing_not_approved', $scenario['work']->failure['error_code'] ?? null, 'Student-note consent failure code drifted.' );

$retry_gateway = Submission_Evaluation_Gateway_Result::failure( 'addon_ai_task_failed', true, 'gateway-correlation-g4-note-retry', 80 );
$scenario      = g4_note_worker_scenario( $canonical, $source, $retry_gateway );
g4_note_worker_same( Run_Student_Note_Draft_Result::RETRY_WAIT, $scenario['worker']->run_once()->state(), 'Retryable Gateway failure must enter retry_wait.' );
g4_note_worker_same( '2026-08-24 10:01:00', $scenario['work']->failure['available_at_utc'] ?? null, 'First student-note retry backoff must be 60 seconds.' );

$scenario = g4_note_worker_scenario( $canonical, $source, $valid_gateway, null, array( true, true ), array( $route, g4_note_worker_route( 'routing-g4-note-worker-v2' ) ) );
g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Postflight routing drift must discard output.' );
g4_note_worker_same( 'routing_changed_during_execute', $scenario['work']->failure['error_code'] ?? null, 'Postflight routing drift reason changed.' );

$scenario                         = g4_note_worker_scenario( $canonical, $source, $valid_gateway );
$scenario['work']->success_status = Student_Note_Work_Repository_Port_Interface::COMMIT_SOURCE_STALE;
g4_note_worker_same( Run_Student_Note_Draft_Result::FAILED, $scenario['worker']->run_once()->state(), 'Final transaction source race must report failed.' );

fwrite( STDOUT, "G4-IMP-05-S01 student-note worker preflight, postflight, privacy, consent, routing, retry, and race contracts passed.\n" );
