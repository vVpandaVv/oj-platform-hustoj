<?php
/**
 * Run worksheet pdf result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPdf\Application;

use InvalidArgumentException;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run worksheet pdf result.
 */
final class Run_Worksheet_Pdf_Result {
	public const IDLE   = 'idle';
	public const READY  = 'ready';
	public const FAILED = 'failed';
	public const STALE  = 'stale';
	/**
	 * Initialize worksheet pdf result dependencies and state.
	 *
	 * @param string      $status Status.
	 * @param string|null $job_id Job id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $status, private ?string $job_id = null ) {
		if ( ! in_array( $status, array( self::IDLE, self::READY, self::FAILED, self::STALE ), true ) ) {
			throw new InvalidArgumentException( 'Worksheet PDF worker result is invalid.' );
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
