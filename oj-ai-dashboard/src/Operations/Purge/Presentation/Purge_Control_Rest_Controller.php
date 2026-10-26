<?php
/**
 * Purge control rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Cancel_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Execute_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict C73 execute and C74 safe-boundary cancellation endpoints.
 */
final class Purge_Control_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_EXECUTE  = '/purge-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})/commands/execute';
	public const ROUTE_CANCEL   = '/purge-jobs/(?P<job_id>[0-9A-HJKMNP-TV-Z]{26})/commands/cancel';
	public const ACTION_EXECUTE = 'operations.purge.execute';
	public const ACTION_CANCEL  = 'operations.purge.cancel';

	private const OPERATION_EXECUTE = 'execute';
	private const OPERATION_CANCEL  = 'cancel';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize purge control rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Execute_Purge           $executions Executions.
	 * @param Cancel_Purge            $cancellations Cancellations.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Execute_Purge $executions,
		private Cancel_Purge $cancellations,
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
			throw new LogicException( 'Purge control REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EXECUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permissions_execute' ),
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
	 * Permissions execute.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_execute( object $request ) {
		return $this->permission( $request, self::OPERATION_EXECUTE );
	}

	/**
	 * Permissions cancel.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_cancel( object $request ) {
		return $this->permission( $request, self::OPERATION_CANCEL );
	}

	/**
	 * Execute purge control rest controller.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute( object $request ) {
		return $this->control( $request, self::OPERATION_EXECUTE );
	}

	/**
	 * Cancel.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function cancel( object $request ) {
		return $this->control( $request, self::OPERATION_CANCEL );
	}

	/**
	 * Permission.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return true|\WP_Error
	 */
	private function permission( object $request, string $operation ) {
		try {
			$this->empty_query( $request );
			$this->path_id( $request );
			$this->input( $request, $operation );
		} catch ( Throwable ) {
			return $this->problem(
				422,
				self::OPERATION_EXECUTE === $operation ? Purge_Failure::EXECUTION_INVALID : Purge_Failure::CANCELLATION_INVALID,
				$this->correlations->create()
			);
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::OPERATION_EXECUTE === $operation ? self::ACTION_EXECUTE : self::ACTION_CANCEL,
			array( Addon_Capabilities::MANAGE_MIGRATIONS ),
			null,
			null,
			self::OPERATION_EXECUTE === $operation
				? array( Access_Policy_Request::CORE_DELETE_PLUGINS )
				: array()
		);
	}

	/**
	 * Control.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function control( object $request, string $operation ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, Purge_Failure::STORAGE_UNAVAILABLE, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, Purge_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		try {
			$job_id = $this->path_id( $request );
			$input  = $this->input( $request, $operation );
			if ( self::OPERATION_EXECUTE === $operation ) {
				$result = $this->executions->execute(
					new Execute_Purge_Command(
						$grant->metadata()->actor_user_id(),
						$job_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_preview_hash'],
						$idempotency->fingerprint(),
						$correlation,
						$this->canonical
					)
				);
				$data   = array(
					'purge_job' => array(
						'id'             => $result->job_id(),
						'state'          => $result->state(),
						'preview_hash'   => $result->preview_hash(),
						'checkpoint'     => $this->checkpoint( $result->checkpoint(), $result->checkpoint_hash() ),
						'started_at_utc' => $result->started_at_utc(),
					),
				);
				$status = 202;
			} else {
				$result = $this->cancellations->execute(
					new Cancel_Purge_Command(
						$grant->metadata()->actor_user_id(),
						$job_id,
						$input['command_id'],
						$input['expected_state'],
						$input['expected_preview_hash'],
						$idempotency->fingerprint(),
						$correlation,
						$this->canonical
					)
				);
				$data   = array(
					'purge_job' => array(
						'id'                   => $result->job_id(),
						'state'                => $result->state(),
						'preview_hash'         => $result->preview_hash(),
						'checkpoint'           => $this->checkpoint( $result->checkpoint(), $result->checkpoint_hash() ),
						'cancelled_at_utc'     => $result->cancelled_at_utc(),
						'maintenance_retained' => $result->maintenance_retained(),
					),
				);
				$status = 200;
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem(
				422,
				self::OPERATION_EXECUTE === $operation ? Purge_Failure::EXECUTION_INVALID : Purge_Failure::CANCELLATION_INVALID,
				$correlation
			);
		} catch ( Purge_Failure $failure ) {
			return $this->purge_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Purge_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			$data,
			$correlation,
			$status,
			array(
				'replayed'                => $result->replayed(),
				'mutation_count'          => $result->mutation_count(),
				'ledger_mutation_count'   => $result->ledger_mutation_count(),
				'business_mutation_count' => $result->business_mutation_count(),
				'file_mutation_count'     => $result->file_mutation_count(),
				'platform_write_count'    => $result->platform_write_count(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @param string $operation Operation.
	 * @return array{command_id:string,expected_state:string,expected_preview_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request, string $operation ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Purge control body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Purge control body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Purge control sections are invalid.' );
		}
		$this->exact( $expected, array( 'preview_hash', 'state' ) );

		$command_id     = $input['command_id'] ?? null;
		$state          = $expected['state'] ?? null;
		$preview_hash   = $expected['preview_hash'] ?? null;
		$allowed_states = self::OPERATION_EXECUTE === $operation ? array( 'approved' ) : array( 'approved', 'running' );
		if (
			! is_string( $command_id ) || ! Public_Id::is_valid( $command_id )
			|| ! is_string( $state ) || ! in_array( $state, $allowed_states, true )
			|| ! is_string( $preview_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $preview_hash )
		) {
			throw new InvalidArgumentException( 'Purge control values are invalid.' );
		}
		return array(
			'command_id'            => $command_id,
			'expected_state'        => $state,
			'expected_preview_hash' => $preview_hash,
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
			throw new InvalidArgumentException( 'Purge control path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['job_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Purge control path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Empty query.
	 *
	 * @param object $request Request.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function empty_query( object $request ): void {
		if ( ! method_exists( $request, 'get_query_params' ) || array() !== $request->get_query_params() ) {
			throw new InvalidArgumentException( 'Purge control query must be empty.' );
		}
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
			throw new InvalidArgumentException( 'Purge control input shape is invalid.' );
		}
	}

	/**
	 * Checkpoint.
	 *
	 * @param array<string,mixed>|null $checkpoint Checkpoint.
	 * @param string|null              $hash Hash.
	 * @return array<string,mixed>|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checkpoint( ?array $checkpoint, ?string $hash ): ?array {
		if ( null === $checkpoint && null === $hash ) {
			return null;
		}
		$cursor        = is_array( $checkpoint['cursor'] ?? null ) ? $checkpoint['cursor'] : null;
		$totals        = is_array( $checkpoint['totals'] ?? null ) ? $checkpoint['totals'] : null;
		$postcondition = is_array( $checkpoint['postcondition'] ?? null ) ? $checkpoint['postcondition'] : null;
		if (
			! is_array( $checkpoint ) || null === $cursor || null === $totals || null === $postcondition
			|| ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! is_string( $checkpoint['profile'] ?? null )
			|| ! in_array( $checkpoint['phase'] ?? null, array( 'running', 'completed', 'cancelled', 'failed' ), true )
			|| ! is_int( $checkpoint['batch_size'] ?? null ) || $checkpoint['batch_size'] < 1
			|| ! is_int( $checkpoint['time_limit_seconds'] ?? null ) || $checkpoint['time_limit_seconds'] < 1
			|| ! in_array( $cursor['stage'] ?? null, array( 'private_files', 'tables', 'verify' ), true )
			|| ! is_int( $cursor['table_index'] ?? null ) || $cursor['table_index'] < 0
		) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		foreach ( array( 'deleted_rows', 'deleted_files', 'deleted_bytes' ) as $key ) {
			if ( ! is_int( $totals[ $key ] ?? null ) || $totals[ $key ] < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
		}
		foreach ( array( 'remaining_rows', 'remaining_files', 'remaining_blob_bytes' ) as $key ) {
			if ( ! is_int( $postcondition[ $key ] ?? null ) || $postcondition[ $key ] < 0 ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
				throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
			}
		}
		$verified = $postcondition['verified_at_utc'] ?? null;
		if ( null !== $verified && ( ! is_string( $verified ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $verified ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Purge_Failure::because( Purge_Failure::CHECKPOINT_INVALID );
		}
		return array(
			'profile'            => $checkpoint['profile'],
			'phase'              => $checkpoint['phase'],
			'batch_size'         => $checkpoint['batch_size'],
			'time_limit_seconds' => $checkpoint['time_limit_seconds'],
			'cursor'             => array(
				'stage'       => $cursor['stage'],
				'table_index' => $cursor['table_index'],
			),
			'totals'             => array(
				'deleted_rows'  => $totals['deleted_rows'],
				'deleted_files' => $totals['deleted_files'],
				'deleted_bytes' => $totals['deleted_bytes'],
			),
			'postcondition'      => array(
				'remaining_rows'       => $postcondition['remaining_rows'],
				'remaining_files'      => $postcondition['remaining_files'],
				'remaining_blob_bytes' => $postcondition['remaining_blob_bytes'],
				'verified_at_utc'      => $verified,
			),
			'hash'               => $hash,
		);
	}

	/**
	 * Purge problem.
	 *
	 * @param Purge_Failure $failure Failure.
	 * @param string        $correlation Correlation.
	 * @return \WP_Error
	 */
	private function purge_problem( Purge_Failure $failure, string $correlation ) {
		$definition = match ( $failure->reason() ) {
			Purge_Failure::JOB_NOT_FOUND => array( 404, '정리 작업을 찾을 수 없습니다.' ),
			Purge_Failure::JOB_STATE_CONFLICT => array( 409, '정리 작업 상태가 바뀌었습니다.' ),
			Purge_Failure::PREVIEW_STALE,
			Purge_Failure::APPROVAL_EXPIRED,
			Purge_Failure::BACKUP_INVALID => array( 409, '삭제 내용을 다시 확인해 주세요.' ),
			Purge_Failure::LEGAL_HOLD_BLOCKED => array( 409, '보존 중인 데이터가 있습니다.' ),
			Purge_Failure::INCOMPLETE_JOBS_BLOCKED => array( 409, '진행 중인 작업을 먼저 완료해 주세요.' ),
			Purge_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			Purge_Failure::EXECUTION_INVALID,
			Purge_Failure::CANCELLATION_INVALID,
			Purge_Failure::INPUT_INVALID => array( 422, '요청 내용을 확인해 주세요.' ),
			default => array( 503, '지금은 영구 정리 작업을 처리할 수 없습니다.' ),
		};
		return $this->problem( $definition[0], $failure->reason(), $correlation, $definition[1] );
	}

	/**
	 * Problem.
	 *
	 * @param int         $status Status.
	 * @param string      $code Code.
	 * @param string      $correlation Correlation.
	 * @param string|null $message Message.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $code, string $correlation, ?string $message = null ) {
		$message ??= 422 === $status ? '요청 내용을 확인해 주세요.' : '지금은 영구 정리 작업을 처리할 수 없습니다.';
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
