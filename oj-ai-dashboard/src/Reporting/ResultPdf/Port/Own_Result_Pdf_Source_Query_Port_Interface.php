<?php
/**
 * Student result PDF source boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads only the authenticated learner's current release through Q33.
 */
interface Own_Result_Pdf_Source_Query_Port_Interface {
	/**
	 * Find current for actor.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $release_id Release id.
	 * @return array{
	 *   release_id:string,
	 *   release_hash:string,
	 *   release_state:string,
	 *   released_at_utc:string,
	 *   projection:?array<string,mixed>,
	 *   projection_hash:?string
	 * }|null
	 */
	public function find_current_for_actor( int $actor_user_id, string $release_id ): ?array;
}
