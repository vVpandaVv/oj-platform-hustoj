<?php
/**
 * Worksheet evaluation execution port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Gateway_Result;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation execution port interface.
 */
interface Worksheet_Evaluation_Execution_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Worksheet_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Worksheet_Evaluation_Routing_Snapshot;
	/**
	 * Execute worksheet evaluation execution port interface.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @return Worksheet_Evaluation_Gateway_Result
	 */
	public function execute( Worksheet_Evaluation_Work_Item $item ): Worksheet_Evaluation_Gateway_Result;
}
