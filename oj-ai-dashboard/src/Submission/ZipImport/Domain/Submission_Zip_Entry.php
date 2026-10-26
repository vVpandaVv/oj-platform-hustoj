<?php
/**
 * Validated regular entry in a submission ZIP archive.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only offsets produced by Submission_Zip_Upload_Profile validation.
 */
final class Submission_Zip_Entry {
	/**
	 * Initialize submission zip entry dependencies and state.
	 *
	 * @param int    $item_no Item no.
	 * @param string $relative_path Relative path.
	 * @param int    $method Method.
	 * @param int    $crc32 Crc32.
	 * @param int    $compressed_bytes Compressed bytes.
	 * @param int    $byte_size Byte size.
	 * @param int    $data_offset Data offset.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $item_no,
		private string $relative_path,
		private int $method,
		private int $crc32,
		private int $compressed_bytes,
		private int $byte_size,
		private int $data_offset
	) {
		if (
			$item_no < 1
			|| '' === $relative_path
			|| strlen( $relative_path ) > Submission_Zip_Upload_Profile::MAX_PATH_BYTES
			|| ! in_array( $method, array( 0, 8 ), true )
			|| $crc32 < 0 || $crc32 > 0xffffffff
			|| $compressed_bytes < 0
			|| $byte_size < 0 || $byte_size > Submission_Zip_Upload_Profile::MAX_ENTRY_BYTES
			|| $data_offset < 0
		) {
			throw new InvalidArgumentException( 'Submission ZIP entry is invalid.' );
		}
	}

	/**
	 * Item no.
	 *
	 * @return int
	 */
	public function item_no(): int {
		return $this->item_no;
	}
	/**
	 * Relative path.
	 *
	 * @return string
	 */
	public function relative_path(): string {
		return $this->relative_path;
	}
	/**
	 * Method.
	 *
	 * @return int
	 */
	public function method(): int {
		return $this->method;
	}
	/**
	 * Crc32.
	 *
	 * @return int
	 */
	public function crc32(): int {
		return $this->crc32;
	}
	/**
	 * Compressed bytes.
	 *
	 * @return int
	 */
	public function compressed_bytes(): int {
		return $this->compressed_bytes;
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
	 * Data offset.
	 *
	 * @return int
	 */
	public function data_offset(): int {
		return $this->data_offset;
	}
}
