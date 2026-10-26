<?php
/**
 * Run submission pdf result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionPdf\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run submission pdf result.
 */
final class Run_Submission_Pdf_Result {
	public const IDLE   = 'idle';
	public const READY  = 'ready';
	public const FAILED = 'failed';
	public const STALE  = 'stale';

	/**
	 * Initialize submission pdf result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string|null $job_id Job id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $status, private ?string $job_id = null ) {
		if ( ! in_array( $status, array( self::IDLE, self::READY, self::FAILED, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Submission PDF worker result is invalid.' );
		}
	}

	/**
	 * Status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}
	/**
	 * Job id.
	 *
	 * @return string|null
	 */
	public function job_id(): ?string {
		return $this->job_id;
	}
}
