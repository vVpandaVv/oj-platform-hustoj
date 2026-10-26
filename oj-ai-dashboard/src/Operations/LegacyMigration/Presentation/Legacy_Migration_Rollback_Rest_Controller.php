<?php
/**
 * Legacy migration rollback rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Rollback_Legacy_Migration_Command;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the frozen A-DS08-C63 unchanged-target compensation command.
 */
final class Legacy_Migration_Rollback_Rest_Controller {
	public const REST_NAMESPACE  = 'oj-ai/v1';
	public const ROUTE_ROLLBACK  = '/migration-runs/(?P<run_id>[0-9A-HJKMNP-TV-Z]{26})/commands/rollback';
	public const ACTION_ROLLBACK = 'operations.legacy_migration.rollback';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize legacy migration rollback rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Rollback_Legacy_Migration $rollbacks Rollbacks.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 * @param Canonical_Json            $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Rollback_Legacy_Migration $rollbacks,
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
			throw new LogicException( 'Legacy migration rollback REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ROLLBACK,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => array( $this, 'permissions_rollback' ),
			)
		);
	}

	/**
	 * Permissions rollback.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_rollback( object $request ) {
		try {
			$this->empty_query( $request );
			$this->path_id( $request );
			$this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, Legacy_Migration_Failure::ROLLBACK_INVALID, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_ROLLBACK,
			array( Addon_Capabilities::MANAGE_MIGRATIONS )
		);
	}

	/**
	 * Rollback.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rollback( object $request ) {
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
			$result = $this->rollbacks->rollback(
				new Rollback_Legacy_Migration_Command(
					$grant->metadata()->actor_user_id(),
					$this->path_id( $request ),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_checkpoint_hash'],
					$input['expected_updated_at_utc'],
					$idempotency->fingerprint(),
					$correlation,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Legacy_Migration_Failure::ROLLBACK_INVALID, $correlation );
		} catch ( Legacy_Migration_Failure $failure ) {
			return $this->migration_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'migration_run' => array(
					'id'                       => $result->run_id(),
					'state'                    => $result->state(),
					'checkpoint'               => array(
						'phase' => 'compensated',
						'hash'  => $result->checkpoint_hash(),
					),
					'items'                    => $result->item_totals(),
					'compensated_target_count' => $result->compensated_target_count(),
					'compensated_at_utc'       => $result->compensated_at_utc(),
				),
			),
			$correlation,
			200,
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
	 * @return array{command_id:string,expected_state:string,expected_checkpoint_hash:string,expected_updated_at_utc:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Legacy migration rollback body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Legacy migration rollback body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Legacy migration rollback sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'checkpoint_hash', 'state', 'updated_at_utc' ) );

		$command_id      = $input['command_id'] ?? null;
		$state           = $expected['state'] ?? null;
		$checkpoint_hash = $expected['checkpoint_hash'] ?? null;
		$updated_at_utc  = $expected['updated_at_utc'] ?? null;
		if (
			! is_string( $command_id ) || ! Public_Id::is_valid( $command_id )
			|| ! is_string( $state ) || ! in_array( $state, array( 'completed', 'completed_with_errors' ), true )
			|| ! is_string( $checkpoint_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash )
			|| ! is_string( $updated_at_utc ) || ! $this->is_utc( $updated_at_utc )
		) {
			throw new InvalidArgumentException( 'Legacy migration rollback values are invalid.' );
		}

		return array(
			'command_id'               => $command_id,
			'expected_state'           => $state,
			'expected_checkpoint_hash' => $checkpoint_hash,
			'expected_updated_at_utc'  => $updated_at_utc,
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
			throw new InvalidArgumentException( 'Legacy migration rollback path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['run_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Legacy migration rollback run ID is invalid.' );
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
		if ( ! method_exists( $request, 'get_query_params' ) || array() !== $request->get_query_params() ) {
			throw new InvalidArgumentException( 'Legacy migration rollback query must be empty.' );
		}
	}

	/**
	 * Is utc.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private function is_utc( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return false;
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $time instanceof DateTimeImmutable && $time->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Legacy migration rollback input shape is invalid.' );
		}
	}

	/**
	 * Migration problem.
	 *
	 * @param Legacy_Migration_Failure $failure Failure.
	 * @param string                   $correlation Correlation.
	 * @return \WP_Error
	 */
	private function migration_problem( Legacy_Migration_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Legacy_Migration_Failure::RUN_NOT_FOUND => 404,
			Legacy_Migration_Failure::RUN_STATE_CONFLICT,
			Legacy_Migration_Failure::TARGET_CHANGED,
			Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT => 409,
			Legacy_Migration_Failure::ROLLBACK_INVALID => 422,
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
			404 => '이전 작업을 찾을 수 없습니다.',
			409 => '최신 이전 상태를 다시 확인해 주세요.',
			422 => '되돌리기 내용을 확인해 주세요.',
			default => '지금은 되돌릴 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
