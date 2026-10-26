<?php
/**
 * Storage backlog port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Port;

/**
 * Storage backlog port interface.
 */
interface Storage_Backlog_Port_Interface {
	/**
	 * Inspect.
	 *
	 * @param string $now_utc Now utc.
	 * @return array<string,array{expired:int,held:int,bound:int,eligible?:int,preserved?:int,in_use?:int,blocked?:int}>
	 */
	public function inspect( string $now_utc ): array;
}
