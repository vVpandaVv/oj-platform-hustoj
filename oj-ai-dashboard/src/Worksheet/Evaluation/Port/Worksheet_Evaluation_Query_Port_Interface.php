<?php
/**
 * Worksheet self-evaluation query boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet self-evaluation query boundary.
 */
interface Worksheet_Evaluation_Query_Port_Interface {
	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @param bool   $latest_only Latest only.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list_owned(
		int $actor_user_id,
		string $state,
		string $worksheet_id,
		string $revision_id,
		string $cursor,
		int $limit,
		bool $latest_only = false
	): array;

	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned( int $actor_user_id, string $evaluation_id ): ?array;

	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $evaluation_id Evaluation id.
	 * @return array{current_revision:string,state:string,relation:string}|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $evaluation_id ): ?array;
}
