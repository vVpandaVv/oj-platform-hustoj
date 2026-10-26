<?php
/**
 * Safe platform AI routing snapshot.
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
 * Keeps only routing provenance that fits the frozen add-on schema.
 */
final class Worksheet_Evaluation_Routing_Snapshot {
	/**
	 * Initialize worksheet evaluation routing snapshot dependencies and state.
	 *
	 * @param string $task_key Task key.
	 * @param string $provider_key Provider key.
	 * @param string $model_key Model key.
	 * @param string $profile_fingerprint Profile fingerprint.
	 * @param string $routing_revision Routing revision.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private string $task_key,
		private string $provider_key,
		private string $model_key,
		private string $profile_fingerprint,
		private string $routing_revision
	) {
		if (
			strlen( $task_key ) > 191
			|| strlen( $provider_key ) > 100
			|| strlen( $model_key ) > 100
			|| strlen( $routing_revision ) > 191
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $task_key )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $provider_key )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $model_key )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $profile_fingerprint )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $routing_revision )
		) {
			throw new InvalidArgumentException( 'Worksheet evaluation routing snapshot is invalid.' );
		}
	}

	/**
	 * Task key.
	 *
	 * @return string
	 */
	public function task_key(): string {
		return $this->task_key;
	}

	/**
	 * Provider key.
	 *
	 * @return string
	 */
	public function provider_key(): string {
		return $this->provider_key;
	}

	/**
	 * Model key.
	 *
	 * @return string
	 */
	public function model_key(): string {
		return $this->model_key;
	}

	/**
	 * Profile fingerprint.
	 *
	 * @return string
	 */
	public function profile_fingerprint(): string {
		return $this->profile_fingerprint;
	}

	/**
	 * Routing revision.
	 *
	 * @return string
	 */
	public function routing_revision(): string {
		return $this->routing_revision;
	}
}
