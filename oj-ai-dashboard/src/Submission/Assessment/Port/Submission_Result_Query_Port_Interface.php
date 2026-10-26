<?php
/**
 * Student result query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the authenticated learner's current release.
 */
interface Submission_Result_Query_Port_Interface {
	/**
	 * Authorization snapshot.
	 *
	 * @param string $release_id Release id.
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @return array{release_id:string,release_hash:string,release_state:string,submission_id:string,context_type:string,context_id:string,context_revision:string,assignment_state:string,target_state:string}|null
	 */
	public function authorization_snapshot( string $release_id, int $actor_user_id ): ?array;

	/**
	 * Find current for actor.
	 *
	 * @param string                                                                                                                               $release_id Release id.
	 * @param int                                                                                                                                  $actor_user_id Authenticated actor from the access context.
	 * @param array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>} $context Access or execution context for this operation.
	 * @return array<string,mixed>|null
	 */
	public function find_current_for_actor( string $release_id, int $actor_user_id, array $context ): ?array;
}
