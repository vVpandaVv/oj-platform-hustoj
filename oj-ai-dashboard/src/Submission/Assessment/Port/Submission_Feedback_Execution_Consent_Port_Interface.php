<?php
/**
 * Submission feedback execution consent port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Work_Item;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Submission feedback execution consent port interface.
 */
interface Submission_Feedback_Execution_Consent_Port_Interface {
	/**
	 * Allows.
	 *
	 * @param Submission_Feedback_Work_Item $item Item.
	 * @return bool
	 */
	public function allows( Submission_Feedback_Work_Item $item ): bool;
}
