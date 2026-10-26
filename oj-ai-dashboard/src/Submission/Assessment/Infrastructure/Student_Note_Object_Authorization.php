<?php
/**
 * Student-note object authorization adapter.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Student_Note_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student-note object authorization adapter.
 */
final class Student_Note_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Initialize student note object authorization dependencies and state.
	 *
	 * @param Student_Note_Query_Port_Interface $queries Queries.
	 */
	public function __construct( private Student_Note_Query_Port_Interface $queries ) {
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
		unset( $actor_user_id, $action_key );
		if (
			Object_Scope::TYPE_STUDENT_NOTE !== $object->type()
			|| ! in_array( $object->action(), array( 'view', 'review', 'approve', 'withdraw_approval', 'include_in_reports', 'exclude_from_reports' ), true )
			|| null === $context
			|| ! $context->is_allowed()
		) {
			return Object_Authorization_Result::unavailable();
		}

		$snapshot = $this->queries->authorization_snapshot(
			$object->id(),
			(string) $context->type(),
			(string) $context->id(),
			(string) $context->revision()
		);
		if ( null === $snapshot ) {
			return Object_Authorization_Result::denied();
		}
		if (
			$snapshot['context_type'] !== $context->type()
			|| $snapshot['context_id'] !== $context->id()
			|| $snapshot['context_revision'] !== $context->revision()
		) {
			return Object_Authorization_Result::denied();
		}

		return Object_Authorization_Result::allowed(
			$snapshot['current_revision'],
			$snapshot['state'],
			$snapshot['relation']
		);
	}
}
