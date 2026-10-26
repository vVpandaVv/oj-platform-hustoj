<?php
/**
 * Start own submission result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable C22 result and idempotency receipt projection.
 */
final class Start_Own_Submission_Result {
	/**
	 * Initialize start own submission result dependencies and state.
	 *
	 * @param array<string,mixed> $submission Submission.
	 * @param bool                $created Created.
	 * @param bool                $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $submission, private bool $created, private bool $replayed ) {
		$expected = array( 'actions', 'assignment_id', 'current_revision_id', 'id', 'lock_version', 'official_revision_id', 'save_token', 'started_at_utc', 'state' );
		$actual   = array_keys( $submission );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'StartOwnSubmission result fields are invalid.' );
		}
		new Public_Id( (string) $submission['id'] );
		new Public_Id( (string) $submission['assignment_id'] );
		if (
			'draft' !== $submission['state'] || ! is_int( $submission['lock_version'] ) || $submission['lock_version'] < 0
			|| null !== $submission['current_revision_id'] || null !== $submission['official_revision_id']
			|| ! is_string( $submission['started_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $submission['started_at_utc'] )
			|| ! is_string( $submission['save_token'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $submission['save_token'] )
			|| array( 'submission.autosave' ) !== $submission['actions']
			|| ( $replayed && ! $created )
		) {
			throw new InvalidArgumentException( 'StartOwnSubmission result is invalid.' );
		}
	}

	/**
	 * Submission.
	 *
	 * @return array<string,mixed>
	 */
	public function submission(): array {
		return $this->submission;
	}
	/**
	 * Created.
	 *
	 * @return bool
	 */
	public function created(): bool {
		return $this->created;
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
