<?php
/**
 * Purge failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use RuntimeException;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purge failure.
 */
final class Purge_Failure extends RuntimeException {
	public const INPUT_INVALID           = 'purge_preview_invalid';
	public const INVENTORY_UNAVAILABLE   = 'purge_inventory_unavailable';
	public const SITE_UNAVAILABLE        = 'purge_site_unavailable';
	public const TOKEN_UNAVAILABLE       = 'purge_preview_token_unavailable';
	public const TOKEN_INVALID           = 'purge_preview_token_invalid';
	public const TOKEN_EXPIRED           = 'purge_preview_token_expired';
	public const APPROVAL_INVALID        = 'purge_approval_invalid';
	public const TOKEN_SCOPE_MISMATCH    = 'purge_preview_token_scope_mismatch';
	public const PREVIEW_STALE           = 'purge_preview_stale';
	public const BACKUP_INVALID          = 'purge_backup_invalid';
	public const LEGAL_HOLD_BLOCKED      = 'purge_legal_hold_blocked';
	public const IDEMPOTENCY_CONFLICT    = 'purge_idempotency_conflict';
	public const TOKEN_ALREADY_USED      = 'purge_preview_token_already_used';
	public const EXECUTION_INVALID       = 'purge_execution_invalid';
	public const CANCELLATION_INVALID    = 'purge_cancellation_invalid';
	public const JOB_NOT_FOUND           = 'purge_job_not_found';
	public const JOB_PROFILE_UNSUPPORTED = 'purge_job_profile_unsupported';
	public const JOB_DATA_INVALID        = 'purge_job_data_invalid';
	public const JOB_STATE_CONFLICT      = 'purge_job_state_conflict';
	public const APPROVAL_EXPIRED        = 'purge_approval_expired';
	public const INCOMPLETE_JOBS_BLOCKED = 'purge_incomplete_jobs_blocked';
	public const CHECKPOINT_INVALID      = 'purge_checkpoint_invalid';
	public const MAINTENANCE_UNAVAILABLE = 'purge_maintenance_unavailable';
	public const DELETE_FAILED           = 'purge_delete_failed';
	public const STORAGE_UNAVAILABLE     = 'purge_storage_unavailable';

	private const REASONS = array(
		self::INPUT_INVALID,
		self::INVENTORY_UNAVAILABLE,
		self::SITE_UNAVAILABLE,
		self::TOKEN_UNAVAILABLE,
		self::TOKEN_INVALID,
		self::TOKEN_EXPIRED,
		self::APPROVAL_INVALID,
		self::TOKEN_SCOPE_MISMATCH,
		self::PREVIEW_STALE,
		self::BACKUP_INVALID,
		self::LEGAL_HOLD_BLOCKED,
		self::IDEMPOTENCY_CONFLICT,
		self::TOKEN_ALREADY_USED,
		self::EXECUTION_INVALID,
		self::CANCELLATION_INVALID,
		self::JOB_NOT_FOUND,
		self::JOB_PROFILE_UNSUPPORTED,
		self::JOB_DATA_INVALID,
		self::JOB_STATE_CONFLICT,
		self::APPROVAL_EXPIRED,
		self::INCOMPLETE_JOBS_BLOCKED,
		self::CHECKPOINT_INVALID,
		self::MAINTENANCE_UNAVAILABLE,
		self::DELETE_FAILED,
		self::STORAGE_UNAVAILABLE,
	);

	/**
	 * Initialize purge failure dependencies and state.
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
		return new self( in_array( $reason, self::REASONS, true ) ? $reason : self::INVENTORY_UNAVAILABLE, $previous );
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
