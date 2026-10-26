<?php
/**
 * Worksheet pdf failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet pdf failure.
 */
final class Worksheet_Pdf_Failure extends RuntimeException {
	public const NOT_FOUND            = 'worksheet_pdf_not_found';
	public const SOURCE_STALE         = 'worksheet_pdf_source_stale';
	public const SOURCE_INVALID       = 'worksheet_pdf_source_invalid';
	public const IDEMPOTENCY_CONFLICT = 'worksheet_pdf_idempotency_conflict';
	public const ACCESS_DENIED        = 'worksheet_pdf_access_denied';
	public const EXPIRED              = 'worksheet_pdf_expired';
	public const NOT_READY            = 'worksheet_pdf_not_ready';
	public const RENDER_FAILED        = 'worksheet_pdf_render_failed';
	public const TOO_LARGE            = 'worksheet_pdf_too_large';
	public const STORAGE_UNAVAILABLE  = 'worksheet_pdf_storage_unavailable';
	public const STORED_DATA_INVALID  = 'worksheet_pdf_stored_data_invalid';

	/**
	 * Initialize worksheet pdf failure dependencies and state.
	 *
	 * @param string $reason Reason.
	 */
	private function __construct( private string $reason ) { // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found -- Constructor promotion stores domain state and private construction enforces factory use; this override is not redundant.
		parent::__construct( $reason );
	}
	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string $reason Reason.
	 * @return self
	 */
	public static function because( string $reason ): self {
		return new self( $reason );
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}
}
