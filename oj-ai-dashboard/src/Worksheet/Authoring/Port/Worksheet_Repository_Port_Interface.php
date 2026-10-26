<?php
/**
 * Worksheet authoring repository port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

use OJAI\Dashboard\Worksheet\Authoring\Application\Create_Worksheet_Result;
use OJAI\Dashboard\Worksheet\Authoring\Application\Save_Worksheet_Draft_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns Worksheet SQL and returns only public projections.
 */
interface Worksheet_Repository_Port_Interface {
	/**
	 * Create initial.
	 *
	 * @param array<string,mixed> $record Validated initial aggregate record.
	 * @return Create_Worksheet_Result
	 */
	public function create_initial( array $record ): Create_Worksheet_Result;

	/**
	 * Append draft revision.
	 *
	 * @param array<string,mixed> $record Validated draft revision record and projections.
	 * @return Save_Worksheet_Draft_Result
	 */
	public function append_draft_revision( array $record ): Save_Worksheet_Draft_Result;

	/**
	 * List owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $state State.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}
	 */
	public function list_owned( int $actor_user_id, string $state, string $cursor, int $limit ): array;

	/**
	 * Find owned worksheet.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_worksheet( int $actor_user_id, string $worksheet_id ): ?array;

	/**
	 * List owned revisions.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $cursor Continuation cursor from the previous page.
	 * @param int    $limit Maximum number of entries requested.
	 * @return array{items:array<int,array<string,mixed>>,next_cursor:string}|null
	 */
	public function list_owned_revisions(
		int $actor_user_id,
		string $worksheet_id,
		string $cursor,
		int $limit
	): ?array;

	/**
	 * Find owned revision.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return array<string,mixed>|null
	 */
	public function find_owned_revision( int $actor_user_id, string $revision_id ): ?array;

	/**
	 * Authorization snapshot.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $object_type Object type.
	 * @param string $object_id Object id.
	 * @return array{current_revision:string,state:string,relation:string}|null
	 */
	public function authorization_snapshot( int $actor_user_id, string $object_type, string $object_id ): ?array;
}
