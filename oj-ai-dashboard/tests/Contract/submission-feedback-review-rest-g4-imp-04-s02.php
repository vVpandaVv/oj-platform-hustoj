<?php
/**
 * G4-IMP-04-S02 C36 REST and authorization contract.
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
use OJAI\Dashboard\Submission\Assessment\Application\Review_Submission_Feedback;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Output_Normalizer;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Feedback_Review_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-04-S02 C36 REST and authorization contract.
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
	 * G4-IMP-04-S02 C36 REST and authorization contract.
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

$GLOBALS['g4_feedback_rest_actor']       = 42;
$GLOBALS['g4_feedback_rest_nonce_calls'] = 0;
$GLOBALS['g4_feedback_rest_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_feedback_rest_actor'];
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
		++$GLOBALS['g4_feedback_rest_nonce_calls'];
		return 'valid-g4-feedback-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_feedback_rest_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

ob_start();
require __DIR__ . '/submission-feedback-review-g4-imp-04-s02.php';
ob_end_clean();

/**
 * G4-IMP-04-S02 C36 REST and authorization contract.
 */
final class G4_Feedback_Rest_Policy implements Access_Policy_Port_Interface {
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
	 * Initialize g4 feedback rest policy dependencies and state.
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
			return Access_Policy_Decision::denied( 403, 'submission_feedback_review_scope_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified()
			|| 42 !== $request->actor_user_id()
			|| Submission_Feedback_Review_Rest_Controller::ACTION_REVIEW !== $request->action_key()
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
			return Access_Policy_Decision::denied( 403, 'submission_feedback_review_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( $this->object_revision, 'reviewed', 'context_staff' )
		);
	}
}

/**
 * G4-IMP-04-S02 C36 REST and authorization contract.
 */
final class G4_Feedback_Rest_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers;

	/**
	 * Initialize g4 feedback rest request dependencies and state.
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
			'x-wp-nonce'      => 'valid-g4-feedback-nonce',
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
 * G4 feedback rest context.
 *
 * @param array<string,mixed> $source Source.
 * @return array<string,string>
 */
function g4_feedback_rest_context( array $source ): array {
	return array(
		'context_type'     => $source['context_type'],
		'context_id'       => $source['context_id'],
		'context_revision' => $source['context_revision'],
	);
}

/**
 * G4 feedback rest body.
 *
 * @param array<string,mixed> $source Source.
 * @param string              $command_id Command identifier used for idempotency.
 * @return array<string,mixed>
 */
function g4_feedback_rest_body( array $source, string $command_id ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_feedback_revision_hash' => null,
			'expected_feedback_revision_id'   => null,
			'expected_feedback_revision_no'   => 0,
			'expected_official_review_hash'   => $source['official_review_hash'],
			'expected_official_review_id'     => $source['official_review_id'],
		),
		'payload'    => array(
			'decision'    => 'teacher_draft',
			'feedback'    => g4_feedback_review_payload(),
			'reason'      => null,
			'source_mode' => 'direct',
		),
	);
}

$rest_repository   = new G4_Feedback_Review_Repository( g4_feedback_review_snapshot( $source ) );
$rest_application  = new Review_Submission_Feedback(
	new G4_Feedback_Review_Source( $source ),
	$rest_repository,
	new Submission_Feedback_Input_Builder( $canonical ),
	new Submission_Feedback_Output_Normalizer( $canonical ),
	new Public_Id_Generator(),
	$canonical,
	static fn (): string => '2026-08-24 14:00:00'
);
$rest_policy       = new G4_Feedback_Rest_Policy( $source['evaluation_id'], $source['context_id'], $source['context_revision'], (string) $source['evaluation_lock_version'] );
$rest_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-feedback-rest' );
$rest_envelopes    = new Rest_Envelope_Factory();
$rest_permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
	new Rest_Request_Authorizer( $rest_policy, new WordPress_Rest_Nonce_Verifier(), $rest_correlations ),
	$rest_envelopes,
	$rest_correlations
);
$rest_controller   = new Submission_Feedback_Review_Rest_Controller( $rest_permissions, $rest_application, $canonical, $rest_envelopes, $rest_correlations );
$rest_controller->register_routes();
$rest_controller->register_routes();
g4_feedback_review_same( 1, count( $GLOBALS['g4_feedback_rest_routes'] ), 'C36 must register exactly once.' );
g4_feedback_review_same( 'oj-ai/v1', $GLOBALS['g4_feedback_rest_routes'][0][0], 'C36 namespace drifted.' );
g4_feedback_review_same( Submission_Feedback_Review_Rest_Controller::ROUTE_REVIEW, $GLOBALS['g4_feedback_rest_routes'][0][1], 'C36 route drifted.' );
g4_feedback_review_same( 'POST', $GLOBALS['g4_feedback_rest_routes'][0][2]['methods'], 'C36 must use POST.' );

$rest_body    = g4_feedback_rest_body( $source, 'command-g4-feedback-rest' );
$rest_request = new G4_Feedback_Rest_Request( $rest_body, $source['evaluation_id'], g4_feedback_rest_context( $source ), 'idempotency-g4-feedback-rest' );
g4_feedback_review_same( true, $rest_controller->permissions_review( $rest_request ), 'C36 permission must use the common REST handoff.' );
$rest_response = $rest_controller->review( $rest_request );
g4_feedback_review_assert( $rest_response instanceof WP_REST_Response, 'C36 success must return a REST response.' );
g4_feedback_review_same( 201, $rest_response->get_status(), 'New C36 feedback revision must return 201.' );
$rest_data = $rest_response->get_data();
g4_feedback_review_same( 'teacher_draft', $rest_data['data']['feedback_revision']['state'], 'C36 response state drifted.' );
g4_feedback_review_same( 1, $rest_data['data']['feedback_revision']['revision_no'], 'C36 response revision number drifted.' );
g4_feedback_review_same( false, $rest_data['meta']['idempotent_replay'], 'New C36 response must not report replay.' );
g4_feedback_review_same( Submission_Feedback_Review_Rest_Controller::ACTION_REVIEW, $rest_policy->last_request?->action_key(), 'C36 policy action drifted.' );
g4_feedback_review_same( array( Addon_Capabilities::REVIEW_ASSESSMENTS ), $rest_policy->last_request?->required_capabilities(), 'C36 capability drifted.' );

g4_feedback_review_same( true, $rest_controller->permissions_review( $rest_request ), 'Exact C36 retry must reauthorize.' );
$rest_replay = $rest_controller->review( $rest_request );
g4_feedback_review_assert( $rest_replay instanceof WP_REST_Response, 'Exact C36 retry must return a REST response.' );
g4_feedback_review_same( 201, $rest_replay->get_status(), 'Exact C36 replay retains the command resource status.' );
g4_feedback_review_same( true, $rest_replay->get_data()['meta']['idempotent_replay'], 'Exact C36 retry must use the durable receipt.' );
g4_feedback_review_same( 1, $rest_repository->append_count, 'Exact C36 retry must append zero additional feedback revisions.' );

$invalid_body                          = $rest_body;
$invalid_body['payload']['unexpected'] = true;
$invalid_request                       = new G4_Feedback_Rest_Request( $invalid_body, $source['evaluation_id'], g4_feedback_rest_context( $source ), 'idempotency-g4-feedback-invalid' );
g4_feedback_review_same( true, $rest_controller->permissions_review( $invalid_request ), 'C36 permission must remain independent of body parsing.' );
$invalid_response = $rest_controller->review( $invalid_request );
g4_feedback_review_assert( $invalid_response instanceof WP_Error, 'Unknown C36 payload fields must fail.' );
g4_feedback_review_same( 422, $invalid_response->get_error_data()['status'], 'Unknown C36 payload fields must return 422.' );

$conflict_body    = g4_feedback_rest_body( $source, 'command-g4-feedback-rest-conflict' );
$conflict_request = new G4_Feedback_Rest_Request( $conflict_body, $source['evaluation_id'], g4_feedback_rest_context( $source ), 'idempotency-g4-feedback-rest' );
g4_feedback_review_same( true, $rest_controller->permissions_review( $conflict_request ), 'C36 idempotency conflict request must reauthorize.' );
$conflict_response = $rest_controller->review( $conflict_request );
g4_feedback_review_assert( $conflict_response instanceof WP_Error, 'Changed C36 body under one idempotency key must fail.' );
g4_feedback_review_same( 409, $conflict_response->get_error_data()['status'], 'C36 idempotency conflict must return 409.' );

$records_before_revoke = $rest_repository->append_count;
$rest_policy->deny     = true;
$denied                = $rest_controller->permissions_review( $rest_request );
g4_feedback_review_assert( $denied instanceof WP_Error, 'Permission revocation must deny C36 before application execution.' );
g4_feedback_review_same( 403, $denied->get_error_data()['status'], 'Revoked C36 permission must return 403.' );
g4_feedback_review_same( $records_before_revoke, $rest_repository->append_count, 'Revoked C36 permission must append zero feedback revisions.' );
g4_feedback_review_same( 5, $GLOBALS['g4_feedback_rest_nonce_calls'], 'Each C36 mutation permission check must verify the REST nonce exactly once.' );

fwrite( STDOUT, "G4-IMP-04-S02 C36 REST, authorization, strict input, replay, and conflict contracts passed.\n" );
