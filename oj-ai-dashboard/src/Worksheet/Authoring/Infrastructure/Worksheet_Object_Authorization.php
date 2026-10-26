<?php
/**
 * Worksheet owner authorization adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Worksheet\Authoring\Port\Worksheet_Repository_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorizes only the owner relation supported by the frozen physical schema.
 */
final class Worksheet_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Worksheets.
	 *
	 * @var Worksheet_Repository_Port_Interface
	 */
	private Worksheet_Repository_Port_Interface $worksheets;

	/**
	 * Initialize worksheet object authorization dependencies and state.
	 *
	 * @param Worksheet_Repository_Port_Interface $worksheets Worksheets.
	 */
	public function __construct( Worksheet_Repository_Port_Interface $worksheets ) {
		$this->worksheets = $worksheets;
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
		if (
			! in_array( $object->action(), array( 'view', 'edit' ), true )
			|| ! in_array(
				$object->type(),
				array( Object_Scope::TYPE_WORKSHEET, Object_Scope::TYPE_WORKSHEET_REVISION ),
				true
			)
		) {
			return Object_Authorization_Result::unavailable();
		}

		$snapshot = $this->worksheets->authorization_snapshot(
			$actor_user_id,
			$object->type(),
			$object->id()
		);
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
