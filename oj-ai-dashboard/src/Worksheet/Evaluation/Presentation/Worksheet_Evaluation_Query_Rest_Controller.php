<?php
/**
 * Worksheet self-evaluation Q09 and Q10 REST controller.
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
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Read_Worksheet_Evaluations;
use OJAI\Dashboard\Worksheet\Evaluation\Application\Worksheet_Evaluation_Failure;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet self-evaluation Q09 and Q10 REST controller.
 */
final class Worksheet_Evaluation_Query_Rest_Controller {
	public const REST_NAMESPACE    = 'oj-ai/v1';
	public const ROUTE_EVALUATIONS = '/worksheet-evaluations';
	public const ROUTE_EVALUATION  = '/worksheet-evaluations/(?P<evaluation_id>[0-9A-HJKMNP-TV-Z]{26})';

	public const ACTION_LIST = 'worksheet.evaluation.list';
	public const ACTION_VIEW = 'worksheet.evaluation.view';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet evaluation query rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff    $permissions Permissions.
	 * @param Read_Worksheet_Evaluations $queries Queries.
	 * @param Rest_Envelope_Factory      $envelopes Envelopes.
	 * @param Correlation_Id_Factory     $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Worksheet_Evaluations $queries,
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
			self::ROUTE_EVALUATIONS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_list' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EVALUATION,
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
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST,
			array( Addon_Capabilities::ASSESS_WORKSHEETS )
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
			$evaluation_id = $this->path_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_VIEW,
			array( Addon_Capabilities::ASSESS_WORKSHEETS ),
			null,
			new Object_Scope( Object_Scope::TYPE_EVALUATION, $evaluation_id, 'view' )
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
			$page  = $this->queries->list(
				$grant->metadata()->actor_user_id(),
				$query['state'],
				$query['worksheet_id'],
				$query['revision_id'],
				$query['cursor'],
				$query['limit'],
				$query['latest_only']
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			array( 'items' => $page['items'] ),
			$correlation_id,
			200,
			array(
				'next_cursor'    => $page['next_cursor'],
				'has_more'       => '' !== $page['next_cursor'],
				'limit'          => $query['limit'],
				'mutation_count' => 0,
				'relation_scope' => 'worksheet_owner',
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
			$result = $this->queries->detail(
				$grant->metadata()->actor_user_id(),
				$this->path_id( $request )
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 404, $correlation_id );
		} catch ( Worksheet_Evaluation_Failure $failure ) {
			return $this->evaluation_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		return $this->envelopes->success(
			$result,
			$correlation_id,
			200,
			array(
				'mutation_count'      => 0,
				'raw_provider_output' => false,
				'raw_prompt'          => false,
			)
		);
	}

	/**
	 * List query.
	 *
	 * @param object $request Request.
	 * @return array{state:string,worksheet_id:string,revision_id:string,cursor:string,limit:int,latest_only:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function list_query( object $request ): array {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation query parameters are unavailable.' );
		}
		$params = $request->get_query_params();
		if ( ! is_array( $params ) || array_is_list( $params ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation query parameters are invalid.' );
		}
		$this->allowed_keys( $params, array( 'state', 'worksheet_id', 'revision_id', 'cursor', 'limit', 'view' ) );
		$view = $params['view'] ?? 'history';
		if ( ! is_string( $view ) || ! in_array( $view, array( 'latest', 'history' ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation view is invalid.' );
		}
		$latest_only  = 'latest' === $view;
		$state        = $params['state'] ?? '';
		$worksheet_id = $params['worksheet_id'] ?? '';
		$revision_id  = $params['revision_id'] ?? '';
		$cursor       = $params['cursor'] ?? '';
		$limit        = $params['limit'] ?? Read_Worksheet_Evaluations::DEFAULT_LIMIT;
		if (
			! is_string( $state )
			|| ! is_string( $worksheet_id )
			|| ! is_string( $revision_id )
			|| ! is_string( $cursor )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation query values are invalid.' );
		}
		if ( is_string( $limit ) ) {
			if ( 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
				throw new InvalidArgumentException( 'Worksheet evaluation limit is not canonical.' );
			}
			$limit = (int) $limit;
		}
		if ( ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation limit must be an integer.' );
		}

		return compact( 'state', 'worksheet_id', 'revision_id', 'cursor', 'limit', 'latest_only' );
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
	 * Allowed keys.
	 *
	 * @param array<string,mixed> $params Params.
	 * @param array<int,string>   $allowed Allowed.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function allowed_keys( array $params, array $allowed ): void {
		if ( array_diff( array_keys( $params ), $allowed ) ) {
			throw new InvalidArgumentException( 'Worksheet evaluation query contains unknown fields.' );
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
		if ( Worksheet_Evaluation_Failure::NOT_FOUND === $failure->reason() ) {
			return $this->problem( 404, $correlation_id );
		}
		return $this->envelopes->problem(
			Rest_Problem::operation(
				503,
				$failure->reason(),
				'평가 결과를 불러오지 못했습니다.',
				$correlation_id
			)
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
