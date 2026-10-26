<?php
/**
 * Claimed asynchronous submission ZIP scan.
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
 * Carries a root lease, immutable source metadata, and frozen mapping candidates.
 */
final class Submission_Zip_Scan_Job {
	/**
	 * Initialize submission zip scan job dependencies and state.
	 *
	 * @param array<string,mixed> $record Record.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private array $record ) {
		$expected = array(
			'actor_user_id',
			'assignment_id',
			'claimed_at_utc',
			'context_id',
			'context_revision',
			'context_type',
			'mapping_hash',
			'mapping_revision',
			'mapping_source',
			'source_byte_size',
			'source_expires_at_utc',
			'source_file_id',
			'source_file_lock_version',
			'source_original_name',
			'source_sha256',
			'source_storage_key',
			'targets',
			'upload_id',
			'upload_lock_version',
			'upload_responses',
		);
		$actual   = array_keys( $record );
		sort( $actual, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'Submission ZIP scan job shape is invalid.' );
		}
		foreach ( array( 'assignment_id', 'source_file_id', 'upload_id' ) as $field ) {
			new Public_Id( is_string( $record[ $field ] ) ? $record[ $field ] : '' );
		}
		foreach ( array( 'actor_user_id', 'source_byte_size' ) as $field ) {
			if ( ! is_int( $record[ $field ] ) || $record[ $field ] < 1 ) {
				throw new InvalidArgumentException( 'Submission ZIP scan integer is invalid.' );
			}
		}
		foreach ( array( 'mapping_revision', 'source_file_lock_version', 'upload_lock_version' ) as $field ) {
			if ( ! is_int( $record[ $field ] ) || $record[ $field ] < 0 ) {
				throw new InvalidArgumentException( 'Submission ZIP scan version is invalid.' );
			}
		}
		foreach ( array( 'mapping_hash', 'source_sha256' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission ZIP scan hash is invalid.' );
			}
		}
		foreach ( array( 'claimed_at_utc', 'source_expires_at_utc' ) as $field ) {
			if ( ! is_string( $record[ $field ] ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $record[ $field ] ) ) {
				throw new InvalidArgumentException( 'Submission ZIP scan time is invalid.' );
			}
		}
		if (
			! is_string( $record['source_original_name'] ) || '' === $record['source_original_name'] || strlen( $record['source_original_name'] ) > 255
			|| ! is_string( $record['source_storage_key'] ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9._\/-]*$/D', $record['source_storage_key'] )
			|| ! is_array( $record['mapping_source'] ) || array_is_list( $record['mapping_source'] )
			|| ! is_array( $record['targets'] ) || ! array_is_list( $record['targets'] )
			|| ! is_array( $record['upload_responses'] ) || ! array_is_list( $record['upload_responses'] )
		) {
			throw new InvalidArgumentException( 'Submission ZIP scan source is invalid.' );
		}
	}

	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->record['actor_user_id'];
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
	/**
	 * Source file id.
	 *
	 * @return string
	 */
	public function source_file_id(): string {
		return $this->record['source_file_id'];
	}
	/**
	 * Source file lock version.
	 *
	 * @return int
	 */
	public function source_file_lock_version(): int {
		return $this->record['source_file_lock_version'];
	}
	/**
	 * Source storage key.
	 *
	 * @return string
	 */
	public function source_storage_key(): string {
		return $this->record['source_storage_key'];
	}
	/**
	 * Source sha256.
	 *
	 * @return string
	 */
	public function source_sha256(): string {
		return $this->record['source_sha256'];
	}
	/**
	 * Source byte size.
	 *
	 * @return int
	 */
	public function source_byte_size(): int {
		return $this->record['source_byte_size'];
	}
	/**
	 * Source original name.
	 *
	 * @return string
	 */
	public function source_original_name(): string {
		return $this->record['source_original_name'];
	}
	/**
	 * Source expires at utc.
	 *
	 * @return string
	 */
	public function source_expires_at_utc(): string {
		return $this->record['source_expires_at_utc'];
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
	 * Mapping hash.
	 *
	 * @return string
	 */
	public function mapping_hash(): string {
		return $this->record['mapping_hash'];
	}
	/**
	 * Mapping revision.
	 *
	 * @return int
	 */
	public function mapping_revision(): int {
		return $this->record['mapping_revision'];
	}
	/**
	 * Mapping source.
	 *
	 * @return array<string,mixed>
	 */
	public function mapping_source(): array {
		return $this->record['mapping_source'];
	}
	/**
	 * Targets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function targets(): array {
		return $this->record['targets'];
	}
	/**
	 * Upload responses.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function upload_responses(): array {
		return $this->record['upload_responses'];
	}
	/**
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->record['context_type'];
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->record['context_id'];
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->record['context_revision'];
	}

	/**
	 * Source metadata matches.
	 *
	 * @return bool
	 */
	public function source_metadata_matches(): bool {
		$source = $this->record['mapping_source'];
		return (int) ( $source['byte_size'] ?? -1 ) === $this->source_byte_size()
			&& hash_equals( (string) ( $source['sha256'] ?? '' ), $this->source_sha256() )
			&& (int) ( $source['entry_count'] ?? 0 ) > 0;
	}
}
