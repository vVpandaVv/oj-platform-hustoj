<?php
/**
 * Submission pdf failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submission pdf failure.
 */
final class Submission_Pdf_Failure extends RuntimeException {
	public const NOT_FOUND            = 'submission_pdf_not_found';
	public const SOURCE_STALE         = 'submission_pdf_source_stale';
	public const SOURCE_INVALID       = 'submission_pdf_source_invalid';
	public const IDEMPOTENCY_CONFLICT = 'submission_pdf_idempotency_conflict';
	public const ACCESS_DENIED        = 'submission_pdf_access_denied';
	public const EXPIRED              = 'submission_pdf_expired';
	public const NOT_READY            = 'submission_pdf_not_ready';
	public const RENDER_FAILED        = 'submission_pdf_render_failed';
	public const TOO_LARGE            = 'submission_pdf_too_large';
	public const STORAGE_UNAVAILABLE  = 'submission_pdf_storage_unavailable';
	public const STORED_DATA_INVALID  = 'submission_pdf_stored_data_invalid';

	/**
	 * Initialize submission pdf failure dependencies and state.
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
