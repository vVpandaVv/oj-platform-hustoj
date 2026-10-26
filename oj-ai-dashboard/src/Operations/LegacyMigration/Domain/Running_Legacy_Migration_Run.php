<?php
/**
 * Running legacy migration run.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Domain;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validated running-run snapshot used by one worker batch.
 */
final class Running_Legacy_Migration_Run {
	/**
	 * Initialize running legacy migration run dependencies and state.
	 *
	 * @param int                    $database_id Database id.
	 * @param string                 $public_id Public id.
	 * @param int                    $lock_version Lock version.
	 * @param int                    $actor_user_id Authenticated actor from the access context.
	 * @param string                 $source_profile Source profile.
	 * @param string                 $source_fingerprint Source fingerprint.
	 * @param string                 $plan_hash Plan hash.
	 * @param array<array-key,mixed> $plan Plan to validate.
	 * @param string                 $checkpoint_hash Checkpoint hash.
	 * @param array<array-key,mixed> $checkpoint Checkpoint to validate.
	 * @param int                    $item_total Item total.
	 * @param int                    $success_total Success total.
	 * @param int                    $failure_total Failure total.
	 * @param int                    $skipped_total Skipped total.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $database_id,
		private string $public_id,
		private int $lock_version,
		private int $actor_user_id,
		private string $source_profile,
		private string $source_fingerprint,
		private string $plan_hash,
		private array $plan,
		private string $checkpoint_hash,
		private array $checkpoint,
		private int $item_total,
		private int $success_total,
		private int $failure_total,
		private int $skipped_total
	) {
		if (
			$database_id < 1
			|| ! Public_Id::is_valid( $public_id )
			|| $lock_version < 1
			|| $actor_user_id < 1
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $source_profile )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $source_fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $plan_hash )
			|| array_is_list( $plan )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $checkpoint_hash )
			|| array_is_list( $checkpoint )
			|| min( $item_total, $success_total, $failure_total, $skipped_total ) < 0
			|| $success_total + $failure_total + $skipped_total > $item_total
		) {
			throw new InvalidArgumentException( 'Running legacy migration run is invalid.' );
		}
	}

	/**
	 * Database id.
	 *
	 * @return int
	 */
	public function database_id(): int {
		return $this->database_id;
	}
	/**
	 * Public id.
	 *
	 * @return string
	 */
	public function public_id(): string {
		return $this->public_id;
	}
	/**
	 * Lock version.
	 *
	 * @return int
	 */
	public function lock_version(): int {
		return $this->lock_version;
	}
	/**
	 * Actor user id.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}
	/**
	 * Source profile.
	 *
	 * @return string
	 */
	public function source_profile(): string {
		return $this->source_profile;
	}
	/**
	 * Source fingerprint.
	 *
	 * @return string
	 */
	public function source_fingerprint(): string {
		return $this->source_fingerprint;
	}
	/**
	 * Plan hash.
	 *
	 * @return string
	 */
	public function plan_hash(): string {
		return $this->plan_hash;
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
	 * Checkpoint hash.
	 *
	 * @return string
	 */
	public function checkpoint_hash(): string {
		return $this->checkpoint_hash;
	}
	/**
	 * Checkpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function checkpoint(): array {
		return $this->checkpoint;
	}
	/**
	 * Item total.
	 *
	 * @return int
	 */
	public function item_total(): int {
		return $this->item_total;
	}
	/**
	 * Success total.
	 *
	 * @return int
	 */
	public function success_total(): int {
		return $this->success_total;
	}
	/**
	 * Failure total.
	 *
	 * @return int
	 */
	public function failure_total(): int {
		return $this->failure_total;
	}
	/**
	 * Skipped total.
	 *
	 * @return int
	 */
	public function skipped_total(): int {
		return $this->skipped_total;
	}
}
