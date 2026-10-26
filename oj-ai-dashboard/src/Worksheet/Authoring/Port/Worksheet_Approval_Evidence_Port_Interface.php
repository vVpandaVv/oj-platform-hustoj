<?php
/**
 * Worksheet approval evidence boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet approval evidence boundary.
 */
interface Worksheet_Approval_Evidence_Port_Interface {
	/**
	 * Current.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $worksheet_id Worksheet public identifier.
	 * @param string $revision_id Revision id.
	 * @return array{
	 *   policy:\OJAI\Dashboard\Worksheet\Authoring\Domain\Worksheet_Approval_Policy,
	 *   evaluation_detail:array<string,mixed>|null,
	 *   configuration_guard:array<string,string>,
	 *   evaluation_guard:array<string,mixed>
	 * }
	 */
	public function current( int $actor_user_id, string $worksheet_id, string $revision_id ): array;
}
