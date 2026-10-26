<?php
/**
 * Teaching Context REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Context\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Context\Port\Teaching_Context_Query_Port_Interface;
use OJAI\Dashboard\Context\Teaching_Context_Query;
use OJAI\Dashboard\Context\Teaching_Context_Query_Result;
use OJAI\Dashboard\Context\Teaching_Context_Summary;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens only the frozen A-DS08-Q01 teacher Context list route.
 */
final class Context_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_CONTEXTS = '/contexts';
	public const ACTION_LIST    = 'workspace.contexts.list';

	/**
	 * Permissions.
	 *
	 * @var Rest_Permission_Handoff
	 */
	private Rest_Permission_Handoff $permissions;
	/**
	 * Contexts.
	 *
	 * @var Teaching_Context_Query_Port_Interface
	 */
	private Teaching_Context_Query_Port_Interface $contexts;
	/**
	 * Envelopes.
	 *
	 * @var Rest_Envelope_Factory
	 */
	private Rest_Envelope_Factory $envelopes;
	/**
	 * Correlations.
	 *
	 * @var Correlation_Id_Factory
	 */
	private Correlation_Id_Factory $correlations;
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize context rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff               $permissions Permissions.
	 * @param Teaching_Context_Query_Port_Interface $contexts Contexts.
	 * @param Rest_Envelope_Factory                 $envelopes Envelopes.
	 * @param Correlation_Id_Factory                $correlations Correlations.
	 */
	public function __construct(
		Rest_Permission_Handoff $permissions,
		Teaching_Context_Query_Port_Interface $contexts,
		Rest_Envelope_Factory $envelopes,
		Correlation_Id_Factory $correlations
	) {
		$this->permissions  = $permissions;
		$this->contexts     = $contexts;
		$this->envelopes    = $envelopes;
		$this->correlations = $correlations;
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
			self::ROUTE_CONTEXTS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permissions check.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_check( object $request ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_LIST
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
			$query = $this->query_from_request(
				$request,
				$grant->metadata()->actor_user_id()
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		}

		try {
			$result = $this->contexts->query( $query );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}

		if ( ! $result->is_successful() ) {
			$status = Teaching_Context_Query_Result::REASON_CONTEXT_DENIED === $result->reason()
				? 403
				: 503;
			return $this->problem( $status, $correlation_id );
		}

		$page = $result->page();
		if ( null === $page ) {
			return $this->problem( 503, $correlation_id );
		}

		$items = array_map( array( $this, 'project_item' ), $page->items() );

		return $this->envelopes->success(
			array( 'items' => $items ),
			$correlation_id,
			200,
			array(
				'next_cursor'    => $page->next_cursor(),
				'has_more'       => '' !== $page->next_cursor(),
				'limit'          => $query->limit(),
				'excluded_count' => $page->excluded_count(),
			)
		);
	}

	/**
	 * Query from request.
	 *
	 * @param object $request Request.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return Teaching_Context_Query
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function query_from_request( object $request, int $actor_user_id ): Teaching_Context_Query {
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'WordPress REST query parameters are unavailable.' );
		}

		$params = $request->get_query_params();
		if ( ! is_array( $params ) ) {
			throw new InvalidArgumentException( 'WordPress REST query parameters are invalid.' );
		}

		$search = $params['search'] ?? '';
		$cursor = $params['cursor'] ?? '';
		$limit  = $params['limit'] ?? Teaching_Context_Query::DEFAULT_LIMIT;
		if ( ! is_string( $search ) || ! is_string( $cursor ) ) {
			throw new InvalidArgumentException( 'Context search and cursor must be strings.' );
		}
		if ( is_string( $limit ) ) {
			if ( 1 !== preg_match( '/^[1-9][0-9]{0,2}$/D', $limit ) ) {
				throw new InvalidArgumentException( 'Context limit must be a canonical integer.' );
			}
			$limit = (int) $limit;
		}
		if ( ! is_int( $limit ) ) {
			throw new InvalidArgumentException( 'Context limit must be an integer.' );
		}

		return new Teaching_Context_Query( $actor_user_id, $search, $cursor, $limit );
	}

	/**
	 * Project item.
	 *
	 * @param Teaching_Context_Summary $item Item.
	 * @return array<string,mixed>
	 */
	private function project_item( Teaching_Context_Summary $item ): array {
		$data = array(
			'type'            => $item->type(),
			'id'              => $item->id(),
			'label'           => $item->label(),
			'lifecycle_state' => $item->lifecycle_state(),
			'revision'        => $item->revision(),
			'enabled'         => $item->enabled(),
			'selectable'      => $item->is_selectable(),
		);
		if ( null !== $item->academic_year() ) {
			$data['academic_year'] = $item->academic_year();
		}
		if ( null !== $item->grade_label() ) {
			$data['grade_label'] = $item->grade_label();
		}

		return $data;
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
