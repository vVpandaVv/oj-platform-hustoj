<?php
/**
 * Current learner Context query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the actor's current class and group participation.
 */
interface Learning_Context_Query_Port_Interface {
	/**
	 * For actor.
	 *
	 * @param int $actor_user_id Authenticated actor from the access context.
	 * @return array<string,array{type:string,id:string,label:string,lifecycle_state:string,revision:string,attributes:array<string,bool|int|float|string|null>}>
	 */
	public function for_actor( int $actor_user_id ): array;
}
