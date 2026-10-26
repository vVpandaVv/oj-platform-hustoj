<?php
/**
 * Q28 operations diagnostics REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Presentation;

use LogicException;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_System_Diagnostics;
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
 * Q28 operations diagnostics REST controller.
 */
final class Operations_Diagnostics_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE          = '/diagnostics';
	public const ACTION_READ    = 'operations.diagnostics.read';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize operations diagnostics rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Read_System_Diagnostics $diagnostics Diagnostics.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_System_Diagnostics $diagnostics,
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
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
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
			self::ACTION_READ
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
			return $this->problem( $this->correlations->create() );
		}

		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$data = $this->diagnostics->execute();
		} catch ( Throwable ) {
			return $this->problem( $correlation_id );
		}

		return $this->envelopes->success( $data, $correlation_id );
	}

	/**
	 * Problem.
	 *
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function problem( string $correlation_id ) {
		return $this->envelopes->problem(
			Rest_Problem::operation(
				503,
				'addon_status_unavailable',
				'연결 상태를 확인할 수 없습니다.',
				$correlation_id
			)
		);
	}
}
