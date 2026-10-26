<?php
/**
 * Legacy target write result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe target receipt returned after an owner aggregate write.
 */
final class Legacy_Target_Write_Result {
	/**
	 * Initialize legacy target write result dependencies and state.
	 *
	 * @param string                 $target_type Target type.
	 * @param string                 $target_public_id Target public id.
	 * @param string                 $content_hash Content hash.
	 * @param array<array-key,mixed> $metadata Metadata to validate.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $target_type,
		private string $target_public_id,
		private string $content_hash,
		private array $metadata = array()
	) {
		if (
			1 !== preg_match( '/^[a-z][a-z0-9_]{2,63}$/D', $target_type )
			|| ! Public_Id::is_valid( $target_public_id )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $content_hash )
			|| ( array() !== $metadata && array_is_list( $metadata ) )
		) {
			throw new InvalidArgumentException( 'Legacy target write result is invalid.' );
		}
		$this->assert_safe_metadata( $metadata );
	}

	/**
	 * Target type.
	 *
	 * @return string
	 */
	public function target_type(): string {
		return $this->target_type;
	}
	/**
	 * Target public id.
	 *
	 * @return string
	 */
	public function target_public_id(): string {
		return $this->target_public_id;
	}
	/**
	 * Content hash.
	 *
	 * @return string
	 */
	public function content_hash(): string {
		return $this->content_hash;
	}
	/**
	 * Metadata.
	 *
	 * @return array<string,mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Reject invalid safe metadata.
	 *
	 * @param array<array-key,mixed> $metadata Metadata to validate.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function assert_safe_metadata( array $metadata ): void {
		if ( count( $metadata ) > 32 ) {
			throw new InvalidArgumentException( 'Legacy target write metadata is too large.' );
		}
		foreach ( $metadata as $key => $value ) {
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{1,63}$/D', $key ) ) {
				throw new InvalidArgumentException( 'Legacy target write metadata key is invalid.' );
			}
			if ( str_ends_with( $key, '_public_id' ) ) {
				if ( ! is_string( $value ) || ! Public_Id::is_valid( $value ) ) {
					throw new InvalidArgumentException( 'Legacy target write metadata public ID is invalid.' );
				}
				continue;
			}
			if ( str_ends_with( $key, '_hash' ) ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
					throw new InvalidArgumentException( 'Legacy target write metadata hash is invalid.' );
				}
				continue;
			}
			if ( str_ends_with( $key, '_count' ) || str_ends_with( $key, '_number' ) ) {
				if ( ! is_int( $value ) || $value < 0 ) {
					throw new InvalidArgumentException( 'Legacy target write metadata count is invalid.' );
				}
				continue;
			}
			if ( str_ends_with( $key, '_source_key' ) ) {
				if ( ! is_string( $value ) || 1 !== preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) ) {
					throw new InvalidArgumentException( 'Legacy target write metadata source key is invalid.' );
				}
				continue;
			}
			if ( str_ends_with( $key, '_public_ids' ) && is_array( $value ) && count( $value ) <= 250 ) {
				foreach ( $value as $source_key => $public_id ) {
					$safe_source_key = (string) $source_key;
					if (
					1 !== preg_match( '/^(?:(?:0|[1-9][0-9]{0,18})|[a-f0-9]{64})$/D', $safe_source_key )
					|| ! is_string( $public_id ) || ! Public_Id::is_valid( $public_id )
					) {
						throw new InvalidArgumentException( 'Legacy target write metadata public ID map is invalid.' );
					}
				}
				continue;
			}
			if ( str_ends_with( $key, '_hashes' ) && is_array( $value ) && count( $value ) <= 250 ) {
				foreach ( $value as $source_key => $hash ) {
					$safe_source_key = (string) $source_key;
					if (
					1 !== preg_match( '/^(?:(?:0|[1-9][0-9]{0,18})|[a-f0-9]{64})$/D', $safe_source_key )
					|| ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
					) {
						throw new InvalidArgumentException( 'Legacy target write metadata hash map is invalid.' );
					}
				}
				continue;
			}
			throw new InvalidArgumentException( 'Legacy target write metadata field is not allowlisted.' );
		}
	}
}
