<?php
/**
 * Worksheet package queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet package queue wakeup port interface.
 */
interface Worksheet_Package_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
