<?php
/**
 * Manual storage-worker resume REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\Diagnostics\Application\Resume_Storage_Jobs;
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
 * Lets a teacher who can already see this diagnostics page run the four
 * storage workers synchronously, instead of waiting on WP-Cron. Every worker
 * tick is already safe to repeat (per-item optimistic-locking claims), so this
 * is a plain POST command -- no backup preconditions, no replay cache, and no
 * response field beyond safe per-worker counts.
 */
final class Storage_Jobs_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_RESUME   = '/operations/storage/commands/resume';
	public const ACTION_RESUME  = 'operations.storage.resume';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize storage jobs rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Resume_Storage_Jobs     $resume Resume.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Resume_Storage_Jobs $resume,
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
			throw new LogicException( 'Storage jobs REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_RESUME,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => array( $this, 'permissions_resume' ),
			)
		);
	}

	/**
	 * Permissions resume.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_resume( object $request ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_RESUME,
			array( Addon_Capabilities::MANAGE_POLICIES )
		);
	}

	/**
	 * Resume.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function resume( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}

		$correlation = $grant->authorization()->correlation_id();
		$idempotency = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, $correlation );
		}

		try {
			$this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, $correlation );
		}

		try {
			$result = $this->resume->execute();
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation );
		}

		return $this->envelopes->success(
			array( 'storage_jobs' => array( 'workers' => $result->projection() ) ),
			$correlation,
			200,
			array(
				'total_iterations' => $result->total_iterations(),
				'all_succeeded'    => $result->all_succeeded(),
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Storage jobs body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Storage jobs body is invalid.' );
		}
		$this->exact( $input, array( 'command_id' ) );
		$command_id = $input['command_id'] ?? null;
		if ( ! is_string( $command_id ) || '' === $command_id || strlen( $command_id ) > 128 ) {
			throw new InvalidArgumentException( 'Storage jobs command ID is invalid.' );
		}

		return array( 'command_id' => $command_id );
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
			throw new InvalidArgumentException( 'Storage jobs input shape is invalid.' );
		}
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
