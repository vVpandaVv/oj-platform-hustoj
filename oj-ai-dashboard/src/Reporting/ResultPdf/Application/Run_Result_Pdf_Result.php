<?php
/**
 * Run result pdf result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Reporting\ResultPdf\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run result pdf result.
 */
final class Run_Result_Pdf_Result {
	public const IDLE   = 'idle';
	public const READY  = 'ready';
	public const FAILED = 'failed';
	public const STALE  = 'stale';
	/**
	 * Initialize result pdf result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string|null $job_id Job id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $status, private ?string $job_id = null ) {
		if ( ! in_array( $status, array( self::IDLE, self::READY, self::FAILED, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Result PDF worker status is invalid.' );
		}
		if ( null !== $job_id ) {
			new Public_Id( $job_id );
		}
		if ( self::IDLE === $status && null !== $job_id ) {
			throw new InvalidArgumentException( 'Idle result PDF worker has a job.' );
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
