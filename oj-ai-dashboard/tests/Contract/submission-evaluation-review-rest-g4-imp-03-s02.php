<?php
/**
 * G4-IMP-03-S02 C34 REST and authorization contract.
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
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Review_Result;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Evaluation_Review_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Evaluation_Review_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

$addon_root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $addon_root . DIRECTORY_SEPARATOR );

if ( ! class_exists( 'Normalizer' ) ) {
	/**
	 * G4-IMP-03-S02 C34 REST and authorization contract.
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
	 * G4-IMP-03-S02 C34 REST and authorization contract.
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
	 * G4-IMP-03-S02 C34 REST and authorization contract.
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

$GLOBALS['g4_s02_rest_actor']       = 42;
$GLOBALS['g4_s02_rest_nonce_calls'] = 0;
$GLOBALS['g4_s02_rest_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_s02_rest_actor'];
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
		++$GLOBALS['g4_s02_rest_nonce_calls'];
		return 'valid-g4-s02-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_s02_rest_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

require_once $addon_root . '/src/Core/Autoloader.php';
( new AddonAutoloader( $addon_root . '/src' ) )->register();

/**
 * G4 s02 rest assert.
 *
 * @param bool   $condition Condition.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_rest_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * G4 s02 rest same.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual Actual.
 * @param string $message Message.
 * @return void
 * @throws \Throwable Propagates validation or dependency failures.
 */
function g4_s02_rest_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message . "\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) );
	}
}

/**
 * G4 s02 rest id.
 *
 * @return string
 */
function g4_s02_rest_id(): string {
	static $suffix = 60100;
	++$suffix;
	$id = '01K' . str_pad( (string) $suffix, 23, '0', STR_PAD_LEFT );
	g4_s02_rest_assert( Public_Id::is_valid( $id ), 'REST fixture ID must satisfy PublicIdV1.' );
	return $id;
}

/**
 * G4 s02 rest snapshot.
 *
 * @return array<string,mixed>
 */
function g4_s02_rest_snapshot(): array {
	return array(
		'evaluation_lock_version'  => 4,
		'evaluation_state'         => 'review_required',
		'context_type'             => 'class',
		'context_id'               => 'class-g4-s02-rest',
		'context_revision'         => 'class-g4-s02-rest-r1',
		'submission_id'            => g4_s02_rest_id(),
		'submission_revision_id'   => g4_s02_rest_id(),
		'submission_revision_hash' => str_repeat( 'a', 64 ),
		'submission_lock_version'  => 7,
		'official_review_id'       => null,
		'official_review_hash'     => null,
		'current_release_id'       => null,
		'current_release_hash'     => null,
		'current_release_state'    => null,
		'result_id'                => g4_s02_rest_id(),
		'result_hash'              => str_repeat( 'b', 64 ),
		'result_items'             => array(
			array(
				'binding_key'            => str_repeat( 'c', 64 ),
				'criterion_id'           => g4_s02_rest_id(),
				'response_id'            => g4_s02_rest_id(),
				'scope'                  => 'response',
				'position'               => 0,
				'candidate_points'       => '4.0000',
				'maximum_points'         => '5.0000',
				'levels'                 => null,
				'flags'                  => array(),
				'safety_review_required' => false,
			),
		),
		'latest_review_id'         => null,
		'latest_review_no'         => 0,
	);
}

/**
 * G4-IMP-03-S02 C34 REST and authorization contract.
 */
final class G4_S02_Rest_Repository implements Submission_Evaluation_Review_Repository_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $snapshot;
	/**
	 * Records.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $records = array();
	/**
	 * Receipts.
	 *
	 * @var array<string,array{digest:string,result:Submission_Evaluation_Review_Result}>
	 */
	private array $receipts = array();

	/**
	 * Initialize g4 s02 rest repository dependencies and state.
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
	 * @return Submission_Evaluation_Review_Result|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function find_command_receipt( string $key, string $digest ): ?Submission_Evaluation_Review_Result {
		if ( ! isset( $this->receipts[ $key ] ) ) {
			return null;
		}
		$receipt = $this->receipts[ $key ];
		if ( ! hash_equals( $receipt['digest'], $digest ) ) {
			throw Submission_Evaluation_Failure::because( Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT );
		}
		return $this->copy( $receipt['result'], true );
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
	 * Append review.
	 *
	 * @param array $record Record.
	 * @return Submission_Evaluation_Review_Result
	 */
	public function append_review( array $record ): Submission_Evaluation_Review_Result {
		$this->records[] = $record;
		$advanced        = 'rejected' !== $record['decision'];
		$result          = new Submission_Evaluation_Review_Result(
			$record['evaluation_public_id'],
			$record['submission_public_id'],
			$record['submission_revision_public_id'],
			$record['submission_revision_hash'],
			$record['expected_result_public_id'],
			$record['expected_result_hash'],
			$record['review_public_id'],
			$record['content_hash'],
			$record['review_no'],
			$record['decision'],
			$record['official_score'],
			$record['score_decisions'],
			$record['reason'],
			'withdrawn' === $record['decision'] ? null : ( $advanced ? $record['review_public_id'] : $record['expected_official_review_public_id'] ),
			'withdrawn' === $record['decision'] ? null : ( $advanced ? $record['content_hash'] : $record['expected_official_review_hash'] ),
			$advanced,
			$record['occurred_at_utc'],
			false
		);
		$this->receipts[ $record['command_storage_key'] ] = array(
			'digest' => $record['request_digest'],
			'result' => $result,
		);
		return $result;
	}

	/**
	 * Copy.
	 *
	 * @param Submission_Evaluation_Review_Result $source Source.
	 * @param bool                                $replayed Replayed.
	 * @return Submission_Evaluation_Review_Result
	 */
	private function copy( Submission_Evaluation_Review_Result $source, bool $replayed ): Submission_Evaluation_Review_Result {
		return new Submission_Evaluation_Review_Result(
			$source->evaluation_id(),
			$source->submission_id(),
			$source->submission_revision_id(),
			$source->submission_revision_hash(),
			$source->result_id(),
			$source->result_hash(),
			$source->review_id(),
			$source->review_hash(),
			$source->review_no(),
			$source->decision(),
			$source->official_score(),
			$source->score_decisions(),
			$source->reason(),
			$source->official_review_id(),
			$source->official_review_hash(),
			$source->official_pointer_advanced(),
			$source->occurred_at_utc(),
			$replayed
		);
	}
}

/**
 * G4-IMP-03-S02 C34 REST and authorization contract.
 */
final class G4_S02_Rest_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;
	/**
	 * Deny.
	 *
	 * @var bool
	 */
	public bool $deny = false;
	/**
	 * Authorized evaluation state.
	 *
	 * @var string
	 */
	public string $state = 'review_required';
	/**
	 * Initialize g4 s02 rest policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $context_id,
		private string $context_revision
	) {
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
			return Access_Policy_Decision::denied( 403, 'submission_evaluation_review_scope_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified()
			|| 42 !== $request->actor_user_id()
			|| Submission_Evaluation_Review_Rest_Controller::ACTION_REVIEW !== $request->action_key()
			|| array( Addon_Capabilities::REVIEW_ASSESSMENTS ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope
			|| 'class' !== $context->type()
			|| $this->context_id !== $context->id()
			|| Context_Scope::OPERATION_EVALUATE !== $context->operation()
			|| $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_EVALUATION !== $object->type()
			|| $this->evaluation_id !== $object->id()
			|| 'review' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'submission_evaluation_review_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( '4', $this->state, 'context_staff' )
		);
	}
}

/**
 * G4-IMP-03-S02 C34 REST and authorization contract.
 */
final class G4_S02_Rest_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers;
	/**
	 * Initialize g4 s02 rest request dependencies and state.
	 *
	 * @param array<string,mixed>  $json Json.
	 * @param string               $evaluation_id Evaluation id.
	 * @param array<string,string> $query Query.
	 * @param string               $idempotency_key Idempotency key.
	 */
	public function __construct(
		private array $json,
		private string $evaluation_id,
		private array $query,
		string $idempotency_key
	) {
		$this->headers = array(
			'x-wp-nonce'      => 'valid-g4-s02-nonce',
			'idempotency-key' => $idempotency_key,
		);
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
	/**
	 * Get query params.
	 *
	 * @return array<string,string>
	 */
	public function get_query_params(): array {
		return $this->query;
	}
}

/**
 * G4 s02 rest context.
 *
 * @return array<string,string>
 */
function g4_s02_rest_context(): array {
	return array(
		'context_type'     => 'class',
		'context_id'       => 'class-g4-s02-rest',
		'context_revision' => 'class-g4-s02-rest-r1',
	);
}

/**
 * G4 s02 rest body.
 *
 * @param array  $snapshot Snapshot.
 * @param string $command_id Command identifier used for idempotency.
 * @return array<string,mixed>
 */
function g4_s02_rest_body( array $snapshot, string $command_id ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_official_review_hash' => $snapshot['official_review_hash'],
			'expected_official_review_id'   => $snapshot['official_review_id'],
			'expected_result_hash'          => $snapshot['result_hash'],
			'expected_result_id'            => $snapshot['result_id'],
			'expected_review_no'            => $snapshot['latest_review_no'],
		),
		'payload'    => array(
			'decision'       => 'accepted',
			'item_decisions' => array(),
			'reason'         => null,
		),
	);
}

$canonical     = new Canonical_Json();
$snapshot      = g4_s02_rest_snapshot();
$evaluation_id = g4_s02_rest_id();
$repository    = new G4_S02_Rest_Repository( $snapshot );
$application   = new Review_Submission_Evaluation(
	$repository,
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-23 18:10:00'
);
$policy        = new G4_S02_Rest_Policy( $evaluation_id, 'class-g4-s02-rest', 'class-g4-s02-rest-r1' );
$correlations  = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-s02-rest' );
$envelopes     = new Rest_Envelope_Factory();
$permissions   = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
	$envelopes,
	$correlations
);
$controller    = new Submission_Evaluation_Review_Rest_Controller(
	$permissions,
	$application,
	$canonical,
	$envelopes,
	$correlations
);
$controller->register_routes();
$controller->register_routes();
g4_s02_rest_same( 1, count( $GLOBALS['g4_s02_rest_routes'] ), 'C34 must register exactly once.' );
g4_s02_rest_same( 'oj-ai/v1', $GLOBALS['g4_s02_rest_routes'][0][0], 'C34 namespace drifted.' );
g4_s02_rest_same( Submission_Evaluation_Review_Rest_Controller::ROUTE_REVIEW, $GLOBALS['g4_s02_rest_routes'][0][1], 'C34 route drifted.' );
g4_s02_rest_same( 'POST', $GLOBALS['g4_s02_rest_routes'][0][2]['methods'], 'C34 must use POST.' );

$body    = g4_s02_rest_body( $snapshot, 'command-g4-s02-rest-review' );
$request = new G4_S02_Rest_Request( $body, $evaluation_id, g4_s02_rest_context(), 'idempotency-g4-s02-rest-review' );
g4_s02_rest_same( true, $controller->permissions_review( $request ), 'C34 permission must use the common REST handoff.' );
$response = $controller->review( $request );
g4_s02_rest_assert( $response instanceof WP_REST_Response, 'C34 success must return a REST response.' );
g4_s02_rest_same( 201, $response->get_status(), 'A new C34 review must return 201.' );
$envelope = $response->get_data();
g4_s02_rest_same( 'accepted', $envelope['data']['review']['decision'], 'C34 review decision drifted.' );
g4_s02_rest_same( '4.0000', $envelope['data']['review']['official_score'], 'C34 must expose the server-calculated score.' );
g4_s02_rest_same( true, $envelope['data']['official_review']['advanced'], 'Accepted C34 must report official pointer advancement.' );
g4_s02_rest_same( $envelope['data']['review']['id'], $envelope['data']['official_review']['id'], 'Official pointer must target the appended review.' );
g4_s02_rest_same( false, $envelope['meta']['idempotent_replay'], 'New C34 response must not report replay.' );
g4_s02_rest_same( Submission_Evaluation_Review_Rest_Controller::ACTION_REVIEW, $policy->last_request?->action_key(), 'C34 policy action drifted.' );
g4_s02_rest_same( array( Addon_Capabilities::REVIEW_ASSESSMENTS ), $policy->last_request?->required_capabilities(), 'C34 capability drifted.' );

g4_s02_rest_same( true, $controller->permissions_review( $request ), 'Exact C34 retry must reauthorize.' );
$replay = $controller->review( $request );
g4_s02_rest_assert( $replay instanceof WP_REST_Response, 'Exact C34 retry must return a REST response.' );
g4_s02_rest_same( 200, $replay->get_status(), 'Exact C34 replay must return 200.' );
g4_s02_rest_same( true, $replay->get_data()['meta']['idempotent_replay'], 'Exact C34 retry must use the durable receipt.' );
g4_s02_rest_same( 1, count( $repository->records ), 'Exact C34 retry must append zero additional reviews.' );

$invalid_body                          = $body;
$invalid_body['payload']['unexpected'] = true;
$invalid_request                       = new G4_S02_Rest_Request(
	$invalid_body,
	$evaluation_id,
	g4_s02_rest_context(),
	'idempotency-g4-s02-rest-invalid'
);
g4_s02_rest_same( true, $controller->permissions_review( $invalid_request ), 'C34 permission must remain independent of body parsing.' );
$invalid_response = $controller->review( $invalid_request );
g4_s02_rest_assert( $invalid_response instanceof WP_Error, 'Unknown C34 payload fields must fail.' );
g4_s02_rest_same( 422, $invalid_response->get_error_data()['status'], 'Unknown C34 payload fields must return 422.' );

$conflict_body    = g4_s02_rest_body( $snapshot, 'command-g4-s02-rest-conflict' );
$conflict_request = new G4_S02_Rest_Request(
	$conflict_body,
	$evaluation_id,
	g4_s02_rest_context(),
	'idempotency-g4-s02-rest-review'
);
g4_s02_rest_same( true, $controller->permissions_review( $conflict_request ), 'C34 idempotency conflict request must reauthorize.' );
$conflict_response = $controller->review( $conflict_request );
g4_s02_rest_assert( $conflict_response instanceof WP_Error, 'Changed body under one idempotency key must fail.' );
g4_s02_rest_same( 409, $conflict_response->get_error_data()['status'], 'C34 idempotency conflict must return 409.' );

$records_before_revoke = count( $repository->records );
$policy->deny          = true;
$denied                = $controller->permissions_review( $request );
g4_s02_rest_assert( $denied instanceof WP_Error, 'Permission revocation must deny C34 before application execution.' );
g4_s02_rest_same( 403, $denied->get_error_data()['status'], 'Revoked C34 permission must return 403.' );
g4_s02_rest_same( $records_before_revoke, count( $repository->records ), 'Revoked C34 permission must append zero reviews.' );
$policy->deny = false;
g4_s02_rest_same( 5, $GLOBALS['g4_s02_rest_nonce_calls'], 'Each C34 mutation permission check must verify the REST nonce exactly once.' );

$repository->snapshot['evaluation_state']     = 'reviewed';
$repository->snapshot['official_review_id']   = $envelope['data']['review']['id'];
$repository->snapshot['official_review_hash'] = $envelope['data']['review']['hash'];
$policy->state                                = 'reviewed';
$withdraw_body                                = g4_s02_rest_body( $repository->snapshot, 'command-g4-rest-withdraw' );
$withdraw_body['payload']                     = array(
	'decision'       => 'withdrawn',
	'item_decisions' => array(),
	'reason'         => 'Review the score again.',
);
$withdraw_request                             = new G4_S02_Rest_Request( $withdraw_body, $evaluation_id, g4_s02_rest_context(), 'idempotency-g4-rest-withdraw' );
g4_s02_rest_same( true, $controller->permissions_review( $withdraw_request ), 'Withdrawal uses the same nonce and context/object permission handoff.' );
$withdraw_response = $controller->review( $withdraw_request );
g4_s02_rest_assert( $withdraw_response instanceof WP_REST_Response, 'Withdrawal returns a review receipt.' );
g4_s02_rest_same( 'withdrawn', $withdraw_response->get_data()['data']['review']['decision'], 'Withdrawal is distinct from rejection.' );
g4_s02_rest_same( null, $withdraw_response->get_data()['data']['official_review']['id'], 'Withdrawal response clears the official pointer.' );
$withdraw_count = count( $repository->records );
g4_s02_rest_same( true, $controller->permissions_review( $withdraw_request ), 'Withdrawal replay is reauthorized.' );
$withdraw_replay = $controller->review( $withdraw_request );
g4_s02_rest_same( 200, $withdraw_replay->get_status(), 'Identical withdrawal reuses its receipt.' );
g4_s02_rest_same( $withdraw_count, count( $repository->records ), 'Withdrawal replay appends no extra history.' );

$controller_source = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Evaluation_Review_Rest_Controller.php' );
$object_source     = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/Submission_Evaluation_Object_Authorization.php' );
$root_source       = (string) file_get_contents( $addon_root . '/src/Core/Composition_Root.php' );
foreach ( array(
	'Addon_Capabilities::REVIEW_ASSESSMENTS',
	'Context_Scope::OPERATION_EVALUATE',
	'Object_Scope::TYPE_EVALUATION, $evaluation_id, \'review\'',
	"array( 'command_id', 'expected', 'payload' )",
	"array( 'decision', 'item_decisions', 'reason' )",
) as $marker ) {
	g4_s02_rest_assert( str_contains( $controller_source, $marker ), 'C34 REST marker is missing: ' . $marker );
}
g4_s02_rest_assert( str_contains( $object_source, "'view', 'retry', 'cancel', 'review'" ), 'Submission evaluation object authorization must allow review.' );
g4_s02_rest_assert( str_contains( $root_source, 'new Submission_Evaluation_Review_Rest_Module' ), 'Composition Root must register the C34 module.' );
g4_s02_rest_assert( str_contains( $root_source, 'new WordPress_Submission_Evaluation_Review_Repository' ), 'Composition Root must activate the S02 transaction repository.' );
g4_s02_rest_assert( ! str_contains( $controller_source, '$wpdb->prefix' ) && ! str_contains( $controller_source, 'wp_oj_ai_' ), 'C34 REST must not introduce prefixed tables.' );
g4_s02_rest_assert( ! str_contains( $controller_source, 'CodingPanda\\OjPlatform\\Kernel\\' ), 'C34 REST must use only public platform boundaries.' );

fwrite( STDOUT, "G4-IMP-03-S02 C34 REST and authorization contract passed.\n" );
