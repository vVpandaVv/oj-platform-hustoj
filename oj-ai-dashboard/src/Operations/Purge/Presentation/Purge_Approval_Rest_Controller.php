<?php
/**
 * Purge approval rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge;
use OJAI\Dashboard\Operations\Purge\Application\Approve_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Failure;
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
 * Purge approval rest controller.
 */
final class Purge_Approval_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_APPROVE  = '/purge-jobs/commands/approve';
	public const ACTION_APPROVE = 'operations.purge.approve';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize purge approval rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Approve_Purge           $approvals Approvals.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Approve_Purge $approvals,
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
			throw new LogicException( 'Purge approval REST support is unavailable.' );
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
			return $this->problem( 422, Purge_Failure::APPROVAL_INVALID, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_APPROVE,
			array( Addon_Capabilities::MANAGE_MIGRATIONS ),
			null,
			null,
			array( Access_Policy_Request::CORE_DELETE_PLUGINS )
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
			return $this->problem( 503, Purge_Failure::STORAGE_UNAVAILABLE, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, Purge_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		try {
			$input  = $this->input( $request );
			$result = $this->approvals->execute(
				new Approve_Purge_Command(
					$grant->metadata()->actor_user_id(),
					$input['scope_type'],
					$input['preview_token'],
					$input['backup_evidence_digest'],
					$input['backup_verified_at_utc'],
					$input['confirmation_phrase'],
					$idempotency->fingerprint(),
					$correlation,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Purge_Failure::APPROVAL_INVALID, $correlation );
		} catch ( Purge_Failure $failure ) {
			return $this->purge_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Purge_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'purge_job' => array(
					'id'                      => $result->job_id(),
					'state'                   => $result->state(),
					'scope'                   => array(
						'type'      => $result->scope_type(),
						'public_id' => null,
					),
					'scope_hash'              => $result->scope_hash(),
					'preview_hash'            => $result->preview_hash(),
					'approved_at_utc'         => $result->approved_at_utc(),
					'approval_expires_at_utc' => $result->approval_expires_at_utc(),
				),
			),
			$correlation,
			$result->replayed() ? 200 : 201,
			array(
				'replayed'                => $result->replayed(),
				'mutation_count'          => $result->mutation_count(),
				'ledger_mutation_count'   => $result->ledger_mutation_count(),
				'business_mutation_count' => $result->business_mutation_count(),
				'file_mutation_count'     => $result->file_mutation_count(),
				'option_mutation_count'   => $result->option_mutation_count(),
				'role_mutation_count'     => $result->role_mutation_count(),
				'platform_write_count'    => $result->platform_write_count(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{scope_type:string,preview_token:string,backup_evidence_digest:string,backup_verified_at_utc:DateTimeImmutable,confirmation_phrase:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Purge approval body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Purge approval body is invalid.' );
		}
		$this->exact( $input, array( 'scope', 'preview_token', 'backup', 'confirmation' ) );
		$scope        = $input['scope'] ?? null;
		$backup       = $input['backup'] ?? null;
		$confirmation = $input['confirmation'] ?? null;
		if (
			! is_array( $scope ) || array_is_list( $scope )
			|| ! is_array( $backup ) || array_is_list( $backup )
			|| ! is_array( $confirmation ) || array_is_list( $confirmation )
		) {
			throw new InvalidArgumentException( 'Purge approval sections are invalid.' );
		}
		$this->exact( $scope, array( 'type' ) );
		$this->exact( $backup, array( 'evidence_digest', 'verified_at_utc' ) );
		$this->exact( $confirmation, array( 'phrase' ) );

		$scope_type      = $scope['type'] ?? null;
		$preview_token   = $input['preview_token'] ?? null;
		$evidence_digest = $backup['evidence_digest'] ?? null;
		$verified_at     = $backup['verified_at_utc'] ?? null;
		$phrase          = $confirmation['phrase'] ?? null;
		foreach ( array( $scope_type, $preview_token, $evidence_digest, $verified_at, $phrase ) as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Purge approval string value is invalid.' );
			}
		}
		if (
			Preview_Purge_Command::SCOPE_ADDON !== $scope_type
			|| '' === $preview_token
			|| strlen( $preview_token ) > 4096
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $preview_token )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $evidence_digest )
			|| Approve_Purge_Command::CONFIRMATION_PHRASE !== $phrase
		) {
			throw new InvalidArgumentException( 'Purge approval values are invalid.' );
		}
		$verified = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $verified_at, new DateTimeZone( 'UTC' ) );
		if ( ! $verified instanceof DateTimeImmutable || $verified->format( 'Y-m-d H:i:s' ) !== $verified_at ) {
			throw new InvalidArgumentException( 'Purge approval backup time is invalid.' );
		}

		return array(
			'scope_type'             => $scope_type,
			'preview_token'          => $preview_token,
			'backup_evidence_digest' => $evidence_digest,
			'backup_verified_at_utc' => $verified,
			'confirmation_phrase'    => $phrase,
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
			throw new InvalidArgumentException( 'Purge approval query must be empty.' );
		}
	}

	/**
	 * Exact.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Purge approval input shape is invalid.' );
		}
	}

	/**
	 * Purge problem.
	 *
	 * @param Purge_Failure $failure Failure.
	 * @param string        $correlation Correlation.
	 * @return object
	 */
	private function purge_problem( Purge_Failure $failure, string $correlation ): object {
		$status = match ( $failure->reason() ) {
			Purge_Failure::TOKEN_EXPIRED,
			Purge_Failure::TOKEN_SCOPE_MISMATCH,
			Purge_Failure::PREVIEW_STALE,
			Purge_Failure::LEGAL_HOLD_BLOCKED,
			Purge_Failure::IDEMPOTENCY_CONFLICT,
			Purge_Failure::TOKEN_ALREADY_USED => 409,
			Purge_Failure::APPROVAL_INVALID,
			Purge_Failure::BACKUP_INVALID,
			Purge_Failure::TOKEN_INVALID => 422,
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
			409 => '정리할 데이터를 다시 확인해 주세요.',
			422 => '백업과 승인 내용을 확인해 주세요.',
			default => '지금은 데이터 영구 정리를 승인할 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
