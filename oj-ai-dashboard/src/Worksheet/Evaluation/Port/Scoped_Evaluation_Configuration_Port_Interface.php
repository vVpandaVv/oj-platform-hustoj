<?php
/**
 * Scoped evaluation configuration port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Scoped evaluation configuration port interface.
 */
interface Scoped_Evaluation_Configuration_Port_Interface extends Worksheet_Evaluation_Configuration_Port_Interface {
	/**
	 * For worksheet.
	 *
	 * @param string $worksheet_id Worksheet public identifier.
	 * @return array
	 */
	public function for_worksheet( string $worksheet_id ): array;
	/**
	 * Execution.
	 *
	 * @return array
	 */
	public function execution(): array;
}
