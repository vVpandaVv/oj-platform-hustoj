<?php
/**
 * Repair addon schema command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\SchemaRepair\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Database\Foundation\Installation_State;
use OJAI\Dashboard\Database\Foundation\Schema_Repair_Authorization;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repair addon schema command.
 */
final class Repair_Addon_Schema_Command {
	public const PROFILE = 'oj_ai.schema_repair_command@1.0.0';

	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;
	/**
	 * Approval token hash.
	 *
	 * @var string
	 */
	private string $approval_token_hash;

	/**
	 * Initialize repair addon schema command dependencies and state.
	 *
	 * @param int               $actor_user_id Authenticated actor from the access context.
	 * @param string            $command_id Command identifier used for idempotency.
	 * @param string            $expected_installation_state Expected installation state.
	 * @param string            $expected_catalog_fingerprint Expected catalog fingerprint.
	 * @param string            $expected_repair_state_hash Expected repair state hash.
	 * @param string            $backup_evidence_hash Backup evidence hash.
	 * @param DateTimeImmutable $backup_verified_at_utc Backup verified at utc.
	 * @param bool              $forward_only Forward only.
	 * @param string            $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string            $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json    $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $command_id,
		private string $expected_installation_state,
		private string $expected_catalog_fingerprint,
		private string $expected_repair_state_hash,
		private string $backup_evidence_hash,
		private DateTimeImmutable $backup_verified_at_utc,
		private bool $forward_only,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if (
			$actor_user_id < 1
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $command_id )
			|| ! in_array( $expected_installation_state, array( Installation_State::INSTALLING, Installation_State::MAINTENANCE_REQUIRED ), true )
			|| ! $forward_only
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Schema repair command identity is invalid.' );
		}
		foreach ( array( $expected_catalog_fingerprint, $expected_repair_state_hash, $backup_evidence_hash, $idempotency_fingerprint ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $digest ) ) {
				throw new InvalidArgumentException( 'Schema repair command digests are invalid.' );
			}
		}
		$this->backup_verified_at_utc = $backup_verified_at_utc->setTimezone( new DateTimeZone( 'UTC' ) );
		$this->request_digest         = hash(
			'sha256',
			$canonical->encode(
				array(
					'actor_user_id' => $actor_user_id,
					'backup'        => array(
						'evidence_digest' => $backup_evidence_hash,
						'verified_at_utc' => $this->backup_verified_at_utc->format( 'Y-m-d H:i:s' ),
					),
					'command_id'    => $command_id,
					'expected'      => array(
						'catalog_fingerprint' => $expected_catalog_fingerprint,
						'installation_state'  => $expected_installation_state,
						'repair_state_hash'   => $expected_repair_state_hash,
					),
					'payload'       => array( 'forward_only' => true ),
					'profile'       => self::PROFILE,
				)
			)
		);
		$this->approval_token_hash    = hash(
			'sha256',
			$canonical->encode(
				array(
					'actor_user_id'           => $actor_user_id,
					'idempotency_fingerprint' => $idempotency_fingerprint,
					'request_digest'          => $this->request_digest,
				)
			)
		);
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
	 * Storage key.
	 *
	 * @return string
	 */
	public function storage_key(): string {
		return 'schema-repair:' . $this->idempotency_fingerprint;
	}

	/**
	 * Authorization.
	 *
	 * @return Schema_Repair_Authorization
	 */
	public function authorization(): Schema_Repair_Authorization {
		return new Schema_Repair_Authorization(
			$this->actor_user_id,
			$this->correlation_id,
			$this->approval_token_hash,
			$this->backup_evidence_hash,
			$this->expected_catalog_fingerprint,
			$this->backup_verified_at_utc
		);
	}

	/**
	 * Expected installation state.
	 *
	 * @return string
	 */
	public function expected_installation_state(): string {
		return $this->expected_installation_state;
	}

	/**
	 * Expected catalog fingerprint.
	 *
	 * @return string
	 */
	public function expected_catalog_fingerprint(): string {
		return $this->expected_catalog_fingerprint;
	}

	/**
	 * Expected repair state hash.
	 *
	 * @return string
	 */
	public function expected_repair_state_hash(): string {
		return $this->expected_repair_state_hash;
	}

	/**
	 * Receipt.
	 *
	 * @param string $claimed_at_utc Claimed at utc.
	 * @return array<string,mixed>
	 */
	public function receipt( string $claimed_at_utc ): array {
		return array(
			'profile'                      => self::PROFILE,
			'state'                        => 'pending',
			'actor_user_id'                => $this->actor_user_id,
			'command_id'                   => $this->command_id,
			'storage_key'                  => $this->storage_key(),
			'request_digest'               => $this->request_digest,
			'approval_token_hash'          => $this->approval_token_hash,
			'backup_evidence_hash'         => $this->backup_evidence_hash,
			'backup_verified_at_utc'       => $this->backup_verified_at_utc->format( 'Y-m-d H:i:s' ),
			'expected_installation_state'  => $this->expected_installation_state,
			'expected_catalog_fingerprint' => $this->expected_catalog_fingerprint,
			'expected_repair_state_hash'   => $this->expected_repair_state_hash,
			'correlation_id'               => $this->correlation_id,
			'claimed_at_utc'               => $claimed_at_utc,
		);
	}
}
