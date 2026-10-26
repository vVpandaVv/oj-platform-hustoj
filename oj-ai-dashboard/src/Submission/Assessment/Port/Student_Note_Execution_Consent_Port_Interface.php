<?php
/**
 * Student note execution consent port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Student_Note_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Student note execution consent port interface.
 */
interface Student_Note_Execution_Consent_Port_Interface {
	/**
	 * Allows.
	 *
	 * @param Student_Note_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Student_Note_Work_Item $item ): bool;
}
