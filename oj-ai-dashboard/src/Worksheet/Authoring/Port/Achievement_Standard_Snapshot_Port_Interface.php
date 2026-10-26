<?php
/**
 * Achievement standard snapshot reader port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads authoritative curriculum values without exposing storage internals.
 */
interface Achievement_Standard_Snapshot_Port_Interface {
	/**
	 * Find snapshots.
	 *
	 * @param array<int,string> $standard_public_ids Sorted unique PublicIdV1 values.
	 * @return array<int,array<string,mixed>>
	 */
	public function find_snapshots( array $standard_public_ids ): array;
}
