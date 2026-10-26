<?php
/**
 * Common application access policy.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy;

use OJAI\Dashboard\Contracts\Platform_Runtime_Readiness_Port_Interface;
use OJAI\Dashboard\Security\Addon_Capabilities;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Actor_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluates Runtime, actor, shell, capability, Context, object, and state in order.
 */
final class Access_Policy implements Access_Policy_Port_Interface {
	private const CONSOLE_CORE_CAPABILITY = 'oj_access_console';

	/**
	 * Runtime.
	 *
	 * @var Platform_Runtime_Readiness_Port_Interface
	 */
	private Platform_Runtime_Readiness_Port_Interface $runtime;
	/**
	 * Actors.
	 *
	 * @var Actor_Authorization_Port_Interface
	 */
	private Actor_Authorization_Port_Interface $actors;
	/**
	 * Contexts.
	 *
	 * @var Context_Authorization_Port_Interface
	 */
	private Context_Authorization_Port_Interface $contexts;
	/**
	 * Objects.
	 *
	 * @var Object_Authorization_Port_Interface
	 */
	private Object_Authorization_Port_Interface $objects;

	/**
	 * Initialize access policy dependencies and state.
	 *
	 * @param Platform_Runtime_Readiness_Port_Interface $runtime Runtime.
	 * @param Actor_Authorization_Port_Interface        $actors Actors.
	 * @param Context_Authorization_Port_Interface      $contexts Contexts.
	 * @param Object_Authorization_Port_Interface       $objects Objects.
	 */
	public function __construct(
		Platform_Runtime_Readiness_Port_Interface $runtime,
		Actor_Authorization_Port_Interface $actors,
		Context_Authorization_Port_Interface $contexts,
		Object_Authorization_Port_Interface $objects
	) {
		$this->runtime  = $runtime;
		$this->actors   = $actors;
		$this->contexts = $contexts;
		$this->objects  = $objects;
	}

	/**
	 * Authorize.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision
	 */
	public function authorize( Access_Policy_Request $request ): Access_Policy_Decision {
		return $this->authorize_with_runtime( $request, $this->runtime );
	}

	/**
	 * Authorize schema recovery.
	 *
	 * @param Access_Policy_Request                     $request Request.
	 * @param Platform_Runtime_Readiness_Port_Interface $runtime Runtime.
	 * @return Access_Policy_Decision
	 */
	public function authorize_schema_recovery(
		Access_Policy_Request $request,
		Platform_Runtime_Readiness_Port_Interface $runtime
	): Access_Policy_Decision {
		if ( ! in_array(
			$request->action_key(),
			array( 'operations.schema.repair', 'operations.schema.repair.view' ),
			true
		) ) {
			return $this->deny( $request, 403, 'schema_recovery_action_denied' );
		}

		return $this->authorize_with_runtime( $request, $runtime );
	}

	/**
	 * Authorize purge recovery.
	 *
	 * @param Access_Policy_Request                     $request Request.
	 * @param Platform_Runtime_Readiness_Port_Interface $runtime Runtime.
	 * @return Access_Policy_Decision
	 */
	public function authorize_purge_recovery(
		Access_Policy_Request $request,
		Platform_Runtime_Readiness_Port_Interface $runtime
	): Access_Policy_Decision {
		if ( ! in_array(
			$request->action_key(),
			array(
				'operations.purge.read',
				'operations.purge.preview',
				'operations.purge.approve',
				'operations.purge.execute',
				'operations.purge.cancel',
			),
			true
		) ) {
			return $this->deny( $request, 403, 'purge_recovery_action_denied' );
		}

		return $this->authorize_with_runtime( $request, $runtime );
	}

	/**
	 * Authorize with runtime.
	 *
	 * @param Access_Policy_Request                     $request Request.
	 * @param Platform_Runtime_Readiness_Port_Interface $runtime Runtime.
	 * @return Access_Policy_Decision
	 */
	private function authorize_with_runtime(
		Access_Policy_Request $request,
		Platform_Runtime_Readiness_Port_Interface $runtime
	): Access_Policy_Decision {
		if ( ! $runtime->is_ready() ) {
			return $this->deny( $request, 503, 'addon_runtime_not_ready' );
		}
		if ( 0 === $request->actor_user_id() ) {
			return $this->deny( $request, 401, 'authentication_required' );
		}

		try {
			if ( ! $this->actors->is_active( $request->actor_user_id() ) ) {
				return $this->deny( $request, 401, 'actor_inactive' );
			}
		} catch ( Throwable ) {
			return $this->deny( $request, 503, 'actor_authorization_unavailable' );
		}

		if ( ! $request->request_integrity_verified() ) {
			return $this->deny( $request, 403, 'request_integrity_invalid' );
		}

		$capability_decision = $this->authorize_capabilities( $request );
		if ( null !== $capability_decision ) {
			return $capability_decision;
		}

		$context_result = $this->authorize_context( $request );
		if ( $context_result instanceof Access_Policy_Decision ) {
			return $context_result;
		}

		$object = $request->object();
		if ( null !== $object && $object->requires_context() && null === $context_result ) {
			return $this->deny( $request, 404, 'object_context_missing' );
		}

		$object_result = null;
		if ( null !== $object ) {
			try {
				$object_result = $this->objects->authorize(
					$request->actor_user_id(),
					$request->action_key(),
					$context_result,
					$object
				);
			} catch ( Throwable ) {
				return $this->deny( $request, 503, 'object_authorization_failed' );
			}

			if ( $object_result->is_unavailable() ) {
				return $this->deny( $request, 503, $object_result->code() );
			}
			if ( $object_result->is_conflict() ) {
				return $this->deny( $request, 409, $object_result->code() );
			}
			if ( $object_result->is_denied() ) {
				return $this->deny( $request, 404, $object_result->code() );
			}
			if ( ! $object_result->is_allowed() ) {
				return $this->deny( $request, 503, 'object_authorization_invalid' );
			}

			if (
				null !== $object->expected_revision()
				&& ! hash_equals( $object->expected_revision(), (string) $object_result->current_revision() )
			) {
				return $this->deny( $request, 409, 'object_revision_conflict' );
			}
			if (
				null !== $object->expected_state()
				&& ! hash_equals( $object->expected_state(), (string) $object_result->current_state() )
			) {
				return $this->deny( $request, 409, 'object_state_conflict' );
			}
		}

		return Access_Policy_Decision::allowed( $request->correlation_id(), $context_result, $object_result );
	}

	/**
	 * Authorize capabilities.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Access_Policy_Decision|null
	 */
	private function authorize_capabilities( Access_Policy_Request $request ): ?Access_Policy_Decision {
		$actor_user_id = $request->actor_user_id();

		try {
			if (
				Access_Policy_Request::SHELL_CONSOLE === $request->shell()
				&& ! $this->actors->has_capability( $actor_user_id, self::CONSOLE_CORE_CAPABILITY )
			) {
				return $this->deny( $request, 403, 'console_access_denied' );
			}

			$shell_capability      = Access_Policy_Request::SHELL_CONSOLE === $request->shell()
				? Addon_Capabilities::VIEW_WORKSPACE
				: Addon_Capabilities::PARTICIPATE;
			$declared_capabilities = array_values(
				array_unique( array_merge( array( $shell_capability ), $request->required_capabilities() ) )
			);

			foreach ( $declared_capabilities as $declared_capability ) {
				$wordpress_capability = Addon_Capabilities::wordpress( $declared_capability );
				if ( '' === $wordpress_capability ) {
					return $this->deny( $request, 503, 'capability_mapper_unavailable' );
				}
				if ( ! $this->actors->has_capability( $actor_user_id, $wordpress_capability ) ) {
					return $this->deny( $request, 403, 'addon_capability_denied' );
				}
			}

			foreach ( $request->required_core_capabilities() as $core_capability ) {
				if ( ! $this->actors->has_capability( $actor_user_id, $core_capability ) ) {
					return $this->deny( $request, 403, 'core_capability_denied' );
				}
			}
		} catch ( Throwable ) {
			return $this->deny( $request, 503, 'capability_authorization_unavailable' );
		}

		return null;
	}

	/**
	 * Authorize context.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @return Context_Authorization_Result|Access_Policy_Decision|null
	 */
	private function authorize_context( Access_Policy_Request $request ) {
		$scope = $request->context();
		if ( null === $scope ) {
			return null;
		}

		if (
			Context_Scope::OPERATION_PARTICIPATE === $scope->operation()
			&& $request->actor_user_id() !== $scope->learner_user_id()
		) {
			return $this->deny( $request, 404, 'learner_context_scope_denied' );
		}
		if (
			Access_Policy_Request::SHELL_PUBLIC === $request->shell()
			&& Context_Scope::OPERATION_PARTICIPATE !== $scope->operation()
		) {
			return $this->deny( $request, 404, 'public_context_operation_denied' );
		}

		try {
			$result = $this->contexts->authorize( $request->actor_user_id(), $scope );
		} catch ( Throwable ) {
			return $this->deny( $request, 503, 'context_authorization_failed' );
		}

		if ( $result->is_unavailable() ) {
			return $this->deny( $request, 503, $result->code() );
		}
		if ( $result->is_denied() ) {
			return $this->deny( $request, 404, $result->code() );
		}
		if ( ! $result->is_allowed() ) {
			return $this->deny( $request, 503, 'context_authorization_invalid' );
		}
		if ( $result->type() !== $scope->type() || $result->id() !== $scope->id() ) {
			return $this->deny( $request, 503, 'context_authorization_invalid' );
		}
		if ( 'active' !== $result->lifecycle_state() ) {
			return $this->deny( $request, 404, 'context_inactive' );
		}
		if (
			null !== $scope->expected_revision()
			&& ! hash_equals( $scope->expected_revision(), (string) $result->revision() )
		) {
			return $this->deny( $request, 409, 'context_revision_conflict' );
		}

		return $result;
	}

	/**
	 * Deny.
	 *
	 * @param Access_Policy_Request $request Request.
	 * @param int                   $status Status.
	 * @param string                $reason_code Reason code.
	 * @return Access_Policy_Decision
	 */
	private function deny(
		Access_Policy_Request $request,
		int $status,
		string $reason_code
	): Access_Policy_Decision {
		return Access_Policy_Decision::denied( $status, $reason_code, $request->correlation_id() );
	}
}
