<?php
/**
 * Approve purge result.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approve purge result.
 */
final class Approve_Purge_Result {
	/**
	 * Initialize purge result dependencies and state.
	 *
	 * @param string $job_id Job id.
	 * @param string $state State.
	 * @param string $scope_type Scope type.
	 * @param string $scope_hash Scope hash.
	 * @param string $preview_hash Preview hash.
	 * @param string $approved_at_utc Approved at utc.
	 * @param string $approval_expires_at_utc Approval expires at utc.
	 * @param bool   $replayed Replayed.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $job_id,
		private string $state,
		private string $scope_type,
		private string $scope_hash,
		private string $preview_hash,
		private string $approved_at_utc,
		private string $approval_expires_at_utc,
		private bool $replayed
	) {
		new Public_Id( $job_id );
		if (
			'approved' !== $state
			|| Preview_Purge_Command::SCOPE_ADDON !== $scope_type
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $approved_at_utc )
			|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $approval_expires_at_utc )
		) {
			throw new InvalidArgumentException( 'Purge approval result is invalid.' );
		}
		foreach ( array( $scope_hash, $preview_hash ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				throw new InvalidArgumentException( 'Purge approval result digest is invalid.' );
			}
		}
	}

	/**
	 * Job id.
	 *
	 * @return string
	 */
	public function job_id(): string {
		return $this->job_id;
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
	 * Scope type.
	 *
	 * @return string
	 */
	public function scope_type(): string {
		return $this->scope_type;
	}
	/**
	 * Scope hash.
	 *
	 * @return string
	 */
	public function scope_hash(): string {
		return $this->scope_hash;
	}
	/**
	 * Preview hash.
	 *
	 * @return string
	 */
	public function preview_hash(): string {
		return $this->preview_hash;
	}
	/**
	 * Approved at utc.
	 *
	 * @return string
	 */
	public function approved_at_utc(): string {
		return $this->approved_at_utc;
	}
	/**
	 * Approval expires at utc.
	 *
	 * @return string
	 */
	public function approval_expires_at_utc(): string {
		return $this->approval_expires_at_utc;
	}
	/**
	 * Replayed.
	 *
	 * @return bool
	 */
	public function replayed(): bool {
		return $this->replayed;
	}
	/**
	 * Mutation count.
	 *
	 * @return int
	 */
	public function mutation_count(): int {
		return $this->replayed ? 0 : 1;
	}
	/**
	 * Ledger mutation count.
	 *
	 * @return int
	 */
	public function ledger_mutation_count(): int {
		return $this->replayed ? 0 : 1;
	}
	/**
	 * Business mutation count.
	 *
	 * @return int
	 */
	public function business_mutation_count(): int {
		return 0;
	}
	/**
	 * File mutation count.
	 *
	 * @return int
	 */
	public function file_mutation_count(): int {
		return 0;
	}
	/**
	 * Option mutation count.
	 *
	 * @return int
	 */
	public function option_mutation_count(): int {
		return 0;
	}
	/**
	 * Role mutation count.
	 *
	 * @return int
	 */
	public function role_mutation_count(): int {
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
