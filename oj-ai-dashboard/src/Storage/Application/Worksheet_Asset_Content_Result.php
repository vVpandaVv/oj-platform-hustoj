<?php
/**
 * Authorized private worksheet asset content.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorized private worksheet asset content.
 */
final class Worksheet_Asset_Content_Result {
	/**
	 * Initialize worksheet asset content result dependencies and state.
	 *
	 * @param string $path Path.
	 * @param string $original_name Original name.
	 * @param string $mime_type Mime type.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $path,
		private string $original_name,
		private string $mime_type,
		private int $byte_size,
		private string $sha256
	) {
		if (
			'' === $path || '' === $original_name || strlen( $original_name ) > 255
			|| '' === $mime_type || strlen( $mime_type ) > 191 || $byte_size < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
		) {
			throw new InvalidArgumentException( 'Worksheet asset content result is invalid.' );
		}
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
	 * Mime type.
	 *
	 * @return string
	 */
	public function mime_type(): string {
		return $this->mime_type;
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
}
