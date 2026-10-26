<?php
/**
 * Leased assignment auto-submit work item.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Leased assignment auto-submit work item.
 */
final class Auto_Submit_Assignment_Work_Item {
	/**
	 * Initialize auto submit assignment work item dependencies and state.
	 *
	 * @param int    $outbox_pk Outbox pk.
	 * @param int    $outbox_lock_version Outbox lock version.
	 * @param int    $assignment_pk Assignment pk.
	 * @param string $assignment_id Distribution public identifier.
	 * @param int    $owner_user_id Owner user id.
	 * @param string $context_type Context type.
	 * @param string $context_id Context id.
	 * @param string $context_revision Context revision.
	 * @param int    $schedule_version Schedule version.
	 * @param string $schedule_hash Schedule hash.
	 * @param string $due_at_utc Due at utc.
	 * @param string $closes_at_utc Closes at utc.
	 * @param string $lease_token Lease token.
	 * @param int    $candidate_cursor Candidate cursor.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $outbox_pk,
		private int $outbox_lock_version,
		private int $assignment_pk,
		private string $assignment_id,
		private int $owner_user_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private int $schedule_version,
		private string $schedule_hash,
		private string $due_at_utc,
		private string $closes_at_utc,
		private string $lease_token,
		private int $candidate_cursor = 0
	) {
		new Public_Id( $assignment_id );
		if (
			$outbox_pk < 1 || $outbox_lock_version < 1 || $assignment_pk < 1 || $owner_user_id < 1 || $schedule_version < 1 || $candidate_cursor < 0
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $context_type )
			|| '' === $context_id || strlen( $context_id ) > 191 || '' === $context_revision || strlen( $context_revision ) > 191
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $schedule_hash )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $due_at_utc )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $closes_at_utc )
			|| $due_at_utc > $closes_at_utc || 1 !== preg_match( '/^[a-f0-9-]{36}$/D', $lease_token )
		) {
			throw new InvalidArgumentException( 'Auto-submit assignment work item is invalid.' );
		}
	}

	/**
	 * Outbox pk.
	 *
	 * @return int
	 */
	public function outbox_pk(): int {
		return $this->outbox_pk;
	}
	/**
	 * Outbox lock version.
	 *
	 * @return int
	 */
	public function outbox_lock_version(): int {
		return $this->outbox_lock_version;
	}
	/**
	 * Assignment pk.
	 *
	 * @return int
	 */
	public function assignment_pk(): int {
		return $this->assignment_pk;
	}
	/**
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->assignment_id;
	}
	/**
	 * Owner user id.
	 *
	 * @return int
	 */
	public function owner_user_id(): int {
		return $this->owner_user_id;
	}
	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Schedule version.
	 *
	 * @return int
	 */
	public function schedule_version(): int {
		return $this->schedule_version;
	}
	/**
	 * Schedule hash.
	 *
	 * @return string
	 */
	public function schedule_hash(): string {
		return $this->schedule_hash;
	}
	/**
	 * Due at utc.
	 *
	 * @return string
	 */
	public function due_at_utc(): string {
		return $this->due_at_utc;
	}
	/**
	 * Closes at utc.
	 *
	 * @return string
	 */
	public function closes_at_utc(): string {
		return $this->closes_at_utc;
	}
	/**
	 * Lease token.
	 *
	 * @return string
	 */
	public function lease_token(): string {
		return $this->lease_token;
	}
	/**
	 * Candidate cursor.
	 *
	 * @return int
	 */
	public function candidate_cursor(): int {
		return $this->candidate_cursor;
	}
	/**
	 * Advance cursor.
	 *
	 * @param int $cursor Continuation cursor from the previous page.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function advance_cursor( int $cursor ): void {
		if ( $cursor < 0 ) {
			throw new InvalidArgumentException( 'Invalid auto-submit cursor.' );
		}
		$this->candidate_cursor = $cursor;
	}
}
