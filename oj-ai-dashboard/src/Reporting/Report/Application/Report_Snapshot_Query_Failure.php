<?php
/**
 * Stable Q22 report snapshot query failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\Report\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable Q22 report snapshot query failure.
 */
final class Report_Snapshot_Query_Failure extends RuntimeException {
	public const NOT_FOUND                 = 'report_snapshot_not_found';
	public const PROFILE_UNSUPPORTED       = 'report_snapshot_profile_unsupported';
	public const STORED_DATA_INVALID       = 'report_snapshot_stored_data_invalid';
	public const SOURCE_ACCESS_DENIED      = 'report_snapshot_source_access_denied';
	public const SOURCE_ACCESS_UNAVAILABLE = 'report_snapshot_source_access_unavailable';
	public const STORAGE_UNAVAILABLE       = 'report_snapshot_query_storage_unavailable';

	/**
	 * Initialize report snapshot query failure dependencies and state.
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
