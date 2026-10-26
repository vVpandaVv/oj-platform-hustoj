<?php
/**
 * StageSubmissionZip command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\ZipImport\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Accepts one staff-authorized C19 multipart request.
 */
final class Stage_Submission_Zip_Command {
	/**
	 * Initialize stage submission zip command dependencies and state.
	 *
	 * @param int                 $actor_user_id Authenticated actor from the access context.
	 * @param string              $assignment_id Distribution public identifier.
	 * @param string              $command_id Command identifier used for idempotency.
	 * @param string              $expected_state Expected state.
	 * @param int                 $expected_schedule_version Expected schedule version.
	 * @param string              $authorized_context_type Authorized context type.
	 * @param string              $authorized_context_id Authorized context id.
	 * @param string              $authorized_context_revision Authorized context revision.
	 * @param array<string,mixed> $upload Upload.
	 * @param string              $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string              $correlation_id Request correlation identifier for diagnostics.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $assignment_id,
		private string $command_id,
		private string $expected_state,
		private int $expected_schedule_version,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private array $upload,
		private string $idempotency_fingerprint,
		private string $correlation_id
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'StageSubmissionZip actor is invalid.' );
		}
		new Public_Id( $assignment_id );
		if ( ! in_array( $expected_state, array( 'draft', 'scheduled', 'open', 'closed' ), true ) || $expected_schedule_version < 1 ) {
			throw new InvalidArgumentException( 'StageSubmissionZip expected assignment state is invalid.' );
		}
		foreach ( array(
			'authorized_context_type'     => $authorized_context_type,
			'authorized_context_id'       => $authorized_context_id,
			'authorized_context_revision' => $authorized_context_revision,
		) as $field => $value ) {
			if ( '' === $value || strlen( $value ) > ( 'authorized_context_type' === $field ? 16 : 191 ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
				throw new InvalidArgumentException( 'StageSubmissionZip Context is invalid.' );
			}
		}
		foreach ( array( $command_id, $correlation_id ) as $token ) {
			if ( strlen( $token ) < 8 || strlen( $token ) > 128 || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D', $token ) ) {
				throw new InvalidArgumentException( 'StageSubmissionZip token is invalid.' );
			}
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip idempotency fingerprint is invalid.' );
		}
		$keys = array_keys( $upload );
		sort( $keys, SORT_STRING );
		if ( $keys !== array( 'error', 'name', 'size', 'tmp_name', 'type' ) ) {
			throw new InvalidArgumentException( 'StageSubmissionZip upload shape is invalid.' );
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
	 * Expected schedule version.
	 *
	 * @return int
	 */
	public function expected_schedule_version(): int {
		return $this->expected_schedule_version;
	}
	/**
	 * Authorized context type.
	 *
	 * @return string
	 */
	public function authorized_context_type(): string {
		return $this->authorized_context_type;
	}
	/**
	 * Authorized context id.
	 *
	 * @return string
	 */
	public function authorized_context_id(): string {
		return $this->authorized_context_id;
	}
	/**
	 * Authorized context revision.
	 *
	 * @return string
	 */
	public function authorized_context_revision(): string {
		return $this->authorized_context_revision;
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
					'submission_zip.stage',
					$this->assignment_id,
					$this->idempotency_fingerprint,
				)
			)
		);
	}

	/**
	 * Storage key.
	 *
	 * @return string
	 */
	public function storage_key(): string {
		$key = $this->idempotency_storage_key();
		return 'submission-zips/' . substr( $key, 0, 2 ) . '/' . $key . '.zip';
	}

	/**
	 * Request digest.
	 *
	 * @param array<string,mixed> $inspected Inspected.
	 * @param Canonical_Json      $canonical Canonical JSON encoder used for stable digests.
	 * @return string
	 */
	public function request_digest( array $inspected, Canonical_Json $canonical ): string {
		return hash(
			'sha256',
			$canonical->encode(
				array(
					'action'                      => 'submission_zip.stage',
					'assignment_id'               => $this->assignment_id,
					'authorized_context_id'       => $this->authorized_context_id,
					'authorized_context_revision' => $this->authorized_context_revision,
					'authorized_context_type'     => $this->authorized_context_type,
					'command_id'                  => $this->command_id,
					'expected_schedule_version'   => $this->expected_schedule_version,
					'expected_state'              => $this->expected_state,
					'source'                      => array(
						'byte_size'              => $inspected['byte_size'] ?? null,
						'compressed_entry_bytes' => $inspected['compressed_entry_bytes'] ?? null,
						'entry_count'            => $inspected['entry_count'] ?? null,
						'original_name'          => $inspected['original_name'] ?? null,
						'sha256'                 => $inspected['sha256'] ?? null,
						'uncompressed_bytes'     => $inspected['uncompressed_bytes'] ?? null,
					),
				)
			)
		);
	}
}
