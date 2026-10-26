<?php
/**
 * Legacy migration execution rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Execute_Legacy_Migration_Command;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
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
 * Exposes the frozen A-DS08-C62 approved-run execution command.
 */
final class Legacy_Migration_Execution_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_EXECUTE  = '/migration-runs/(?P<run_id>[0-9A-HJKMNP-TV-Z]{26})/commands/execute';
	public const ACTION_EXECUTE = 'operations.legacy_migration.execute';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize legacy migration execution rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff  $permissions Permissions.
	 * @param Execute_Legacy_Migration $executions Executions.
	 * @param Rest_Envelope_Factory    $envelopes Envelopes.
	 * @param Correlation_Id_Factory   $correlations Correlations.
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Execute_Legacy_Migration $executions,
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
			throw new LogicException( 'Legacy migration execution REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_EXECUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permissions_execute' ),
			)
		);
	}

	/**
	 * Permissions execute.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_execute( object $request ) {
		try {
			$this->empty_query( $request );
			$this->path_id( $request );
			$this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, Legacy_Migration_Failure::EXECUTION_INVALID, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_EXECUTE,
			array( Addon_Capabilities::MANAGE_MIGRATIONS )
		);
	}

	/**
	 * Execute legacy migration execution rest controller.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute( object $request ) {
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
			$run_id     = $this->path_id( $request );
			$input      = $this->input( $request );
			$result     = $this->executions->execute(
				new Execute_Legacy_Migration_Command(
					$grant->metadata()->actor_user_id(),
					$run_id,
					$input['command_id'],
					$input['expected_state'],
					$input['expected_source_fingerprint'],
					$input['expected_plan_hash'],
					$idempotency->fingerprint(),
					$correlation,
					$this->canonical
				)
			);
			$checkpoint = $this->checkpoint_projection( $result->checkpoint(), $result->checkpoint_hash() );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Legacy_Migration_Failure::EXECUTION_INVALID, $correlation );
		} catch ( Legacy_Migration_Failure $failure ) {
			return $this->migration_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'migration_run' => array(
					'id'             => $result->run_id(),
					'state'          => $result->state(),
					'source'         => array( 'fingerprint' => $result->source_fingerprint() ),
					'plan_hash'      => $result->plan_hash(),
					'checkpoint'     => $checkpoint,
					'item_total'     => $result->item_total(),
					'started_at_utc' => $result->started_at_utc(),
				),
			),
			$correlation,
			202,
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
	 * @return array{command_id:string,expected_state:string,expected_source_fingerprint:string,expected_plan_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Legacy migration execution body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Legacy migration execution body is invalid.' );
		}
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'Legacy migration execution sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'plan_hash', 'source_fingerprint', 'state' ) );

		$command_id         = $input['command_id'] ?? null;
		$state              = $expected['state'] ?? null;
		$source_fingerprint = $expected['source_fingerprint'] ?? null;
		$plan_hash          = $expected['plan_hash'] ?? null;
		if (
			! is_string( $command_id ) || ! Public_Id::is_valid( $command_id )
			|| 'approved' !== $state
			|| ! is_string( $source_fingerprint ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint )
			|| ! is_string( $plan_hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash )
		) {
			throw new InvalidArgumentException( 'Legacy migration execution values are invalid.' );
		}

		return array(
			'command_id'                  => $command_id,
			'expected_state'              => $state,
			'expected_source_fingerprint' => $source_fingerprint,
			'expected_plan_hash'          => $plan_hash,
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
			throw new InvalidArgumentException( 'Legacy migration execution path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['run_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Legacy migration execution run ID is invalid.' );
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
			throw new InvalidArgumentException( 'Legacy migration execution query must be empty.' );
		}
	}

	/**
	 * Checkpoint projection.
	 *
	 * @param array<string,mixed> $checkpoint Checkpoint.
	 * @param string              $hash Hash.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function checkpoint_projection( array $checkpoint, string $hash ): array {
		$cursor = $checkpoint['cursor'] ?? null;
		$totals = $checkpoint['totals'] ?? null;
		if (
			! is_array( $cursor ) || array_is_list( $cursor )
			|| ! is_array( $totals ) || array_is_list( $totals )
			|| ! is_string( $checkpoint['profile'] ?? null )
			|| 'running' !== ( $checkpoint['phase'] ?? null )
			|| Execute_Legacy_Migration::BATCH_SIZE !== ( $checkpoint['batch_size'] ?? null )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
		) {
			throw new InvalidArgumentException( 'Legacy migration checkpoint projection is invalid.' );
		}
		foreach ( array( 'table_index', 'after_source_id', 'next_item_no' ) as $key ) {
			if ( ! is_int( $cursor[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Legacy migration checkpoint cursor is invalid.' );
			}
		}
		foreach ( array( 'success', 'failure', 'skipped' ) as $key ) {
			if ( ! is_int( $totals[ $key ] ?? null ) ) {
				throw new InvalidArgumentException( 'Legacy migration checkpoint totals are invalid.' );
			}
		}

		return array(
			'profile'    => $checkpoint['profile'],
			'phase'      => 'running',
			'batch_size' => Execute_Legacy_Migration::BATCH_SIZE,
			'cursor'     => array(
				'table_index'     => $cursor['table_index'],
				'after_source_id' => $cursor['after_source_id'],
				'next_item_no'    => $cursor['next_item_no'],
			),
			'totals'     => array(
				'success' => $totals['success'],
				'failure' => $totals['failure'],
				'skipped' => $totals['skipped'],
			),
			'hash'       => $hash,
		);
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
			throw new InvalidArgumentException( 'Legacy migration execution input shape is invalid.' );
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
			Legacy_Migration_Failure::MAPPING_REQUIRED,
			Legacy_Migration_Failure::SOURCE_CONFLICT,
			Legacy_Migration_Failure::SOURCE_PROFILE_MISMATCH,
			Legacy_Migration_Failure::SOURCE_STALE,
			Legacy_Migration_Failure::IDEMPOTENCY_CONFLICT => 409,
			Legacy_Migration_Failure::SOURCE_LIMIT_EXCEEDED => 413,
			Legacy_Migration_Failure::EXECUTION_INVALID,
			Legacy_Migration_Failure::SOURCE_PROFILE_UNSUPPORTED => 422,
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
			413 => '이전할 데이터 크기를 나누어 주세요.',
			422 => '이전 실행 내용을 확인해 주세요.',
			default => '지금은 이전을 시작할 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
