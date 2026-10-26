<?php
/**
 * Preview purge command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preview purge command.
 */
final class Preview_Purge_Command {
	public const SCOPE_ADDON = 'addon';

	/**
	 * Initialize preview purge command dependencies and state.
	 *
	 * @param int    $actor_user_id Authenticated actor from the access context.
	 * @param string $scope_type Scope type.
	 * @param string $idempotency_key_hash Idempotency key hash.
	 * @param string $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $scope_type,
		private string $idempotency_key_hash,
		private string $correlation_id
	) {
		if (
			$actor_user_id < 1
			|| self::SCOPE_ADDON !== $scope_type
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_key_hash )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Purge preview command is invalid.' );
		}
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
	 * Scope type.
	 *
	 * @return string
	 */
	public function scope_type(): string {
		return $this->scope_type;
	}

	/**
	 * Idempotency key hash.
	 *
	 * @return string
	 */
	public function idempotency_key_hash(): string {
		return $this->idempotency_key_hash;
	}

	/**
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}
}
