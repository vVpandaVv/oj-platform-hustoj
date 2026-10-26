<?php
/**
 * Student-lens roster report list.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Report\Application\Read_Student_Roster_Reports;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-lens roster report list.
 */
final class Student_Roster_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_STUDENTS = '/reports/students';
	public const ACTION_LIST    = 'report.students.list';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize student roster rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff     $permissions Permissions.
	 * @param Read_Student_Roster_Reports $reports Reports.
	 * @param Rest_Envelope_Factory       $envelopes Envelopes.
	 * @param Correlation_Id_Factory      $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Student_Roster_Reports $reports,
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
			throw new LogicException( 'Student roster report REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_STUDENTS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_list' ),
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
			$query = $this->query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST,
			array( Addon_Capabilities::VIEW_REPORTS ),
			$this->context_scope( $query )
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
			$query = $this->query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$page = $this->reports->list(
				$grant->metadata()->actor_user_id(),
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['cursor'],
				$query['limit'],
				$query['filter']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Report_Query_Failure $failure ) {
			return $this->query_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			$page['report'],
			$correlation_id,
			200,
			array(
				'next_cursor'     => $page['next_cursor'],
				'has_more'        => '' !== $page['next_cursor'],
				'limit'           => $query['limit'],
				'mutation_count'  => 0,
				'relation_scope'  => 'context_staff',
				'student_visible' => false,
			)
		);
	}

	/**
	 * Query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,cursor:string,limit:int,filter:\OJAI\Dashboard\Reporting\Report\Domain\Report_Filter}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Student roster report query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Student roster report query is invalid.' );
		}
		$allowed  = array_merge( array( 'context_type', 'context_id', 'context_revision', 'cursor', 'limit' ), Report_Filter_Query::KEYS );
		$required = array( 'context_type', 'context_id', 'context_revision' );
		if ( array_diff( array_keys( $params ), $allowed ) || array_diff( $required, array_keys( $params ) ) ) {
			throw new InvalidArgumentException( 'Student roster report query shape is invalid.' );
		}
		$type     = $params['context_type'];
		$id       = $params['context_id'];
		$revision = $params['context_revision'];
		$cursor   = $params['cursor'] ?? '';
		$limit    = $params['limit'] ?? Read_Student_Roster_Reports::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		if ( ! is_string( $type ) || ! is_string( $id ) || ! is_string( $revision ) || ! is_string( $cursor ) || ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Student roster report query values are invalid.' );
		}
		return array(
			'context_type'     => $type,
			'context_id'       => $id,
			'context_revision' => $revision,
			'cursor'           => $cursor,
			'limit'            => $limit,
			'filter'           => Report_Filter_Query::from_params( $params ),
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
			throw new InvalidArgumentException( 'Granted student roster report Context drifted.' );
		}
	}

	/**
	 * Query problem.
	 *
	 * @param Report_Query_Failure $failure Failure.
	 * @param string               $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function query_problem( Report_Query_Failure $failure, string $correlation_id ) {
		if ( in_array( $failure->reason(), array( Report_Query_Failure::SOURCE_STALE, Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED ), true ) ) {
			return $this->envelopes->problem(
				Rest_Problem::operation( 409, $failure->reason(), '보고서 원본이 변경되었습니다. 다시 불러와 주세요.', $correlation_id )
			);
		}
		return $this->envelopes->problem(
			Rest_Problem::operation( 503, $failure->reason(), '지금은 보고서를 불러올 수 없습니다.', $correlation_id )
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
