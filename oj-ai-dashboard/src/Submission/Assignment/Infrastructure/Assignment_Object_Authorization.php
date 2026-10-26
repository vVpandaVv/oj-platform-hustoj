<?php
/**
 * Assignment object authorization.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Port\Assignment_Repository_Port_Interface;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Enforces staff assignment scope and the learner's current self target scope.
 */
final class Assignment_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Initialize assignment object authorization dependencies and state.
	 *
	 * @param Assignment_Repository_Port_Interface          $assignments Assignments.
	 * @param Student_Assignment_Access_Port_Interface|null $student_assignments Student assignments.
	 */
	public function __construct(
		private Assignment_Repository_Port_Interface $assignments,
		private ?Student_Assignment_Access_Port_Interface $student_assignments = null
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
	public function authorize( int $actor_user_id, string $action_key, ?Context_Authorization_Result $context, Object_Scope $object ): Object_Authorization_Result { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.objectFound -- Preserve the established named-argument signature; these parameter names are legal in supported PHP versions.
		unset( $action_key );
		if ( Object_Scope::TYPE_ASSIGNMENT !== $object->type() || null === $context || ! $context->is_allowed() ) {
			return Object_Authorization_Result::unavailable();
		}
		if ( in_array( $object->action(), array( 'self_view', 'start', 'export_pdf' ), true ) ) {
			if ( null === $this->student_assignments ) {
				return Object_Authorization_Result::unavailable();
			}
			$snapshot = $this->student_assignments->authorization_snapshot( $object->id(), $actor_user_id );
			if ( null === $snapshot || 'active' !== $snapshot['target_state'] ) {
				return Object_Authorization_Result::denied();
			}
			$allowed = array(
				'self_view'  => array( 'scheduled', 'open', 'closed' ),
				'start'      => array( 'open' ),
				'export_pdf' => array( 'open', 'closed' ),
			);
			if ( ! in_array( $snapshot['state'], $allowed[ $object->action() ], true ) ) {
				return Object_Authorization_Result::denied();
			}
			if ( $snapshot['context_type'] !== $context->type() || $snapshot['context_id'] !== $context->id() ) {
				return Object_Authorization_Result::denied();
			}
			return Object_Authorization_Result::allowed( $snapshot['current_revision'], $snapshot['state'], 'self_target' );
		}
		$snapshot = $this->assignments->authorization_snapshot( $object->id() );
		if ( null === $snapshot ) {
			return Object_Authorization_Result::denied();
		}
		$allowed_actions = array(
			'draft'     => array( 'view', 'view_submissions', 'import_zip', 'update_schedule', 'open', 'withdraw', 'add_targets' ),
			'scheduled' => array( 'view', 'view_submissions', 'import_zip', 'update_schedule', 'open', 'withdraw', 'add_targets' ),
			'open'      => array( 'view', 'view_submissions', 'import_zip', 'evaluate_submissions', 'export_submissions', 'update_schedule', 'close', 'withdraw', 'add_targets' ),
			'closed'    => array( 'view', 'view_submissions', 'import_zip', 'evaluate_submissions', 'export_submissions', 'reopen', 'withdraw' ),
			'withdrawn' => array( 'view', 'view_submissions' ),
		);
		if ( ! in_array( $object->action(), $allowed_actions[ $snapshot['state'] ] ?? array(), true ) ) {
			return Object_Authorization_Result::denied();
		}
		if (
			$snapshot['context_type'] !== $context->type() || $snapshot['context_id'] !== $context->id()
			|| $snapshot['context_revision'] !== $context->revision()
		) {
			return Object_Authorization_Result::denied();
		}
		return Object_Authorization_Result::allowed( $snapshot['current_revision'], $snapshot['state'], 'context_staff' );
	}
}
