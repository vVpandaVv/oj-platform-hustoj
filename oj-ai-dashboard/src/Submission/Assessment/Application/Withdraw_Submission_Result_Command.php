<?php
/**
 * Frozen C44 exact student result withdrawal command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen C44 exact student result withdrawal command.
 */
final class Withdraw_Submission_Result_Command {
	public const REASON_CODES = array(
		'data_correction',
		'publication_error',
		'privacy_request',
		'other',
	);

	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize withdraw submission result command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $evaluation_id Evaluation id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $expected_current_release_id Expected current release id.
	 * @param string         $expected_current_release_hash Expected current release hash.
	 * @param string         $reason_code Reason code.
	 * @param string|null    $reason_note Reason note.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $context_type Context type.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $evaluation_id,
		private string $command_id,
		private string $expected_current_release_id,
		private string $expected_current_release_hash,
		private string $reason_code,
		private ?string $reason_note,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'WithdrawSubmissionResult actor is invalid.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_current_release_id );
		$this->hash_value( $expected_current_release_hash );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->safe_token( $context_id, 'context_id', 1, 191, true );
		$this->safe_token( $context_revision, 'context_revision', 1, 191, true );
		$this->reason_note = $this->normalize_note( $reason_note );
		if (
			! in_array( $reason_code, self::REASON_CODES, true )
			|| ( 'other' === $reason_code && null === $this->reason_note )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $authorized_revision )
			|| 'reviewed' !== $authorized_state
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'WithdrawSubmissionResult command is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_current_release_hash' => $expected_current_release_hash,
						'expected_current_release_id'   => $expected_current_release_id,
					),
					'payload'    => array(
						'reason_code' => $reason_code,
						'reason_note' => $this->reason_note,
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
	 * Evaluation id.
	 *
	 * @return string
	 */
	public function evaluation_id(): string {
		return $this->evaluation_id;
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
	 * Expected current release id.
	 *
	 * @return string
	 */
	public function expected_current_release_id(): string {
		return $this->expected_current_release_id;
	}
	/**
	 * Expected current release hash.
	 *
	 * @return string
	 */
	public function expected_current_release_hash(): string {
		return $this->expected_current_release_hash;
	}
	/**
	 * Reason code.
	 *
	 * @return string
	 */
	public function reason_code(): string {
		return $this->reason_code;
	}
	/**
	 * Reason note.
	 *
	 * @return string|null
	 */
	public function reason_note(): ?string {
		return $this->reason_note;
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
	 * Context type.
	 *
	 * @return string
	 */
	public function context_type(): string {
		return $this->context_type;
	}
	/**
	 * Context id.
	 *
	 * @return string
	 */
	public function context_id(): string {
		return $this->context_id;
	}
	/**
	 * Context revision.
	 *
	 * @return string
	 */
	public function context_revision(): string {
		return $this->context_revision;
	}
	/**
	 * Authorized revision.
	 *
	 * @return string
	 */
	public function authorized_revision(): string {
		return $this->authorized_revision;
	}
	/**
	 * Authorized state.
	 *
	 * @return string
	 */
	public function authorized_state(): string {
		return $this->authorized_state;
	}
	/**
	 * Actor relation.
	 *
	 * @return string
	 */
	public function actor_relation(): string {
		return $this->actor_relation;
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|submission.result.withdraw|' . $this->evaluation_id . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Normalize note.
	 *
	 * @param string|null $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_note( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'WithdrawSubmissionResult reason note is invalid.' );
		}
		return $value;
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
			throw new InvalidArgumentException( 'WithdrawSubmissionResult hash is invalid.' );
		}
	}

	/**
	 * Safe token.
	 *
	 * @param string $value Value.
	 * @param string $field Field.
	 * @param int    $minimum Minimum.
	 * @param int    $maximum Maximum.
	 * @param bool   $slash Slash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function safe_token( string $value, string $field, int $minimum, int $maximum, bool $slash = false ): void {
		$pattern = $slash ? '/^[A-Za-z0-9][A-Za-z0-9_.:@\/-]*$/D' : '/^[A-Za-z0-9][A-Za-z0-9_.:@-]*$/D';
		if ( strlen( $value ) < $minimum || strlen( $value ) > $maximum || 1 !== preg_match( $pattern, $value ) ) {
			throw new InvalidArgumentException( 'WithdrawSubmissionResult ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}
}
