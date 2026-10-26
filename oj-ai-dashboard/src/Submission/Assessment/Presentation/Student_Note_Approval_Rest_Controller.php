<?php
/**
 * Frozen A-DS08-C40 exact student-note approval command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Student_Note;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Student_Note_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C40 exact student-note approval command.
 */
final class Student_Note_Approval_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_APPROVE  = '/student-notes/(?P<note_id>[0-9A-HJKMNP-TV-Z]{26})/commands/approve';
	public const ACTION_APPROVE = 'student_note.approve';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize student note approval rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Approve_Student_Note    $approve Approve.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Approve_Student_Note $approve,
		private Canonical_Json $canonical,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations
	) {
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function register_routes(): void {
		if ( $this->registered ) {
			return;
		}
		if ( ! function_exists( 'register_rest_route' ) ) {
			throw new LogicException( 'Student-note approval REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_APPROVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'permissions_approve' ),
			)
		);
	}

	/**
	 * Permissions approve.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_approve( object $request ) {
		try {
			$note_id = $this->path_id( $request );
			$context = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_APPROVE,
			array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_REPORT, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_STUDENT_NOTE, $note_id, 'approve' )
		);
	}

	/**
	 * Approve.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function approve( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		$decision      = $authorization->policy_decision();
		$context       = $decision->context();
		$object        = $decision->object();
		if (
			null === $idempotency
			|| ! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
			|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
		) {
			return $this->problem( 503, $correlation );
		}

		try {
			$note_id = $this->path_id( $request );
			$query   = $this->context_query( $request );
			$input   = $this->input( $request );
			if ( $query['context_type'] !== $context->type() || $query['context_id'] !== $context->id() || $query['context_revision'] !== $context->revision() ) {
				throw new InvalidArgumentException( 'Student-note approval Context changed.' );
			}
			$result = $this->approve->execute(
				new Approve_Student_Note_Command(
					$grant->metadata()->actor_user_id(),
					$note_id,
					$input['command_id'],
					$input['expected_official_review_id'],
					$input['expected_official_review_hash'],
					$input['expected_revision_id'],
					$input['expected_revision_hash'],
					$input['expected_revision_no'],
					$input['expected_state'],
					$input['expected_include_in_reports'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $context->type(),
					(string) $context->id(),
					(string) $context->revision(),
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->student_note_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array(
				'note_id'         => $result->note_id(),
				'evaluation_id'   => $result->evaluation_id(),
				'official_review' => array(
					'id'   => $result->official_review_id(),
					'hash' => $result->official_review_hash(),
				),
				'note_revision'   => array(
					'id'                       => $result->revision_id(),
					'hash'                     => $result->revision_hash(),
					'revision_no'              => $result->revision_no(),
					'state'                    => $result->state(),
					'include_in_reports'       => $result->include_in_reports(),
					'previous_revision_id'     => $result->previous_revision_id(),
					'source_evaluation_run_id' => $result->source_evaluation_run_id(),
				),
			),
			$correlation,
			201,
			array(
				'idempotent_replay' => $result->replayed(),
				'occurred_at_utc'   => $result->occurred_at_utc(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Student-note approval body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Student-note approval body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Student-note approval sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_include_in_reports', 'expected_official_review_hash', 'expected_official_review_id', 'expected_revision_hash', 'expected_revision_id', 'expected_revision_no', 'expected_state' ) );

		$command_id    = $input['command_id'] ?? null;
		$official_id   = $expected['expected_official_review_id'] ?? null;
		$official_hash = $expected['expected_official_review_hash'] ?? null;
		$revision_id   = $expected['expected_revision_id'] ?? null;
		$revision_hash = $expected['expected_revision_hash'] ?? null;
		$revision_no   = $expected['expected_revision_no'] ?? null;
		$state         = $expected['expected_state'] ?? null;
		$include       = $expected['expected_include_in_reports'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $official_id ) || ! is_string( $official_hash )
			|| ! is_string( $revision_id ) || ! is_string( $revision_hash ) || ! is_int( $revision_no ) || $revision_no < 1
			|| 'teacher_draft' !== $state || false !== $include
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $official_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision_hash )
		) {
			throw new InvalidArgumentException( 'Student-note approval values are invalid.' );
		}
		new Public_Id( $official_id );
		new Public_Id( $revision_id );
		return array(
			'command_id'                    => $command_id,
			'expected_official_review_id'   => $official_id,
			'expected_official_review_hash' => $official_hash,
			'expected_revision_id'          => $revision_id,
			'expected_revision_hash'        => $revision_hash,
			'expected_revision_no'          => $revision_no,
			'expected_state'                => $state,
			'expected_include_in_reports'   => $include,
		);
	}

	/**
	 * Context query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Student-note approval Context is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Student-note approval Context is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Student-note approval Context value is invalid.' );
			}
		}
		return array(
			'context_type'     => $query['context_type'],
			'context_id'       => $query['context_id'],
			'context_revision' => $query['context_revision'],
		);
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Student-note approval path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['note_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Student-note approval path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Student-note approval shape is invalid.' );
		}
	}

	/**
	 * Student note problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation Correlation.
	 * @return \WP_Error
	 */
	private function student_note_problem( Submission_Evaluation_Failure $failure, string $correlation ) {
		$definition = match ( $failure->reason() ) {
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '학생 특기사항을 찾을 수 없습니다.' ),
			Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE,
			Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT,
			Submission_Evaluation_Failure::STATE_CONFLICT => array( 409, '특기사항이 변경되었습니다. 다시 확인해 주세요.' ),
			Submission_Evaluation_Failure::STUDENT_NOTE_SCHEMA_INVALID,
			Submission_Evaluation_Failure::INPUT_INVALID,
			Submission_Evaluation_Failure::STORED_DATA_INVALID => array( 422, '승인할 특기사항을 확인해 주세요.' ),
			default => array( 503, '지금은 특기사항을 승인할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation );
		return array() !== $failure->safe_context() ? $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() ) : $this->envelopes->problem( $problem );
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation Correlation.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $correlation ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation ) );
	}
}
