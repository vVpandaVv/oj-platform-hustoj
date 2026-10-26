<?php
/**
 * Common REST request authorizer.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use InvalidArgumentException;
use OJAI\Dashboard\Security\Policy\Access_Policy_Decision;
use OJAI\Dashboard\Security\Policy\Access_Policy_Request;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Access_Policy_Port_Interface;
use OJAI\Dashboard\Rest\Port\Rest_Nonce_Verifier_Port_Interface;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies standard nonce, common policy, then POST idempotency validation.
 */
final class Rest_Request_Authorizer {
	/**
	 * Policy.
	 *
	 * @var Access_Policy_Port_Interface
	 */
	private Access_Policy_Port_Interface $policy;
	/**
	 * Nonces.
	 *
	 * @var Rest_Nonce_Verifier_Port_Interface
	 */
	private Rest_Nonce_Verifier_Port_Interface $nonces;
	/**
	 * Correlations.
	 *
	 * @var Correlation_Id_Factory
	 */
	private Correlation_Id_Factory $correlations;

	/**
	 * Initialize rest request authorizer dependencies and state.
	 *
	 * @param Access_Policy_Port_Interface       $policy Policy.
	 * @param Rest_Nonce_Verifier_Port_Interface $nonces Nonces.
	 * @param Correlation_Id_Factory             $correlations Correlations.
	 */
	public function __construct(
		Access_Policy_Port_Interface $policy,
		Rest_Nonce_Verifier_Port_Interface $nonces,
		Correlation_Id_Factory $correlations
	) {
		$this->policy       = $policy;
		$this->nonces       = $nonces;
		$this->correlations = $correlations;
	}

	/**
	 * Authorize.
	 *
	 * @param Rest_Request_Metadata $metadata Metadata.
	 * @param string                $shell Shell.
	 * @param string                $action_key Action key.
	 * @param array<int,string>     $required_capabilities Declared add-on capability keys.
	 * @param Context_Scope|null    $context Access or execution context for this operation.
	 * @param Object_Scope|null     $object Object.
	 * @param array<int,string>     $required_core_capabilities Explicit frozen WordPress capabilities.
	 * @return Rest_Authorization_Result
	 */
	public function authorize(
		Rest_Request_Metadata $metadata,
		string $shell,
		string $action_key,
		array $required_capabilities = array(),
		?Context_Scope $context = null,
		?Object_Scope $object = null, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		array $required_core_capabilities = array()
	): Rest_Authorization_Result {
		$correlation_id = $this->correlations->create();
		$integrity      = true;

		if ( $metadata->is_mutation() ) {
			try {
				$integrity = $this->nonces->verify( $metadata->nonce() );
			} catch ( Throwable ) {
				$integrity = false;
			}
		}

		$request = new Access_Policy_Request(
			$metadata->actor_user_id(),
			$shell,
			Access_Policy_Request::SURFACE_REST,
			$action_key,
			$required_capabilities,
			$context,
			$object,
			$integrity,
			$correlation_id,
			$required_core_capabilities
		);

		try {
			$decision = $this->policy->authorize( $request );
		} catch ( Throwable ) {
			$decision = Access_Policy_Decision::denied( 503, 'access_policy_failed', $correlation_id );
		}

		if ( ! $decision->is_allowed() ) {
			return Rest_Authorization_Result::from_policy( $decision );
		}

		if ( ! $metadata->is_mutation() ) {
			return Rest_Authorization_Result::allowed( $decision );
		}

		try {
			$idempotency_key = new Idempotency_Key( $metadata->idempotency_key() );
		} catch ( InvalidArgumentException ) {
			$reason = '' === $metadata->idempotency_key()
				? 'idempotency_key_required'
				: 'idempotency_key_invalid';
			return Rest_Authorization_Result::validation_failed( $decision, $reason );
		}

		return Rest_Authorization_Result::allowed( $decision, $idempotency_key );
	}
}
