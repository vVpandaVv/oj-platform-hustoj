<?php
/**
 * Worksheet package rest controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Enqueue_Worksheet_Package_Command;
use OJAI\Dashboard\Reporting\WorksheetPackage\Application\Worksheet_Package_Failure;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package rest controller.
 */
final class Worksheet_Package_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_ENQUEUE  = '/worksheet-revisions/(?P<revision_id>[0-9A-HJKMNP-TV-Z]{26})/commands/export-package';
	public const ACTION_ENQUEUE = 'worksheet.package.enqueue';
	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize worksheet package rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff   $permissions Permissions.
	 * @param Enqueue_Worksheet_Package $enqueue Enqueue.
	 * @param Canonical_Json            $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory     $envelopes Envelopes.
	 * @param Correlation_Id_Factory    $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Enqueue_Worksheet_Package $enqueue,
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
			throw new LogicException( 'Worksheet package REST support is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_ENQUEUE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'enqueue' ),
				'permission_callback' => array( $this, 'permissions_enqueue' ),
			)
		);
	}

	/**
	 * Permissions enqueue.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_enqueue( object $request ) {
		try {
			$id = $this->path_id( $request, 'revision_id' ); } catch ( Throwable ) {
			return $this->problem( 404, $this->correlations->create() );
			}
			return $this->permissions->check(
				$request,
				Access_Policy_Request::SHELL_CONSOLE,
				self::ACTION_ENQUEUE,
				array( Addon_Capabilities::EXPORT_REPORTS ),
				null,
				new Object_Scope( Object_Scope::TYPE_WORKSHEET_REVISION, $id, 'view' )
			);
	}

	/**
	 * Enqueue.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function enqueue( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation = $grant->authorization()->correlation_id();
		$idempotency = $grant->authorization()->idempotency_key();
		$object      = $grant->authorization()->policy_decision()->object();
		if ( null === $idempotency || ! $object instanceof Object_Authorization_Result || ! $object->is_allowed() ) {
			return $this->problem( 503, $correlation );
		}
		try {
			$revision = $this->path_id( $request, 'revision_id' );
			$input    = $this->input( $request );
			$result   = $this->enqueue->execute(
				new Enqueue_Worksheet_Package_Command(
					$grant->metadata()->actor_user_id(),
					$revision,
					$input['command_id'],
					$input['expected_revision_id'],
					$input['expected_source_hash'],
					$input['expected_state'],
					$input['format_version'],
					$idempotency->fingerprint(),
					$correlation,
					(string) $object->current_revision(),
					(string) $object->current_state(),
					(string) $object->actor_relation(),
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation );
		} catch ( Worksheet_Package_Failure $failure ) {
			return $this->package_problem( $failure, $correlation );
		} catch ( Throwable ) {
				return $this->problem( 503, $correlation );
		}
			return $this->envelopes->success(
				array(
					'export_job' => array(
						'id'            => $result->job_id(),
						'state'         => $result->state(),
						'artifact_kind' => 'worksheet_package',
						'source_type'   => 'worksheet_revision',
						'source_id'     => $result->source_id(),
						'source_hash'   => $result->source_hash(),
					),
				),
				$correlation,
				202,
				array(
					'idempotent_replay' => $result->replayed(),
					'queued_at_utc'     => $result->queued_at_utc(),
				)
			);
	}

	/**
	 * Input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_revision_id:string,expected_source_hash:string,expected_state:string,format_version:int}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function input( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet package body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Worksheet package body is invalid.' );
		}
		$this->exact( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'Worksheet package sections are invalid.' );
		}
		$this->exact( $expected, array( 'expected_revision_id', 'expected_source_hash', 'expected_state' ) );
		$this->exact( $payload, array( 'format_version' ) );
		foreach ( array( $input['command_id'] ?? null, $expected['expected_revision_id'] ?? null, $expected['expected_source_hash'] ?? null, $expected['expected_state'] ?? null ) as $value ) {
			if ( ! is_string( $value ) ) {
				throw new InvalidArgumentException( 'Worksheet package value is invalid.' );
			}
		}
		if ( ! is_int( $payload['format_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Worksheet package format version is invalid.' );
		}
		new Public_Id( $expected['expected_revision_id'] );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected['expected_source_hash'] ) ) {
			throw new InvalidArgumentException( 'Worksheet package source hash is invalid.' );
		}
		return array(
			'command_id'           => $input['command_id'],
			'expected_revision_id' => $expected['expected_revision_id'],
			'expected_source_hash' => $expected['expected_source_hash'],
			'expected_state'       => $expected['expected_state'],
			'format_version'       => $payload['format_version'],
		);
	}
	/**
	 * Exact.
	 *
	 * @param array $value Value.
	 * @param array $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual );
		sort( $expected );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Worksheet package input shape is invalid.' );
		}
	}
	/**
	 * Path id.
	 *
	 * @param object $request Request.
	 * @param string $name Name.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function path_id( object $request, string $name ): string {
		if ( ! method_exists( $request, 'get_url_params' ) ) {
			throw new InvalidArgumentException( 'Worksheet package path is unavailable.' );
		}$params = $request->get_url_params();
		$value   = is_array( $params ) ? ( $params[ $name ] ?? null ) : null;
		if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
			throw new InvalidArgumentException( 'Worksheet package path ID is invalid.' );
		}return $value;
	}
	/**
	 * Package problem.
	 *
	 * @param Worksheet_Package_Failure $failure Failure.
	 * @param string                    $correlation Correlation.
	 * @return mixed
	 */
	private function package_problem( Worksheet_Package_Failure $failure, string $correlation ) {
		$status = match ( $failure->reason() ) {
			Worksheet_Package_Failure::NOT_FOUND => 404,
			Worksheet_Package_Failure::SOURCE_STALE, Worksheet_Package_Failure::IDEMPOTENCY_CONFLICT => 409,
			Worksheet_Package_Failure::SOURCE_INVALID, Worksheet_Package_Failure::TOO_LARGE => 422,
			Worksheet_Package_Failure::ACCESS_DENIED => 403,
			default => 503,
		};
		return $this->envelopes->problem(
			Rest_Problem::operation(
				$status,
				$failure->reason(),
				match ( $status ) {
				403 => '권한을 확인해 주세요.', 404 => '대상을 찾을 수 없습니다.', 409 => '현재 상태를 다시 확인해 주세요.',
				422 => '활동지와 첨부 파일을 확인해 주세요.', default => '지금은 패키지를 만들 수 없습니다.',
				},
				$correlation
			)
		);
	}
	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation Correlation.
	 * @return mixed
	 */
	private function problem( int $status, string $correlation ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation ) );
	}
}
