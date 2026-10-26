<?php
/**
 * C11 retry and C12 cancellation REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Cancel_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Cancel_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Retry_Worksheet_Evaluation;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Retry_Worksheet_Evaluation_Command;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Control_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C11 retry and C12 cancellation REST controller.
 */
final class Worksheet_Evaluation_Control_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_RETRY    = '/worksheet-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/retry';
	public const ROUTE_CANCEL   = '/worksheet-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})/commands/cancel';
	public const ACTION_RETRY   = 'worksheet.evaluation.retry';
	public const ACTION_CANCEL  = 'worksheet.evaluation.cancel';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet evaluation control rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff     $permissions Permissions.
	 * @param Retry_Worksheet_Evaluation  $retry Retry.
	 * @param Cancel_Worksheet_Evaluation $cancel Cancel.
	 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory       $envelopes Envelopes.
	 * @param Correlation_Id_Factory      $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Retry_Worksheet_Evaluation $retry,
		private Cancel_Worksheet_Evaluation $cancel,
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
			throw new LogicException( 'WordPress REST route registration is unavailable.' );
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
		return $this->permission( $request, self::ACTION_RETRY );
	}

	/**
	 * Permissions cancel.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_cancel( object $request ) {
		return $this->permission( $request, self::ACTION_CANCEL );
	}

	/**
	 * Retry.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry( object $request ) {
		return $this->execute( $request, Worksheet_Evaluation_Control_Result::OPERATION_RETRY );
	}

	/**
	 * Cancel.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( object $request ) {
		return $this->execute( $request, Worksheet_Evaluation_Control_Result::OPERATION_CANCEL );
	}

	/**
	 * Permission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 */
	private function permission( object $request, string $action ) {
		try {
			$evaluation_id = $this->path_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action,
			array( Addon_Capabilities::ASSESS_WORKSHEETS ),
			null,
			new Object_Scope( Object_Scope::TYPE_EVALUATION, $evaluation_id, 'edit' )
		);
	}

	/**
	 * Execute worksheet evaluation control rest controller.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function execute( object $request, string $operation ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		$object         = $grant->authorization()->policy_decision()->object();
		if ( null === $idempotency || ! $object instanceof Object_Authorization_Result || ! $object->is_allowed() ) {
			return $this->problem( 503, $correlation_id );
		}

		try {
			$evaluation_id = $this->path_id( $request );
			$input         = $this->input( $request );
			if ( Worksheet_Evaluation_Control_Result::OPERATION_RETRY === $operation ) {
				$result = $this->retry->execute(
					new Retry_Worksheet_Evaluation_Command(
						$grant->metadata()->actor_user_id(),
						$evaluation_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_run_id'],
						$input['expected_run_no'],
						$idempotency->fingerprint(),
						$correlation_id,
						(string) $object->current_revision(),
						(string) $object->current_state(),
						(string) $object->actor_relation(),
						$this->canonical
					)
				);
			} else {
				$result = $this->cancel->execute(
					new Cancel_Worksheet_Evaluation_Command(
						$grant->metadata()->actor_user_id(),
						$evaluation_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_run_id'],
						$input['expected_run_no'],
						$idempotency->fingerprint(),
						$correlation_id,
						(string) $object->current_revision(),
						(string) $object->current_state(),
						(string) $object->actor_relation(),
						$this->canonical
					)
				);
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'evaluation' => array(
					'id'                    => $result->evaluation_id(),
					'state'                 => $result->evaluation_state(),
					'worksheet_revision_id' => $result->worksheet_revision_id(),
					'source_hash'           => $result->source_hash(),
				),
				'run'        => array(
					'id'     => $result->run_id(),
					'run_no' => $result->run_no(),
					'state'  => $result->run_state(),
				),
			),
			$correlation_id,
			Worksheet_Evaluation_Control_Result::OPERATION_RETRY === $operation ? 202 : 200,
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
	 * @return array{command_id:string,expected_state:string,expected_run_id:string,expected_run_no:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation command JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation command JSON body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Worksheet evaluation command sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_state', 'expected_run_id', 'expected_run_no' ) );
		$command_id      = $input['command_id'] ?? null;
		$expected_state  = $expected['expected_state'] ?? null;
		$expected_run_id = $expected['expected_run_id'] ?? null;
		$expected_run_no = $expected['expected_run_no'] ?? null;
		if (
			! is_string( $command_id )
			|| ! is_string( $expected_state )
			|| ! is_string( $expected_run_id )
			|| ! is_int( $expected_run_no )
			|| $expected_run_no < 1
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation command values are invalid.' );
		}
		new Public_Id( $expected_run_id );
		return compact( 'command_id', 'expected_state', 'expected_run_id', 'expected_run_no' );
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
			throw new InvalidArgumentException( 'Worksheet evaluation path parameters are unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['evaluation_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation ID is invalid.' );
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
			throw new InvalidArgumentException( 'Worksheet evaluation command contains unknown or missing fields.' );
		}
	}

	/**
	 * Evaluation problem.
	 *
	 * @param Worksheet_Evaluation_Failure $failure Failure.
	 * @param string                       $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function evaluation_problem( Worksheet_Evaluation_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Worksheet_Evaluation_Failure::NOT_FOUND => array( 404, '대상을 찾을 수 없습니다.' ),
			Worksheet_Evaluation_Failure::STATE_CONFLICT => array( 409, '평가 상태가 바뀌었습니다.' ),
			Worksheet_Evaluation_Failure::SOURCE_STALE => array( 409, '내용이 바뀌었습니다.' ),
			Worksheet_Evaluation_Failure::CONFIGURATION_CHANGED => array( 409, '평가 설정이 바뀌었습니다.' ),
			Worksheet_Evaluation_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			Worksheet_Evaluation_Failure::CONFIGURATION_UNAVAILABLE => array( 503, '평가 설정을 확인해 주세요.' ),
			Worksheet_Evaluation_Failure::NOT_ROUTABLE,
			Worksheet_Evaluation_Failure::ROUTING_SNAPSHOT_INVALID => array( 503, 'AI 연결을 확인해 주세요.' ),
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
