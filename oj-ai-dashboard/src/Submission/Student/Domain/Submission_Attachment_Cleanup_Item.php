<?php
/**
 * Claimed expired submission attachment cleanup item.
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
 * Carries the exact attachment/private-file locks used by one purge attempt.
 */
final class Submission_Attachment_Cleanup_Item {
	/**
	 * Initialize submission attachment cleanup item dependencies and state.
	 *
	 * @param array<string,mixed> $record Record.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $record ) {
		$expected = array(
			'attachment_id',
			'attachment_lock_version',
			'claimed_at_utc',
			'file_id',
			'file_lock_version',
			'owner_user_id',
			'scan_state',
			'sha256',
			'storage_key',
			'submission_id',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup item shape is invalid.' );
		}
		foreach ( array( 'file_id', 'submission_id' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission attachment cleanup public ID is invalid.' );
			}
			new Public_Id( $record[ $field ] );
		}
		if ( null !== $record['attachment_id'] ) {
			if ( ! is_string( $record['attachment_id'] ) || ! is_int( $record['attachment_lock_version'] ) || $record['attachment_lock_version'] < 0 ) {
				throw new InvalidArgumentException( 'Submission attachment cleanup attachment reference is invalid.' );
			}
			new Public_Id( $record['attachment_id'] );
		} elseif ( null !== $record['attachment_lock_version'] ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup attachment lock is invalid.' );
		}
		if ( ! is_int( $record['file_lock_version'] ) || $record['file_lock_version'] < 0 || ! is_int( $record['owner_user_id'] ) || $record['owner_user_id'] < 1 ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup lock or owner is invalid.' );
		}
		if ( ! is_string( $record['scan_state'] ) || ! in_array( $record['scan_state'], array( 'pending', 'clean', 'rejected' ), true ) ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup scan state is invalid.' );
		}
		if ( ! is_string( $record['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup hash is invalid.' );
		}
		if (
			! is_string( $record['storage_key'] ) || strlen( $record['storage_key'] ) > 512
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $record['storage_key'] )
			|| in_array( '..', explode( '/', $record['storage_key'] ), true )
		) {
			throw new InvalidArgumentException( 'Submission attachment cleanup storage key is invalid.' );
		}
		if ( ! is_string( $record['claimed_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $record['claimed_at_utc'] ) ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup time is invalid.' );
		}
	}

	/**
	 * Attachment id.
	 *
	 * @return string|null
	 */
	public function attachment_id(): ?string {
		return $this->record['attachment_id'];
	}
	/**
	 * Attachment lock version.
	 *
	 * @return int|null
	 */
	public function attachment_lock_version(): ?int {
		return $this->record['attachment_lock_version'];
	}
	/**
	 * File id.
	 *
	 * @return string
	 */
	public function file_id(): string {
		return $this->record['file_id'];
	}
	/**
	 * File lock version.
	 *
	 * @return int
	 */
	public function file_lock_version(): int {
		return $this->record['file_lock_version'];
	}
	/**
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->record['submission_id'];
	}
	/**
	 * Owner user id.
	 *
	 * @return int
	 */
	public function owner_user_id(): int {
		return $this->record['owner_user_id'];
	}
	/**
	 * Storage key.
	 *
	 * @return string
	 */
	public function storage_key(): string {
		return $this->record['storage_key'];
	}
	/**
	 * Sha256.
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->record['sha256'];
	}
	/**
	 * Scan state.
	 *
	 * @return string
	 */
	public function scan_state(): string {
		return $this->record['scan_state'];
	}
	/**
	 * Claimed at utc.
	 *
	 * @return string
	 */
	public function claimed_at_utc(): string {
		return $this->record['claimed_at_utc'];
	}
}
