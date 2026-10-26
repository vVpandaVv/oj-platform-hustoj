<?php
/**
 * Submission bundle failure.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Application;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable C50 submission bundle failure reasons.
 */
final class Submission_Bundle_Failure extends RuntimeException {
	public const NOT_FOUND            = 'submission_bundle_not_found';
	public const SOURCE_STALE         = 'submission_bundle_source_stale';
	public const SOURCE_ACCESS_DENIED = 'submission_bundle_source_access_denied';
	public const SOURCE_INVALID       = 'submission_bundle_source_invalid';
	public const IDEMPOTENCY_CONFLICT = 'submission_bundle_idempotency_conflict';
	public const TOO_LARGE            = 'submission_bundle_too_large';
	public const STORED_DATA_INVALID  = 'submission_bundle_stored_data_invalid';
	public const STORAGE_UNAVAILABLE  = 'submission_bundle_storage_unavailable';
	public const RENDER_FAILED        = 'submission_bundle_render_failed';
	public const ARCHIVE_FAILED       = 'submission_bundle_archive_failed';
	public const ZIP_UNAVAILABLE      = 'submission_bundle_zip_unavailable';

	/**
	 * Initialize submission bundle failure dependencies and state.
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
