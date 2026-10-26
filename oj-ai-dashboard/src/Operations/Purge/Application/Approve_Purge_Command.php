<?php
/**
 * Approve purge command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Operations\Purge\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approve purge command.
 */
final class Approve_Purge_Command {
	public const CONFIRMATION_PHRASE = '영구 삭제';

	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;
	/**
	 * Backup verified at utc.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $backup_verified_at_utc;

	/**
	 * Initialize purge command dependencies and state.
	 *
	 * @param int               $actor_user_id Authenticated actor from the access context.
	 * @param string            $scope_type Scope type.
	 * @param string            $preview_token Preview token.
	 * @param string            $backup_evidence_digest Backup evidence digest.
	 * @param DateTimeImmutable $backup_verified_at_utc Backup verified at utc.
	 * @param string            $confirmation_phrase Confirmation phrase.
	 * @param string            $idempotency_key_hash Idempotency key hash.
	 * @param string            $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json    $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $scope_type,
		private string $preview_token,
		private string $backup_evidence_digest,
		DateTimeImmutable $backup_verified_at_utc,
		private string $confirmation_phrase,
		private string $idempotency_key_hash,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if (
			$actor_user_id < 1
			|| Preview_Purge_Command::SCOPE_ADDON !== $scope_type
			|| '' === $preview_token
			|| strlen( $preview_token ) > 4096
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $preview_token )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $backup_evidence_digest )
			|| self::CONFIRMATION_PHRASE !== $confirmation_phrase
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_key_hash )
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]{7,127}$/D', $correlation_id )
		) {
			throw new InvalidArgumentException( 'Purge approval command is invalid.' );
		}

		$this->backup_verified_at_utc = $backup_verified_at_utc->setTimezone( new DateTimeZone( 'UTC' ) );
		$this->request_digest         = hash(
			'sha256',
			$canonical->encode(
				array(
					'backup'                   => array(
						'evidence_digest' => $backup_evidence_digest,
						'verified_at_utc' => $this->backup_verified_at_utc->format( 'Y-m-d H:i:s' ),
					),
					'confirmation_phrase_hash' => $this->confirmation_phrase_hash(),
					'preview_token_hash'       => $this->preview_token_hash(),
					'scope'                    => array(
						'type'      => $scope_type,
						'public_id' => null,
					),
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
	 * Scope type.
	 *
	 * @return string
	 */
	public function scope_type(): string {
		return $this->scope_type;
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
	 * @return DateTimeImmutable
	 */
	public function backup_verified_at_utc(): DateTimeImmutable {
		return $this->backup_verified_at_utc;
	}
	/**
	 * Confirmation phrase hash.
	 *
	 * @return string
	 */
	public function confirmation_phrase_hash(): string {
		return hash( 'sha256', $this->confirmation_phrase );
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
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|operations.purge.approve|' . $this->scope_type . '|' . $this->idempotency_key_hash
		);
	}
}
