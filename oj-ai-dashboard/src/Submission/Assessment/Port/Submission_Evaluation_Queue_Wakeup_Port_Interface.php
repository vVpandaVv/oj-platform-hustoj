<?php
/**
 * Submission evaluation queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation queue wakeup port interface.
 */
interface Submission_Evaluation_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
