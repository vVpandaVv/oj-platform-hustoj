<?php
/**
 * Frozen A-DS08-C38 student special-note draft command.
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
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Student_Note_Draft;
use OJAI\Dashboard\Submission\Assessment\Application\Generate_Student_Note_Draft_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-C38 student special-note draft command.
 */
final class Student_Note_Rest_Controller {
	public const REST_NAMESPACE  = 'oj-ai/v1';
	public const ROUTE_GENERATE  = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/generate-student-note';
	public const ACTION_GENERATE = 'submission.student_note.generate';
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * Initialize student note rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff     $permissions Permissions.
	 * @param Generate_Student_Note_Draft $generate Generate.
	 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory       $envelopes Envelopes.
	 * @param Correlation_Id_Factory      $correlations Correlations.
	 */
	public function __construct( private Rest_Permission_Handoff $permissions, private Generate_Student_Note_Draft $generate, private Canonical_Json $canonical, private Rest_Envelope_Factory $envelopes, private Correlation_Id_Factory $correlations ) {
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
		} if ( ! function_exists( 'register_rest_route' ) ) {
			throw new LogicException( 'Student note REST support is unavailable.' );
		} $this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_GENERATE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate' ),
				'permission_callback' => array( $this, 'permissions_generate' ),
			)
		);
	}
	/**
	 * Permissions generate.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_generate( object $request ) {
		try {
			$id      = $this->path_id( $request );
			$context = $this->context_query( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}
			return $this->permissions->check( $request, Access_Policy_Request::SHELL_CONSOLE, self::ACTION_GENERATE, array( Addon_Capabilities::MANAGE_STUDENT_NOTES ), new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_REPORT, null, $context['context_revision'] ), new Object_Scope( Object_Scope::TYPE_EVALUATION, $id, 'report' ) );
	}
	/**
	 * Generate.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function generate( object $request ) {
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
		if ( null === $idempotency || ! $context instanceof Context_Authorization_Result || ! $context->is_allowed() || ! $object instanceof Object_Authorization_Result || ! $object->is_allowed() ) {
			return $this->problem( 503, $correlation );
		}
		try {
			$evaluation_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if ( $query['context_type'] !== $context->type() || $query['context_id'] !== $context->id() || $query['context_revision'] !== $context->revision() ) {
				throw new InvalidArgumentException( 'Student note Context changed.' );
			}
			$result = $this->generate->execute( new Generate_Student_Note_Draft_Command( $grant->metadata()->actor_user_id(), $evaluation_id, $input['command_id'], $input['expected_official_review_id'], $input['expected_official_review_hash'], $idempotency->fingerprint(), $correlation, (string) $context->type(), (string) $context->id(), (string) $context->revision(), (string) $object->current_revision(), (string) $object->current_state(), (string) $object->actor_relation(), $this->canonical ) );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->student_note_problem( $failure, $correlation );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation );
		}
			return $this->envelopes->success(
				array(
					'evaluation_id'   => $result->evaluation_id(),
					'official_review' => array(
						'id'   => $result->official_review_id(),
						'hash' => $result->official_review_hash(),
					),
					'run'             => array(
						'id'        => $result->run_id(),
						'run_no'    => $result->run_no(),
						'state'     => $result->run_state(),
						'operation' => 'special_note_draft',
					),
				),
				$correlation,
				202,
				array(
					'idempotent_replay' => $result->replayed(),
					'input_digest'      => $result->input_digest(),
					'queued_at_utc'     => $result->queued_at_utc(),
				)
			);
	}
	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_official_review_id:string,expected_official_review_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Student note body is unavailable.' );
		} $input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Student note body is invalid.' );
		} $this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Student note sections are invalid.' );
		} $this->exact_keys( $expected, array( 'expected_official_review_hash', 'expected_official_review_id' ) );
		$command = $input['command_id'] ?? null;
		$id      = $expected['expected_official_review_id'] ?? null;
		$hash    = $expected['expected_official_review_hash'] ?? null;
		if ( ! is_string( $command ) || ! is_string( $id ) || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
			throw new InvalidArgumentException( 'Student note values are invalid.' );
		} new Public_Id( $id );
		return array(
			'command_id'                    => $command,
			'expected_official_review_id'   => $id,
			'expected_official_review_hash' => $hash,
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
			throw new InvalidArgumentException( 'Student note Context is unavailable.' );
		} $query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Student note Context is invalid.' );
		} $this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Student note Context value is invalid.' );
			}
		} return array(
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
			throw new InvalidArgumentException( 'Student note path is unavailable.' );
		} $params = $request->get_url_params();
		$value    = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Student note path ID is invalid.' );
		} return $value;
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
			throw new InvalidArgumentException( 'Student note shape is invalid.' );
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
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '평가를 찾을 수 없습니다.' ), Submission_Evaluation_Failure::STUDENT_NOTE_SOURCE_STALE, Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT, Submission_Evaluation_Failure::STATE_CONFLICT => array( 409, '평가 근거가 변경되어 특기사항을 다시 확인해야 합니다.' ), Submission_Evaluation_Failure::INPUT_TOO_LARGE => array( 413, '특기사항 근거가 너무 큽니다.' ), Submission_Evaluation_Failure::INPUT_INVALID, Submission_Evaluation_Failure::STUDENT_NOTE_SCHEMA_INVALID, Submission_Evaluation_Failure::STORED_DATA_INVALID => array( 422, '특기사항 근거를 확인해 주세요.' ), default => array( 503, '지금은 특기사항 초안을 만들 수 없습니다.' ) };
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
