<?php
/**
 * Claimed private file for deferred submission ZIP cleanup.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries the exact root, item, and private-file locks for one purge attempt.
 */
final class Submission_Zip_Cleanup_Item {
	/**
	 * Initialize submission zip cleanup item dependencies and state.
	 *
	 * @param array<string,mixed> $record Record.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $record ) {
		$expected = array(
			'assignment_id',
			'claimed_at_utc',
			'file_id',
			'file_kind',
			'file_lock_version',
			'file_owner_public_id',
			'file_owner_type',
			'file_state',
			'item_no',
			'owner_user_id',
			'scan_state',
			'sha256',
			'storage_key',
			'upload_id',
			'upload_lock_version',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup item shape is invalid.' );
		}

		foreach ( array( 'assignment_id', 'file_id', 'file_owner_public_id', 'upload_id' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission ZIP cleanup public ID is invalid.' );
			}
			new Public_Id( $record[ $field ] );
		}
		if (
			! is_int( $record['upload_lock_version'] ) || $record['upload_lock_version'] < 0
			|| ! is_int( $record['file_lock_version'] ) || $record['file_lock_version'] < 0
			|| ! is_int( $record['owner_user_id'] ) || $record['owner_user_id'] < 1
		) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup lock or owner is invalid.' );
		}
		if ( ! in_array( $record['file_kind'], array( 'source', 'item' ), true ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup file kind is invalid.' );
		}
		if ( 'source' === $record['file_kind'] ) {
			if (
				null !== $record['item_no'] || 'assignment' !== $record['file_owner_type']
				|| ! hash_equals( $record['assignment_id'], $record['file_owner_public_id'] )
			) {
				throw new InvalidArgumentException( 'Submission ZIP source cleanup ownership is invalid.' );
			}
		} elseif (
			! is_int( $record['item_no'] ) || $record['item_no'] < 1
			|| 'submission_zip' !== $record['file_owner_type']
			|| ! hash_equals( $record['upload_id'], $record['file_owner_public_id'] )
		) {
			throw new InvalidArgumentException( 'Submission ZIP item cleanup ownership is invalid.' );
		}
		if ( ! is_string( $record['file_state'] ) || ! in_array( $record['file_state'], array( 'quarantined', 'ready', 'rejected' ), true ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup file state is invalid.' );
		}
		if ( ! is_string( $record['scan_state'] ) || ! in_array( $record['scan_state'], array( 'pending', 'clean', 'rejected' ), true ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup scan state is invalid.' );
		}
		if ( ! is_string( $record['sha256'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup hash is invalid.' );
		}
		if (
			! is_string( $record['storage_key'] ) || strlen( $record['storage_key'] ) > 512
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $record['storage_key'] )
			|| in_array( '..', explode( '/', $record['storage_key'] ), true )
		) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup storage key is invalid.' );
		}
		if ( ! is_string( $record['claimed_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $record['claimed_at_utc'] ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup time is invalid.' );
		}
	}

	/**
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->record['assignment_id'];
	}
	/**
	 * Claimed at utc.
	 *
	 * @return string
	 */
	public function claimed_at_utc(): string {
		return $this->record['claimed_at_utc'];
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
	 * File kind.
	 *
	 * @return string
	 */
	public function file_kind(): string {
		return $this->record['file_kind'];
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
	 * File owner public id.
	 *
	 * @return string
	 */
	public function file_owner_public_id(): string {
		return $this->record['file_owner_public_id'];
	}
	/**
	 * File owner type.
	 *
	 * @return string
	 */
	public function file_owner_type(): string {
		return $this->record['file_owner_type'];
	}
	/**
	 * File state.
	 *
	 * @return string
	 */
	public function file_state(): string {
		return $this->record['file_state'];
	}
	/**
	 * Item no.
	 *
	 * @return int|null
	 */
	public function item_no(): ?int {
		return $this->record['item_no'];
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
	 * Scan state.
	 *
	 * @return string
	 */
	public function scan_state(): string {
		return $this->record['scan_state'];
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
	 * Storage key.
	 *
	 * @return string
	 */
	public function storage_key(): string {
		return $this->record['storage_key'];
	}
	/**
	 * Upload id.
	 *
	 * @return string
	 */
	public function upload_id(): string {
		return $this->record['upload_id'];
	}
	/**
	 * Upload lock version.
	 *
	 * @return int
	 */
	public function upload_lock_version(): int {
		return $this->record['upload_lock_version'];
	}
}
