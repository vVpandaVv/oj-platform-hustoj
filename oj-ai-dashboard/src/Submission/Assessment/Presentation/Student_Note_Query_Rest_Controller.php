<?php
/**
 * Teacher-only Q18 and Q19 student-note REST controller.
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
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Student_Notes;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teacher-only Q18 and Q19 student-note REST controller.
 */
final class Student_Note_Query_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_NOTES    = '/student-notes';
	public const ROUTE_NOTE     = '/student-notes/(?P<note_id>[0-9A-HJKMNP-TV-Z]{26})';

	public const ACTION_LIST = 'student_note.list';
	public const ACTION_VIEW = 'student_note.view';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize student note query rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Read_Student_Notes      $queries Queries.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Student_Notes $queries,
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
			self::ROUTE_NOTES,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_list' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_NOTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
			)
		);
	}

	/**
	 * Permissions list.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_list( object $request ) {
		try {
			$query   = $this->list_query( $request );
			$context = $this->context_scope( $query );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST,
			array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
			$context
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
			$query   = $this->detail_query( $request );
			$note_id = $this->path_id( $request );
			$context = $this->context_scope( $query );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $this->correlations->create() );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_VIEW,
			array( Addon_Capabilities::MANAGE_STUDENT_NOTES ),
			$context,
			new Object_Scope( Object_Scope::TYPE_STUDENT_NOTE, $note_id, 'view' )
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
			$query = $this->list_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$page = $this->queries->list(
				$grant->metadata()->actor_user_id(),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['assignment_id'],
				$query['state'],
				$query['report_status'],
				$query['cursor'],
				$query['limit']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->query_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'items' => $page['items'] ),
			$correlation_id,
			200,
			array(
				'next_cursor'           => $page['next_cursor'],
				'has_more'              => '' !== $page['next_cursor'],
				'limit'                 => $query['limit'],
				'mutation_count'        => 0,
				'relation_scope'        => 'context_staff',
				'student_visible'       => false,
				'report_auto_inclusion' => false,
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
			$query = $this->detail_query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$result = $this->queries->detail(
				$this->path_id( $request ),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->query_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			$result,
			$correlation_id,
			200,
			array(
				'mutation_count'        => 0,
				'relation_scope'        => 'context_staff',
				'student_visible'       => false,
				'report_auto_inclusion' => false,
				'raw_provider_output'   => false,
				'raw_prompt'            => false,
			)
		);
	}

	/**
	 * List query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,assignment_id:string,state:string,report_status:string,cursor:string,limit:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->allowed_keys(
			$params,
			array( 'context_type', 'context_id', 'context_revision', 'assignment_id', 'state', 'report_status', 'cursor', 'limit' ),
			array( 'context_type', 'context_id', 'context_revision' )
		);
		$type          = $params['context_type'];
		$id            = $params['context_id'];
		$revision      = $params['context_revision'];
		$assignment    = $params['assignment_id'] ?? '';
		$state         = $params['state'] ?? '';
		$report_status = $params['report_status'] ?? '';
		$cursor        = $params['cursor'] ?? '';
		$limit         = $params['limit'] ?? Read_Student_Notes::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if (
			! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision )
			|| ! is_string( $assignment ) || ! is_string( $state ) || ! is_string( $report_status )
			|| ! is_string( $cursor ) || ! is_int( $limit )
		) {
			throw new InvalidArgumentException( 'Student-note list query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'assignment_id'    => $assignment,
			'state'            => $state,
			'report_status'    => $report_status,
			'cursor'           => $cursor,
			'limit'            => $limit,
		);
	}

	/**
	 * Detail query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function detail_query( object $request ): array {
		$params = $this->query_params( $request );
		$this->allowed_keys(
			$params,
			array( 'context_type', 'context_id', 'context_revision' ),
			array( 'context_type', 'context_id', 'context_revision' )
		);
		$type     = $params['context_type'];
		$id       = $params['context_id'];
		$revision = $params['context_revision'];
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) ) {
			throw new InvalidArgumentException( 'Student-note detail query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
		);
	}

	/**
	 * Context scope.
	 *
	 * @param array<string,mixed> $query Query.
	 * @return Context_Scope
	 */
	private function context_scope( array $query ): Context_Scope {
		return new Context_Scope(
			(string) $query['context_type'],
			(string) $query['context_id'],
			Context_Scope::OPERATION_REPORT,
			null,
			(string) $query['context_revision']
		);
	}

	/**
	 * Reject invalid granted context.
	 *
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param array<string,mixed>               $query Query.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_granted_context( ?Context_Authorization_Result $context, array $query ): void {
		if (
			null === $context || ! $context->is_allowed()
			|| $context->type() !== $query['context_type']
			|| $context->id() !== $query['context_id']
			|| $context->revision() !== $query['context_revision']
		) {
			throw new InvalidArgumentException( 'Granted student-note Context drifted.' );
		}
	}

	/**
	 * Query params.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_params( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Student-note query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Student-note query is invalid.' );
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
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new LogicException( 'Student-note path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['note_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new LogicException( 'Student-note path ID is invalid.' );
		}
		return $value;
	}

	/**
	 * Allowed keys.
	 *
	 * @param array<string,mixed> $params Params.
	 * @param array<int,string>   $allowed Allowed.
	 * @param array<int,string>   $required Required.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function allowed_keys( array $params, array $allowed, array $required ): void {
		if ( array_diff( array_keys( $params ), $allowed ) || array_diff( $required, array_keys( $params ) ) ) {
			throw new InvalidArgumentException( 'Student-note query contains unknown or missing fields.' );
		}
	}

	/**
	 * Query problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function query_problem( Submission_Evaluation_Failure $failure, string $correlation_id ) {
		if ( Submission_Evaluation_Failure::NOT_FOUND === $failure->reason() ) {
			return $this->problem( 404, $correlation_id );
		}
		return $this->envelopes->problem(
			Rest_Problem::operation( 503, $failure->reason(), '학생 특기사항을 불러오지 못했습니다.', $correlation_id )
		);
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
