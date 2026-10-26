<?php
/**
 * C36 teacher feedback review command.
 *
 * @package OJAI\Dashboard
 */

declare(strict_types=1);

namespace OJAI\Dashboard\Submission\Assessment\Application;

use InvalidArgumentException;
use OJAI\Dashboard\Submission\Assessment\Domain\Submission_Feedback_Input_Builder;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Canonical_Json;
use OJAI\Dashboard\Worksheet\Authoring\Domain\Public_Id;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * C36 teacher feedback review command.
 */
final class Review_Submission_Feedback_Command {
	/**
	 * Feedback.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $feedback;
	/**
	 * Reason.
	 *
	 * @var string|null
	 */
	private ?string $reason;
	/**
	 * Request digest.
	 *
	 * @var string
	 */
	private string $request_digest;

	/**
	 * Initialize review submission feedback command dependencies and state.
	 *
	 * @param int                      $actor_user_id Authenticated actor from the access context.
	 * @param string                   $evaluation_id Evaluation id.
	 * @param string                   $command_id Command identifier used for idempotency.
	 * @param string                   $expected_official_review_id Expected official review id.
	 * @param string                   $expected_official_review_hash Expected official review hash.
	 * @param string|null              $expected_feedback_revision_id Expected feedback revision id.
	 * @param string|null              $expected_feedback_revision_hash Expected feedback revision hash.
	 * @param int                      $expected_feedback_revision_no Expected feedback revision no.
	 * @param string                   $decision Decision.
	 * @param string                   $source_mode Source mode.
	 * @param array<string,mixed>|null $feedback Feedback.
	 * @param string|null              $reason Reason.
	 * @param string                   $idempotency_fingerprint Fingerprint scoped to the actor and command.
	 * @param string                   $correlation_id Request correlation identifier for diagnostics.
	 * @param string                   $context_type Context type.
	 * @param string                   $context_id Context id.
	 * @param string                   $context_revision Context revision.
	 * @param string                   $authorized_revision Authorized revision.
	 * @param string                   $authorized_state Authorized state.
	 * @param string                   $actor_relation Actor relation.
	 * @param Canonical_Json           $canonical Canonical JSON encoder used for stable digests.
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	public function __construct(
		private int $actor_user_id,
		private string $evaluation_id,
		private string $command_id,
		private string $expected_official_review_id,
		private string $expected_official_review_hash,
		private ?string $expected_feedback_revision_id,
		private ?string $expected_feedback_revision_hash,
		private int $expected_feedback_revision_no,
		private string $decision,
		private string $source_mode,
		?array $feedback,
		?string $reason,
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
		if ( $actor_user_id < 1 || $expected_feedback_revision_no < 0 ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback command identity is invalid.' );
		}
		new Public_Id( $evaluation_id );
		new Public_Id( $expected_official_review_id );
		$this->hash_value( $expected_official_review_hash );
		$this->feedback_pointer( $expected_feedback_revision_id, $expected_feedback_revision_hash, $expected_feedback_revision_no );
		$this->safe_token( $command_id, 'command_id', 8, 128 );
		$this->safe_token( $correlation_id, 'correlation_id', 1, 100 );
		$this->safe_token( $context_id, 'context_id', 1, 191, true );
		$this->safe_token( $context_revision, 'context_revision', 1, 191, true );
		if (
			! in_array( $decision, array( 'teacher_draft', 'rejected' ), true )
			|| ! in_array( $source_mode, array( 'direct', 'edit' ), true )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $idempotency_fingerprint )
			|| ! in_array( $context_type, array( 'class', 'group' ), true )
			|| 1 !== preg_match( '/^(?:0|[1-9][0-9]*)$/D', $authorized_revision )
			|| 'reviewed' !== $authorized_state
			|| 'context_staff' !== $actor_relation
		) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback command evidence is invalid.' );
		}

		$this->feedback = null === $feedback ? null : $this->normalize_feedback( $feedback );
		$this->reason   = $this->normalize_reason( $reason );
		if (
			( 'teacher_draft' === $decision && ( null === $this->feedback || null !== $this->reason ) )
			|| ( 'edit' === $source_mode && null === $expected_feedback_revision_id )
			|| ( 'rejected' === $decision && ( 'edit' !== $source_mode || null !== $this->feedback || null === $this->reason ) )
		) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback decision payload is invalid.' );
		}

		$this->request_digest = hash(
			'sha256',
			$canonical->encode(
				array(
					'command_id'    => $command_id,
					'evaluation_id' => $evaluation_id,
					'expected'      => array(
						'expected_feedback_revision_hash' => $expected_feedback_revision_hash,
						'expected_feedback_revision_id'   => $expected_feedback_revision_id,
						'expected_feedback_revision_no'   => $expected_feedback_revision_no,
						'expected_official_review_hash'   => $expected_official_review_hash,
						'expected_official_review_id'     => $expected_official_review_id,
					),
					'payload'       => array(
						'decision'    => $decision,
						'feedback'    => $this->feedback,
						'reason'      => $this->reason,
						'source_mode' => $source_mode,
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
	 * Expected feedback revision no.
	 *
	 * @return int
	 */
	public function expected_feedback_revision_no(): int {
		return $this->expected_feedback_revision_no;
	}
	/**
	 * Decision.
	 *
	 * @return string
	 */
	public function decision(): string {
		return $this->decision;
	}
	/**
	 * Source mode.
	 *
	 * @return string
	 */
	public function source_mode(): string {
		return $this->source_mode;
	}
	/**
	 * Feedback.
	 *
	 * @return array<string,mixed>|null
	 */
	public function feedback(): ?array {
		return $this->feedback;
	}
	/**
	 * Return the stable failure reason used by boundary mappers.
	 *
	 * @return string|null
	 */
	public function reason(): ?string {
		return $this->reason;
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
		return 'command:' . hash(
			'sha256',
			$this->actor_user_id . '|submission.feedback.review|' . $this->evaluation_id . '|' . $this->idempotency_fingerprint
		);
	}

	/**
	 * Normalize feedback.
	 *
	 * @param array<array-key,mixed> $feedback Feedback.
	 * @return array<string,mixed>
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_feedback( array $feedback ): array {
		if ( array_is_list( $feedback ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback feedback must be an object.' );
		}
		$this->exact_keys( $feedback, array( 'criterion_feedback', 'next_steps', 'overall_feedback' ) );
		$items = $feedback['criterion_feedback'] ?? null;
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 500 ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback criterion feedback is invalid.' );
		}
		$normalized_items = array();
		$seen             = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) ) {
				throw new InvalidArgumentException( 'ReviewSubmissionFeedback criterion item is invalid.' );
			}
			$this->exact_keys( $item, array( 'binding_key', 'next_step', 'strength' ) );
			$key = $item['binding_key'] ?? null;
			if ( ! is_string( $key ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $key ) || isset( $seen[ $key ] ) ) {
				throw new InvalidArgumentException( 'ReviewSubmissionFeedback criterion identity is invalid.' );
			}
			$strength  = $this->text( $item['strength'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS );
			$next_step = $this->text( $item['next_step'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS );
			if ( $this->length( $strength ) + $this->length( $next_step ) > Submission_Feedback_Input_Builder::MAXIMUM_CRITERION_FEEDBACK_CHARACTERS ) {
				throw new InvalidArgumentException( 'ReviewSubmissionFeedback criterion text is too long.' );
			}
			$seen[ $key ]       = true;
			$normalized_items[] = array(
				'binding_key' => $key,
				'next_step'   => $next_step,
				'strength'    => $strength,
			);
		}
		usort( $normalized_items, static fn ( array $left, array $right ): int => strcmp( $left['binding_key'], $right['binding_key'] ) );

		$steps = $feedback['next_steps'] ?? null;
		if ( ! is_array( $steps ) || ! array_is_list( $steps ) || count( $steps ) > Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEPS ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback next steps are invalid.' );
		}
		$normalized_steps = array();
		foreach ( $steps as $step ) {
			$step = $this->text( $step, Submission_Feedback_Input_Builder::MAXIMUM_NEXT_STEP_CHARACTERS );
			if ( in_array( $step, $normalized_steps, true ) ) {
				throw new InvalidArgumentException( 'ReviewSubmissionFeedback next step is duplicated.' );
			}
			$normalized_steps[] = $step;
		}

		return array(
			'criterion_feedback' => $normalized_items,
			'next_steps'         => $normalized_steps,
			'overall_feedback'   => $this->text( $feedback['overall_feedback'] ?? null, Submission_Feedback_Input_Builder::MAXIMUM_OVERALL_FEEDBACK_CHARACTERS ),
		);
	}

	/**
	 * Feedback pointer.
	 *
	 * @param string|null $id Id.
	 * @param string|null $hash Hash.
	 * @param int         $revision_no Revision no.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function feedback_pointer( ?string $id, ?string $hash, int $revision_no ): void {
		if ( ( null === $id ) !== ( null === $hash ) || ( null === $id ) !== ( 0 === $revision_no ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback source pointer is partial.' );
		}
		if ( null !== $id ) {
			new Public_Id( $id );
			$this->hash_value( (string) $hash );
		}
	}

	/**
	 * Normalize reason.
	 *
	 * @param string|null $value Value.
	 * @return string|null
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function normalize_reason( ?string $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( $value );
		if ( '' === $value || $this->length( $value ) > 2000 || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback reason is invalid.' );
		}
		return $value;
	}

	/**
	 * Text.
	 *
	 * @param mixed $value Value.
	 * @param int   $maximum Maximum.
	 * @return string
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function text( $value, int $maximum ): string {
		if ( ! is_string( $value ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback text is invalid.' );
		}
		$value = trim( $value );
		if ( '' === $value || $this->length( $value ) > $maximum || 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value ) ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback text is invalid.' );
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
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback hash is invalid.' );
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
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback ' . $field . ' is invalid.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception details stay in the application failure channel; presentation maps and escapes displayed errors.
		}
	}

	/**
	 * Length.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
	}

	/**
	 * Exact keys.
	 *
	 * @param array<string,mixed> $value Value.
	 * @param array<int,string>   $expected Expected.
	 * @return void
	 * @throws \Throwable Propagates validation or dependency failures.
	 */
	private function exact_keys( array $value, array $expected ): void {
		$actual = array_keys( $value );
		sort( $actual, SORT_STRING );
		sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) {
			throw new InvalidArgumentException( 'ReviewSubmissionFeedback object shape is invalid.' );
		}
	}
}
