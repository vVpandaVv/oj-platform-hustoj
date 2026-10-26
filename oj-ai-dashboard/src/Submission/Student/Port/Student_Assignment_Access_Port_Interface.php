<?php
/**
 * Student assignment access port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Supplies the minimal local assignment target needed before Context authorization.
 */
interface Student_Assignment_Access_Port_Interface {
	/**
	 * Authorization snapshot.
	 *
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,target_state:string}|null
	 */
	public function authorization_snapshot( string $assignment_id, int $actor_user_id ): ?array;

	/**
	 * Submission authorization snapshot.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{submission_id:string,assignment_id:string,context_type:string,context_id:string,context_revision:string,current_revision:string,state:string,assignment_state:string,target_state:string}|null
	 */
	public function submission_authorization_snapshot( string $submission_id, int $actor_user_id ): ?array;
}
