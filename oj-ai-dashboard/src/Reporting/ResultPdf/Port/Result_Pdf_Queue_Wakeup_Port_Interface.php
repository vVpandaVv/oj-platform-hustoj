<?php
/**
 * Result pdf queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Result pdf queue wakeup port interface.
 */
interface Result_Pdf_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
