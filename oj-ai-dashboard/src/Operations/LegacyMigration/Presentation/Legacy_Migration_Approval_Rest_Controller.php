<?php
/**
 * Legacy migration approval rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Approve_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration approval rest controller.
 */
final class Legacy_Migration_Approval_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_APPROVE  = '/migration-runs/commands/approve';
	public const ACTION_APPROVE = 'operations.legacy_migration.approve';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize legacy migration approval rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff  $permissions Permissions.
	 * @param Approve_Legacy_Migration $approvals Approvals.
	 * @param Rest_Envelope_Factory    $envelopes Envelopes.
	 * @param Correlation_Id_Factory   $correlations Correlations.
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Approve_Legacy_Migration $approvals,
		private Rest_Envelope_Factory $envelopes,
		private Correlation_Id_Factory $correlations,
		private Canonical_Json $canonical
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
			throw new LogicException( 'Legacy migration approval REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_APPROVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'permissions_approve' ),
			)
		);
	}

	/**
	 * Permissions approve.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_approve( object $request ) {
		try {
			$this->empty_query( $request );
			$this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, Legacy_Migration_Failure::APPROVAL_INVALID, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_APPROVE,
			array( Addon_Capabilities::MANAGE_MIGRATIONS )
		);
	}

	/**
	 * Approve.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		try {
			$input  = $this->input( $request );
			$result = $this->approvals->execute(
				new Approve_Legacy_Migration_Command(
					$grant->metadata()->actor_user_id(),
					$input['source_profile'],
					$input['preview_token'],
					$idempotency->fingerprint(),
					$correlation,
					$this->canonical,
					$input['mapping_decisions']
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Legacy_Migration_Failure::APPROVAL_INVALID, $correlation );
		} catch ( Legacy_Migration_Failure $failure ) {
			return $this->migration_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'migration_run' => array(
					'id'              => $result->run_id(),
					'state'           => $result->state(),
					'source'          => array(
						'profile'     => $result->source_profile(),
						'version'     => $result->source_version(),
						'fingerprint' => $result->source_fingerprint(),
					),
					'plan_hash'       => $result->plan_hash(),
					'preview_hash'    => $result->preview_hash(),
					'item_total'      => $result->item_total(),
					'approved_at_utc' => $result->approved_at_utc(),
				),
			),
			$correlation,
			$result->replayed() ? 200 : 201,
			array(
				'replayed'                => $result->replayed(),
				'mutation_count'          => $result->mutation_count(),
				'ledger_mutation_count'   => $result->ledger_mutation_count(),
				'business_mutation_count' => $result->business_mutation_count(),
				'platform_write_count'    => $result->platform_write_count(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{source_profile:string,preview_token:string,mapping_decisions:array<int,array<string,mixed>>}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Legacy migration approval body is unavailable.' );
		}
		$input = $request->get_json_params();
		$keys  = is_array( $input ) ? array_keys( $input ) : array();
		if (
			! is_array( $input )
			|| array_is_list( $input )
			|| ! in_array( $keys, array( array( 'source', 'preview_token' ), array( 'source', 'preview_token', 'mapping_decisions' ) ), true )
		) {
			throw new InvalidArgumentException( 'Legacy migration approval body is invalid.' );
		}
		$source = $input['source'];
		if ( ! is_array( $source ) || array_is_list( $source ) || array( 'profile' ) !== array_keys( $source ) ) {
			throw new InvalidArgumentException( 'Legacy migration approval source is invalid.' );
		}
		$profile           = $source['profile'];
		$token             = $input['preview_token'];
		$mapping_decisions = $input['mapping_decisions'] ?? array();
		if (
			! is_string( $profile )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $profile )
			|| ! is_string( $token )
			|| '' === $token
			|| strlen( $token ) > 4096
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $token )
			|| ! is_array( $mapping_decisions ) || ! array_is_list( $mapping_decisions ) || count( $mapping_decisions ) > 50000
		) {
			throw new InvalidArgumentException( 'Legacy migration approval input is invalid.' );
		}

		return array(
			'source_profile'    => $profile,
			'preview_token'     => $token,
			'mapping_decisions' => $mapping_decisions,
		);
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
			throw new InvalidArgumentException( 'Legacy migration approval query must be empty.' );
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
			Legacy_Migration_Failure::MAPPING_REQUIRED,
			Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH,
			Legacy_Migration_Failure::SOURCE_STALE,
			Legacy_Migration_Failure::TOKEN_EXPIRED,
			Legacy_Migration_Failure::TOKEN_SCOPE_MISMATCH,
			Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT => 409,
			Legacy_Migration_Failure::APPROVAL_INVALID,
			Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED,
			Legacy_Migration_Failure::TOKEN_INVALID => 422,
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
			409 => '이전 내용을 다시 확인해 주세요.',
			413 => '기존 데이터 크기를 나누어 다시 확인해 주세요.',
			422 => '승인할 이전 내용을 확인해 주세요.',
			default => '지금은 이전 계획을 승인할 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
