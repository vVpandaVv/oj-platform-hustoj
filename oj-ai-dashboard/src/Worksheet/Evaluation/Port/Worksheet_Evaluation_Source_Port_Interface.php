<?php
/**
 * Worksheet evaluation source port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

use OJAI\Dashboard\Worksheet\Evaluation\Domain\Worksheet_Evaluation_Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet evaluation source port interface.
 */
interface Worksheet_Evaluation_Source_Port_Interface {
	/**
	 * Find owned.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $revision_id Revision id.
	 * @return Worksheet_Evaluation_Source|null
	 */
	public function find_owned( int $actor_user_id, string $revision_id ): ?Worksheet_Evaluation_Source;
}
