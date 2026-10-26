<?php
/**
 * Submission evaluation source port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assessment\Port;

use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Evaluation_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission evaluation source port interface.
 */
interface Submission_Evaluation_Source_Port_Interface {
	/**
	 * Find official.
	 *
	 * @param string $submission_id Submission public identifier.
	 * @param string $official_revision_id Official revision id.
	 * @param bool   $manual Use original attachments without AI representations.
	 * @return Submission_Evaluation_Source|null
	 */
	public function find_official( string $submission_id, string $official_revision_id, bool $manual = false ): ?Submission_Evaluation_Source;
}
