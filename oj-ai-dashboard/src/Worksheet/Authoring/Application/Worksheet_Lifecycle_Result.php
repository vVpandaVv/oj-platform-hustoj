<?php
/**
 * ArchiveWorksheet and RestoreWorksheet receipt.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Authoring\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ArchiveWorksheet and RestoreWorksheet receipt.
 */
final class Worksheet_Lifecycle_Result {
	/**
	 * Initialize worksheet lifecycle result dependencies and state.
	 *
	 * @param string      $operation Operation.
	 * @param string      $worksheet_id Worksheet public identifier.
	 * @param string      $previous_state Previous state.
	 * @param string      $state State.
	 * @param string      $current_revision_id Current revision id.
	 * @param string|null $latest_publication_id Latest publication id.
	 * @param int         $lock_version Lock version.
	 * @param string|null $archived_at_utc Archived at utc.
	 * @param int         $changed_by_user_id Changed by user id.
	 * @param string      $changed_at_utc Changed at utc.
	 * @param bool        $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $operation,
		private string $worksheet_id,
		private string $previous_state,
		private string $state,
		private string $current_revision_id,
		private ?string $latest_publication_id,
		private int $lock_version,
		private ?string $archived_at_utc,
		private int $changed_by_user_id,
		private string $changed_at_utc,
		private bool $replayed
	) {
		new Public_Id( $worksheet_id );
		new Public_Id( $current_revision_id );
		if ( null !== $latest_publication_id ) {
			new Public_Id( $latest_publication_id );
		}
		if ( $lock_version < 1 || $changed_by_user_id < 1 ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle receipt identity is invalid.' );
		}
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $changed_at_utc ) ) {
			throw new InvalidArgumentException( 'Worksheet lifecycle receipt UTC value is invalid.' );
		}
		if (
			( Worksheet_Lifecycle_Command::ARCHIVE === $operation
				&& ( ! in_array( $previous_state, array( 'draft', 'active' ), true ) || 'archived' !== $state || null === $archived_at_utc ) )
			|| ( Worksheet_Lifecycle_Command::ARCHIVE === $operation
				&& ( ( 'draft' === $previous_state && null !== $latest_publication_id )
					|| ( 'active' === $previous_state && null === $latest_publication_id ) ) )
			|| ( Worksheet_Lifecycle_Command::RESTORE === $operation
				&& ( 'archived' !== $previous_state || ! in_array( $state, array( 'draft', 'active' ), true ) || null !== $archived_at_utc ) )
			|| ( null !== $archived_at_utc && 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $archived_at_utc ) )
			|| ( 'draft' === $state && null !== $latest_publication_id )
			|| ( 'active' === $state && null === $latest_publication_id )
		) {
			throw new InvalidArgumentException( 'Worksheet lifecycle receipt transition is invalid.' );
		}
	}

	/**
	 * Operation.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}
	/**
	 * Worksheet id.
	 *
	 * @return string
	 */
	public function worksheet_id(): string {
		return $this->worksheet_id;
	}
	/**
	 * Previous state.
	 *
	 * @return string
	 */
	public function previous_state(): string {
		return $this->previous_state;
	}
	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}
	/**
	 * Current revision id.
	 *
	 * @return string
	 */
	public function current_revision_id(): string {
		return $this->current_revision_id;
	}
	/**
	 * Latest publication id.
	 *
	 * @return string|null
	 */
	public function latest_publication_id(): ?string {
		return $this->latest_publication_id;
	}
	/**
	 * Lock version.
	 *
	 * @return int
	 */
	public function lock_version(): int {
		return $this->lock_version;
	}
	/**
	 * Archived at utc.
	 *
	 * @return string|null
	 */
	public function archived_at_utc(): ?string {
		return $this->archived_at_utc;
	}
	/**
	 * Changed by user id.
	 *
	 * @return int
	 */
	public function changed_by_user_id(): int {
		return $this->changed_by_user_id;
	}
	/**
	 * Changed at utc.
	 *
	 * @return string
	 */
	public function changed_at_utc(): string {
		return $this->changed_at_utc;
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
