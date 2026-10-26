<?php
/**
 * Change assignment result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Submission\Assignment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Immutable C14-C18 mutation receipt.
 */
final class Change_Assignment_Result {
	/**
	 * Initialize change assignment result dependencies and state.
	 *
	 * @param array<string,mixed> $assignment Assignment.
	 * @param string              $action Action.
	 * @param bool                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $assignment, private string $action, private bool $replayed ) {
		new Public_Id( (string) ( $assignment['id'] ?? '' ) );
		if (
			! in_array( $action, array( 'update_schedule', 'open', 'close', 'reopen', 'withdraw' ), true )
			|| ! in_array( $assignment['state'] ?? null, array( 'draft', 'scheduled', 'open', 'closed', 'withdrawn' ), true )
			|| ! is_int( $assignment['lock_version'] ?? null ) || $assignment['lock_version'] < 1
			|| ! is_int( $assignment['schedule_version'] ?? null ) || $assignment['schedule_version'] < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $assignment['schedule_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'Assignment mutation result is invalid.' );
		}
	}
	/**
	 * Assignment.
	 *
	 * @return array<string,mixed>
	 */
	public function assignment(): array {
		return $this->assignment;
	}
	/**
	 * Action.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
}
