<?php
/**
 * REST permission callback handoff.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Rest;

use OJAI\Dashboard\Rest\Infrastructure\WordPress_Rest_Request_Adapter;
use OJAI\Dashboard\Security\Policy\Context_Scope;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use Throwable;
use WeakMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hands an approved permission_callback decision to the route callback once.
 */
final class Rest_Permission_Handoff {
	/**
	 * Requests.
	 *
	 * @var WordPress_Rest_Request_Adapter
	 */
	private WordPress_Rest_Request_Adapter $requests;
	/**
	 * Authorizer.
	 *
	 * @var Rest_Request_Authorizer
	 */
	private Rest_Request_Authorizer $authorizer;
	/**
	 * Envelopes.
	 *
	 * @var Rest_Envelope_Factory
	 */
	private Rest_Envelope_Factory $envelopes;
	/**
	 * Correlations.
	 *
	 * @var Correlation_Id_Factory
	 */
	private Correlation_Id_Factory $correlations;

	/**
	 * Grants.
	 *
	 * @var WeakMap<object,Rest_Permission_Grant>
	 */
	private WeakMap $grants;

	/**
	 * Initialize rest permission handoff dependencies and state.
	 *
	 * @param WordPress_Rest_Request_Adapter $requests Requests.
	 * @param Rest_Request_Authorizer        $authorizer Authorizer.
	 * @param Rest_Envelope_Factory          $envelopes Envelopes.
	 * @param Correlation_Id_Factory         $correlations Correlations.
	 */
	public function __construct(
		WordPress_Rest_Request_Adapter $requests,
		Rest_Request_Authorizer $authorizer,
		Rest_Envelope_Factory $envelopes,
		Correlation_Id_Factory $correlations
	) {
		$this->requests     = $requests;
		$this->authorizer   = $authorizer;
		$this->envelopes    = $envelopes;
		$this->correlations = $correlations;
		$this->grants       = new WeakMap();
	}

	/**
	 * Check.
	 *
	 * @param object             $request Request.
	 * @param string             $shell Shell.
	 * @param string             $action_key Action key.
	 * @param array<int,string>  $required_capabilities Declared add-on capabilities.
	 * @param Context_Scope|null $context Access or execution context for this operation.
	 * @param Object_Scope|null  $object Object.
	 * @param array<int,string>  $required_core_capabilities Frozen WordPress capabilities.
	 * @return true|\WP_Error
	 */
	public function check(
		object $request,
		string $shell,
		string $action_key,
		array $required_capabilities = array(),
		?Context_Scope $context = null,
		?Object_Scope $object = null, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		array $required_core_capabilities = array()
	) {
		try {
			$metadata      = $this->requests->capture( $request );
			$authorization = $this->authorizer->authorize(
				$metadata,
				$shell,
				$action_key,
				$required_capabilities,
				$context,
				$object,
				$required_core_capabilities
			);
		} catch ( Throwable ) {
			return $this->envelopes->problem(
				Rest_Problem::for_status( 500, $this->correlations->create() )
			);
		}

		if ( ! $authorization->is_allowed() ) {
			return $this->envelopes->authorization_error( $authorization );
		}

		$this->grants[ $request ] = new Rest_Permission_Grant( $metadata, $authorization );
		return true;
	}

	/**
	 * Authorize when any one complete capability set is allowed.
	 *
	 * Context and object checks still run through the common access policy. A
	 * non-403 result is definitive because capability checks precede those
	 * scopes in the frozen policy order.
	 *
	 * @param object                 $request Request.
	 * @param string                 $shell Shell.
	 * @param string                 $action_key Action key.
	 * @param array<array-key,mixed> $capability_alternatives Alternative declared capability sets.
	 * @param Context_Scope|null     $context Access or execution context for this operation.
	 * @param Object_Scope|null      $object Object.
	 * @param array<int,string>      $required_core_capabilities Frozen WordPress capabilities.
	 * @return true|\WP_Error
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function check_any(
		object $request,
		string $shell,
		string $action_key,
		array $capability_alternatives,
		?Context_Scope $context = null,
		?Object_Scope $object = null, // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		array $required_core_capabilities = array()
	) {
		try {
			if ( array() === $capability_alternatives ) {
				throw new \InvalidArgumentException( 'At least one capability alternative is required.' );
			}

			$metadata = $this->requests->capture( $request );
			$denied   = null;
			foreach ( $capability_alternatives as $required_capabilities ) {
				if ( ! is_array( $required_capabilities ) || array() === $required_capabilities ) {
					throw new \InvalidArgumentException( 'Capability alternatives must be non-empty arrays.' );
				}
				$authorization = $this->authorizer->authorize(
					$metadata,
					$shell,
					$action_key,
					$required_capabilities,
					$context,
					$object,
					$required_core_capabilities
				);
				if ( $authorization->is_allowed() ) {
					$this->grants[ $request ] = new Rest_Permission_Grant( $metadata, $authorization );
					return true;
				}

				$problem = $authorization->problem();
				if ( null === $problem || 403 !== $problem->status() ) {
					return $this->envelopes->authorization_error( $authorization );
				}
				$denied ??= $authorization;
			}
		} catch ( Throwable ) {
			return $this->envelopes->problem(
				Rest_Problem::for_status( 500, $this->correlations->create() )
			);
		}

		return $this->envelopes->authorization_error( $denied );
	}

	/**
	 * Take.
	 *
	 * @param object $request Request.
	 * @return Rest_Permission_Grant|null
	 */
	public function take( object $request ): ?Rest_Permission_Grant {
		$grant = $this->grants[ $request ] ?? null;
		unset( $this->grants[ $request ] );

		return $grant;
	}
}
