<?php
/**
 * Report export failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ReportExport\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable C46/C47 enqueue failure reasons.
 */
final class Report_Export_Failure extends RuntimeException {
	public const NOT_FOUND            = 'report_export_not_found';
	public const SOURCE_STALE         = 'report_export_source_stale';
	public const SOURCE_ACCESS_DENIED = 'report_export_source_access_denied';
	public const PROFILE_UNSUPPORTED  = 'report_export_profile_unsupported';
	public const SOURCE_INVALID       = 'report_export_source_invalid';
	public const IDEMPOTENCY_CONFLICT = 'report_export_idempotency_conflict';
	public const TOO_LARGE            = 'report_export_too_large';
	public const STORED_DATA_INVALID  = 'report_export_stored_data_invalid';
	public const STORAGE_UNAVAILABLE  = 'report_export_storage_unavailable';
	public const RENDER_FAILED        = 'report_export_render_failed';
	public const ARCHIVE_FAILED       = 'report_export_archive_failed';
	public const ZIP_UNAVAILABLE      = 'report_export_zip_unavailable';

	/**
	 * Initialize report export failure dependencies and state.
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
