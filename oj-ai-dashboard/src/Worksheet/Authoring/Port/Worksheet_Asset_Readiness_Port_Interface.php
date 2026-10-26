<?php
/**
 * Worksheet revision asset readiness port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet revision asset readiness port.
 */
interface Worksheet_Asset_Readiness_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @param int               $actor_user_id Authenticated actor from the access context.
	 * @param string            $worksheet_id Worksheet public identifier.
	 * @param array<int,string> $asset_ids Asset ids.
	 * @return array{ready:bool,blockers:array<int,array<string,string>>,statistics:array<string,int>}
	 */
	public function inspect( int $actor_user_id, string $worksheet_id, array $asset_ids ): array;
}
