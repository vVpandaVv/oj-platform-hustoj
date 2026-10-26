<?php
/**
 * Explicit schema repair authorization.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Database\Foundation;

use DateTimeImmutable;
use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds only digests and timestamps; backup paths and credentials are forbidden.
 */
final class Schema_Repair_Authorization {
	/**
	 * Authenticated actor from the access context.
	 *
	 * @var int
	 */
	private int $actor_user_id;
	/**
	 * Request correlation identifier for diagnostics.
	 *
	 * @var string
	 */
	private string $correlation_id;
	/**
	 * Approval token hash.
	 *
	 * @var string
	 */
	private string $approval_token_hash;
	/**
	 * Backup evidence hash.
	 *
	 * @var string
	 */
	private string $backup_evidence_hash;
	/**
	 * Scope fingerprint.
	 *
	 * @var string
	 */
	private string $scope_fingerprint;
	/**
	 * Backup verified at utc.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $backup_verified_at_utc;

	/**
	 * Initialize schema repair authorization dependencies and state.
	 *
	 * @param int               $actor_user_id Authenticated actor from the access context.
	 * @param string            $correlation_id Request correlation identifier for diagnostics.
	 * @param string            $approval_token_hash Approval token hash.
	 * @param string            $backup_evidence_hash Backup evidence hash.
	 * @param string            $scope_fingerprint Scope fingerprint.
	 * @param DateTimeImmutable $backup_verified_at_utc Backup verified at utc.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		int $actor_user_id,
		string $correlation_id,
		string $approval_token_hash,
		string $backup_evidence_hash,
		string $scope_fingerprint,
		DateTimeImmutable $backup_verified_at_utc
	) {
		if ( $actor_user_id <= 0 ) {
			throw new InvalidArgumentException( 'Schema repair requires an authenticated operator.' );
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9._:-]{8,100}$/', $correlation_id ) ) {
			throw new InvalidArgumentException( 'Schema repair requires a stable correlation ID.' );
		}
		foreach ( array( $approval_token_hash, $backup_evidence_hash, $scope_fingerprint ) as $digest ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $digest ) ) {
				throw new InvalidArgumentException( 'Schema repair evidence must use SHA-256 digests.' );
			}
		}

		$this->actor_user_id          = $actor_user_id;
		$this->correlation_id         = $correlation_id;
		$this->approval_token_hash    = $approval_token_hash;
		$this->backup_evidence_hash   = $backup_evidence_hash;
		$this->scope_fingerprint      = $scope_fingerprint;
		$this->backup_verified_at_utc = $backup_verified_at_utc;
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
	 * Correlation id.
	 *
	 * @return string
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}

	/**
	 * Approval token hash.
	 *
	 * @return string
	 */
	public function approval_token_hash(): string {
		return $this->approval_token_hash;
	}

	/**
	 * Backup evidence hash.
	 *
	 * @return string
	 */
	public function backup_evidence_hash(): string {
		return $this->backup_evidence_hash;
	}

	/**
	 * Scope fingerprint.
	 *
	 * @return string
	 */
	public function scope_fingerprint(): string {
		return $this->scope_fingerprint;
	}

	/**
	 * Backup verified at utc.
	 *
	 * @return DateTimeImmutable
	 */
	public function backup_verified_at_utc(): DateTimeImmutable {
		return $this->backup_verified_at_utc;
	}

	/**
	 * Valid for.
	 *
	 * @param Schema_Catalog    $catalog Catalog.
	 * @param DateTimeImmutable $now Clock value supplied for deterministic scheduling.
	 * @return bool
	 */
	public function valid_for( Schema_Catalog $catalog, DateTimeImmutable $now ): bool {
		$age = $now->getTimestamp() - $this->backup_verified_at_utc->getTimestamp();

		return hash_equals( $catalog->fingerprint(), $this->scope_fingerprint )
			&& $age >= 0
			&& $age <= 86400;
	}
}
