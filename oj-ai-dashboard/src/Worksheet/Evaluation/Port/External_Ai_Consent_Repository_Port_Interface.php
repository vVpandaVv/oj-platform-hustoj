<?php
/**
 * External AI consent ledger port.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Worksheet\Evaluation\Port;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * External AI consent ledger port.
 */
interface External_Ai_Consent_Repository_Port_Interface {
	/**
	 * Current record.
	 *
	 * @return array<string,mixed>|null
	 */
	public function current_record(): ?array;

	/**
	 * Replay.
	 *
	 * @param string $idempotency_storage_key Idempotency storage key.
	 * @param string $request_digest Request digest.
	 * @return array{record:array<string,mixed>|null,occurred_at_utc:string,replayed:bool}|null
	 */
	public function replay(
		string $idempotency_storage_key,
		string $request_digest
	): ?array;

	/**
	 * Commit.
	 *
	 * @param string                   $operation Operation.
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $idempotency_storage_key Idempotency storage key.
	 * @param string                   $request_digest Request digest.
	 * @param string|null              $expected_consent_hash Expected consent hash.
	 * @param array<string,mixed>|null $record Record.
	 * @param string                   $occurred_at_utc Occurred at utc.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @return array{record:array<string,mixed>|null,occurred_at_utc:string,replayed:bool}
	 */
	public function commit(
		string $operation,
		int $actor_user_id,
		string $command_id,
		string $idempotency_storage_key,
		string $request_digest,
		?string $expected_consent_hash,
		?array $record,
		string $occurred_at_utc,
		string $correlation_id
	): array;
}
