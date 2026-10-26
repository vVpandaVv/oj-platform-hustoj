<?php
/**
 * G4-IMP-04-S03 C37 REST and authorization contract.
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
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Feedback_Approval_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-04-S03 C37 REST and authorization contract.
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
	 * G4-IMP-04-S03 C37 REST and authorization contract.
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

$GLOBALS['g4_feedback_approval_rest_actor']       = 42;
$GLOBALS['g4_feedback_approval_rest_nonce_calls'] = 0;
$GLOBALS['g4_feedback_approval_rest_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_feedback_approval_rest_actor'];
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
		++$GLOBALS['g4_feedback_approval_rest_nonce_calls'];
		return 'valid-g4-feedback-approval-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_feedback_approval_rest_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

ob_start();
require __DIR__ . '/submission-feedback-approval-g4-imp-04-s03.php';
ob_end_clean();

/**
 * G4-IMP-04-S03 C37 REST and authorization contract.
 */
final class G4_Feedback_Approval_Rest_Policy implements Access_Policy_Port_Interface {
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
	 * Initialize g4 feedback approval rest policy dependencies and state.
	 *
	 * @param string $evaluation_id Evaluation id.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $object_revision Object revision.
	 */
	public function __construct(
		private string $evaluation_id,
		private string $context_id,
		private string $context_revision,
		private string $object_revision
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
			return Access_Policy_Decision::denied( 403, 'submission_feedback_approval_scope_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified()
			|| 42 !== $request->actor_user_id()
			|| Submission_Feedback_Approval_Rest_Controller::ACTION_APPROVE !== $request->action_key()
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
			return Access_Policy_Decision::denied( 403, 'submission_feedback_approval_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( $this->object_revision, 'reviewed', 'context_staff' )
		);
	}
}

/**
 * G4-IMP-04-S03 C37 REST and authorization contract.
 */
final class G4_Feedback_Approval_Rest_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers;

	/**
	 * Initialize g4 feedback approval rest request dependencies and state.
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
			'x-wp-nonce'      => 'valid-g4-feedback-approval-nonce',
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
 * G4 feedback approval rest context.
 *
 * @param array<string,mixed> $source Source.
 * @return array<string,string>
 */
function g4_feedback_approval_rest_context( array $source ): array {
	return array(
		'context_type'     => $source['context_type'],
		'context_id'       => $source['context_id'],
		'context_revision' => $source['context_revision'],
	);
}

/**
 * G4 feedback approval rest body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @return array<string,mixed>
 */
function g4_feedback_approval_rest_body( array $snapshot, string $command_id ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_feedback_revision_hash' => $snapshot['latest_feedback_revision_hash'],
			'expected_feedback_revision_id'   => $snapshot['latest_feedback_revision_id'],
			'expected_feedback_revision_no'   => $snapshot['latest_feedback_revision_no'],
			'expected_official_review_hash'   => $snapshot['official_review_hash'],
			'expected_official_review_id'     => $snapshot['official_review_id'],
		),
		'payload'    => array(),
	);
}

$approval_rest_canonical    = new Canonical_Json();
$approval_rest_fixture      = g4_feedback_approval_fixture( $approval_rest_canonical, true );
$approval_rest_source       = $approval_rest_fixture['source'];
$approval_rest_snapshot     = $approval_rest_fixture['snapshot'];
$approval_rest_repository   = new G4_Feedback_Approval_Repository( $approval_rest_snapshot );
$approval_rest_application  = new Approve_Submission_Feedback(
	new G4_Feedback_Review_Source( $approval_rest_source ),
	$approval_rest_repository,
	new Submission_Feedback_Input_Builder( $approval_rest_canonical ),
	new Submission_Feedback_Output_Normalizer( $approval_rest_canonical ),
	new Public_Id_Generator(),
	$approval_rest_canonical,
	static fn (): string => '2026-08-24 17:00:00'
);
$approval_rest_policy       = new G4_Feedback_Approval_Rest_Policy(
	$approval_rest_source['evaluation_id'],
	$approval_rest_source['context_id'],
	$approval_rest_source['context_revision'],
	(string) $approval_rest_source['evaluation_lock_version']
);
$approval_rest_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-feedback-approval-rest' );
$approval_rest_envelopes    = new Rest_Envelope_Factory();
$approval_rest_permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $approval_rest_policy, new WordPress_Rest_Nonce_Verifier(), $approval_rest_correlations ),
	$approval_rest_envelopes,
	$approval_rest_correlations
);
$approval_rest_controller   = new Submission_Feedback_Approval_Rest_Controller(
	$approval_rest_permissions,
	$approval_rest_application,
	$approval_rest_canonical,
	$approval_rest_envelopes,
	$approval_rest_correlations
);
$approval_rest_controller->register_routes();
$approval_rest_controller->register_routes();
g4_feedback_review_same( 1, count( $GLOBALS['g4_feedback_approval_rest_routes'] ), 'C37 must register exactly once.' );
g4_feedback_review_same( 'oj-ai/v1', $GLOBALS['g4_feedback_approval_rest_routes'][0][0], 'C37 namespace drifted.' );
g4_feedback_review_same( Submission_Feedback_Approval_Rest_Controller::ROUTE_APPROVE, $GLOBALS['g4_feedback_approval_rest_routes'][0][1], 'C37 route drifted.' );
g4_feedback_review_same( 'POST', $GLOBALS['g4_feedback_approval_rest_routes'][0][2]['methods'], 'C37 must use POST.' );

$approval_rest_body    = g4_feedback_approval_rest_body( $approval_rest_snapshot, 'command-g4-feedback-approval-rest' );
$approval_rest_request = new G4_Feedback_Approval_Rest_Request(
	$approval_rest_body,
	$approval_rest_source['evaluation_id'],
	g4_feedback_approval_rest_context( $approval_rest_source ),
	'idempotency-g4-feedback-approval-rest'
);
g4_feedback_review_same( true, $approval_rest_controller->permissions_approve( $approval_rest_request ), 'C37 permission must use the common REST handoff.' );
$approval_rest_response = $approval_rest_controller->approve( $approval_rest_request );
g4_feedback_review_assert( $approval_rest_response instanceof WP_REST_Response, 'C37 success must return a REST response.' );
g4_feedback_review_same( 201, $approval_rest_response->get_status(), 'New C37 approved revision must return 201.' );
$approval_rest_data = $approval_rest_response->get_data();
g4_feedback_review_same( 'approved', $approval_rest_data['data']['feedback_revision']['state'], 'C37 response state drifted.' );
g4_feedback_review_same( $approval_rest_snapshot['latest_feedback_revision_no'] + 1, $approval_rest_data['data']['feedback_revision']['revision_no'], 'C37 response revision number drifted.' );
g4_feedback_review_same( $approval_rest_snapshot['latest_feedback_revision_id'], $approval_rest_data['data']['feedback_revision']['previous_revision_id'], 'C37 response must expose the exact approved source.' );
g4_feedback_review_same( false, $approval_rest_data['meta']['idempotent_replay'], 'New C37 response must not report replay.' );
g4_feedback_review_same( Submission_Feedback_Approval_Rest_Controller::ACTION_APPROVE, $approval_rest_policy->last_request?->action_key(), 'C37 policy action drifted.' );
g4_feedback_review_same( array( Addon_Capabilities::REVIEW_ASSESSMENTS ), $approval_rest_policy->last_request?->required_capabilities(), 'C37 capability drifted.' );

g4_feedback_review_same( true, $approval_rest_controller->permissions_approve( $approval_rest_request ), 'Exact C37 retry must reauthorize.' );
$approval_rest_replay = $approval_rest_controller->approve( $approval_rest_request );
g4_feedback_review_assert( $approval_rest_replay instanceof WP_REST_Response, 'Exact C37 retry must return a REST response.' );
g4_feedback_review_same( 201, $approval_rest_replay->get_status(), 'Exact C37 replay retains the command resource status.' );
g4_feedback_review_same( true, $approval_rest_replay->get_data()['meta']['idempotent_replay'], 'Exact C37 retry must use the durable receipt.' );
g4_feedback_review_same( 1, $approval_rest_repository->append_count, 'Exact C37 retry must append zero additional revisions.' );

$approval_invalid_body                        = $approval_rest_body;
$approval_invalid_body['payload']['feedback'] = array();
$approval_invalid_request                     = new G4_Feedback_Approval_Rest_Request(
	$approval_invalid_body,
	$approval_rest_source['evaluation_id'],
	g4_feedback_approval_rest_context( $approval_rest_source ),
	'idempotency-g4-feedback-approval-invalid'
);
g4_feedback_review_same( true, $approval_rest_controller->permissions_approve( $approval_invalid_request ), 'C37 permission must remain independent of body parsing.' );
$approval_invalid_response = $approval_rest_controller->approve( $approval_invalid_request );
g4_feedback_review_assert( $approval_invalid_response instanceof WP_Error, 'Non-empty C37 payload must fail.' );
g4_feedback_review_same( 422, $approval_invalid_response->get_error_data()['status'], 'Non-empty C37 payload must return 422.' );

$approval_conflict_body    = g4_feedback_approval_rest_body( $approval_rest_snapshot, 'command-g4-feedback-approval-rest-conflict' );
$approval_conflict_request = new G4_Feedback_Approval_Rest_Request(
	$approval_conflict_body,
	$approval_rest_source['evaluation_id'],
	g4_feedback_approval_rest_context( $approval_rest_source ),
	'idempotency-g4-feedback-approval-rest'
);
g4_feedback_review_same( true, $approval_rest_controller->permissions_approve( $approval_conflict_request ), 'C37 idempotency conflict request must reauthorize.' );
$approval_conflict_response = $approval_rest_controller->approve( $approval_conflict_request );
g4_feedback_review_assert( $approval_conflict_response instanceof WP_Error, 'Changed C37 request under one idempotency key must fail.' );
g4_feedback_review_same( 409, $approval_conflict_response->get_error_data()['status'], 'C37 idempotency conflict must return 409.' );

$approval_records_before_revoke = $approval_rest_repository->append_count;
$approval_rest_policy->deny     = true;
$approval_denied                = $approval_rest_controller->permissions_approve( $approval_rest_request );
g4_feedback_review_assert( $approval_denied instanceof WP_Error, 'Permission revocation must deny C37 before application execution.' );
g4_feedback_review_same( 403, $approval_denied->get_error_data()['status'], 'Revoked C37 permission must return 403.' );
g4_feedback_review_same( $approval_records_before_revoke, $approval_rest_repository->append_count, 'Revoked C37 permission must append zero revisions.' );
g4_feedback_review_same( 5, $GLOBALS['g4_feedback_approval_rest_nonce_calls'], 'Each C37 mutation permission check must verify the REST nonce exactly once.' );

fwrite( STDOUT, "G4-IMP-04-S03 C37 REST, common authorization, empty payload, replay, and conflict contracts passed.\n" );
