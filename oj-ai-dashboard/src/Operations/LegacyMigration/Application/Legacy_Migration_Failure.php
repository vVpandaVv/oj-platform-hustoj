<?php
/**
 * Legacy migration failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Legacy migration failure.
 */
final class Legacy_Migration_Failure extends RuntimeException {
	public const INPUT_INVALID              = 'legacy_migration_preview_invalid';
	public const APPROVAL_INVALID           = 'legacy_migration_approval_invalid';
	public const EXECUTION_INVALID          = 'legacy_migration_execution_invalid';
	public const ROLLBACK_INVALID           = 'legacy_migration_rollback_invalid';
	public const RUN_NOT_FOUND              = 'legacy_migration_run_not_found';
	public const RUN_STATE_CONFLICT         = 'legacy_migration_run_state_conflict';
	public const RUN_DATA_INVALID           = 'legacy_migration_run_data_invalid';
	public const RUN_PROFILE_UNSUPPORTED    = 'legacy_migration_run_profile_unsupported';
	public const MAPPING_REQUIRED           = 'legacy_migration_mapping_required';
	public const SOURCE_CONFLICT            = 'legacy_migration_source_conflict';
	public const SOURCE_PROFILE_UNSUPPORTED = 'legacy_source_profile_unsupported';
	public const SOURCE_PROFILE_MISMATCH    = 'legacy_source_profile_mismatch';
	public const SOURCE_COLLISION           = 'legacy_collision_requires_cutover';
	public const SOURCE_LIMIT_EXCEEDED      = 'legacy_source_limit_exceeded';
	public const SOURCE_UNAVAILABLE         = 'legacy_source_unavailable';
	public const SOURCE_STALE               = 'legacy_source_stale';
	public const SITE_UNAVAILABLE           = 'legacy_migration_site_unavailable';
	public const TOKEN_UNAVAILABLE          = 'legacy_preview_token_unavailable';
	public const TOKEN_INVALID              = 'legacy_preview_token_invalid';
	public const TOKEN_EXPIRED              = 'legacy_preview_token_expired';
	public const TOKEN_SCOPE_MISMATCH       = 'legacy_preview_token_scope_mismatch';
	public const IDEMPOTENCY_CONFLICT       = 'legacy_migration_idempotency_conflict';
	public const STORAGE_UNAVAILABLE        = 'legacy_migration_storage_unavailable';
	public const TARGET_CHANGED             = 'legacy_migration_target_changed';

	private const REASONS = array(
		self::INPUT_INVALID,
		self::APPROVAL_INVALID,
		self::EXECUTION_INVALID,
		self::ROLLBACK_INVALID,
		self::RUN_NOT_FOUND,
		self::RUN_STATE_CONFLICT,
		self::MAPPING_REQUIRED,
		self::SOURCE_CONFLICT,
		self::RUN_DATA_INVALID,
		self::RUN_PROFILE_UNSUPPORTED,
		self::SOURCE_PROFILE_UNSUPPORTED,
		self::SOURCE_PROFILE_MISMATCH,
		self::SOURCE_COLLISION,
		self::SOURCE_LIMIT_EXCEEDED,
		self::SOURCE_UNAVAILABLE,
		self::SOURCE_STALE,
		self::SITE_UNAVAILABLE,
		self::TOKEN_UNAVAILABLE,
		self::TOKEN_INVALID,
		self::TOKEN_EXPIRED,
		self::TOKEN_SCOPE_MISMATCH,
		self::IDEMPOTENCY_CONFLICT,
		self::STORAGE_UNAVAILABLE,
		self::TARGET_CHANGED,
	);

	/**
	 * Initialize legacy migration failure dependencies and state.
	 *
	 * @param string         $reason Reason.
	 * @param Throwable|null $previous Previous.
	 */
	private function __construct( private string $reason, ?Throwable $previous = null ) {
		parent::__construct( $reason, 0, $previous );
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string         $reason Reason.
	 * @param Throwable|null $previous Previous.
	 * @return self
	 */
	public static function because( string $reason, ?Throwable $previous = null ): self {
		return new self( in_array( $reason, self::REASONS, true ) ? $reason : self::SOURCE_UNAVAILABLE, $previous );
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
