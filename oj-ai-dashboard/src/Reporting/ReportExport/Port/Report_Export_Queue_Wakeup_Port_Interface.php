<?php
/**
 * Report export queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report export queue wakeup port interface.
 */
interface Report_Export_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
