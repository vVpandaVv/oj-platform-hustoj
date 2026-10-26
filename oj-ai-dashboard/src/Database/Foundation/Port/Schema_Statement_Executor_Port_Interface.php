<?php
/**
 * Forward-only schema statement port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes one validated migration statement without transaction assumptions.
 */
interface Schema_Statement_Executor_Port_Interface {
	/**
	 * Execute schema statement executor port interface.
	 *
	 * @param string $statement Statement.
	 * @return void
	 */
	public function execute( string $statement ): void;
}
