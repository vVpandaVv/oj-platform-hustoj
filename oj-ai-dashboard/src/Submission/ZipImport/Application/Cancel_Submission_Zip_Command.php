<?php
/**
 * C21 submission ZIP cancellation command.
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
 * Cancels only an unimported private ZIP staging aggregate.
 */
final class Cancel_Submission_Zip_Command {
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize submission zip command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $upload_id Upload id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param int            $expected_mapping_version Expected mapping version.
	 * @param string         $authorized_context_type Authorized context type.
	 * @param string         $authorized_context_id Authorized context id.
	 * @param string         $authorized_context_revision Authorized context revision.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $upload_id,
		private string $command_id,
		private int $expected_mapping_version,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_mapping_version < 0 ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation identity or version is invalid.' );
		}
		new Public_Id( $upload_id );
		$this->token( $command_id, 'command_id', 128 );
		$this->context_value( $authorized_context_type, 'context_type', 16 );
		$this->context_value( $authorized_context_id, 'context_id', 191 );
		$this->context_value( $authorized_context_revision, 'context_revision', 191 );
		$this->token( $correlation_id, 'correlation_id', 128 );
		$this->hash( $idempotency_fingerprint, 'idempotency_fingerprint' );
		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array( 'expected_mapping_version' => $expected_mapping_version ),
					'payload'    => array(),
					'upload_id'  => $upload_id,
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
	 * Upload id.
	 *
	 * @return string
	 */
	public function upload_id(): string {
		return $this->upload_id;
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
	 * Expected mapping version.
	 *
	 * @return int
	 */
	public function expected_mapping_version(): int {
		return $this->expected_mapping_version;
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
		return 'submission-zip-cancel:' . hash(
			'sha256',
			$this->actor_user_id . '|submission_zip.cancel|' . $this->upload_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function token( string $value, string $field, int $maximum ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 !== preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Context value.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $maximum Maximum.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function context_value( string $value, string $field, int $maximum ): void {
		if ( '' === $value || strlen( $value ) > $maximum || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Submission ZIP cancellation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
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
			throw new InvalidArgumentException( 'Submission ZIP cancellation ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
