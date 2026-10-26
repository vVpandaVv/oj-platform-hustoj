<?php
/**
 * Frozen A-DS08-Q22 immutable report snapshot REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\Report\Application\Read_Report_Snapshot;
use OJAI\Dashboard\Reporting\Report\Application\Report_Snapshot_Query_Failure;
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
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-Q22 immutable report snapshot REST boundary.
 */
final class Report_Snapshot_Query_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_SHOW     = '/report-snapshots/(?P<snapshot_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ACTION_SHOW    = Read_Report_Snapshot::ACTION_READ;

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize report snapshot query rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Read_Report_Snapshot    $snapshots Snapshots.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Report_Snapshot $snapshots,
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
			throw new LogicException( 'Report snapshot query REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_SHOW,
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
		$correlation = $this->correlations->create();
		try {
			$this->empty_query( $request );
			$snapshot_id = $this->snapshot_id( $request );
			$scope       = $this->snapshots->scope( $snapshot_id );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			return $this->snapshot_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_SHOW,
			array( Addon_Capabilities::VIEW_REPORTS ),
			new Context_Scope( $scope->context_type(), $scope->context_id(), Context_Scope::OPERATION_REPORT ),
			new Object_Scope( Object_Scope::TYPE_REPORT, $snapshot_id, 'view' )
		);
	}

	/**
	 * Show.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function show( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$decision      = $authorization->policy_decision();
		$context       = $decision->context();
		$object        = $decision->object();
		if (
			! $context instanceof Context_Authorization_Result || ! $context->is_allowed()
			|| ! $object instanceof Object_Authorization_Result || ! $object->is_allowed()
		) {
			return $this->problem( 503, $correlation );
		}

		try {
			$this->empty_query( $request );
			$result = $this->snapshots->read(
				$grant->metadata()->actor_user_id(),
				$this->snapshot_id( $request ),
				$context
			);
			if (
				! hash_equals( $result->snapshot_hash(), (string) $object->current_revision() )
				|| 'ready' !== $object->current_state()
			) {
				throw Report_Snapshot_Query_Failure::because( Report_Snapshot_Query_Failure::STORED_DATA_INVALID );
			}
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Report_Snapshot_Query_Failure $failure ) {
			return $this->snapshot_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array( 'report_snapshot' => $result->payload() ),
			$correlation,
			200,
			array(
				'mutation_count'          => 0,
				'relation_scope'          => (string) $object->actor_relation(),
				'student_visible'         => false,
				'snapshot_immutable'      => true,
				'authorized_source_count' => $result->authorized_source_count(),
			)
		);
	}

	/**
	 * Snapshot id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function snapshot_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Report snapshot path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['snapshot_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Report snapshot path is invalid.' );
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
		if ( ! method_exists( $request, 'get_query_params' ) ) {
			throw new InvalidArgumentException( 'Report snapshot query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array() !== $query ) {
			throw new InvalidArgumentException( 'Report snapshot query must be empty.' );
		}
	}

	/**
	 * Snapshot problem.
	 *
	 * @param Report_Snapshot_Query_Failure $failure Failure.
	 * @param string                        $correlation Correlation.
	 * @return \WP_Error
	 */
	private function snapshot_problem( Report_Snapshot_Query_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Report_Snapshot_Query_Failure::NOT_FOUND,
			Report_Snapshot_Query_Failure::SOURCE_ACCESS_DENIED => 404,
			Report_Snapshot_Query_Failure::PROFILE_UNSUPPORTED => 409,
			default => 503,
		};
		$message = match ( $status ) {
			404 => '보고서 스냅샷을 찾을 수 없습니다.',
			409 => '이 보고서 스냅샷 형식은 현재 지원되지 않습니다.',
			default => '지금은 보고서 스냅샷을 불러올 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $failure->reason(), $message, $correlation ) );
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
