<?php
/**
 * Create assignment result.
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
 * Immutable C13 command receipt.
 */
final class Create_Assignment_Result {
	/**
	 * Initialize assignment result dependencies and state.
	 *
	 * @param array<string,mixed> $assignment Assignment.
	 * @param bool                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $assignment, private bool $replayed ) {
		new Public_Id( (string) ( $assignment['id'] ?? '' ) );
		new Public_Id( (string) ( $assignment['worksheet_publication_id'] ?? '' ) );
		if (
			! in_array( $assignment['state'] ?? null, array( 'draft', 'scheduled' ), true )
			|| ! is_int( $assignment['target_count'] ?? null ) || $assignment['target_count'] < 1 || $assignment['target_count'] > 5000
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $assignment['publication_hash'] ?? '' ) )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $assignment['target_snapshot_hash'] ?? '' ) )
		) {
			throw new InvalidArgumentException( 'CreateAssignment result is invalid.' );
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
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
}
