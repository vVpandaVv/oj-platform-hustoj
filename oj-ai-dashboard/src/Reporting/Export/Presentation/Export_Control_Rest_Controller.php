<?php
/**
 * C53/C54 export job control REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Export\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Export\Application\Cancel_Export_Job;
use OJAI\Dashboard\Reporting\Export\Application\Cancel_Export_Job_Command;
use OJAI\Dashboard\Reporting\Export\Application\Export_Control_Result;
use OJAI\Dashboard\Reporting\Export\Application\Export_Failure;
use OJAI\Dashboard\Reporting\Export\Application\Retry_Export_Job;
use OJAI\Dashboard\Reporting\Export\Application\Retry_Export_Job_Command;
use OJAI\Dashboard\Reporting\Export\Port\Export_Query_Port_Interface;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C53/C54 export job control REST controller.
 */
final class Export_Control_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_CANCEL   = '/export-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})/commands/cancel';
	public const ROUTE_RETRY    = '/export-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})/commands/retry';
	public const ACTION_CANCEL  = 'export.job.cancel';
	public const ACTION_RETRY   = 'export.job.retry';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize export control rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff      $permissions Permissions.
	 * @param Current_Actor_Port_Interface $current_actor Current actor.
	 * @param Export_Query_Port_Interface  $query Query.
	 * @param Cancel_Export_Job            $cancel_job Cancel job.
	 * @param Retry_Export_Job             $retry_job Retry job.
	 * @param Canonical_Json               $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory        $envelopes Envelopes.
	 * @param Correlation_Id_Factory       $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Current_Actor_Port_Interface $current_actor,
		private Export_Query_Port_Interface $query,
		private Cancel_Export_Job $cancel_job,
		private Retry_Export_Job $retry_job,
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
			throw new LogicException( 'Export control REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_CANCEL,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'cancel' ),
				'permission_callback' => array( $this, 'permissions_cancel' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_RETRY,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'retry' ),
				'permission_callback' => array( $this, 'permissions_retry' ),
			)
		);
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
	 * Permissions retry.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_retry( object $request ) {
		return $this->permission( $request, self::ACTION_RETRY );
	}
	/**
	 * Cancel.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( object $request ) {
		return $this->execute( $request, Export_Control_Result::OPERATION_CANCEL );
	}
	/**
	 * Retry.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function retry( object $request ) {
		return $this->execute( $request, Export_Control_Result::OPERATION_RETRY );
	}

	/**
	 * Permission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function permission( object $request, string $action ) {
		try {
			$job_id = $this->path_id( $request );
			$actor  = $this->current_actor->current_actor_user_id();
			if ( $actor < 1 ) {
				return $this->permissions->check( $request, Access_Policy_Request::SHELL_PUBLIC, $action, array( Addon_Capabilities::PARTICIPATE ) );
			}
			$row = $this->query->find_job_for_actor( $actor, $job_id );
			if ( null === $row ) {
				return $this->problem( 404, $this->correlations->create() );
			}
			$authorization = $row['authorization'] ?? null;
			if ( ! is_array( $authorization ) || array_is_list( $authorization ) ) {
				throw new InvalidArgumentException( 'Export control authorization is unavailable.' );
			}
			$this->exact( $authorization, array( 'context', 'object' ) );
			$object  = $authorization['object'] ?? null;
			$context = $authorization['context'] ?? null;
			if ( ! is_array( $object ) || array_is_list( $object ) ) {
				throw new InvalidArgumentException( 'Export control object authorization is invalid.' );
			}
			$this->exact( $object, array( 'action', 'id', 'relation', 'revision', 'state', 'type' ) );
			$relation = (string) $object['relation'];
			if ( ! in_array( $relation, array( 'owner', 'context_staff', 'context_reporter', 'self_target', 'self_submission', 'self_release' ), true ) ) {
				throw new InvalidArgumentException( 'Export control actor relation is invalid.' );
			}
			$is_self       = in_array( $relation, array( 'self_target', 'self_submission', 'self_release' ), true );
			$context_scope = null;
			if ( null !== $context ) {
				if ( ! is_array( $context ) || array_is_list( $context ) ) {
					throw new InvalidArgumentException( 'Export control Context authorization is invalid.' );
				}
				$this->exact( $context, array( 'id', 'revision', 'type' ) );
				$context_scope = new Context_Scope(
					(string) $context['type'],
					(string) $context['id'],
					$is_self ? Context_Scope::OPERATION_PARTICIPATE : Context_Scope::OPERATION_REPORT,
					$is_self ? $actor : null,
					(string) $context['revision']
				);
			}
			if ( $is_self && null === $context_scope ) {
				throw new InvalidArgumentException( 'Self export control Context is required.' );
			}
			return $this->permissions->check(
				$request,
				$is_self ? Access_Policy_Request::SHELL_PUBLIC : Access_Policy_Request::SHELL_CONSOLE,
				$action,
				array( $is_self ? Addon_Capabilities::PARTICIPATE : Addon_Capabilities::EXPORT_REPORTS ),
				$context_scope,
				new Object_Scope(
					(string) $object['type'],
					(string) $object['id'],
					(string) $object['action'],
					is_string( $object['revision'] ) ? $object['revision'] : null,
					is_string( $object['state'] ) ? $object['state'] : null
				)
			);
		} catch ( Export_Failure ) {
			return $this->problem( 503, $this->correlations->create() );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
	}

	/**
	 * Execute export control rest controller.
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
		$correlation = $grant->authorization()->correlation_id();
		$idempotency = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, $correlation );
		}
		try {
			$job_id = $this->path_id( $request );
			$input  = $this->input( $request, $operation );
			if ( Export_Control_Result::OPERATION_CANCEL === $operation ) {
				$result = $this->cancel_job->execute(
					new Cancel_Export_Job_Command(
						$grant->metadata()->actor_user_id(),
						$job_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_source_hash'],
						$input['reason'],
						$idempotency->fingerprint(),
						$correlation,
						$this->canonical
					)
				);
			} else {
				$result = $this->retry_job->execute(
					new Retry_Export_Job_Command(
						$grant->metadata()->actor_user_id(),
						$job_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_source_hash'],
						$input['expected_error_code'],
						$input['reason'],
						$idempotency->fingerprint(),
						$correlation,
						$this->canonical
					)
				);
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Export_Failure $failure ) {
			return $this->export_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}
		return $this->envelopes->success(
			array(
				'export_job'    => array(
					'artifact_kind' => $result->artifact_kind(),
					'job_id'        => $result->job_id(),
					'queued_at_utc' => $result->queued_at_utc(),
					'request_hash'  => $result->request_hash(),
					'source_hash'   => $result->source_hash(),
					'source_id'     => $result->source_id(),
					'source_type'   => $result->source_type(),
					'state'         => $result->state(),
				),
				'source_job_id' => $result->source_job_id(),
			),
			$correlation,
			Export_Control_Result::OPERATION_RETRY === $operation ? 202 : 200,
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
	 * @return array<string,string>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request, string $operation ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Export control JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Export control JSON body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Export control sections are invalid.' );
		}
		$this->exact( $payload, array( 'reason' ) );
		$this->exact(
			$expected,
			Export_Control_Result::OPERATION_RETRY === $operation
			? array( 'expected_error_code', 'expected_source_hash', 'expected_state' )
			: array( 'expected_source_hash', 'expected_state' )
		);
		$command_id           = $input['command_id'] ?? null;
		$reason               = $payload['reason'] ?? null;
		$expected_state       = $expected['expected_state'] ?? null;
		$expected_source_hash = $expected['expected_source_hash'] ?? null;
		$expected_error_code  = $expected['expected_error_code'] ?? '';
		foreach ( array( $command_id, $reason, $expected_state, $expected_source_hash, $expected_error_code ) as $index => $value ) {
			if ( ! is_string( $value ) || ( 4 === $index && Export_Control_Result::OPERATION_CANCEL === $operation && '' !== $value ) ) {
				throw new InvalidArgumentException( 'Export control values are invalid.' );
			}
		}
		return compact( 'command_id', 'reason', 'expected_state', 'expected_source_hash', 'expected_error_code' );
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
			throw new InvalidArgumentException( 'Export control path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['job_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Export control job ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Export control fields are invalid.' );
		}
	}

	/**
	 * Export problem.
	 *
	 * @param Export_Failure $failure Failure.
	 * @param string         $correlation Correlation.
	 * @return \WP_Error
	 */
	private function export_problem( Export_Failure $failure, string $correlation ) {
		$definition = match ( $failure->reason() ) {
			Export_Failure::NOT_FOUND => array( 404, '대상을 찾을 수 없습니다.' ),
			Export_Failure::STATE_CONFLICT => array( 409, '내보내기 상태가 바뀌었습니다.' ),
			Export_Failure::NOT_RETRYABLE => array( 409, '이 작업은 다시 실행할 수 없습니다.' ),
			Export_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			default => array( 503, '지금은 내보내기 작업을 변경할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation );
		if ( 409 === $definition[0] && array() !== $failure->safe_context() ) {
			return $this->envelopes->problem_with_safe_data( $problem, $failure->safe_context() );
		}
		return $this->envelopes->problem( $problem );
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
