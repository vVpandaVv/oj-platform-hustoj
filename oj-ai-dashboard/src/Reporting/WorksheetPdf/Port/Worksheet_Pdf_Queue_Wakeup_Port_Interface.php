<?php
/**
 * Worksheet pdf queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet pdf queue wakeup port interface.
 */
interface Worksheet_Pdf_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
