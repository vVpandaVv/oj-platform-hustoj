<?php
/**
 * Run submission zip cleanup result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run submission zip cleanup result.
 */
final class Run_Submission_Zip_Cleanup_Result {
	public const IDLE       = 'idle';
	public const PURGED     = 'purged';
	public const RETRY_WAIT = 'retry_wait';
	public const STALE      = 'stale';

	/**
	 * Initialize submission zip cleanup result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $upload_id Upload id.
	 * @param string|null $file_id File id.
	 * @param string|null $file_kind File kind.
	 * @param bool        $cleanup_complete Cleanup complete.
	 * @param string|null $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $state,
		private ?string $upload_id = null,
		private ?string $file_id = null,
		private ?string $file_kind = null,
		private bool $cleanup_complete = false,
		private ?string $reason_code = null
	) {
		if ( ! in_array( $state, array( self::IDLE, self::PURGED, self::RETRY_WAIT, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup result state is invalid.' );
		}
		if ( self::IDLE === $state && ( null !== $upload_id || null !== $file_id || null !== $file_kind || $cleanup_complete || null !== $reason_code ) ) {
			throw new InvalidArgumentException( 'Idle submission ZIP cleanup result must be empty.' );
		}
		if ( self::IDLE !== $state && ( null === $upload_id || null === $file_id || ! in_array( $file_kind, array( 'source', 'item' ), true ) ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cleanup result identity is invalid.' );
		}
		if ( self::PURGED !== $state && $cleanup_complete ) {
			throw new InvalidArgumentException( 'Only a purged result may complete ZIP cleanup.' );
		}
	}

	/**
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}
	/**
	 * Upload id.
	 *
	 * @return string|null
	 */
	public function upload_id(): ?string {
		return $this->upload_id;
	}
	/**
	 * File id.
	 *
	 * @return string|null
	 */
	public function file_id(): ?string {
		return $this->file_id;
	}
	/**
	 * File kind.
	 *
	 * @return string|null
	 */
	public function file_kind(): ?string {
		return $this->file_kind;
	}
	/**
	 * Cleanup complete.
	 *
	 * @return bool
	 */
	public function cleanup_complete(): bool {
		return $this->cleanup_complete;
	}
	/**
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
		return $this->reason_code;
	}
}
