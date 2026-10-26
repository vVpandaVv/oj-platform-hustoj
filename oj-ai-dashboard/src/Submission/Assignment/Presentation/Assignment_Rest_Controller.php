<?php
/**
 * Assignment REST controller.
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
use OJAI\Dashboard\Submission\Assignment\Application\Add_Assignment_Targets;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment;
use OJAI\Dashboard\Submission\Assignment\Application\Create_Assignment_Command;
use OJAI\Dashboard\Submission\Assignment\Application\Read_Assignments;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens frozen Q11, Q12, and C13 without accepting caller-supplied learners.
 */
final class Assignment_Rest_Controller {
	public const REST_NAMESPACE    = 'oj-ai/v1';
	public const ROUTE_ASSIGNMENTS = '/assignments';
	public const ROUTE_ASSIGNMENT  = '/assignments/(?P<assignment_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ACTION_LIST       = 'assignment.list';
	public const ACTION_VIEW       = 'assignment.view';
	public const ACTION_CREATE     = 'assignment.create';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;
	/**
	 * Initialize assignment rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff     $permissions Permissions.
	 * @param Read_Assignments            $read_assignments Read assignments.
	 * @param Create_Assignment           $create_assignment Create assignment.
	 * @param Canonical_Json              $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory       $envelopes Envelopes.
	 * @param Correlation_Id_Factory      $correlations Correlations.
	 * @param Add_Assignment_Targets|null $targets Late-joiner commands.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Assignments $read_assignments,
		private Create_Assignment $create_assignment,
		private Canonical_Json $canonical,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private ?Add_Assignment_Targets $targets = null
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
			self::ROUTE_ASSIGNMENTS,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'permissions_list' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'permissions_create' ),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ASSIGNMENT,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
			)
		);
		if ( null !== $this->targets ) {
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_ASSIGNMENT . '/target-candidates',
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'target_candidates' ),
					'permission_callback' => array( $this, 'permissions_show' ),
				)
			);
			register_rest_route(
				self::REST_NAMESPACE,
				self::ROUTE_ASSIGNMENT . '/commands/add-targets',
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_targets' ),
					'permission_callback' => array( $this, 'permissions_add_targets' ),
				)
			);
		}
	}

	/**
	 * Require teacher assignment authority, nonce and an idempotency key.
	 *
	 * @param object $request REST request.
	 * @return true|\WP_Error
	 */
	public function permissions_add_targets( object $request ) {
		try {
			$query = $this->context_query( $request );
			$id    = $this->path_id( $request );
			Add_Assignment_Targets::input( $request->get_json_params() );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			'assignment.add_targets',
			array( Addon_Capabilities::ASSIGN_WORKSHEETS ),
			new Context_Scope( $query['context_type'], $query['context_id'], Context_Scope::OPERATION_ASSIGN, null, $query['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $id, 'add_targets' )
		);
	}

	/**
	 * Preview current eligible group members without changing the assignment.
	 *
	 * @param object $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function target_candidates( object $request ) {
		return $this->targets_response( $request, false );
	}

	/**
	 * Append the selected current group members.
	 *
	 * @param object $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_targets( object $request ) {
		return $this->targets_response( $request, true );
	}

	/**
	 * Consume the permission grant exactly once for both target operations.
	 *
	 * @param object $request REST request.
	 * @param bool   $write Whether to commit additions.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function targets_response( object $request, bool $write ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant || null === $this->targets ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation = $grant->authorization()->correlation_id();
		try {
			$query = $this->context_query( $request );
			$id    = $this->path_id( $request );
			$actor = $grant->metadata()->actor_user_id();
			if ( $write ) {
				$key = $grant->authorization()->idempotency_key();
				if ( null === $key ) {
					return $this->problem( 422, $correlation );
				}
				$data = $this->targets->execute( $actor, $id, $query, Add_Assignment_Targets::input( $request->get_json_params() ), $key->fingerprint(), $correlation );
			} else {
				$data = $this->targets->preview( $actor, $id, $query );
			}
			return $this->envelopes->success( $data, $correlation );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}
	}

	/**
	 * Permissions list.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_list( object $request ) {
		try {
			$query = $this->context_query( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				self::ACTION_LIST,
				array( Addon_Capabilities::ASSIGN_WORKSHEETS ),
				new Context_Scope( $query['context_type'], $query['context_id'], Context_Scope::OPERATION_ASSIGN, null, $query['context_revision'] )
			);
	}

	/**
	 * Permissions create.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_create( object $request ) {
		try {
			$input = $this->create_input( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				self::ACTION_CREATE,
				array( Addon_Capabilities::ASSIGN_WORKSHEETS ),
				new Context_Scope(
					$input['context_type'],
					$input['context_id'],
					Context_Scope::OPERATION_ASSIGN,
					null,
					$input['expected_context_revision']
				)
			);
	}

	/**
	 * Permissions show.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_show( object $request ) {
		try {
			$query         = $this->context_query( $request );
			$assignment_id = $this->path_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_VIEW,
			array( Addon_Capabilities::ASSIGN_WORKSHEETS ),
			new Context_Scope( $query['context_type'], $query['context_id'], Context_Scope::OPERATION_ASSIGN, null, $query['context_revision'] ),
			new Object_Scope( Object_Scope::TYPE_ASSIGNMENT, $assignment_id, 'view' )
		);
	}

	/**
	 * Index.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function index( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query = $this->context_query( $request );
			$page  = $this->read_assignments->list( $query['context_type'], $query['context_id'], $query['context_revision'], $query['cursor'], $query['limit'] );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}
			return $this->envelopes->success(
				array( 'items' => $page['items'] ),
				$correlation_id,
				200,
				array(
					'next_cursor' => $page['next_cursor'],
					'has_more'    => '' !== $page['next_cursor'],
					'limit'       => $query['limit'],
				)
			);
	}

	/**
	 * Show.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function show( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$query  = $this->context_query( $request );
			$result = $this->read_assignments->detail( $this->path_id( $request ), $query['context_type'], $query['context_id'], $query['context_revision'], $query['cursor'], $query['limit'] );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Assignment_Failure $failure ) {
			return $this->assignment_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation_id );
		}
			return $this->envelopes->success(
				array(
					'assignment' => $result['assignment'],
					'targets'    => $result['targets'],
				),
				$correlation_id,
				200,
				array(
					'next_cursor' => $result['next_cursor'],
					'has_more'    => '' !== $result['next_cursor'],
					'limit'       => $query['limit'],
				)
			);
	}

	/**
	 * Create assignment rest controller.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create( object $request ) {
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
			$input  = $this->create_input( $request );
			$result = $this->create_assignment->execute(
				new Create_Assignment_Command(
					$grant->metadata()->actor_user_id(),
					$input['command_id'],
					$input['worksheet_publication_id'],
					$input['expected_publication_hash'],
					$input['context_type'],
					$input['context_id'],
					$input['expected_context_revision'],
					$input['schedule'],
					$input['resubmission_policy'],
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
				201,
				array(
					'idempotent_replay' => $result->replayed(),
					'mutation_count'    => $result->replayed() ? 0 : 1,
				),
				array( 'self' => $this->assignment_url( $assignment['id'], $assignment['context'] ) ),
				array( self::ACTION_VIEW )
			);
	}

	/**
	 * Context query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Assignment query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Assignment query is invalid.' );
		}
		$this->allowed_keys( $params, array( 'context_id', 'context_revision', 'context_type', 'cursor', 'limit' ), array( 'context_id', 'context_revision', 'context_type' ) );
		$type     = $params['context_type'] ?? null;
		$id       = $params['context_id'] ?? null;
		$revision = $params['context_revision'] ?? null;
		$cursor   = $params['cursor'] ?? '';
		$limit    = $params['limit'] ?? Read_Assignments::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) || ! is_string( $cursor ) || ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Assignment query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'cursor'           => $cursor,
			'limit'            => $limit,
		);
	}

	/**
	 * Create input.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function create_input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'CreateAssignment body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'CreateAssignment body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'CreateAssignment sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_context_revision', 'expected_publication_hash' ) );
		$this->exact_keys( $payload, array( 'context', 'resubmission_policy', 'schedule', 'worksheet_publication_id' ) );
		$context      = $payload['context'] ?? null;
		$schedule     = $payload['schedule'] ?? null;
		$resubmission = $payload['resubmission_policy'] ?? null;
		if ( ! is_array( $context ) || array_is_list( $context ) || ! is_array( $schedule ) || array_is_list( $schedule ) || ! is_array( $resubmission ) || array_is_list( $resubmission ) ) {
			throw new InvalidArgumentException( 'CreateAssignment payload is invalid.' );
		}
		$this->exact_keys( $context, array( 'id', 'type' ) );
		$this->exact_keys( $schedule, array( 'auto_submit_enabled', 'closes_at_utc', 'due_at_utc', 'opens_at_utc' ) );
		$this->exact_keys( $resubmission, array( 'copy_previous_answers', 'max_attempts', 'reopen_until_utc' ) );
		$result = array(
			'command_id'                => $input['command_id'] ?? null,
			'expected_context_revision' => $expected['expected_context_revision'] ?? null,
			'expected_publication_hash' => $expected['expected_publication_hash'] ?? null,
			'worksheet_publication_id'  => $payload['worksheet_publication_id'] ?? null,
			'context_type'              => $context['type'] ?? null,
			'context_id'                => $context['id'] ?? null,
			'schedule'                  => $schedule,
			'resubmission_policy'       => $resubmission,
		);
		foreach ( array( 'command_id', 'expected_context_revision', 'expected_publication_hash', 'worksheet_publication_id', 'context_type', 'context_id' ) as $field ) {
			if ( ! is_string( $result[ $field ] ) ) {
				throw new InvalidArgumentException( 'CreateAssignment value is invalid.' );
			}
		}
		new Public_Id( $result['worksheet_publication_id'] );
		return $result;
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
			throw new InvalidArgumentException( 'Assignment path is unavailable.' );
		}
		$params = $request->get_url_params();
		$id     = is_array( $params ) ? ( $params['assignment_id'] ?? null ) : null;
		if ( ! is_string( $id ) ) {
			throw new InvalidArgumentException( 'Assignment path is invalid.' );
		}
		new Public_Id( $id );
		return $id;
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
			throw new InvalidArgumentException( 'Assignment object keys are invalid.' );
		}
	}
	/**
	 * Allowed keys.
	 *
	 * @param array<array-key,mixed> $value Value.
	 * @param array<int,string>      $allowed Allowed.
	 * @param array<int,string>      $required Required.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function allowed_keys( array $value, array $allowed, array $required ): void {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Assignment query key is invalid.' );
			}
		}
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $value ) ) {
				throw new InvalidArgumentException( 'Assignment query key is missing.' );
			}
		}
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
		return $base . '?' . http_build_query(
			array(
				'context_type'     => $context['type'],
				'context_id'       => $context['id'],
				'context_revision' => $context['revision'],
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
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
			Assignment_Failure::ROSTER_DENIED => array( 403, '배포 대상을 불러올 수 없습니다.' ),
			Assignment_Failure::ROSTER_EMPTY => array( 422, '배포할 학생이 없습니다.' ),
			Assignment_Failure::TARGETS_CLOSED => array( 409, '학생을 추가하려면 배포를 다시 열고 제출 기한을 확인해 주세요.' ),
			Assignment_Failure::TARGET_OVERLAP => array( 409, '같은 발행본을 이미 배포받은 학생이 있습니다. 기존 배포나 대상 그룹을 확인해 주세요.' ),
			Assignment_Failure::ROSTER_TOO_LARGE => array( 422, '한 번에 배포할 수 있는 학생 수를 넘었습니다.' ),
			Assignment_Failure::PUBLICATION_CONFLICT, Assignment_Failure::STATE_CONFLICT, Assignment_Failure::IDEMPOTENCY_CONFLICT => array( 409, '내용이 바뀌었습니다. 새로고침해 주세요.' ),
			Assignment_Failure::NOT_FOUND => array( 404, '배포를 찾을 수 없습니다.' ),
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
