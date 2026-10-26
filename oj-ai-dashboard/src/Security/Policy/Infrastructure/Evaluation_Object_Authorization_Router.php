<?php
/**
 * Evaluation object authorization router.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Security\Policy\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Separates context-free worksheet evaluations from Context-bound submission evaluations.
 */
final class Evaluation_Object_Authorization_Router implements Object_Authorization_Port_Interface {
	/**
	 * Initialize evaluation object authorization router dependencies and state.
	 *
	 * @param Object_Authorization_Port_Interface $worksheet_evaluations Worksheet evaluations.
	 * @param Object_Authorization_Port_Interface $submission_evaluations Submission evaluations.
	 */
	public function __construct(
		private Object_Authorization_Port_Interface $worksheet_evaluations,
		private Object_Authorization_Port_Interface $submission_evaluations
	) {
	}

	/**
	 * Authorize.
	 *
	 * @param int                               $actor_user_id Authenticated actor from the access context.
	 * @param string                            $action_key Action key.
	 * @param Context_Authorization_Result|null $context Access or execution context for this operation.
	 * @param Object_Scope                      $object Object.
	 * @return Object_Authorization_Result
	 */
	public function authorize(
		int $actor_user_id,
		string $action_key,
		?Context_Authorization_Result $context,
		Object_Scope $object // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
	): Object_Authorization_Result {
		$route = null === $context ? $this->worksheet_evaluations : $this->submission_evaluations;

		return $route->authorize( $actor_user_id, $action_key, $context, $object );
	}
}
