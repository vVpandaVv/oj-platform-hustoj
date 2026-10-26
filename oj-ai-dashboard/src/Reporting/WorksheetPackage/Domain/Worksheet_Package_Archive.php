<?php
/**
 * Worksheet package archive.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package archive.
 */
final class Worksheet_Package_Archive {
	/**
	 * Initialize worksheet package archive dependencies and state.
	 *
	 * @param string $path Path.
	 * @param int    $byte_size Byte size.
	 * @param string $sha256 Sha256.
	 * @param string $exporter_version Exporter version.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $path, private int $byte_size, private string $sha256, private string $exporter_version ) {
		if ( ! is_file( $path ) || $byte_size < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $exporter_version ) ) {
			throw new InvalidArgumentException( 'Worksheet package archive is invalid.' );
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
	 * Exporter version.
	 *
	 * @return string
	 */
	public function exporter_version(): string {
		return $this->exporter_version;
	}
}
