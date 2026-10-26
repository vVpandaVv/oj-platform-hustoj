<?php
/**
 * Frozen A-DS08-Q21 worksheet report drill-down.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Report\Application\Read_Worksheet_Report_Detail;
use OJAI\Dashboard\Reporting\Report\Application\Report_Query_Failure;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-Q21 worksheet report drill-down.
 */
final class Worksheet_Report_Detail_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_DETAIL   = '/reports/worksheets/(?P<worksheet_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ACTION_DETAIL  = 'report.worksheet.detail';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet report detail rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff      $permissions Permissions.
	 * @param Read_Worksheet_Report_Detail $reports Reports.
	 * @param Rest_Envelope_Factory        $envelopes Envelopes.
	 * @param Correlation_Id_Factory       $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Worksheet_Report_Detail $reports,
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
			throw new LogicException( 'Worksheet report detail REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_DETAIL,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permissions_show' ),
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
			$this->worksheet_id( $request );
			$query = $this->query( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_DETAIL,
			array( Addon_Capabilities::VIEW_REPORTS ),
			$this->context_scope( $query )
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
			$worksheet_id = $this->worksheet_id( $request );
			$query        = $this->query( $request );
			$this->assert_granted_context( $grant->authorization()->policy_decision()->context(), $query );
			$page = $this->reports->detail(
				$grant->metadata()->actor_user_id(),
				$worksheet_id,
				$query['publication_id'],
				$query['publication_hash'],
				$query['context_type'],
				$query['context_id'],
				$query['context_revision'],
				$query['assignment_id'],
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
				'next_cursor'           => $page['next_cursor'],
				'has_more'              => '' !== $page['next_cursor'],
				'limit'                 => $query['limit'],
				'level'                 => $page['report']['level'],
				'mutation_count'        => 0,
				'relation_scope'        => 'context_staff',
				'student_visible'       => false,
				'contains_student_rows' => 'students' === $page['report']['level'],
			)
		);
	}

	/**
	 * Query.
	 *
	 * @param object $request Request.
	 * @return array{context_type:string,context_id:string,context_revision:string,publication_id:string,publication_hash:string,assignment_id:?string,cursor:string,limit:int,filter:\OJAI\Dashboard\Reporting\Report\Domain\Report_Filter}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail query is unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail query is invalid.' );
		}
		$allowed  = array_merge( array( 'context_type', 'context_id', 'context_revision', 'publication_id', 'publication_hash', 'assignment_id', 'cursor', 'limit' ), Report_Filter_Query::KEYS );
		$required = array( 'context_type', 'context_id', 'context_revision', 'publication_id', 'publication_hash' );
		if ( array_diff( array_keys( $params ), $allowed ) || array_diff( $required, array_keys( $params ) ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail query shape is invalid.' );
		}
		$limit = $params['limit'] ?? Read_Worksheet_Report_Detail::DEFAULT_LIMIT;
		if ( is_string( $limit ) && 1 === preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
			$limit = (int) $limit;
		}
		$assignment_id = $params['assignment_id'] ?? null;
		$cursor        = $params['cursor'] ?? '';
		foreach ( array( 'context_type', 'context_id', 'context_revision', 'publication_id', 'publication_hash' ) as $key ) {
			if ( ! is_string( $params[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Worksheet report detail query value is invalid.' );
			}
		}
		if ( ! is_string( $cursor ) || ! is_int( $limit ) || ( null !== $assignment_id && ! is_string( $assignment_id ) ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail page is invalid.' );
		}
		if ( ! Public_Id::is_valid( $params['publication_id'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $params['publication_hash'] ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail publication is invalid.' );
		}
		if ( null !== $assignment_id && ! Public_Id::is_valid( $assignment_id ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail assignment is invalid.' );
		}
		return array(
			'context_type'     => $params['context_type'],
			'context_id'       => $params['context_id'],
			'context_revision' => $params['context_revision'],
			'publication_id'   => $params['publication_id'],
			'publication_hash' => $params['publication_hash'],
			'assignment_id'    => $assignment_id,
			'cursor'           => $cursor,
			'limit'            => $limit,
			'filter'           => Report_Filter_Query::from_params( $params ),
		);
	}

	/**
	 * Worksheet id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function worksheet_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['worksheet_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet report detail path is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Granted worksheet report detail Context drifted.' );
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
		if ( Report_Query_Failure::NOT_FOUND === $failure->reason() ) {
			return $this->envelopes->problem(
				Rest_Problem::operation( 404, $failure->reason(), '보고서 상세를 찾을 수 없습니다.', $correlation_id )
			);
		}
		if ( in_array( $failure->reason(), array( Report_Query_Failure::SOURCE_STALE, Report_Query_Failure::SOURCE_SCHEMA_UNSUPPORTED ), true ) ) {
			return $this->envelopes->problem(
				Rest_Problem::operation( 409, $failure->reason(), '보고서 원본이 변경되었습니다. 목록에서 다시 열어 주세요.', $correlation_id )
			);
		}
		return $this->envelopes->problem(
			Rest_Problem::operation( 503, $failure->reason(), '지금은 보고서 상세를 불러올 수 없습니다.', $correlation_id )
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
