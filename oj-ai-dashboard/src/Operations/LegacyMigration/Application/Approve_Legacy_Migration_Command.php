<?php
/**
 * Approve legacy migration command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\LegacyMigration\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approve legacy migration command.
 */
final class Approve_Legacy_Migration_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Mapping decisions.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $mapping_decisions;

	/**
	 * Initialize legacy migration command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $source_profile Source profile.
	 * @param string         $preview_token Preview token.
	 * @param string         $idempotency_key_hash Idempotency key hash.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param array          $mapping_decisions Mapping decisions.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $source_profile,
		private string $preview_token,
		private string $idempotency_key_hash,
		private string $correlation_id,
		Canonical_Json $canonical,
		array $mapping_decisions = array()
	) {
		if (
			$actor_user_id < 1
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]{7,127}$/D', $source_profile )
			|| '' === $preview_token
			|| strlen( $preview_token ) > 4096
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $preview_token )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_key_hash )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $correlation_id )
			|| ! array_is_list( $mapping_decisions )
			|| count( $mapping_decisions ) > 50000
		) {
			throw new InvalidArgumentException( 'Legacy migration approval command is invalid.' );
		}
		$this->mapping_decisions = $mapping_decisions;
		$this->request_digest    = hash(
			'sha256',
			$canonical->encode(
				array(
					'mapping_decisions'  => $mapping_decisions,
					'source_profile'     => $source_profile,
					'preview_token_hash' => $this->preview_token_hash(),
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
	 * Source profile.
	 *
	 * @return string
	 */
	public function source_profile(): string {
		return $this->source_profile;
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
	 * Preview token hash.
	 *
	 * @return string
	 */
	public function preview_token_hash(): string {
		return hash( 'sha256', $this->preview_token );
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
	/**
	 * Request digest.
	 *
	 * @return string
	 */
	public function request_digest(): string {
		return $this->request_digest;
	}

	/**
	 * Mapping decisions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function mapping_decisions(): array {
		return $this->mapping_decisions;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|legacy.migration.approve|' . $this->source_profile . '|' . $this->idempotency_key_hash
		);
	}
}
