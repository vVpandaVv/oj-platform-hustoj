<?php
/**
 * StageOwnAttachment command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Student\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts trusted actor metadata and one strict multipart upload descriptor.
 */
final class Stage_Own_Attachment_Command {
	/**
	 * Initialize stage own attachment command dependencies and state.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $submission_id Submission public identifier.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param string              $response_id Response id.
	 * @param string              $role Role.
	 * @param string              $expected_state Expected state.
	 * @param int                 $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param string|null         $expected_current_revision_id Expected current revision id.
	 * @param int                 $expected_schedule_version Expected schedule version.
	 * @param string              $expected_schedule_hash Expected schedule hash.
	 * @param string              $expected_publication_hash Expected publication hash.
	 * @param string              $expected_save_token Expected save token.
	 * @param array<string,mixed> $upload Upload.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $submission_id,
		private string $command_id,
		private string $response_id,
		private string $role,
		private string $expected_state,
		private int $expected_lock_version,
		private ?string $expected_current_revision_id,
		private int $expected_schedule_version,
		private string $expected_schedule_hash,
		private string $expected_publication_hash,
		private string $expected_save_token,
		private array $upload,
		private string $idempotency_fingerprint,
		private string $correlation_id
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'StageOwnAttachment actor is invalid.' );
		}
		new Public_Id( $submission_id );
		new Public_Id( $response_id );
		if ( null !== $expected_current_revision_id ) {
			new Public_Id( $expected_current_revision_id );
		}
		if ( ! in_array( $role, array( 'file', 'document', 'preview' ), true ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment role is invalid.' );
		}
		if ( ! in_array( $expected_state, array( 'draft', 'conflict', 'reopened' ), true ) || $expected_lock_version < 0 || $expected_schedule_version < 1 ) {
			throw new InvalidArgumentException( 'StageOwnAttachment expected state is invalid.' );
		}
		foreach ( array( $expected_schedule_hash, $expected_publication_hash, $expected_save_token, $idempotency_fingerprint ) as $hash ) {
			if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash ) ) {
				throw new InvalidArgumentException( 'StageOwnAttachment hash is invalid.' );
			}
		}
		foreach ( array( $command_id, $correlation_id ) as $token ) {
			if ( strlen( $token ) < 8 || strlen( $token ) > 128 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $token ) ) {
				throw new InvalidArgumentException( 'StageOwnAttachment token is invalid.' );
			}
		}
		$keys = array_keys( $upload );
		sort( $keys, SORT_STRING );
		if ( $keys !== array( 'error', 'name', 'size', 'tmp_name', 'type' ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment upload shape is invalid.' );
		}
		if ( ! is_string( $upload['name'] ) || '' === $upload['name'] || ! is_string( $upload['tmp_name'] ) || '' === $upload['tmp_name'] ) {
			throw new InvalidArgumentException( 'StageOwnAttachment upload is incomplete.' );
		}
		if ( ! is_string( $upload['type'] ) || ! is_int( $upload['error'] ) || ! is_int( $upload['size'] ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment upload metadata is invalid.' );
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
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
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
	 * Response id.
	 *
	 * @return string
	 */
	public function response_id(): string {
		return $this->response_id;
	}
	/**
	 * Role.
	 *
	 * @return string
	 */
	public function role(): string {
		return $this->role;
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
	 * Upload.
	 *
	 * @return array<string,mixed>
	 */
	public function upload(): array {
		return $this->upload;
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
	 * Storage key.
	 *
	 * @param string $extension Extension.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function storage_key( string $extension ): string {
		if ( 1 !== preg_match( '/^[a-z0-9]{2,5}$/D', $extension ) ) {
			throw new InvalidArgumentException( 'StageOwnAttachment extension is invalid.' );
		}
		$key = $this->idempotency_storage_key();
		return 'submission-attachments/' . substr( $key, 0, 2 ) . '/' . $key . '.' . $extension;
	}

	/**
	 * Idempotency storage key.
	 *
	 * @return string
	 */
	public function idempotency_storage_key(): string {
		return hash(
			'sha256',
			implode(
				'|',
				array(
					(string) $this->actor_user_id,
					'submission.attachment.stage',
					$this->submission_id,
					$this->idempotency_fingerprint,
				)
			)
		);
	}
}
