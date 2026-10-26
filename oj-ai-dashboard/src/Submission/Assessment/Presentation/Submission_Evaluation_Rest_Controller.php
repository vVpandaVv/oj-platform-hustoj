<?php
/**
 * C29 student submission evaluation REST command.
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
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers only the frozen A-DS08-C29 command.
 */
final class Submission_Evaluation_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_EVALUATE = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/evaluate';
	public const ACTION_ENQUEUE = 'submission.evaluation.enqueue';
	public const ROUTE_MANUAL   = '/submissions/(?P<submission_id>[0-9A-HJKMNP-TV-Z]{26})/commands/start-manual-assessment';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission evaluation rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff       $permissions Permissions.
	 * @param Enqueue_Submission_Evaluation $enqueue Enqueue.
	 * @param Canonical_Json                $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory         $envelopes Envelopes.
	 * @param Correlation_Id_Factory        $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Enqueue_Submission_Evaluation $enqueue,
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
			throw new LogicException( 'Submission evaluation REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_MANUAL,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'manual' ),
				'permission_callback' => array( $this, 'permissions_manual' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EVALUATE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'evaluate' ),
				'permission_callback' => array( $this, 'permissions_evaluate' ),
			)
		);
	}

	/**
	 * Permissions evaluate.
	 *
	 * @param object $request Request.
	 * @param bool   $manual Require teacher review permission instead of AI permission.
	 * @return true|\WP_Error
	 */
	public function permissions_evaluate( object $request, bool $manual = false ) {
		try {
			$submission_id = $this->path_id( $request );
			$context       = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$manual ? 'submission.evaluation.manual' : self::ACTION_ENQUEUE,
			array( $manual ? Addon_Capabilities::REVIEW_ASSESSMENTS : Addon_Capabilities::ASSESS_SUBMISSIONS ),
			new Context_Scope(
				$context['context_type'],
				$context['context_id'],
				Context_Scope::OPERATION_EVALUATE,
				null,
				$context['context_revision']
			),
			new Object_Scope( Object_Scope::TYPE_SUBMISSION, $submission_id, 'evaluate' )
		);
	}

	/**
	 * Evaluate.
	 *
	 * @param object $request Request.
	 * @param bool   $manual Prepare locally instead of enqueueing AI work.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function evaluate( object $request, bool $manual = false ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization  = $grant->authorization();
		$correlation_id = $authorization->correlation_id();
		$idempotency    = $authorization->idempotency_key();
		$decision       = $authorization->policy_decision();
		$context        = $decision->context();
		$object         = $decision->object();
		if (
			null === $idempotency
			|| ! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
			|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
		) {
			return $this->problem( 503, $correlation_id );
		}

		try {
			$submission_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if (
				$query['context_type'] !== $context->type()
				|| $query['context_id'] !== $context->id()
				|| $query['context_revision'] !== $context->revision()
			) {
				throw new InvalidArgumentException( 'Submission evaluation Context evidence changed.' );
			}
			$result = $this->enqueue->execute(
				new Enqueue_Submission_Evaluation_Command(
					$grant->metadata()->actor_user_id(),
					$submission_id,
					$input['command_id'],
					$input['expected_official_revision_id'],
					$input['expected_official_revision_hash'],
					$idempotency->fingerprint(),
					$correlation_id,
					(string) $context->type(),
					(string) $context->id(),
					(string) $context->revision(),
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					$this->canonical,
					$manual
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'evaluation' => array(
					'id'                     => $result->evaluation_id(),
					'publication_hash'       => $result->publication_hash(),
					'publication_id'         => $result->publication_id(),
					'source_hash'            => $result->source_hash(),
					'state'                  => $result->evaluation_state(),
					'submission_id'          => $result->submission_id(),
					'submission_revision_id' => $result->submission_revision_id(),
				),
				'run'        => array(
					'id'     => $result->run_id(),
					'run_no' => $result->run_no(),
					'state'  => $result->run_state(),
				),
			),
			$correlation_id,
			$manual ? ( $result->replayed() ? 200 : 201 ) : 202,
			array(
				'idempotent_replay' => $result->replayed(),
				'queued_at_utc'     => $result->queued_at_utc(),
			)
		);
	}

	/**
	 * Authorize teacher-only preparation through the same object and context policy.
	 *
	 * @param object $request REST request.
	 * @return true|\WP_Error
	 */
	public function permissions_manual( object $request ) {
		return $this->permissions_evaluate( $request, true );
	}

	/**
	 * Prepare without AI routing or queue wakeup.
	 *
	 * @param object $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function manual( object $request ) {
		return $this->evaluate( $request, true );
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_official_revision_id:string,expected_official_revision_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission evaluation body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission evaluation body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Submission evaluation body sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_official_revision_hash', 'expected_official_revision_id' ) );
		$command_id    = $input['command_id'] ?? null;
		$revision_id   = $expected['expected_official_revision_id'] ?? null;
		$revision_hash = $expected['expected_official_revision_hash'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $revision_id ) || ! is_string( $revision_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $revision_hash )
		) {
			throw new InvalidArgumentException( 'Submission evaluation body values are invalid.' );
		}
		new Public_Id( $revision_id );
		return array(
			'command_id'                      => $command_id,
			'expected_official_revision_id'   => $revision_id,
			'expected_official_revision_hash' => $revision_hash,
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
			throw new InvalidArgumentException( 'Submission evaluation Context query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Submission evaluation Context query is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission evaluation Context value is invalid.' );
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
			throw new InvalidArgumentException( 'Submission evaluation path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['submission_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation path ID is invalid.' );
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
			throw new InvalidArgumentException( 'Submission evaluation input shape is invalid.' );
		}
	}

	/**
	 * Evaluation problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function evaluation_problem( Submission_Evaluation_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '제출을 찾을 수 없습니다.' ),
			Submission_Evaluation_Failure::SOURCE_STALE,
			Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT => array( 409, '최신 제출 상태를 다시 확인해 주세요.' ),
			Submission_Evaluation_Failure::ARTIFACT_NOT_READY => array( 409, '첨부 파일 처리가 끝난 뒤 다시 시도해 주세요.' ),
			Submission_Evaluation_Failure::INPUT_TOO_LARGE => array( 413, '제출 내용을 나누어 주세요.' ),
			Submission_Evaluation_Failure::SOURCE_INVALID,
			Submission_Evaluation_Failure::INPUT_INVALID,
			Submission_Evaluation_Failure::SUBMISSION_NOT_OFFICIAL,
			Submission_Evaluation_Failure::GRADING_SCHEMA_UNSUPPORTED,
			Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID => array( 422, '활동지와 제출 내용을 확인해 주세요.' ),
			Submission_Evaluation_Failure::NOT_ROUTABLE,
			Submission_Evaluation_Failure::ROUTING_SNAPSHOT_INVALID => array( 503, 'AI 연결을 확인해 주세요.' ),
			default => array( 503, '지금은 평가를 시작할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation(
			$definition[0],
			$failure->reason(),
			$definition[1],
			$correlation_id
		);
		if (
			in_array(
				$failure->reason(),
				array(
					Submission_Evaluation_Failure::SOURCE_STALE,
					Submission_Evaluation_Failure::SOURCE_INVALID,
					Submission_Evaluation_Failure::INPUT_INVALID,
					Submission_Evaluation_Failure::SUBMISSION_NOT_OFFICIAL,
					Submission_Evaluation_Failure::GRADING_SCHEMA_UNSUPPORTED,
					Submission_Evaluation_Failure::GRADING_SCHEMA_INVALID,
					Submission_Evaluation_Failure::ARTIFACT_NOT_READY,
				),
				true
			)
			&& array() !== $failure->safe_context()
		) {
			return $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() );
		}
		return $this->envelopes->problem( $problem );
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $correlation_id ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation_id ) );
	}
}
