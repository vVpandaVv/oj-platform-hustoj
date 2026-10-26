<?php
/**
 * Read-only Runtime schema verification result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries only stable codes and never physical metadata or database errors.
 */
final class Schema_Runtime_Readiness_Result {
	public const READY       = 'ready';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Status.
	 *
	 * @var string
	 */
	private string $status;
	/**
	 * Code.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Issue codes.
	 *
	 * @var array<int,string>
	 */
	private array $issue_codes;

	/**
	 * Initialize schema runtime readiness result dependencies and state.
	 *
	 * @param string                 $status Status.
	 * @param string                 $code Code.
	 * @param array<array-key,mixed> $issue_codes Values filtered to stable internal issue codes.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct( string $status, string $code, array $issue_codes = array() ) {
		if ( ! in_array( $status, array( self::READY, self::UNAVAILABLE ), true ) ) {
			throw new InvalidArgumentException( 'Unknown Runtime schema readiness status.' );
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/', $code ) ) {
			throw new InvalidArgumentException( 'Runtime schema readiness requires a stable code.' );
		}
		if ( self::READY === $status && 'schema_runtime_ready' !== $code ) {
			throw new InvalidArgumentException( 'Ready Runtime schema results require the canonical code.' );
		}

		$normalized = array();
		foreach ( $issue_codes as $issue_code ) {
			if ( is_string( $issue_code ) && 1 === preg_match( '/^[a-z][a-z0-9_]{2,99}$/', $issue_code ) ) {
				$normalized[ $issue_code ] = true;
			}
		}

		$this->status      = $status;
		$this->code        = $code;
		$this->issue_codes = array_keys( $normalized );
	}

	/**
	 * Is ready.
	 *
	 * @return bool
	 */
	public function is_ready(): bool {
		return self::READY === $this->status;
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
	 * Issue codes.
	 *
	 * @return array<int,string>
	 */
	public function issue_codes(): array {
		return $this->issue_codes;
	}
}
