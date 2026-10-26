<?php
/**
 * Legacy source blob.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-memory-only descriptor for source bytes; paths never enter migration receipts.
 */
final class Legacy_Source_Blob {
	/**
	 * Initialize legacy source blob dependencies and state.
	 *
	 * @param int    $file_id File id.
	 * @param string $path Path.
	 * @param string $original_name Original name.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $file_id,
		private string $path,
		private string $original_name,
		private int $byte_size,
		private string $sha256
	) {
		if (
			$file_id < 1
			|| '' === $path
			|| '' === $original_name
			|| basename( str_replace( '\\', '/', $original_name ) ) !== $original_name
			|| strlen( $original_name ) > 255
			|| 1 === preg_match( '/[\x00-\x1F\x7F]/', $original_name )
			|| $byte_size < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
		) {
			throw new InvalidArgumentException( 'Legacy source blob is invalid.' );
		}
	}

	/**
	 * File id.
	 *
	 * @return int
	 */
	public function file_id(): int {
		return $this->file_id;
	}
	/**
	 * Path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}
	/**
	 * Original name.
	 *
	 * @return string
	 */
	public function original_name(): string {
		return $this->original_name;
	}
	/**
	 * Byte size.
	 *
	 * @return int
	 */
	public function byte_size(): int {
		return $this->byte_size;
	}
	/**
	 * Sha256.
	 *
	 * @return string
	 */
	public function sha256(): string {
		return $this->sha256;
	}

	/**
	 * Same bytes.
	 *
	 * @param self $other Other.
	 * @return bool
	 */
	public function same_bytes( self $other ): bool {
		return $this->file_id === $other->file_id
			&& hash_equals( $this->path, $other->path )
			&& $this->byte_size === $other->byte_size
			&& hash_equals( $this->sha256, $other->sha256 );
	}
}
