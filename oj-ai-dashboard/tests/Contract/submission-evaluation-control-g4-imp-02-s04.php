<?php
/**
 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

use OJAI\Dashboard\Core\Autoloader as AddonAutoloader;
use OJAI\Dashboard\Platform\Addon_Manifest;
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
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Cancel_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Cancel_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Retry_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Retry_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Control_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Control_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Queue_Wakeup_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Routing_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Evaluation_Control_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
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
	 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
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
	 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
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

$GLOBALS['g4_s04_actor']       = 42;
$GLOBALS['g4_s04_nonce_calls'] = 0;
$GLOBALS['g4_s04_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_s04_actor'];
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
		++$GLOBALS['g4_s04_nonce_calls'];
		return 'valid-g4-s04-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_s04_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s04 assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s04 same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s04 failure.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $reason Reason.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_failure( callable $callback, string $reason, string $message ): void {
	try {
		$callback(); } catch ( Submission_Evaluation_Failure $failure ) {
		g4_s04_same( $reason, $failure->reason(), $message );
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s04 invalid.
 *
 * @param callable():mixed $callback Callback.
 * @param string           $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s04_invalid( callable $callback, string $message ): void {
	try {
		$callback(); } catch ( InvalidArgumentException ) {
		return;
		}
		throw new RuntimeException( $message );
}

/**
 * G4 s04 id.
 *
 * @return string
 */
function g4_s04_id(): string {
	static $suffix = 9400;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s04_assert( Public_Id::is_valid( $id ), 'S04 fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s04 snapshot.
 *
 * @param string      $state State.
 * @param string      $run_state Run state.
 * @param int         $run_no Run no.
 * @param string|null $latest_result_id Latest result id.
 * @return array<string,mixed>
 */
function g4_s04_snapshot( string $state, string $run_state, int $run_no = 1, ?string $latest_result_id = null ): array {
	return array(
		'evaluation_id'              => g4_s04_id(),
		'evaluation_state'           => $state,
		'evaluation_lock_version'    => 3,
		'submission_id'              => g4_s04_id(),
		'submission_revision_id'     => g4_s04_id(),
		'source_hash'                => str_repeat( 'a', 64 ),
		'publication_id'             => g4_s04_id(),
		'publication_hash'           => str_repeat( 'b', 64 ),
		'context_type'               => 'class',
		'context_id'                 => 'class-g4-s04',
		'context_revision'           => 'class-g4-s04-r4',
		'input_hash'                 => str_repeat( 'c', 64 ),
		'evaluation_identity_digest' => str_repeat( 'd', 64 ),
		'latest_run_id'              => g4_s04_id(),
		'latest_run_no'              => $run_no,
		'latest_run_state'           => $run_state,
		'latest_run_lock_version'    => 5,
		'latest_result_id'           => $latest_result_id,
		'result_count'               => null === $latest_result_id ? 0 : 1,
		'source_is_current'          => true,
	);
}

/**
 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
 */
final class G4_S04_Control_Repository implements Submission_Evaluation_Control_Repository_Port_Interface {
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
	 * @var array<string,array{digest:string,operation:string,result:Submission_Evaluation_Control_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 s04 control repository dependencies and state.
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
	 * @return Submission_Evaluation_Control_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest, string $operation ): ?Submission_Evaluation_Control_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$stored = $this->receipts[ $key ];
		if ( ! hash_equals( $digest, $stored['digest'] ) || ! hash_equals( $operation, $stored['operation'] ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		$result = $stored['result'];
		return new Submission_Evaluation_Control_Result(
			$result->operation(),
			$result->evaluation_id(),
			$result->evaluation_state(),
			$result->submission_id(),
			$result->submission_revision_id(),
			$result->source_hash(),
			$result->publication_id(),
			$result->publication_hash(),
			$result->run_id(),
			$result->run_no(),
			$result->run_state(),
			$result->latest_result_id(),
			$result->occurred_at_utc(),
			true
		);
	}

	/**
	 * Find snapshot.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @return array|null
	 */
	public function find_snapshot( string $evaluation_id ): ?array {
		return $evaluation_id === $this->snapshot['evaluation_id'] ? $this->snapshot : null;
	}

	/**
	 * Retry.
	 *
	 * @param array $record Record.
	 * @return Submission_Evaluation_Control_Result
	 */
	public function retry( array $record ): Submission_Evaluation_Control_Result {
		$this->retry_records[] = $record;
		$result                = $this->result( $record, Submission_Evaluation_Control_Result::OPERATION_RETRY );
		$this->store( $record, $result );
		$this->snapshot['evaluation_state']        = 'queued';
		$this->snapshot['latest_run_id']           = $record['run_public_id'];
		$this->snapshot['latest_run_no']           = $record['run_no'];
		$this->snapshot['latest_run_state']        = 'queued';
		$this->snapshot['latest_run_lock_version'] = 0;
		return $result;
	}

	/**
	 * Cancel.
	 *
	 * @param array $record Record.
	 * @return Submission_Evaluation_Control_Result
	 */
	public function cancel( array $record ): Submission_Evaluation_Control_Result {
		$this->cancel_records[] = $record;
		$result                 = $this->result( $record, Submission_Evaluation_Control_Result::OPERATION_CANCEL );
		$this->store( $record, $result );
		$this->snapshot['evaluation_state'] = 'cancelled';
		$this->snapshot['latest_run_state'] = 'cancelled';
		return $result;
	}

	/**
	 * Result.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $operation Operation.
	 * @return Submission_Evaluation_Control_Result
	 */
	private function result( array $record, string $operation ): Submission_Evaluation_Control_Result {
		$retry = Submission_Evaluation_Control_Result::OPERATION_RETRY === $operation;
		return new Submission_Evaluation_Control_Result(
			$operation,
			$record['evaluation_public_id'],
			$retry ? 'queued' : 'cancelled',
			$record['submission_public_id'],
			$record['submission_revision_public_id'],
			$record['submission_revision_hash'],
			$record['worksheet_publication_public_id'],
			$record['publication_hash'],
			$retry ? $record['run_public_id'] : $record['expected_run_public_id'],
			$retry ? $record['run_no'] : $record['expected_run_no'],
			$retry ? 'queued' : 'cancelled',
			$record['expected_latest_result_id'],
			$record['occurred_at_utc'],
			false
		);
	}

	/**
	 * Store.
	 *
	 * @param array<string,mixed>                  $record Record.
	 * @param Submission_Evaluation_Control_Result $result Result.
	 * @return void
	 */
	private function store( array $record, Submission_Evaluation_Control_Result $result ): void {
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest'    => $record['request_digest'],
			'operation' => $result->operation(),
			'result'    => $result,
		);
	}
}

/**
 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
 */
final class G4_S04_Routing implements Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;
	/**
	 * Plan.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 */
	public function plan(): Submission_Evaluation_Routing_Snapshot {
		++$this->calls;
		return new Submission_Evaluation_Routing_Snapshot(
			Addon_Manifest::TASK_STUDENT_ASSESSMENT,
			'provider-g4-s04',
			'model-g4-s04',
			str_repeat( 'e', 64 ),
			'route-g4-s04-r1'
		);
	}
}

/**
 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
 */
final class G4_S04_Queue implements Submission_Evaluation_Queue_Wakeup_Port_Interface {
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
 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
 */
final class G4_S04_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * State.
	 *
	 * @var string
	 */
	public string $state;
	/**
	 * Initialize g4 s04 policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $state State.
	 */
	public function __construct( private string $evaluation_id, private string $context_id, private string $context_revision, string $state ) {
		$this->state = $state;
	}
	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		$context            = $request->context();
		$object             = $request->object();
		$expected_action    = Submission_Evaluation_Control_Rest_Controller::ACTION_RETRY === $request->action_key() ? 'retry' : 'cancel';
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id()
			|| ! in_array( $request->action_key(), array( Submission_Evaluation_Control_Rest_Controller::ACTION_RETRY, Submission_Evaluation_Control_Rest_Controller::ACTION_CANCEL ), true )
			|| array( Addon_Capabilities::ASSESS_SUBMISSIONS ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope || 'class' !== $context->type() || $this->context_id !== $context->id()
			|| Context_Scope::OPERATION_EVALUATE !== $context->operation() || $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_EVALUATION !== $object->type()
			|| $this->evaluation_id !== $object->id() || $expected_action !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'submission_evaluation_control_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( '3', $this->state, 'context_staff' )
		);
	}
}

/**
 * G4-IMP-02-S04 C31 retry and C32 cancellation contract.
 */
final class G4_S04_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,mixed>
	 */
	private array $headers = array();
	/**
	 * Initialize g4 s04 request dependencies and state.
	 *
	 * @param array<string,mixed> $json Json.
	 * @param string              $evaluation_id Evaluation id.
	 * @param array<string,mixed> $query Query.
	 * @param string              $key Key.
	 */
	public function __construct( array $json, private string $evaluation_id, private array $query, string $key ) {
		$this->headers = array(
			'x-wp-nonce'      => 'valid-g4-s04-nonce',
			'idempotency-key' => $key,
		);
		$this->json    = $json;
	}
	/**
	 * Json.
	 *
	 * @var array<string,mixed>
	 */
	private array $json;
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
	/**
	 * Get query params.
	 *
	 * @return array<string,mixed>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G4 s04 context.
 *
 * @return array<string,string>
 */
function g4_s04_context(): array {
	return array(
		'context_type'     => 'class',
		'context_id'       => 'class-g4-s04',
		'context_revision' => 'class-g4-s04-r4',
	);
}

/**
 * G4 s04 retry body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param array  $snapshot Snapshot.
 * @param string $reason Reason.
 * @return array<string,mixed>
 */
function g4_s04_retry_body( string $command_id, array $snapshot, string $reason ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_latest_result_id' => $snapshot['latest_result_id'],
			'expected_run_id'           => $snapshot['latest_run_id'],
			'expected_run_no'           => $snapshot['latest_run_no'],
			'expected_state'            => $snapshot['evaluation_state'],
		),
		'payload'    => array( 'reason' => $reason ),
	);
}

/**
 * G4 s04 cancel body.
 *
 * @param string $command_id Command identifier used for idempotency.
 * @param array  $snapshot Snapshot.
 * @param string $reason Reason.
 * @return array<string,mixed>
 */
function g4_s04_cancel_body( string $command_id, array $snapshot, string $reason ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_run_id' => $snapshot['latest_run_id'],
			'expected_run_no' => $snapshot['latest_run_no'],
			'expected_state'  => $snapshot['evaluation_state'],
		),
		'payload'    => array( 'reason' => $reason ),
	);
}

$canonical        = new Canonical_Json();
$ids              = new Public_Id_Generator();
$latest_result_id = g4_s04_id();
$retry_snapshot   = g4_s04_snapshot( 'review_required', 'succeeded', 1, $latest_result_id );
$retry_repository = new G4_S04_Control_Repository( $retry_snapshot );
$routing          = new G4_S04_Routing();
$queue            = new G4_S04_Queue();
$retry            = new Retry_Submission_Evaluation(
	$retry_repository,
	$routing,
	$queue,
	$ids,
	$canonical,
	static fn (): string => '2026-08-23 16:00:00'
);
$retry_command    = new Retry_Submission_Evaluation_Command(
	42,
	$retry_snapshot['evaluation_id'],
	'command-g4-s04-retry-001',
	'review_required',
	$retry_snapshot['latest_run_id'],
	1,
	$latest_result_id,
	'교사가 결과를 다시 확인합니다.',
	str_repeat( '1', 64 ),
	'correlation-g4-s04-retry-1',
	'3',
	'review_required',
	'context_staff',
	'class',
	'class-g4-s04',
	'class-g4-s04-r4',
	$canonical
);
$retry_result     = $retry->execute( $retry_command );
g4_s04_same( 'queued', $retry_result->evaluation_state(), 'C31 must return the evaluation to queued.' );
g4_s04_same( 2, $retry_result->run_no(), 'C31 must append one contiguous run.' );
g4_s04_same( $latest_result_id, $retry_result->latest_result_id(), 'C31 must preserve the previous official candidate result.' );
g4_s04_same( 1, count( $retry_repository->retry_records ), 'C31 must persist one retry record.' );
g4_s04_same( 1, $queue->calls, 'C31 must wake the queue after durable retry.' );
g4_s04_same( 1, $routing->calls, 'C31 must plan one fresh routing snapshot for the new run.' );
$retry_record = $retry_repository->retry_records[0];
g4_s04_same( str_repeat( 'c', 64 ), $retry_record['input_hash'], 'C31 must reuse the immutable input digest.' );
g4_s04_same( 1, $retry_record['expected_result_count'], 'C31 must pin the previous result count.' );
g4_s04_same( $latest_result_id, $retry_record['expected_latest_result_id'], 'C31 must CAS the previous latest result.' );
g4_s04_same( 5, $retry_record['expected_run_lock_version'], 'C31 must carry the internal run CAS version.' );

$retry_replay = $retry->execute( $retry_command );
g4_s04_same( true, $retry_replay->replayed(), 'Exact C31 replay must use its durable receipt.' );
g4_s04_same( 1, count( $retry_repository->retry_records ), 'Exact C31 replay must append zero runs.' );
g4_s04_same( 2, $queue->calls, 'Exact C31 replay may only wake the existing durable run.' );
g4_s04_failure(
	static fn () => $retry->execute(
		new Retry_Submission_Evaluation_Command(
			42,
			$retry_snapshot['evaluation_id'],
			'command-g4-s04-retry-001',
			'review_required',
			$retry_snapshot['latest_run_id'],
			1,
			$latest_result_id,
			'같은 키에 다른 사유를 전송합니다.',
			str_repeat( '1', 64 ),
			'correlation-g4-s04-retry-1',
			'3',
			'review_required',
			'context_staff',
			'class',
			'class-g4-s04',
			'class-g4-s04-r4',
			$canonical
		)
	),
	Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
	'C31 must reject a changed request under the same idempotency fingerprint.'
);

$drift_snapshot   = g4_s04_snapshot( 'review_required', 'succeeded', 1, g4_s04_id() );
$drift_repository = new G4_S04_Control_Repository( $drift_snapshot );
$drift_routing    = new G4_S04_Routing();
$drift_retry      = new Retry_Submission_Evaluation(
	$drift_repository,
	$drift_routing,
	new G4_S04_Queue(),
	$ids,
	$canonical,
	static fn (): string => '2026-08-23 16:01:00'
);
g4_s04_failure(
	static fn () => $drift_retry->execute(
		new Retry_Submission_Evaluation_Command(
			42,
			$drift_snapshot['evaluation_id'],
			'command-g4-s04-retry-002',
			'review_required',
			$drift_snapshot['latest_run_id'],
			1,
			null,
			'최신 결과가 다르면 거부합니다.',
			str_repeat( '2', 64 ),
			'correlation-g4-s04-retry-2',
			'3',
			'review_required',
			'context_staff',
			'class',
			'class-g4-s04',
			'class-g4-s04-r4',
			$canonical
		)
	),
	Submission_Evaluation_Failure::STATE_CONFLICT,
	'C31 must reject a stale latest-result expectation before routing.'
);
g4_s04_same( 0, $drift_routing->calls, 'Stale C31 evidence must fail before route planning.' );

$cancel_snapshot   = g4_s04_snapshot( 'running', 'running', 2, g4_s04_id() );
$cancel_repository = new G4_S04_Control_Repository( $cancel_snapshot );
$cancel            = new Cancel_Submission_Evaluation(
	$cancel_repository,
	$ids,
	static fn (): string => '2026-08-23 16:02:00'
);
$cancel_command    = new Cancel_Submission_Evaluation_Command(
	42,
	$cancel_snapshot['evaluation_id'],
	'command-g4-s04-cancel-001',
	'running',
	$cancel_snapshot['latest_run_id'],
	2,
	'진행 중인 평가를 중단합니다.',
	str_repeat( '3', 64 ),
	'correlation-g4-s04-cancel-1',
	'3',
	'running',
	'context_staff',
	'class',
	'class-g4-s04',
	'class-g4-s04-r4',
	$canonical
);
$cancel_result     = $cancel->execute( $cancel_command );
g4_s04_same( 'cancelled', $cancel_result->evaluation_state(), 'C32 must cancel the evaluation root.' );
g4_s04_same( 'cancelled', $cancel_result->run_state(), 'C32 must cancel the exact latest run.' );
g4_s04_same( $cancel_snapshot['latest_result_id'], $cancel_result->latest_result_id(), 'C32 must preserve an older successful result.' );
g4_s04_same( 1, $cancel_repository->cancel_records[0]['expected_result_count'], 'C32 must pin the existing result count.' );
$cancel_replay = $cancel->execute( $cancel_command );
g4_s04_same( true, $cancel_replay->replayed(), 'Exact C32 replay must use its durable receipt.' );
g4_s04_same( 1, count( $cancel_repository->cancel_records ), 'Exact C32 replay must add zero writes.' );

g4_s04_invalid(
	static fn () => new Retry_Submission_Evaluation_Command(
		42,
		$retry_snapshot['evaluation_id'],
		'command-g4-s04-invalid-1',
		'running',
		$retry_snapshot['latest_run_id'],
		1,
		$latest_result_id,
		'잘못된 상태를 거부합니다.',
		str_repeat( '4', 64 ),
		'correlation-g4-s04-invalid-1',
		'3',
		'running',
		'context_staff',
		'class',
		'class-g4-s04',
		'class-g4-s04-r4',
		$canonical
	),
	'C31 DTO must reject a non-terminal expected state.'
);
g4_s04_invalid(
	static fn () => new Cancel_Submission_Evaluation_Command(
		42,
		$cancel_snapshot['evaluation_id'],
		'command-g4-s04-invalid-2',
		'failed',
		$cancel_snapshot['latest_run_id'],
		2,
		'잘못된 상태를 거부합니다.',
		str_repeat( '5', 64 ),
		'correlation-g4-s04-invalid-2',
		'3',
		'failed',
		'context_staff',
		'class',
		'class-g4-s04',
		'class-g4-s04-r4',
		$canonical
	),
	'C32 DTO must reject a terminal expected state.'
);
g4_s04_invalid(
	static fn () => new Cancel_Submission_Evaluation_Command(
		42,
		$cancel_snapshot['evaluation_id'],
		'command-g4-s04-invalid-3',
		'running',
		$cancel_snapshot['latest_run_id'],
		2,
		'no',
		str_repeat( '6', 64 ),
		'correlation-g4-s04-invalid-3',
		'3',
		'running',
		'context_staff',
		'class',
		'class-g4-s04',
		'class-g4-s04-r4',
		$canonical
	),
	'C32 DTO must reject a reason shorter than three bytes.'
);

$rest_result_id  = g4_s04_id();
$rest_snapshot   = g4_s04_snapshot( 'review_required', 'succeeded', 1, $rest_result_id );
$rest_repository = new G4_S04_Control_Repository( $rest_snapshot );
$rest_queue      = new G4_S04_Queue();
$rest_retry      = new Retry_Submission_Evaluation(
	$rest_repository,
	new G4_S04_Routing(),
	$rest_queue,
	$ids,
	$canonical,
	static fn (): string => '2026-08-23 16:03:00'
);
$rest_cancel     = new Cancel_Submission_Evaluation(
	$rest_repository,
	$ids,
	static fn (): string => '2026-08-23 16:04:00'
);
$policy          = new G4_S04_Policy( $rest_snapshot['evaluation_id'], 'class-g4-s04', 'class-g4-s04-r4', 'review_required' );
$correlations    = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-s04-rest' );
$envelopes       = new Rest_Envelope_Factory();
$permissions     = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller      = new Submission_Evaluation_Control_Rest_Controller(
	$permissions,
	$rest_retry,
	$rest_cancel,
	$canonical,
	$envelopes,
	$correlations
);
$controller->register_routes();
$controller->register_routes();
g4_s04_same( 2, count( $GLOBALS['g4_s04_routes'] ), 'S04 must register exactly C31 and C32 once.' );
g4_s04_same( 'POST', $GLOBALS['g4_s04_routes'][0][2]['methods'], 'C31 must use POST.' );
g4_s04_same( 'POST', $GLOBALS['g4_s04_routes'][1][2]['methods'], 'C32 must use POST.' );

$rest_request = new G4_S04_Request(
	g4_s04_retry_body( 'command-g4-s04-rest-retry-1', $rest_snapshot, 'REST에서 평가를 다시 실행합니다.' ),
	$rest_snapshot['evaluation_id'],
	g4_s04_context(),
	'idempotency-g4-s04-rest-retry-1'
);
g4_s04_same( true, $controller->permissions_retry( $rest_request ), 'C31 permission must use the common REST handoff.' );
$rest_response = $controller->retry( $rest_request );
g4_s04_assert( $rest_response instanceof WP_REST_Response, 'C31 success must return a REST response.' );
g4_s04_same( 202, $rest_response->get_status(), 'C31 must return 202 for the new queued run.' );
$retry_envelope = $rest_response->get_data();
g4_s04_same( 2, $retry_envelope['data']['run']['run_no'], 'C31 REST must expose the appended run number.' );
g4_s04_same( $rest_result_id, $retry_envelope['data']['evaluation']['latest_result_id'], 'C31 REST must expose the preserved latest result.' );
g4_s04_same( Submission_Evaluation_Control_Rest_Controller::ACTION_RETRY, $policy->last_request?->action_key(), 'C31 Policy action drifted.' );

$policy->state  = 'queued';
$cancel_body    = g4_s04_cancel_body( 'command-g4-s04-rest-cancel-1', $rest_repository->snapshot, '대기 중인 재평가를 취소합니다.' );
$cancel_request = new G4_S04_Request(
	$cancel_body,
	$rest_snapshot['evaluation_id'],
	g4_s04_context(),
	'idempotency-g4-s04-rest-cancel-1'
);
g4_s04_same( true, $controller->permissions_cancel( $cancel_request ), 'C32 permission must use Context and Evaluation scopes.' );
$cancel_response = $controller->cancel( $cancel_request );
g4_s04_assert( $cancel_response instanceof WP_REST_Response, 'C32 success must return a REST response.' );
g4_s04_same( 200, $cancel_response->get_status(), 'C32 must return 200 after durable cancellation.' );
g4_s04_same( Submission_Evaluation_Control_Rest_Controller::ACTION_CANCEL, $policy->last_request?->action_key(), 'C32 Policy action drifted.' );
g4_s04_same( 2, $GLOBALS['g4_s04_nonce_calls'], 'C31/C32 must each verify the REST nonce exactly once.' );

$invalid_body = $cancel_body;
$invalid_body['expected']['expected_latest_result_id'] = $rest_result_id;
$invalid_request                                       = new G4_S04_Request(
	$invalid_body,
	$rest_snapshot['evaluation_id'],
	g4_s04_context(),
	'idempotency-g4-s04-rest-invalid-1'
);
$policy->state = 'cancelled';
g4_s04_same( true, $controller->permissions_cancel( $invalid_request ), 'C32 permission must stay independent from body parsing.' );
$invalid_response = $controller->cancel( $invalid_request );
g4_s04_assert( $invalid_response instanceof WP_Error, 'C32 must reject the C31-only latest-result field.' );
g4_s04_same( 422, $invalid_response->get_error_data()['status'], 'C32 unknown expected fields must return 422.' );

$repository_source = file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Control_Repository.php' );
$worker_source     = file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Evaluation_Work_Repository.php' );
$controller_source = file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Evaluation_Control_Rest_Controller.php' );
$object_source     = file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/Submission_Evaluation_Object_Authorization.php' );
$root_source       = file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
g4_s04_assert( is_string( $repository_source ) && is_string( $worker_source ) && is_string( $controller_source ) && is_string( $object_source ) && is_string( $root_source ), 'S04 sources must be readable.' );

foreach ( array(
	"'oj_ai_submission_evaluations'",
	"'oj_ai_submission_evaluation_inputs'",
	"'oj_ai_submission_evaluation_results'",
	"'oj_ai_evaluation_runs'",
	"'oj_ai_evaluation_attempts'",
	"'oj_ai_submission_revisions'",
	"'oj_ai_submissions'",
	"'oj_ai_assignments'",
	"'oj_ai_assignment_targets'",
	"'oj_ai_audit_events'",
	"'oj_ai_outbox_events'",
	"'submission_evaluation.retry_queued'",
	"'submission_evaluation.cancelled'",
	'START TRANSACTION',
	'FOR UPDATE',
	'lease_token = NULL',
	'lock_version = lock_version + 1',
	'result_public_id IS NULL',
	'result_count',
	'assert_retry_postcondition(',
	'assert_cancel_postcondition(',
	'cancel_started_attempt(',
) as $marker ) {
	g4_s04_assert( str_contains( $repository_source, $marker ), 'S04 atomic repository marker is missing: ' . $marker );
}
g4_s04_assert( substr_count( $repository_source, 'START TRANSACTION' ) >= 2, 'C31 and C32 must each start one transaction.' );
g4_s04_assert( ! str_contains( $repository_source, '$wpdb->prefix' ) && ! str_contains( $repository_source, 'wp_oj_ai_' ), 'S04 must use literal add-on table names.' );
g4_s04_assert( ! str_contains( $repository_source, "'UPDATE ' . self::TABLE_RESULTS" ), 'C31/C32 must never update an existing result row.' );
$insert_run  = strpos( $repository_source, '$this->insert_retry_run(' );
$update_root = strpos( $repository_source, '$this->update_evaluation(', false === $insert_run ? 0 : $insert_run );
g4_s04_assert( false !== $insert_run && false !== $update_root && $insert_run < $update_root, 'C31 must append the run before committing the root transition.' );
$cancel_attempt = strpos( $repository_source, '$this->cancel_started_attempt(' );
$cancel_run     = strpos( $repository_source, '$this->cancel_run(', false === $cancel_attempt ? 0 : $cancel_attempt );
g4_s04_assert( false !== $cancel_attempt && false !== $cancel_run && $cancel_attempt < $cancel_run, 'C32 must cancel a started attempt before invalidating its run lease.' );
$worker_lock   = strpos( $worker_source, "if ( ! \$this->lock_matches( \$item, 'running' )" );
$worker_result = strpos( $worker_source, 'self::TABLE_RESULTS,', false === $worker_lock ? 0 : $worker_lock );
g4_s04_assert( false !== $worker_lock && false !== $worker_result && $worker_lock < $worker_result, 'Late worker output must pass run state, lock, and lease CAS before result insertion.' );

foreach ( array(
	'Context_Scope::OPERATION_EVALUATE',
	'Addon_Capabilities::ASSESS_SUBMISSIONS',
	'Object_Scope::TYPE_EVALUATION',
	"array( 'reason' )",
	'expected_latest_result_id',
	'? 202 : 200',
) as $marker ) {
	g4_s04_assert( str_contains( $controller_source, $marker ), 'S04 REST marker is missing: ' . $marker );
}
g4_s04_same( 2, preg_match_all( "/'methods'\\s*=>\\s*'POST'/", $controller_source ), 'S04 controller must expose exactly two POST routes.' );
g4_s04_assert( str_contains( $object_source, "array( 'view', 'retry', 'cancel', 'review', 'release', 'withdraw_release', 'withdraw_approval', 'report' )" ), 'Evaluation object authorization must preserve view/retry/cancel/review/report and expose release/withdraw_release/withdraw_approval.' );
foreach ( array(
	'new WordPress_Submission_Evaluation_Control_Repository(',
	'new Retry_Submission_Evaluation(',
	'new Cancel_Submission_Evaluation(',
	'new Submission_Evaluation_Control_Rest_Controller(',
	'new Submission_Evaluation_Control_Rest_Module(',
	'$submission_evaluation_queue',
) as $marker ) {
	g4_s04_assert( str_contains( $root_source, $marker ), 'S04 Composition Root marker is missing: ' . $marker );
}
g4_s04_assert( ! str_contains( $repository_source . $controller_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'S04 runtime must not depend on platform internals.' );

echo "G4-IMP-02-S04 submission evaluation retry/cancel contract passed.\n";
