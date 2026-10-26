<?php
/**
 * G4-IMP-04-S01 student submission feedback worker contract.
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
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Submission_Feedback_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Submission_Feedback_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Execution_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Feedback_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

( new Addon_Autoloader( $addon_root . '/src' ) )->register();

/**
 * G4 feedback worker assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_worker_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 feedback worker same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_feedback_worker_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 feedback worker id.
 *
 * @return string
 */
function g4_feedback_worker_id(): string {
	static $counter = 45000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_feedback_worker_assert( Public_Id::is_valid( $id ), 'Worker fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 feedback worker ids.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function g4_feedback_worker_ids( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * G4 feedback worker source.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g4_feedback_worker_source( Canonical_Json $canonical ): array {
	$response_id = g4_feedback_worker_id();
	$binding_key = str_repeat( 'a', 64 );
	$payload     = array(
		'bindings'  => array(
			array(
				'binding_key'    => $binding_key,
				'criterion_id'   => g4_feedback_worker_id(),
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
		'evaluation_pk'            => 21,
		'evaluation_id'            => g4_feedback_worker_id(),
		'evaluation_lock_version'  => 5,
		'evaluation_state'         => 'reviewed',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-feedback-worker',
		'context_revision'         => 'class-g4-feedback-worker-r1',
		'submission_pk'            => 22,
		'submission_id'            => g4_feedback_worker_id(),
		'submission_lock_version'  => 7,
		'submission_revision_id'   => g4_feedback_worker_id(),
		'submission_revision_hash' => str_repeat( 'b', 64 ),
		'official_review_pk'       => 23,
		'official_review_id'       => g4_feedback_worker_id(),
		'official_review_hash'     => str_repeat( 'c', 64 ),
		'result_pk'                => 24,
		'result_id'                => g4_feedback_worker_id(),
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
 * G4 feedback worker route.
 *
 * @return Submission_Evaluation_Routing_Snapshot
 */
function g4_feedback_worker_route(): Submission_Evaluation_Routing_Snapshot {
	return new Submission_Evaluation_Routing_Snapshot( Addon_Manifest::TASK_STUDENT_ASSESSMENT, 'openai', 'gpt-5-mini', str_repeat( '8', 64 ), 'routing-g4-feedback-worker-v1' );
}

/**
 * G4 feedback worker output.
 *
 * @param array<string,mixed> $input Input.
 * @return array<string,mixed>
 */
function g4_feedback_worker_output( array $input ): array {
	$payload = $input['operation_payload'];
	return array(
		'criterion_feedback' => array(
			array(
				'binding_key' => $payload['criteria'][0]['binding_key'],
				'next_step'   => 'Explain why each observation supports the conclusion.',
				'strength'    => 'Two relevant observations are included.',
			),
		),
		'feedback_ref'       => $payload['subject']['feedback_ref'],
		'next_steps'         => array( 'Connect each observation to the conclusion.' ),
		'overall_feedback'   => 'The response uses relevant evidence and can make the reasoning more explicit.',
		'safety_flags'       => array(),
		'schema_id'          => Submission_Feedback_Input_Builder::OUTPUT_SCHEMA,
		'schema_version'     => Submission_Feedback_Input_Builder::OUTPUT_VERSION,
		'source_hash'        => $payload['subject']['source_hash'],
	);
}

/**
 * G4 feedback worker item.
 *
 * @param array                                  $source Source.
 * @param Submission_Feedback_Input_Builder      $builder Builder.
 * @param Submission_Evaluation_Routing_Snapshot $route Route.
 * @return Submission_Feedback_Work_Item
 */
function g4_feedback_worker_item( array $source, Submission_Feedback_Input_Builder $builder, Submission_Evaluation_Routing_Snapshot $route ): Submission_Feedback_Work_Item {
	$input = $builder->build( $source );
	return new Submission_Feedback_Work_Item(
		$source['evaluation_pk'],
		31,
		$source['evaluation_id'],
		g4_feedback_worker_id(),
		42,
		$source['submission_id'],
		$source['context_type'],
		$source['context_id'],
		$source['context_revision'],
		$source['evaluation_lock_version'],
		$source['evaluation_state'],
		Submission_Feedback_Input_Builder::OUTER_INPUT_CONTRACT,
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
 * G4-IMP-04-S01 student submission feedback worker contract.
 */
final class G4_Feedback_Work_Repository implements Submission_Feedback_Work_Repository_Port_Interface {
	/**
	 * Success.
	 *
	 * @var Submission_Feedback_Normalized_Result|null
	 */
	public ?Submission_Feedback_Normalized_Result $success = null;
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
	 * Initialize g4 feedback work repository dependencies and state.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 */
	public function __construct( public Submission_Feedback_Work_Item $item ) {
	}
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Feedback_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Submission_Feedback_Work_Item {
		unset( $now_utc, $lease_token, $lease_expires_at_utc );
		return $this->item;
	}
	/**
	 * Start attempt.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @param string                        $attempt_public_id Attempt public id.
	 * @param string                        $started_at_utc Started at utc.
	 * @param string                        $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Feedback_Work_Item|null
	 */
	public function start_attempt( Submission_Feedback_Work_Item $item, string $attempt_public_id, string $started_at_utc, string $lease_expires_at_utc ): ?Submission_Feedback_Work_Item {
		unset( $attempt_public_id, $started_at_utc, $lease_expires_at_utc );
		++$this->start_calls;
		$this->item = $item->started( $item->attempt_count() + 1, $item->lock_version() + 1 );
		return $this->item;
	}
	/**
	 * Complete success.
	 *
	 * @param Submission_Feedback_Work_Item         $item Item.
	 * @param Submission_Evaluation_Gateway_Result  $gateway Gateway.
	 * @param Submission_Feedback_Normalized_Result $result Result.
	 * @param string                                $feedback_public_id Feedback public id.
	 * @param string                                $audit_public_id Audit public id.
	 * @param string                                $outbox_public_id Outbox public id.
	 * @param string                                $finished_at_utc Finished at utc.
	 * @return string
	 */
	public function complete_success( Submission_Feedback_Work_Item $item, Submission_Evaluation_Gateway_Result $gateway, Submission_Feedback_Normalized_Result $result, string $feedback_public_id, string $audit_public_id, string $outbox_public_id, string $finished_at_utc ): string {
		unset( $item, $gateway, $feedback_public_id, $audit_public_id, $outbox_public_id, $finished_at_utc );
		$this->success = $result;
		return $this->success_status;
	}
	/**
	 * Complete failure.
	 *
	 * @param Submission_Feedback_Work_Item               $item Item.
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
	public function complete_failure( Submission_Feedback_Work_Item $item, string $attempt_state, string $error_code, ?string $gateway_correlation_id, ?string $provider_output_digest, ?int $duration_ms, ?Submission_Evaluation_Routing_Snapshot $actual_routing, bool $retry, string $available_at_utc, string $audit_public_id, string $outbox_public_id, string $finished_at_utc, ?string $error_message = null ): bool {
		unset( $audit_public_id, $outbox_public_id );
		g4_feedback_worker_same( $this->item->lock_version(), $item->lock_version(), 'Failure must use the current claimed run version.' );
		g4_feedback_worker_same( $this->item->state(), $item->state(), 'Failure must use the current run state.' );
		$this->failure = compact( 'item', 'attempt_state', 'error_code', 'gateway_correlation_id', 'provider_output_digest', 'duration_ms', 'actual_routing', 'retry', 'available_at_utc', 'finished_at_utc', 'error_message' );
		return true;
	}
}

/**
 * G4-IMP-04-S01 student submission feedback worker contract.
 */
final class G4_Feedback_Source_Repository implements Submission_Feedback_Repository_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 feedback source repository dependencies and state.
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
	 * @return Generate_Submission_Feedback_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $command_storage_key, string $request_digest ): ?Generate_Submission_Feedback_Result {
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
	 * @return Generate_Submission_Feedback_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $record ): Generate_Submission_Feedback_Result {
		unset( $record );
		throw new LogicException( 'Worker must not enqueue runs.' );
	}
}

/**
 * G4-IMP-04-S01 student submission feedback worker contract.
 */
final class G4_Feedback_Execution implements Submission_Feedback_Execution_Port_Interface {
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
	 * Initialize g4 feedback execution dependencies and state.
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
		g4_feedback_worker_same( Addon_Manifest::TASK_STUDENT_ASSESSMENT, $task_key, 'Feedback task key drifted.' );
		++$this->snapshot_calls;
		return array_shift( $this->snapshots );
	}
	/**
	 * Execute g4 feedback execution.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @return Submission_Evaluation_Gateway_Result
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function execute( Submission_Feedback_Work_Item $item ): Submission_Evaluation_Gateway_Result {
		g4_feedback_worker_same( Submission_Feedback_Input_Builder::OPERATION, $item->input()['operation'], 'Feedback worker sent the wrong operation.' );
		++$this->execute_calls;
		if ( null !== $this->exception ) {
			throw $this->exception;
		}
		return $this->gateway;
	}
}

/**
 * G4-IMP-04-S01 student submission feedback worker contract.
 */
final class G4_Feedback_Consent implements Submission_Feedback_Execution_Consent_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 feedback consent dependencies and state.
	 *
	 * @param array<int,bool> $decisions Decisions.
	 */
	public function __construct( private array $decisions = array( true, true ) ) {
	}
	/**
	 * Allows.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Submission_Feedback_Work_Item $item ): bool {
		unset( $item );
		++$this->calls;
		return array() === $this->decisions ? true : (bool) array_shift( $this->decisions );
	}
}

/**
 * G4-IMP-04-S01 student submission feedback worker contract.
 */
final class G4_Feedback_Policy implements Access_Policy_Port_Interface {
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
		g4_feedback_worker_same( Run_Next_Submission_Feedback::ACTION_EXECUTE, $request->action_key(), 'Feedback worker action drifted.' );
		g4_feedback_worker_same( array( Addon_Capabilities::REVIEW_ASSESSMENTS ), $request->required_capabilities(), 'Feedback worker capability drifted.' );
		return Access_Policy_Decision::allowed( $request->correlation_id(), null, Object_Authorization_Result::allowed( '5', 'reviewed', 'context_staff' ) );
	}
}

/**
 * G4 feedback worker scenario.
 *
 * @param Canonical_Json                           $canonical Canonical JSON encoder used for stable digests.
 * @param array                                    $source Source.
 * @param Submission_Evaluation_Gateway_Result     $gateway Gateway.
 * @param array<int,array<string,mixed>|null>|null $sources Sources.
 * @param array<int,bool>                          $consent Consent.
 * @return array<string,mixed>
 */
function g4_feedback_worker_scenario( Canonical_Json $canonical, array $source, Submission_Evaluation_Gateway_Result $gateway, ?array $sources = null, array $consent = array( true, true ) ): array {
	$route             = g4_feedback_worker_route();
	$builder           = new Submission_Feedback_Input_Builder( $canonical );
	$work              = new G4_Feedback_Work_Repository( g4_feedback_worker_item( $source, $builder, $route ) );
	$source_repository = new G4_Feedback_Source_Repository( $sources ?? array( $source, $source ) );
	$execution         = new G4_Feedback_Execution( array( $route, $route ), $gateway );
	$consent_port      = new G4_Feedback_Consent( $consent );
	$policy            = new G4_Feedback_Policy();
	$worker            = new Run_Next_Submission_Feedback(
		$work,
		$source_repository,
		$execution,
		$consent_port,
		$policy,
		$builder,
		new Submission_Feedback_Output_Normalizer( $canonical ),
		g4_feedback_worker_ids( 900 ),
		static fn (): string => '2026-08-24 10:00:00',
		static fn (): string => '123e4567-e89b-42d3-a456-426614174001'
	);
	return compact( 'work', 'source_repository', 'execution', 'consent_port', 'policy', 'worker' );
}

$canonical     = new Canonical_Json();
$source        = g4_feedback_worker_source( $canonical );
$input         = ( new Submission_Feedback_Input_Builder( $canonical ) )->build( $source )->gateway_input();
$route         = g4_feedback_worker_route();
$valid_gateway = Submission_Evaluation_Gateway_Result::success( g4_feedback_worker_output( $input ), $route, 'gateway-correlation-g4-feedback', 120 );

foreach ( array(
	new RuntimeException( 'Authorization: Bearer secret-fixture-token' ),
	new InvalidArgumentException( 'student-private-fixture@example.invalid' ),
	new Error( '/private/fixture-path.php: secret fixture' ),
) as $exception ) {
	$scenario                         = g4_feedback_worker_scenario( $canonical, $source, $valid_gateway );
	$scenario['execution']->exception = $exception;
	g4_feedback_worker_same( Run_Submission_Feedback_Result::FAILED, $scenario['worker']->run_once()->state(), 'Unexpected execution error must complete the claimed run.' );
	g4_feedback_worker_same( 'worker_exception', $scenario['work']->failure['error_code'], 'Unexpected errors require a stable code.' );
	g4_feedback_worker_same( null, $scenario['work']->failure['error_message'], 'No exception content may be persisted for the UI.' );
	g4_feedback_worker_same( 'gateway_failed', $scenario['work']->failure['attempt_state'], 'The started attempt must also become terminal.' );
	g4_feedback_worker_assert( str_starts_with( $scenario['work']->failure['gateway_correlation_id'], 'worker:' ), 'A content-free correlation must remain available.' );
	g4_feedback_worker_assert( is_int( $scenario['work']->failure['duration_ms'] ) && $scenario['work']->failure['duration_ms'] >= 0, 'Elapsed attempt duration must be recorded.' );
}

$scenario = g4_feedback_worker_scenario( $canonical, $source, $valid_gateway );
$result   = $scenario['worker']->run_once();
g4_feedback_worker_same( Run_Submission_Feedback_Result::SUCCEEDED, $result->state(), 'Valid feedback run must succeed.' );
g4_feedback_worker_same( 1, $scenario['execution']->execute_calls, 'Feedback Gateway must execute exactly once.' );
g4_feedback_worker_same( 2, $scenario['execution']->snapshot_calls, 'Routing must be checked before and after Gateway execution.' );
g4_feedback_worker_same( 2, $scenario['source_repository']->calls, 'Official review source must be rebuilt before and after execution.' );
g4_feedback_worker_same( 2, $scenario['policy']->calls, 'Authorization must be checked before and after execution.' );
g4_feedback_worker_same( 2, $scenario['consent_port']->calls, 'External processing consent must be checked before and after execution.' );
g4_feedback_worker_assert( $scenario['work']->success instanceof Submission_Feedback_Normalized_Result, 'Successful worker must commit one normalized feedback draft.' );
g4_feedback_worker_same( null, $scenario['work']->failure, 'Successful worker must not record a failure.' );

$changed_source                         = $source;
$changed_source['official_review_hash'] = str_repeat( 'e', 64 );
$scenario                               = g4_feedback_worker_scenario( $canonical, $source, $valid_gateway, array( $source, $changed_source ) );
g4_feedback_worker_same( Run_Submission_Feedback_Result::FAILED, $scenario['worker']->run_once()->state(), 'Changed official review after Gateway must discard output.' );
g4_feedback_worker_same( Submission_Evaluation_Failure::FEEDBACK_SOURCE_STALE, $scenario['work']->failure['error_code'] ?? null, 'Changed official review must use the stable feedback source code.' );
g4_feedback_worker_same( null, $scenario['work']->success, 'Changed official review must append no feedback draft.' );
g4_feedback_worker_assert( is_string( $scenario['work']->failure['provider_output_digest'] ?? null ), 'Discarded provider output must retain only its digest.' );

$invalid_output                   = g4_feedback_worker_output( $input );
$invalid_output['official_score'] = '8.0000';
$invalid_gateway                  = Submission_Evaluation_Gateway_Result::success( $invalid_output, $route, 'gateway-correlation-g4-feedback-invalid', 90 );
$scenario                         = g4_feedback_worker_scenario( $canonical, $source, $invalid_gateway );
g4_feedback_worker_same( Run_Submission_Feedback_Result::FAILED, $scenario['worker']->run_once()->state(), 'Schema-invalid provider output must fail closed.' );
g4_feedback_worker_same( Submission_Evaluation_Failure::FEEDBACK_SCHEMA_INVALID, $scenario['work']->failure['error_code'] ?? null, 'Schema-invalid output reason drifted.' );
g4_feedback_worker_same( null, $scenario['work']->success, 'Schema-invalid output must append no draft.' );

$scenario = g4_feedback_worker_scenario( $canonical, $source, $valid_gateway, null, array( false ) );
g4_feedback_worker_same( Run_Submission_Feedback_Result::FAILED, $scenario['worker']->run_once()->state(), 'Missing consent must fail closed.' );
g4_feedback_worker_same( 0, $scenario['execution']->execute_calls, 'Missing consent must prevent Gateway execution.' );
g4_feedback_worker_same( 'external_processing_not_approved', $scenario['work']->failure['error_code'] ?? null, 'Consent failure code drifted.' );

$retry_gateway = Submission_Evaluation_Gateway_Result::failure( 'addon_ai_task_failed', true, 'gateway-correlation-g4-feedback-retry', 80 );
$scenario      = g4_feedback_worker_scenario( $canonical, $source, $retry_gateway );
g4_feedback_worker_same( Run_Submission_Feedback_Result::RETRY_WAIT, $scenario['worker']->run_once()->state(), 'Retryable Gateway failure must enter retry_wait.' );
g4_feedback_worker_same( '2026-08-24 10:01:00', $scenario['work']->failure['available_at_utc'] ?? null, 'First feedback retry backoff must be 60 seconds.' );

$scenario                         = g4_feedback_worker_scenario( $canonical, $source, $valid_gateway );
$scenario['work']->success_status = Submission_Feedback_Work_Repository_Port_Interface::COMMIT_SOURCE_STALE;
g4_feedback_worker_same( Run_Submission_Feedback_Result::FAILED, $scenario['worker']->run_once()->state(), 'Final transaction source race must report failed without a successful projection.' );

fwrite( STDOUT, "G4-IMP-04-S01 feedback worker preflight, postflight, stale-source discard, schema failure, consent, and retry contracts passed.\n" );
