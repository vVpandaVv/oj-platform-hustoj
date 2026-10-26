<?php
/**
 * WordPress learner display snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Infrastructure;

use OJAI\Dashboard\Submission\Assignment\Application\Assignment_Failure;
use OJAI\Dashboard\Submission\Assignment\Port\Learner_Display_Snapshot_Port_Interface;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Reads only the same-site display name; login and email are never snapshotted.
 */
final class WordPress_Learner_Display_Snapshot implements Learner_Display_Snapshot_Port_Interface {
	/**
	 * Snapshot.
	 *
	 * @param int $learner_user_id Learner user id.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function snapshot( int $learner_user_id ): array {
		if ( $learner_user_id < 1 || ! function_exists( 'get_userdata' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::DISPLAY_UNAVAILABLE );
		}
		$user = get_userdata( $learner_user_id );
		$name = is_object( $user ) && isset( $user->display_name ) ? trim( (string) $user->display_name ) : '';
		if ( '' === $name || strlen( $name ) > 255 || 1 !== preg_match( '//u', $name ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Propagates an exception object; presentation boundaries map stable errors and escape displayed text.
			throw Assignment_Failure::because( Assignment_Failure::DISPLAY_UNAVAILABLE );
		}
		return array( 'display_name' => $name );
	}
}
