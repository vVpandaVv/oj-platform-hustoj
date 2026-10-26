<?php
/**
 * Worksheet pdf render result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet pdf render result.
 */
final class Worksheet_Pdf_Render_Result {
	/**
	 * Initialize worksheet pdf render result dependencies and state.
	 *
	 * @param string $bytes Bytes.
	 * @param int    $page_count Page count.
	 * @param string $renderer_version Renderer version.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $bytes, private int $page_count, private string $renderer_version ) {
		if ( ! str_starts_with( $bytes, '%PDF-' ) || $page_count < 1 || strlen( $renderer_version ) > 32
			|| 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/D', $renderer_version ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF render result is invalid.' );
		}
	}
	/**
	 * Bytes.
	 *
	 * @return string
	 */
	public function bytes(): string {
		return $this->bytes;
	}
	/**
	 * Page count.
	 *
	 * @return int
	 */
	public function page_count(): int {
		return $this->page_count;
	}
	/**
	 * Renderer version.
	 *
	 * @return string
	 */
	public function renderer_version(): string {
		return $this->renderer_version;
	}
}
