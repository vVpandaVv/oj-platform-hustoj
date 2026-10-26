<?php
/**
 * RemoveOwnStagedAttachment command.
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
 * Validates the exact C25 optimistic-concurrency envelope.
 */
final class Remove_Own_Staged_Attachment_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize remove own staged attachment command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $submission_id Submission public identifier.
	 * @param string         $attachment_id Attachment id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param int            $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param string|null    $expected_current_revision_id Expected current revision id.
	 * @param int            $expected_schedule_version Expected schedule version.
	 * @param string         $expected_schedule_hash Expected schedule hash.
	 * @param string         $expected_publication_hash Expected publication hash.
	 * @param string         $expected_save_token Expected save token.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $submission_id,
		private string $attachment_id,
		private string $command_id,
		private string $expected_state,
		private int $expected_lock_version,
		private ?string $expected_current_revision_id,
		private int $expected_schedule_version,
		private string $expected_schedule_hash,
		private string $expected_publication_hash,
		private string $expected_save_token,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if (
			$actor_user_id < 1
			|| ! in_array( $expected_state, array( 'draft', 'conflict', 'reopened' ), true )
			|| $expected_lock_version < 0
			|| $expected_schedule_version < 1
		) {
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment expected state is invalid.' );
		}
		new Public_Id( $submission_id );
		new Public_Id( $attachment_id );
		if ( null !== $expected_current_revision_id ) {
			new Public_Id( $expected_current_revision_id );
		}
		$this->token( $command_id, 'command_id' );
		$this->token( $correlation_id, 'correlation_id' );
		foreach ( array( $expected_schedule_hash, $expected_publication_hash, $expected_save_token, $idempotency_fingerprint ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'RemoveOwnStagedAttachment hash is invalid.' );
			}
		}

		$json                 = $canonical->encode(
			array(
				'action'        => 'remove_staged_attachment',
				'attachment_id' => $attachment_id,
				'command_id'    => $command_id,
				'expected'      => array(
					'expected_current_revision_id' => $expected_current_revision_id,
					'expected_lock_version'        => $expected_lock_version,
					'expected_publication_hash'    => $expected_publication_hash,
					'expected_save_token'          => $expected_save_token,
					'expected_schedule_hash'       => $expected_schedule_hash,
					'expected_schedule_version'    => $expected_schedule_version,
					'expected_state'               => $expected_state,
				),
				'payload'       => array(),
				'submission_id' => $submission_id,
			)
		);
		$this->request_digest = hash( 'sha256', $json );
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
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
	}
	/**
	 * Attachment id.
	 *
	 * @return string
	 */
	public function attachment_id(): string {
		return $this->attachment_id;
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
	 * Expected current revision id.
	 *
	 * @return string|null
	 */
	public function expected_current_revision_id(): ?string {
		return $this->expected_current_revision_id;
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
	 * Expected save token.
	 *
	 * @return string
	 */
	public function expected_save_token(): string {
		return $this->expected_save_token;
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
		return 'submission:' . hash(
			'sha256',
			$this->actor_user_id . '|submission.attachment.remove|' . $this->idempotency_fingerprint
		);
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
			throw new InvalidArgumentException( 'RemoveOwnStagedAttachment ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
