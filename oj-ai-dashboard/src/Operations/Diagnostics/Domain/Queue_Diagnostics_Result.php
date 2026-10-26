<?php
/**
 * Safe aggregate diagnostic result for the add-on AI work queue.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Diagnostics\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe aggregate diagnostic result for the add-on AI work queue.
 */
final class Queue_Diagnostics_Result {
	public const READY       = 'ready';
	public const ATTENTION   = 'attention';
	public const UNAVAILABLE = 'unavailable';

	private const METRIC_KEYS = array(
		'expected_workers',
		'scheduled_workers',
		'pending',
		'active',
		'delayed',
		'stale_leases',
		'recent_failures',
	);

	/**
	 * Metrics.
	 *
	 * @var array<string,int>
	 */
	private array $metrics;

	/**
	 * Issue codes.
	 *
	 * @var array<int,string>
	 */
	private array $issue_codes;

	/**
	 * Initialize queue diagnostics result dependencies and state.
	 *
	 * @param string                 $status Status.
	 * @param string                 $code Code.
	 * @param array<string,mixed>    $metrics Raw metric values, validated before storage.
	 * @param array<array-key,mixed> $issue_codes Raw diagnostic codes, validated and deduplicated below.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $status,
		private string $code,
		array $metrics,
		array $issue_codes = array()
	) {
		if ( ! in_array( $status, array( self::READY, self::ATTENTION, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Unknown queue diagnostic status.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
			throw new InvalidArgumentException( 'Queue diagnostics require a stable code.' );
		}
		if ( self::READY === $status && 'queue_runtime_ready' !== $code ) {
			throw new InvalidArgumentException( 'Ready queue diagnostics require the canonical code.' );
		}

		ksort( $metrics );
		$expected_keys = self::METRIC_KEYS;
		sort( $expected_keys );
		if ( array_keys( $metrics ) !== $expected_keys ) {
			throw new InvalidArgumentException( 'Queue diagnostic metrics are incomplete.' );
		}
		foreach ( $metrics as $value ) {
			if ( ! is_int( $value ) || $value < 0 ) {
				throw new InvalidArgumentException( 'Queue diagnostic metrics must be non-negative integers.' );
			}
		}
		if ( $metrics['expected_workers'] < 1 || $metrics['scheduled_workers'] > $metrics['expected_workers'] ) {
			throw new InvalidArgumentException( 'Queue worker metrics are inconsistent.' );
		}

		$normalized = array();
		foreach ( $issue_codes as $issue_code ) {
			if ( ! is_string( $issue_code ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $issue_code ) ) {
				throw new InvalidArgumentException( 'Queue diagnostic issue codes must be stable.' );
			}
			$normalized[ $issue_code ] = true;
		}
		if ( self::READY === $status && array() !== $normalized ) {
			throw new InvalidArgumentException( 'Ready queue diagnostics cannot contain issues.' );
		}

		$this->metrics     = $metrics;
		$this->issue_codes = array_keys( $normalized );
	}

	/**
	 * Unavailable.
	 *
	 * @param string $code Code.
	 * @param int    $expected_workers Expected workers.
	 * @return self
	 */
	public static function unavailable( string $code, int $expected_workers = 4 ): self {
		return new self(
			self::UNAVAILABLE,
			$code,
			array(
				'expected_workers'  => $expected_workers,
				'scheduled_workers' => 0,
				'pending'           => 0,
				'active'            => 0,
				'delayed'           => 0,
				'stale_leases'      => 0,
				'recent_failures'   => 0,
			),
			array( $code )
		);
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
	 * Code.
	 *
	 * @return string
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Metrics.
	 *
	 * @return array<string,int>
	 */
	public function metrics(): array {
		return $this->metrics;
	}

	/**
	 * Issue codes.
	 *
	 * @return array<int,string>
	 */
	public function issue_codes(): array {
		return $this->issue_codes;
	}
}
