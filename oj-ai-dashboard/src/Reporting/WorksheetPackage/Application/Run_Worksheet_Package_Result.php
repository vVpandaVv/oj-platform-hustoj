<?php
/**
 * Run worksheet package result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\Reporting\WorksheetPackage\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Run worksheet package result.
 */
final class Run_Worksheet_Package_Result {
	public const IDLE   = 'idle';
	public const READY  = 'ready';
	public const FAILED = 'failed';
	public const STALE  = 'stale';
	/**
	 * Initialize worksheet package result dependencies and state.
	 *
	 * @param string      $state State.
	 * @param string|null $job_id Job id.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( private string $state, private ?string $job_id = null ) {
		if ( ! in_array( $state, array( self::IDLE, self::READY, self::FAILED, self::STALE ), true ) || ( self::IDLE === $state ) !== ( null === $job_id ) ) {
			throw new InvalidArgumentException( 'Worksheet package worker result is invalid.' );
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
	 * Job id.
	 *
	 * @return string|null
	 */
	public function job_id(): ?string {
		return $this->job_id;
	}
}
