<?php
/**
 * Worksheet evaluation execution consent port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation execution consent port interface.
 */
interface Worksheet_Evaluation_Execution_Consent_Port_Interface {
	/**
	 * Allows.
	 *
	 * @param Worksheet_Evaluation_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Worksheet_Evaluation_Work_Item $item ): bool;
}
