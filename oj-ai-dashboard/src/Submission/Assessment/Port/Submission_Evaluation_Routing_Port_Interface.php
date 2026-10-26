<?php
/**
 * Submission evaluation routing port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Routing_Snapshot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation routing port interface.
 */
interface Submission_Evaluation_Routing_Port_Interface {
	/**
	 * Plan.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot
	 */
	public function plan(): Submission_Evaluation_Routing_Snapshot;
}
