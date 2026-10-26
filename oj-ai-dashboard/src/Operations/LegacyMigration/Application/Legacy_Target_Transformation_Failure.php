<?php
/**
 * Legacy target transformation failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A safe per-item transform failure that may be committed without partial target writes.
 */
final class Legacy_Target_Transformation_Failure extends RuntimeException {
	public const UNSUPPORTED        = 'legacy_transform_unsupported';
	public const SOURCE_INVALID     = 'legacy_transform_source_invalid';
	public const DEPENDENCY_MISSING = 'legacy_transform_dependency_missing';

	private const REASONS = array( self::UNSUPPORTED, self::SOURCE_INVALID, self::DEPENDENCY_MISSING );

	/**
	 * Initialize legacy target transformation failure dependencies and state.
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
		return new self( in_array( $reason, self::REASONS, true ) ? $reason : self::UNSUPPORTED );
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
