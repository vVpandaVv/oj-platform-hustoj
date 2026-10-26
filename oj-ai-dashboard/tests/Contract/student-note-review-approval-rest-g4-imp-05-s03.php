<?php
/**
 * G4-IMP-05-S03 C39/C40 REST and authorization contract.
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
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Input_Builder;
use OJAI\Dashboard\Submission\Assessment\Presentation\Student_Note_Approval_Rest_Controller;
use OJAI\Dashboard\Submission\Assessment\Presentation\Student_Note_Review_Rest_Controller;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-05-S03 C39/C40 REST and authorization contract.
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
	 * G4-IMP-05-S03 C39/C40 REST and authorization contract.
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

$GLOBALS['g4_note_revision_rest_actor']       = 42;
$GLOBALS['g4_note_revision_rest_nonce_calls'] = 0;
$GLOBALS['g4_note_revision_rest_routes']      = array();

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_note_revision_rest_actor'];
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
		++$GLOBALS['g4_note_revision_rest_nonce_calls'];
		return 'valid-g4-note-revision-nonce' === $nonce && 'wp_rest' === $action ? 1 : false;
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
		$GLOBALS['g4_note_revision_rest_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}
}

ob_start();
require __DIR__ . '/student-note-review-approval-g4-imp-05-s03.php';
ob_end_clean();

/**
 * G4-IMP-05-S03 C39/C40 REST and authorization contract.
 */
final class G4_Student_Note_Rest_Policy implements Access_Policy_Port_Interface {
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
	 * Initialize g4 student note rest policy dependencies and state.
	 *
	 * @param string $action Action.
	 * @param string $object_action Object action.
	 * @param string $note_id Note id.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param string $object_revision Object revision.
	 * @param string $object_state Object state.
	 */
	public function __construct(
		private string $action,
		private string $object_action,
		private string $note_id,
		private string $context_id,
		private string $context_revision,
		private string $object_revision,
		private string $object_state
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
			return Access_Policy_Decision::denied( 403, 'student_note_revision_scope_denied', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			! $request->request_integrity_verified() || 42 !== $request->actor_user_id() || $this->action !== $request->action_key()
			|| array( Addon_Capabilities::MANAGE_STUDENT_NOTES ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope || 'class' !== $context->type() || $this->context_id !== $context->id()
			|| Context_Scope::OPERATION_REPORT !== $context->operation() || $this->context_revision !== $context->expected_revision()
			|| ! $object instanceof Object_Scope || Object_Scope::TYPE_STUDENT_NOTE !== $object->type()
			|| $this->note_id !== $object->id() || $this->object_action !== $object->action()
		) {
			return Access_Policy_Decision::denied( 403, 'student_note_revision_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( 'class', $this->context_id, 'active', $this->context_revision, 'educator' ),
			Object_Authorization_Result::allowed( $this->object_revision, $this->object_state, 'context_staff' )
		);
	}
}

/**
 * G4-IMP-05-S03 C39/C40 REST and authorization contract.
 */
final class G4_Student_Note_Rest_Request {
	/**
	 * Headers.
	 *
	 * @var array<string,string>
	 */
	private array $headers;
	/**
	 * Initialize g4 student note rest request dependencies and state.
	 *
	 * @param array<string,mixed>  $json Json.
	 * @param string               $note_id Note id.
	 * @param array<string,string> $query Query.
	 * @param string               $idempotency_key Idempotency key.
	 */
	public function __construct( private array $json, private string $note_id, private array $query, string $idempotency_key ) {
		$this->headers = array(
			'x-wp-nonce'      => 'valid-g4-note-revision-nonce',
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
		return array( 'note_id' => $this->note_id );
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
 * G4 note revision rest context.
 *
 * @param array<string,mixed> $source Source.
 * @return array<string,string>
 */
function g4_note_revision_rest_context( array $source ): array {
	return array(
		'context_type'     => $source['context_type'],
		'context_id'       => $source['context_id'],
		'context_revision' => $source['context_revision'],
	);
}

/**
 * G4 note revision rest review body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param array<string,mixed> $note Note.
 * @param string              $command_id Command identifier used for idempotency.
 * @return array<string,mixed>
 */
function g4_note_revision_rest_review_body( array $snapshot, array $note, string $command_id ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_include_in_reports'   => $snapshot['latest_include_in_reports'],
			'expected_official_review_hash' => $snapshot['official_review_hash'],
			'expected_official_review_id'   => $snapshot['official_review_id'],
			'expected_revision_hash'        => $snapshot['latest_revision_hash'],
			'expected_revision_id'          => $snapshot['latest_revision_id'],
			'expected_revision_no'          => $snapshot['latest_revision_no'],
			'expected_state'                => $snapshot['latest_state'],
		),
		'payload'    => array(
			'decision'    => 'teacher_draft',
			'note'        => $note,
			'reason'      => null,
			'source_mode' => 'direct',
		),
	);
}

/**
 * G4 note revision rest approval body.
 *
 * @param array<string,mixed> $snapshot Snapshot.
 * @param string              $command_id Command identifier used for idempotency.
 * @return array<string,mixed>
 */
function g4_note_revision_rest_approval_body( array $snapshot, string $command_id ): array {
	return array(
		'command_id' => $command_id,
		'expected'   => array(
			'expected_include_in_reports'   => false,
			'expected_official_review_hash' => $snapshot['official_review_hash'],
			'expected_official_review_id'   => $snapshot['official_review_id'],
			'expected_revision_hash'        => $snapshot['latest_revision_hash'],
			'expected_revision_id'          => $snapshot['latest_revision_id'],
			'expected_revision_no'          => $snapshot['latest_revision_no'],
			'expected_state'                => 'teacher_draft',
		),
		'payload'    => array(),
	);
}

/**
 * G4 note revision rest permissions.
 *
 * @param G4_Student_Note_Rest_Policy $policy Policy.
 * @param Correlation_Id_Factory      $correlations Correlations.
 * @param Rest_Envelope_Factory       $envelopes Envelopes.
 * @return Rest_Permission_Handoff
 */
function g4_note_revision_rest_permissions( G4_Student_Note_Rest_Policy $policy, Correlation_Id_Factory $correlations, Rest_Envelope_Factory $envelopes ): Rest_Permission_Handoff {
	return new Rest_Permission_Handoff(
		new WordPress_Rest_Request_Adapter( new WordPress_Current_Actor() ),
		new Rest_Request_Authorizer( $policy, new WordPress_Rest_Nonce_Verifier(), $correlations ),
		$envelopes,
		$correlations
	);
}

$rest_canonical           = new Canonical_Json();
$rest_source              = g4_note_source( $rest_canonical, 3 );
$rest_gateway             = ( new Student_Note_Input_Builder( $rest_canonical ) )->build( $rest_source )->gateway_input();
$rest_note                = g4_note_teacher_payload( $rest_gateway );
$rest_initial             = g4_note_revision_snapshot( $rest_source );
$rest_review_repository   = new G4_Student_Note_Revision_Repository( $rest_initial );
$rest_review_policy       = new G4_Student_Note_Rest_Policy(
	Student_Note_Review_Rest_Controller::ACTION_REVIEW,
	'review',
	$rest_source['evaluation_id'],
	$rest_source['context_id'],
	$rest_source['context_revision'],
	$rest_source['official_review_hash'],
	'reviewed'
);
$rest_review_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-note-review-rest' );
$rest_review_envelopes    = new Rest_Envelope_Factory();
$rest_review_controller   = new Student_Note_Review_Rest_Controller(
	g4_note_revision_rest_permissions( $rest_review_policy, $rest_review_correlations, $rest_review_envelopes ),
	g4_note_review_application( $rest_source, $rest_review_repository, $rest_canonical, '2026-08-24 20:00:00' ),
	$rest_canonical,
	$rest_review_envelopes,
	$rest_review_correlations
);
$rest_review_controller->register_routes();
$rest_review_controller->register_routes();
g4_note_same( 1, count( $GLOBALS['g4_note_revision_rest_routes'] ), 'C39 must register exactly once.' );
g4_note_same( Student_Note_Review_Rest_Controller::ROUTE_REVIEW, $GLOBALS['g4_note_revision_rest_routes'][0][1], 'C39 route drifted.' );

$rest_review_body    = g4_note_revision_rest_review_body( $rest_initial, $rest_note, 'command-g4-note-review-rest' );
$rest_review_request = new G4_Student_Note_Rest_Request( $rest_review_body, $rest_source['evaluation_id'], g4_note_revision_rest_context( $rest_source ), 'idempotency-g4-note-review-rest' );
g4_note_same( true, $rest_review_controller->permissions_review( $rest_review_request ), 'C39 permission must use the common REST handoff.' );
$rest_review_response = $rest_review_controller->review( $rest_review_request );
g4_note_assert( $rest_review_response instanceof WP_REST_Response, 'C39 success must return a REST response.' );
g4_note_same( 201, $rest_review_response->get_status(), 'New C39 revision must return 201.' );
$rest_review_data = $rest_review_response->get_data();
g4_note_same( 'teacher_draft', $rest_review_data['data']['note_revision']['state'], 'C39 response state drifted.' );
g4_note_same( false, $rest_review_data['data']['note_revision']['include_in_reports'], 'C39 response must expose report exclusion.' );
g4_note_same( $rest_source['evaluation_id'], $rest_review_data['data']['note_id'], 'C39 first-write note root drifted.' );
g4_note_same( Student_Note_Review_Rest_Controller::ACTION_REVIEW, $rest_review_policy->last_request?->action_key(), 'C39 policy action drifted.' );

g4_note_same( true, $rest_review_controller->permissions_review( $rest_review_request ), 'Exact C39 retry must reauthorize.' );
$rest_review_replay = $rest_review_controller->review( $rest_review_request );
g4_note_same( true, $rest_review_replay->get_data()['meta']['idempotent_replay'], 'Exact C39 retry must use the durable receipt.' );
g4_note_same( 1, $rest_review_repository->append_count, 'Exact C39 retry must append no second revision.' );

$rest_review_invalid_body                           = $rest_review_body;
$rest_review_invalid_body['expected']['unexpected'] = true;
$rest_review_invalid_request                        = new G4_Student_Note_Rest_Request( $rest_review_invalid_body, $rest_source['evaluation_id'], g4_note_revision_rest_context( $rest_source ), 'idempotency-g4-note-review-invalid' );
g4_note_same( true, $rest_review_controller->permissions_review( $rest_review_invalid_request ), 'C39 permission must remain independent of body parsing.' );
$rest_review_invalid = $rest_review_controller->review( $rest_review_invalid_request );
g4_note_assert( $rest_review_invalid instanceof WP_Error, 'Unknown C39 expected field must fail.' );
g4_note_same( 422, $rest_review_invalid->get_error_data()['status'], 'Unknown C39 expected field must return 422.' );

$rest_review_conflict_body    = g4_note_revision_rest_review_body( $rest_initial, $rest_note, 'command-g4-note-review-rest-conflict' );
$rest_review_conflict_request = new G4_Student_Note_Rest_Request( $rest_review_conflict_body, $rest_source['evaluation_id'], g4_note_revision_rest_context( $rest_source ), 'idempotency-g4-note-review-rest' );
g4_note_same( true, $rest_review_controller->permissions_review( $rest_review_conflict_request ), 'C39 conflict request must reauthorize.' );
$rest_review_conflict = $rest_review_controller->review( $rest_review_conflict_request );
g4_note_assert( $rest_review_conflict instanceof WP_Error, 'Changed C39 request under one idempotency key must fail.' );
g4_note_same( 409, $rest_review_conflict->get_error_data()['status'], 'C39 idempotency conflict must return 409.' );

$rest_teacher_snapshot      = g4_note_revision_snapshot(
	$rest_source,
	$rest_review_data['data']['note_revision']['id'],
	$rest_review_data['data']['note_revision']['hash'],
	$rest_review_data['data']['note_revision']['revision_no'],
	'teacher_draft',
	null,
	$rest_review_repository->record['note_json'],
	$rest_review_repository->record['note_hash']
);
$rest_approval_repository   = new G4_Student_Note_Revision_Repository( $rest_teacher_snapshot );
$rest_approval_policy       = new G4_Student_Note_Rest_Policy(
	Student_Note_Approval_Rest_Controller::ACTION_APPROVE,
	'approve',
	$rest_source['evaluation_id'],
	$rest_source['context_id'],
	$rest_source['context_revision'],
	$rest_teacher_snapshot['latest_revision_hash'],
	'teacher_draft'
);
$rest_approval_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-note-approval-rest' );
$rest_approval_envelopes    = new Rest_Envelope_Factory();
$rest_approval_controller   = new Student_Note_Approval_Rest_Controller(
	g4_note_revision_rest_permissions( $rest_approval_policy, $rest_approval_correlations, $rest_approval_envelopes ),
	g4_note_approval_application( $rest_source, $rest_approval_repository, $rest_canonical, '2026-08-24 20:01:00' ),
	$rest_canonical,
	$rest_approval_envelopes,
	$rest_approval_correlations
);
$rest_approval_controller->register_routes();
$rest_approval_controller->register_routes();
g4_note_same( 2, count( $GLOBALS['g4_note_revision_rest_routes'] ), 'C40 must register exactly once beside C39.' );
g4_note_same( Student_Note_Approval_Rest_Controller::ROUTE_APPROVE, $GLOBALS['g4_note_revision_rest_routes'][1][1], 'C40 route drifted.' );

$rest_approval_body    = g4_note_revision_rest_approval_body( $rest_teacher_snapshot, 'command-g4-note-approval-rest' );
$rest_approval_request = new G4_Student_Note_Rest_Request( $rest_approval_body, $rest_source['evaluation_id'], g4_note_revision_rest_context( $rest_source ), 'idempotency-g4-note-approval-rest' );
g4_note_same( true, $rest_approval_controller->permissions_approve( $rest_approval_request ), 'C40 permission must use the common REST handoff.' );
$rest_approval_response = $rest_approval_controller->approve( $rest_approval_request );
g4_note_assert( $rest_approval_response instanceof WP_REST_Response, 'C40 success must return a REST response.' );
g4_note_same( 201, $rest_approval_response->get_status(), 'New C40 revision must return 201.' );
g4_note_same( 'approved', $rest_approval_response->get_data()['data']['note_revision']['state'], 'C40 response state drifted.' );
g4_note_same( false, $rest_approval_response->get_data()['data']['note_revision']['include_in_reports'], 'C40 must not bypass report inclusion.' );
g4_note_same( Student_Note_Approval_Rest_Controller::ACTION_APPROVE, $rest_approval_policy->last_request?->action_key(), 'C40 policy action drifted.' );

$rest_approval_invalid_body                    = $rest_approval_body;
$rest_approval_invalid_body['payload']['note'] = array();
$rest_approval_invalid_request                 = new G4_Student_Note_Rest_Request( $rest_approval_invalid_body, $rest_source['evaluation_id'], g4_note_revision_rest_context( $rest_source ), 'idempotency-g4-note-approval-invalid' );
g4_note_same( true, $rest_approval_controller->permissions_approve( $rest_approval_invalid_request ), 'C40 permission must remain independent of body parsing.' );
$rest_approval_invalid = $rest_approval_controller->approve( $rest_approval_invalid_request );
g4_note_assert( $rest_approval_invalid instanceof WP_Error, 'Non-empty C40 payload must fail.' );
g4_note_same( 422, $rest_approval_invalid->get_error_data()['status'], 'Non-empty C40 payload must return 422.' );

$records_before_revoke      = $rest_approval_repository->append_count;
$rest_approval_policy->deny = true;
$rest_approval_denied       = $rest_approval_controller->permissions_approve( $rest_approval_request );
g4_note_assert( $rest_approval_denied instanceof WP_Error, 'Permission revocation must deny C40 before execution.' );
g4_note_same( 403, $rest_approval_denied->get_error_data()['status'], 'Revoked C40 permission must return 403.' );
g4_note_same( $records_before_revoke, $rest_approval_repository->append_count, 'Revoked C40 permission must append zero revisions.' );
g4_note_assert( $GLOBALS['g4_note_revision_rest_nonce_calls'] >= 7, 'Every C39/C40 mutation permission check must verify the REST nonce.' );

fwrite( STDOUT, "G4-IMP-05-S03 C39/C40 REST, common authorization, exact DTO, empty approval payload, replay, and conflict contracts passed.\n" );
