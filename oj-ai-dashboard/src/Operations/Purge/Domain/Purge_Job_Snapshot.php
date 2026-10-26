<?php
/**
 * Purge job snapshot.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Operations\Purge\Application\Preview_Purge_Command;
use OJAI\Dashboard\Operations\Purge\Application\Purge_Preview_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable, integrity-checked control snapshot used by C73 and C74.
 */
final class Purge_Job_Snapshot {
	/**
	 * Initialize purge job snapshot dependencies and state.
	 *
	 * @param int                      $database_id Database id.
	 * @param string                   $public_id Public id.
	 * @param int                      $lock_version Lock version.
	 * @param string                   $state State.
	 * @param array<string,mixed>      $preview Preview.
	 * @param string                   $preview_json_hash Preview json hash.
	 * @param string                   $approved_at_utc Approved at utc.
	 * @param string                   $approval_expires_at_utc Approval expires at utc.
	 * @param string                   $backup_evidence_digest Backup evidence digest.
	 * @param string                   $backup_verified_at_utc Backup verified at utc.
	 * @param array<string,mixed>|null $checkpoint Checkpoint.
	 * @param string|null              $checkpoint_hash Checkpoint hash.
	 * @param int                      $installation_database_id Installation database id.
	 * @param int                      $installation_lock_version Installation lock version.
	 * @param string                   $installation_state Installation state.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $database_id,
		private string $public_id,
		private int $lock_version,
		private string $state,
		private array $preview,
		private string $preview_json_hash,
		private string $approved_at_utc,
		private string $approval_expires_at_utc,
		private string $backup_evidence_digest,
		private string $backup_verified_at_utc,
		private ?array $checkpoint,
		private ?string $checkpoint_hash,
		private int $installation_database_id,
		private int $installation_lock_version,
		private string $installation_state
	) {
		new Public_Id( $public_id );
		if (
			$database_id < 1
			|| $lock_version < 0
			|| ! in_array( $state, array( 'approved', 'running', 'cancelled', 'completed', 'failed' ), true )
			|| $installation_database_id < 1
			|| $installation_lock_version < 0
			|| ! in_array( $installation_state, array( 'registration_pending', 'runtime_ready', 'maintenance_required' ), true )
		) {
			throw new InvalidArgumentException( 'Purge job control state is invalid.' );
		}
		foreach ( array( $preview_json_hash, $backup_evidence_digest ) as $hash ) {
			$this->hash_value( $hash );
		}
		$this->utc( $approved_at_utc );
		$this->utc( $approval_expires_at_utc );
		$this->utc( $backup_verified_at_utc );
		if ( $this->time( $approval_expires_at_utc ) <= $this->time( $approved_at_utc ) ) {
			throw new InvalidArgumentException( 'Purge approval expiry is invalid.' );
		}
		if (
			Purge_Preview_Builder::PROFILE !== ( $preview['profile'] ?? null )
			|| Preview_Purge_Command::SCOPE_ADDON !== ( $preview['scope']['type'] ?? null )
			|| null !== ( $preview['scope']['public_id'] ?? null )
		) {
			throw new InvalidArgumentException( 'Purge job preview is invalid.' );
		}
		$this->hash_value( (string) ( $preview['scope_hash'] ?? '' ) );
		$this->hash_value( (string) ( $preview['preview_hash'] ?? '' ) );
		if ( null === $checkpoint xor null === $checkpoint_hash ) {
			throw new InvalidArgumentException( 'Purge job checkpoint is incomplete.' );
		}
		if ( null !== $checkpoint_hash ) {
			$this->hash_value( $checkpoint_hash );
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
	 * State.
	 *
	 * @return string
	 */
	public function state(): string {
		return $this->state;
	}
	/**
	 * Preview.
	 *
	 * @return array<string,mixed>
	 */
	public function preview(): array {
		return $this->preview;
	}
	/**
	 * Preview json hash.
	 *
	 * @return string
	 */
	public function preview_json_hash(): string {
		return $this->preview_json_hash;
	}
	/**
	 * Scope hash.
	 *
	 * @return string
	 */
	public function scope_hash(): string {
		return (string) $this->preview['scope_hash'];
	}
	/**
	 * Preview hash.
	 *
	 * @return string
	 */
	public function preview_hash(): string {
		return (string) $this->preview['preview_hash'];
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
	 * Backup evidence digest.
	 *
	 * @return string
	 */
	public function backup_evidence_digest(): string {
		return $this->backup_evidence_digest;
	}
	/**
	 * Backup verified at utc.
	 *
	 * @return string
	 */
	public function backup_verified_at_utc(): string {
		return $this->backup_verified_at_utc;
	}
	/**
	 * Checkpoint.
	 *
	 * @return array<string,mixed>|null
	 */
	public function checkpoint(): ?array {
		return $this->checkpoint;
	}
	/**
	 * Checkpoint hash.
	 *
	 * @return string|null
	 */
	public function checkpoint_hash(): ?string {
		return $this->checkpoint_hash;
	}
	/**
	 * Installation database id.
	 *
	 * @return int
	 */
	public function installation_database_id(): int {
		return $this->installation_database_id;
	}
	/**
	 * Installation lock version.
	 *
	 * @return int
	 */
	public function installation_lock_version(): int {
		return $this->installation_lock_version;
	}
	/**
	 * Installation state.
	 *
	 * @return string
	 */
	public function installation_state(): string {
		return $this->installation_state;
	}

	/**
	 * Hash value.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash_value( string $value ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Purge job digest is invalid.' );
		}
	}

	/**
	 * Utc.
	 *
	 * @param string $value Value.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function utc( string $value ): void {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			throw new InvalidArgumentException( 'Purge job UTC value is invalid.' );
		}
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		if ( ! $time instanceof DateTimeImmutable || $time->format( 'Y-m-d H:i:s' ) !== $value ) {
			throw new InvalidArgumentException( 'Purge job UTC value is invalid.' );
		}
	}

	/**
	 * Time.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function time( string $value ): int {
		$time = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $time instanceof DateTimeImmutable ? $time->getTimestamp() : -1;
	}
}
