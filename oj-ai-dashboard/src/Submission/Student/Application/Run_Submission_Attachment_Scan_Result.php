<?php
/**
 * Run submission attachment scan result.
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
 * One worker tick result; no scanner or storage detail is exposed.
 */
final class Run_Submission_Attachment_Scan_Result {
	public const IDLE       = 'idle';
	public const READY      = 'ready';
	public const REJECTED   = 'rejected';
	public const RETRY_WAIT = 'retry_wait';
	public const STALE      = 'stale';

	/**
	 * Initialize submission attachment scan result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $attachment_id Attachment id.
	 * @param string|null $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $state,
		private ?string $attachment_id = null,
		private ?string $reason_code = null
	) {
		if ( ! in_array( $state, array( self::IDLE, self::READY, self::REJECTED, self::RETRY_WAIT, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Submission attachment scan result state is invalid.' );
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
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
		return $this->reason_code;
	}
}
