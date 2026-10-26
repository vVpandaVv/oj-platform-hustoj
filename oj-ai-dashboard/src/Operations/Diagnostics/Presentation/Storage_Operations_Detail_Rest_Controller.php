<?php
/**
 * Storage operations detail Page REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Presentation;

use LogicException;
use OJAI\Dashboard\Operations\Diagnostics\Application\Read_Storage_Operations_Detail;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only GET boundary for the separate "파일 보관 상세" Page. Gated by the
 * same manage_policies capability as the settings Page and the manual resume
 * command -- never a different capability.
 */
final class Storage_Operations_Detail_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE          = '/operations/storage/detail';
	public const ACTION_READ    = 'operations.storage.detail.read';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize storage operations detail rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff        $permissions Permissions.
	 * @param Read_Storage_Operations_Detail $detail Detail.
	 * @param Rest_Envelope_Factory          $envelopes Envelopes.
	 * @param Correlation_Id_Factory         $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Storage_Operations_Detail $detail,
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
			throw new LogicException( 'Storage operations detail REST support is unavailable.' );
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
			self::ACTION_READ,
			array( Addon_Capabilities::MANAGE_POLICIES )
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
			$data = $this->detail->execute();
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
				'storage_operations_detail_unavailable',
				'파일 보관 상세 정보를 확인할 수 없습니다.',
				$correlation_id
			)
		);
	}
}
