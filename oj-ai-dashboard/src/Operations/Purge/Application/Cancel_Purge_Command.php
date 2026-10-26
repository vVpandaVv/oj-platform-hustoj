<?php
/**
 * Cancel purge command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strict A-DS08-C74 cancellation command.
 */
final class Cancel_Purge_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize purge command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $job_id Job id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string         $expected_preview_hash Expected preview hash.
	 * @param string         $idempotency_key_hash Idempotency key hash.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $job_id,
		private string $command_id,
		private string $expected_state,
		private string $expected_preview_hash,
		private string $idempotency_key_hash,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if (
			$actor_user_id < 1
			|| ! Public_Id::is_valid( $job_id )
			|| ! Public_Id::is_valid( $command_id )
			|| ! in_array( $expected_state, array( 'approved', 'running' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_preview_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_key_hash )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Purge cancellation command is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'preview_hash' => $expected_preview_hash,
						'state'        => $expected_state,
					),
					'payload'    => array(),
					'job_id'     => $job_id,
				)
			)
		);
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
	 * Job id.
	 *
	 * @return string
	 */
	public function job_id(): string {
		return $this->job_id;
	}
	/**
	 * Command id.
	 *
	 * @return string
	 */
	public function command_id(): string {
		return $this->command_id;
	}
	/**
	 * Expected state.
	 *
	 * @return string
	 */
	public function expected_state(): string {
		return $this->expected_state;
	}
	/**
	 * Expected preview hash.
	 *
	 * @return string
	 */
	public function expected_preview_hash(): string {
		return $this->expected_preview_hash;
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
	 * Request digest.
	 *
	 * @return string
	 */
	public function request_digest(): string {
		return $this->request_digest;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|operations.purge.cancel|' . $this->job_id . '|' . $this->idempotency_key_hash
		);
	}
}
