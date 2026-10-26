<?php
/**
 * Worksheet pdf download.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use InvalidArgumentException;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Worksheet pdf download.
 */
final class Worksheet_Pdf_Download {
	/**
	 * Initialize worksheet pdf download dependencies and state.
	 *
	 * @param string $path Path.
	 * @param string $name Name.
	 * @param int    $bytes Bytes.
	 * @param string $sha256 Sha256.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $path, private string $name, private int $bytes, private string $sha256 ) {
		if ( ! is_file( $path ) || $bytes < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $sha256 ) || '' === $name || str_contains( $name, "\r" ) || str_contains( $name, "\n" ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF download is invalid.' );
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
	 * Name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Bytes.
	 *
	 * @return int
	 */
	public function bytes(): int {
		return $this->bytes;
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
