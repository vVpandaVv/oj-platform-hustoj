<?php
/**
 * Stable submission ZIP import failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use InvalidArgumentException;
use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps archive, storage, and database details out of public responses.
 */
final class Zip_Import_Failure extends RuntimeException {
	public const IDEMPOTENCY_CONFLICT = 'submission_zip_idempotency_conflict';
	public const INVALID_UPLOAD       = 'submission_zip_upload_invalid';
	public const LIMIT_EXCEEDED       = 'submission_zip_limit_exceeded';
	public const MAPPING_INVALID      = 'zip_mapping_invalid';
	public const NOT_FOUND            = 'submission_zip_assignment_not_found';
	public const OFFICIAL_EXISTS      = 'submission_official_exists';
	public const PREVIEW_NOT_READY    = 'zip_preview_not_ready';
	public const STATE_CONFLICT       = 'submission_zip_assignment_conflict';
	public const STORAGE_UNAVAILABLE  = 'submission_zip_storage_unavailable';
	public const STORED_DATA_INVALID  = 'submission_zip_stored_data_invalid';
	public const UNSAFE_ARCHIVE       = 'submission_zip_archive_unsafe';
	public const UNSUPPORTED_ARCHIVE  = 'submission_zip_archive_unsupported';

	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Initialize zip import failure dependencies and state.
	 *
	 * @param string $reason Reason.
	 */
	private function __construct( string $reason ) {
		parent::__construct( $reason );
		$this->reason = $reason;
	}

	/**
	 * Create a domain failure carrying the specified reason.
	 *
	 * @param string $reason Reason.
	 * @return self
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public static function because( string $reason ): self {
		if ( ! in_array( $reason, self::reasons(), true ) ) {
			throw new InvalidArgumentException( 'Unknown submission ZIP failure reason.' );
		}
		return new self( $reason );
	}

	/**
	 * Reasons.
	 *
	 * @return array<int,string>
	 */
	public static function reasons(): array {
		return array(
			self::IDEMPOTENCY_CONFLICT,
			self::INVALID_UPLOAD,
			self::LIMIT_EXCEEDED,
			self::MAPPING_INVALID,
			self::NOT_FOUND,
			self::OFFICIAL_EXISTS,
			self::PREVIEW_NOT_READY,
			self::STATE_CONFLICT,
			self::STORAGE_UNAVAILABLE,
			self::STORED_DATA_INVALID,
			self::UNSAFE_ARCHIVE,
			self::UNSUPPORTED_ARCHIVE,
		);
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
