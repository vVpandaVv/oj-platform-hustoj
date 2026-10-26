<?php
/**
 * Frozen A-DS08-C75 forward schema repair REST boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Presentation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Repair_Addon_Schema_Command;
use OJAI\Dashboard\Operations\SchemaRepair\Application\Schema_Repair_Failure;
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
 * Frozen A-DS08-C75 forward schema repair REST boundary.
 */
final class Schema_Repair_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_REPAIR   = '/operations/schema/commands/repair';
	public const ACTION_REPAIR  = 'operations.schema.repair';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize schema repair rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff $permissions Permissions.
	 * @param Repair_Addon_Schema     $repairs Repairs.
	 * @param Canonical_Json          $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory   $envelopes Envelopes.
	 * @param Correlation_Id_Factory  $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Repair_Addon_Schema $repairs,
		private Canonical_Json $canonical,
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
			throw new LogicException( 'Schema repair REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_REPAIR,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'repair' ),
				'permission_callback' => array( $this, 'permissions_repair' ),
			)
		);
	}

	/**
	 * Permissions repair.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_repair( object $request ) {
		try {
			$this->empty_query( $request );
			$this->input( $request );
		} catch ( Throwable ) {
			return $this->problem( 422, Schema_Repair_Failure::BACKUP_INVALID, $this->correlations->create() );
		}

		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_REPAIR,
			array( Addon_Capabilities::MANAGE_MIGRATIONS )
		);
	}

	/**
	 * Repair.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function repair( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, Schema_Repair_Failure::STORAGE_UNAVAILABLE, $this->correlations->create() );
		}
		$authorization = $grant->authorization();
		$correlation   = $authorization->correlation_id();
		$idempotency   = $authorization->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, Schema_Repair_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		try {
			$input  = $this->input( $request );
			$result = $this->repairs->execute(
				new Repair_Addon_Schema_Command(
					$grant->metadata()->actor_user_id(),
					$input['command_id'],
					$input['installation_state'],
					$input['catalog_fingerprint'],
					$input['repair_state_hash'],
					$input['backup_evidence_digest'],
					$input['backup_verified_at_utc'],
					$input['forward_only'],
					$idempotency->fingerprint(),
					$correlation,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, Schema_Repair_Failure::BACKUP_INVALID, $correlation );
		} catch ( Schema_Repair_Failure $failure ) {
			return $this->repair_problem( $failure, $correlation );
		} catch ( Throwable ) {
			return $this->problem( 503, Schema_Repair_Failure::STORAGE_UNAVAILABLE, $correlation );
		}

		return $this->envelopes->success(
			array(
				'schema_repair' => array(
					'state'               => 'completed',
					'code'                => $result->code(),
					'catalog_fingerprint' => $result->catalog_fingerprint(),
					'completed_at_utc'    => $result->completed_at_utc(),
				),
			),
			$correlation,
			202,
			array(
				'idempotent_replay' => $result->replayed(),
				'mutation_count'    => $result->mutation_count(),
				'forward_only'      => true,
				'student_visible'   => false,
			)
		);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,installation_state:string,catalog_fingerprint:string,repair_state_hash:string,backup_evidence_digest:string,backup_verified_at_utc:DateTimeImmutable,forward_only:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Schema repair body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Schema repair body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'backup', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$backup   = $input['backup'] ?? null;
		$payload  = $input['payload'] ?? null;
		if (
			! is_array( $expected ) || array_is_list( $expected )
			|| ! is_array( $backup ) || array_is_list( $backup )
			|| ! is_array( $payload ) || array_is_list( $payload )
		) {
			throw new InvalidArgumentException( 'Schema repair sections are invalid.' );
		}
		$this->exact( $expected, array( 'installation_state', 'catalog_fingerprint', 'repair_state_hash' ) );
		$this->exact( $backup, array( 'evidence_digest', 'verified_at_utc' ) );
		$this->exact( $payload, array( 'forward_only' ) );

		$strings = array(
			'command_id'                => $input['command_id'] ?? null,
			'installation_state'        => $expected['installation_state'] ?? null,
			'catalog_fingerprint'       => $expected['catalog_fingerprint'] ?? null,
			'repair_state_hash'         => $expected['repair_state_hash'] ?? null,
			'backup_evidence_digest'    => $backup['evidence_digest'] ?? null,
			'backup_verified_at_string' => $backup['verified_at_utc'] ?? null,
		);
		foreach ( $strings as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Schema repair string value is invalid.' );
			}
		}
		if ( true !== ( $payload['forward_only'] ?? null ) ) {
			throw new InvalidArgumentException( 'Schema repair must be forward-only.' );
		}
		$verified = DateTimeImmutable::createFromFormat(
			'!Y-m-d H:i:s',
			$strings['backup_verified_at_string'],
			new DateTimeZone( 'UTC' )
		);
		if ( ! $verified instanceof DateTimeImmutable || $verified->format( 'Y-m-d H:i:s' ) !== $strings['backup_verified_at_string'] ) {
			throw new InvalidArgumentException( 'Schema repair backup time is invalid.' );
		}

		return array(
			'command_id'             => $strings['command_id'],
			'installation_state'     => $strings['installation_state'],
			'catalog_fingerprint'    => $strings['catalog_fingerprint'],
			'repair_state_hash'      => $strings['repair_state_hash'],
			'backup_evidence_digest' => $strings['backup_evidence_digest'],
			'backup_verified_at_utc' => $verified,
			'forward_only'           => true,
		);
	}

	/**
	 * Repair problem.
	 *
	 * @param Schema_Repair_Failure $failure Failure.
	 * @param string                $correlation Correlation.
	 * @return object
	 */
	private function repair_problem( Schema_Repair_Failure $failure, string $correlation ): object {
		$status = match ( $failure->reason() ) {
			Schema_Repair_Failure::IDEMPOTENCY_CONFLICT,
			Schema_Repair_Failure::STATE_STALE,
			Schema_Repair_Failure::NOT_AVAILABLE,
			Schema_Repair_Failure::BLOCKED => 409,
			Schema_Repair_Failure::BACKUP_INVALID => 422,
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
			409 => '상태를 다시 확인한 뒤 복구해 주세요.',
			422 => '백업 확인 정보를 확인해 주세요.',
			default => '지금은 데이터 구조를 복구할 수 없습니다.',
		};

		return $this->envelopes->problem( Rest_Problem::operation( $status, $code, $message, $correlation ) );
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
			throw new InvalidArgumentException( 'Schema repair query must be empty.' );
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
			throw new InvalidArgumentException( 'Schema repair input shape is invalid.' );
		}
	}
}
