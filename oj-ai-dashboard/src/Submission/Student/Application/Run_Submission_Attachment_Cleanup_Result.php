<?php
/**
 * Run submission attachment cleanup result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run submission attachment cleanup result.
 */
final class Run_Submission_Attachment_Cleanup_Result {
	public const IDLE       = 'idle';
	public const PURGED     = 'purged';
	public const RETRY_WAIT = 'retry_wait';
	public const STALE      = 'stale';

	/**
	 * Initialize submission attachment cleanup result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $attachment_id Attachment id.
	 * @param string|null $file_id File id.
	 * @param string|null $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $state,
		private ?string $attachment_id = null,
		private ?string $file_id = null,
		private ?string $reason_code = null
	) {
		if ( ! in_array( $state, array( self::IDLE, self::PURGED, self::RETRY_WAIT, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Submission attachment cleanup result state is invalid.' );
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
	 * Attachment id.
	 *
	 * @return string|null
	 */
	public function attachment_id(): ?string {
		return $this->attachment_id;
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
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
		return $this->reason_code;
	}
}
