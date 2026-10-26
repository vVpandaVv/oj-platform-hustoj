<?php
/**
 * Safe aggregate diagnostic result for private storage.
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
 * Safe aggregate diagnostic result for private storage.
 */
final class Storage_Diagnostics_Result {
	public const READY       = 'ready';
	public const ATTENTION   = 'attention';
	public const UNAVAILABLE = 'unavailable';

	private const METRIC_KEYS = array(
		'expected_workers',
		'scheduled_workers',
		'pending_scans',
		'delayed_scans',
		'cleanup_due',
		'delayed_cleanup',
		'metadata_issues',
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
	 * Initialize storage diagnostics result dependencies and state.
	 *
	 * @param string                   $status Status.
	 * @param string                   $code Code.
	 * @param Storage_Path_Diagnostics $path Path.
	 * @param array<string,mixed>      $metrics Raw metric values, validated before storage.
	 * @param array<array-key,mixed>   $issue_codes Raw diagnostic codes, validated and deduplicated below.
	 * @param string                   $scanner_status Scanner status.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $status,
		private string $code,
		private Storage_Path_Diagnostics $path,
		array $metrics,
		array $issue_codes = array(),
		private string $scanner_status = 'unknown'
	) {
		if ( ! in_array( $scanner_status, array( 'unknown', 'responding', 'unavailable' ), true ) ) {
			throw new InvalidArgumentException( 'Unknown file scanner diagnostic status.' );
		}
		if ( ! in_array( $status, array( self::READY, self::ATTENTION, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Unknown storage diagnostic status.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $code ) ) {
			throw new InvalidArgumentException( 'Storage diagnostics require a stable code.' );
		}

		ksort( $metrics );
		$expected_keys = self::METRIC_KEYS;
		sort( $expected_keys );
		if ( array_keys( $metrics ) !== $expected_keys ) {
			throw new InvalidArgumentException( 'Storage diagnostic metrics are incomplete.' );
		}
		foreach ( $metrics as $value ) {
			if ( ! is_int( $value ) || $value < 0 ) {
				throw new InvalidArgumentException( 'Storage diagnostic metrics must be non-negative integers.' );
			}
		}
		if ( $metrics['expected_workers'] < 1 || $metrics['scheduled_workers'] > $metrics['expected_workers'] ) {
			throw new InvalidArgumentException( 'Storage worker metrics are inconsistent.' );
		}

		$normalized = array();
		foreach ( $issue_codes as $issue_code ) {
			if ( ! is_string( $issue_code ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $issue_code ) ) {
				throw new InvalidArgumentException( 'Storage diagnostic issue codes must be stable.' );
			}
			$normalized[ $issue_code ] = true;
		}
		if ( self::READY === $status ) {
			if ( 'storage_runtime_ready' !== $code || array() !== $normalized || Storage_Path_Diagnostics::READY !== $path->status() ) {
				throw new InvalidArgumentException( 'Ready storage diagnostics are inconsistent.' );
			}
		} elseif ( array() === $normalized ) {
			throw new InvalidArgumentException( 'Non-ready storage diagnostics require an issue.' );
		}

		$this->metrics     = $metrics;
		$this->issue_codes = array_keys( $normalized );
	}

	/**
	 * Unavailable.
	 *
	 * @param string                        $code Code.
	 * @param Storage_Path_Diagnostics|null $path Path.
	 * @param int                           $expected_workers Expected workers.
	 * @param int                           $scheduled_workers Scheduled workers.
	 * @return self
	 */
	public static function unavailable(
		string $code = 'storage_observation_failed',
		?Storage_Path_Diagnostics $path = null,
		int $expected_workers = 4,
		int $scheduled_workers = 0
	): self {
		return new self(
			self::UNAVAILABLE,
			$code,
			$path ?? Storage_Path_Diagnostics::unavailable(),
			array(
				'expected_workers'  => $expected_workers,
				'scheduled_workers' => $scheduled_workers,
				'pending_scans'     => 0,
				'delayed_scans'     => 0,
				'cleanup_due'       => 0,
				'delayed_cleanup'   => 0,
				'metadata_issues'   => 0,
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
	 * Scanner status.
	 *
	 * @return string
	 */
	public function scanner_status(): string {
		return $this->scanner_status;
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
	 * Path.
	 *
	 * @return array{private:bool,readable:bool,writable:bool,space_available:bool}
	 */
	public function path(): array {
		return $this->path->projection();
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
