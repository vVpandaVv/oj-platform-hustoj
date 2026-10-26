<?php
/**
 * Worksheet evaluation queue wakeup port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation queue wakeup port interface.
 */
interface Worksheet_Evaluation_Queue_Wakeup_Port_Interface {
	/**
	 * Wake.
	 *
	 * @return void
	 */
	public function wake(): void;
}
