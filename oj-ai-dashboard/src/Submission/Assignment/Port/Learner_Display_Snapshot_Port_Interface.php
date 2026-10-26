<?php
/**
 * Learner display snapshot port interface.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Learner display snapshot port interface.
 */
interface Learner_Display_Snapshot_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @param int $learner_user_id Learner user id.
	 * @return array{display_name:string}
	 */
	public function snapshot( int $learner_user_id ): array;
}
