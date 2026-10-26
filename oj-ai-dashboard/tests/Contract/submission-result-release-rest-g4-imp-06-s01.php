<?php
/**
 * G4-IMP-06-S01 C43 REST and authorization contract.
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
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Result_Release_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-06-S01 C43 REST and authorization contract.
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
	 * G4-IMP-06-S01 C43 REST and authorization contract.
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

$GLOBALS['g4_result_release_rest_actor']       = 42;
$GLOBALS['g4_result_release_rest_nonce_calls'] = 0;
$GLOBALS['g4_result_release_rest_routes']      = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_result_release_rest_actor'];
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
		++$GLOBALS['g4_result_release_rest_nonce_calls'];
		return 'valid-g4-result-release-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_result_release_rest_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}

}

ob_start();
require __DIR__ . '/submission-result-release-g4-imp-06-s01.php';
ob_end_clean();

/**
 * G4-IMP-06-S01 C43 REST and authorization contract.
 */
final class G4_Result_Release_Rest_Policy implements Access_Policy_Port_Interface {
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
	 * Initialize g4 result release rest policy dependencies and state.
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
			return Access_Policy_Decision::denied( 403, 'submission_result_release_scope_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id()
			|| Submission_Result_Release_Rest_Controller::ACTION_RELEASE !== $request->action_key()
			|| array( Addon_Capabilities::PUBLISH_FEEDBACK ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope || 'class' !== $context->type() || $this->context_id !== $context->id() || Context_Scope::OPERATION_EVALUATE !== $context->operation() || $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_EVALUATION !== $object->type() || $this->evaluation_id !== $object->id() || 'release' !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'submission_result_release_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed( $request->correlation_id(), Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ), Object_Authorization_Result::allowed( $this->object_revision, 'reviewed', 'context_staff' ) );
	}
}

/**
 * G4-IMP-06-S01 C43 REST and authorization contract.
 */
final class G4_Result_Release_Rest_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers;
	/**
	 * Initialize g4 result release rest request dependencies and state.
	 *
	 * @param array<string,mixed>  $json Json.
	 * @param string               $evaluation_id Evaluation id.
	 * @param array<string,string> $query Query.
	 * @param string               $idempotency_key Idempotency key.
	 */
	public function __construct( private array $json, private string $evaluation_id, private array $query, string $idempotency_key ) {
		$this->headers = array(
			'x-wp-nonce'      => 'valid-g4-result-release-nonce',
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
 * G4 result release rest context.
 *
 * @param array<string,mixed> $source Source.
 * @return array<string,string>
 */
function g4_result_release_rest_context( array $source ): array {
	return array(
		'context_type'     => $source['context_type'],
		'context_id'       => $source['context_id'],
		'context_revision' => $source['context_revision'],
	);
}
/**
 * G4 result release rest body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @param string              $score Score.
 * @param string              $feedback Feedback.
 * @return array<string,mixed>
 */
function g4_result_release_rest_body( array $snapshot, string $command_id, string $score = 'total_and_criteria', string $feedback = 'shown' ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_current_release_hash'   => null,
			'expected_current_release_id'     => null,
			'expected_feedback_revision_hash' => 'shown' === $feedback ? $snapshot['latest_feedback_revision_hash'] : null,
			'expected_feedback_revision_id'   => 'shown' === $feedback ? $snapshot['latest_feedback_revision_id'] : null,
			'expected_official_review_hash'   => $snapshot['official_review_hash'],
			'expected_official_review_id'     => $snapshot['official_review_id'],
		),
		'payload'    => array(
			'feedback_visibility' => $feedback,
			'mode'                => 'release',
			'score_visibility'    => $score,
		),
	);
}

$release_rest_canonical    = new Canonical_Json();
$release_rest_fixture      = g4_release_fixture( $release_rest_canonical );
$release_rest_source       = $release_rest_fixture['source'];
$release_rest_snapshot     = $release_rest_fixture['snapshot'];
$release_rest_repository   = new G4_Result_Release_Repository( $release_rest_snapshot );
$release_rest_application  = g4_release_application( $release_rest_source, $release_rest_repository, $release_rest_canonical, '2026-08-24 23:30:00' );
$release_rest_policy       = new G4_Result_Release_Rest_Policy( $release_rest_source['evaluation_id'], $release_rest_source['context_id'], $release_rest_source['context_revision'], (string) $release_rest_source['evaluation_lock_version'] );
$release_rest_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-result-release-rest' );
$release_rest_envelopes    = new Rest_Envelope_Factory();
$release_rest_permissions  = new Rest_Permission_Handoff( new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ), new Rest_Request_Authorizer( $release_rest_policy, new WordPress_Rest_Nonce_Verifier(), $release_rest_correlations ), $release_rest_envelopes, $release_rest_correlations );
$release_rest_controller   = new Submission_Result_Release_Rest_Controller( $release_rest_permissions, $release_rest_application, $release_rest_canonical, $release_rest_envelopes, $release_rest_correlations );
$release_rest_controller->register_routes();
$release_rest_controller->register_routes();
g4_release_same( 1, count( $GLOBALS['g4_result_release_rest_routes'] ), 'C43 must register exactly once.' );
g4_release_same( 'oj-ai/v1', $GLOBALS['g4_result_release_rest_routes'][0][0], 'C43 namespace drifted.' );
g4_release_same( Submission_Result_Release_Rest_Controller::ROUTE_RELEASE, $GLOBALS['g4_result_release_rest_routes'][0][1], 'C43 route drifted.' );
g4_release_same( 'POST', $GLOBALS['g4_result_release_rest_routes'][0][2]['methods'], 'C43 must use POST.' );

$release_rest_body    = g4_result_release_rest_body( $release_rest_snapshot, 'command-g4-result-release-rest' );
$release_rest_request = new G4_Result_Release_Rest_Request( $release_rest_body, $release_rest_source['evaluation_id'], g4_result_release_rest_context( $release_rest_source ), 'idempotency-g4-result-release-rest' );
g4_release_same( true, $release_rest_controller->permissions_release( $release_rest_request ), 'C43 permission must use the common REST handoff.' );
$release_rest_response = $release_rest_controller->release( $release_rest_request );
g4_release_assert( $release_rest_response instanceof WP_REST_Response, 'C43 success must return a REST response.' );
g4_release_same( 201, $release_rest_response->get_status(), 'New C43 release must return 201.' );
$release_rest_data = $release_rest_response->get_data();
g4_release_same( 'released', $release_rest_data['data']['release']['state'], 'C43 response state drifted.' );
g4_release_same( '/oj-addons/oj-ai-dashboard/results/' . $release_rest_data['data']['release']['id'], $release_rest_data['data']['release']['path'], 'C43 response path drifted.' );
g4_release_same( false, $release_rest_data['meta']['idempotent_replay'], 'New C43 response must not report replay.' );
g4_release_same( Submission_Result_Release_Rest_Controller::ACTION_RELEASE, $release_rest_policy->last_request?->action_key(), 'C43 policy action drifted.' );
g4_release_same( array( Addon_Capabilities::PUBLISH_FEEDBACK ), $release_rest_policy->last_request?->required_capabilities(), 'C43 capability drifted.' );
$release_response_json = json_encode( $release_rest_data, JSON_THROW_ON_ERROR );
foreach ( array( 'visible_score', 'overall_feedback', 'criterion_feedback', 'student_projection_json' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $release_response_json, $forbidden ), 'C43 command response leaked student result body: ' . $forbidden );
}

g4_release_same( true, $release_rest_controller->permissions_release( $release_rest_request ), 'Exact C43 retry must reauthorize.' );
$release_rest_replay = $release_rest_controller->release( $release_rest_request );
g4_release_assert( $release_rest_replay instanceof WP_REST_Response, 'Exact C43 retry must return a REST response.' );
g4_release_same( true, $release_rest_replay->get_data()['meta']['idempotent_replay'], 'Exact C43 retry must use the durable receipt.' );
g4_release_same( 1, $release_rest_repository->append_count, 'Exact C43 retry must append zero releases.' );

$release_invalid_body                      = $release_rest_body;
$release_invalid_body['payload']['reason'] = 'not-accepted-in-first-release';
$release_invalid_request                   = new G4_Result_Release_Rest_Request( $release_invalid_body, $release_rest_source['evaluation_id'], g4_result_release_rest_context( $release_rest_source ), 'idempotency-g4-result-release-invalid' );
g4_release_same( true, $release_rest_controller->permissions_release( $release_invalid_request ), 'C43 permission must remain independent of body parsing.' );
$release_invalid_response = $release_rest_controller->release( $release_invalid_request );
g4_release_assert( $release_invalid_response instanceof WP_Error, 'Unknown C43 payload field must fail.' );
g4_release_same( 422, $release_invalid_response->get_error_data()['status'], 'Unknown C43 payload field must return 422.' );

$release_empty_request = new G4_Result_Release_Rest_Request( g4_result_release_rest_body( $release_rest_snapshot, 'command-g4-result-release-empty', 'hidden', 'hidden' ), $release_rest_source['evaluation_id'], g4_result_release_rest_context( $release_rest_source ), 'idempotency-g4-result-release-empty' );
g4_release_same( true, $release_rest_controller->permissions_release( $release_empty_request ), 'Empty C43 projection request must still reauthorize.' );
$release_empty_response = $release_rest_controller->release( $release_empty_request );
g4_release_assert( $release_empty_response instanceof WP_Error, 'Empty C43 projection must fail.' );
g4_release_same( 422, $release_empty_response->get_error_data()['status'], 'Empty C43 projection must return 422.' );

$release_conflict_body    = g4_result_release_rest_body( $release_rest_snapshot, 'command-g4-result-release-conflict' );
$release_conflict_request = new G4_Result_Release_Rest_Request( $release_conflict_body, $release_rest_source['evaluation_id'], g4_result_release_rest_context( $release_rest_source ), 'idempotency-g4-result-release-rest' );
g4_release_same( true, $release_rest_controller->permissions_release( $release_conflict_request ), 'C43 idempotency conflict request must reauthorize.' );
$release_conflict_response = $release_rest_controller->release( $release_conflict_request );
g4_release_assert( $release_conflict_response instanceof WP_Error, 'Changed C43 request under one idempotency key must fail.' );
g4_release_same( 409, $release_conflict_response->get_error_data()['status'], 'C43 idempotency conflict must return 409.' );

$release_records_before_revoke = $release_rest_repository->append_count;
$release_rest_policy->deny     = true;
$release_denied                = $release_rest_controller->permissions_release( $release_rest_request );
g4_release_assert( $release_denied instanceof WP_Error, 'Permission revocation must deny C43 before application execution.' );
g4_release_same( 403, $release_denied->get_error_data()['status'], 'Revoked C43 permission must return 403.' );
g4_release_same( $release_records_before_revoke, $release_rest_repository->append_count, 'Revoked C43 permission must append zero releases.' );
g4_release_same( 6, $GLOBALS['g4_result_release_rest_nonce_calls'], 'Each C43 mutation permission check must verify the REST nonce exactly once.' );

fwrite( STDOUT, "G4-IMP-06-S01 C43 REST passed: frozen route, Console publish permission, strict DTO, body-free response, replay, conflict, and revocation are enforced.\n" );
