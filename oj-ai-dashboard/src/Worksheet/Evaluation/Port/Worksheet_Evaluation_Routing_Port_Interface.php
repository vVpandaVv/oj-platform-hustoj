<?php
/**
 * Worksheet evaluation routing port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation routing port interface.
 */
interface Worksheet_Evaluation_Routing_Port_Interface {
	/**
	 * Plan.
	 *
	 * @return Worksheet_Evaluation_Routing_Snapshot
	 */
	public function plan(): Worksheet_Evaluation_Routing_Snapshot;
}
