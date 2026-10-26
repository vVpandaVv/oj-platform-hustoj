<?php
/**
 * Assignment roster port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Port;

use OJAI\Dashboard\Submission\Assignment\Domain\Assignment_Roster_Snapshot;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Assignment roster port interface.
 */
interface Assignment_Roster_Port_Interface {
	/**
	 * Collect.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $expected_context_revision Expected context revision.
	 * @return Assignment_Roster_Snapshot
	 */
	public function collect(
		int $actor_user_id,
		string $context_type,
		string $context_id,
		string $expected_context_revision
	): Assignment_Roster_Snapshot;
}
