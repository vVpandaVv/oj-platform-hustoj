<?php
/**
 * Stable worksheet asset application failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Storage\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps filesystem and database details out of REST responses.
 */
final class Worksheet_Asset_Failure extends RuntimeException {
	public const IDEMPOTENCY_CONFLICT = 'worksheet_asset_idempotency_conflict';
	public const EXPIRED              = 'worksheet_asset_expired';
	public const INVALID_UPLOAD       = 'worksheet_asset_upload_invalid';
	public const LIMIT_EXCEEDED       = 'worksheet_asset_limit_exceeded';
	public const NOT_FOUND            = 'worksheet_asset_not_found';
	public const STORAGE_UNAVAILABLE  = 'worksheet_asset_storage_unavailable';
	public const TARGET_CONFLICT      = 'worksheet_asset_target_conflict';
	public const UNSUPPORTED_MEDIA    = 'worksheet_asset_media_unsupported';

	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Initialize worksheet asset failure dependencies and state.
	 *
	 * @param string $reason Reason.
	 */
	private function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Expired.
	 *
	 * @return self
	 */
	public static function expired(): self {
		return new self( self::EXPIRED );
	}

	/**
	 * Idempotency conflict.
	 *
	 * @return self
	 */
	public static function idempotency_conflict(): self {
		return new self( self::IDEMPOTENCY_CONFLICT );
	}

	/**
	 * Invalid upload.
	 *
	 * @return self
	 */
	public static function invalid_upload(): self {
		return new self( self::INVALID_UPLOAD );
	}

	/**
	 * Limit exceeded.
	 *
	 * @return self
	 */
	public static function limit_exceeded(): self {
		return new self( self::LIMIT_EXCEEDED );
	}

	/**
	 * Not found.
	 *
	 * @return self
	 */
	public static function not_found(): self {
		return new self( self::NOT_FOUND );
	}

	/**
	 * Storage unavailable.
	 *
	 * @return self
	 */
	public static function storage_unavailable(): self {
		return new self( self::STORAGE_UNAVAILABLE );
	}

	/**
	 * Target conflict.
	 *
	 * @return self
	 */
	public static function target_conflict(): self {
		return new self( self::TARGET_CONFLICT );
	}

	/**
	 * Unsupported media.
	 *
	 * @return self
	 */
	public static function unsupported_media(): self {
		return new self( self::UNSUPPORTED_MEDIA );
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
