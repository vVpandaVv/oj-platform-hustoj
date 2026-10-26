<?php
/**
 * Student release object policy.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Submission\Assessment\Port\Submission_Result_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows only the learner's current release inside the current Context.
 */
final class Submission_Result_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Initialize submission result object authorization dependencies and state.
	 *
	 * @param Submission_Result_Query_Port_Interface $results Results.
	 */
	public function __construct( private Submission_Result_Query_Port_Interface $results ) {
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
			Object_Scope::TYPE_RELEASE !== $object->type()
			|| ! in_array( $object->action(), array( 'self_view', 'export_pdf' ), true )
			|| null === $context
			|| ! $context->is_allowed()
		) {
			return Object_Authorization_Result::unavailable();
		}

		$snapshot = $this->results->authorization_snapshot( $object->id(), $actor_user_id );
		if (
			null === $snapshot
			|| 'active' !== $snapshot['target_state']
			|| 'withdrawn' === $snapshot['assignment_state']
			|| $snapshot['context_type'] !== $context->type()
			|| $snapshot['context_id'] !== $context->id()
			|| $snapshot['context_revision'] !== $context->revision()
		) {
			return Object_Authorization_Result::denied();
		}

		return Object_Authorization_Result::allowed(
			$snapshot['release_hash'],
			$snapshot['release_state'],
			'self_release'
		);
	}
}
