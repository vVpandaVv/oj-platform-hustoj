<?php
/**
 * Submission pdf queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission pdf queue wakeup port interface.
 */
interface Submission_Pdf_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
