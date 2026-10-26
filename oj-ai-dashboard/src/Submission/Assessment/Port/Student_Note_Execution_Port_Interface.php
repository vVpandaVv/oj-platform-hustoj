<?php
/**
 * Student note execution port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Student note execution port interface.
 */
interface Student_Note_Execution_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Submission_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Submission_Evaluation_Routing_Snapshot;
	/**
	 * Execute student note execution port interface.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return Submission_Evaluation_Gateway_Result
	 */
	public function execute( Student_Note_Work_Item $item ): Submission_Evaluation_Gateway_Result;
}
