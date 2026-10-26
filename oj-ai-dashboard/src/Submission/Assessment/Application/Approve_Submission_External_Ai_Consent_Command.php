<?php
/**
 * C78 approve student submission external AI processing command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C78 approve student submission external AI processing command.
 */
final class Approve_Submission_External_Ai_Consent_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize submission external ai consent command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param string|null    $expected_consent_hash Expected consent hash.
	 * @param string         $expected_routing_revision Expected routing revision.
	 * @param string         $expected_profile_fingerprint Expected profile fingerprint.
	 * @param bool           $acknowledged_external_processing Acknowledged external processing.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $command_id,
		private string $expected_state,
		private ?string $expected_consent_hash,
		private string $expected_routing_revision,
		private string $expected_profile_fingerprint,
		private bool $acknowledged_external_processing,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || ! $acknowledged_external_processing ) {
			throw new InvalidArgumentException( 'Submission external AI consent approval requires an authenticated acknowledgement.' );
		}
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 128 );
		$this->safe_token( $expected_routing_revision, 'expected_routing_revision', 1, 191 );
		if (
			! in_array( $expected_state, array( 'missing', 'stale' ), true )
			|| ( null !== $expected_consent_hash && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_consent_hash ) )
			|| ( 'missing' === $expected_state && null !== $expected_consent_hash )
			|| ( 'stale' === $expected_state && null === $expected_consent_hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $expected_profile_fingerprint )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
		) {
			throw new InvalidArgumentException( 'Submission external AI consent approval evidence is invalid.' );
		}
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_consent_hash'        => $expected_consent_hash,
						'expected_profile_fingerprint' => $expected_profile_fingerprint,
						'expected_routing_revision'    => $expected_routing_revision,
						'expected_state'               => $expected_state,
					),
					'payload'    => array( 'acknowledged_external_processing' => true ),
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
	 * Expected consent hash.
	 *
	 * @return string|null
	 */
	public function expected_consent_hash(): ?string {
		return $this->expected_consent_hash;
	}
	/**
	 * Expected routing revision.
	 *
	 * @return string
	 */
	public function expected_routing_revision(): string {
		return $this->expected_routing_revision;
	}
	/**
	 * Expected profile fingerprint.
	 *
	 * @return string
	 */
	public function expected_profile_fingerprint(): string {
		return $this->expected_profile_fingerprint;
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
		return 'submission-consent:' . hash(
			'sha256',
			$this->actor_user_id . '|submission_external_ai.consent.approve|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field, int $minimum, int $maximum ): void {
		if (
			strlen( $value ) < $minimum
			|| strlen( $value ) > $maximum
			|| 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value )
		) {
			throw new InvalidArgumentException( 'Submission external AI consent ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
