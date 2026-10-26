<?php
/**
 * Student submission object policy.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Infrastructure;

use OJAI\Dashboard\Security\Policy\Context_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Authorization_Result;
use OJAI\Dashboard\Security\Policy\Object_Scope;
use OJAI\Dashboard\Security\Policy\Port\Object_Authorization_Port_Interface;
use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Student\Port\Student_Assignment_Access_Port_Interface;
use OJAI\Dashboard\Submission\Teacher\Port\Teacher_Submission_Query_Port_Interface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allows only the authenticated learner's submission inside an active Context.
 */
final class Submission_Object_Authorization implements Object_Authorization_Port_Interface {
	/**
	 * Initialize submission object authorization dependencies and state.
	 *
	 * @param Student_Assignment_Access_Port_Interface     $submissions Submissions.
	 * @param Teacher_Submission_Query_Port_Interface|null $teacher_submissions Teacher submissions.
	 */
	public function __construct(
		private Student_Assignment_Access_Port_Interface $submissions,
		private ?Teacher_Submission_Query_Port_Interface $teacher_submissions = null
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
		unset( $action_key );
		if ( in_array( $object->action(), array( 'teacher_view', 'teacher_history', 'reopen', 'lock', 'evaluate' ), true ) ) {
			if (
				Object_Scope::TYPE_SUBMISSION !== $object->type()
				|| null === $context
				|| ! $context->is_allowed()
				|| null === $this->teacher_submissions
			) {
				return Object_Authorization_Result::unavailable();
			}
			$snapshot = $this->teacher_submissions->authorization_snapshot( $object->id() );
			if (
				null === $snapshot
				|| $snapshot['context_type'] !== $context->type()
				|| $snapshot['context_id'] !== $context->id()
				|| $snapshot['context_revision'] !== $context->revision()
			) {
				return Object_Authorization_Result::denied();
			}
			if ( in_array( $object->action(), array( 'reopen', 'lock', 'evaluate' ), true ) ) {
				if ( 'active' !== $snapshot['target_state'] ) {
					return Object_Authorization_Result::denied();
				}
				$allowed_states = array(
					'reopen'   => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ),
					'lock'     => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened' ),
					'evaluate' => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ),
				);
				if ( ! in_array( $snapshot['state'], $allowed_states[ $object->action() ], true ) ) {
					return Object_Authorization_Result::conflict( Assignment_Failure::STATE_CONFLICT );
				}
				if (
					( 'reopen' === $object->action() && 'open' !== $snapshot['assignment_state'] )
					|| ( in_array( $object->action(), array( 'lock', 'evaluate' ), true ) && ! in_array( $snapshot['assignment_state'], array( 'open', 'closed' ), true ) )
				) {
					return Object_Authorization_Result::conflict( Assignment_Failure::STATE_CONFLICT );
				}
			}
			return Object_Authorization_Result::allowed(
				$snapshot['current_revision'],
				$snapshot['state'],
				'context_staff'
			);
		}
		if (
			Object_Scope::TYPE_SUBMISSION !== $object->type()
			|| ! in_array( $object->action(), array( 'self_view', 'autosave', 'stage_attachment', 'remove_attachment', 'submit', 'export_pdf' ), true )
			|| null === $context
			|| ! $context->is_allowed()
		) {
			return Object_Authorization_Result::unavailable();
		}
		$snapshot = $this->submissions->submission_authorization_snapshot( $object->id(), $actor_user_id );
		if (
			null === $snapshot
			|| 'active' !== $snapshot['target_state']
			|| 'withdrawn' === $snapshot['assignment_state']
			|| $snapshot['context_type'] !== $context->type()
			|| $snapshot['context_id'] !== $context->id()
		) {
			return Object_Authorization_Result::denied();
		}
		$submit_action = 'submit' === $object->action();
		$write_action  = in_array( $object->action(), array( 'autosave', 'stage_attachment', 'remove_attachment', 'submit' ), true );
		if ( $write_action && ! $submit_action && 'open' !== $snapshot['assignment_state'] ) {
			return Object_Authorization_Result::conflict( Assignment_Failure::STATE_CONFLICT );
		}
		if ( $write_action && ! $submit_action && ! in_array( $snapshot['state'], array( 'draft', 'conflict', 'reopened' ), true ) ) {
			return Object_Authorization_Result::conflict( Assignment_Failure::SUBMISSION_ALREADY_OFFICIAL );
		}

		return Object_Authorization_Result::allowed(
			$snapshot['current_revision'],
			$snapshot['state'],
			'self_submission'
		);
	}
}
