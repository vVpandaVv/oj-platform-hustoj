<?php
/**
 * Public OJ Platform Context authorization adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy\Infrastructure;

use CodingPanda\OjPlatform\AddonApi\V1\AddonContextAccessRequestV1;
use OJAI\Dashboard\Contracts\Platform_Context_Runtime_Port_Interface;
use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Port\Context_Authorization_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts Addon API 1.1 decisions into the add-on's stable policy result.
 */
final class Platform_Context_Authorization implements Context_Authorization_Port_Interface {
	/**
	 * Runtime.
	 *
	 * @var Platform_Context_Runtime_Port_Interface
	 */
	private Platform_Context_Runtime_Port_Interface $runtime;

	/**
	 * Initialize platform context authorization dependencies and state.
	 *
	 * @param Platform_Context_Runtime_Port_Interface $runtime Runtime.
	 */
	public function __construct( Platform_Context_Runtime_Port_Interface $runtime ) {
		$this->runtime = $runtime;
	}

	/**
	 * Authorize.
	 *
	 * @param int           $actor_user_id Authenticated actor from the access context.
	 * @param Context_Scope $scope Scope.
	 * @return Context_Authorization_Result
	 */
	public function authorize( int $actor_user_id, Context_Scope $scope ): Context_Authorization_Result {
		if ( ! $this->runtime->is_ready() ) {
			return Context_Authorization_Result::unavailable( 'context_runtime_unavailable' );
		}

		$gateway = $this->runtime->contexts();
		if ( null === $gateway ) {
			return Context_Authorization_Result::unavailable( 'context_gateway_unavailable' );
		}

		try {
			$decision = $gateway->authorize(
				new AddonContextAccessRequestV1(
					$actor_user_id,
					$scope->type(),
					$scope->id(),
					$scope->operation(),
					$scope->learner_user_id(),
					$scope->expected_revision()
				)
			);
		} catch ( Throwable ) {
			return Context_Authorization_Result::unavailable( 'context_authorization_failed' );
		}

		if ( ! $decision->isAllowed() ) {
			if ( 'addon_context_unavailable' === $decision->code() ) {
				return Context_Authorization_Result::unavailable(
					'context_authorization_unavailable',
					$decision->correlationId()
				);
			}

			return Context_Authorization_Result::denied( 'context_scope_denied', $decision->correlationId() );
		}

		$snapshot = $decision->snapshot();
		if ( null === $snapshot ) {
			return Context_Authorization_Result::unavailable(
				'context_authorization_invalid',
				$decision->correlationId()
			);
		}

		try {
			return Context_Authorization_Result::allowed(
				$snapshot->type(),
				$snapshot->id(),
				$snapshot->lifecycleState(),
				$snapshot->revision(),
				$snapshot->actorRelation(),
				$decision->correlationId()
			);
		} catch ( Throwable ) {
			return Context_Authorization_Result::unavailable(
				'context_authorization_invalid',
				$decision->correlationId()
			);
		}
	}
}
