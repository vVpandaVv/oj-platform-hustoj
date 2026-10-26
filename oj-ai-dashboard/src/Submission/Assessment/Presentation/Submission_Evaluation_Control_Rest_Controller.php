<?php
/**
 * C31 retry and C32 cooperative cancellation REST controller.
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
use OJAI\Dashboard\Submission\Assessment\Application\Cancel_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Cancel_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Retry_Submission_Evaluation;
use OJAI\Dashboard\Submission\Assessment\Application\Retry_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Control_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C31 retry and C32 cooperative cancellation REST controller.
 */
final class Submission_Evaluation_Control_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_RETRY    = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/retry';
	public const ROUTE_CANCEL   = '/submission-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/cancel';
	public const ACTION_RETRY   = 'submission.evaluation.retry';
	public const ACTION_CANCEL  = 'submission.evaluation.cancel';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission evaluation control rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff      $permissions Permissions.
	 * @param Retry_Submission_Evaluation  $retry Retry.
	 * @param Cancel_Submission_Evaluation $cancel Cancel.
	 * @param Canonical_Json               $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory        $envelopes Envelopes.
	 * @param Correlation_Id_Factory       $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Retry_Submission_Evaluation $retry,
		private Cancel_Submission_Evaluation $cancel,
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
			throw new LogicException( 'Submission evaluation control REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_RETRY,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'permissions_retry' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CANCEL,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permissions_cancel' ),
			)
		);
	}

	/**
	 * Permissions retry.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_retry( object $request ) {
		return $this->permission( $request, self::ACTION_RETRY, 'retry' );
	}

	/**
	 * Permissions cancel.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_cancel( object $request ) {
		return $this->permission( $request, self::ACTION_CANCEL, 'cancel' );
	}

	/**
	 * Retry.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry( object $request ) {
		return $this->execute( $request, Submission_Evaluation_Control_Result::OPERATION_RETRY );
	}

	/**
	 * Cancel.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( object $request ) {
		return $this->execute( $request, Submission_Evaluation_Control_Result::OPERATION_CANCEL );
	}

	/**
	 * Permission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @param string $object_action Object action.
	 * @return true|\WP_Error
	 */
	private function permission( object $request, string $action, string $object_action ) {
		try {
			$evaluation_id = $this->path_id( $request );
			$context       = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action,
			array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
			new Context_Scope(
				$context['context_type'],
				$context['context_id'],
				Context_Scope::OPERATION_EVALUATE,
				null,
				$context['context_revision']
			),
			new Object_Scope( Object_Scope::TYPE_EVALUATION, $evaluation_id, $object_action )
		);
	}

	/**
	 * Execute submission evaluation control rest controller.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function execute( object $request, string $operation ) {
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
			$evaluation_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request, $operation );
			if (
				$query['context_type'] !== $context->type()
				|| $query['context_id'] !== $context->id()
				|| $query['context_revision'] !== $context->revision()
			) {
				throw new InvalidArgumentException( 'Submission evaluation control Context evidence changed.' );
			}
			if ( Submission_Evaluation_Control_Result::OPERATION_RETRY === $operation ) {
				$result = $this->retry->execute(
					new Retry_Submission_Evaluation_Command(
						$grant->metadata()->actor_user_id(),
						$evaluation_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_run_id'],
						$input['expected_run_no'],
						$input['expected_latest_result_id'],
						$input['reason'],
						$idempotency->fingerprint(),
						$correlation_id,
						(string) $object->current_revision(),
						(string) $object->current_state(),
						(string) $object->actor_relation(),
						(string) $context->type(),
						(string) $context->id(),
						(string) $context->revision(),
						$this->canonical
					)
				);
			} else {
				$result = $this->cancel->execute(
					new Cancel_Submission_Evaluation_Command(
						$grant->metadata()->actor_user_id(),
						$evaluation_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_run_id'],
						$input['expected_run_no'],
						$input['reason'],
						$idempotency->fingerprint(),
						$correlation_id,
						(string) $object->current_revision(),
						(string) $object->current_state(),
						(string) $object->actor_relation(),
						(string) $context->type(),
						(string) $context->id(),
						(string) $context->revision(),
						$this->canonical
					)
				);
			}
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
					'latest_result_id'       => $result->latest_result_id(),
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
			Submission_Evaluation_Control_Result::OPERATION_RETRY === $operation ? 202 : 200,
			array(
				'idempotent_replay' => $result->replayed(),
				'operation'         => $result->operation(),
				'occurred_at_utc'   => $result->occurred_at_utc(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request, string $operation ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission evaluation control body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission evaluation control body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Submission evaluation control sections are invalid.' );
		}
		$this->exact_keys( $payload, array( 'reason' ) );
		$expected_keys = Submission_Evaluation_Control_Result::OPERATION_RETRY === $operation
			? array( 'expected_latest_result_id', 'expected_run_id', 'expected_run_no', 'expected_state' )
			: array( 'expected_run_id', 'expected_run_no', 'expected_state' );
		$this->exact_keys( $expected, $expected_keys );

		$command_id       = $input['command_id'] ?? null;
		$expected_state   = $expected['expected_state'] ?? null;
		$expected_run_id  = $expected['expected_run_id'] ?? null;
		$expected_run_no  = $expected['expected_run_no'] ?? null;
		$reason           = $payload['reason'] ?? null;
		$latest_result_id = $expected['expected_latest_result_id'] ?? null;
		if (
			! is_string( $command_id ) || ! is_string( $expected_state ) || ! is_string( $expected_run_id )
			|| ! is_int( $expected_run_no ) || $expected_run_no < 1 || ! is_string( $reason )
			|| ( null !== $latest_result_id && ! is_string( $latest_result_id ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation control values are invalid.' );
		}
		new Public_Id( $expected_run_id );
		if ( null !== $latest_result_id ) {
			new Public_Id( $latest_result_id );
		}
		return array(
			'command_id'                => $command_id,
			'expected_state'            => $expected_state,
			'expected_run_id'           => $expected_run_id,
			'expected_run_no'           => $expected_run_no,
			'expected_latest_result_id' => $latest_result_id,
			'reason'                    => $reason,
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
			throw new InvalidArgumentException( 'Submission evaluation control Context query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Submission evaluation control Context query is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Submission evaluation control Context value is invalid.' );
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
			throw new InvalidArgumentException( 'Submission evaluation control path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Submission evaluation control path ID is invalid.' );
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
			throw new InvalidArgumentException( 'Submission evaluation control input shape is invalid.' );
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
			Submission_Evaluation_Failure::NOT_FOUND => array( 404, '평가를 찾을 수 없습니다.' ),
			Submission_Evaluation_Failure::STATE_CONFLICT => array( 409, '평가 상태가 바뀌었습니다.' ),
			Submission_Evaluation_Failure::SOURCE_STALE => array( 409, '학생 제출 내용이 바뀌었습니다.' ),
			Submission_Evaluation_Failure::CONFIGURATION_CHANGED => array( 409, '평가 설정이 바뀌었습니다.' ),
			Submission_Evaluation_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			Submission_Evaluation_Failure::NOT_ROUTABLE,
			Submission_Evaluation_Failure::ROUTING_SNAPSHOT_INVALID => array( 503, 'AI 연결을 확인해 주세요.' ),
			default => array( 503, '지금은 사용할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id );
		if ( 409 === $definition[0] && array() !== $failure->safe_context() ) {
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
