<?php
/**
 * Schema repair failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Application;

use InvalidArgumentException;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema repair failure.
 */
final class Schema_Repair_Failure extends RuntimeException {
	public const IDEMPOTENCY_CONFLICT = 'schema_repair_idempotency_conflict';
	public const STATE_STALE          = 'schema_repair_state_stale';
	public const NOT_AVAILABLE        = 'schema_repair_not_available';
	public const BACKUP_INVALID       = 'schema_repair_backup_invalid';
	public const LOCK_UNAVAILABLE     = 'schema_repair_lock_unavailable';
	public const BLOCKED              = 'schema_repair_blocked';
	public const POSTCONDITION_FAILED = 'schema_repair_postcondition_failed';
	public const STORAGE_UNAVAILABLE  = 'schema_repair_storage_unavailable';

	private const REASONS = array(
		self::IDEMPOTENCY_CONFLICT,
		self::STATE_STALE,
		self::NOT_AVAILABLE,
		self::BACKUP_INVALID,
		self::LOCK_UNAVAILABLE,
		self::BLOCKED,
		self::POSTCONDITION_FAILED,
		self::STORAGE_UNAVAILABLE,
	);

	/**
	 * Initialize schema repair failure dependencies and state.
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
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function because( string $reason ): self {
		if ( ! in_array( $reason, self::REASONS, true ) ) {
			throw new InvalidArgumentException( 'Unknown schema repair failure reason.' );
		}

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
