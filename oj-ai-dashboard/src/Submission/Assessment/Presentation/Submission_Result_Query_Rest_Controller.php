<?php
/**
 * Frozen A-DS08-Q33 student result query.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Port\Current_Actor_Port_Interface;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Submission\Assessment\Application\Read_Own_Submission_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_Evaluation_Failure;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen A-DS08-Q33 student result query.
 */
final class Submission_Result_Query_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_RESULT   = '/me/results/(?P<release_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ACTION_VIEW    = 'submission.result.view';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission result query rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff                $permissions Permissions.
	 * @param Current_Actor_Port_Interface           $current_actor Current actor.
	 * @param Submission_Result_Query_Port_Interface $access Access.
	 * @param Read_Own_Submission_Result             $read Read.
	 * @param Rest_Envelope_Factory                  $envelopes Envelopes.
	 * @param Correlation_Id_Factory                 $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Current_Actor_Port_Interface $current_actor,
		private Submission_Result_Query_Port_Interface $access,
		private Read_Own_Submission_Result $read,
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
			throw new LogicException( 'Student result REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_RESULT,
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
			$this->empty_query( $request );
			$release_id = $this->path_id( $request ); } catch ( Throwable ) {
			return $this->problem( 422, $this->correlations->create() );
			}

			$actor_user_id = $this->current_actor->current_actor_user_id();
			if ( $actor_user_id < 1 ) {
				return $this->permissions->check(
					$request,
					Access_Policy_Request::SHELL_PUBLIC,
					self::ACTION_VIEW,
					array( Addon_Capabilities::PARTICIPATE )
				);
			}
			try {
				$snapshot = $this->access->authorization_snapshot( $release_id, $actor_user_id ); } catch ( Throwable ) {
						return $this->problem( 503, $this->correlations->create() );
				}
				if ( null === $snapshot || 'active' !== $snapshot['target_state'] || 'withdrawn' === $snapshot['assignment_state'] ) {
					return $this->problem( 404, $this->correlations->create() );
				}

				return $this->permissions->check(
					$request,
					Access_Policy_Request::SHELL_PUBLIC,
					self::ACTION_VIEW,
					array( Addon_Capabilities::PARTICIPATE ),
					new Context_Scope(
						$snapshot['context_type'],
						$snapshot['context_id'],
						Context_Scope::OPERATION_PARTICIPATE,
						$actor_user_id,
						$snapshot['context_revision']
					),
					new Object_Scope( Object_Scope::TYPE_RELEASE, $release_id, 'self_view' )
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
		$correlation = $grant->authorization()->correlation_id();
		try {
			$result = $this->read->read( $grant->metadata()->actor_user_id(), $this->path_id( $request ) );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Submission_Evaluation_Failure $failure ) {
			return $this->result_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array( 'result' => $result ),
			$correlation,
			200,
			array(),
			array(
				'self' => $this->result_url( $result['release']['id'] ),
				'page' => $this->result_page( $result['release']['id'] ),
			)
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
			throw new InvalidArgumentException( 'Student result path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['release_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Student result path ID is invalid.' );
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
			throw new InvalidArgumentException( 'Student result query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array() !== $query ) {
			throw new InvalidArgumentException( 'Student result query must be empty.' );
		}
	}

	/**
	 * Result url.
	 *
	 * @param string $release_id Release id.
	 * @return string
	 */
	private function result_url( string $release_id ): string {
		$path = 'oj-ai/v1/me/results/' . rawurlencode( $release_id );
		return function_exists( 'rest_url' ) ? rest_url( $path ) : '/wp-json/' . $path;
	}

	/**
	 * Result page.
	 *
	 * @param string $release_id Release id.
	 * @return string
	 */
	private function result_page( string $release_id ): string {
		return '/oj-addons/oj-ai-dashboard/results/' . rawurlencode( $release_id );
	}

	/**
	 * Result problem.
	 *
	 * @param Submission_Evaluation_Failure $failure Failure.
	 * @param string                        $correlation Correlation.
	 * @return \WP_Error
	 */
	private function result_problem( Submission_Evaluation_Failure $failure, string $correlation ) {
		if ( Submission_Evaluation_Failure::NOT_FOUND === $failure->reason() ) {
			return $this->envelopes->problem( Rest_Problem::operation( 404, $failure->reason(), '결과를 찾을 수 없습니다.', $correlation ) );
		}
		return $this->envelopes->problem( Rest_Problem::operation( 503, $failure->reason(), '지금은 결과를 확인할 수 없습니다.', $correlation ) );
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
