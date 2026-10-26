<?php
/**
 * Legacy migration run query rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Legacy_Migration_Failure;
use OJAI\Dashboard\Operations\LegacyMigration\Application\Read_Legacy_Migration_Run;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes the frozen A-DS08-Q29 safe migration-run projection.
 */
final class Legacy_Migration_Run_Query_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_SHOW     = '/migration-runs/(?P<run_id>[0-9A-HJKMNP-TV-Z]{26})';
	public const ACTION_SHOW    = Read_Legacy_Migration_Run::ACTION_READ;

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize legacy migration run query rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Read_Legacy_Migration_Run $runs Runs.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Read_Legacy_Migration_Run $runs,
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
			throw new LogicException( 'Legacy migration run query REST support is unavailable.' );
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
		try {
			$this->empty_query( $request );
			$this->run_id( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, Legacy_Migration_Failure::INPUT_INVALID, $this->correlations->create() );
		}
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_SHOW,
			array( Addon_Capabilities::MANAGE_MIGRATIONS )
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
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $this->correlations->create() );
		}
		$correlation = $grant->authorization()->correlation_id();
		try {
			$this->empty_query( $request );
			$result = $this->runs->read( $this->run_id( $request ) );
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Legacy_Migration_Failure::INPUT_INVALID, $correlation );
		} catch ( Legacy_Migration_Failure $failure ) {
			return $this->migration_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Legacy_Migration_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array( 'migration_run' => $result->payload() ),
			$correlation,
			200,
			array(
				'mutation_count'          => 0,
				'ledger_mutation_count'   => 0,
				'business_mutation_count' => 0,
				'platform_write_count'    => 0,
				'student_visible'         => false,
				'source_row_visible'      => false,
			)
		);
	}

	/**
	 * Run id.
	 *
	 * @param object $request Request.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function run_id( object $request ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Legacy migration run path is unavailable.' );
		}
		$params = $request->get_url_params();
		$value  = is_array( $params ) ? ( $params['run_id'] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Legacy migration run path is invalid.' );
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
			throw new InvalidArgumentException( 'Legacy migration run query is unavailable.' );
		}
		$query = $request->get_query_params();
		if ( ! is_array( $query ) || array() !== $query ) {
			throw new InvalidArgumentException( 'Legacy migration run query must be empty.' );
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
			Legacy_Migration_Failure::RUN_PROFILE_UNSUPPORTED => 409,
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
			409 => '이전 작업 형식이 현재 지원되지 않습니다.',
			422 => '요청 내용을 확인해 주세요.',
			default => '지금은 이전 작업을 불러올 수 없습니다.',
		};
		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
	}
}
