<?php
/**
 * Run submission zip scan result.
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
 * Safe result of one asynchronous ZIP worker tick.
 */
final class Run_Submission_Zip_Scan_Result {
	public const IDLE       = 'idle';
	public const READY      = 'ready';
	public const REJECTED   = 'rejected';
	public const RETRY_WAIT = 'retry_wait';
	public const STALE      = 'stale';

	/**
	 * Initialize submission zip scan result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $upload_id Upload id.
	 * @param string|null $reason_code Reason code.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $state,
		private ?string $upload_id = null,
		private ?string $reason_code = null
	) {
		if ( ! in_array( $state, array( self::IDLE, self::READY, self::REJECTED, self::RETRY_WAIT, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Submission ZIP scan result is invalid.' );
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
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
		return $this->reason_code;
	}
}
