<?php
/**
 * Stable worksheet report query failure.
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
 * Stable worksheet report query failure.
 */
final class Report_Query_Failure extends RuntimeException {
	public const SOURCE_STALE              = 'report_source_stale';
	public const SOURCE_SCHEMA_UNSUPPORTED = 'report_source_schema_unsupported';
	public const STORAGE_UNAVAILABLE       = 'report_storage_unavailable';
	public const STORED_DATA_INVALID       = 'report_stored_data_invalid';
	public const NOT_FOUND                 = 'report_not_found';

	/**
	 * Initialize report query failure dependencies and state.
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
