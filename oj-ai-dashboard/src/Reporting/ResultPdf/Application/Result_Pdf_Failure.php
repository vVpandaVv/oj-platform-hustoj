<?php
/**
 * Result pdf failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable failure reasons shared by the result PDF pipeline.
 */
final class Result_Pdf_Failure extends RuntimeException {
	public const NOT_FOUND            = 'result_pdf_not_found';
	public const SOURCE_STALE         = 'result_pdf_source_stale';
	public const SOURCE_INVALID       = 'result_pdf_source_invalid';
	public const STORAGE_UNAVAILABLE  = 'result_pdf_storage_unavailable';
	public const ACCESS_DENIED        = 'result_pdf_access_denied';
	public const IDEMPOTENCY_CONFLICT = 'result_pdf_idempotency_conflict';
	public const TOO_LARGE            = 'result_pdf_too_large';
	public const RENDER_FAILED        = 'result_pdf_render_failed';
	public const STORED_DATA_INVALID  = 'result_pdf_stored_data_invalid';
	public const EXPIRED              = 'result_pdf_expired';
	public const NOT_READY            = 'result_pdf_not_ready';

	/**
	 * Initialize result pdf failure dependencies and state.
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
