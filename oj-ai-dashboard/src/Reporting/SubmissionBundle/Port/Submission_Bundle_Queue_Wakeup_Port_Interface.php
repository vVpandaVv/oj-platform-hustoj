<?php
/**
 * Submission bundle queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission bundle queue wakeup port interface.
 */
interface Submission_Bundle_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
