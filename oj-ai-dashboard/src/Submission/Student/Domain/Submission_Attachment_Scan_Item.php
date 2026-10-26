<?php
/**
 * Claimed submission attachment scan item.
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
 * Carries one private-file lease without exposing a path outside storage ports.
 */
final class Submission_Attachment_Scan_Item {
	/**
	 * Initialize submission attachment scan item dependencies and state.
	 *
	 * @param array<string,mixed> $record Record.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $record ) {
		$expected = array(
			'attachment_byte_size',
			'attachment_id',
			'attachment_lock_version',
			'attachment_mime_type',
			'attachment_original_name',
			'attachment_sha256',
			'claimed_at_utc',
			'file_byte_size',
			'file_id',
			'file_lock_version',
			'file_mime_type',
			'file_original_name',
			'file_sha256',
			'owner_user_id',
			'response_id',
			'storage_key',
			'submission_id',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission attachment scan item shape is invalid.' );
		}

		foreach ( array( 'attachment_id', 'file_id', 'response_id', 'submission_id' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission attachment scan public ID is invalid.' );
			}
			new Public_Id( $record[ $field ] );
		}
		foreach ( array( 'attachment_lock_version', 'file_lock_version' ) as $field ) {
			if ( ! is_int( $record[ $field ] ) || $record[ $field ] < 0 ) {
				throw new InvalidArgumentException( 'Submission attachment scan lock is invalid.' );
			}
		}
		foreach ( array( 'attachment_byte_size', 'file_byte_size', 'owner_user_id' ) as $field ) {
			if ( ! is_int( $record[ $field ] ) || $record[ $field ] < 1 ) {
				throw new InvalidArgumentException( 'Submission attachment scan integer is invalid.' );
			}
		}
		foreach ( array( 'attachment_sha256', 'file_sha256' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission attachment scan hash is invalid.' );
			}
		}
		foreach ( array( 'attachment_mime_type', 'file_mime_type' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*$/D', $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission attachment scan MIME is invalid.' );
			}
		}
		foreach ( array( 'attachment_original_name', 'file_original_name' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) || strlen( $record[ $field ] ) > 255 || str_contains( $record[ $field ], "\0" ) ) {
				throw new InvalidArgumentException( 'Submission attachment scan filename is invalid.' );
			}
		}
		if (
			! is_string( $record['storage_key'] )
			|| strlen( $record['storage_key'] ) > 512
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $record['storage_key'] )
			|| in_array( '..', explode( '/', $record['storage_key'] ), true )
		) {
			throw new InvalidArgumentException( 'Submission attachment scan storage key is invalid.' );
		}
		if ( ! is_string( $record['claimed_at_utc'] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $record['claimed_at_utc'] ) ) {
			throw new InvalidArgumentException( 'Submission attachment scan time is invalid.' );
		}
	}

	/**
	 * Attachment id.
	 *
	 * @return string
	 */
	public function attachment_id(): string {
		return $this->record['attachment_id'];
	}
	/**
	 * Attachment lock version.
	 *
	 * @return int
	 */
	public function attachment_lock_version(): int {
		return $this->record['attachment_lock_version'];
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
	 * Response id.
	 *
	 * @return string
	 */
	public function response_id(): string {
		return $this->record['response_id'];
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
	 * Original name.
	 *
	 * @return string
	 */
	public function original_name(): string {
		return $this->record['attachment_original_name'];
	}
	/**
	 * Mime type.
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return $this->record['attachment_mime_type'];
	}
	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function byte_size(): int {
		return $this->record['attachment_byte_size'];
	}
	/**
	 * Sha256.
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->record['attachment_sha256'];
	}
	/**
	 * File original name.
	 *
	 * @return string
	 */
	public function file_original_name(): string {
		return $this->record['file_original_name'];
	}
	/**
	 * File mime type.
	 *
	 * @return string
	 */
	public function file_mime_type(): string {
		return $this->record['file_mime_type'];
	}
	/**
	 * File byte size.
	 *
	 * @return int
	 */
	public function file_byte_size(): int {
		return $this->record['file_byte_size'];
	}
	/**
	 * File sha256.
	 *
	 * @return string
	 */
	public function file_sha256(): string {
		return $this->record['file_sha256'];
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
	 * Metadata matches.
	 *
	 * @return bool
	 */
	public function metadata_matches(): bool {
		return hash_equals( $this->record['attachment_original_name'], $this->record['file_original_name'] )
			&& hash_equals( $this->record['attachment_mime_type'], $this->record['file_mime_type'] )
			&& $this->record['attachment_byte_size'] === $this->record['file_byte_size']
			&& hash_equals( $this->record['attachment_sha256'], $this->record['file_sha256'] );
	}
}
