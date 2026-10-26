<?php
/**
 * Worksheet self-evaluation owner authorization adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Evaluation\Port\Worksheet_Evaluation_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet self-evaluation owner authorization adapter.
 */
final class Worksheet_Evaluation_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Initialize worksheet evaluation object authorization dependencies and state.
	 *
	 * @param Worksheet_Evaluation_Query_Port_Interface $queries Queries.
	 */
	public function __construct( private Worksheet_Evaluation_Query_Port_Interface $queries ) {
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
		unset( $action_key );
		if (
			Object_Scope::TYPE_EVALUATION !== $object->type()
			|| ! in_array( $object->action(), array( 'view', 'edit' ), true )
			|| null !== $context
		) {
			return Object_Authorization_Result::unavailable();
		}

		$snapshot = $this->queries->authorization_snapshot( $actor_user_id, $object->id() );
		if ( null === $snapshot ) {
			return Object_Authorization_Result::denied();
		}

		return Object_Authorization_Result::allowed(
			$snapshot['current_revision'],
			$snapshot['state'],
			$snapshot['relation']
		);
	}
}
