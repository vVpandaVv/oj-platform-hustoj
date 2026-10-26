<?php
/**
 * StartOwnSubmission command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the exact C22 optimistic-concurrency envelope.
 */
final class Start_Own_Submission_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize start own submission command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $assignment_id Distribution public identifier.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param int            $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param int            $expected_schedule_version Expected schedule version.
	 * @param string         $expected_schedule_hash Expected schedule hash.
	 * @param string         $expected_publication_hash Expected publication hash.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $assignment_id,
		private string $command_id,
		private string $expected_state,
		private int $expected_lock_version,
		private int $expected_schedule_version,
		private string $expected_schedule_hash,
		private string $expected_publication_hash,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || 'open' !== $expected_state || $expected_lock_version < 0 || $expected_schedule_version < 1 ) {
			throw new InvalidArgumentException( 'StartOwnSubmission expected state is invalid.' );
		}
		new Public_Id( $assignment_id );
		$this->token( $command_id, 'command_id' );
		$this->token( $correlation_id, 'correlation_id' );
		$this->hash( $expected_schedule_hash, 'expected_schedule_hash' );
		$this->hash( $expected_publication_hash, 'expected_publication_hash' );
		$this->hash( $idempotency_fingerprint, 'idempotency_fingerprint' );
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'action'        => 'start',
					'assignment_id' => $assignment_id,
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_lock_version'     => $expected_lock_version,
						'expected_publication_hash' => $expected_publication_hash,
						'expected_schedule_hash'    => $expected_schedule_hash,
						'expected_schedule_version' => $expected_schedule_version,
						'expected_state'            => $expected_state,
					),
					'payload'       => array(),
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
	 * Assignment id.
	 *
	 * @return string
	 */
	public function assignment_id(): string {
		return $this->assignment_id;
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
	 * Expected lock version.
	 *
	 * @return int
	 */
	public function expected_lock_version(): int {
		return $this->expected_lock_version;
	}
	/**
	 * Expected schedule version.
	 *
	 * @return int
	 */
	public function expected_schedule_version(): int {
		return $this->expected_schedule_version;
	}
	/**
	 * Expected schedule hash.
	 *
	 * @return string
	 */
	public function expected_schedule_hash(): string {
		return $this->expected_schedule_hash;
	}
	/**
	 * Expected publication hash.
	 *
	 * @return string
	 */
	public function expected_publication_hash(): string {
		return $this->expected_publication_hash;
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
		return 'submission:' . hash( 'sha256', $this->actor_user_id . '|submission.start|' . $this->idempotency_fingerprint );
	}

	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( string $value, string $field ): void {
		if ( strlen( $value ) < 8 || strlen( $value ) > 128 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'StartOwnSubmission ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Hash.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function hash( string $value, string $field ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $value ) ) {
			throw new InvalidArgumentException( 'StartOwnSubmission ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
