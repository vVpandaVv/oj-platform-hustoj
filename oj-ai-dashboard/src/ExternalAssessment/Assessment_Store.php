<?php
/**
 * Private AI-owned queue and result persistence contract.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);
namespace OJAI\Dashboard\ExternalAssessment;

/** Assessment Store boundary. */
interface Assessment_Store {
	/**
	 * Read the newest metadata record for an exact source.
	 *
	 * @param string $source source.
	 * @param string $source_id source id.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function latest( string $source, string $source_id ): ?array;
	/**
	 * Queue one approved snapshot without duplicating a pending or completed run.
	 *
	 * @param array $metadata metadata.
	 * @return array
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function enqueue( array $metadata ): array;
	/**
	 * Claim at most one run under the serial worker lock.
	 *
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function claim(): ?array;
	/**
	 * Commit a private result and terminal metadata under the exact lease.
	 *
	 * @param array      $run run.
	 * @param string     $state state.
	 * @param array|null $result result.
	 * @param string     $error_code error code.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function finish( array $run, string $state, ?array $result, string $error_code = '' ): void;
	/**
	 * Read an unexpired private result for its exact owning run.
	 *
	 * @param array $run run.
	 * @return array|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function result( array $run ): ?array;
	/**
	 * Release the worker lock even after failures.
	 *
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function release(): void;
}
