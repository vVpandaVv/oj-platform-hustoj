<?php
/**
 * Run submission bundle result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\SubmissionBundle\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outcome of one submission bundle worker pass.
 */
final class Run_Submission_Bundle_Result {
	public const IDLE   = 'idle';
	public const READY  = 'ready';
	public const FAILED = 'failed';
	public const STALE  = 'stale';

	/**
	 * Initialize submission bundle result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string|null $job_id Job id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $status, private ?string $job_id = null ) {
		if (
			! in_array( $status, array( self::IDLE, self::READY, self::FAILED, self::STALE ), true )
			|| ( self::IDLE === $status ) !== ( null === $job_id )
		) {
			throw new InvalidArgumentException( 'Submission bundle worker result is invalid.' );
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
