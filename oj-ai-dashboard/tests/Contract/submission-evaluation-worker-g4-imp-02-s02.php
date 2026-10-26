<?php
/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );
define( 'ARRAY_A', 'ARRAY_A' );

require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Public_Id.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Public_Id_Generator.php';
require_once ABSPATH . 'src/Worksheet/Authoring/Domain/Canonical_Json.php';
require_once ABSPATH . 'src/Security/Addon_Capabilities.php';
require_once ABSPATH . 'src/Security/Policy/Policy_Value.php';
require_once ABSPATH . 'src/Security/Policy/Context_Scope.php';
require_once ABSPATH . 'src/Security/Policy/Object_Scope.php';
require_once ABSPATH . 'src/Security/Policy/Context_Authorization_Result.php';
require_once ABSPATH . 'src/Security/Policy/Object_Authorization_Result.php';
require_once ABSPATH . 'src/Security/Policy/Access_Policy_Request.php';
require_once ABSPATH . 'src/Security/Policy/Access_Policy_Decision.php';
require_once ABSPATH . 'src/Security/Policy/Port/Access_Policy_Port_Interface.php';
require_once ABSPATH . 'src/Platform/Addon_Manifest.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Submission_Evaluation_Worker_Failure.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Run_Submission_Evaluation_Result.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Score_Input_Builder.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Routing_Snapshot.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Work_Item.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Source.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Gateway_Result.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Normalized_Result.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_Evaluation_Output_Normalizer.php';
require_once ABSPATH . 'src/Submission/Assessment/Domain/Submission_External_Ai_Consent_Record.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Submission_Evaluation_Work_Repository_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Submission_Evaluation_Execution_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Submission_Evaluation_Execution_Consent_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Port/Submission_Evaluation_Source_Port_Interface.php';
require_once ABSPATH . 'src/Submission/Assessment/Application/Run_Next_Submission_Evaluation.php';
require_once ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Execution_Consent.php';
require_once ABSPATH . 'src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Work_Repository.php';

use OJAI\Dashboard\Platform\Addon_Manifest;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Next_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Run_Submission_Evaluation_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Worker_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Normalized_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Source;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Work_Item;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_External_Ai_Consent_Record;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Score_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Execution_Consent;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Evaluation_Work_Repository;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Execution_Consent_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Execution_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Source_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Work_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

/**
 * G4 s02 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s02 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s02 id.
 *
 * @return string
 */
function g4_s02_id(): string {
	static $counter = 31000;
	++$counter;
	$id = '01K' . str_pad( (string) $counter, 23, '0', STR_PAD_LEFT );
	g4_s02_assert( Public_Id::is_valid( $id ), 'Fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s02 ids.
 *
 * @param int $seed Seed.
 * @return Public_Id_Generator
 */
function g4_s02_ids( int $seed ): Public_Id_Generator {
	$counter = $seed;
	return new Public_Id_Generator(
		static function () use ( &$counter ): string {
			++$counter;
			return pack( 'N4', 0, 0, 0, $counter );
		}
	);
}

/**
 * G4 s02 fixture.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @return array<string,mixed>
 */
function g4_s02_fixture( Canonical_Json $canonical ): array {
	$response_id    = g4_s02_id();
	$criterion_id   = g4_s02_id();
	$binding_key    = str_repeat( 'b', 64 );
	$source_hash    = str_repeat( 'a', 64 );
	$evaluation_ref = 'EV_' . str_repeat( 'A', 32 );
	$payload        = array(
		'artifacts'       => array(),
		'bindings'        => array(
			array(
				'binding_key'    => $binding_key,
				'criterion_id'   => $criterion_id,
				'description'    => 'Assesses whether the response directly and clearly addresses the task.',
				'label'          => 'Clarity',
				'maximum_points' => '10',
				'position'       => 0,
				'response_id'    => $response_id,
				'scope'          => 'response',
			),
		),
		'output_contract' => array(
			'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
		),
		'responses'       => array(
			array(
				'answer'      => array( 'text' => 'A clear answer.' ),
				'kind'        => 'long_text',
				'label'       => 'Answer',
				'position'    => 0,
				'response_id' => $response_id,
			),
		),
		'subject'         => array(
			'evaluation_ref'          => $evaluation_ref,
			'grading_schema_hash'     => str_repeat( 'c', 64 ),
			'locale'                  => 'ko-KR',
			'publication_hash'        => str_repeat( 'd', 64 ),
			'representation_set_hash' => str_repeat( 'e', 64 ),
			'source_hash'             => $source_hash,
		),
	);
	$input          = array(
		'operation'          => Submission_Score_Input_Builder::OPERATION,
		'operation_contract' => Submission_Score_Input_Builder::OPERATION_CONTRACT,
		'operation_payload'  => $payload,
		'operation_schema'   => Submission_Score_Input_Builder::OPERATION_SCHEMA,
		'payload_hash'       => hash( 'sha256', $canonical->encode( $payload ) ),
	);
	$output         = array(
		'schema_id'      => Submission_Score_Input_Builder::OUTPUT_SCHEMA,
		'schema_version' => Submission_Score_Input_Builder::OUTPUT_VERSION,
		'evaluation_ref' => $evaluation_ref,
		'source_hash'    => $source_hash,
		'items'          => array(
			array(
				'binding_key'      => $binding_key,
				'decision'         => 'score',
				'candidate_points' => '7.5',
				'confidence'       => 0.8,
				'rationale'        => 'The response is clear and directly addresses the task.',
				'evidence'         => array(
					array(
						'source' => 'response',
						'anchor' => $response_id,
						'quote'  => 'clear answer',
					),
				),
				'flags'            => array(),
			),
		),
		'summary'        => 'A reviewable candidate score was produced.',
		'limitations'    => 'The response was brief, limiting the evidence available for a fully confident assessment.',
	);
	return compact( 'response_id', 'criterion_id', 'binding_key', 'source_hash', 'evaluation_ref', 'input', 'output' );
}

/**
 * G4 s02 failure.
 *
 * @param callable():void $callback Callback.
 * @param string          $reason Reason.
 * @param string          $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Worker_Failure $failure ) {
		g4_s02_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message . ': expected failure was not thrown.' );
}

/**
 * G4 s02 route.
 *
 * @param string $fingerprint Fingerprint.
 * @return Submission_Evaluation_Routing_Snapshot
 */
function g4_s02_route( string $fingerprint = '' ): Submission_Evaluation_Routing_Snapshot {
	return new Submission_Evaluation_Routing_Snapshot(
		Addon_Manifest::TASK_STUDENT_ASSESSMENT,
		'openai',
		'gpt-5-mini',
		'' === $fingerprint ? str_repeat( '7', 64 ) : $fingerprint,
		'routing-g4-s02-v1'
	);
}

/**
 * G4 s02 item.
 *
 * @param array<string,mixed>                    $input Input.
 * @param Submission_Evaluation_Routing_Snapshot $route Route.
 * @param string                                 $state State.
 * @return Submission_Evaluation_Work_Item
 */
function g4_s02_item( array $input, Submission_Evaluation_Routing_Snapshot $route, string $state = 'leased' ): Submission_Evaluation_Work_Item {
	$running = 'running' === $state;
	return new Submission_Evaluation_Work_Item(
		2,
		1,
		g4_s02_id(),
		g4_s02_id(),
		42,
		g4_s02_id(),
		7,
		g4_s02_id(),
		(string) $input['operation_payload']['subject']['source_hash'],
		'submitted',
		g4_s02_id(),
		(string) $input['operation_payload']['subject']['publication_hash'],
		'class',
		'class-g4-s02',
		'context-r1',
		Submission_Score_Input_Builder::OUTER_INPUT_CONTRACT,
		hash( 'sha256', ( new Canonical_Json() )->encode( $input ) ),
		'run-idempotency-g4-s02',
		$input,
		$route,
		'123e4567-e89b-42d3-a456-426614174000',
		$running ? 2 : 1,
		$running ? 1 : 0,
		$running ? 1 : 0,
		$state,
		'2026-08-23 10:00:00'
	);
}

/**
 * G4 s02 source.
 *
 * @param Submission_Evaluation_Work_Item $item Item.
 * @return Submission_Evaluation_Source
 */
function g4_s02_source( Submission_Evaluation_Work_Item $item ): Submission_Evaluation_Source {
	return new Submission_Evaluation_Source(
		$item->submission_id(),
		$item->source_lock_version(),
		$item->source_state(),
		$item->context_type(),
		$item->context_id(),
		$item->context_revision(),
		array(
			'id'           => $item->revision_id(),
			'content_hash' => $item->source_hash(),
		),
		array(
			'id'               => $item->publication_id(),
			'publication_hash' => $item->publication_hash(),
		),
		array( 'evaluation_mode' => 'score' ),
		array()
	);
}

/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 */
final class G4_S02_Work_Repository implements Submission_Evaluation_Work_Repository_Port_Interface {
	/**
	 * Success.
	 *
	 * @var Submission_Evaluation_Normalized_Result|null
	 */
	public ?Submission_Evaluation_Normalized_Result $success = null;
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
	 * Stale start.
	 *
	 * @var bool
	 */
	public bool $stale_start = false;
	/**
	 * Stale commit.
	 *
	 * @var bool
	 */
	public bool $stale_commit = false;
	/**
	 * Initialize g4 s02 work repository dependencies and state.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param bool                            $idle Idle.
	 */
	public function __construct( public Submission_Evaluation_Work_Item $item, public bool $idle = false ) {
	}
	/**
	 * Claim due.
	 *
	 * @param string $now_utc Now utc.
	 * @param string $lease_token Lease token.
	 * @param string $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Evaluation_Work_Item|null
	 */
	public function claim_due( string $now_utc, string $lease_token, string $lease_expires_at_utc ): ?Submission_Evaluation_Work_Item {
		unset( $now_utc, $lease_token, $lease_expires_at_utc );
		return $this->idle ? null : $this->item;
	}
	/**
	 * Start attempt.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @param string                          $attempt_public_id Attempt public id.
	 * @param string                          $started_at_utc Started at utc.
	 * @param string                          $lease_expires_at_utc Lease expires at utc.
	 * @return Submission_Evaluation_Work_Item|null
	 */
	public function start_attempt( Submission_Evaluation_Work_Item $item, string $attempt_public_id, string $started_at_utc, string $lease_expires_at_utc ): ?Submission_Evaluation_Work_Item {
		unset( $attempt_public_id, $started_at_utc, $lease_expires_at_utc );
		++$this->start_calls;
		return $this->stale_start ? null : $item->started( $item->attempt_count() + 1, $item->lock_version() + 1 );
	}
	/**
	 * Complete success.
	 *
	 * @param Submission_Evaluation_Work_Item         $item Item.
	 * @param Submission_Evaluation_Gateway_Result    $gateway Gateway.
	 * @param Submission_Evaluation_Normalized_Result $result Result.
	 * @param string                                  $result_public_id Result public id.
	 * @param string                                  $audit_public_id Audit public id.
	 * @param string                                  $outbox_public_id Outbox public id.
	 * @param string                                  $finished_at_utc Finished at utc.
	 * @return bool
	 */
	public function complete_success( Submission_Evaluation_Work_Item $item, Submission_Evaluation_Gateway_Result $gateway, Submission_Evaluation_Normalized_Result $result, string $result_public_id, string $audit_public_id, string $outbox_public_id, string $finished_at_utc ): bool {
		unset( $item, $gateway, $result_public_id, $audit_public_id, $outbox_public_id, $finished_at_utc );
		$this->success = $result;
		return ! $this->stale_commit;
	}
	/**
	 * Complete failure.
	 *
	 * @param Submission_Evaluation_Work_Item             $item Item.
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
	public function complete_failure( Submission_Evaluation_Work_Item $item, string $attempt_state, string $error_code, ?string $gateway_correlation_id, ?string $provider_output_digest, ?int $duration_ms, ?Submission_Evaluation_Routing_Snapshot $actual_routing, bool $retry, string $available_at_utc, string $audit_public_id, string $outbox_public_id, string $finished_at_utc, ?string $error_message = null ): bool {
		unset( $audit_public_id, $outbox_public_id );
		$this->failure = compact( 'item', 'attempt_state', 'error_code', 'gateway_correlation_id', 'provider_output_digest', 'duration_ms', 'actual_routing', 'retry', 'available_at_utc', 'finished_at_utc', 'error_message' );
		return ! $this->stale_commit;
	}
}

/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 */
final class G4_S02_Execution implements Submission_Evaluation_Execution_Port_Interface {
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
	 * Initialize g4 s02 execution dependencies and state.
	 *
	 * @param array<int,Submission_Evaluation_Routing_Snapshot|null> $snapshots Snapshots.
	 * @param Submission_Evaluation_Gateway_Result                   $result Result.
	 */
	public function __construct( private array $snapshots, public Submission_Evaluation_Gateway_Result $result ) {
	}
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Submission_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Submission_Evaluation_Routing_Snapshot {
		unset( $task_key );
		++$this->snapshot_calls;
		return array_shift( $this->snapshots );
	}
	/**
	 * Execute g4 s02 execution.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return Submission_Evaluation_Gateway_Result
	 */
	public function execute( Submission_Evaluation_Work_Item $item ): Submission_Evaluation_Gateway_Result {
		unset( $item );
		++$this->execute_calls;
		return $this->result;
	}
}

/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 */
final class G4_S02_Consent implements Submission_Evaluation_Execution_Consent_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s02 consent dependencies and state.
	 *
	 * @param array<int,bool> $decisions Decisions.
	 */
	public function __construct( private array $decisions = array( true, true ) ) {
	}
	/**
	 * Allows.
	 *
	 * @param Submission_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Submission_Evaluation_Work_Item $item ): bool {
		unset( $item );
		++$this->calls;
		return array() === $this->decisions ? true : (bool) array_shift( $this->decisions );
	}
}

/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 */
final class G4_S02_Source_Port implements Submission_Evaluation_Source_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s02 source port dependencies and state.
	 *
	 * @param Submission_Evaluation_Source $source Source.
	 * @param array<int,bool>              $decisions Decisions.
	 */
	public function __construct( private Submission_Evaluation_Source $source, private array $decisions = array( true, true ) ) {
	}
	/**
	 * Find official.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $official_revision_id Official revision id.
	 * @return Submission_Evaluation_Source|null
	 */
	public function find_official( string $submission_id, string $official_revision_id, bool $manual = false ): ?Submission_Evaluation_Source {
		++$this->calls;
		$allowed = array() === $this->decisions ? true : (bool) array_shift( $this->decisions );
		return $allowed && $submission_id === $this->source->submission_id() && $official_revision_id === $this->source->official_revision_id() ? $this->source : null;
	}
}

/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 */
final class G4_S02_Policy implements Access_Policy_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize g4 s02 policy dependencies and state.
	 *
	 * @param array<int,bool> $decisions Decisions.
	 */
	public function __construct( private array $decisions = array( true, true ) ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		++$this->calls;
		g4_s02_same( Run_Next_Submission_Evaluation::ACTION_EXECUTE, $request->action_key(), 'Worker action drifted.' );
		g4_s02_same( array( Addon_Capabilities::ASSESS_SUBMISSIONS ), $request->required_capabilities(), 'Worker capability drifted.' );
		$allowed = array() === $this->decisions ? true : (bool) array_shift( $this->decisions );
		return $allowed
		? Access_Policy_Decision::allowed( $request->correlation_id(), null, Object_Authorization_Result::allowed( '7', 'submitted', 'context_staff' ) )
		: Access_Policy_Decision::denied( 403, 'worker_authorization_revoked', $request->correlation_id() );
	}
}

/**
 * G4 s02 worker scenario.
 *
 * @param array                                                       $fixture Fixture.
 * @param Canonical_Json                                              $canonical Canonical JSON encoder used for stable digests.
 * @param Submission_Evaluation_Routing_Snapshot                      $route Route.
 * @param Submission_Evaluation_Gateway_Result                        $gateway Gateway.
 * @param array<int,Submission_Evaluation_Routing_Snapshot|null>|null $snapshots Snapshots.
 * @param array<int,bool>                                             $consent Consent.
 * @param array<int,bool>                                             $policy Policy.
 * @param array<int,bool>                                             $sources Sources.
 * @return array
 */
function g4_s02_worker_scenario( array $fixture, Canonical_Json $canonical, Submission_Evaluation_Routing_Snapshot $route, Submission_Evaluation_Gateway_Result $gateway, ?array $snapshots = null, array $consent = array( true, true ), array $policy = array( true, true ), array $sources = array( true, true ) ): array {
	$item         = g4_s02_item( $fixture['input'], $route );
	$repository   = new G4_S02_Work_Repository( $item );
	$execution    = new G4_S02_Execution( $snapshots ?? array( $route, $route ), $gateway );
	$consent_port = new G4_S02_Consent( $consent );
	$source_port  = new G4_S02_Source_Port( g4_s02_source( $item ), $sources );
	$policy_port  = new G4_S02_Policy( $policy );
	$worker       = new Run_Next_Submission_Evaluation(
		$repository,
		$execution,
		$consent_port,
		$source_port,
		$policy_port,
		new Submission_Evaluation_Output_Normalizer( $canonical ),
		g4_s02_ids( 600 ),
		static fn (): string => '2026-08-23 12:00:00',
		static fn (): string => '123e4567-e89b-42d3-a456-426614174001'
	);
	return compact( 'repository', 'execution', 'consent_port', 'source_port', 'policy_port', 'worker' );
}

/**
 * G4-IMP-02-S02 student submission evaluation worker contract.
 */
final class G4_S02_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Insert id.
	 *
	 * @var int
	 */
	public int $insert_id = 0;
	/**
	 * Tables.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $tables;
	/**
	 * Fail insert table.
	 *
	 * @var string|null
	 */
	public ?string $fail_insert_table = null;
	/**
	 * Snapshot.
	 *
	 * @var array<string,array<int,array<string,mixed>>>|null
	 */
	private ?array $snapshot = null;
	/**
	 * Initialize g4 s02 database dependencies and state.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $tables Tables.
	 */
	public function __construct( array $tables ) {
		$this->tables = $tables;
	}
	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$values Values.
	 * @return string
	 */
	public function prepare( string $sql, ...$values ): string {
		foreach ( $values as $value ) {
			if ( 1 !== preg_match( '/%(s|d)/', $sql, $placeholder ) ) {
				return '';
			}
			$replacement = 'd' === $placeholder[1] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql         = preg_replace( '/%' . $placeholder[1] . '/', $replacement, $sql, 1 ) ?? '';
		}
		return $sql;
	}
	/**
	 * Get row.
	 *
	 * @param string $sql Sql.
	 * @param string $format Format.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $sql, string $format ): ?array {
		unset( $format );
		$this->last_error = '';
		$run              = $this->tables['oj_ai_evaluation_runs'][0];
		$evaluation       = $this->tables['oj_ai_submission_evaluations'][0];
		if ( str_contains( $sql, 'SELECT r.id, r.state, r.lock_version' ) ) {
			return array(
				'id'               => $run['id'],
				'state'            => $run['state'],
				'lock_version'     => $run['lock_version'],
				'lease_token'      => $run['lease_token'],
				'result_public_id' => $run['result_public_id'],
				'evaluation_state' => $evaluation['state'],
			);
		}
		if ( str_contains( $sql, 'SELECT id FROM oj_ai_submission_evaluation_results' ) ) {
			return array() === $this->tables['oj_ai_submission_evaluation_results'] ? null : array( 'id' => $this->tables['oj_ai_submission_evaluation_results'][0]['id'] );
		}
		if ( str_contains( $sql, 'SELECT r.state AS run_state' ) ) {
			$result = $this->tables['oj_ai_submission_evaluation_results'][0] ?? null;
			return null === $result ? null : array(
				'run_state'        => $run['state'],
				'result_public_id' => $run['result_public_id'],
				'evaluation_state' => $evaluation['state'],
				'stored_result_id' => $result['public_id'],
			);
		}
		$this->last_error = 'unexpected get_row';
		return null;
	}
	/**
	 * Get var.
	 *
	 * @param string $sql Sql.
	 * @return mixed
	 */
	public function get_var( string $sql ) {
		$this->last_error = '';
		if ( ! str_contains( $sql, 'SELECT event_hash FROM oj_ai_audit_events' ) ) {
			$this->last_error = 'unexpected get_var';
			return null;
		}
		$events = $this->tables['oj_ai_audit_events'];
		return array() === $events ? null : $events[ count( $events ) - 1 ]['event_hash'];
	}
	/**
	 * Query.
	 *
	 * @param string $sql Sql.
	 * @return int|false
	 */
	public function query( string $sql ): int|false {
		$this->last_error = '';
		if ( 'START TRANSACTION' === $sql ) {
			$this->snapshot = unserialize( serialize( $this->tables ) );
			return 0;
		}
		if ( 'COMMIT' === $sql ) {
			$this->snapshot = null;
			return 0;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null !== $this->snapshot ) {
				$this->tables = $this->snapshot;
			} $this->snapshot = null;
			return 0;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_evaluation_attempts' ) ) {
			if ( ! preg_match( "/SET state = '([^']+)'/", $sql, $match ) ) {
				return false;
			}
			$this->tables['oj_ai_evaluation_attempts'][0]['state']           = $match[1];
			$this->tables['oj_ai_evaluation_attempts'][0]['finished_at_utc'] = '2026-08-23 12:00:00';
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_evaluation_runs' ) ) {
			if ( ! preg_match( "/SET state = '([^']+)'/", $sql, $match ) ) {
				return false;
			}
			$this->tables['oj_ai_evaluation_runs'][0]['state'] = $match[1];
			++$this->tables['oj_ai_evaluation_runs'][0]['lock_version'];
			$this->tables['oj_ai_evaluation_runs'][0]['lease_token'] = null;
			if ( preg_match( "/result_public_id = '([^']+)'/", $sql, $result ) ) {
				$this->tables['oj_ai_evaluation_runs'][0]['result_public_id'] = $result[1];
			}
			return 1;
		}
		if ( str_starts_with( $sql, 'UPDATE oj_ai_submission_evaluations' ) ) {
			if ( ! preg_match( "/SET state = '([^']+)'/", $sql, $match ) ) {
				return false;
			}
			$this->tables['oj_ai_submission_evaluations'][0]['state'] = $match[1];
			++$this->tables['oj_ai_submission_evaluations'][0]['lock_version'];
			return 1;
		}
		$this->last_error = 'unexpected query';
		return false;
	}
	/**
	 * Insert.
	 *
	 * @param string              $table Table.
	 * @param array<string,mixed> $data Data.
	 * @return int|false
	 */
	public function insert( string $table, array $data ): int|false {
		$this->last_error = '';
		if ( $table === $this->fail_insert_table ) {
			$this->last_error = 'forced insert failure';
			return false;
		}
		$data['id']               = count( $this->tables[ $table ] ) + 1;
		$this->tables[ $table ][] = $data;
		$this->insert_id          = $data['id'];
		return 1;
	}
}

/**
 * G4 s02 tables.
 *
 * @param Submission_Evaluation_Work_Item $item Item.
 * @return array<string,array<int,array<string,mixed>>>
 */
function g4_s02_tables( Submission_Evaluation_Work_Item $item ): array {
	return array(
		'oj_ai_submission_evaluations'        => array(
			array(
				'id'           => $item->evaluation_pk(),
				'public_id'    => $item->evaluation_id(),
				'state'        => 'running',
				'lock_version' => 1,
			),
		),
		'oj_ai_evaluation_runs'               => array(
			array(
				'id'                   => $item->run_pk(),
				'public_id'            => $item->run_id(),
				'evaluation_public_id' => $item->evaluation_id(),
				'state'                => 'running',
				'lock_version'         => $item->lock_version(),
				'lease_token'          => $item->lease_token(),
				'result_public_id'     => null,
			),
		),
		'oj_ai_evaluation_attempts'           => array(
			array(
				'id'                => 1,
				'evaluation_run_id' => $item->run_pk(),
				'attempt_no'        => 1,
				'state'             => 'started',
			),
		),
		'oj_ai_submission_evaluation_results' => array(),
		'oj_ai_submission_evaluation_items'   => array(),
		'oj_ai_audit_events'                  => array(),
		'oj_ai_outbox_events'                 => array(),
	);
}

$canonical  = new Canonical_Json();
$fixture    = g4_s02_fixture( $canonical );
$normalizer = new Submission_Evaluation_Output_Normalizer( $canonical );
$normalized = $normalizer->normalize( $fixture['output'], $fixture['input'] );
g4_s02_same( '7.5000', $normalized->candidate_score(), 'Candidate score normalization drifted.' );
g4_s02_same( '10.0000', $normalized->maximum_score(), 'Maximum score join drifted.' );
g4_s02_same( '0.8000', $normalized->overall_confidence(), 'Confidence normalization drifted.' );
g4_s02_same( false, $normalized->abstained(), 'A complete score must not be marked abstained.' );
g4_s02_same( 1, count( $normalized->items() ), 'Every expected binding must produce one stored item.' );
g4_s02_assert( ! str_contains( $normalized->normalized_json(), 'raw_response' ), 'Raw provider output must never be stored.' );
g4_s02_same( $normalized->normalized_hash(), $normalizer->normalize( $fixture['output'], $fixture['input'] )->normalized_hash(), 'Normalization must be deterministic.' );

$abstain             = $fixture['output'];
$abstain['items'][0] = array(
	'binding_key'      => $fixture['binding_key'],
	'decision'         => 'abstain',
	'candidate_points' => null,
	'confidence'       => 0.2,
	'abstain_reason'   => 'insufficient_evidence',
	'evidence'         => array(),
	'flags'            => array( 'teacher_review_required' ),
);
$abstained           = $normalizer->normalize( $abstain, $fixture['input'] );
g4_s02_same( null, $abstained->candidate_score(), 'Abstain must never become zero.' );
g4_s02_same( true, $abstained->abstained(), 'Abstain state was not preserved.' );

$invalid                                 = $fixture['output'];
$invalid['items'][0]['candidate_points'] = '10.0001';
g4_s02_failure( static fn () => $normalizer->normalize( $invalid, $fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Over-maximum score must fail closed.' );
$invalid                                      = $fixture['output'];
$invalid['items'][0]['evidence'][0]['anchor'] = 'response:' . g4_s02_id();
$unverified                                   = $normalizer->normalize( $invalid, $fixture['input'] );
g4_s02_same( null, $unverified->candidate_score(), 'Unknown evidence must withhold the score, not block teacher review.' );
g4_s02_same( array(), $unverified->items()[0]['evidence']['evidence'], 'Unknown evidence must never enter storage.' );
$invalid                      = $fixture['output'];
$invalid['items'][0]['flags'] = array( 'free_form_flag' );
g4_s02_failure( static fn () => $normalizer->normalize( $invalid, $fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Unknown flag must fail closed.' );
$invalid                 = $fixture['output'];
$invalid['raw_response'] = 'private';
g4_s02_failure( static fn () => $normalizer->normalize( $invalid, $fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_PRIVACY_INVALID, 'Forbidden output fields must fail closed.' );
$invalid            = $fixture['output'];
$invalid['summary'] = str_repeat( 'x', Submission_Evaluation_Output_Normalizer::MAXIMUM_OUTPUT_BYTES );
g4_s02_failure( static fn () => $normalizer->normalize( $invalid, $fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_TOO_LARGE, 'Oversize output must fail closed.' );

$route    = g4_s02_route();
$gateway  = Submission_Evaluation_Gateway_Result::success( $fixture['output'], $route, 'gateway-correlation-g4-s02', 125 );
$scenario = g4_s02_worker_scenario( $fixture, $canonical, $route, $gateway );
g4_s02_same( Run_Submission_Evaluation_Result::SUCCEEDED, $scenario['worker']->run_once()->outcome(), 'Valid worker run must succeed.' );
g4_s02_same( 1, $scenario['execution']->execute_calls, 'Worker must execute the Gateway exactly once.' );
g4_s02_same( 2, $scenario['execution']->snapshot_calls, 'Worker must perform routing preflight and postflight.' );
g4_s02_same( 2, $scenario['policy_port']->calls, 'Worker must reauthorize before and after execution.' );
g4_s02_same( 2, $scenario['source_port']->calls, 'Worker must recheck source before and after execution.' );
g4_s02_same( 2, $scenario['consent_port']->calls, 'Worker must recheck consent before and after execution.' );

$scenario = g4_s02_worker_scenario( $fixture, $canonical, $route, $gateway, null, array( false ) );
g4_s02_same( Run_Submission_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Missing submission consent must fail.' );
g4_s02_same( 0, $scenario['execution']->execute_calls, 'Missing consent must prevent Gateway execution.' );
g4_s02_same( 'external_processing_not_approved', $scenario['repository']->failure['error_code'], 'Consent failure code drifted.' );

$changed_route = g4_s02_route( str_repeat( '8', 64 ) );
$scenario      = g4_s02_worker_scenario( $fixture, $canonical, $route, $gateway, array( $route, $changed_route ) );
g4_s02_same( Run_Submission_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Routing drift must discard output.' );
g4_s02_same( 'routing_changed_during_execute', $scenario['repository']->failure['error_code'], 'Routing drift code changed.' );
g4_s02_assert( is_string( $scenario['repository']->failure['provider_output_digest'] ), 'Discarded valid output must retain only its digest.' );
g4_s02_same( null, $scenario['repository']->success, 'Routing drift must never commit a candidate result.' );

$scenario = g4_s02_worker_scenario( $fixture, $canonical, $route, $gateway, null, array( true, false ) );
g4_s02_same( Run_Submission_Evaluation_Result::FAILED, $scenario['worker']->run_once()->outcome(), 'Revoked postflight consent must discard output.' );
g4_s02_same( 'external_processing_not_approved', $scenario['repository']->failure['error_code'], 'Postflight consent code drifted.' );

$retry_gateway = Submission_Evaluation_Gateway_Result::failure( 'addon_ai_task_failed', true, 'gateway-correlation-retry', 80 );
$scenario      = g4_s02_worker_scenario( $fixture, $canonical, $route, $retry_gateway );
g4_s02_same( Run_Submission_Evaluation_Result::RETRY_WAIT, $scenario['worker']->run_once()->outcome(), 'Retryable Gateway failure must wait.' );
g4_s02_same( '2026-08-23 12:01:00', $scenario['repository']->failure['available_at_utc'], 'First retry backoff must be 60 seconds.' );

$consent_item                     = g4_s02_item( $fixture['input'], $route );
$unsigned_consent                 = array(
	'schema_id'           => Submission_External_Ai_Consent_Record::SCHEMA,
	'schema_version'      => Submission_External_Ai_Consent_Record::VERSION,
	'state'               => 'approved',
	'task_key'            => Addon_Manifest::TASK_STUDENT_ASSESSMENT,
	'destination'         => array(
		'provider_key'        => $route->provider_key(),
		'model_key'           => $route->model_key(),
		'profile_fingerprint' => $route->profile_fingerprint(),
	),
	'input_categories'    => Submission_External_Ai_Consent_Record::DATA_CATEGORIES,
	'approved_by_user_id' => 42,
	'approved_at_utc'     => '2026-08-23 11:30:00',
);
$approved_consent                 = $unsigned_consent;
$approved_consent['consent_hash'] = hash( 'sha256', $canonical->encode( $unsigned_consent ) );
$consent                          = new WordPress_Submission_Evaluation_Execution_Consent( $canonical, static fn () => $approved_consent );
g4_s02_same( true, $consent->allows( $consent_item ), 'Exact task-specific consent must allow execution.' );
$missing_consent = new WordPress_Submission_Evaluation_Execution_Consent( $canonical, static fn () => null );
g4_s02_same( false, $missing_consent->allows( $consent_item ), 'Submission consent must default to deny.' );

$running_item       = g4_s02_item( $fixture['input'], $route, 'running' );
$abstain_database   = new G4_S02_Database( g4_s02_tables( $running_item ) );
$abstain_repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $abstain_database );
$abstain_gateway    = Submission_Evaluation_Gateway_Result::success( $abstain, $route, 'gateway-correlation-abstain', 90 );
g4_s02_same(
	true,
	$abstain_repository->complete_success( $running_item, $abstain_gateway, $abstained, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:00' ),
	'Abstained candidate result transaction must commit.'
);
g4_s02_same( null, $abstain_database->tables['oj_ai_submission_evaluation_results'][0]['candidate_score'], 'Abstain candidate score must remain SQL NULL.' );
g4_s02_same( 1, $abstain_database->tables['oj_ai_submission_evaluation_results'][0]['abstained'], 'Abstain flag must be persisted.' );
g4_s02_same( null, $abstain_database->tables['oj_ai_submission_evaluation_items'][0]['candidate_points'], 'Abstained item points must remain SQL NULL.' );

$cancelled_tables = g4_s02_tables( $running_item );
$cancelled_tables['oj_ai_submission_evaluations'][0]['state'] = 'cancelled';
$cancelled_tables['oj_ai_evaluation_runs'][0]['state']        = 'cancelled';
$cancelled_tables['oj_ai_evaluation_runs'][0]['lock_version'] = $running_item->lock_version() + 1;
$cancelled_tables['oj_ai_evaluation_runs'][0]['lease_token']  = null;
$cancelled_database   = new G4_S02_Database( $cancelled_tables );
$cancelled_repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $cancelled_database );
g4_s02_same(
	false,
	$cancelled_repository->complete_success( $running_item, $gateway, $normalized, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:00' ),
	'Late worker output after cancellation must lose the CAS race.'
);
g4_s02_same( 0, count( $cancelled_database->tables['oj_ai_submission_evaluation_results'] ), 'Cancellation race must persist zero candidate results.' );

$database   = new G4_S02_Database( g4_s02_tables( $running_item ) );
$repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $database );
$committed  = $repository->complete_success( $running_item, $gateway, $normalized, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:00' );
g4_s02_same( true, $committed, 'Official candidate result transaction must commit.' );
g4_s02_same( 1, count( $database->tables['oj_ai_submission_evaluation_results'] ), 'Result must be inserted exactly once.' );
g4_s02_same( 1, count( $database->tables['oj_ai_submission_evaluation_items'] ), 'Binding item must be inserted exactly once.' );
g4_s02_same( 'succeeded', $database->tables['oj_ai_evaluation_runs'][0]['state'], 'Run success state drifted.' );
g4_s02_same( 'review_required', $database->tables['oj_ai_submission_evaluations'][0]['state'], 'Evaluation must remain teacher-review gated.' );
g4_s02_same( 1, count( $database->tables['oj_ai_audit_events'] ), 'Success audit must share the transaction.' );
g4_s02_same( 1, count( $database->tables['oj_ai_outbox_events'] ), 'Review-required outbox must share the transaction.' );
g4_s02_same( false, $repository->complete_success( $running_item, $gateway, $normalized, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:01' ), 'Late duplicate commit must affect zero rows.' );

$rollback_database                    = new G4_S02_Database( g4_s02_tables( $running_item ) );
$rollback_database->fail_insert_table = 'oj_ai_submission_evaluation_items';
$rollback_repository                  = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $rollback_database );
g4_s02_failure(
	static fn () => $rollback_repository->complete_success( $running_item, $gateway, $normalized, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:00' ),
	Submission_Evaluation_Worker_Failure::STORAGE_UNAVAILABLE,
	'Partial result insert must roll back.'
);
g4_s02_same( 0, count( $rollback_database->tables['oj_ai_submission_evaluation_results'] ), 'Rollback must remove the partial result.' );
g4_s02_same( 'running', $rollback_database->tables['oj_ai_evaluation_runs'][0]['state'], 'Rollback must preserve the leased run.' );

$failure_database   = new G4_S02_Database( g4_s02_tables( $running_item ) );
$failure_repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $failure_database );
$failed             = $failure_repository->complete_failure(
	$running_item,
	'gateway_failed',
	'addon_ai_task_failed',
	'gateway-correlation-retry',
	null,
	80,
	$route,
	true,
	'2026-08-23 12:01:00',
	g4_s02_id(),
	g4_s02_id(),
	'2026-08-23 12:00:00'
);
g4_s02_same( true, $failed, 'Retry transition must commit.' );
g4_s02_same( 'retry_wait', $failure_database->tables['oj_ai_evaluation_runs'][0]['state'], 'Run retry state drifted.' );
g4_s02_same( 'queued', $failure_database->tables['oj_ai_submission_evaluations'][0]['state'], 'Retry must return evaluation to queued.' );
g4_s02_same( 0, count( $failure_database->tables['oj_ai_submission_evaluation_results'] ), 'Failure must never create a result.' );

$reference_fixture = g4_s02_fixture( $canonical );
$reference_input   = $reference_fixture['input'];
$reference_input['operation_payload']['bindings'][0]['binding_ref'] = 'B1';
$second_binding                                     = $reference_input['operation_payload']['bindings'][0];
$second_binding['binding_key']                      = hash( 'sha256', 'second binding' );
$second_binding['binding_ref']                      = 'B2';
$second_binding['criterion_id']                     = g4_s02_id();
$second_binding['position']                         = 1;
$second_binding['scope']                            = 'submission';
$second_binding['response_id']                      = null;
$reference_input['operation_payload']['bindings'][] = $second_binding;
$rehash_input                                       = static function ( array $value ) use ( $canonical ): array {
	$value['payload_hash'] = hash( 'sha256', $canonical->encode( $value['operation_payload'] ) );
	return $value;
};
$reference_input                                    = $rehash_input( $reference_input );
$reference_output                                   = $reference_fixture['output'];
$reference_output['items'][0]['binding_key']        = 'B1';
$second_item                                        = $reference_output['items'][0];
$second_item['binding_key']                         = 'B2';
$second_item['candidate_points']                    = '3';
$reference_output['items'][]                        = $second_item;
$reference_output['items']                          = array_reverse( $reference_output['items'] );
$reference_result                                   = $normalizer->normalize( $reference_output, $reference_input );
$stored_by_key                                      = array_column( json_decode( $reference_result->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['items'], null, 'binding_key' );
g4_s02_same( '7.5000', $stored_by_key[ $reference_fixture['binding_key'] ]['candidate_points'], 'Reordered references must preserve the response score.' );
g4_s02_same( '3.0000', $stored_by_key[ $second_binding['binding_key'] ]['candidate_points'], 'Submission-wide score must resolve by reference, never output order.' );
g4_s02_same( '10.5000', $reference_result->candidate_score(), 'References must not alter scoring arithmetic.' );

$legacy_output                            = $reference_output;
$legacy_output['items'][0]['binding_key'] = $second_binding['binding_key'];
$legacy_output['items'][1]['binding_key'] = $reference_fixture['binding_key'];
g4_s02_same( $reference_result->normalized_hash(), $normalizer->normalize( $legacy_output, $reference_input )->normalized_hash(), 'Exact canonical keys must remain compatible.' );

foreach ( array( 'B99', 'b2', 'B02', ' B2 ', 2, $second_binding['criterion_id'], hash( 'sha256', 'unknown AI key' ) ) as $unknown_key ) {
	$invalid_output                            = $reference_output;
	$invalid_output['items'][0]['binding_key'] = $unknown_key;
	g4_s02_failure( static fn () => $normalizer->normalize( $invalid_output, $reference_input ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Unknown keys must fail even on the first output item.' );
}
foreach ( array( 'B1', $reference_fixture['binding_key'] ) as $duplicate_key ) {
	$invalid_output                            = $reference_output;
	$invalid_output['items'][0]['binding_key'] = $duplicate_key;
	g4_s02_failure( static fn () => $normalizer->normalize( $invalid_output, $reference_input ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Canonical and alias forms of one binding must count as duplicate.' );
}
$missing_output = $reference_output;
array_pop( $missing_output['items'] );
g4_s02_failure( static fn () => $normalizer->normalize( $missing_output, $reference_input ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'Missing items must fail.' );
$old_output                            = $reference_fixture['output'];
$old_output['items'][0]['binding_key'] = 'B1';
g4_s02_failure( static fn () => $normalizer->normalize( $old_output, $reference_fixture['input'] ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Never infer references for historical inputs.' );
foreach ( array( 'binding_ref', 'binding_key', 'criterion_id' ) as $duplicate_field ) {
	$invalid_input = $reference_input;
	$invalid_input['operation_payload']['bindings'][1][ $duplicate_field ] = $invalid_input['operation_payload']['bindings'][0][ $duplicate_field ];
	$invalid_input = $rehash_input( $invalid_input );
	g4_s02_failure( static fn () => $normalizer->normalize( $reference_output, $invalid_input ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Input references and canonical identities must be unique independently.' );
}
$tampered_input = $reference_input;
$tampered_input['operation_payload']['bindings'][0]['binding_ref'] = 'B99';
g4_s02_failure( static fn () => $normalizer->normalize( $reference_output, $tampered_input ), Submission_Evaluation_Worker_Failure::OUTPUT_DOMAIN_INVALID, 'Input hash must protect the reference map.' );
$wrong_source                = $reference_output;
$wrong_source['source_hash'] = hash( 'sha256', 'another submission' );
g4_s02_failure( static fn () => $normalizer->normalize( $wrong_source, $reference_input ), Submission_Evaluation_Worker_Failure::OUTPUT_SCHEMA_INVALID, 'References must never cross submission snapshots.' );
$wrong_evidence                                      = $reference_output;
$wrong_evidence['items'][1]['evidence'][0]['anchor'] = 'response:' . g4_s02_id();
$partial_evidence                                    = $normalizer->normalize( $wrong_evidence, $reference_input );
g4_s02_same( null, $partial_evidence->candidate_score(), 'A partial result must not fabricate a total.' );
g4_s02_same( null, $partial_evidence->items()[0]['candidate_points'], 'The unverified binding must have no AI score.' );
g4_s02_same( '3.0000', $partial_evidence->items()[1]['candidate_points'], 'The independently verified binding must retain its score.' );
g4_s02_same( 'partial', json_decode( $partial_evidence->normalized_json(), true, 64, JSON_THROW_ON_ERROR )['provider_status'], 'Mixed outcomes must enter partial review.' );

$unverified_output                                      = $fixture['output'];
$unverified_output['items'][0]['evidence'][0]['anchor'] = 'response:' . g4_s02_id();
$unverified_output['items'][0]['evidence'][0]['quote']  = 'fabricated quote';
$unverified_result                                      = $normalizer->normalize( $unverified_output, $fixture['input'] );
$unverified_gateway                                     = Submission_Evaluation_Gateway_Result::success( $unverified_output, $route, 'gateway-unverified', 90 );
$unverified_scenario                                    = g4_s02_worker_scenario( $fixture, $canonical, $route, $unverified_gateway );
g4_s02_same( Run_Submission_Evaluation_Result::SUCCEEDED, $unverified_scenario['worker']->run_once()->outcome(), 'Unverified evidence should produce a reviewable result, not a failed queue job.' );
g4_s02_same( 1, $unverified_scenario['execution']->execute_calls, 'Review recovery must not issue extra AI calls.' );

$unverified_database   = new G4_S02_Database( g4_s02_tables( $running_item ) );
$unverified_repository = new WordPress_Submission_Evaluation_Work_Repository( $canonical, $unverified_database );
g4_s02_same( true, $unverified_repository->complete_success( $running_item, $unverified_gateway, $unverified_result, g4_s02_id(), g4_s02_id(), g4_s02_id(), '2026-08-23 12:00:00' ), 'Unverified item recovery must commit atomically.' );
g4_s02_same( 'review_required', $unverified_database->tables['oj_ai_submission_evaluations'][0]['state'], 'Recovery must wait for teacher review.' );
g4_s02_same( null, $unverified_database->tables['oj_ai_submission_evaluation_items'][0]['candidate_points'], 'Unverified scores must remain SQL NULL.' );
g4_s02_same( false, str_contains( json_encode( $unverified_database->tables, JSON_THROW_ON_ERROR ), 'fabricated quote' ), 'Unverified citation text must not enter result storage.' );

fwrite( STDOUT, "G4-IMP-02-S02 passed: strict output, request-local references, historical hashes, consent, retries and exactly-once result transactions.\n" );
