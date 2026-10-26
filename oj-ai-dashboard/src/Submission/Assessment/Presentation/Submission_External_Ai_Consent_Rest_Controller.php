<?php
/**
 * Q38, C78, and C79 external AI consent REST controller.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Presentation;

use InvalidArgumentException;
use LogicException;
use OJAI\Dashboard\Rest\Correlation_Id_Factory;
use OJAI\Dashboard\Rest\Rest_Envelope_Factory;
use OJAI\Dashboard\Rest\Rest_Permission_Handoff;
use OJAI\Dashboard\Rest\Rest_Problem;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Submission\Assessment\Application\Approve_Submission_External_Ai_Consent_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_External_Ai_Consent_Command_Result;
use OJAI\Dashboard\Submission\Assessment\Application\Submission_External_Ai_Consent_Failure;
use OJAI\Dashboard\Submission\Assessment\Application\Manage_Submission_External_Ai_Consent;
use OJAI\Dashboard\Submission\Assessment\Application\Revoke_Submission_External_Ai_Consent_Command;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Q38, C78, and C79 external AI consent REST controller.
 */
final class Submission_External_Ai_Consent_Rest_Controller {
	public const REST_NAMESPACE = 'oj-ai/v1';
	public const ROUTE_STATUS   = '/settings/submission-external-ai-consent';
	public const ROUTE_APPROVE  = '/settings/submission-external-ai-consent/commands/approve';
	public const ROUTE_REVOKE   = '/settings/submission-external-ai-consent/commands/revoke';
	public const ACTION_READ    = 'submission_external_ai.consent.read';
	public const ACTION_APPROVE = 'submission_external_ai.consent.approve';
	public const ACTION_REVOKE  = 'submission_external_ai.consent.revoke';

	/**
	 * Registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Initialize submission external ai consent rest controller dependencies and state.
	 *
	 * @param Rest_Permission_Handoff               $permissions Permissions.
	 * @param Manage_Submission_External_Ai_Consent $consent Consent.
	 * @param Canonical_Json                        $canonical Canonical JSON encoder used for stable digests.
	 * @param Rest_Envelope_Factory                 $envelopes Envelopes.
	 * @param Correlation_Id_Factory                $correlations Correlations.
	 */
	public function __construct(
		private Rest_Permission_Handoff $permissions,
		private Manage_Submission_External_Ai_Consent $consent,
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
			throw new LogicException( 'WordPress REST route registration is unavailable.' );
		}
		$this->registered = true;
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_STATUS,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'permissions_status' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_APPROVE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'permissions_approve' ),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_REVOKE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'permissions_revoke' ),
			)
		);
	}

	/**
	 * Permissions status.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_status( object $request ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			self::ACTION_READ,
			array()
		);
	}

	/**
	 * Permissions approve.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_approve( object $request ) {
		return $this->permission( $request, self::ACTION_APPROVE );
	}

	/**
	 * Permissions revoke.
	 *
	 * @param object $request Request.
	 * @return true|\WP_Error
	 */
	public function permissions_revoke( object $request ) {
		return $this->permission( $request, self::ACTION_REVOKE );
	}

	/**
	 * Status.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function status( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		try {
			$status = $this->consent->read();
		} catch ( Submission_External_Ai_Consent_Failure $failure ) {
			return $this->consent_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->success( $status, $correlation_id );
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
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, $correlation_id );
		}
		try {
			$input  = $this->approve_input( $request );
			$result = $this->consent->approve(
				new Approve_Submission_External_Ai_Consent_Command(
					$grant->metadata()->actor_user_id(),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_consent_hash'],
					$input['expected_routing_revision'],
					$input['expected_profile_fingerprint'],
					$input['acknowledged_external_processing'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_External_Ai_Consent_Failure $failure ) {
			return $this->consent_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->command_success( $result, $correlation_id );
	}

	/**
	 * Revoke.
	 *
	 * @param object $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke( object $request ) {
		$grant = $this->permissions->take( $request );
		if ( null === $grant ) {
			return $this->problem( 503, $this->correlations->create() );
		}
		$correlation_id = $grant->authorization()->correlation_id();
		$idempotency    = $grant->authorization()->idempotency_key();
		if ( null === $idempotency ) {
			return $this->problem( 503, $correlation_id );
		}
		try {
			$input  = $this->revoke_input( $request );
			$result = $this->consent->revoke(
				new Revoke_Submission_External_Ai_Consent_Command(
					$grant->metadata()->actor_user_id(),
					$input['command_id'],
					$input['expected_state'],
					$input['expected_consent_hash'],
					$idempotency->fingerprint(),
					$correlation_id,
					$this->canonical
				)
			);
		} catch ( InvalidArgumentException ) {
			return $this->problem( 422, $correlation_id );
		} catch ( Submission_External_Ai_Consent_Failure $failure ) {
			return $this->consent_problem( $failure, $correlation_id );
		} catch ( Throwable ) {
			return $this->problem( 503, $correlation_id );
		}
		return $this->command_success( $result, $correlation_id );
	}

	/**
	 * Permission.
	 *
	 * @param object $request Request.
	 * @param string $action Action.
	 * @return true|\WP_Error
	 */
	private function permission( object $request, string $action ) {
		return $this->permissions->check(
			$request,
			Access_Policy_Request::SHELL_CONSOLE,
			$action,
			array( Addon_Capabilities::MANAGE_POLICIES )
		);
	}

	/**
	 * Approve input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_consent_hash:?string,expected_routing_revision:string,expected_profile_fingerprint:string,acknowledged_external_processing:bool}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function approve_input( object $request ): array {
		$input = $this->json_body( $request );
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array_is_list( $payload ) ) {
			throw new InvalidArgumentException( 'External AI approval sections are invalid.' );
		}
		$this->exact_keys(
			$expected,
			array( 'expected_consent_hash', 'expected_profile_fingerprint', 'expected_routing_revision', 'expected_state' )
		);
		$this->exact_keys( $payload, array( 'acknowledged_external_processing' ) );
		$values = array(
			'command_id'                       => $input['command_id'] ?? null,
			'expected_state'                   => $expected['expected_state'] ?? null,
			'expected_consent_hash'            => $expected['expected_consent_hash'] ?? null,
			'expected_routing_revision'        => $expected['expected_routing_revision'] ?? null,
			'expected_profile_fingerprint'     => $expected['expected_profile_fingerprint'] ?? null,
			'acknowledged_external_processing' => $payload['acknowledged_external_processing'] ?? null,
		);
		if (
			! is_string( $values['command_id'] )
			|| ! is_string( $values['expected_state'] )
			|| ( null !== $values['expected_consent_hash'] && ! is_string( $values['expected_consent_hash'] ) )
			|| ! is_string( $values['expected_routing_revision'] )
			|| ! is_string( $values['expected_profile_fingerprint'] )
			|| true !== $values['acknowledged_external_processing']
		) {
			throw new InvalidArgumentException( 'External AI approval values are invalid.' );
		}
		return $values;
	}

	/**
	 * Revoke input.
	 *
	 * @param object $request Request.
	 * @return array{command_id:string,expected_state:string,expected_consent_hash:string}
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function revoke_input( object $request ): array {
		$input = $this->json_body( $request );
		$this->exact_keys( $input, array( 'command_id', 'expected', 'payload' ) );
		$expected = $input['expected'] ?? null;
		$payload  = $input['payload'] ?? null;
		if ( ! is_array( $expected ) || array_is_list( $expected ) || ! is_array( $payload ) || array() !== $payload ) {
			throw new InvalidArgumentException( 'External AI revocation sections are invalid.' );
		}
		$this->exact_keys( $expected, array( 'expected_consent_hash', 'expected_state' ) );
		$command_id            = $input['command_id'] ?? null;
		$expected_state        = $expected['expected_state'] ?? null;
		$expected_consent_hash = $expected['expected_consent_hash'] ?? null;
		if ( ! is_string( $command_id ) || ! is_string( $expected_state ) || ! is_string( $expected_consent_hash ) ) {
			throw new InvalidArgumentException( 'External AI revocation values are invalid.' );
		}
		return compact( 'command_id', 'expected_state', 'expected_consent_hash' );
	}

	/**
	 * Json body.
	 *
	 * @param object $request Request.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function json_body( object $request ): array {
		if ( ! method_exists( $request, 'get_json_params' ) ) {
			throw new InvalidArgumentException( 'Submission external AI consent JSON body is unavailable.' );
		}
		$input = $request->get_json_params();
		if ( ! is_array( $input ) || array_is_list( $input ) ) {
			throw new InvalidArgumentException( 'Submission external AI consent JSON body is invalid.' );
		}
		return $input;
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
			throw new InvalidArgumentException( 'Submission external AI consent contains unknown or missing fields.' );
		}
	}

	/**
	 * Success.
	 *
	 * @param array<string,mixed> $status Status.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_REST_Response
	 */
	private function success( array $status, string $correlation_id ) {
		return $this->envelopes->success(
			array( 'submission_external_ai_consent' => $status ),
			$correlation_id,
			200,
			array(),
			array()
		);
	}

	/**
	 * Command success.
	 *
	 * @param Submission_External_Ai_Consent_Command_Result $result Result.
	 * @param string                                        $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_REST_Response
	 */
	private function command_success( Submission_External_Ai_Consent_Command_Result $result, string $correlation_id ) {
		$status = $result->status();
		return $this->envelopes->success(
			array( 'submission_external_ai_consent' => $status ),
			$correlation_id,
			200,
			array(
				'idempotent_replay' => $result->replayed(),
				'operation'         => $result->operation(),
				'occurred_at_utc'   => $result->occurred_at_utc(),
			),
			array(),
			$this->actions( $status )
		);
	}

	/**
	 * Actions.
	 *
	 * @param array<string,mixed> $status Status.
	 * @return array<int,string>
	 */
	private function actions( array $status ): array {
		$state     = $status['consent']['state'] ?? null;
		$available = true === ( $status['routing']['available'] ?? false );
		$actions   = array();
		if ( $available && in_array( $state, array( 'missing', 'stale' ), true ) ) {
			$actions[] = self::ACTION_APPROVE;
		}
		if ( in_array( $state, array( 'approved', 'stale' ), true ) ) {
			$actions[] = self::ACTION_REVOKE;
		}
		return $actions;
	}

	/**
	 * Consent problem.
	 *
	 * @param Submission_External_Ai_Consent_Failure $failure Failure.
	 * @param string                                 $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function consent_problem( Submission_External_Ai_Consent_Failure $failure, string $correlation_id ) {
		$definition = match ( $failure->reason() ) {
			Submission_External_Ai_Consent_Failure::ROUTING_CHANGED => array( 409, 'AI 연결이 바뀌었습니다. 새로고침해 주세요.' ),
			Submission_External_Ai_Consent_Failure::STATE_CONFLICT => array( 409, '동의 상태가 바뀌었습니다. 새로고침해 주세요.' ),
			Submission_External_Ai_Consent_Failure::IDEMPOTENCY_CONFLICT => array( 409, '같은 요청 키의 내용이 다릅니다.' ),
			Submission_External_Ai_Consent_Failure::LOCK_UNAVAILABLE => array( 409, '다른 설정 작업이 진행 중입니다.' ),
			Submission_External_Ai_Consent_Failure::NOT_ROUTABLE => array( 503, 'AI 연결을 먼저 확인해 주세요.' ),
			default => array( 503, '동의 설정을 불러오지 못했습니다.' ),
		};
		return $this->envelopes->problem(
			Rest_Problem::operation( $definition[0], $failure->reason(), $definition[1], $correlation_id )
		);
	}

	/**
	 * Problem.
	 *
	 * @param int    $status Status.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @return \WP_Error
	 */
	private function problem( int $status, string $correlation_id ) {
		return $this->envelopes->problem( Rest_Problem::for_status( $status, $correlation_id ) );
	}
}
