<?php
/**
 * G4-IMP-06-S03 C44 REST and authorization contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

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
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Result_Withdrawal_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-06-S03 C44 REST and authorization contract.
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
	 * G4-IMP-06-S03 C44 REST and authorization contract.
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

$GLOBALS['g4_result_withdrawal_rest_actor']       = 42;
$GLOBALS['g4_result_withdrawal_rest_nonce_calls'] = 0;
$GLOBALS['g4_result_withdrawal_rest_routes']      = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_result_withdrawal_rest_actor'];
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
		++$GLOBALS['g4_result_withdrawal_rest_nonce_calls'];
		return 'valid-g4-result-withdrawal-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_result_withdrawal_rest_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}

}

ob_start();
require __DIR__ . '/submission-result-withdrawal-g4-imp-06-s03.php';
ob_end_clean();

/**
 * G4-IMP-06-S03 C44 REST and authorization contract.
 */
final class G4_Result_Withdrawal_Rest_Policy implements Access_Policy_Port_Interface {
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
	 * Initialize g4 result withdrawal rest policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $object_revision Object revision.
	 */
	public function __construct( private string $evaluation_id, private string $context_id, private string $context_revision, private string $object_revision ) {
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
			return Access_Policy_Decision::denied( 403, 'submission_result_withdrawal_scope_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id()
			|| Submission_Result_Withdrawal_Rest_Controller::ACTION_WITHDRAW !== $request->action_key()
			|| array( Addon_Capabilities::PUBLISH_FEEDBACK ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope || 'class' !== $context->type() || $this->context_id !== $context->id() || Context_Scope::OPERATION_EVALUATE !== $context->operation() || $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_EVALUATION !== $object->type() || $this->evaluation_id !== $object->id() || 'withdraw_release' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'submission_result_withdrawal_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id(), Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ), Object_Authorization_Result::allowed( $this->object_revision, 'reviewed', 'context_staff' ) );
	}
}

/**
 * G4-IMP-06-S03 C44 REST and authorization contract.
 */
final class G4_Result_Withdrawal_Rest_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers;
	/**
	 * Initialize g4 result withdrawal rest request dependencies and state.
	 *
	 * @param array<string,mixed>  $json Json.
	 * @param string               $evaluation_id Evaluation id.
	 * @param array<string,string> $query Query.
	 * @param string               $idempotency_key Idempotency key.
	 */
	public function __construct( private array $json, private string $evaluation_id, private array $query, string $idempotency_key ) {
		$this->headers = array(
			'x-wp-nonce'      => 'valid-g4-result-withdrawal-nonce',
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
 * G4 result withdrawal rest context.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @return array<string,string>
 */
function g4_result_withdrawal_rest_context( array $snapshot ): array {
	return array(
		'context_type'     => $snapshot['context_type'],
		'context_id'       => $snapshot['context_id'],
		'context_revision' => $snapshot['context_revision'],
	);
}

/**
 * G4 result withdrawal rest body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $reason_code Reason code.
 * @param string|null         $reason_note Reason note.
 * @return array<string,mixed>
 */
function g4_result_withdrawal_rest_body( array $snapshot, string $command_id, string $reason_code = 'data_correction', ?string $reason_note = null ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_current_release_hash' => $snapshot['current_release_hash'],
			'expected_current_release_id'   => $snapshot['current_release_id'],
		),
		'payload'    => array(
			'reason_code' => $reason_code,
			'reason_note' => $reason_note,
		),
	);
}

$withdrawal_rest_canonical    = new Canonical_Json();
$withdrawal_rest_fixture      = g4_withdrawal_fixture( $withdrawal_rest_canonical );
$withdrawal_rest_snapshot     = $withdrawal_rest_fixture['snapshot'];
$withdrawal_rest_repository   = new G4_Result_Withdrawal_Repository( $withdrawal_rest_snapshot );
$withdrawal_rest_application  = g4_withdrawal_application( $withdrawal_rest_repository, $withdrawal_rest_canonical, '2026-08-25 00:30:00' );
$withdrawal_rest_policy       = new G4_Result_Withdrawal_Rest_Policy( $withdrawal_rest_snapshot['evaluation_id'], $withdrawal_rest_snapshot['context_id'], $withdrawal_rest_snapshot['context_revision'], (string) $withdrawal_rest_snapshot['evaluation_lock_version'] );
$withdrawal_rest_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-result-withdrawal-rest' );
$withdrawal_rest_envelopes    = new Rest_Envelope_Factory();
$withdrawal_rest_permissions  = new Rest_Permission_Handoff( new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ), new Rest_Request_Authorizer( $withdrawal_rest_policy, new WordPress_Rest_Nonce_Verifier(), $withdrawal_rest_correlations ), $withdrawal_rest_envelopes, $withdrawal_rest_correlations );
$withdrawal_rest_controller   = new Submission_Result_Withdrawal_Rest_Controller( $withdrawal_rest_permissions, $withdrawal_rest_application, $withdrawal_rest_canonical, $withdrawal_rest_envelopes, $withdrawal_rest_correlations );
$withdrawal_rest_controller->register_routes();
$withdrawal_rest_controller->register_routes();
g4_release_same( 1, count( $GLOBALS['g4_result_withdrawal_rest_routes'] ), 'C44 must register exactly once.' );
g4_release_same( 'oj-ai/v1', $GLOBALS['g4_result_withdrawal_rest_routes'][0][0], 'C44 namespace drifted.' );
g4_release_same( Submission_Result_Withdrawal_Rest_Controller::ROUTE_WITHDRAW, $GLOBALS['g4_result_withdrawal_rest_routes'][0][1], 'C44 route drifted.' );
g4_release_same( 'POST', $GLOBALS['g4_result_withdrawal_rest_routes'][0][2]['methods'], 'C44 must use POST.' );

$withdrawal_rest_body    = g4_result_withdrawal_rest_body( $withdrawal_rest_snapshot, 'command-g4-result-withdrawal-rest', 'other', 'Teacher-only withdrawal detail.' );
$withdrawal_rest_request = new G4_Result_Withdrawal_Rest_Request( $withdrawal_rest_body, $withdrawal_rest_snapshot['evaluation_id'], g4_result_withdrawal_rest_context( $withdrawal_rest_snapshot ), 'idempotency-g4-result-withdrawal-rest' );
g4_release_same( true, $withdrawal_rest_controller->permissions_withdraw( $withdrawal_rest_request ), 'C44 permission must use the common REST handoff.' );
$withdrawal_rest_response = $withdrawal_rest_controller->withdraw( $withdrawal_rest_request );
g4_release_assert( $withdrawal_rest_response instanceof WP_REST_Response, 'C44 success must return a REST response.' );
g4_release_same( 201, $withdrawal_rest_response->get_status(), 'New C44 withdrawal must return 201.' );
$withdrawal_rest_data = $withdrawal_rest_response->get_data();
g4_release_same( 'withdrawn', $withdrawal_rest_data['data']['release']['state'], 'C44 response state drifted.' );
g4_release_same( $withdrawal_rest_snapshot['current_release_id'], $withdrawal_rest_data['data']['release']['previous_release_id'], 'C44 response previous pointer drifted.' );
g4_release_same( 'other', $withdrawal_rest_data['data']['release']['reason_code'], 'C44 response reason code drifted.' );
g4_release_same( Submission_Result_Withdrawal_Rest_Controller::ACTION_WITHDRAW, $withdrawal_rest_policy->last_request?->action_key(), 'C44 policy action drifted.' );
g4_release_same( array( Addon_Capabilities::PUBLISH_FEEDBACK ), $withdrawal_rest_policy->last_request?->required_capabilities(), 'C44 capability drifted.' );
$withdrawal_response_json = json_encode( $withdrawal_rest_data, JSON_THROW_ON_ERROR );
foreach ( array( 'Teacher-only withdrawal detail.', 'reason_note', 'visible_score', 'overall_feedback', 'student_projection_json' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $withdrawal_response_json, $forbidden ), 'C44 command response leaked private data: ' . $forbidden );
}

g4_release_same( true, $withdrawal_rest_controller->permissions_withdraw( $withdrawal_rest_request ), 'Exact C44 retry must reauthorize.' );
$withdrawal_rest_replay = $withdrawal_rest_controller->withdraw( $withdrawal_rest_request );
g4_release_assert( $withdrawal_rest_replay instanceof WP_REST_Response, 'Exact C44 retry must return a REST response.' );
g4_release_same( true, $withdrawal_rest_replay->get_data()['meta']['idempotent_replay'], 'Exact C44 retry must use the durable receipt.' );
g4_release_same( 1, $withdrawal_rest_repository->append_count, 'Exact C44 retry must append zero revisions.' );

$withdrawal_invalid_body                       = $withdrawal_rest_body;
$withdrawal_invalid_body['payload']['unknown'] = true;
$withdrawal_invalid_request                    = new G4_Result_Withdrawal_Rest_Request( $withdrawal_invalid_body, $withdrawal_rest_snapshot['evaluation_id'], g4_result_withdrawal_rest_context( $withdrawal_rest_snapshot ), 'idempotency-g4-result-withdrawal-invalid' );
g4_release_same( true, $withdrawal_rest_controller->permissions_withdraw( $withdrawal_invalid_request ), 'C44 permission must remain independent of body parsing.' );
$withdrawal_invalid_response = $withdrawal_rest_controller->withdraw( $withdrawal_invalid_request );
g4_release_assert( $withdrawal_invalid_response instanceof WP_Error, 'Unknown C44 payload field must fail.' );
g4_release_same( 422, $withdrawal_invalid_response->get_error_data()['status'], 'Unknown C44 payload field must return 422.' );

$withdrawal_other_request = new G4_Result_Withdrawal_Rest_Request( g4_result_withdrawal_rest_body( $withdrawal_rest_snapshot, 'command-g4-result-withdrawal-other', 'other', null ), $withdrawal_rest_snapshot['evaluation_id'], g4_result_withdrawal_rest_context( $withdrawal_rest_snapshot ), 'idempotency-g4-result-withdrawal-other' );
g4_release_same( true, $withdrawal_rest_controller->permissions_withdraw( $withdrawal_other_request ), 'Incomplete C44 reason must still reauthorize.' );
$withdrawal_other_response = $withdrawal_rest_controller->withdraw( $withdrawal_other_request );
g4_release_assert( $withdrawal_other_response instanceof WP_Error, 'Other C44 reason without note must fail.' );
g4_release_same( 422, $withdrawal_other_response->get_error_data()['status'], 'Incomplete C44 reason must return 422.' );

$withdrawal_state_request = new G4_Result_Withdrawal_Rest_Request( g4_result_withdrawal_rest_body( $withdrawal_rest_snapshot, 'command-g4-result-withdrawal-state' ), $withdrawal_rest_snapshot['evaluation_id'], g4_result_withdrawal_rest_context( $withdrawal_rest_snapshot ), 'idempotency-g4-result-withdrawal-state' );
g4_release_same( true, $withdrawal_rest_controller->permissions_withdraw( $withdrawal_state_request ), 'Repeated C44 request must reauthorize.' );
$withdrawal_state_response = $withdrawal_rest_controller->withdraw( $withdrawal_state_request );
g4_release_assert( $withdrawal_state_response instanceof WP_Error, 'Already withdrawn release under a new key must conflict.' );
g4_release_same( 409, $withdrawal_state_response->get_error_data()['status'], 'Already withdrawn release must return 409.' );

$withdrawal_records_before_revoke = $withdrawal_rest_repository->append_count;
$withdrawal_rest_policy->deny     = true;
$withdrawal_denied                = $withdrawal_rest_controller->permissions_withdraw( $withdrawal_rest_request );
g4_release_assert( $withdrawal_denied instanceof WP_Error, 'Permission revocation must deny C44 before application execution.' );
g4_release_same( 403, $withdrawal_denied->get_error_data()['status'], 'Revoked C44 permission must return 403.' );
g4_release_same( $withdrawal_records_before_revoke, $withdrawal_rest_repository->append_count, 'Revoked C44 permission must append zero revisions.' );
g4_release_same( 6, $GLOBALS['g4_result_withdrawal_rest_nonce_calls'], 'Each C44 mutation permission check must verify the REST nonce exactly once.' );

fwrite( STDOUT, "G4-IMP-06-S03 C44 REST passed: frozen route, Console publish permission, strict DTO, private-note suppression, replay, state conflict, and revocation are enforced.\n" );
