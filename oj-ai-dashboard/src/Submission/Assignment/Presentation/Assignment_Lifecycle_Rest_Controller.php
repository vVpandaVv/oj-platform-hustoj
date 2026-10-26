<?php
/**
 * Assignment schedule and lifecycle REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment;
use OJAI\Dashboard\Submission\Assignment\Application\Change_Assignment_Command;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens C14-C18 through one exact command path profile.
 */
final class Assignment_Lifecycle_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_COMMAND  = '/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})/commands/(?P<assignment_command>update-schedule|open|close|reopen|withdraw)';
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize assignment lifecycle rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Change_Assignment       $change_assignment Change assignment.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Change_Assignment $change_assignment,
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
			self::ROUTE_COMMAND,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'change' ),
				'permission_callback' => array( $this, 'permissions_change' ),
			)
		);
	}

	/**
	 * Permissions change.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function permissions_change( object $request ) {
		try {
			$input         = $this->change_input( $request );
			$context       = $this->context_query( $request );
			$assignment_id = $this->path_id( $request );
			$action        = $this->path_action( $request );
			if ( $input['action'] !== $action ) {
				throw new InvalidArgumentException( 'Assignment command action does not match.' );
			}
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'assignment.' . $action,
			array( Addon_Capabilities::ASSIGN_WORKSHEETS ),
			new Context_Scope( $context['context_type'], $context['context_id'], Context_Scope::OPERATION_ASSIGN, null, $context['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, $action )
		);
	}

	/**
	 * Change.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 422, $correlation_id );
		}
		try {
			$input  = $this->change_input( $request );
			$result = $this->change_assignment->execute(
				new Change_Assignment_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request ),
					$input['action'],
					$input['command_id'],
					$input['expected_state'],
					$input['expected_lock_version'],
					$input['expected_schedule_version'],
					$input['expected_schedule_hash'],
					$input['schedule'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}
			$assignment = $result->assignment();
			return $this->envelopes->success(
				array( 'assignment' => $assignment ),
				$correlation_id,
				200,
				array(
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->replayed() ? 0 : 1,
				),
				array( 'self' => $this->assignment_url( $assignment['id'], $this->context_query( $request ) ) ),
				$this->actions( $assignment['state'] )
			);
	}

	/**
	 * Change input.
	 *
	 * @param object $request Request.
	 * @return array{action:string,command_id:string,expected_state:string,expected_lock_version:int,expected_schedule_version:int,expected_schedule_hash:string,schedule:?array}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function change_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Assignment command body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Assignment command body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if (
			! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload )
			|| ( array() !== $payload && array_is_list( $payload ) )
		) {
			throw new InvalidArgumentException( 'Assignment command sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_lock_version', 'expected_schedule_hash', 'expected_schedule_version', 'expected_state' ) );
		$action        = $this->path_action( $request );
		$with_schedule = in_array( $action, array( Change_Assignment_Command::UPDATE_SCHEDULE, Change_Assignment_Command::REOPEN ), true );
		$this->exact_keys( $payload, $with_schedule ? array( 'schedule' ) : array() );
		$schedule = $with_schedule ? ( $payload['schedule'] ?? null ) : null;
		if ( $with_schedule && ( ! is_array( $schedule ) || array_is_list( $schedule ) ) ) {
			throw new InvalidArgumentException( 'Assignment command schedule is invalid.' );
		}
		$result = array(
			'action'                    => $action,
			'command_id'                => $input['command_id'] ?? null,
			'expected_state'            => $expected['expected_state'] ?? null,
			'expected_lock_version'     => $expected['expected_lock_version'] ?? null,
			'expected_schedule_version' => $expected['expected_schedule_version'] ?? null,
			'expected_schedule_hash'    => $expected['expected_schedule_hash'] ?? null,
			'schedule'                  => $schedule,
		);
		foreach ( array( 'action', 'command_id', 'expected_state', 'expected_schedule_hash' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Assignment command value is invalid.' );
			}
		}
		foreach ( array( 'expected_lock_version', 'expected_schedule_version' ) as $field ) {
			if ( ! is_int( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'Assignment command version is invalid.' );
			}
		}
		return $result;
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
			throw new InvalidArgumentException( 'Assignment Context query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Assignment Context query is invalid.' );
		}
		$this->exact_keys( $params, array( 'context_id', 'context_revision', 'context_type' ) );
		foreach ( array( 'context_id', 'context_revision', 'context_type' ) as $field ) {
			if ( ! is_string( $params[ $field ] ?? null ) ) {
				throw new InvalidArgumentException( 'Assignment Context value is invalid.' );
			}
		}
		return $params;
	}

	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request ): string {
		$params = method_exists( $request, 'get_url_params' ) ? $request->get_url_params() : null;
		$id     = is_array( $params ) ? ( $params['assignment_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Assignment path is invalid.' );
		}
		new Public_Id( $id );
		return $id;
	}
	/**
	 * Path action.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_action( object $request ): string {
		$params = method_exists( $request, 'get_url_params' ) ? $request->get_url_params() : null;
		$value  = is_array( $params ) ? ( $params['assignment_command'] ?? null ) : null;
		return match ( $value ) {
			'update-schedule' => Change_Assignment_Command::UPDATE_SCHEDULE,
			'open' => Change_Assignment_Command::OPEN,
			'close' => Change_Assignment_Command::CLOSE,
			'reopen' => Change_Assignment_Command::REOPEN,
			'withdraw' => Change_Assignment_Command::WITHDRAW,
			default => throw new InvalidArgumentException( 'Assignment command path is invalid.' ),
		};
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
			throw new InvalidArgumentException( 'Assignment command object keys are invalid.' );
		}
	}
	/**
	 * Actions.
	 *
	 * @param string $state State.
	 * @return array<int,string>
	 */
	private function actions( string $state ): array {
		return match ( $state ) {
			'draft', 'scheduled' => array( 'assignment.update_schedule', 'assignment.open', 'assignment.withdraw' ),
			'open' => array( 'assignment.update_schedule', 'assignment.close', 'assignment.withdraw' ),
			'closed' => array( 'assignment.reopen', 'assignment.withdraw' ),
			default => array(),
		};
	}
	/**
	 * Assignment url.
	 *
	 * @param string               $id Id.
	 * @param array<string,string> $context Access or execution context for this operation.
	 * @return string
	 */
	private function assignment_url( string $id, array $context ): string {
		$path = 'oj-ai/v1/assignments/' . rawurlencode( $id );
		$base = function_exists( 'rest_url' ) ? rest_url( $path ) : '/wp-json/' . $path;
		return $base . '?' . http_build_query( $context, '', '&', PHP_QUERY_RFC3986 );
	}
	/**
	 * Assignment problem.
	 *
	 * @param Assignment_Failure $failure Failure.
	 * @param string             $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function assignment_problem( Assignment_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Assignment_Failure::NOT_FOUND => array( 404, '배포를 찾을 수 없습니다.' ),
			Assignment_Failure::STATE_CONFLICT, Assignment_Failure::IDEMPOTENCY_CONFLICT => array( 409, '내용이 바뀌었습니다. 새로고침해 주세요.' ),
			default => array( 503, '배포를 처리하지 못했습니다.' ),
		};
		return $this->envelopes->problem( Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id ) );
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
