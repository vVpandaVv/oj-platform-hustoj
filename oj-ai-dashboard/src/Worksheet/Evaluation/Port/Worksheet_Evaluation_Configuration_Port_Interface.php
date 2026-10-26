<?php
/**
 * Worksheet evaluation configuration port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation configuration port interface.
 */
interface Worksheet_Evaluation_Configuration_Port_Interface {
	/**
	 * Active.
	 *
	 * @return array<string,mixed>
	 */
	public function active(): array;
}
