<?php
/**
 * Worksheet evaluation gateway result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Domain;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Safe projection of the public OJ Platform AI result DTO.
 */
final class Worksheet_Evaluation_Gateway_Result {
	/**
	 * Initialize worksheet evaluation gateway result dependencies and state.
	 *
	 * @param bool                                       $successful Successful.
	 * @param array<array-key,mixed>                     $output Output.
	 * @param Worksheet_Evaluation_Routing_Snapshot|null $routing Routing.
	 * @param string                                     $correlation_id Request correlation identifier for diagnostics.
	 * @param string|null                                $error_code Error code.
	 * @param string|null                                $error_message Error message.
	 * @param bool                                       $retryable Retryable.
	 * @param int                                        $duration_ms Duration ms.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function __construct(
		private bool $successful,
		private array $output,
		private ?Worksheet_Evaluation_Routing_Snapshot $routing,
		private string $correlation_id,
		private ?string $error_code,
		private ?string $error_message,
		private bool $retryable,
		private int $duration_ms
	) {
		if (
			1 !== preg_match( '/^[A-Za-z0-9._:-]{8,100}$/D', $correlation_id )
			|| $duration_ms < 0
			|| $duration_ms > 300000
			|| ( $successful && ( null === $routing || null !== $error_code || null !== $error_message || $retryable ) )
			|| ( ! $successful && ( null !== $routing || null === $error_code || array() !== $output ) )
			|| ( null !== $error_code && 1 !== preg_match( '/^[a-z][a-z0-9_]{2,99}$/D', $error_code ) )
			|| ( null !== $error_message && ( '' === trim( $error_message ) || strlen( $error_message ) > 500 ) )
			|| ( array() !== $output && array_is_list( $output ) )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation gateway result is invalid.' );
		}
	}

	/**
	 * Success.
	 *
	 * @param array<string,mixed>                   $output Output.
	 * @param Worksheet_Evaluation_Routing_Snapshot $routing Routing.
	 * @param string                                $correlation_id Request correlation identifier for diagnostics.
	 * @param int                                   $duration_ms Duration ms.
	 * @return self
	 */
	public static function success(
		array $output,
		Worksheet_Evaluation_Routing_Snapshot $routing,
		string $correlation_id,
		int $duration_ms
	): self {
		return new self( true, $output, $routing, $correlation_id, null, null, false, $duration_ms );
	}

	/**
	 * Failure.
	 *
	 * @param string      $error_code Error code.
	 * @param bool        $retryable Retryable.
	 * @param string      $correlation_id Request correlation identifier for diagnostics.
	 * @param int         $duration_ms Duration ms.
	 * @param string|null $error_message Error message.
	 * @return self
	 */
	public static function failure(
		string $error_code,
		bool $retryable,
		string $correlation_id,
		int $duration_ms,
		?string $error_message = null
	): self {
		return new self( false, array(), null, $correlation_id, $error_code, $error_message, $retryable, $duration_ms );
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
	 * @return Worksheet_Evaluation_Routing_Snapshot|null
	 */
	public function routing(): ?Worksheet_Evaluation_Routing_Snapshot {
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
	 * Error message.
	 *
	 * @return string|null
	 */
	public function error_message(): ?string {
		return $this->error_message;
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
