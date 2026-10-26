<?php
/**
 * Student submission external AI consent command result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Student submission external AI consent command result.
 */
final class Submission_External_Ai_Consent_Command_Result {
	/**
	 * Initialize submission external ai consent command result dependencies and state.
	 *
	 * @param array<string,mixed> $status Status.
	 * @param string              $operation Operation.
	 * @param string              $occurred_at_utc Occurred at utc.
	 * @param bool                $replayed Replayed.
	 */
	public function __construct(
		private array $status,
		private string $operation,
		private string $occurred_at_utc,
		private bool $replayed
	) {
	}

	/**
	 * Status.
	 *
	 * @return array<string,mixed>
	 */
	public function status(): array {
		return $this->status;
	}
	/**
	 * Operation.
	 *
	 * @return string
	 */
	public function operation(): string {
		return $this->operation;
	}
	/**
	 * Occurred at utc.
	 *
	 * @return string
	 */
	public function occurred_at_utc(): string {
		return $this->occurred_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
}
