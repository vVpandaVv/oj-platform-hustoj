<?php
/**
 * Submission evaluation gateway result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe projection of the public OJ Platform AI result DTO.
 */
final class Submission_Evaluation_Gateway_Result {
	/**
	 * Initialize submission evaluation gateway result dependencies and state.
	 *
	 * @param bool                                        $successful Successful.
	 * @param array<array-key,mixed>                      $output Output.
	 * @param Submission_Evaluation_Routing_Snapshot|null $routing Routing.
	 * @param string                                      $correlation_id Request correlation identifier for diagnostics.
	 * @param string|null                                 $error_code Error code.
	 * @param bool                                        $retryable Retryable.
	 * @param int                                         $duration_ms Duration ms.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		private bool $successful,
		private array $output,
		private ?Submission_Evaluation_Routing_Snapshot $routing,
		private string $correlation_id,
		private ?string $error_code,
		private bool $retryable,
		private int $duration_ms
	) {
		if (
			1 !== preg_match( '/^[A-Za-z0-9._:-]{8,100}$/D', $correlation_id )
			|| $duration_ms < 0
			|| $duration_ms > 300000
			|| ( $successful && ( null === $routing || null !== $error_code || $retryable ) )
			|| ( ! $successful && ( null !== $routing || null === $error_code || array() !== $output ) )
			|| ( null !== $error_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $error_code ) )
			|| ( array() !== $output && array_is_list( $output ) )
		) {
			throw new InvalidArgumentException( 'Submission evaluation gateway result is invalid.' );
		}
	}

	/**
	 * Success.
	 *
	 * @param array<string,mixed>                    $output Output.
	 * @param Submission_Evaluation_Routing_Snapshot $routing Routing.
	 * @param string                                 $correlation_id Request correlation identifier for diagnostics.
	 * @param int                                    $duration_ms Duration ms.
	 * @return self
	 */
	public static function success(
		array $output,
		Submission_Evaluation_Routing_Snapshot $routing,
		string $correlation_id,
		int $duration_ms
	): self {
		return new self( true, $output, $routing, $correlation_id, null, false, $duration_ms );
	}

	/**
	 * Failure.
	 *
	 * @param string $error_code Error code.
	 * @param bool   $retryable Retryable.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @param int    $duration_ms Duration ms.
	 * @return self
	 */
	public static function failure(
		string $error_code,
		bool $retryable,
		string $correlation_id,
		int $duration_ms
	): self {
		return new self( false, array(), null, $correlation_id, $error_code, $retryable, $duration_ms );
	}

	/**
	 * Is successful.
	 *
	 * @return bool
	 */
	public function is_successful(): bool {
		return $this->successful;
	}
	/**
	 * Output.
	 *
	 * @return array<string,mixed>
	 */
	public function output(): array {
		return $this->output;
	}
	/**
	 * Routing.
	 *
	 * @return Submission_Evaluation_Routing_Snapshot|null
	 */
	public function routing(): ?Submission_Evaluation_Routing_Snapshot {
		return $this->routing;
	}
	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}
	/**
	 * Error code.
	 *
	 * @return string|null
	 */
	public function error_code(): ?string {
		return $this->error_code;
	}
	/**
	 * Retryable.
	 *
	 * @return bool
	 */
	public function retryable(): bool {
		return $this->retryable;
	}
	/**
	 * Duration ms.
	 *
	 * @return int
	 */
	public function duration_ms(): int {
		return $this->duration_ms;
	}
}
