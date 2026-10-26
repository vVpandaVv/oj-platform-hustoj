<?php
/**
 * Single submission evaluation enqueue boundary.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Command;
use OJAI\Dashboard\Submission\Assessment\Application\Enqueue_Submission_Evaluation_Result;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets C30 reuse the exact C29 transaction without depending on its adapter graph.
 */
interface Submission_Evaluation_Enqueuer_Port_Interface {
	/**
	 * Execute submission evaluation enqueuer port interface.
	 *
	 * @param Enqueue_Submission_Evaluation_Command $command Command.
	 * @return Enqueue_Submission_Evaluation_Result
	 */
	public function execute( Enqueue_Submission_Evaluation_Command $command ): Enqueue_Submission_Evaluation_Result;
}
