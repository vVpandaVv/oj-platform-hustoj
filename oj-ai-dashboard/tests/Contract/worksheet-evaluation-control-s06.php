<?php
/**
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Current_Actor;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Nonce_Verifier;
use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Request_Authorizer;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Cancel_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Cancel_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Retry_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Retry_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Control_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Input_Builder;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Configuration_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Presentation\Worksheet_Evaluation_Control_Rest_Controller;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
	 */
	final class Normalizer {
		public const FORM_C = 4;
		/**
		 * Normalize.
		 *
		 * @param string $value Value.
		 * @param int    $form Form.
		 * @return string
		 */
		public static function normalize( string $value, int $form = self::FORM_C ): string {
			unset( $form );
			return $value;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
	 */
	final class WP_REST_Response {
		/**
		 * Initialize wp rest response dependencies and state.
		 *
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( private $data = null, private int $status = 200 ) {
		}
		/**
		 * Get data.
		 *
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
		/**
		 * Get status.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
	 */
	final class WP_Error {
		/**
		 * Initialize wp error dependencies and state.
		 *
		 * @param string $code Code.
		 * @param string $message Message.
		 * @param mixed  $data Data.
		 */
		public function __construct( private string $code, private string $message, private $data = array() ) {
		}
		/**
		 * Get error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}
		/**
		 * Get error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}
		/**
		 * Get error data.
		 *
		 * @return mixed
		 */
		public function get_error_data() {
			return $this->data;
		}
	}
}

$GLOBALS['eval_s06_actor']       = 42;
$GLOBALS['eval_s06_nonce_calls'] = 0;
$GLOBALS['eval_s06_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['eval_s06_actor'];
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	/**
	 * Wp verify nonce.
	 *
	 * @param string $nonce Nonce.
	 * @param string $action Action.
	 * @return mixed
	 */
	function wp_verify_nonce( string $nonce, string $action ) {
		++$GLOBALS['eval_s06_nonce_calls'];
		return 'valid-s06-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
	}
}

if ( ! function_exists( 'register_rest_route' ) ) {
	/**
	 * Register rest route.
	 *
	 * @param string              $namespace Namespace.
	 * @param string              $route Route.
	 * @param array<string,mixed> $arguments Arguments.
	 * @return bool
	 */
	function register_rest_route( string $namespace, string $route, array $arguments ): bool {
		$GLOBALS['eval_s06_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Core'
	. DIRECTORY_SEPARATOR . 'Autoloader.php';
( new AddonAutoloader( $addon_root . DIRECTORY_SEPARATOR . 'src' ) )->register();

/**
 * Eval s06 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s06_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * Eval s06 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s06_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException(
			$message . "\nExpected: " . var_export( $expected, true )
			. "\nActual: " . var_export( $actual, true )
		);
	}
}

/**
 * Eval s06 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s06_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback();
	} catch ( Worksheet_Evaluation_Failure $failure ) {
		eval_s06_same( $reason, $failure->reason(), $message );
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s06 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function eval_s06_invalid( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( InvalidArgumentException ) {
		return;
	}
	throw new RuntimeException( $message );
}

/**
 * Eval s06 id.
 *
 * @return string
 */
function eval_s06_id(): string {
	static $suffix = 6000;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	eval_s06_assert( Public_Id::is_valid( $id ), 'S06 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * Eval s06 configuration.
 *
 * @return array<string,mixed>
 */
function eval_s06_configuration(): array {
	$dimensions = array();
	foreach ( Worksheet_Evaluation_Input_Builder::CORE_DIMENSIONS as $dimension ) {
		$dimensions[] = array(
			'dimension_key'     => $dimension,
			'label'             => $dimension,
			'description'       => 'Assess this dimension using worksheet evidence.',
			'evidence_required' => true,
		);
	}
	return array(
		'policy'         => array(
			'revision_id'  => eval_s06_id(),
			'content_hash' => str_repeat( 'b', 64 ),
			'subject_type' => 'worksheet_quality',
		),
		'rubric'         => array(
			'revision_id'  => eval_s06_id(),
			'content_hash' => str_repeat( 'c', 64 ),
			'dimensions'   => $dimensions,
		),
		'grading_rubric' => array(
			'assessment_enabled' => true,
			'revision_id'        => eval_s06_id(),
			'content_hash'       => str_repeat( 'a', 64 ),
			'total_points'       => '10',
			'criteria'           => array(
				array(
					'criterion_id'   => eval_s06_id(),
					'label'          => 'Evidence and reasoning',
					'description'    => 'Check whether the submitted work uses relevant evidence and explains the reasoning.',
					'maximum_points' => '10',
					'position'       => 0,
					'scope'          => 'submission',
					'levels'         => array(
						array(
							'label'     => 'Uses relevant evidence and explains the reasoning.',
							'level_key' => eval_s06_id(),
							'points'    => '10',
							'position'  => 0,
						),
						array(
							'label'     => 'Evidence or reasoning is missing.',
							'level_key' => eval_s06_id(),
							'points'    => '0',
							'position'  => 1,
						),
					),
				),
			),
		),
		'review_rule'    => array(
			'revision_id'               => eval_s06_id(),
			'content_hash'              => str_repeat( 'd', 64 ),
			'review_required'           => true,
			'explicit_release_required' => true,
			'confidence_threshold'      => '0.8',
			'abstain_handling'          => 'require_review',
			'safety_handling'           => 'require_explicit_review',
		),
		'prompt'         => array(
			'contract_version' => Worksheet_Evaluation_Input_Builder::PROMPT_CONTRACT,
			'content_hash'     => Worksheet_Evaluation_Input_Builder::PROMPT_CONTENT_HASH,
		),
		'input_schema'   => array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::REQUEST_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::REQUEST_VERSION,
			'content_hash'   => str_repeat( 'e', 64 ),
		),
		'output_schema'  => array(
			'schema_id'      => Worksheet_Evaluation_Input_Builder::OUTPUT_SCHEMA,
			'schema_version' => Worksheet_Evaluation_Input_Builder::OUTPUT_VERSION,
			'content_hash'   => str_repeat( 'f', 64 ),
		),
	);
}

/**
 * Eval s06 snapshot.
 *
 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
 * @param array          $configuration Configuration.
 * @param string         $state State.
 * @param string         $run_state Run state.
 * @return array<string,mixed>
 */
function eval_s06_snapshot( Canonical_Json $canonical, array $configuration, string $state = 'failed', string $run_state = 'failed' ): array {
	$revision_id   = eval_s06_id();
	$configuration = array_intersect_key( $configuration, array_flip( array( 'policy', 'rubric', 'review_rule', 'prompt', 'input_schema', 'output_schema' ) ) );
	return array(
		'evaluation_state'           => $state,
		'worksheet_revision_id'      => $revision_id,
		'current_revision_id'        => $revision_id,
		'source_hash'                => str_repeat( 'a', 64 ),
		'frozen_configuration_hash'  => hash( 'sha256', $canonical->encode( $configuration ) ),
		'evaluation_identity_digest' => str_repeat( '1', 64 ),
		'input_hash'                 => str_repeat( '2', 64 ),
		'evaluation_lock_version'    => 3,
		'latest_run_id'              => eval_s06_id(),
		'latest_run_no'              => 1,
		'latest_run_state'           => $run_state,
		'latest_run_lock_version'    => 4,
	);
}

/**
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 */
final class Eval_S06_Control_Repository implements Worksheet_Evaluation_Control_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Retry records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $retry_records = array();
	/**
	 * Cancel records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $cancel_records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,operation:string,result:Worksheet_Evaluation_Control_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize eval s06 control repository dependencies and state.
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
	 * @param string $operation Operation.
	 * @return Worksheet_Evaluation_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest, string $operation ): ?Worksheet_Evaluation_Control_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( ! hash_equals( $digest, $receipt['digest'] ) || ! hash_equals( $operation, $receipt['operation'] ) ) {
			throw Worksheet_Evaluation_Failure::because( Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $receipt['result'];
		return new Worksheet_Evaluation_Control_Result(
			$result->operation(),
			$result->evaluation_id(),
			$result->evaluation_state(),
			$result->worksheet_revision_id(),
			$result->source_hash(),
			$result->run_id(),
			$result->run_no(),
			$result->run_state(),
			$result->occurred_at_utc(),
			true
		);
	}

	/**
	 * Find owned snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_owned_snapshot( int $actor_user_id, string $evaluation_id ): ?array {
		return 42 === $actor_user_id && $evaluation_id === ( $this->snapshot['evaluation_id'] ?? null )
			? $this->snapshot
			: null;
	}

	/**
	 * Retry.
	 *
	 * @param array $record Record.
	 * @return Worksheet_Evaluation_Control_Result
	 */
	public function retry( array $record ): Worksheet_Evaluation_Control_Result {
		$this->retry_records[] = $record;
		$result                = new Worksheet_Evaluation_Control_Result(
			Worksheet_Evaluation_Control_Result::OPERATION_RETRY,
			$record['evaluation_public_id'],
			'queued',
			$record['worksheet_revision_public_id'],
			$record['worksheet_revision_hash'],
			$record['run_public_id'],
			$record['run_no'],
			'queued',
			$record['occurred_at_utc'],
			false
		);
		$this->store( $record, $result );
		$this->snapshot['evaluation_state'] = 'queued';
		$this->snapshot['latest_run_id']    = $record['run_public_id'];
		$this->snapshot['latest_run_no']    = $record['run_no'];
		$this->snapshot['latest_run_state'] = 'queued';
		return $result;
	}

	/**
	 * Cancel.
	 *
	 * @param array $record Record.
	 * @return Worksheet_Evaluation_Control_Result
	 */
	public function cancel( array $record ): Worksheet_Evaluation_Control_Result {
		$this->cancel_records[] = $record;
		$result                 = new Worksheet_Evaluation_Control_Result(
			Worksheet_Evaluation_Control_Result::OPERATION_CANCEL,
			$record['evaluation_public_id'],
			'cancelled',
			$record['worksheet_revision_public_id'],
			$record['worksheet_revision_hash'],
			$record['expected_run_public_id'],
			$record['expected_run_no'],
			'cancelled',
			$record['occurred_at_utc'],
			false
		);
		$this->store( $record, $result );
		$this->snapshot['evaluation_state'] = 'cancelled';
		$this->snapshot['latest_run_state'] = 'cancelled';
		return $result;
	}

	/**
	 * Store.
	 *
	 * @param array<string,mixed>                 $record Record.
	 * @param Worksheet_Evaluation_Control_Result $result Result.
	 * @return void
	 */
	private function store( array $record, Worksheet_Evaluation_Control_Result $result ): void {
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest'    => $record['request_digest'],
			'operation' => $result->operation(),
			'result'    => $result,
		);
	}
}

/**
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 */
final class Eval_S06_Configuration implements Worksheet_Evaluation_Configuration_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Initialize eval s06 configuration dependencies and state.
	 *
	 * @param array<string,mixed> $configuration Configuration.
	 */
	public function __construct( public array $configuration ) {
	}
	/**
	 * Active.
	 *
	 * @return array
	 */
	public function active(): array {
		++$this->calls;
		return $this->configuration;
	}
}

/**
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 */
final class Eval_S06_Routing implements Worksheet_Evaluation_Routing_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Plan.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 */
	public function plan(): Worksheet_Evaluation_Routing_Snapshot {
		++$this->calls;
		return new Worksheet_Evaluation_Routing_Snapshot(
			'worksheet_quality_assessment',
			'provider-s06',
			'model-s06',
			str_repeat( '8', 64 ),
			'route-s06-v1'
		);
	}
}

/**
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 */
final class Eval_S06_Queue implements Worksheet_Evaluation_Queue_Wakeup_Port_Interface {
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
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 */
final class Eval_S06_Policy implements Access_Policy_Port_Interface {
	/**
	 * Deny.
	 *
	 * @var bool
	 */
	public bool $deny = false;
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Initialize eval s06 policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $revision_id Revision id.
	 * @param string $state State.
	 */
	public function __construct( private string $evaluation_id, private string $revision_id, public string $state ) {
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		if ( $this->deny ) {
			return Access_Policy_Decision::denied( 404, 'worksheet_evaluation_scope_denied', $request->correlation_id() );
		}
		$object = $request->object();
		if (
			! $request->request_integrity_verified()
			|| 42 !== $request->actor_user_id()
			|| ! in_array( $request->action_key(), array( Worksheet_Evaluation_Control_Rest_Controller::ACTION_RETRY, Worksheet_Evaluation_Control_Rest_Controller::ACTION_CANCEL ), true )
			|| array( Addon_Capabilities::ASSESS_WORKSHEETS ) !== $request->required_capabilities()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_EVALUATION !== $object->type()
			|| $this->evaluation_id !== $object->id()
			|| 'edit' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 404, 'worksheet_evaluation_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			null,
			Object_Authorization_Result::allowed( $this->revision_id, $this->state, 'owner' )
		);
	}
}

/**
 * G2-IMP-05-S06 C11 retry and C12 cancellation contract.
 */
final class Eval_S06_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();
	/**
	 * Initialize eval s06 request dependencies and state.
	 *
	 * @param array<string,mixed> $headers Headers.
	 * @param array<string,mixed> $json Json.
	 * @param string              $evaluation_id Evaluation id.
	 */
	public function __construct( array $headers, private array $json, private string $evaluation_id ) {
		foreach ( $headers as $name => $value ) {
			$this->headers[ strtolower( $name ) ] = $value;
		}
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'POST';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_header( string $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
	/**
	 * Get json params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_json_params(): array {
		return $this->json;
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array( 'evaluation_id' => $this->evaluation_id );
	}
}

/**
 * Eval s06 body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param string $state State.
 * @param string $run_id Run id.
 * @param int    $run_no Run no.
 * @return array<string,mixed>
 */
function eval_s06_body( string $command_id, string $state, string $run_id, int $run_no ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_state'  => $state,
			'expected_run_id' => $run_id,
			'expected_run_no' => $run_no,
		),
		'payload'    => array(),
	);
}

/**
 * Eval s06 request.
 *
 * @param string $key Key.
 * @param array  $body Body.
 * @param string $evaluation_id Evaluation id.
 * @param string $nonce Nonce.
 * @return Eval_S06_Request
 */
function eval_s06_request( string $key, array $body, string $evaluation_id, string $nonce = 'valid-s06-nonce' ): Eval_S06_Request {
	$headers = array( 'X-WP-Nonce' => $nonce );
	if ( '' !== $key ) {
		$headers['Idempotency-Key'] = $key;
	}
	return new Eval_S06_Request( $headers, $body, $evaluation_id );
}

$canonical                 = new Canonical_Json();
$configuration             = eval_s06_configuration();
$evaluation_id             = eval_s06_id();
$snapshot                  = eval_s06_snapshot( $canonical, $configuration );
$snapshot['evaluation_id'] = $evaluation_id;
$repository                = new Eval_S06_Control_Repository( $snapshot );
$configuration_port        = new Eval_S06_Configuration( $configuration );
$routing                   = new Eval_S06_Routing();
$queue                     = new Eval_S06_Queue();
$ids                       = new Public_Id_Generator();
$retry                     = new Retry_Worksheet_Evaluation(
	$repository,
	$configuration_port,
	$routing,
	$queue,
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 14:00:00'
);
$retry_command             = new Retry_Worksheet_Evaluation_Command(
	42,
	$evaluation_id,
	's06-retry-command-0001',
	'failed',
	$snapshot['latest_run_id'],
	1,
	str_repeat( '9', 64 ),
	's06-correlation-retry-0001',
	$snapshot['worksheet_revision_id'],
	'failed',
	'owner',
	$canonical
);
$retry_result              = $retry->execute( $retry_command );
eval_s06_same( 'queued', $retry_result->evaluation_state(), 'C11 must return the evaluation to queued.' );
eval_s06_same( 2, $retry_result->run_no(), 'C11 must append a contiguous run number.' );
eval_s06_same( 1, count( $repository->retry_records ), 'C11 must persist one new run.' );
eval_s06_same( 1, $queue->calls, 'C11 must wake the worker after the durable write.' );
eval_s06_same( str_repeat( '2', 64 ), $repository->retry_records[0]['input_hash'], 'C11 must preserve the frozen input digest.' );
eval_s06_same( 3, $repository->retry_records[0]['expected_evaluation_lock_version'], 'C11 must carry an internal evaluation CAS version.' );
eval_s06_same( 4, $repository->retry_records[0]['expected_run_lock_version'], 'C11 must carry an internal run CAS version.' );
$retry_replay = $retry->execute( $retry_command );
eval_s06_same( true, $retry_replay->replayed(), 'An exact C11 retry must replay the durable receipt.' );
eval_s06_same( 1, count( $repository->retry_records ), 'An exact C11 retry must add zero runs.' );
eval_s06_same( 2, $queue->calls, 'An exact C11 retry may only issue a best-effort wakeup.' );

$drift_snapshot                              = eval_s06_snapshot( $canonical, $configuration );
$drift_snapshot['evaluation_id']             = eval_s06_id();
$drift_snapshot['frozen_configuration_hash'] = str_repeat( '7', 64 );
$drift_repository                            = new Eval_S06_Control_Repository( $drift_snapshot );
$drift_routing                               = new Eval_S06_Routing();
$drift_retry                                 = new Retry_Worksheet_Evaluation(
	$drift_repository,
	new Eval_S06_Configuration( $configuration ),
	$drift_routing,
	new Eval_S06_Queue(),
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 14:01:00'
);
$drift_command                               = new Retry_Worksheet_Evaluation_Command(
	42,
	$drift_snapshot['evaluation_id'],
	's06-retry-command-0002',
	'failed',
	$drift_snapshot['latest_run_id'],
	1,
	str_repeat( '6', 64 ),
	's06-correlation-retry-0002',
	$drift_snapshot['worksheet_revision_id'],
	'failed',
	'owner',
	$canonical
);
eval_s06_failure(
	static fn () => $drift_retry->execute( $drift_command ),
	Worksheet_Evaluation_Failure::CONFIGURATION_CHANGED,
	'C11 must require C10 when the active configuration differs from the frozen evaluation.'
);
eval_s06_same( 0, $drift_routing->calls, 'Configuration drift must fail before routing.' );

$stale_snapshot                        = eval_s06_snapshot( $canonical, $configuration );
$stale_snapshot['evaluation_id']       = eval_s06_id();
$stale_snapshot['current_revision_id'] = eval_s06_id();
$stale_repository                      = new Eval_S06_Control_Repository( $stale_snapshot );
$stale_routing                         = new Eval_S06_Routing();
$stale_retry                           = new Retry_Worksheet_Evaluation(
	$stale_repository,
	new Eval_S06_Configuration( $configuration ),
	$stale_routing,
	new Eval_S06_Queue(),
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 14:02:00'
);
$stale_command                         = new Retry_Worksheet_Evaluation_Command(
	42,
	$stale_snapshot['evaluation_id'],
	's06-retry-command-0003',
	'failed',
	$stale_snapshot['latest_run_id'],
	1,
	str_repeat( '5', 64 ),
	's06-correlation-retry-0003',
	$stale_snapshot['worksheet_revision_id'],
	'failed',
	'owner',
	$canonical
);
eval_s06_failure(
	static fn () => $stale_retry->execute( $stale_command ),
	Worksheet_Evaluation_Failure::SOURCE_STALE,
	'C11 must require C10 when the worksheet current revision changed.'
);
eval_s06_same( 0, $stale_routing->calls, 'Source drift must fail before routing.' );

$cancel_snapshot                  = eval_s06_snapshot( $canonical, $configuration, 'running', 'running' );
$cancel_snapshot['evaluation_id'] = eval_s06_id();
$cancel_repository                = new Eval_S06_Control_Repository( $cancel_snapshot );
$cancel                           = new Cancel_Worksheet_Evaluation(
	$cancel_repository,
	$ids,
	static fn (): string => '2026-08-21 14:03:00'
);
$cancel_command                   = new Cancel_Worksheet_Evaluation_Command(
	42,
	$cancel_snapshot['evaluation_id'],
	's06-cancel-command-0001',
	'running',
	$cancel_snapshot['latest_run_id'],
	1,
	str_repeat( '4', 64 ),
	's06-correlation-cancel-0001',
	$cancel_snapshot['worksheet_revision_id'],
	'running',
	'owner',
	$canonical
);
$cancel_result                    = $cancel->execute( $cancel_command );
eval_s06_same( 'cancelled', $cancel_result->evaluation_state(), 'C12 must cancel the evaluation root.' );
eval_s06_same( 'cancelled', $cancel_result->run_state(), 'C12 must cancel the exact latest run.' );
eval_s06_same( 1, count( $cancel_repository->cancel_records ), 'C12 must persist one cancellation.' );
$cancel_replay = $cancel->execute( $cancel_command );
eval_s06_same( true, $cancel_replay->replayed(), 'An exact C12 retry must replay the durable receipt.' );
eval_s06_same( 1, count( $cancel_repository->cancel_records ), 'An exact C12 retry must add zero writes.' );

eval_s06_invalid(
	static fn () => new Retry_Worksheet_Evaluation_Command(
		42,
		$evaluation_id,
		's06-retry-invalid-0001',
		'running',
		$snapshot['latest_run_id'],
		1,
		str_repeat( '3', 64 ),
		's06-correlation-invalid-0001',
		$snapshot['worksheet_revision_id'],
		'running',
		'owner',
		$canonical
	),
	'C11 DTO must reject a non-terminal expected state.'
);
eval_s06_invalid(
	static fn () => new Cancel_Worksheet_Evaluation_Command(
		42,
		$evaluation_id,
		's06-cancel-invalid-0001',
		'failed',
		$snapshot['latest_run_id'],
		1,
		str_repeat( '3', 64 ),
		's06-correlation-invalid-0002',
		$snapshot['worksheet_revision_id'],
		'failed',
		'owner',
		$canonical
	),
	'C12 DTO must reject a terminal expected state.'
);

$rest_snapshot                  = eval_s06_snapshot( $canonical, $configuration );
$rest_snapshot['evaluation_id'] = eval_s06_id();
$rest_repository                = new Eval_S06_Control_Repository( $rest_snapshot );
$rest_queue                     = new Eval_S06_Queue();
$rest_retry                     = new Retry_Worksheet_Evaluation(
	$rest_repository,
	new Eval_S06_Configuration( $configuration ),
	new Eval_S06_Routing(),
	$rest_queue,
	$ids,
	$canonical,
	static fn (): string => '2026-08-21 14:04:00'
);
$rest_cancel                    = new Cancel_Worksheet_Evaluation(
	$rest_repository,
	$ids,
	static fn (): string => '2026-08-21 14:04:00'
);
$policy                         = new Eval_S06_Policy( $rest_snapshot['evaluation_id'], $rest_snapshot['worksheet_revision_id'], 'failed' );
$correlations                   = new Correlation_Id_Factory( static fn (): string => 's06-rest-correlation-0001' );
$envelopes                      = new Rest_Envelope_Factory();
$permissions                    = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller                     = new Worksheet_Evaluation_Control_Rest_Controller(
	$permissions,
	$rest_retry,
	$rest_cancel,
	$canonical,
	$envelopes,
	$correlations
);
$controller->register_routes();
$controller->register_routes();
eval_s06_same( 2, count( $GLOBALS['eval_s06_routes'] ), 'S06 must register exactly C11 and C12 once.' );
eval_s06_same( 'POST', $GLOBALS['eval_s06_routes'][0][2]['methods'], 'C11 must be POST.' );
eval_s06_same( 'POST', $GLOBALS['eval_s06_routes'][1][2]['methods'], 'C12 must be POST.' );
$rest_body    = eval_s06_body( 's06-rest-retry-command-0001', 'failed', $rest_snapshot['latest_run_id'], 1 );
$rest_request = eval_s06_request( 's06-rest-idempotency-0001', $rest_body, $rest_snapshot['evaluation_id'] );
eval_s06_same( true, $controller->permissions_retry( $rest_request ), 'C11 permission must use common REST handoff.' );
$rest_response = $controller->retry( $rest_request );
eval_s06_assert( $rest_response instanceof WP_REST_Response, 'C11 success must return a REST response.' );
eval_s06_same( 202, $rest_response->get_status(), 'C11 must return 202 for a new queued run.' );
$rest_envelope = $rest_response->get_data();
eval_s06_same( 2, $rest_envelope['data']['run']['run_no'], 'C11 REST must expose the appended run number.' );
eval_s06_same( false, $rest_envelope['meta']['idempotent_replay'], 'New C11 REST command must not report replay.' );
eval_s06_same( 1, $GLOBALS['eval_s06_nonce_calls'], 'C11 permission must verify wp_rest nonce exactly once.' );
eval_s06_same( Worksheet_Evaluation_Control_Rest_Controller::ACTION_RETRY, $policy->last_request?->action_key(), 'C11 Policy action drifted.' );

$invalid_body               = $rest_body;
$invalid_body['unexpected'] = true;
$invalid_request            = eval_s06_request( 's06-rest-idempotency-0002', $invalid_body, $rest_snapshot['evaluation_id'] );
$policy->state              = 'queued';
eval_s06_same( true, $controller->permissions_retry( $invalid_request ), 'Invalid C11 body must still pass independent permission checks.' );
$invalid_response = $controller->retry( $invalid_request );
eval_s06_assert( $invalid_response instanceof WP_Error, 'Unknown C11 body fields must fail.' );
eval_s06_same( 422, $invalid_response->get_error_data()['status'], 'Unknown C11 body fields must return 422.' );

$control_source = file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Control_Repository.php' );
$worker_source  = file_get_contents( $addon_root . '/src/Worksheet/Evaluation/Infrastructure/WordPress_Worksheet_Evaluation_Work_Repository.php' );
$root_source    = file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
eval_s06_assert( is_string( $control_source ) && is_string( $worker_source ) && is_string( $root_source ), 'S06 source files must be readable.' );
eval_s06_assert( str_contains( $control_source, "private const TABLE_RUNS        = 'oj_ai_evaluation_runs';" ), 'S06 must use literal add-on run table names.' );
eval_s06_assert( ! str_contains( $control_source, '$wpdb->prefix' ) && ! str_contains( $control_source, 'wp_oj_ai_' ), 'S06 must not prefix add-on table names.' );
eval_s06_assert( substr_count( $control_source, 'START TRANSACTION' ) >= 2, 'C11 and C12 must each start one transaction.' );
eval_s06_assert( str_contains( $control_source, 'FOR UPDATE' ), 'C11/C12 must lock command receipts and the latest run.' );
eval_s06_assert( str_contains( $control_source, 'lease_token = NULL' ), 'C12 must invalidate the active lease token.' );
eval_s06_assert( str_contains( $control_source, 'result_public_id IS NULL' ), 'C12 must CAS against an absent official result.' );
$lock_position   = strpos( $worker_source, "lock_matches( \$item, 'running' )" );
$result_position = strpos( $worker_source, 'self::TABLE_RESULTS,' );
eval_s06_assert( false !== $lock_position && false !== $result_position && $lock_position < $result_position, 'Late worker output must pass run state and lease CAS before result insertion.' );
eval_s06_assert( str_contains( $root_source, 'new Worksheet_Evaluation_Control_Rest_Module' ), 'Composition Root must register the S06 REST module.' );
eval_s06_assert( ! str_contains( $control_source, 'OJ\\Platform\\' ), 'S06 control repository must not depend on platform internals.' );

echo "G2-IMP-05-S06 worksheet evaluation retry/cancel contract passed.\n";
