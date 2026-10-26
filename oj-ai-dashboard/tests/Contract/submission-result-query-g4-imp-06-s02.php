<?php
/**
 * G4-IMP-06-S02 Q33 own current result query contract.
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
use OJAI\Dashboard\Submission\Assessment\Application\Read_Own_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\Submission_Result_Object_Authorization;
use OJAI\Dashboard\Submission\Assessment\Infrastructure\WordPress_Submission_Result_Query;
use OJAI\Dashboard\Submission\Assessment\Presentation\Submission_Result_Query_Rest_Controller;
use OJAI\Dashboard\Submission\Student\Port\Learning_Context_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id_Generator;

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * G4-IMP-06-S02 Q33 own current result query contract.
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
	 * G4-IMP-06-S02 Q33 own current result query contract.
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

$GLOBALS['g4_q33_actor']  = 42;
$GLOBALS['g4_q33_routes'] = array();
if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * Get current user id.
	 *
	 * @return int
	 */
	function get_current_user_id(): int {
		return (int) $GLOBALS['g4_q33_actor'];
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
		unset( $nonce, $action );
		return false;
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
		$GLOBALS['g4_q33_routes'][] = array( $namespace, $route, $arguments );
		return true;
	}

}

ob_start();
require __DIR__ . '/submission-result-release-g4-imp-06-s01.php';
ob_end_clean();

/**
 * G4-IMP-06-S02 Q33 own current result query contract.
 */
final class G4_Q33_Contexts implements Learning_Context_Query_Port_Interface {
	/**
	 * Active.
	 *
	 * @var bool
	 */
	public bool $active = true;
	/**
	 * Revision.
	 *
	 * @var string
	 */
	public string $revision;
	/**
	 * Calls.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Initialize g4 q33 contexts dependencies and state.
	 *
	 * @param string $type Type.
	 * @param string $id Id.
	 * @param string $revision Revision.
	 */
	public function __construct( private string $type, private string $id, string $revision ) {
		$this->revision = $revision;
	}

	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}>
	 */
	public function for_actor( int $actor_user_id ): array {
		++$this->calls;
		if ( 42 !== $actor_user_id ) {
			return array();
		}
		return array(
			$this->type . ':' . $this->id => array(
				'type'            => $this->type,
				'id'              => $this->id,
				'label'           => 'Class 21',
				'lifecycle_state' => $this->active ? 'active' : 'archived',
				'revision'        => $this->revision,
				'attributes'      => array(),
			),
		);
	}
}

/**
 * G4 q33 content hash.
 *
 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
 * @param array<string,mixed> $record Record.
 * @return string
 */
function g4_q33_content_hash( Canonical_Json $canonical, array $record ): string {
	return hash(
		'sha256',
		$canonical->encode(
			array(
				'evaluation_id'              => $record['evaluation_public_id'],
				'feedback_id'                => $record['expected_feedback_revision_id'],
				'previous_release_id'        => $record['previous_release_id'],
				'release_no'                 => $record['release_no'],
				'release_reason_code'        => $record['reason_code'],
				'release_reason_note'        => $record['reason_note'],
				'released_at_utc'            => $record['released_at_utc'],
				'review_id'                  => $record['expected_official_review_id'],
				'state'                      => $record['state'],
				'student_projection_hash'    => $record['projection_hash'],
				'student_projection_schema'  => $record['projection_schema'],
				'student_projection_version' => $record['projection_version'],
				'visible_score'              => $record['visible_score'],
			)
		)
	);
}

/**
 * G4-IMP-06-S02 Q33 own current result query contract.
 */
final class G4_Q33_Database {
	/**
	 * Last error.
	 *
	 * @var string
	 */
	public string $last_error = '';
	/**
	 * Pointer current.
	 *
	 * @var bool
	 */
	public bool $pointer_current = true;
	/**
	 * Assignment state.
	 *
	 * @var string
	 */
	public string $assignment_state = 'open';
	/**
	 * Target state.
	 *
	 * @var string
	 */
	public string $target_state = 'active';
	/**
	 * Previous pk.
	 *
	 * @var int|null
	 */
	public ?int $previous_pk = null;
	/**
	 * Previous id.
	 *
	 * @var string|null
	 */
	public ?string $previous_id = null;
	/**
	 * Sql.
	 *
	 * @var array<int,string>
	 */
	public array $sql = array();

	/**
	 * Initialize g4 q33 database dependencies and state.
	 *
	 * @param array<string,mixed> $source Source.
	 * @param array<string,mixed> $record Record.
	 */
	public function __construct( public array $source, public array $record ) {
	}

	/**
	 * Prepare.
	 *
	 * @param string $sql Sql.
	 * @param mixed  ...$arguments Arguments.
	 * @return string
	 */
	public function prepare( string $sql, mixed ...$arguments ): string {
		return 'G4Q33:' . base64_encode( serialize( array( $sql, $arguments ) ) );
	}

	/**
	 * Get row.
	 *
	 * @param string $prepared Prepared.
	 * @param mixed  $output Output.
	 * @return array<string,mixed>|null
	 */
	public function get_row( string $prepared, mixed $output = null ): ?array {
		unset( $output );
		[ $sql, $arguments ] = $this->prepared( $prepared );
		$this->sql[]         = $sql;
		g4_release_assert( str_contains( $sql, 'FROM oj_ai_submission_release_revisions release_row' ), 'Q33 must read the literal release table.' );
		g4_release_assert( str_contains( $sql, 'submission_row.learner_user_id = %d' ) && str_contains( $sql, 'target.learner_user_id = %d' ), 'Q33 SQL must bind the actor to submission and target.' );
		g4_release_assert( str_contains( $sql, 'submission_row.current_release_public_id = release_row.public_id' ) && str_contains( $sql, 'submission_row.current_release_hash = release_row.content_hash' ), 'Q33 SQL must select only the current release pointer.' );
		if (
			$this->record['release_public_id'] !== ( $arguments[0] ?? null )
			|| 42 !== ( $arguments[1] ?? null )
			|| 42 !== ( $arguments[2] ?? null )
			|| ! $this->pointer_current
		) {
			return null;
		}

		$common = array(
			'release_id'                => $this->record['release_public_id'],
			'release_hash'              => $this->record['content_hash'],
			'release_state'             => $this->record['state'],
			'submission_id'             => $this->source['submission_id'],
			'current_release_public_id' => $this->record['release_public_id'],
			'current_release_hash'      => $this->record['content_hash'],
			'context_type'              => $this->source['context_type'],
			'context_id'                => $this->source['context_id'],
			'context_revision'          => $this->source['context_revision'],
			'assignment_state'          => $this->assignment_state,
			'target_state'              => $this->target_state,
		);
		if ( ! str_contains( $sql, 'release_row.release_no' ) ) {
			return $common;
		}
		if (
			'active' !== $this->target_state
			|| 'withdrawn' === $this->assignment_state
			|| $this->source['context_type'] !== ( $arguments[5] ?? null )
			|| $this->source['context_id'] !== ( $arguments[6] ?? null )
			|| $this->source['context_revision'] !== ( $arguments[7] ?? null )
		) {
			return null;
		}

		$feedback_pk = null === $this->record['expected_feedback_revision_id'] ? null : 81;
		return array_merge(
			$common,
			array(
				'release_no'                 => (string) $this->record['release_no'],
				'visible_score'              => $this->record['visible_score'],
				'student_projection_schema'  => $this->record['projection_schema'],
				'student_projection_version' => (string) $this->record['projection_version'],
				'student_projection_json'    => $this->record['projection_json'],
				'student_projection_hash'    => $this->record['projection_hash'],
				'previous_release_pk'        => $this->previous_pk,
				'release_feedback_pk'        => $feedback_pk,
				'release_reason_code'        => $this->record['reason_code'],
				'release_reason_note'        => $this->record['reason_note'],
				'released_at_utc'            => $this->record['released_at_utc'],
				'evaluation_pk'              => $this->source['evaluation_pk'],
				'evaluation_id'              => $this->record['evaluation_public_id'],
				'review_pk'                  => $this->source['official_review_pk'],
				'review_id'                  => $this->record['expected_official_review_id'],
				'joined_feedback_pk'         => $feedback_pk,
				'feedback_id'                => $this->record['expected_feedback_revision_id'],
				'joined_previous_pk'         => $this->previous_pk,
				'previous_release_id'        => $this->previous_id,
				'previous_evaluation_pk'     => null === $this->previous_pk ? null : $this->source['evaluation_pk'],
				'release_count'              => (string) $this->record['release_no'],
			)
		);
	}

	/**
	 * Transition.
	 *
	 * @param string      $state State.
	 * @param string      $reason_code Reason code.
	 * @param string|null $reason_note Reason note.
	 * @param string      $time Time.
	 * @return void
	 */
	public function transition( string $state, string $reason_code, ?string $reason_note, string $time ): void {
		$this->previous_pk                   = 901;
		$this->previous_id                   = $this->record['release_public_id'];
		$this->record['previous_release_id'] = $this->previous_id;
		$this->record['release_public_id']   = ( new Public_Id_Generator() )->create()->value();
		$this->record['release_no']          = 2;
		$this->record['state']               = $state;
		$this->record['reason_code']         = $reason_code;
		$this->record['reason_note']         = $reason_note;
		$this->record['released_at_utc']     = $time;
		$this->record['content_hash']        = g4_q33_content_hash( new Canonical_Json(), $this->record );
	}

	/**
	 * Add forbidden projection field.
	 *
	 * @return void
	 */
	public function add_forbidden_projection_field(): void {
		$canonical                       = new Canonical_Json();
		$projection                      = json_decode( $this->record['projection_json'], true, 128, JSON_THROW_ON_ERROR );
		$projection['teacher_memo']      = 'private';
		$this->record['projection_json'] = $canonical->encode( $projection );
		$this->record['projection_hash'] = hash( 'sha256', $this->record['projection_json'] );
		$this->record['content_hash']    = g4_q33_content_hash( $canonical, $this->record );
	}

	/**
	 * Prepared.
	 *
	 * @param string $value Value.
	 * @return array{0:string,1:array<int,mixed>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function prepared( string $value ): array {
		if ( ! str_starts_with( $value, 'G4Q33:' ) ) {
			throw new RuntimeException( 'Unexpected unprepared Q33 SQL.' );
		}
		$decoded = unserialize( base64_decode( substr( $value, 6 ), true ), array( 'allowed_classes' => false ) );
		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ! is_string( $decoded[0] ) || ! is_array( $decoded[1] ) ) {
			throw new RuntimeException( 'Invalid Q33 SQL payload.' );
		}
		return $decoded;
	}
}

/**
 * G4-IMP-06-S02 Q33 own current result query contract.
 */
final class G4_Q33_Rest_Policy implements Access_Policy_Port_Interface {
	/**
	 * Last request.
	 *
	 * @var Access_Policy_Request|null
	 */
	public ?Access_Policy_Request $last_request = null;

	/**
	 * Initialize g4 q33 rest policy dependencies and state.
	 *
	 * @param G4_Q33_Database $database Database.
	 */
	public function __construct( private G4_Q33_Database $database ) {
	}

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		$this->last_request = $request;
		if ( $request->actor_user_id() < 1 ) {
			return Access_Policy_Decision::denied( 401, 'authentication_required', $request->correlation_id() );
		}
		$context = $request->context();
		$object  = $request->object();
		if (
			42 !== $request->actor_user_id()
			|| Access_Policy_Request::SHELL_PUBLIC !== $request->shell()
			|| Access_Policy_Request::SURFACE_REST !== $request->surface()
			|| Submission_Result_Query_Rest_Controller::ACTION_VIEW !== $request->action_key()
			|| array( Addon_Capabilities::PARTICIPATE ) !== $request->required_capabilities()
			|| ! $context instanceof Context_Scope
			|| Context_Scope::OPERATION_PARTICIPATE !== $context->operation()
			|| 42 !== $context->learner_user_id()
			|| $this->database->source['context_revision'] !== $context->expected_revision()
			|| ! $object instanceof Object_Scope
			|| Object_Scope::TYPE_RELEASE !== $object->type()
			|| 'self_view' !== $object->action()
			|| $this->database->record['release_public_id'] !== $object->id()
		) {
			return Access_Policy_Decision::denied( 403, 'q33_scope_denied', $request->correlation_id() );
		}
		return Access_Policy_Decision::allowed(
			$request->correlation_id(),
			Context_Authorization_Result::allowed( $this->database->source['context_type'], $this->database->source['context_id'], 'active', $this->database->source['context_revision'], 'learner' ),
			Object_Authorization_Result::allowed( $this->database->record['content_hash'], $this->database->record['state'], 'self_release' )
		);
	}
}

/**
 * G4-IMP-06-S02 Q33 own current result query contract.
 */
final class G4_Q33_Request {
	/**
	 * Initialize g4 q33 request dependencies and state.
	 *
	 * @param string $release_id Release id.
	 * @param array  $query Query.
	 */
	public function __construct( private string $release_id, private array $query = array() ) {
	}
	/**
	 * Get method.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return 'GET';
	}
	/**
	 * Get header.
	 *
	 * @param string $name Name.
	 * @return mixed
	 */
	public function get_header( string $name ) {
		unset( $name );
		return '';
	}
	/**
	 * Get url params.
	 *
	 * @return array<string,string>
	 */
	public function get_url_params(): array {
		return array( 'release_id' => $this->release_id );
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

$q33_canonical          = new Canonical_Json();
$q33_fixture            = g4_release_fixture( $q33_canonical );
$q33_release_repository = new G4_Result_Release_Repository( $q33_fixture['snapshot'] );
$q33_released           = g4_release_application( $q33_fixture['source'], $q33_release_repository, $q33_canonical, '2026-08-24 23:40:00' )
	->execute( g4_release_command( $q33_fixture['source'], $q33_fixture['snapshot'], $q33_canonical, 'command-g4-q33-source', str_repeat( '1', 64 ) ) );
$q33_record             = $q33_release_repository->record;
if ( null === $q33_record ) {
	throw new RuntimeException( 'Q33 C43 source record is missing.' );
}

$q33_database = new G4_Q33_Database( $q33_fixture['source'], $q33_record );
$q33_query    = new WordPress_Submission_Result_Query( $q33_canonical, $q33_database );
$q33_contexts = new G4_Q33_Contexts( $q33_fixture['source']['context_type'], $q33_fixture['source']['context_id'], $q33_fixture['source']['context_revision'] );
$q33_read     = new Read_Own_Submission_Result( $q33_query, $q33_contexts );
$q33_result   = $q33_read->read( 42, $q33_released->release_id() );

g4_release_same( array( 'notice', 'projection', 'release' ), array_keys( $q33_result ), 'Q33 response top-level allowlist drifted.' );
g4_release_same( null, $q33_result['notice'], 'A visible Q33 result must not carry a notice.' );
g4_release_same( 'released', $q33_result['release']['state'], 'Q33 visible state drifted.' );
g4_release_same( $q33_released->release_hash(), $q33_result['release']['hash'], 'Q33 must return the exact current release hash.' );
g4_release_same( $q33_record['projection_hash'], hash( 'sha256', $q33_canonical->encode( $q33_result['projection'] ) ), 'Q33 projection must be the exact C43 student projection.' );
$q33_json = json_encode( $q33_result, JSON_THROW_ON_ERROR );
foreach ( array( 'answer_key', 'binding_key', 'teacher_memo', 'provider_key', 'model_key', 'reason_code', 'reason_note', 'student_note', 'safety_flags' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $q33_json, $forbidden ), 'Q33 leaked a forbidden field: ' . $forbidden );
}

g4_release_failure( static fn () => $q33_read->read( 99, $q33_released->release_id() ), Submission_Evaluation_Failure::NOT_FOUND, 'Another learner must receive Q33 404 semantics.' );
$q33_database->pointer_current = false;
g4_release_failure( static fn () => $q33_read->read( 42, $q33_released->release_id() ), Submission_Evaluation_Failure::NOT_FOUND, 'A historical release ID must receive Q33 404 semantics.' );
$q33_database->pointer_current = true;
$q33_contexts->revision        = 'context-new-revision';
g4_release_failure( static fn () => $q33_read->read( 42, $q33_released->release_id() ), Submission_Evaluation_Failure::NOT_FOUND, 'A stale Context snapshot must receive Q33 404 semantics.' );
$q33_contexts->revision = $q33_fixture['source']['context_revision'];

$q33_stale_database = clone $q33_database;
$q33_stale_database->transition( 'stale', 'official_review_changed', null, '2026-08-24 23:50:00' );
$q33_stale_query  = new WordPress_Submission_Result_Query( $q33_canonical, $q33_stale_database );
$q33_stale_result = ( new Read_Own_Submission_Result( $q33_stale_query, $q33_contexts ) )->read( 42, $q33_stale_database->record['release_public_id'] );
g4_release_same( null, $q33_stale_result['projection'], 'Q33 stale result must expose no previous projection.' );
g4_release_same( 'result_updating', $q33_stale_result['notice']['code'], 'Q33 stale notice drifted.' );
g4_release_assert( ! str_contains( json_encode( $q33_stale_result, JSON_THROW_ON_ERROR ), 'Fraction comparison worksheet' ), 'Q33 stale notice leaked the previous result body.' );

$q33_withdrawn_database = clone $q33_database;
$q33_withdrawn_database->transition( 'withdrawn', 'teacher_withdrawn', 'Private teacher reason', '2026-08-24 23:51:00' );
$q33_withdrawn_query  = new WordPress_Submission_Result_Query( $q33_canonical, $q33_withdrawn_database );
$q33_withdrawn_result = ( new Read_Own_Submission_Result( $q33_withdrawn_query, $q33_contexts ) )->read( 42, $q33_withdrawn_database->record['release_public_id'] );
g4_release_same( null, $q33_withdrawn_result['projection'], 'Q33 withdrawn result must expose no previous projection.' );
g4_release_same( 'result_withdrawn', $q33_withdrawn_result['notice']['code'], 'Q33 withdrawn notice drifted.' );
g4_release_assert( ! str_contains( json_encode( $q33_withdrawn_result, JSON_THROW_ON_ERROR ), 'Private teacher reason' ), 'Q33 withdrawn notice leaked the private reason.' );

$q33_corrupt_database = clone $q33_database;
$q33_corrupt_database->add_forbidden_projection_field();
$q33_corrupt_query = new WordPress_Submission_Result_Query( $q33_canonical, $q33_corrupt_database );
g4_release_failure( static fn () => $q33_corrupt_query->find_current_for_actor( $q33_corrupt_database->record['release_public_id'], 42, $q33_contexts->for_actor( 42 )[ $q33_fixture['source']['context_type'] . ':' . $q33_fixture['source']['context_id'] ] ), Submission_Evaluation_Failure::STORED_DATA_INVALID, 'Q33 must reject a hash-consistent projection outside the exact allowlist.' );

$q33_total_fixture    = g4_release_fixture( $q33_canonical );
$q33_total_repository = new G4_Result_Release_Repository( $q33_total_fixture['snapshot'] );
$q33_total_release    = g4_release_application( $q33_total_fixture['source'], $q33_total_repository, $q33_canonical, '2026-08-24 23:42:00' )
	->execute( g4_release_command( $q33_total_fixture['source'], $q33_total_fixture['snapshot'], $q33_canonical, 'command-g4-q33-total', str_repeat( '2', 64 ), 'total', 'hidden' ) );
$q33_total_database   = new G4_Q33_Database( $q33_total_fixture['source'], $q33_total_repository->record ?? array() );
$q33_total_contexts   = new G4_Q33_Contexts( $q33_total_fixture['source']['context_type'], $q33_total_fixture['source']['context_id'], $q33_total_fixture['source']['context_revision'] );
$q33_total_result     = ( new Read_Own_Submission_Result( new WordPress_Submission_Result_Query( $q33_canonical, $q33_total_database ), $q33_total_contexts ) )->read( 42, $q33_total_release->release_id() );
g4_release_same( 'total', $q33_total_result['projection']['score']['visibility'], 'Q33 must accept C43 total-only score projection.' );
g4_release_same( array(), $q33_total_result['projection']['score']['criteria'], 'Q33 total-only result must keep criterion scores hidden.' );

$q33_object_policy  = new Submission_Result_Object_Authorization( $q33_query );
$q33_context_result = Context_Authorization_Result::allowed( $q33_fixture['source']['context_type'], $q33_fixture['source']['context_id'], 'active', $q33_fixture['source']['context_revision'], 'learner' );
$q33_object         = new Object_Scope( Object_Scope::TYPE_RELEASE, $q33_released->release_id(), 'self_view' );
g4_release_same( true, $q33_object_policy->authorize( 42, Submission_Result_Query_Rest_Controller::ACTION_VIEW, $q33_context_result, $q33_object )->is_allowed(), 'Q33 release object policy must allow the owner current release.' );
g4_release_same( true, $q33_object_policy->authorize( 99, Submission_Result_Query_Rest_Controller::ACTION_VIEW, $q33_context_result, $q33_object )->is_denied(), 'Q33 release object policy must deny another learner.' );

$q33_rest_policy  = new G4_Q33_Rest_Policy( $q33_database );
$q33_correlations = new Correlation_Id_Factory( static fn (): string => 'correlation-g4-q33-rest' );
$q33_envelopes    = new Rest_Envelope_Factory();
$q33_actor        = new WordPress_Current_Actor();
$q33_permissions  = new Rest_Permission_Handoff(
	new WordPress_Rest_Request_Adapter( $q33_actor ),
	new Rest_Request_Authorizer( $q33_rest_policy, new WordPress_Rest_Nonce_Verifier(), $q33_correlations ),
	$q33_envelopes,
	$q33_correlations
);
$q33_controller   = new Submission_Result_Query_Rest_Controller( $q33_permissions, $q33_actor, $q33_query, $q33_read, $q33_envelopes, $q33_correlations );
$q33_controller->register_routes();
$q33_controller->register_routes();
g4_release_same( 1, count( $GLOBALS['g4_q33_routes'] ), 'Q33 must register exactly once.' );
g4_release_same( 'oj-ai/v1', $GLOBALS['g4_q33_routes'][0][0], 'Q33 REST namespace drifted.' );
g4_release_same( Submission_Result_Query_Rest_Controller::ROUTE_RESULT, $GLOBALS['g4_q33_routes'][0][1], 'Q33 route drifted.' );
g4_release_same( 'GET', $GLOBALS['g4_q33_routes'][0][2]['methods'], 'Q33 must use GET.' );

$q33_request = new G4_Q33_Request( $q33_released->release_id() );
g4_release_same( true, $q33_controller->permissions_show( $q33_request ), 'Q33 must use the common REST permission handoff.' );
$q33_response = $q33_controller->show( $q33_request );
g4_release_assert( $q33_response instanceof WP_REST_Response, 'Q33 success must return a REST response.' );
g4_release_same( 200, $q33_response->get_status(), 'Q33 success must return 200.' );
$q33_response_data = $q33_response->get_data();
g4_release_same( $q33_released->release_id(), $q33_response_data['data']['result']['release']['id'], 'Q33 REST response release drifted.' );
g4_release_same( '/wp-json/oj-ai/v1/me/results/' . $q33_released->release_id(), $q33_response_data['links']['self'], 'Q33 self link drifted.' );
g4_release_same( '/oj-addons/oj-ai-dashboard/results/' . $q33_released->release_id(), $q33_response_data['links']['page'], 'Q33 PublicUI deep link drifted.' );
g4_release_same( array(), $q33_response_data['actions'], 'Q33 must not advertise the unimplemented result PDF command.' );
g4_release_same( Submission_Result_Query_Rest_Controller::ACTION_VIEW, $q33_rest_policy->last_request?->action_key(), 'Q33 policy action drifted.' );

$q33_unknown_query    = new G4_Q33_Request( $q33_released->release_id(), array( 'include' => 'private' ) );
$q33_unknown_response = $q33_controller->permissions_show( $q33_unknown_query );
g4_release_assert( $q33_unknown_response instanceof WP_Error && 422 === $q33_unknown_response->get_error_data()['status'], 'Q33 must reject unknown query fields with 422.' );

$GLOBALS['g4_q33_actor'] = 99;
$q33_other               = $q33_controller->permissions_show( new G4_Q33_Request( $q33_released->release_id() ) );
g4_release_assert( $q33_other instanceof WP_Error && 404 === $q33_other->get_error_data()['status'], 'Q33 must hide another learner release with 404.' );
$GLOBALS['g4_q33_actor'] = 0;
$q33_anonymous           = $q33_controller->permissions_show( new G4_Q33_Request( $q33_released->release_id() ) );
g4_release_assert( $q33_anonymous instanceof WP_Error && 401 === $q33_anonymous->get_error_data()['status'], 'Q33 must require PublicUI login.' );
$GLOBALS['g4_q33_actor'] = 42;

$q33_runtime = (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Infrastructure/WordPress_Submission_Result_Query.php' )
	. (string) file_get_contents( $addon_root . '/src/Submission/Assessment/Presentation/Submission_Result_Query_Rest_Controller.php' );
foreach ( array( '$wpdb->prefix', 'wp_oj_ai_', 'CodingPanda\\OjPlatform\\Kernel\\' ) as $forbidden ) {
	g4_release_assert( ! str_contains( $q33_runtime, $forbidden ), 'Q33 boundary contains a forbidden dependency: ' . $forbidden );
}

fwrite( STDOUT, "G4-IMP-06-S02 Q33 passed: owner-only current release, exact projection integrity, stale/withdrawn body suppression, release object policy, PublicUI REST, and literal tables are frozen.\n" );
