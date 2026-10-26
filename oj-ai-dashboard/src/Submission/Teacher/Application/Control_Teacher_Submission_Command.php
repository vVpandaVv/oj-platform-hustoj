<?php
/**
 * Teacher submission control command input.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Teacher\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the frozen C27-C28 optimistic-concurrency envelope.
 */
final class Control_Teacher_Submission_Command {
	public const REOPEN = 'reopen';
	public const LOCK   = 'lock';

	/**
	 * Reason.
	 *
	 * @var string
	 */
	private string $reason;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize control teacher submission command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $submission_id Submission public identifier.
	 * @param string         $action Action.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_state Expected state.
	 * @param int            $expected_lock_version Optimistic concurrency version supplied by the caller.
	 * @param string         $expected_official_revision_id Expected official revision id.
	 * @param int            $expected_official_attempt_no Expected official attempt no.
	 * @param int            $expected_schedule_version Expected schedule version.
	 * @param string         $expected_schedule_hash Expected schedule hash.
	 * @param string         $expected_publication_hash Expected publication hash.
	 * @param string         $reason Reason.
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
		private string $submission_id,
		private string $action,
		private string $command_id,
		private string $expected_state,
		private int $expected_lock_version,
		private string $expected_official_revision_id,
		private int $expected_official_attempt_no,
		private int $expected_schedule_version,
		private string $expected_schedule_hash,
		private string $expected_publication_hash,
		string $reason,
		private string $authorized_context_type,
		private string $authorized_context_id,
		private string $authorized_context_revision,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 || $expected_lock_version < 0 || $expected_official_attempt_no < 1 || $expected_schedule_version < 1 ) {
			throw new InvalidArgumentException( 'Teacher submission control versions are invalid.' );
		}
		$allowed_states = array(
			self::REOPEN => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'locked' ),
			self::LOCK   => array( 'submitted', 'auto_submitted', 'proxy_submitted', 'reopened' ),
		);
		if ( ! isset( $allowed_states[ $action ] ) || ! in_array( $expected_state, $allowed_states[ $action ], true ) ) {
			throw new InvalidArgumentException( 'Teacher submission control transition is invalid.' );
		}
		new Public_Id( $submission_id );
		new Public_Id( $expected_official_revision_id );
		$this->token( $command_id, 'command_id', 128 );
		$this->token( $correlation_id, 'correlation_id', 128 );
		$this->token( $authorized_context_type, 'context_type', 32 );
		$this->token( $authorized_context_id, 'context_id', 191 );
		$this->token( $authorized_context_revision, 'context_revision', 191 );
		$this->hash( $expected_schedule_hash, 'expected_schedule_hash' );
		$this->hash( $expected_publication_hash, 'expected_publication_hash' );
		$this->hash( $idempotency_fingerprint, 'idempotency_fingerprint' );
		$this->reason = $this->normalize_reason( $reason );

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'action'        => $action,
					'command_id'    => $command_id,
					'expected'      => array(
						'expected_lock_version'         => $expected_lock_version,
						'expected_official_attempt_no'  => $expected_official_attempt_no,
						'expected_official_revision_id' => $expected_official_revision_id,
						'expected_publication_hash'     => $expected_publication_hash,
						'expected_schedule_hash'        => $expected_schedule_hash,
						'expected_schedule_version'     => $expected_schedule_version,
						'expected_state'                => $expected_state,
					),
					'payload'       => array( 'reason' => $this->reason ),
					'submission_id' => $submission_id,
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
	 * Submission id.
	 *
	 * @return string
	 */
	public function submission_id(): string {
		return $this->submission_id;
	}
	/**
	 * Action.
	 *
	 * @return string
	 */
	public function action(): string {
		return $this->action;
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
	 * Expected official revision id.
	 *
	 * @return string
	 */
	public function expected_official_revision_id(): string {
		return $this->expected_official_revision_id;
	}
	/**
	 * Expected official attempt no.
	 *
	 * @return int
	 */
	public function expected_official_attempt_no(): int {
		return $this->expected_official_attempt_no;
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
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
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
		return 'submission:' . hash( 'sha256', $this->actor_user_id . '|submission.' . $this->action . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Normalize reason.
	 *
	 * @param string $value Value.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_reason( string $value ): string {
		$value = trim( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		if ( '' === $value || strlen( $value ) > 500 || 1 !== preg_match( '//u', $value ) || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value ) ) {
			throw new InvalidArgumentException( 'Teacher submission control reason is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'Teacher submission control ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
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
			throw new InvalidArgumentException( 'Teacher submission control ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
