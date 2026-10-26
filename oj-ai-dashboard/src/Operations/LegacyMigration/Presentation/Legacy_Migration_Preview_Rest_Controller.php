<?php
/**
 * Legacy migration preview rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Preview_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Preview_Legacy_Migration_Command;
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
 * Legacy migration preview rest controller.
 */
final class Legacy_Migration_Preview_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_PREVIEW  = '/migration-runs/commands/preview';
	public const ACTION_PREVIEW = 'operations.legacy_migration.preview';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize legacy migration preview rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff  $permissions Permissions.
	 * @param Preview_Legacy_Migration $previews Previews.
	 * @param Rest_Envelope_Factory    $envelopes Envelopes.
	 * @param Correlation_Id_Factory   $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Preview_Legacy_Migration $previews,
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
			throw new LogicException( 'Legacy migration preview REST support is unavailable.' );
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
			return $this->problem( 422, Legacy_Migration_Failure::INPUT_INVALID, $this->correlations->create() );
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
			return $this->problem( 503, Legacy_Migration_Failure::SOURCE_UNAVAILABLE, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, Legacy_Migration_Failure::SOURCE_UNAVAILABLE, $correlation );
		}

		try {
			$input  = $this->input( $request );
			$result = $this->previews->execute(
				new Preview_Legacy_Migration_Command(
					$grant->metadata()->actor_user_id(),
					$input['source_profile'],
					$idempotency->fingerprint(),
					$correlation
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Legacy_Migration_Failure::INPUT_INVALID, $correlation );
		} catch ( Legacy_Migration_Failure $failure ) {
			return $this->migration_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Legacy_Migration_Failure::SOURCE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'migration_preview' => array_merge(
					array( 'state' => 'previewed' ),
					$result->plan(),
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
				'idempotency_bound'     => true,
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{source_profile:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Legacy migration preview body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) || array( 'source' ) !== array_keys( $input ) ) {
			throw new InvalidArgumentException( 'Legacy migration preview body is invalid.' );
		}
		$source = $input['source'];
		if ( ! is_array( $source ) || array_is_list( $source ) || array( 'profile' ) !== array_keys( $source ) ) {
			throw new InvalidArgumentException( 'Legacy migration preview source is invalid.' );
		}
		$profile = $source['profile'];
		if ( ! is_string( $profile ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $profile ) ) {
			throw new InvalidArgumentException( 'Legacy migration preview profile is invalid.' );
		}
		return array( 'source_profile' => $profile );
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
			throw new InvalidArgumentException( 'Legacy migration preview query must be empty.' );
		}
	}

	/**
	 * Migration problem.
	 *
	 * @param Legacy_Migration_Failure $failure Failure.
	 * @param string                   $correlation Correlation.
	 * @return object
	 */
	private function migration_problem( Legacy_Migration_Failure $failure, string $correlation ): object {
		$status = match ( $failure->reason() ) {
			Legacy_Migration_Failure::SOURCE_COLLISION,
			Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH => 409,
			Legacy_Migration_Failure::INPUT_INVALID,
			Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED => 422,
			Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED => 413,
			default => 503,
		};
		return $this->problem( $status, $failure->reason(), $correlation );
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
		$message = match ( $status ) {
			409 => '기존 데이터 원본과 전환 절차를 다시 확인해 주세요.',
			413 => '기존 데이터 크기를 나누어 다시 확인해 주세요.',
			422 => '가져올 데이터 종류를 확인해 주세요.',
			default => '지금은 기존 데이터를 확인할 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
