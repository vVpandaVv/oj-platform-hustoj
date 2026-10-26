<?php
/**
 * Purge preview rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
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
 * Purge preview rest controller.
 */
final class Purge_Preview_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_PREVIEW  = '/purge-jobs/commands/preview';
	public const ACTION_PREVIEW = 'operations.purge.preview';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize purge preview rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Preview_Purge           $previews Previews.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Preview_Purge $previews,
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
			throw new LogicException( 'Purge preview REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_PREVIEW,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'permissions_preview' ),
			)
		);
	}

	/**
	 * Permissions preview.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_preview( object $request ) {
		try {
			$this->empty_query( $request );
			$this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, Purge_Failure::INPUT_INVALID, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_PREVIEW,
			array( Addon_Capabilities::MANAGE_MIGRATIONS )
		);
	}

	/**
	 * Preview.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function preview( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, Purge_Failure::INVENTORY_UNAVAILABLE, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, Purge_Failure::INVENTORY_UNAVAILABLE, $correlation );
		}

		try {
			$input  = $this->input( $request );
			$result = $this->previews->execute(
				new Preview_Purge_Command(
					$grant->metadata()->actor_user_id(),
					$input['scope_type'],
					$idempotency->fingerprint(),
					$correlation
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Purge_Failure::INPUT_INVALID, $correlation );
		} catch ( Purge_Failure $failure ) {
			return $this->problem( 503, $failure->reason(), $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Purge_Failure::INVENTORY_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'purge_preview' => array_merge(
					array( 'state' => 'previewed' ),
					$result->preview(),
					array(
						'preview_token'  => $result->preview_token(),
						'issued_at_utc'  => $result->issued_at_utc(),
						'expires_at_utc' => $result->expires_at_utc(),
					)
				),
			),
			$correlation,
			200,
			array(
				'mutation_count'        => $result->mutation_count(),
				'ledger_mutation_count' => $result->ledger_mutation_count(),
				'platform_write_count'  => $result->platform_write_count(),
				'role_mutation_count'   => $result->role_mutation_count(),
				'file_mutation_count'   => $result->file_mutation_count(),
				'option_mutation_count' => $result->option_mutation_count(),
				'idempotency_bound'     => true,
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{scope_type:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Purge preview body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) || array( 'scope' ) !== array_keys( $input ) ) {
			throw new InvalidArgumentException( 'Purge preview body is invalid.' );
		}
		$scope = $input['scope'];
		if ( ! is_array( $scope ) || array_is_list( $scope ) || array( 'type' ) !== array_keys( $scope ) ) {
			throw new InvalidArgumentException( 'Purge preview scope is invalid.' );
		}
		if ( Preview_Purge_Command::SCOPE_ADDON !== ( $scope['type'] ?? null ) ) {
			throw new InvalidArgumentException( 'Purge preview supports only the complete add-on scope.' );
		}
		return array( 'scope_type' => Preview_Purge_Command::SCOPE_ADDON );
	}

	/**
	 * Empty query.
	 *
	 * @param object $request Request.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function empty_query( object $request ): void {
		if ( ! method_exists( $request, 'get_query_params' ) || array() !== $request->get_query_params() ) {
			throw new InvalidArgumentException( 'Purge preview query must be empty.' );
		}
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $code Code.
	 * @param string $correlation Correlation.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $code, string $correlation ) {
		$message = 422 === $status
			? '정리할 데이터 범위를 확인해 주세요.'
			: '지금은 정리할 데이터를 확인할 수 없습니다.';
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
