<?php
/**
 * C30 assignment submission evaluation REST command.
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
use OJAI\Dashboard\Submission\Assessment\Application\Assignment_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Assignment_Evaluations_Result;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers only the frozen A-DS08-C30 command.
 */
final class Assignment_Evaluation_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_EVALUATE = '/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/evaluate-submissions';
	public const ACTION_ENQUEUE = 'assignment.evaluations.enqueue';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize assignment evaluation rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff        $permissions Permissions.
	 * @param Enqueue_Assignment_Evaluations $enqueue Enqueue.
	 * @param Canonical_Json                 $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory          $envelopes Envelopes.
	 * @param Correlation_Id_Factory         $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Enqueue_Assignment_Evaluations $enqueue,
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
			throw new LogicException( 'Assignment evaluation REST support is unavailable.' );
		}
		$this->registered = true;
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
	 * @return true|\WP_Error
	 */
	public function permissions_evaluate( object $request ) {
		try {
			$assignment_id = $this->path_id( $request );
			$context       = $this->context_query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_ENQUEUE,
			array( Addon_Capabilities::ASSESS_SUBMISSIONS ),
			new Context_Scope(
				$context['context_type'],
				$context['context_id'],
				Context_Scope::OPERATION_EVALUATE,
				null,
				$context['context_revision']
			),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, 'evaluate_submissions' )
		);
	}

	/**
	 * Evaluate.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function evaluate( object $request ) {
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
			$assignment_id = $this->path_id( $request );
			$query         = $this->context_query( $request );
			$input         = $this->input( $request );
			if (
				$query['context_type'] !== $context->type()
				|| $query['context_id'] !== $context->id()
				|| $query['context_revision'] !== $context->revision()
			) {
				throw new InvalidArgumentException( 'Assignment evaluation Context evidence changed.' );
			}
			$result = $this->enqueue->execute(
				new Enqueue_Assignment_Evaluations_Command(
					$grant->metadata()->actor_user_id(),
					$assignment_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_schedule_version'],
					$input['source_cutoff_utc'],
					$input['item_cursor'],
					$input['limit'],
					$idempotency->fingerprint(),
					$correlation_id,
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
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array(
				'assignment_id' => $result->assignment_id(),
				'items'         => $result->items(),
				'receipt'       => array(
					'digest'         => $result->receipt_digest(),
					'schema_id'      => Enqueue_Assignment_Evaluations_Result::RECEIPT_SCHEMA,
					'schema_version' => Enqueue_Assignment_Evaluations_Result::RECEIPT_VERSION,
				),
			),
			$correlation_id,
			202,
			array(
				'batch_receipt_replay' => $result->replayed(),
				'counts'               => $result->counts(),
				'item_cursor'          => $result->item_cursor(),
				'next_item_cursor'     => $result->next_item_cursor(),
				'processed_at_utc'     => $result->processed_at_utc(),
				'source_cutoff_utc'    => $result->source_cutoff_utc(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_schedule_version:int,source_cutoff_utc:string,item_cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation body sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_schedule_version', 'expected_state' ) );
		$this->exact_keys( $payload, array( 'item_cursor', 'limit', 'source_cutoff_utc' ) );
		if (
			! is_string( $input['command_id'] ?? null )
			|| ! is_string( $expected['expected_state'] ?? null )
			|| ! is_int( $expected['expected_schedule_version'] ?? null )
			|| ! is_string( $payload['source_cutoff_utc'] ?? null )
			|| ! is_string( $payload['item_cursor'] ?? null )
			|| ! is_int( $payload['limit'] ?? null )
		) {
			throw new InvalidArgumentException( 'Assignment evaluation body values are invalid.' );
		}
		return array(
			'command_id'                => $input['command_id'],
			'expected_state'            => $expected['expected_state'],
			'expected_schedule_version' => $expected['expected_schedule_version'],
			'source_cutoff_utc'         => $payload['source_cutoff_utc'],
			'item_cursor'               => $payload['item_cursor'],
			'limit'                     => $payload['limit'],
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
			throw new InvalidArgumentException( 'Assignment evaluation Context query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array_is_list( $query ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation Context query is invalid.' );
		}
		$this->exact_keys( $query, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_type', 'context_id', 'context_revision' ) as $key ) {
			if ( ! is_string( $query[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Assignment evaluation Context value is invalid.' );
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
			throw new InvalidArgumentException( 'Assignment evaluation path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['assignment_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Assignment evaluation path ID is invalid.' );
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
			throw new InvalidArgumentException( 'Assignment evaluation input shape is invalid.' );
		}
	}

	/**
	 * Evaluation problem.
	 *
	 * @param Assignment_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function evaluation_problem( Assignment_Evaluation_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Assignment_Evaluation_Failure::NOT_FOUND => array( 404, '배포를 찾을 수 없습니다.' ),
			Assignment_Evaluation_Failure::SOURCE_STALE,
			Assignment_Evaluation_Failure::IDEMPOTENCY_CONFLICT => array( 409, '최신 배포 상태를 다시 확인해 주세요.' ),
			Assignment_Evaluation_Failure::CUTOFF_INVALID => array( 422, '평가 기준 시각을 확인해 주세요.' ),
			default => array( 503, '지금은 일괄 평가를 시작할 수 없습니다.' ),
		};
		$problem = Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id );
		if ( Assignment_Evaluation_Failure::SOURCE_STALE === $failure->reason() && array() !== $failure->safe_context() ) {
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
