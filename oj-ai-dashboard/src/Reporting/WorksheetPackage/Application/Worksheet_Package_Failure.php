<?php
/**
 * Worksheet package failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Worksheet package failure.
 */
final class Worksheet_Package_Failure extends RuntimeException {
	public const NOT_FOUND               = 'worksheet_package_not_found';
	public const SOURCE_STALE            = 'worksheet_package_source_stale';
	public const SOURCE_INVALID          = 'worksheet_package_source_invalid';
	public const IDEMPOTENCY_CONFLICT    = 'worksheet_package_idempotency_conflict';
	public const ACCESS_DENIED           = 'worksheet_package_access_denied';
	public const ENVIRONMENT_UNSUPPORTED = 'worksheet_package_environment_unsupported';
	public const TOO_LARGE               = 'worksheet_package_too_large';
	public const ARCHIVE_FAILED          = 'worksheet_package_archive_failed';
	public const STORAGE_UNAVAILABLE     = 'worksheet_package_storage_unavailable';
	public const STORED_DATA_INVALID     = 'worksheet_package_stored_data_invalid';

	/**
	 * Initialize worksheet package failure dependencies and state.
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
