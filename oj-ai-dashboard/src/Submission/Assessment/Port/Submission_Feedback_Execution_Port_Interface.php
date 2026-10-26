<?php
/**
 * Submission feedback execution port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Gateway_Result;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Submission feedback execution port interface.
 */
interface Submission_Feedback_Execution_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @param string $task_key Task key.
	 * @return Submission_Evaluation_Routing_Snapshot|null
	 */
	public function snapshot( string $task_key ): ?Submission_Evaluation_Routing_Snapshot;
	/**
	 * Execute submission feedback execution port interface.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @return Submission_Evaluation_Gateway_Result
	 */
	public function execute( Submission_Feedback_Work_Item $item ): Submission_Evaluation_Gateway_Result;
}
