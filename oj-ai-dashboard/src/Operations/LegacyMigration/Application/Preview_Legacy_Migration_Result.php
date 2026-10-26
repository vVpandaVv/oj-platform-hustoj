<?php
/**
 * Preview legacy migration result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview legacy migration result.
 */
final class Preview_Legacy_Migration_Result {
	/**
	 * Initialize preview legacy migration result dependencies and state.
	 *
	 * @param array<string,mixed> $plan Plan.
	 * @param string              $preview_token Preview token.
	 * @param string              $issued_at_utc Issued at utc.
	 * @param string              $expires_at_utc Expires at utc.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private array $plan,
		private string $preview_token,
		private string $issued_at_utc,
		private string $expires_at_utc
	) {
		if (
			1 !== preg_match( '/^[a-f0-9]{64}$/D', (string) ( $plan['plan_hash'] ?? '' ) )
			|| '' === $preview_token
			|| strlen( $preview_token ) > 4096
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $issued_at_utc )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $expires_at_utc )
		) {
			throw new InvalidArgumentException( 'Legacy migration preview result is invalid.' );
		}
	}

	/**
	 * Plan.
	 *
	 * @return array<string,mixed>
	 */
	public function plan(): array {
		return $this->plan;
	}

	/**
	 * Preview token.
	 *
	 * @return string
	 */
	public function preview_token(): string {
		return $this->preview_token;
	}

	/**
	 * Issued at utc.
	 *
	 * @return string
	 */
	public function issued_at_utc(): string {
		return $this->issued_at_utc;
	}

	/**
	 * Expires at utc.
	 *
	 * @return string
	 */
	public function expires_at_utc(): string {
		return $this->expires_at_utc;
	}

	/**
	 * Mutation count.
	 *
	 * @return int
	 */
	public function mutation_count(): int {
		return 0;
	}

	/**
	 * Ledger mutation count.
	 *
	 * @return int
	 */
	public function ledger_mutation_count(): int {
		return 0;
	}

	/**
	 * Platform write count.
	 *
	 * @return int
	 */
	public function platform_write_count(): int {
		return 0;
	}
}
