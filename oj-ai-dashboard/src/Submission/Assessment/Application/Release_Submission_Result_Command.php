<?php
/**
 * Frozen C43 exact first and corrected result release command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Result_Projection_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen C43 exact first and corrected result release command.
 */
final class Release_Submission_Result_Command {
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
	 * Initialize release submission result command dependencies and state.
	 *
	 * @param int            $actor_user_id Authenticated actor from the access context.
	 * @param string         $evaluation_id Evaluation id.
	 * @param string         $command_id Command identifier used for idempotency.
	 * @param string         $mode Mode.
	 * @param string         $score_visibility Score visibility.
	 * @param string         $feedback_visibility Feedback visibility.
	 * @param string         $expected_official_review_id Expected official review id.
	 * @param string         $expected_official_review_hash Expected official review hash.
	 * @param string|null    $expected_feedback_revision_id Expected feedback revision id.
	 * @param string|null    $expected_feedback_revision_hash Expected feedback revision hash.
	 * @param string|null    $expected_current_release_id Expected current release id.
	 * @param string|null    $expected_current_release_hash Expected current release hash.
	 * @param string         $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string         $correlation_id Request correlation identifier for diagnostics.
	 * @param string         $context_type Context type.
	 * @param string         $context_id Context id.
	 * @param string         $context_revision Context revision.
	 * @param string         $authorized_revision Authorized revision.
	 * @param string         $authorized_state Authorized state.
	 * @param string         $actor_relation Actor relation.
	 * @param Canonical_Json $canonical Canonical JSON encoder used for stable digests.
	 * @param string|null    $reason_code Reason code.
	 * @param string|null    $reason_note Reason note.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $evaluation_id,
		private string $command_id,
		private string $mode,
		private string $score_visibility,
		private string $feedback_visibility,
		private string $expected_official_review_id,
		private string $expected_official_review_hash,
		private ?string $expected_feedback_revision_id,
		private ?string $expected_feedback_revision_hash,
		private ?string $expected_current_release_id,
		private ?string $expected_current_release_hash,
		private string $idempotency_fingerprint,
		private string $correlation_id,
		private string $context_type,
		private string $context_id,
		private string $context_revision,
		private string $authorized_revision,
		private string $authorized_state,
		private string $actor_relation,
		Canonical_Json $canonical,
		private ?string $reason_code = null,
		private ?string $reason_note = null
	) {
		if ( $actor_user_id < 1 ) {
			throw new InvalidArgumentException( 'ReleaseSubmissionResult actor is invalid.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_official_review_id );
		$this->pointer( $expected_feedback_revision_id, $expected_feedback_revision_hash );
		$this->pointer( $expected_current_release_id, $expected_current_release_hash );
		$this->hash_value( $expected_official_review_hash );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->safe_token( $context_id, 'context_id', 1, 191, true );
		$this->safe_token( $context_revision, 'context_revision', 1, 191, true );
		$this->reason_note = $this->normalize_note( $reason_note );
		if (
			! in_array( $mode, array( 'release', 'correction' ), true )
			|| ! in_array( $score_visibility, Submission_Result_Projection_Builder::SCORE_VISIBILITIES, true )
			|| ! in_array( $feedback_visibility, Submission_Result_Projection_Builder::FEEDBACK_VISIBILITIES, true )
			|| ( 'hidden' === $score_visibility && 'hidden' === $feedback_visibility )
			|| ( 'shown' === $feedback_visibility ) !== ( null !== $expected_feedback_revision_id )
			|| ( 'release' === $mode ) !== ( null === $expected_current_release_id )
			|| ( 'release' === $mode && ( null !== $reason_code || null !== $this->reason_note ) )
			|| ( 'correction' === $mode && ( null === $reason_code || ! in_array( $reason_code, self::REASON_CODES, true ) || ( 'other' === $reason_code && null === $this->reason_note ) ) )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $authorized_revision )
			|| 'reviewed' !== $authorized_state
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'ReleaseSubmissionResult command is invalid.' );
		}

		$payload = array(
			'feedback_visibility' => $feedback_visibility,
			'mode'                => $mode,
			'score_visibility'    => $score_visibility,
		);
		if ( 'correction' === $mode ) {
			$payload['reason_code'] = $reason_code;
			$payload['reason_note'] = $this->reason_note;
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id' => $command_id,
					'expected'   => array(
						'expected_current_release_hash'   => $expected_current_release_hash,
						'expected_current_release_id'     => $expected_current_release_id,
						'expected_feedback_revision_hash' => $expected_feedback_revision_hash,
						'expected_feedback_revision_id'   => $expected_feedback_revision_id,
						'expected_official_review_hash'   => $expected_official_review_hash,
						'expected_official_review_id'     => $expected_official_review_id,
					),
					'payload'    => $payload,
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
	 * Mode.
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}
	/**
	 * Score visibility.
	 *
	 * @return string
	 */
	public function score_visibility(): string {
		return $this->score_visibility;
	}
	/**
	 * Feedback visibility.
	 *
	 * @return string
	 */
	public function feedback_visibility(): string {
		return $this->feedback_visibility;
	}
	/**
	 * Expected official review id.
	 *
	 * @return string
	 */
	public function expected_official_review_id(): string {
		return $this->expected_official_review_id;
	}
	/**
	 * Expected official review hash.
	 *
	 * @return string
	 */
	public function expected_official_review_hash(): string {
		return $this->expected_official_review_hash;
	}
	/**
	 * Expected feedback revision id.
	 *
	 * @return string|null
	 */
	public function expected_feedback_revision_id(): ?string {
		return $this->expected_feedback_revision_id;
	}
	/**
	 * Expected feedback revision hash.
	 *
	 * @return string|null
	 */
	public function expected_feedback_revision_hash(): ?string {
		return $this->expected_feedback_revision_hash;
	}
	/**
	 * Expected current release id.
	 *
	 * @return string|null
	 */
	public function expected_current_release_id(): ?string {
		return $this->expected_current_release_id;
	}
	/**
	 * Expected current release hash.
	 *
	 * @return string|null
	 */
	public function expected_current_release_hash(): ?string {
		return $this->expected_current_release_hash;
	}
	/**
	 * Reason code.
	 *
	 * @return string|null
	 */
	public function reason_code(): ?string {
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
		return 'command:' . hash( 'sha256', $this->actor_user_id . '|submission.result.release|' . $this->evaluation_id . '|' . $this->idempotency_fingerprint );
	}

	/**
	 * Pointer.
	 *
	 * @param string|null $id Id.
	 * @param string|null $hash Hash.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function pointer( ?string $id, ?string $hash ): void {
		if ( ( null === $id ) !== ( null === $hash ) ) {
			throw new InvalidArgumentException( 'ReleaseSubmissionResult pointer is invalid.' );
		}
		if ( null !== $id ) {
			new Public_Id( $id );
			$this->hash_value( (string) $hash );
		}
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
			throw new InvalidArgumentException( 'ReleaseSubmissionResult hash is invalid.' );
		}
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
		} $value = trim( $value );
		if ( '' === $value || strlen( $value ) > 2000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'ReleaseSubmissionResult reason note is invalid.' );
		} return $value;
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
			throw new InvalidArgumentException( 'ReleaseSubmissionResult ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This internal exception is mapped to stable errors at presentation boundaries, not rendered directly.
		}
	} // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
}
